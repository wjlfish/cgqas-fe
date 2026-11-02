<?php
declare(strict_types=1);
/**
 * 腾讯云验证码(TCaptcha) 服务端票据校验
 * 校验接口: GET https://ssl.captcha.qq.com/ticket/verify
 *   aid / AppSecretKey / Ticket / Randstr / UserIP / CaptchaType=9
 * 返回 JSON, response = "1" 表示通过
 */
require_once __DIR__ . '/config.php';

function tcaptcha_cfg(): array
{
    $c = require __DIR__ . '/config.php';
    return $c['tcaptcha'] ?? [];
}

function tcaptcha_enabled(): bool
{
    $t = tcaptcha_cfg();
    return !empty($t['enable']) && !empty($t['app_id']) && !empty($t['app_key']);
}

function tcaptcha_log(string $tag, array $ctx): void
{
    $c   = require __DIR__ . '/config.php';
    $dir = (string) ($c['log']['dir'] ?? '/var/log/cgtk');
    @file_put_contents(
        rtrim($dir, '/') . '/tcaptcha.log',
        date('Y-m-d H:i:s') . " {$tag} " . json_encode($ctx, JSON_UNESCAPED_UNICODE) . "\n",
        FILE_APPEND | LOCK_EX
    );
}


/** TC3-HMAC-SHA256 签名 */
function tc3_sign(string $secretId, string $secretKey, string $service, string $host,
                  string $action, string $version, string $payload, string $region = ''): array
{
    $ts   = time();
    $date = gmdate('Y-m-d', $ts);

    $canonicalHeaders = "content-type:application/json; charset=utf-8\nhost:{$host}\n";
    $signedHeaders    = 'content-type;host';
    $canonicalRequest = "POST\n/\n\n{$canonicalHeaders}\n{$signedHeaders}\n" . hash('sha256', $payload);

    $credentialScope = "{$date}/{$service}/tc3_request";
    $stringToSign    = "TC3-HMAC-SHA256\n{$ts}\n{$credentialScope}\n" . hash('sha256', $canonicalRequest);

    $kDate    = hash_hmac('sha256', $date, 'TC3' . $secretKey, true);
    $kService = hash_hmac('sha256', $service, $kDate, true);
    $kSigning = hash_hmac('sha256', 'tc3_request', $kService, true);
    $signature = hash_hmac('sha256', $stringToSign, $kSigning);

    $auth = "TC3-HMAC-SHA256 Credential={$secretId}/{$credentialScope}, "
          . "SignedHeaders={$signedHeaders}, Signature={$signature}";

    $headers = [
        'Authorization: ' . $auth,
        'Content-Type: application/json; charset=utf-8',
        'Host: ' . $host,
        'X-TC-Action: ' . $action,
        'X-TC-Version: ' . $version,
        'X-TC-Timestamp: ' . $ts,
    ];
    if ($region !== '') {
        $headers[] = 'X-TC-Region: ' . $region;
    }
    return [$headers, $payload];
}

/** 调用 DescribeCaptchaResult 校验票据（v2 验证码唯一正确方式） */
function tcaptcha_verify_by_api(string $ticket, string $randstr, string $userIp, array $t): array
{
    $host   = 'captcha.tencentcloudapi.com';
    $secretId  = (string) ($t['tc_secret_id'] ?? '');
    $secretKey = (string) ($t['tc_secret_key'] ?? '');
    if ($secretId === '' || $secretKey === '') {
        return [false, '未配置云API密钥'];
    }

    $payload = json_encode([
        'CaptchaAppId'  => (int) $t['app_id'],
        'AppSecretKey'  => (string) $t['app_key'],
        'Ticket'        => $ticket,
        'Randstr'       => $randstr,
        'UserIp'        => $userIp,
        'CaptchaType'   => 9,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    [$headers] = tc3_sign($secretId, $secretKey, 'captcha', $host,
                          'DescribeCaptchaResult', '2019-07-22', $payload, 'ap-guangzhou');

    $ch = curl_init("https://{$host}/");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        tcaptcha_log('api_curl_error', ['err' => $err]);
        return [false, '验证服务不可达'];
    }
    $j = json_decode((string) $resp, true);
    $r = $j['Response'] ?? null;
    if (!is_array($r)) {
        tcaptcha_log('api_bad_json', ['raw' => substr((string) $resp, 0, 200)]);
        return [false, '验证服务返回异常'];
    }
    // 官方定义: CaptchaCode = 1 表示验证通过(0 才是未通过)
    $code = (int) ($r['CaptchaCode'] ?? -1);
    if ($code === 1) {
        return [true, ''];
    }
    $codeMsg = [
        0 => '验证未通过', 7 => 'Randstr 不匹配', 8 => '票据过期',
        9 => '票据重复使用', 15 => '票据不合法(解密失败)',
        16 => 'AppId 与票据不匹配', 21 => '风控判定异常',
        100 => 'AppId/SecretKey/票据不匹配',
    ][$code] ?? (string) ($r['CaptchaMsg'] ?? '未知错误');
    tcaptcha_log('api_rejected', [
        'code' => $code, 'msg' => $codeMsg, 'ticket' => substr($ticket, 0, 10),
    ]);
    // 容灾票据(trerror_ 前缀): 默认不放行(否则可被伪造绕过), 需显式开启
    if (!empty($t['disaster_allow']) && strncmp($ticket, 'trerror_', 8) === 0) {
        tcaptcha_log('disaster_pass', ['ticket' => substr($ticket, 0, 24)]);
        return [true, ''];
    }
    return [false, '人机验证未通过'];
}

/**
 * @return array{0:bool,1:string} [是否通过, 失败原因]
 */
function tcaptcha_verify(string $ticket, string $randstr, string $userIp = ''): array
{
    if (!tcaptcha_enabled()) {
        return [true, 'disabled'];
    }
    if ($ticket === '' || $randstr === '') {
        return [false, '缺少验证参数'];
    }

    $t = tcaptcha_cfg();
    // v2 验证码票据必须走 DescribeCaptchaResult（TC3 签名）
    if (!empty($t['tc_secret_id']) && !empty($t['tc_secret_key'])) {
        return tcaptcha_verify_by_api($ticket, $randstr, $userIp, $t);
    }

    $endpoint = (string) ($t['endpoint'] ?? 'https://ssl.captcha.qq.com/ticket/verify');

    $params = http_build_query([
        'aid'          => (string) $t['app_id'],
        'AppSecretKey' => (string) $t['app_key'],
        'Ticket'       => $ticket,
        'Randstr'      => $randstr,
        'UserIP'       => $userIp,
        'CaptchaType'  => '9',
    ]);

    $ch = curl_init($endpoint . '?' . $params);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_USERAGENT      => 'cgtk/1.0',
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        tcaptcha_log('curl_error', ['err' => $err]);
        return [false, '验证服务不可达'];
    }

    $j = json_decode((string) $resp, true);
    if (!is_array($j)) {
        tcaptcha_log('bad_json', ['raw' => substr((string) $resp, 0, 200)]);
        return [false, '验证服务返回异常'];
    }

    if ((string) ($j['response'] ?? '') === '1') {
        return [true, ''];
    }

    tcaptcha_log('rejected', [
        'response' => (string) ($j['response'] ?? ''),
        'msg'      => (string) ($j['err_msg'] ?? ''),
        'evil'     => (string) ($j['evil_level'] ?? ''),
        'ticket'   => substr($ticket, 0, 10),
    ]);
    return [false, '人机验证未通过'];
}
