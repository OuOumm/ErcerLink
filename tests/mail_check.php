<?php
/**
 * ErcerLink 邮件通知单元验证
 *
 * ErcerLink 只走 CommentToMail 6.0 的直发通道，本脚本覆盖：
 *   1. 开关关闭 / 依赖未启用 / 依赖版本过低时不发信
 *   2. 直发任务的主题与正文内容
 *   3. 直发被跳过（pushMail 返回 false）时的返回值
 *   4. HTML 转义与头字段注入防护
 *
 * 注意：CommentToMail_Plugin 桩一旦加载便无法卸载，因此「没有 pushMail()」
 * 的用例必须排在 require 桩之前。
 *
 * 运行：php tests/mail_check.php
 */

define('__TYPECHO_ADMIN_DIR__', '/admin/');

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/* Mailer 依赖的 Typecho 基类 */
interface Widget_Interface_Do
{
}

abstract class Typecho_Widget
{
    public static $options;

    public static function widget($name)
    {
        return $name === 'Widget_Options' ? self::$options : new stdClass();
    }
}

$pluginDir = dirname(__DIR__);
require_once $pluginDir . '/Util.php';
require_once $pluginDir . '/Mailer.php';

/* ---------------- 桩 ---------------- */

class StubOptions
{
    public $siteUrl = 'https://example.com';
    public $title = '示例站点';
    public $plugins = array();

    public function plugin($name)
    {
        return isset($this->plugins[$name]) ? $this->plugins[$name] : new stdClass();
    }
}

class Typecho_Plugin
{
    public static $activated = array();

    public static function export()
    {
        return array('activated' => self::$activated, 'handles' => array());
    }
}

/* ---------------- 断言 ---------------- */

$pass = 0;
$failures = array();

/**
 * @param string $name
 * @param mixed $actual
 * @param mixed $expected
 */
function check($name, $actual, $expected)
{
    global $pass, $failures;

    if ($actual === $expected) {
        $pass++;
        return;
    }

    $failures[] = sprintf(
        '%s => 实际 %s，期望 %s',
        $name,
        var_export($actual, true),
        var_export($expected, true)
    );
}

/**
 * 重置环境
 *
 * @param bool $notifyOn
 * @param array $activated
 * @param string $title
 * @return object 插件配置对象
 */
function reset_env($notifyOn, array $activated, $title = '示例站点')
{
    $pluginOptions = new stdClass();
    $pluginOptions->enableEmailNotify = $notifyOn ? '1' : '0';

    Typecho_Widget::$options = new StubOptions();
    Typecho_Widget::$options->title = $title;
    Typecho_Widget::$options->plugins['ErcerLink'] = $pluginOptions;

    Typecho_Plugin::$activated = $activated;

    /* 配置对象在进程内只读一次，切换环境后必须丢弃缓存 */
    ErcerLink_Util::flushOptions();

    return $pluginOptions;
}

$data = array(
    'name'        => '我的博客',
    'url'         => 'https://blog.example.net',
    'image'       => 'https://blog.example.net/avatar.png',
    'description' => '一个技术博客',
    'user'        => '198.18.0.1',
);

/* 1. 开关关闭：不发信 */
reset_env(false, array('CommentToMail' => 'CommentToMail_Plugin'));
check('开关关闭不发信', ErcerLink_Mailer::notify($data, 99, '198.18.0.1'), false);
check('开关关闭 enabled 为 false', ErcerLink_Mailer::enabled(), false);

/* 2. 开关开启但 CommentToMail 未启用 */
reset_env(true, array());
check('依赖未启用不发信', ErcerLink_Mailer::notify($data, 99, '198.18.0.1'), false);
check('依赖未启用 enabled 为 false', ErcerLink_Mailer::enabled(), false);
check('依赖未启用 available 为 false', ErcerLink_Mailer::available(), false);

/* 3. 已启用但类未加载（等价于 5.x 或未提供 pushMail） */
reset_env(true, array('CommentToMail' => 'CommentToMail_Plugin'));
check('无直发入口时不发信', ErcerLink_Mailer::notify($data, 99, '198.18.0.1'), false);
check('无直发入口时 available 为 false', ErcerLink_Mailer::available(), false);
check('插件已启用时 pluginActive 为 true', ErcerLink_Mailer::pluginActive(), true);

/* 4. 加载 6.0 直发桩，此后 CommentToMail_Plugin 恒存在 */
require_once __DIR__ . '/commenttomail_stub.php';

reset_env(true, array('CommentToMail' => 'CommentToMail_Plugin'));
CommentToMail_Plugin::$result = true;
CommentToMail_Plugin::$pushed = array();

check('直发成功', ErcerLink_Mailer::notify($data, 99, '198.18.0.1'), true);
check('直发调用一次', count(CommentToMail_Plugin::$pushed), 1);
check('直发后 available 为 true', ErcerLink_Mailer::available(), true);

$task = CommentToMail_Plugin::$pushed[0];
check('直发主题', $task['subject'], '收到新的友链申请：我的博客 · 示例站点');
check('直发正文含站点名', strpos($task['htmlBody'], '我的博客') !== false, true);
check('直发正文含站点地址', strpos($task['htmlBody'], 'https://blog.example.net') !== false, true);
check('直发正文含管理链接', strpos($task['htmlBody'], 'https://example.com/admin/manage-links.php') !== false, true);
check('直发任务 coid', $task['coid'], 0);
check('直发任务不含 smtp', isset($task['smtp']), false);

/* 5. 直发被跳过（SMTP 未配置）：返回 false，但友链流程不受影响 */
reset_env(true, array('CommentToMail' => 'CommentToMail_Plugin'));
CommentToMail_Plugin::$result = false;
CommentToMail_Plugin::$pushed = array();

check('直发被跳过返回 false', ErcerLink_Mailer::notify($data, 99, '198.18.0.1'), false);
check('直发被跳过仍调用一次', count(CommentToMail_Plugin::$pushed), 1);

/* 6. XSS 载荷必须转义进正文 */
reset_env(true, array('CommentToMail' => 'CommentToMail_Plugin'));
CommentToMail_Plugin::$result = true;
CommentToMail_Plugin::$pushed = array();

$xss = $data;
$xss['name'] = '<script>alert(1)</script>恶意站';
$xss['description'] = '"><img src=x onerror=alert(1)>';
$xss['url'] = 'https://blog.example.net/?a="><script>';
$xss['image'] = 'javascript:alert(1)';

ErcerLink_Mailer::notify($xss, 99, '198.18.0.1');
$body = CommentToMail_Plugin::$pushed[0]['htmlBody'];

check('正文无原始 script 标签', strpos($body, '<script>alert(1)</script>') !== false, false);
check('正文无原始 img onerror', strpos($body, '<img src=x onerror=alert(1)>') !== false, false);
check('正文已实体化尖括号', strpos($body, '&lt;script&gt;') !== false, true);
check('非 http 图标退化为纯文本', strpos($body, '<a href="javascript:alert(1)"') !== false, false);

/* 7. 头字段注入：CRLF 不得进入主题 */
reset_env(true, array('CommentToMail' => 'CommentToMail_Plugin'));
CommentToMail_Plugin::$result = true;
CommentToMail_Plugin::$pushed = array();

$inject = $data;
$inject['name'] = "恶意\r\nBcc: victim@example.com";

ErcerLink_Mailer::notify($inject, 99, '198.18.0.1');
$subject = CommentToMail_Plugin::$pushed[0]['subject'];

check('主题无 CR', strpos($subject, "\r"), false);
check('主题无 LF', strpos($subject, "\n"), false);
check('主题保留攻击文本但不换行', strpos($subject, 'Bcc: victim@example.com') !== false, true);

/* 8. 站点标题缺失时主题退化为无站点后缀 */
reset_env(true, array('CommentToMail' => 'CommentToMail_Plugin'), '');
CommentToMail_Plugin::$result = true;
CommentToMail_Plugin::$pushed = array();

check('站点标题为空仍发信', ErcerLink_Mailer::notify($data, 99, '198.18.0.1'), true);
check('站点标题为空时主题退化', CommentToMail_Plugin::$pushed[0]['subject'], '收到新的友链申请：我的博客');
check('站点标题为空时正文用「本站」', strpos(CommentToMail_Plugin::$pushed[0]['htmlBody'], '来自 本站 的友链申请') !== false, true);

/* 结果 */

echo "\n";
foreach ($failures as $failure) {
    echo 'FAIL ' . $failure . "\n";
}

printf("通过 %d 项，失败 %d 项\n", $pass, count($failures));

exit(empty($failures) ? 0 : 1);
