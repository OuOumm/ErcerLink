<?php
/**
 * ErcerLink 公共工具
 *
 * 统一存放配置读取、输入清洗、URL 规范化、CSRF、限流、客户端 IP 等逻辑，
 * 供 ErcerLink_Plugin 与 ErcerLink_Action 共用，避免两处实现漂移。
 *
 * @package ErcerLink
 */
class ErcerLink_Util
{
    /** CSRF Cookie 名（由前端写入，服务端不依赖 Set-Cookie 时序） */
    const CSRF_COOKIE = 'ercerlink_csrf';
    /** CSRF 表单字段名（无请求头时的回落通道） */
    const CSRF_FIELD = 'ercerlink_csrf';
    /** CSRF 请求头名（浏览器端发送的名字；服务端 $_SERVER 键由它推导） */
    const CSRF_HEADER_NAME = 'X-ErcerLink-CSRF';

    /** 字段长度限制（与 typecho_links 表结构对齐，避免严格模式写入失败） */
    const MAX_NAME_LENGTH = 200;
    const MAX_URL_LENGTH = 200;
    const MAX_DESCRIPTION_LENGTH = 200;

    /** 限流：窗口期内单 IP 最大提交次数 */
    const RATE_LIMIT_WINDOW = 300;
    const RATE_LIMIT_MAX = 5;
    /** 限流文件最长保留时间（秒） */
    const RATE_LIMIT_FILE_TTL = 86400;

    /** 插件配置对象缓存 */
    private static $options = null;

    /**
     * 插件配置对象（进程内缓存，永不返回 null）
     *
     * @return object
     */
    public static function options()
    {
        if (self::$options !== null) {
            return self::$options;
        }

        self::$options = new stdClass();

        try {
            $plugin = Typecho_Widget::widget('Widget_Options')->plugin('ErcerLink');

            if (is_object($plugin)) {
                self::$options = $plugin;
            }
        } catch (Throwable $e) {
            self::$options = new stdClass();
        }

        return self::$options;
    }

    /**
     * 丢弃配置缓存
     *
     * 配置对象在进程内只读一次，避免每次取配置都走一遍 Widget 解析。后台
     * 保存设置、或测试中替换配置对象后需要重新读取时调用本方法。
     */
    public static function flushOptions()
    {
        self::$options = null;
    }

    /**
     * 读取单项配置（缺失时返回默认值，避免未定义属性告警）
     *
     * @param string $key
     * @param string $default
     * @return string
     */
    public static function opt($key, $default = '')
    {
        $options = self::options();

        if (isset($options->$key) && is_scalar($options->$key)) {
            return trim((string) $options->$key);
        }

        return $default;
    }

    /**
     * 转换为字符串（拒绝数组 / 对象 / 布尔等非标量输入）
     *
     * @param mixed $value
     * @return string
     */
    public static function toStr($value)
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return '';
    }

    /**
     * 计算字符长度（优先使用 mbstring）
     *
     * @param string $value
     * @return int
     */
    public static function strLength($value)
    {
        return function_exists('mb_strlen') ? (int) mb_strlen($value, 'UTF-8') : strlen($value);
    }

    /**
     * 当前请求主机名（不含端口，小写）
     *
     * @return string
     */
    public static function requestHost()
    {
        $host = isset($_SERVER['HTTP_HOST']) ? self::toStr($_SERVER['HTTP_HOST']) : '';

        return strtolower((string) preg_replace('/:\d+$/', '', trim($host)));
    }

    /**
     * 站点主机名（优先取配置的 siteUrl，回落请求 Host）
     *
     * @return string
     */
    public static function siteHost()
    {
        try {
            $siteUrl = self::toStr(Typecho_Widget::widget('Widget_Options')->siteUrl);
        } catch (Throwable $e) {
            $siteUrl = '';
        }

        if ($siteUrl !== '') {
            $host = parse_url($siteUrl, PHP_URL_HOST);

            if (is_string($host) && $host !== '') {
                return strtolower($host);
            }
        }

        return self::requestHost();
    }

    /**
     * 客户端 IP
     *
     * 默认只信任 REMOTE_ADDR —— X-Forwarded-For 等请求头可被任意伪造，
     * 直接采信会让限流形同虚设。站点前置了 CDN / 反代时在插件设置里显式指定。
     *
     * @return string
     */
    public static function clientIp()
    {
        $header = strtoupper(self::opt('proxyHeader', 'NONE'));

        if ($header !== '' && $header !== 'NONE') {
            $key = 'HTTP_' . str_replace('-', '_', $header);

            if (!empty($_SERVER[$key])) {
                $raw = self::toStr($_SERVER[$key]);

                if ($header === 'X-FORWARDED-FOR') {
                    $parts = explode(',', $raw);
                    $raw = $parts[0];
                }

                $ip = filter_var(trim($raw), FILTER_VALIDATE_IP);

                if ($ip !== false) {
                    return $ip;
                }
            }
        }

        if (empty($_SERVER['REMOTE_ADDR'])) {
            return '';
        }

        $ip = filter_var(self::toStr($_SERVER['REMOTE_ADDR']), FILTER_VALIDATE_IP);

        return $ip === false ? '' : $ip;
    }

    /**
     * 清洗纯文本字段
     *
     * 去掉标签与 HTML 元字符、折叠控制字符。清洗后的内容即使被主题原样
     * 输出也不构成 XSS，同时避免入库后被二次转义破坏观感。
     *
     * @param mixed $value
     * @return string
     */
    public static function cleanText($value)
    {
        $value = self::toStr($value);

        if ($value === '') {
            return '';
        }

        if (function_exists('mb_check_encoding') && !mb_check_encoding($value, 'UTF-8')) {
            return '';
        }

        $value = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value);

        if ($value === null) {
            return '';
        }

        $value = str_replace(array('<', '>', '"', "'", '`', '\\'), '', strip_tags($value));
        $value = preg_replace('/\s+/', ' ', $value);

        return trim((string) $value);
    }

    /**
     * 规范化 URL
     *
     * 仅接受 http / https，禁止空白、控制字符、引号、尖括号与反斜杠，
     * 防止 javascript:、data: 等协议入库后被主题输出成 XSS。
     * 同时统一 scheme / host 大小写并去掉根路径尾斜杠，便于查重。
     *
     * @param mixed $value
     * @return string 合法时返回规范化结果，否则返回空字符串
     */
    public static function normalizeUrl($value)
    {
        $value = trim(self::toStr($value));

        if ($value === '' || strlen($value) > self::MAX_URL_LENGTH) {
            return '';
        }

        if (preg_match('/[\x00-\x20\x7F\x22\x27\x3C\x3E\x5C\x60]/', $value)) {
            return '';
        }

        if (!filter_var($value, FILTER_VALIDATE_URL)) {
            return '';
        }

        $parts = parse_url($value);

        if (empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }

        // 拒绝 user:pass@host 形式，避免 https://trusted@evil.com 这类视觉欺骗链接入库
        if (isset($parts['user']) || isset($parts['pass'])) {
            return '';
        }

        $scheme = strtolower($parts['scheme']);

        if ($scheme !== 'http' && $scheme !== 'https') {
            return '';
        }

        $host = strtolower($parts['host']);

        if (isset($parts['port'])) {
            $host .= ':' . (int) $parts['port'];
        }

        $path = isset($parts['path']) ? $parts['path'] : '';

        if ($path === '/') {
            $path = '';
        }

        $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';

        return $scheme . '://' . $host . $path . $query;
    }

    /**
     * 校验外部脚本地址（留空表示使用本地脚本，视为合法）
     *
     * @param mixed $value
     * @return bool
     */
    public static function isValidScriptUrl($value)
    {
        $value = trim(self::toStr($value));

        if ($value === '') {
            return true;
        }

        $url = self::normalizeUrl($value);

        if ($url === '') {
            return false;
        }

        $path = (string) parse_url($url, PHP_URL_PATH);

        return strtolower(substr($path, -3)) === '.js';
    }

    /**
     * 生成 CSRF Token
     *
     * Token 随页面渲染注入前端，前端写入 Cookie 并在请求头回传，服务端比对
     * 两者是否一致（双提交模式）。服务端不需要在响应头里 Set-Cookie，
     * 因此不受「footer 阶段响应头已发送」影响，也能安全配合整页缓存。
     *
     * @return string 32 位小写十六进制
     */
    public static function csrfToken()
    {
        $token = isset($_COOKIE[self::CSRF_COOKIE]) ? self::toStr($_COOKIE[self::CSRF_COOKIE]) : '';

        if (self::isValidToken($token)) {
            return $token;
        }

        return bin2hex(self::randomBytes(16));
    }

    /**
     * 校验 CSRF Token
     *
     * @return bool
     */
    public static function verifyCsrf()
    {
        $cookie = isset($_COOKIE[self::CSRF_COOKIE]) ? self::toStr($_COOKIE[self::CSRF_COOKIE]) : '';

        if (!self::isValidToken($cookie)) {
            return false;
        }

        $headerKey = 'HTTP_' . strtoupper(str_replace('-', '_', self::CSRF_HEADER_NAME));

        if (isset($_SERVER[$headerKey])) {
            $token = self::toStr($_SERVER[$headerKey]);
        } elseif (isset($_POST[self::CSRF_FIELD])) {
            $token = self::toStr($_POST[self::CSRF_FIELD]);
        } else {
            return false;
        }

        return self::isValidToken($token) && hash_equals($cookie, $token);
    }

    /**
     * Token 格式校验（32 位小写十六进制）
     *
     * @param string $token
     * @return bool
     */
    private static function isValidToken($token)
    {
        return is_string($token) && preg_match('/^[a-f0-9]{32}$/', $token) === 1;
    }

    /**
     * 同源校验（Origin / Referer 若存在，必须与请求 Host 一致）
     *
     * @return bool
     */
    public static function isSameOrigin()
    {
        $expected = self::requestHost();

        foreach (array('HTTP_ORIGIN', 'HTTP_REFERER') as $key) {
            if (empty($_SERVER[$key])) {
                continue;
            }

            $source = parse_url(self::toStr($_SERVER[$key]), PHP_URL_HOST);

            if (!is_string($source) || $source === '') {
                return false;
            }

            if ($expected !== '' && strtolower($source) !== $expected) {
                return false;
            }
        }

        return true;
    }

    /**
     * 记录一次提交并判断是否触发限流（滑动窗口）
     *
     * 基于临时目录的计数文件；目录不可写时放行，避免因环境问题让插件整体不可用。
     *
     * @param string $ip
     * @return bool 是否已被限流
     */
    public static function isRateLimited($ip)
    {
        $dir = self::rateLimitDir();

        if ($dir === '') {
            return false;
        }

        $file = $dir . DIRECTORY_SEPARATOR . ($ip === '' ? 'unknown' : md5($ip)) . '.log';
        $handle = @fopen($file, 'c+');

        if (!$handle) {
            return false;
        }

        $limited = false;

        if (flock($handle, LOCK_EX)) {
            $now = time();
            $hits = array();

            foreach (preg_split('/\s+/', (string) stream_get_contents($handle), -1, PREG_SPLIT_NO_EMPTY) as $stamp) {
                $stamp = (int) $stamp;

                if ($stamp > $now - self::RATE_LIMIT_WINDOW && $stamp <= $now + 5) {
                    $hits[] = $stamp;
                }
            }

            $limited = count($hits) >= self::RATE_LIMIT_MAX;

            if (!$limited) {
                $hits[] = $now;
                rewind($handle);
                ftruncate($handle, 0);
                fwrite($handle, implode("\n", $hits) . "\n");
                fflush($handle);
            }

            flock($handle, LOCK_UN);
        }

        fclose($handle);

        return $limited;
    }

    /**
     * 限流目录（按站点隔离）
     *
     * @return string 不可用时返回空字符串
     */
    private static function rateLimitDir()
    {
        $base = rtrim(sys_get_temp_dir(), '/\\');

        if ($base === '') {
            return '';
        }

        $dir = $base . DIRECTORY_SEPARATOR . 'ercerlink-' . substr(md5(self::siteHost()), 0, 12);

        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return '';
        }

        if (!is_writable($dir)) {
            return '';
        }

        if (mt_rand(1, 200) === 1) {
            self::pruneDir($dir);
        }

        return $dir;
    }

    /**
     * 清理过期限流文件
     *
     * @param string $dir
     */
    private static function pruneDir($dir)
    {
        $files = @glob($dir . DIRECTORY_SEPARATOR . '*.log');

        if (!is_array($files)) {
            return;
        }

        $expire = time() - self::RATE_LIMIT_FILE_TTL;

        foreach ($files as $file) {
            $mtime = @filemtime($file);

            if ($mtime !== false && $mtime < $expire) {
                @unlink($file);
            }
        }
    }

    /**
     * 生成随机字节（兼容无 random_bytes 的旧环境）
     *
     * @param int $length
     * @return string
     */
    private static function randomBytes($length)
    {
        if (function_exists('random_bytes')) {
            try {
                return random_bytes($length);
            } catch (Throwable $e) {
                // 回落到下面的兼容路径
            }
        }

        if (function_exists('openssl_random_pseudo_bytes')) {
            $bytes = openssl_random_pseudo_bytes($length, $strong);

            if ($bytes !== false && $strong) {
                return $bytes;
            }
        }

        $bytes = '';

        if ($handle = @fopen('/dev/urandom', 'rb')) {
            $bytes = (string) fread($handle, $length);
            fclose($handle);
        }

        while (strlen($bytes) < $length) {
            $bytes .= hash('sha256', uniqid((string) mt_rand(), true), true);
        }

        return substr($bytes, 0, $length);
    }
}
