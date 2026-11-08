<?php
/**
 * ErcerLink Action 类
 *
 * 处理前端友链表单：同源 + CSRF 校验、限流、Turnstile 验证、输入规范化、
 * 写库与邮件通知（邮件细节见 Mailer.php）。
 *
 * @package ErcerLink
 */
class ErcerLink_Action extends Typecho_Widget implements Widget_Interface_Do
{
    /** 成功响应体，前端据此判定提交成功 */
    const SUCCESS_RESPONSE = '200';
    /** 新友链默认分组 */
    const DEFAULT_LINK_SORT = 'others';
    /** Turnstile 前端字段名 */
    const TURNSTILE_FIELD = 'cf-turnstile-response';
    /** Turnstile 期望的 action，需与前端渲染参数一致 */
    const TURNSTILE_ACTION = 'link_add';
    const TURNSTILE_VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
    const MAX_TURNSTILE_TOKEN_LENGTH = 2048;

    /**
     * 路由入口
     */
    public function action()
    {
        $this->link_add();
    }

    /**
     * 处理友链添加请求
     */
    public function link_add()
    {
        if (!$this->isPost()) {
            $this->fail('非法请求😋');
        }

        if (!ErcerLink_Util::isSameOrigin()) {
            $this->fail('非法来源😋');
        }

        if (!ErcerLink_Util::verifyCsrf()) {
            $this->fail('页面已过期，请刷新后重试😘');
        }

        $ip = ErcerLink_Util::clientIp();

        if (ErcerLink_Util::isRateLimited($ip)) {
            $this->fail('提交太频繁啦，请稍后再试😴');
        }

        try {
            $this->validateTurnstile($ip);

            $data = $this->collectInput($ip);
            $this->validateInput($data);

            $db = Typecho_Db::get();

            if ($this->linkExists($db, $data['url'])) {
                $this->fail('该链接已经提交过了噢😘');
            }

            $insertId = (int) $db->query($db->insert('table.links')->rows($data));

            ErcerLink_Mailer::notify($data, $insertId, $ip);

            $this->success();
        } catch (Throwable $e) {
            $this->fail('提交失败，请稍后重试😞');
        }
    }

    /**
     * 空实现，满足 Widget_Interface 要求
     */
    public function execute()
    {
    }

    /**
     * 是否为 POST 请求
     *
     * @return bool
     */
    private function isPost()
    {
        return isset($_SERVER['REQUEST_METHOD'])
            && strtoupper(ErcerLink_Util::toStr($_SERVER['REQUEST_METHOD'])) === 'POST';
    }

    /**
     * 输出响应头（纯文本，禁止缓存与 MIME 嗅探）
     */
    private function sendHeaders()
    {
        if (headers_sent()) {
            return;
        }

        header('Content-Type: text/plain; charset=UTF-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');
    }

    /**
     * 统一失败输出
     *
     * @param string $message
     */
    private function fail($message)
    {
        $this->sendHeaders();
        echo $message;
        exit;
    }

    /**
     * 统一成功输出
     */
    private function success()
    {
        $this->sendHeaders();
        echo self::SUCCESS_RESPONSE;
        exit;
    }

    /**
     * 收集并规范化表单数据
     *
     * @param string $ip
     * @return array
     */
    private function collectInput($ip)
    {
        return array(
            'name'        => ErcerLink_Util::cleanText($this->input('host_name')),
            'url'         => ErcerLink_Util::normalizeUrl($this->input('host_url')),
            'image'       => ErcerLink_Util::normalizeUrl($this->input('host_png')),
            'description' => ErcerLink_Util::cleanText($this->input('host_msg')),
            'user'        => $ip,
            'sort'        => self::DEFAULT_LINK_SORT
        );
    }

    /**
     * 读取 POST 原始值（数组等非标量输入统一按空处理）
     *
     * @param string $key
     * @return string
     */
    private function input($key)
    {
        return isset($_POST[$key]) ? ErcerLink_Util::toStr($_POST[$key]) : '';
    }

    /**
     * 校验表单数据
     *
     * @param array $data
     */
    private function validateInput(array $data)
    {
        if ($data['name'] === '' || $data['url'] === '' || $data['description'] === '' || $data['image'] === '') {
            $this->fail('请填写完整的链接信息😘');
        }

        if ($this->input('host_yes') === '') {
            $this->fail('请先添加本站为友链噢😘');
        }

        if ($data['url'] === $data['image']) {
            $this->fail('请填写完整的链接信息😘');
        }

        if (ErcerLink_Util::strLength($data['name']) > ErcerLink_Util::MAX_NAME_LENGTH) {
            $this->fail('站点名称过长😘');
        }

        if (ErcerLink_Util::strLength($data['description']) > ErcerLink_Util::MAX_DESCRIPTION_LENGTH) {
            $this->fail('站点描述过长😘');
        }
    }

    /**
     * 链接是否已存在
     *
     * 除规范化后的精确匹配外，再比对一次「带根路径斜杠」的历史数据，
     * 避免旧版本入库的 https://a.com/ 与新的 https://a.com 被判为不同链接。
     * 逐条查询而非拼 OR —— Typecho 的 where() 每次只替换一个占位符。
     *
     * @param mixed $db
     * @param string $url
     * @return bool
     */
    private function linkExists($db, $url)
    {
        foreach (array($url, $url . '/') as $candidate) {
            $row = $db->fetchRow(
                $db->select('lid')->from('table.links')->where('url = ?', $candidate)->limit(1)
            );

            if (!empty($row)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Turnstile Secret（留空表示不启用验证码）
     *
     * @return string
     */
    private function turnstileSecret()
    {
        return ErcerLink_Util::opt('turnstileSecretKey');
    }

    /**
     * 校验 Turnstile 响应
     *
     * @param string $ip
     */
    private function validateTurnstile($ip)
    {
        $secret = $this->turnstileSecret();

        if ($secret === '') {
            return;
        }

        $token = trim($this->input(self::TURNSTILE_FIELD));

        if ($token === '' || strlen($token) > self::MAX_TURNSTILE_TOKEN_LENGTH) {
            $this->fail('验证码验证失败，请重试😘');
        }

        if (!$this->verifyTurnstile($token, $secret, $ip)) {
            $this->fail('验证码验证失败，请重试😘');
        }
    }

    /**
     * 调用 Cloudflare siteverify
     *
     * @param string $token
     * @param string $secret
     * @param string $ip
     * @return bool
     */
    private function verifyTurnstile($token, $secret, $ip)
    {
        $body = $this->postForm(self::TURNSTILE_VERIFY_URL, array(
            'secret'   => $secret,
            'response' => $token,
            'remoteip' => $ip
        ));

        $result = json_decode($body, true);

        if (!is_array($result) || empty($result['success'])) {
            return false;
        }

        // action 必须与前端渲染时声明的一致，避免令牌被跨用途复用
        if (isset($result['action']) && $result['action'] !== '' && $result['action'] !== self::TURNSTILE_ACTION) {
            return false;
        }

        // hostname 必须属于本站，避免从其它站点骗取的合法令牌被复用
        if (isset($result['hostname']) && $result['hostname'] !== '') {
            $expected = ErcerLink_Util::siteHost();

            if ($expected !== '' && strtolower((string) $result['hostname']) !== $expected) {
                return false;
            }
        }

        return true;
    }

    /**
     * 发送 application/x-www-form-urlencoded POST 请求
     *
     * @param string $url
     * @param array $fields
     * protected 而非 private：便于测试子类替换网络层，验证 siteverify 响应处理
     *
     * @return string 请求失败返回空字符串
     */
    protected function postForm($url, array $fields)
    {
        $payload = http_build_query($fields, '', '&');

        if (function_exists('curl_init')) {
            $ch = curl_init();
            if ($ch === false) {
                return '';
            }

            curl_setopt_array($ch, array(
                CURLOPT_URL            => $url,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_HTTPHEADER     => array('Content-Type: application/x-www-form-urlencoded'),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_TIMEOUT        => 5,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2
            ));

            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            return ($body !== false && $code === 200) ? (string) $body : '';
        }

        if (!ini_get('allow_url_fopen')) {
            return '';
        }

        $context = stream_context_create(array(
            'http' => array(
                'method'        => 'POST',
                'header'        => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content'       => $payload,
                'timeout'       => 5,
                'ignore_errors' => false
            ),
            'ssl'  => array('verify_peer' => true, 'verify_peer_name' => true)
        ));

        $body = @file_get_contents($url, false, $context);

        return is_string($body) ? $body : '';
    }
}
