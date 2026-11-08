<?php
/**
 * ErcerLink Action 场景断言
 *
 * 逐个子进程运行 tests/action_case.php，校验响应体。
 *
 * 运行：php tests/action_check.php
 */

$php = PHP_BINARY;
$case = __DIR__ . '/action_case.php';

/**
 * 运行单个场景
 *
 * @param string $scenario
 * @return array ['code' => int, 'body' => string]
 */
function runCase($php, $case, $scenario)
{
    $command = escapeshellarg($php) . ' ' . escapeshellarg($case) . ' ' . escapeshellarg($scenario) . ' 2>&1';

    $output = array();
    $code = 0;
    exec($command, $output, $code);

    return array('code' => $code, 'body' => trim(implode("\n", $output)));
}

$expectations = array(
    'ok'                      => '200',
    'ok_with_trailing_slash_url' => '200',
    'get'                     => '非法请求😋',
    'cross_origin'            => '非法来源😋',
    'no_csrf_cookie'          => '页面已过期，请刷新后重试😘',
    'csrf_mismatch'           => '页面已过期，请刷新后重试😘',
    'rate_limited'            => '提交太频繁啦，请稍后再试😴',
    'js_url'                  => '请填写完整的链接信息😘',
    'missing_agree'           => '请先添加本站为友链噢😘',
    'missing_name'            => '请填写完整的链接信息😘',
    'name_too_long'           => '站点名称过长😘',
    'duplicate'               => '该链接已经提交过了噢😘',
    'xss_name'                => '200',
    'image_equals_url'        => '请填写完整的链接信息😘',
    'notify_on_without_plugin' => '200',
    'notify_on_with_plugin'   => '200',
    'notify_push'             => '200',
    'notify_push_skipped'     => '200',
    'turnstile_secret_without_token' => '验证码验证失败，请重试😘',
    'turnstile_secret_blank_padded'  => '200',
    'turnstile_token_too_long'       => '验证码验证失败，请重试😘',
    'turnstile_ok'            => '200',
    'turnstile_wrong_action'  => '验证码验证失败，请重试😘',
    'turnstile_wrong_host'    => '验证码验证失败，请重试😘',
    'turnstile_rejected'      => '验证码验证失败，请重试😘'
);

$pass = 0;
$failures = array();

foreach ($expectations as $scenario => $expected) {
    $result = runCase($php, $case, $scenario);

    if ($result['body'] === $expected && $result['code'] === 0) {
        $pass++;
        continue;
    }

    $failures[] = sprintf(
        '%s => 实际 "%s" (exit %d)，期望 "%s"',
        $scenario,
        $result['body'],
        $result['code'],
        $expected
    );
}

echo "\n";
foreach ($failures as $failure) {
    echo 'FAIL ' . $failure . "\n";
}

printf("通过 %d 项，失败 %d 项\n", $pass, count($failures));

exit(empty($failures) ? 0 : 1);
