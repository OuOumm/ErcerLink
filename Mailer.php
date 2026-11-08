<?php
/**
 * ErcerLink 邮件通知
 *
 * 友链提交成功后通知站长。CommentToMail 6.0 起取消了数据库队列表，改为 SMTP
 * 直发，并对外暴露 CommentToMail_Plugin::pushMail() 作为跨插件发信入口。
 * 本插件只走这条通道：SMTP 参数与发送策略全部由 CommentToMail 决定，这里
 * 不复制一份配置读取逻辑，避免两个插件各存一套 SMTP 设置而漂移。
 *
 * 通知属于附属功能：任何异常都被吞掉并写 error_log，绝不影响友链入库结果。
 *
 * @package ErcerLink
 */
class ErcerLink_Mailer
{
    /** 邮件通知开关的开启值 */
    const NOTIFY_ENABLED = '1';
    /** 依赖的插件目录名 */
    const PLUGIN_NAME = 'CommentToMail';
    /** 主题行长度上限 */
    const MAX_SUBJECT_LENGTH = 120;

    /**
     * 发送一封友链申请通知
     *
     * @param array $data    已清洗的友链数据（name/url/image/description/user）
     * @param int $insertId  typecho_links 主键
     * @param string $ip     提交者 IP
     * @return bool 是否已交给发送流程
     */
    public static function notify(array $data, $insertId, $ip)
    {
        if (!self::enabled()) {
            return false;
        }

        try {
            $site = self::siteInfo();

            return self::push(self::subject($data, $site), self::body($data, $site, $insertId, $ip));
        } catch (Throwable $e) {
            self::log('邮件通知失败: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * 通知开关已开且 CommentToMail 可用
     *
     * @return bool
     */
    public static function enabled()
    {
        if (ErcerLink_Util::opt('enableEmailNotify') !== self::NOTIFY_ENABLED) {
            return false;
        }

        return self::available();
    }

    /**
     * CommentToMail 是否已启用
     *
     * @return bool
     */
    public static function pluginActive()
    {
        if (!class_exists('Typecho_Plugin')) {
            return false;
        }

        try {
            $export = Typecho_Plugin::export();
        } catch (Throwable $e) {
            return false;
        }

        return isset($export['activated'][self::PLUGIN_NAME]);
    }

    /**
     * CommentToMail 是否可用
     *
     * 已启用且提供 6.0+ 的直发入口。旧版本没有 pushMail()，也就没有可用的
     * 发信通道，一律视为不可用。
     *
     * @return bool
     */
    public static function available()
    {
        return self::pluginActive()
            && class_exists('CommentToMail_Plugin')
            && method_exists('CommentToMail_Plugin', 'pushMail');
    }

    /**
     * 走 CommentToMail 的直发通道
     *
     * @param string $subject
     * @param string $html
     * @return bool
     */
    private static function push($subject, $html)
    {
        try {
            return CommentToMail_Plugin::pushMail(array(
                'subject'  => $subject,
                'htmlBody' => $html,
                'coid'     => 0,
            )) === true;
        } catch (Throwable $e) {
            self::log('直发失败: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * 邮件主题：单行纯文本，不含标签与换行
     *
     * @param array $data
     * @param array $site
     * @return string
     */
    private static function subject(array $data, array $site)
    {
        $name = self::header($data['name']);
        $title = $site['title'] === '' ? '' : ' · ' . self::header($site['title']);

        $subject = $name === ''
            ? '收到新的友链申请' . $title
            : '收到新的友链申请：' . $name . $title;

        return self::text($subject, self::MAX_SUBJECT_LENGTH);
    }

    /**
     * 邮件正文（内联样式，不依赖 CommentToMail 的评论模板）
     *
     * @param array $data
     * @param array $site
     * @param int $insertId
     * @param string $ip
     * @return string
     */
    private static function body(array $data, array $site, $insertId, $ip)
    {
        $rows = array(
            '站点名称' => self::esc($data['name']),
            '站点地址' => self::link($data['url']),
            '图标地址' => self::link($data['image']),
            '站点描述' => nl2br(self::esc($data['description'])),
            '提交 IP'  => self::esc($ip),
            '记录 ID'  => (int) $insertId,
        );

        $cells = '';
        foreach ($rows as $label => $value) {
            $cells .= '<tr>'
                . '<td style="padding:8px 12px 8px 0;font-size:13px;color:#8a9199;white-space:nowrap;vertical-align:top;">'
                . self::esc($label) . '</td>'
                . '<td style="padding:8px 0;font-size:14px;color:#2b3138;line-height:1.6;word-break:break-all;">'
                . $value . '</td>'
                . '</tr>';
        }

        $button = '';
        if ($site['url'] !== '') {
            $manage = $site['url'] . '/' . trim((string) __TYPECHO_ADMIN_DIR__, '/') . '/manage-links.php';
            $button = '<div style="padding-top:18px;">'
                . '<a href="' . self::esc($manage) . '" style="display:inline-block;padding:10px 22px;'
                . 'background:#2f6fed;color:#ffffff;font-size:14px;text-decoration:none;border-radius:6px;">'
                . '前往后台管理友链</a></div>';
        }

        return '<div style="margin:0;padding:24px 12px;background:#f4f5f7;">'
            . '<div style="max-width:600px;margin:0 auto;background:#ffffff;border-radius:10px;'
            . 'padding:24px 28px;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\','
            . 'Helvetica,Arial,sans-serif;">'
            . '<div style="font-size:16px;font-weight:600;color:#1f2429;padding-bottom:6px;">收到新的友链申请</div>'
            . '<div style="font-size:13px;color:#8a9199;line-height:1.6;padding-bottom:14px;">'
            . '来自 ' . self::esc($site['title'] === '' ? '本站' : $site['title'])
            . ' 的友链申请，请核对后决定是否通过。</div>'
            . '<table style="width:100%;border-collapse:collapse;">' . $cells . '</table>'
            . $button
            . '<div style="padding-top:18px;border-top:1px solid #eceef1;margin-top:20px;'
            . 'font-size:12px;color:#a3aab2;line-height:1.6;">'
            . '本邮件由本站自动发送，请勿直接回复。</div>'
            . '</div></div>';
    }

    /**
     * 站点信息（标题 / 地址）
     *
     * @return array
     */
    private static function siteInfo()
    {
        $info = array('title' => '', 'url' => '');

        try {
            $options = Typecho_Widget::widget('Widget_Options');
        } catch (Throwable $e) {
            return $info;
        }

        if (!is_object($options)) {
            return $info;
        }

        $info['title'] = ErcerLink_Util::toStr($options->title);
        $info['url'] = rtrim(ErcerLink_Util::toStr($options->siteUrl), '/');

        return $info;
    }

    /**
     * 输出一个链接，非 http(s) 地址退化为纯文本
     *
     * @param string $url
     * @return string
     */
    private static function link($url)
    {
        $url = (string) $url;
        if ($url === '') {
            return '<span style="color:#a3aab2;">—</span>';
        }

        $lower = strtolower($url);
        if (strpos($lower, 'http://') !== 0 && strpos($lower, 'https://') !== 0) {
            return self::esc($url);
        }

        return '<a href="' . self::esc($url) . '" style="color:#2f6fed;text-decoration:none;">'
            . self::esc($url) . '</a>';
    }

    /**
     * HTML 转义
     *
     * @param string $value
     * @return string
     */
    private static function esc($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * 单行头字段清洗：去标签、去 CRLF、折叠空白
     *
     * @param string $value
     * @return string
     */
    private static function header($value)
    {
        $value = strip_tags((string) $value);
        $value = str_replace(array("\r", "\n", "\t"), ' ', $value);

        return trim(preg_replace('/\s+/u', ' ', $value));
    }

    /**
     * 截断到指定字符数
     *
     * @param string $value
     * @param int $max
     * @return string
     */
    private static function text($value, $max)
    {
        if (ErcerLink_Util::strLength($value) <= $max) {
            return $value;
        }

        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $max, 'UTF-8');
        }

        return substr($value, 0, $max);
    }

    /**
     * 写错误日志
     *
     * @param string $message
     */
    private static function log($message)
    {
        if (!function_exists('error_log')) {
            return;
        }

        error_log('ErcerLink ' . str_replace(array("\r", "\n"), ' ', (string) $message));
    }
}
