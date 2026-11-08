<?php
/**
 * ErcerLink Action 端到端分支验证
 *
 * 每个场景在独立进程中运行（Action 失败时直接 exit），
 * 桩掉 Typecho 依赖后断言响应体与写库内容。
 *
 * 用法：php tests/action_case.php <scenario>
 */

define('__TYPECHO_ADMIN_DIR__', '/admin/');

/* 仅允许 CLI 运行：避免测试桩被 Web 直接访问 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/* Action 类在定义时就需要这些 Typecho 基类/接口，必须先声明 */
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

$scenario = isset($argv[1]) ? $argv[1] : '';

$pluginDir = dirname(__DIR__);
require_once $pluginDir . '/Util.php';
require_once $pluginDir . '/Mailer.php';
require_once $pluginDir . '/Action.php';

/* CommentToMail 6.0 直发通道的桩：只在相关场景声明。 */
if (in_array($scenario, array('notify_push', 'notify_push_skipped'), true)) {
    require_once __DIR__ . '/commenttomail_stub.php';
}

/* ---------------- 桩 ---------------- */

class StubQuery
{
    public $type;
    public $table;
    public $fields = array();
    public $rows = array();
    public $args = array();
    public $condition = '';

    public function __construct($type, $subject)
    {
        $this->type = $type;

        if ($type === 'select') {
            $this->fields[] = $subject;
        } else {
            $this->table = $subject;
        }
    }

    public function from($table)
    {
        $this->table = $table;
        return $this;
    }

    public function where($condition)
    {
        $this->condition = $condition;
        $this->args = array_slice(func_get_args(), 1);
        return $this;
    }

    public function limit($n)
    {
        return $this;
    }

    public function rows(array $rows)
    {
        $this->rows = $rows;
        return $this;
    }
}

class Typecho_Db
{
    public static $existingUrl = null;
    public static $inserted = null;
    public static $selectCalls = 0;

    public static function get()
    {
        return new self();
    }

    public function select($field)
    {
        return new StubQuery('select', $field);
    }

    public function insert($table)
    {
        return new StubQuery('insert', $table);
    }

    public function getPrefix()
    {
        return 'typecho_';
    }

    public function fetchRow($query)
    {
        self::$selectCalls++;

        if (self::$existingUrl !== null && in_array(self::$existingUrl, $query->args, true)) {
            return array('lid' => 7);
        }

        return array();
    }

    public function query($query)
    {
        if ($query->type !== 'insert') {
            return 0;
        }

        if ($query->table === 'table.links') {
            self::$inserted = $query->rows;
            return 99;
        }

        return 1;
    }
}

class StubOptions
{
    public $siteUrl = 'https://example.com';
    public $index = 'https://example.com';
    public $pluginUrl = 'https://example.com/usr/plugins';
    public $title = '示例站点';
    public $timezone = 'Asia/Shanghai';
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

/**
 * 替换网络层，直接返回构造好的 siteverify 响应
 */
class StubAction extends ErcerLink_Action
{
    public static $response = '';
    public static $calls = 0;

    protected function postForm($url, array $fields)
    {
        self::$calls++;
        return self::$response;
    }
}

/* ---------------- 场景准备 ---------------- */

$pluginOptions = new stdClass();
$pluginOptions->turnstileSecretKey = '';
$pluginOptions->proxyHeader = 'NONE';
$pluginOptions->enableEmailNotify = '0';

Typecho_Widget::$options = new StubOptions();
Typecho_Widget::$options->plugins['ErcerLink'] = $pluginOptions;

$token = str_repeat('b', 32);
$ip = '198.18.' . mt_rand(1, 254) . '.' . mt_rand(1, 254);

$_SERVER = array(
    'REQUEST_METHOD' => 'POST',
    'REMOTE_ADDR'    => $ip,
    'HTTP_HOST'      => 'example.com',
    'SERVER_PORT'    => '443',
    'HTTPS'          => 'on',
    'HTTP_ORIGIN'    => 'https://example.com',
    'HTTP_X_ERCERLINK_CSRF' => $token
);

$_COOKIE = array('ercerlink_csrf' => $token);

$_POST = array(
    'host_name'  => '我的博客',
    'host_url'   => 'https://blog.example.net',
    'host_png'   => 'https://blog.example.net/avatar.png',
    'host_msg'   => '一个技术博客',
    'host_yes'   => '1',
    'ercerlink_csrf' => $token
);

switch ($scenario) {
    case 'ok':
        break;

    case 'ok_with_trailing_slash_url':
        $_POST['host_url'] = 'https://Blog.Example.NET/';
        break;

    case 'get':
        $_SERVER['REQUEST_METHOD'] = 'GET';
        break;

    case 'cross_origin':
        $_SERVER['HTTP_ORIGIN'] = 'https://evil.example';
        break;

    case 'no_csrf_cookie':
        $_COOKIE = array();
        break;

    case 'csrf_mismatch':
        $_SERVER['HTTP_X_ERCERLINK_CSRF'] = str_repeat('c', 32);
        break;

    case 'rate_limited':
        // 预置 5 条窗口内记录，第 6 次应被限流
        $dir = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR
            . 'ercerlink-' . substr(md5(ErcerLink_Util::siteHost()), 0, 12);
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $now = time();
        file_put_contents(
            $dir . DIRECTORY_SEPARATOR . md5($ip) . '.log',
            implode("\n", array($now, $now, $now, $now, $now)) . "\n"
        );
        break;

    case 'js_url':
        $_POST['host_url'] = 'javascript:alert(document.cookie)';
        break;

    case 'missing_agree':
        unset($_POST['host_yes']);
        break;

    case 'missing_name':
        $_POST['host_name'] = '   ';
        break;

    case 'name_too_long':
        $_POST['host_name'] = str_repeat('名', 201);
        break;

    case 'duplicate':
        Typecho_Db::$existingUrl = 'https://blog.example.net';
        break;

    case 'xss_name':
        $_POST['host_name'] = '<img src=x onerror=alert(1)>恶意站';
        break;

    case 'image_equals_url':
        $_POST['host_png'] = $_POST['host_url'];
        break;

    case 'notify_on_without_plugin':
        $pluginOptions->enableEmailNotify = '1';
        break;

    case 'notify_on_with_plugin':
        $pluginOptions->enableEmailNotify = '1';
        Typecho_Plugin::$activated = array('CommentToMail' => 'CommentToMail_Plugin');
        break;

    case 'notify_push':
        // CommentToMail 6.0：存在 pushMail()，走直发通道
        $pluginOptions->enableEmailNotify = '1';
        Typecho_Plugin::$activated = array('CommentToMail' => 'CommentToMail_Plugin');
        break;

    case 'notify_push_skipped':
        // 直发被 CommentToMail 跳过（如 SMTP 未配置）：友链仍应入库成功
        $pluginOptions->enableEmailNotify = '1';
        Typecho_Plugin::$activated = array('CommentToMail' => 'CommentToMail_Plugin');
        CommentToMail_Plugin::$result = false;
        break;

    case 'turnstile_secret_without_token':
        $pluginOptions->turnstileSecretKey = 'stub-secret';
        break;

    case 'turnstile_secret_blank_padded':
        // 历史上未 trim 的 secret 会让 empty() 判断失效并放行空 token
        $pluginOptions->turnstileSecretKey = '   ';
        break;

    case 'turnstile_token_too_long':
        $pluginOptions->turnstileSecretKey = 'stub-secret';
        $_POST['cf-turnstile-response'] = str_repeat('x', 4096);
        break;

    case 'turnstile_ok':
        $pluginOptions->turnstileSecretKey = 'stub-secret';
        $_POST['cf-turnstile-response'] = 'valid-token';
        StubAction::$response = json_encode(array(
            'success'  => true,
            'action'   => 'link_add',
            'hostname' => 'example.com'
        ));
        break;

    case 'turnstile_wrong_action':
        $pluginOptions->turnstileSecretKey = 'stub-secret';
        $_POST['cf-turnstile-response'] = 'valid-token';
        StubAction::$response = json_encode(array(
            'success'  => true,
            'action'   => 'comment',
            'hostname' => 'example.com'
        ));
        break;

    case 'turnstile_wrong_host':
        $pluginOptions->turnstileSecretKey = 'stub-secret';
        $_POST['cf-turnstile-response'] = 'valid-token';
        StubAction::$response = json_encode(array(
            'success'  => true,
            'action'   => 'link_add',
            'hostname' => 'evil.example'
        ));
        break;

    case 'turnstile_rejected':
        $pluginOptions->turnstileSecretKey = 'stub-secret';
        $_POST['cf-turnstile-response'] = 'spent-token';
        StubAction::$response = json_encode(array('success' => false));
        break;

    default:
        fwrite(STDERR, "unknown scenario\n");
        exit(2);
}

/* ---------------- 执行 ---------------- */

if (getenv('ERCERLINK_DUMP') === '1') {
    register_shutdown_function(function () {
        $payload = array(
            'inserted' => Typecho_Db::$inserted,
            'pushed'   => class_exists('CommentToMail_Plugin', false)
                ? CommentToMail_Plugin::$pushed : array()
        );

        file_put_contents(
            dirname(__DIR__) . '/tests/.dump.json',
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );
    });
}

ob_start();
$action = new StubAction();
$action->link_add();
$body = ob_get_clean();

// 走到这里说明没有 exit（正常不会发生）
echo $body;
