<?php
/**
 * ErcerLink 公共工具运行时验证
 *
 * 用最小桩替换 Typecho_* 依赖，直接断言 ErcerLink_Util 的安全行为：
 * URL 规范化、文本清洗、脚本地址校验、CSRF、同源、限流、客户端 IP。
 *
 * 运行：php tests/runtime_check.php
 */

define('__TYPECHO_ADMIN_DIR__', '/admin/');

/* 仅允许 CLI 运行：避免测试文件被 Web 直接访问 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$PLUGIN_DIR = dirname(__DIR__);
require_once $PLUGIN_DIR . '/Util.php';

/* ---------------- 桩 ---------------- */

class StubOptions
{
    public $siteUrl = 'https://example.com';
    public $plugins = array();

    public function plugin($name)
    {
        return isset($this->plugins[$name]) ? $this->plugins[$name] : new stdClass();
    }
}

class Typecho_Widget
{
    public static $options;

    public static function widget($name)
    {
        return $name === 'Widget_Options' ? self::$options : new stdClass();
    }
}

/* ---------------- 断言 ---------------- */

$pass = 0;
$failures = array();

function check($label, $actual, $expected)
{
    global $pass, $failures;

    if ($actual === $expected) {
        $pass++;
        return;
    }

    $failures[] = sprintf(
        '%s => 实际 %s，期望 %s',
        $label,
        var_export($actual, true),
        var_export($expected, true)
    );
}

function reset_request(array $server = array())
{
    $_SERVER = array_merge(array(
        'REQUEST_METHOD' => 'POST',
        'REMOTE_ADDR'    => '203.0.113.9',
        'HTTP_HOST'      => 'example.com',
        'SERVER_PORT'    => '443',
        'HTTPS'          => 'on'
    ), $server);

    $_POST = array();
    $_COOKIE = array();
}

/* ---------------- 准备 ---------------- */

$pluginOptions = new stdClass();
$pluginOptions->proxyHeader = 'NONE';
$pluginOptions->path = '';

Typecho_Widget::$options = new StubOptions();
Typecho_Widget::$options->plugins['ErcerLink'] = $pluginOptions;

/* 1. URL 规范化 */
check('https 规范化', ErcerLink_Util::normalizeUrl('HTTPS://Example.COM/Path?x=1#f'), 'https://example.com/Path?x=1');
check('根路径归一', ErcerLink_Util::normalizeUrl('https://example.com/'), 'https://example.com');
check('拒绝 javascript:', ErcerLink_Util::normalizeUrl('javascript:alert(1)'), '');
check('拒绝大写 JaVaScRiPt:', ErcerLink_Util::normalizeUrl('JaVaScRiPt:alert(1)'), '');
check('拒绝 data:', ErcerLink_Util::normalizeUrl('data:text/html,<script>alert(1)</script>'), '');
check('拒绝空格注入', ErcerLink_Util::normalizeUrl('https://example.com/a b'), '');
check('拒绝引号', ErcerLink_Util::normalizeUrl('https://example.com/"onmouseover=alert(1)'), '');
check('拒绝反斜杠', ErcerLink_Util::normalizeUrl('https://example.com\\@evil.com'), '');
check('拒绝超长', ErcerLink_Util::normalizeUrl('https://example.com/' . str_repeat('a', 300)), '');
check('拒绝空值', ErcerLink_Util::normalizeUrl(''), '');
check('拒绝非 http 协议', ErcerLink_Util::normalizeUrl('ftp://example.com/a'), '');
check('拒绝 userinfo 欺骗链接', ErcerLink_Util::normalizeUrl('https://trusted.example@evil.com/x'), '');
check('拒绝数组输入', ErcerLink_Util::normalizeUrl(array('https://example.com')), '');

/* 2. 文本清洗 */
check('剥离脚本标签', ErcerLink_Util::cleanText('<script>alert(1)</script>hi'), 'alert(1)hi');
check('标签整体剥离', ErcerLink_Util::cleanText('"><img src=x>'), '');
check('标签内文本保留', ErcerLink_Util::cleanText('<b>我的站</b>'), '我的站');
check('折叠控制字符', ErcerLink_Util::cleanText("a\x00b\x1Fc"), 'a b c');
check('折叠空白', ErcerLink_Util::cleanText('  a   b  '), 'a b');
check('拒绝数组输入', ErcerLink_Util::cleanText(array('x')), '');
check('保留中文', ErcerLink_Util::cleanText('我的博客'), '我的博客');
check('去除反引号与反斜杠', ErcerLink_Util::cleanText('a`b\\c'), 'abc');

/* 3. 脚本地址校验（对应原 validateCdnPath 漏洞） */
check('留空允许', ErcerLink_Util::isValidScriptUrl(''), true);
check('合法 .js', ErcerLink_Util::isValidScriptUrl('https://cdn.example.com/a.js'), true);
check('拒绝 javascript:', ErcerLink_Util::isValidScriptUrl('javascript:alert(1)'), false);
check('拒绝非 .js 后缀', ErcerLink_Util::isValidScriptUrl('https://evil.com/a.php'), false);
check('拒绝 data:', ErcerLink_Util::isValidScriptUrl('data:text/javascript,alert(1)'), false);

/* 4. CSRF 双提交 */
reset_request();
$token = ErcerLink_Util::csrfToken();
check('Token 为 32 位十六进制', preg_match('/^[a-f0-9]{32}$/', $token), 1);
check('无 Cookie 时拒绝', ErcerLink_Util::verifyCsrf(), false);

$_COOKIE['ercerlink_csrf'] = $token;
check('有 Cookie 无提交 token 拒绝', ErcerLink_Util::verifyCsrf(), false);

$_SERVER['HTTP_X_ERCERLINK_CSRF'] = $token;
check('Cookie 与请求头一致通过', ErcerLink_Util::verifyCsrf(), true);

$_SERVER['HTTP_X_ERCERLINK_CSRF'] = str_repeat('a', 32);
check('请求头不一致拒绝', ErcerLink_Util::verifyCsrf(), false);

$_SERVER['HTTP_X_ERCERLINK_CSRF'] = 'not-a-valid-token';
check('请求头格式非法拒绝', ErcerLink_Util::verifyCsrf(), false);

unset($_SERVER['HTTP_X_ERCERLINK_CSRF']);
$_POST['ercerlink_csrf'] = $token;
check('表单字段回落通过', ErcerLink_Util::verifyCsrf(), true);

$_COOKIE['ercerlink_csrf'] = 'ARRAY';
check('Cookie 非规范格式拒绝', ErcerLink_Util::verifyCsrf(), false);

/* 5. 同源校验 */
reset_request();
check('无 Origin/Referer 通过', ErcerLink_Util::isSameOrigin(), true);

$_SERVER['HTTP_ORIGIN'] = 'https://evil.com';
check('跨站 Origin 拒绝', ErcerLink_Util::isSameOrigin(), false);

$_SERVER['HTTP_ORIGIN'] = 'https://example.com';
check('同站 Origin 通过', ErcerLink_Util::isSameOrigin(), true);

$_SERVER['HTTP_REFERER'] = 'https://evil.com/x';
check('跨站 Referer 拒绝', ErcerLink_Util::isSameOrigin(), false);

$_SERVER['HTTP_REFERER'] = 'https://example.com/x';
check('同站 Referer 通过', ErcerLink_Util::isSameOrigin(), true);

$_SERVER['HTTP_ORIGIN'] = 'null';
check('Origin: null 拒绝', ErcerLink_Util::isSameOrigin(), false);

/* 6. 客户端 IP */
reset_request();
check('默认取 REMOTE_ADDR', ErcerLink_Util::clientIp(), '203.0.113.9');

$_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4';
check('默认忽略可伪造的 XFF', ErcerLink_Util::clientIp(), '203.0.113.9');

$pluginOptions->proxyHeader = 'X-FORWARDED-FOR';
check('显式开启后取 XFF 首段', ErcerLink_Util::clientIp(), '1.2.3.4');

$_SERVER['HTTP_X_FORWARDED_FOR'] = 'not-an-ip, 5.6.7.8';
check('XFF 非法值回落到 REMOTE_ADDR', ErcerLink_Util::clientIp(), '203.0.113.9');

$pluginOptions->proxyHeader = 'CF-CONNECTING-IP';
$_SERVER['HTTP_CF_CONNECTING_IP'] = '9.9.9.9';
check('Cloudflare 头生效', ErcerLink_Util::clientIp(), '9.9.9.9');
$pluginOptions->proxyHeader = 'NONE';

/* 7. 限流（窗口内第 6 次触发；每次用随机 IP 避免历史计数干扰） */
$ip = '198.51.' . mt_rand(1, 254) . '.' . mt_rand(1, 254);
$limitedFlags = array();

for ($i = 0; $i < 6; $i++) {
    $limitedFlags[] = ErcerLink_Util::isRateLimited($ip);
}

check('前 5 次放行', $limitedFlags, array(false, false, false, false, false, true));
check('不同 IP 互不影响', ErcerLink_Util::isRateLimited('198.51.' . mt_rand(1, 254) . '.' . mt_rand(1, 254)), false);

/* ---------------- 结果 ---------------- */

echo "\n";
foreach ($failures as $failure) {
    echo 'FAIL ' . $failure . "\n";
}

printf("通过 %d 项，失败 %d 项\n", $pass, count($failures));

exit(empty($failures) ? 0 : 1);
