<?php
/**
 * 友链申请插件Action类
 * 处理友链申请的后端逻辑
 */
class ErcerLink_Action extends Typecho_Widget implements Widget_Interface_Do {
    /**
     * 验证Cloudflare Turnstile验证码
     * @param string $token 验证码响应值
     * @param string $remoteIp 客户端IP地址
     * @return bool 验证结果
     */
    private function verifyTurnstile($token, $remoteIp) {
        // 获取插件配置
        $pluginOptions = Typecho_Widget::widget('Widget_Options')->plugin('ErcerLink');
        $secretKey = $pluginOptions->turnstileSecretKey;
        
        // 未配置Secret Key时跳过验证（开发环境或未配置时）
        if (empty($secretKey)) {
            return true;
        }
        
        // 构建验证请求
        $verifyUrl = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
        $data = http_build_query([
            'secret' => $secretKey,
            'response' => $token,
            'remoteip' => $remoteIp
        ]);
        
        // 发送POST请求
        $options = [
            'http' => [
                'header' => "Content-type: application/x-www-form-urlencoded\r\n",
                'method' => 'POST',
                'content' => $data,
                'timeout' => 5 // 5秒超时
            ],
        ];
        
        $context = stream_context_create($options);
        $result = file_get_contents($verifyUrl, false, $context);
        
        // 验证失败时允许通过（防止API故障影响功能）
        if ($result === false) {
            return false;
        }
        
        // 解析验证结果
        $resultData = json_decode($result, true);
        return !empty($resultData['success']);
    }
    
    /**
     * 处理友链添加请求
     */
    public function link_add() {
        // 只允许POST请求
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            die('非法请求😋');
        }

        try {
            $db = Typecho_Db::get();
            $realIp = filter_input(INPUT_SERVER, 'REMOTE_ADDR', FILTER_VALIDATE_IP);
            
            // 1. 验证Turnstile验证码
            $turnstileResponse = filter_input(INPUT_POST, 'cf-turnstile-response', FILTER_SANITIZE_STRING);
            if (empty($turnstileResponse) || !$this->verifyTurnstile($turnstileResponse, $realIp)) {
                die('验证码验证失败，请重试😘');
            }

            // 2. 获取并过滤表单数据
            $postData = [
                'name' => filter_input(INPUT_POST, 'host_name', FILTER_SANITIZE_STRING),
                'url' => filter_input(INPUT_POST, 'host_url', FILTER_SANITIZE_URL),
                'image' => filter_input(INPUT_POST, 'host_png', FILTER_SANITIZE_URL),
                'description' => filter_input(INPUT_POST, 'host_msg', FILTER_SANITIZE_STRING),
                'user' => $realIp,
                'sort' => 'others'
            ];

            // 3. 检查URL是否已存在
            $isExists = $db->fetchRow(
                $db->select('url')
                   ->from('table.links')
                   ->where('url = ?', $postData['url'])
            );
            
            if ($isExists) {
                die('该链接已经提交过了噢😘');
            }

            // 4. 插入新链接
            $insertId = $db->query(
                $db->insert('table.links')
                   ->rows($postData)
            );
            
            // 5. 触发邮件通知，如果开启了邮件通知，并且存在mail数据库表
            $pluginOptions = Typecho_Widget::widget('Widget_Options')->plugin('ErcerLink');
            if ($pluginOptions->enableEmailNotify == '1') {
                try {
                    $db = Typecho_Db::get();
                    $prefix = $db->getPrefix();
                    $options = Typecho_Widget::widget('Widget_Options');
                    $user = $this->widget('Widget_User');
                    $siteUrl = $options->siteUrl;
                    
                    // 构建邮件内容数组
                    $mailContent = [
                        'siteTitle' => $options->title,
                        'timezone'  => $options->timezone,
                        'cid'       => $insertId,
                        'coid'      => $insertId,
                        'created'   => time(),
                        'author'    => $postData['name'],
                        'authorId'  => 0,
                        'ownerId'   => 1,
                        'mail'      => $user->mail,
                        'ip'        => $realIp,
                        'title'     => '新链接提交',
                        'text'      => "新链接：{$postData['name']} - {$postData['url']}<br>描述：{$postData['description']}",
                        'permalink' => $siteUrl,
                        'status'    => 'approved',
                        'parent'    => 0,
                        'manage'    => $siteUrl . __TYPECHO_ADMIN_DIR__ . "manage-comments.php",
                        'banMail'   => 0 // 允许发送邮件
                    ];
                    
                    // 直接写入邮件队列数据库
                    $mailContent = (object)$mailContent;
                    $db->query(
                        $db->insert($prefix . 'mail')->rows(array(
                            'content' => base64_encode(serialize($mailContent)),
                            'sent' => '0' // 0表示待发送
                        ))
                    );
                } catch (Exception $e) {
                    // 忽略邮件发送失败的错误，不影响友链提交
                    error_log('友链申请邮件通知失败：' . $e->getMessage());
                }
            }
            
            // 6. 返回成功
            die('200');
        } catch (Exception $e) {
            die('提交失败，请稍后重试😞');
        }
    }
    
    /**
     * 空实现，满足Widget_Interface_Do接口要求
     */
    public function action() {}
    
    /**
     * 空实现，满足Widget_Interface接口要求
     */
    public function execute() {}
}