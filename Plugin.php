<?php
/**
* <a href='https://blog.warhut.cn/dmbj/1109.html' target='_blank'>handsome友链快速申请插件</a>
* 原作者：<a href='https://blog.ccdalao.cn/archives/197/' target='_blank'>二C</a>
*
* @package ErcerLink
* @author 歆宋
* @version 1.5.0
* @link https://blog.warhut.cn
*/
require_once dirname(__FILE__) . '/Util.php';
require_once dirname(__FILE__) . '/Mailer.php';
require_once dirname(__FILE__) . '/Action.php';

class ErcerLink_Plugin implements Typecho_Plugin_Interface
{
    const ROUTE_NAME = 'route_to_link_add';
    const ROUTE_PATH = '/link_add';
    const ROUTE_ACTION = 'link_add';
    const LOCAL_SCRIPT_PATH = '/js/ErcerLinks.js';
    const TURNSTILE_API_URL = 'https://challenges.cloudflare.com/turnstile/v0/api.js';
    const CONFIG_VAR = 'ercerLinkConfig';

    /**
     * 启用插件
     */
    public static function activate()
    {
        Helper::addRoute(self::ROUTE_NAME, self::ROUTE_PATH, 'ErcerLink_Action', self::ROUTE_ACTION);
        Typecho_Plugin::factory('Widget_Archive')->footer = array('ErcerLink_Plugin', 'footer');
    }

    /**
     * 停用插件
     */
    public static function deactivate()
    {
        Helper::removeRoute(self::ROUTE_NAME);
    }

    /**
     * 插件配置
     *
     * @param Typecho_Widget_Helper_Form $form
     */
    public static function config(Typecho_Widget_Helper_Form $form)
    {
        $usage = new Typecho_Widget_Helper_Form_Element_Textarea(
            'usage',
            null,
            '',
            _t('使用说明'),
            self::usageDescription()
        );
        $form->addInput($usage);
        $usage->input->setAttribute('style', 'display: none;');

        $path = new Typecho_Widget_Helper_Form_Element_Text(
            'path',
            null,
            '',
            _t('CDN加速地址'),
            '<p>留空使用插件自带脚本；如需 CDN 加速，请填写以 <code>.js</code> 结尾的 HTTP(S) 地址。</p>'
            . '<code>https://cdn.example.com/ErcerLinks.js</code>'
        );
        $path->addRule(array('ErcerLink_Util', 'isValidScriptUrl'), _t('请输入以 .js 结尾的有效 HTTP(S) 地址'));
        $form->addInput($path);

        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text(
            'turnstileSiteKey',
            null,
            '',
            _t('Turnstile Site Key'),
            '<p>前端验证码显示所需。填写后自动启用验证码，留空则关闭。</p>'
            . '<p>获取地址：<a href="https://dash.cloudflare.com/" target="_blank">Cloudflare Dashboard</a></p>'
        ));

        $form->addInput(new Typecho_Widget_Helper_Form_Element_Password(
            'turnstileSecretKey',
            null,
            '',
            _t('Turnstile Secret Key'),
            '<p>后端验证所需，请勿泄露。</p>'
        ));

        $form->addInput(new Typecho_Widget_Helper_Form_Element_Select(
            'proxyHeader',
            array(
                'NONE'            => _t('不信任任何转发头（默认）'),
                'CF-CONNECTING-IP' => _t('Cloudflare'),
                'X-FORWARDED-FOR' => _t('X-Forwarded-For'),
                'X-REAL-IP'       => _t('X-Real-IP')
            ),
            'NONE',
            _t('客户端 IP 来源'),
            '<p>站点未接入 CDN / 反向代理时请保持默认，否则限流可能被伪造请求头绕过。</p>'
        ));

        $form->addInput(self::emailNotifyInput());
    }

    /**
     * 个人配置（无）
     *
     * @param Typecho_Widget_Helper_Form $form
     */
    public static function personalConfig(Typecho_Widget_Helper_Form $form)
    {
    }

    /**
     * 输出前端资源
     */
    public static function footer()
    {
        $options = ErcerLink_Util::options();
        $siteKey = trim(ErcerLink_Util::toStr($options->turnstileSiteKey));

        self::configScript(self::buildFrontConfig($siteKey));

        if ($siteKey !== '') {
            // Turnstile 的 onload 回调可能早于本插件脚本执行，先在内联脚本里
            // 建立回调占位，等 ErcerLink 就绪后再转交，避免回调丢失。
            echo '<script>window.ercerLinkTurnstileFired=false;'
                . 'window.ercerLinkTurnstileReady=function(){'
                . 'window.ercerLinkTurnstileFired=true;'
                . 'if(window.ErcerLink&&typeof window.ErcerLink.onTurnstileReady==="function"){'
                . 'window.ErcerLink.onTurnstileReady();'
                . '}};</script>';
            self::scriptTag(self::TURNSTILE_API_URL . '?render=explicit&onload=ercerLinkTurnstileReady', 'async defer');
        }

        self::scriptTag(self::scriptUrl($options), 'defer');
    }

    /**
     * 组装前端配置
     *
     * @param string $siteKey
     * @return array
     */
    private static function buildFrontConfig($siteKey)
    {
        $index = ErcerLink_Util::toStr(Helper::options()->index);

        return array(
            'endpoint'   => rtrim($index, '/') . self::ROUTE_PATH,
            'csrf'       => ErcerLink_Util::csrfToken(),
            'csrfField'  => ErcerLink_Util::CSRF_FIELD,
            'csrfHeader' => ErcerLink_Util::CSRF_HEADER_NAME,
            'siteKey'    => $siteKey,
            'success'    => ErcerLink_Action::SUCCESS_RESPONSE,
            'messages'   => self::frontMessages()
        );
    }

    /**
     * 前端文案
     *
     * @return array
     */
    private static function frontMessages()
    {
        return array(
            'captchaTitle'    => _t('请完成验证'),
            'captchaMessage'  => _t('请先完成人机验证'),
            'requestFail'     => _t('请求失败，请稍后重试'),
            'successTitle'    => _t('提交成功'),
            'successMessage'  => _t('等待站长审核哟！'),
            'failTitle'       => _t('提交失败'),
            'submitting'      => _t('提交中...')
        );
    }

    /**
     * 输出配置脚本
     *
     * @param array $config
     */
    private static function configScript(array $config)
    {
        $json = json_encode(
            $config,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
        );

        if ($json === false) {
            return;
        }

        echo '<script>window.' . self::CONFIG_VAR . '=' . $json . ';</script>';
    }

    /**
     * 输出 script 标签
     *
     * @param string $src
     * @param string $attributes
     */
    private static function scriptTag($src, $attributes = '')
    {
        $src = htmlspecialchars($src, ENT_QUOTES, 'UTF-8');
        $attributes = trim($attributes);

        echo '<script src="' . $src . '"' . ($attributes === '' ? '' : ' ' . $attributes) . '></script>';
    }

    /**
     * 解析前端脚本地址
     *
     * @param object $options
     * @return string
     */
    private static function scriptUrl($options)
    {
        $path = trim(ErcerLink_Util::toStr($options->path));

        if ($path !== '' && ErcerLink_Util::isValidScriptUrl($path)) {
            return $path;
        }

        $pluginUrl = ErcerLink_Util::toStr(Helper::options()->pluginUrl);

        return rtrim($pluginUrl, '/') . '/ErcerLink' . self::LOCAL_SCRIPT_PATH;
    }

    /**
     * 邮件通知配置项
     *
     * @return Typecho_Widget_Helper_Form_Element_Select
     */
    private static function emailNotifyInput()
    {
        $element = new Typecho_Widget_Helper_Form_Element_Select(
            'enableEmailNotify',
            array('0' => _t('关闭'), '1' => _t('开启')),
            '0',
            _t('邮件通知'),
            '<p>开启后，新友链提交会通过 CommentToMail 直接发信通知站长。</p>'
            . '<p><strong>提示：</strong>需安装并启用 '
            . '<a href="https://github.com/ououmm/CommentToMail" target="_blank">CommentToMail</a> '
            . '6.0 及以上版本，SMTP 与异步设置在该插件中配置。</p>'
        );

        $element->addRule(array('ErcerLink_Plugin', 'validateEmailNotify'), _t('插件依赖错误'));

        return $element;
    }

    /**
     * 校验邮件通知依赖
     *
     * @param mixed $value
     * @return bool|string
     */
    public static function validateEmailNotify($value)
    {
        if (ErcerLink_Util::toStr($value) !== '1') {
            return true;
        }

        try {
            $plugins = Typecho_Plugin::export();
        } catch (Throwable $e) {
            return _t('请先安装并启用 CommentToMail 插件');
        }

        if (!isset($plugins['activated'][ErcerLink_Mailer::PLUGIN_NAME])) {
            return _t('请先安装并启用 CommentToMail 插件');
        }

        return ErcerLink_Mailer::available() ? true : _t('CommentToMail 版本过低，请升级到 6.0 及以上');
    }

    /**
     * 使用说明
     *
     * @return string
     */
    private static function usageDescription()
    {
        return '<div style="background:#f0f8ff;padding:15px;border-radius:8px;border-left:4px solid #1890ff;">'
            . '<h4 style="margin:0 0 10px;color:#1890ff;">💡 使用指南</h4>'
            . '<ol style="margin:0;padding-left:20px;">'
            . '<li><strong>添加友链申请区域：</strong>在友链页面添加 <code>&lt;div id="postLink"&gt;&lt;/div&gt;</code></li>'
            . '<li><strong>Pjax 支持：</strong>在回调函数内调用 <code>pjax_Link();</code></li>'
            . '<li><strong>验证码：</strong>填写 Cloudflare Turnstile 密钥后自动启用。</li>'
            . '<li><strong>依赖：</strong>前端脚本为原生 JS，不依赖 jQuery。</li>'
            . '</ol></div>';
    }
}
