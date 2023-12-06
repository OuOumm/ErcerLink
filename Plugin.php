<?php
/**
* <a href='https://blog.warhut.cn/dmbj/1109.html' target='_blank'>handsome友链快速申请插件</a>
* 原作者：<a href='https://blog.ccdalao.cn/archives/197/' target='_blank'>二C</a>
*
* @package ErcerLink
* @author 二C,歆宋
* @version 1.1.0
* @link https://blog.warhut.cn
*/
class ErcerLink_Plugin implements Typecho_Plugin_Interface {
    /* 插件配置方法 */
    public static function config(Typecho_Widget_Helper_Form $form) {
        $text = new Typecho_Widget_Helper_Form_Element_Text('path', NULL, '', _t('CDN路径'), '
        <p>1.默认使用本地文件，如需使用CDN加速，请填写完整路径，例如：</p>
        <pre>https://jsd.onmicrosoft.cn/gh/OuOumm/omo/data/js/ErcerLinks.js</pre>
        <p>2.使用方法，在友联页面添加以下内容：</p>
        <pre>&lt;div id=&quot;postLink&quot;&gt;&lt;/div&gt;</pre>
        <p>3.如果使用pjax，请在回调函数内增加以下代码：</p>
        <pre>pjax_Link();</pre>');
        $form->addInput($text);
    }
    
    /* 个人用户的配置方法 */
    public static function personalConfig(Typecho_Widget_Helper_Form $form) {}
    
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
        echo '<script type="text/javascript" src="' . $path . '"></script>';
    }
}