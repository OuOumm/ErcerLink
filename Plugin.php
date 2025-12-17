<?php
/**
* <a href='https://blog.warhut.cn/dmbj/1109.html' target='_blank'>handsome友链快速申请插件</a>
* 原作者：<a href='https://blog.ccdalao.cn/archives/197/' target='_blank'>二C</a>
*
* @package ErcerLink
* @author 歆宋
* @version 1.2.0
* @link https://blog.warhut.cn
*/
class ErcerLink_Plugin implements Typecho_Plugin_Interface {
    /* 插件配置方法 */
    public static function config(Typecho_Widget_Helper_Form $form) {
        // 配置分组：基础设置
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Textarea('usage', NULL, '', _t('使用说明'), 
            '<div style="background: #f0f8ff; padding: 15px; border-radius: 8px; border-left: 4px solid #1890ff;">
                <h4 style="margin: 0 0 10px 0; color: #1890ff;">💡 使用指南</h4>
                <ol style="margin: 0; padding-left: 20px;">
                    <li><strong>添加友链申请区域：</strong>在友链页面添加 <code>&lt;div id="postLink"&gt;&lt;/div&gt;</code></li>
                    <li><strong>Pjax支持：</strong>如使用pjax，请在回调函数内添加 <code>pjax_Link();</code></li>
                    <li><strong>验证码配置：</strong>填写Cloudflare Turnstile信息以启用安全防护，默认为空不启用。</li>
                </ol>
            </div>'));
        
        // 隐藏说明性输入框
        $usage = $form->getInput('usage');
        $usage->input->setAttribute('style', 'display: none;');
        
        // 配置分组：资源加载
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text('path', NULL, '', _t('CDN加速地址'), 
            '<p>默认使用本地JS文件，如需CDN加速，请填写完整URL：</p>
            <code>https://cdn.example.com/ErcerLinks.js</code>'));
        
        // 配置分组：安全设置
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text('turnstileSiteKey', NULL, '', _t('Turnstile Site Key'), 
            '<p>前端验证码显示所需，获取地址：<a href="https://dash.cloudflare.com/" target="_blank">Cloudflare Dashboard</a></p>'));
        
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Password('turnstileSecretKey', NULL, '', _t('Turnstile Secret Key'), 
            '<p>后端验证码验证所需，请勿泄露！</p>'));
        
        // 配置分组：通知设置
        $enableEmailNotify = new Typecho_Widget_Helper_Form_Element_Select('enableEmailNotify', 
            array('0' => _t('关闭'), '1' => _t('开启')), '0', _t('邮件通知'), 
            '<p>开启后，当有新链接提交时，将通过CommentToMail插件发送邮件通知。</p>
            <p><strong>提示：</strong>需安装并启用 <a href="https://github.com/ououmm/CommentToMail" target="_blank">CommentToMail</a> 插件才能使用此功能。</p>');
        
        // 添加自定义验证规则，检测CommentToMail插件是否已启用
        $enableEmailNotify->addRule(function($value) {
            if ($value == '1') {
                // 1. 获取所有已启用的插件列表（Typecho内置方法）
                $activatedPlugins = Typecho_Plugin::export();
                // 2. 检测插件是否在启用列表中（插件标识需完全匹配）
                if (!isset($activatedPlugins['CommentToMail'])) {
                    return _t('请先安装并启用 CommentToMail 插件！');
                }
            }
            return true;
        }, _t('插件依赖错误'));
        
        $form->addInput($enableEmailNotify);
    }
    
    /* 个人用户的配置方法 */
    public static function personalConfig(Typecho_Widget_Helper_Form $form) {}
    
    /* CDN路径验证 */
    public static function validateCdnPath($path) {
        return empty($path) || filter_var($path, FILTER_VALIDATE_URL) !== false;
    }
    
    /* 插件实现方法 */
    public static function render() {}
    
    public static function activate() {
        Helper::addRoute("route_to_link_add", "/link_add", "ErcerLink_Action", 'link_add');
        Typecho_Plugin::factory('Widget_Archive')->footer = array('ErcerLink_Plugin', 'footer');
    }
    
    public static function deactivate() {
        Helper::removeRoute("route_to_link_add");
    }
    
    public static function footer() {
        $pluginOptions = Typecho_Widget::widget('Widget_Options')->plugin('ErcerLink');
        $path = htmlspecialchars($pluginOptions->path);
        $path = empty($path)?Helper::options()->pluginUrl . '/ErcerLink/js/ErcerLinks.js':$path;
        
        // 添加Turnstile Site Key全局变量
        if (!empty($pluginOptions->turnstileSiteKey)) {
            echo '<script >window.ercerLinkTurnstileSiteKey = "' . $pluginOptions->turnstileSiteKey . '";</script>';
            echo '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>';
        }
        echo '<script src="' . $path . '"></script>';
    }
}