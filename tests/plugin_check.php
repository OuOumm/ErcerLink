<?php
/**
 * ErcerLink Plugin 输出与配置验证
 *
 * 断言 footer 注入的前端配置 JSON、脚本标签属性、
 * 以及配置项的校验规则（CDN 地址、邮件通知依赖）。
 *
 * 运行：php tests/plugin_check.php
 */

define('__TYPECHO_ADMIN_DIR__', '/admin/');

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

interface Widget_Interface_Do
{
}

interface Typecho_Plugin_Interface
{
    public static function activate();

    public static function deactivate();

    public static function config(Typecho_Widget_Helper_Form $form);

    public static function personalConfig(Typecho_Widget_Helper_Form $form);
}

class StubAttr
{
    public $attributes = array();

    public function setAttribute($name, $value)
    {
        $this->attributes[$name] = $value;
    }
}

class StubInput
{
    public $name;
    public $value;
    public $label;
    public $description;
    public $rules = array();

    public function __construct($name, $value, $label, $description)
    {
        $this->name = $name;
        $this->value = $value;
        $this->label = $label;
        $this->description = $description;
        $this->input = new StubAttr();
    }

    public function addRule($rule, $message)
    {
        $this->rules[] = array($rule, $message);

        return $this;
    }
}

class Typecho_Widget_Helper_Form
{
    public $inputs = array();

    public function addInput($input)
    {
        $this->inputs[] = $input;
    }
}

class Typecho_Widget_Helper_Form_Element_Text extends StubInput
{
    public function __construct($name, $options, $value, $label, $description)
    {
        parent::__construct($name, $value, $label, $description);
    }
}

class Typecho_Widget_Helper_Form_Element_Password extends Typecho_Widget_Helper_Form_Element_Text
{
}

class Typecho_Widget_Helper_Form_Element_Select extends Typecho_Widget_Helper_Form_Element_Text
{
}

class Typecho_Widget_Helper_Form_Element_Textarea extends Typecho_Widget_Helper_Form_Element_Text
{
}

class StubOptions
{
    public $siteUrl = 'https://example.com';
    public $index = 'https://example.com/blog/';
    public $pluginUrl = 'https://example.com/usr/plugins';
    public $plugins = array();

    public function plugin($name)
    {
        return isset($this->plugins[$name]) ? $this->plugins[$name] : new stdClass();
    }
}

abstract class Typecho_Widget
{
    public static $options;

    public static function widget($name)
    {
        return $name === 'Widget_Options' ? self::$options : new stdClass();
    }
}

class Typecho_Plugin
{
    public static $activated = array();

    public static function export()
    {
        return array('activated' => self::$activated, 'handles' => array());
    }

    public static function factory($name)
    {
        return new stdClass();
    }
}

class Helper
{
    public static $routes = array();

    public static function addRoute($name, $path, $widget, $action)
    {
        self::$routes[$name] = array($path, $widget, $action);
    }

    public static function removeRoute($name)
    {
        unset(self::$routes[$name]);
    }

    public static function options()
    {
        return Typecho_Widget::$options;
    }
}

function _t($text)
{
    return $text;
}

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

$pluginDir = dirname(__DIR__);

// 预热配置，供 Util 读取
$pluginOptions = new stdClass();
$pluginOptions->path = '';
$pluginOptions->turnstileSiteKey = '';
$pluginOptions->turnstileSecretKey = '';
$pluginOptions->proxyHeader = 'NONE';
$pluginOptions->enableEmailNotify = '0';

Typecho_Widget::$options = new StubOptions();
Typecho_Widget::$options->plugins['ErcerLink'] = $pluginOptions;

require_once $pluginDir . '/Util.php';
require_once $pluginDir . '/Action.php';
require_once $pluginDir . '/Plugin.php';

/* 1. 路由注册 */
ErcerLink_Plugin::activate();
check('激活注册路由', isset(Helper::$routes['route_to_link_add']), true);
check('路由路径', Helper::$routes['route_to_link_add'][0], '/link_add');
check('路由 Action 名', Helper::$routes['route_to_link_add'][2], 'link_add');

ErcerLink_Plugin::deactivate();
check('停用移除路由', isset(Helper::$routes['route_to_link_add']), false);
ErcerLink_Plugin::activate();

/* 2. 无验证码时的 footer 输出 */
ob_start();
ErcerLink_Plugin::footer();
$html = ob_get_clean();

check('注入配置变量', strpos($html, 'window.ercerLinkConfig=') !== false, true);
check('未配置 siteKey 时不加载 Turnstile', strpos($html, 'challenges.cloudflare.com') === false, true);
check('加载本地脚本', strpos($html, '/usr/plugins/ErcerLink/js/ErcerLinks.js') !== false, true);
check('脚本带 defer', strpos($html, 'defer') !== false, true);
check('脚本使用绝对地址', strpos($html, '<script src="https://example.com/usr/plugins') !== false, true);

$json = null;

if (preg_match('/window\.ercerLinkConfig=(\{.*?\});<\/script>/s', $html, $matches)) {
    $json = json_decode($matches[1], true);
}

check('配置 JSON 可解析', is_array($json), true);
check('endpoint 为绝对地址', $json['endpoint'], 'https://example.com/blog/link_add');
check('endpoint 不含双斜杠', strpos($json['endpoint'], '//link_add') === false, true);
check('csrf 为 32 位十六进制', preg_match('/^[a-f0-9]{32}$/', $json['csrf']), 1);
check('csrfField 一致', $json['csrfField'], 'ercerlink_csrf');
check('csrfHeader 一致', $json['csrfHeader'], 'X-ErcerLink-CSRF');
check('success 标记', $json['success'], '200');
check('文案齐全', isset($json['messages']['submitting']), true);
check('配置不含 secret', strpos($matches[1], 'turnstileSecret') === false, true);

// 尖括号必须被 JSON_HEX_TAG 转义，防止 </script> 提前闭合
check('配置转义尖括号', strpos($matches[1], '<') === false, true);

/* 3. 配置了 siteKey 时的 footer 输出 */
$pluginOptions->turnstileSiteKey = '0x4AAAAAAA_site_key';

ob_start();
ErcerLink_Plugin::footer();
$htmlWithCaptcha = ob_get_clean();

check('加载 Turnstile 脚本', strpos($htmlWithCaptcha, 'turnstile/v0/api.js') !== false, true);
check('Turnstile 显式渲染', strpos($htmlWithCaptcha, 'render=explicit') !== false, true);
check('Turnstile onload 回调', strpos($htmlWithCaptcha, 'onload=ercerLinkTurnstileReady') !== false, true);
check('siteKey 注入配置', strpos($htmlWithCaptcha, '0x4AAAAAAA_site_key') !== false, true);
check('Turnstile 回调占位在脚本前', strpos($htmlWithCaptcha, 'window.ercerLinkTurnstileReady=function') !== false, true);
check(
    '回调占位早于 Turnstile 脚本',
    strpos($htmlWithCaptcha, 'window.ercerLinkTurnstileReady=function')
        < strpos($htmlWithCaptcha, 'turnstile/v0/api.js'),
    true
);

/* 4. CDN 地址：恶意值不得进入 script src */
$pluginOptions->path = 'javascript:alert(1)';
check('拒绝 javascript: CDN', ErcerLink_Util::isValidScriptUrl($pluginOptions->path), false);

ob_start();
ErcerLink_Plugin::footer();
$htmlBadCdn = ob_get_clean();
check('恶意 CDN 回落到本地脚本', strpos($htmlBadCdn, 'javascript:') === false, true);

$pluginOptions->path = 'https://cdn.example.com/ErcerLinks.js';
ob_start();
ErcerLink_Plugin::footer();
check('合法 CDN 生效', strpos(ob_get_clean(), 'https://cdn.example.com/ErcerLinks.js') !== false, true);
$pluginOptions->path = '';

/* 5. 配置表单校验规则 */
$form = new Typecho_Widget_Helper_Form();
ErcerLink_Plugin::config($form);

$byName = array();

foreach ($form->inputs as $input) {
    $byName[$input->name] = $input;
}

check('存在 CDN 配置项', isset($byName['path']), true);
check('CDN 项带校验规则', $byName['path']->rules[0][0], array('ErcerLink_Util', 'isValidScriptUrl'));
check('存在 IP 来源配置', isset($byName['proxyHeader']), true);
check('存在邮件通知配置', isset($byName['enableEmailNotify']), true);

/* 6. 邮件通知依赖校验 */
check('关闭时不校验依赖', ErcerLink_Plugin::validateEmailNotify('0'), true);
check('开启但未装 CommentToMail 时拒绝', ErcerLink_Plugin::validateEmailNotify('1'), '请先安装并启用 CommentToMail 插件');

Typecho_Plugin::$activated = array('CommentToMail' => 'CommentToMail_Plugin');
check('开启且已装时通过', ErcerLink_Plugin::validateEmailNotify('1'), true);

echo "\n";

foreach ($failures as $failure) {
    echo 'FAIL ' . $failure . "\n";
}

printf("通过 %d 项，失败 %d 项\n", $pass, count($failures));

exit(empty($failures) ? 0 : 1);
