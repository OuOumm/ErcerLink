<?php
/**
 * CommentToMail 6.0 直发通道桩
 *
 * 只在 action_case.php 的 notify_push / notify_push_fallback 场景中加载，
 * 用来验证 ErcerLink_Mailer 是否优先走 CommentToMail_Plugin::pushMail()。
 *
 * 真实的 pushMail() 会读取自身 SMTP 配置并同步或异步发信，这里只记录调用
 * 参数、返回预设结果，不触网。
 *
 * @package ErcerLink
 */

/**
 * 仅允许 CLI 运行：避免测试桩被 Web 直接访问
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

class CommentToMail_Plugin
{
    /** 预设返回值：true 表示已交给发送流程，false 表示跳过 */
    public static $result = true;
    /** 收到的任务数组 */
    public static $pushed = array();

    /**
     * 与真实插件签名一致
     *
     * @param array $mail
     * @return bool
     */
    public static function pushMail($mail)
    {
        self::$pushed[] = $mail;

        return self::$result === true;
    }
}
