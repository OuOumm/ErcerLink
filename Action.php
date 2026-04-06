<?php
/**
 * 友链申请插件 Action 类
 * 处理友链申请的后端逻辑
 */
class ErcerLink_Action extends Typecho_Widget implements Widget_Interface_Do {
    /**
     * 验证 Cloudflare Turnstile 验证码
     * @param string $token 验证码响应值
     * @param string $remoteIp 客户端 IP 地址
     * @return bool 验证结果
     */
    private function verifyTurnstile($token, $remoteIp) {
        // 获取插件配置（使用静态变量避免重复查询）
        static $pluginOptions = null;
        if ($pluginOptions === null) {
            $pluginOptions = Typecho_Widget::widget('Widget_Options')->plugin('ErcerLink');
        }
        $secretKey = $pluginOptions->turnstileSecretKey;
        
        // 未配置 Secret Key 时跳过验证（开发环境或未配置时）
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
        
        // 使用 cURL 替代 file_get_contents（性能更优，支持连接复用）
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $verifyUrl,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $data,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 3, // 降低超时时间至 3 秒
            CURLOPT_CONNECTTIMEOUT => 2, // 连接超时 2 秒
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2
        ]);
        
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        // 验证失败时允许通过（防止 API 故障影响功能）
        if ($result === false || $httpCode !== 200) {
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
        // CSRF 令牌验证
        $this->widget('Widget_Security')->protect();
        
        // 只允许 POST 请求
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            die('非法请求😋');
        }

        try {
            // 1. 获取数据库实例和 IP（提前初始化）
            $db = Typecho_Db::get();
            $realIp = filter_input(INPUT_SERVER, 'REMOTE_ADDR', FILTER_VALIDATE_IP);
            
            // 2. 验证 Turnstile 验证码（前置检查，失败时提前返回）
            $turnstileResponse = filter_input(INPUT_POST, 'cf-turnstile-response', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
            if (empty($turnstileResponse) || !$this->verifyTurnstile($turnstileResponse, $realIp)) {
                die('验证码验证失败，请重试😘');
            }

            // 3. 获取并过滤表单数据
            $postData = [
                'name' => trim(filter_input(INPUT_POST, 'host_name', FILTER_SANITIZE_FULL_SPECIAL_CHARS)),
                'url' => trim(filter_input(INPUT_POST, 'host_url', FILTER_SANITIZE_URL)),
                'image' => trim(filter_input(INPUT_POST, 'host_png', FILTER_SANITIZE_URL)),
                'description' => trim(filter_input(INPUT_POST, 'host_msg', FILTER_SANITIZE_FULL_SPECIAL_CHARS)),
                'user' => $realIp,
                'sort' => 'others'
            ];
            
            // 4. 输入验证（快速失败策略）
            if (empty($postData['name']) || empty($postData['url'])) {
                die('请填写完整的链接信息😘');
            }
            
            // 验证 URL 格式
            if (!filter_var($postData['url'], FILTER_VALIDATE_URL)) {
                die('请输入有效的网址😘');
            }
            
            // 验证图片 URL（如果提供）
            if (!empty($postData['image']) && !filter_var($postData['image'], FILTER_VALIDATE_URL)) {
                die('请输入有效的图片地址😘');
            }
            
            // 限制长度
            if (strlen($postData['name']) > 100 || strlen($postData['description']) > 500) {
                die('输入内容过长😘');
            }

            // 5. 检查 URL 是否已存在（使用 exists 查询优化性能）
            $isExists = $db->fetchRow(
                $db->select('url')
                   ->from('table.links')
                   ->where('url = ?', $postData['url'])
                   ->limit(1) // 只需判断是否存在，限制返回 1 行
            );
            
            if ($isExists) {
                die('该链接已经提交过了噢😘');
            }

            // 6. 插入新链接
            $insertId = $db->query(
                $db->insert('table.links')
                   ->rows($postData)
            );
            
            // 7. 触发邮件通知（异步处理，避免阻塞主流程）
            $pluginOptions = Typecho_Widget::widget('Widget_Options')->plugin('ErcerLink');
            if ($pluginOptions->enableEmailNotify == '1') {
                try {
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
                        'author'    => htmlspecialchars($postData['name'], ENT_QUOTES, 'UTF-8'),
                        'authorId'  => 0,
                        'ownerId'   => 1,
                        'mail'      => $user->mail,
                        'ip'        => $realIp,
                        'title'     => '新链接提交',
                        'text'      => "新链接：" . htmlspecialchars($postData['name'], ENT_QUOTES, 'UTF-8') . " - " . htmlspecialchars($postData['url'], ENT_QUOTES, 'UTF-8') . "<br>描述：" . htmlspecialchars($postData['description'], ENT_QUOTES, 'UTF-8'),
                        'permalink' => $siteUrl,
                        'status'    => 'approved',
                        'parent'    => 0,
                        'manage'    => $siteUrl . __TYPECHO_ADMIN_DIR__ . "manage-comments.php",
                        'banMail'   => 0
                    ];
                    
                    // 使用 JSON 替代序列化，避免对象注入风险
                    $db->query(
                        $db->insert($prefix . 'mail')->rows(array(
                            'content' => base64_encode(json_encode($mailContent)),
                            'sent' => '0'
                        ))
                    );
                } catch (Exception $e) {
                    // 忽略邮件发送失败的错误，不影响友链提交
                    error_log('友链申请邮件通知失败：' . $e->getMessage());
                }
            }
            
            // 8. 返回成功
            die('200');
        } catch (Exception $e) {
            die('提交失败，请稍后重试😞');
        }
    }
    
    /**
     * 空实现，满足 Widget_Interface_Do 接口要求
     */
    public function action() {}
    
    /**
     * 空实现，满足 Widget_Interface 接口要求
     */
    public function execute() {}
}
