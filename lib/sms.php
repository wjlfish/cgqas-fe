<?php
/**
 * 阿里云「号码认证服务 · 短信认证」(dypnsapi)
 *
 * 模式:
 *   dypns_platform : 用控制台赠送的签名+模板, 平台生成并核验验证码(推荐)
 *   dypns_local    : 用号码认证短信认证, 但验证码由本站生成并本地校验
 *   dev            : 不发短信, 验证码写入本地日志(仅调试)
 */
require_once __DIR__ . '/config.php';

function aliyun_cfg(): array
{
    $c = require __DIR__ . '/config.php';
    return $c['aliyun'] ?? [];
}

function _pe(string $s): string
{
    return rawurlencode($s);
}

/** RPC 调用 (POST + HMAC-SHA1) */
function aliyun_rpc(string $endpoint, string $action, array $params, string $version = '2017-05-25'): array
{
    $cfg = aliyun_cfg();
    $ak  = (string) ($cfg['id'] ?? '');
    $sk  = (string) ($cfg['secret'] ?? '');
    if ($ak === '' || $sk === '') {
        return ['ok' => false, 'code' => 'NO_CONFIG', 'msg' => '短信服务未配置'];
    }

    $base = [
        'Format'           => 'JSON',
        'Version'          => $version,
        'AccessKeyId'      => $ak,
        'SignatureMethod'  => 'HMAC-SHA1',
        'SignatureVersion' => '1.0',
        'SignatureNonce'   => bin2hex(random_bytes(16)),
        'Timestamp'        => gmdate('Y-m-d\TH:i:s\Z'),
        'Action'           => $action,
    ];
    foreach ($params as $k => $v) {
        $base[$k] = (string) $v;
    }
    ksort($base);

    $pairs = [];
    foreach ($base as $k => $v) {
        $pairs[] = _pe($k) . '=' . _pe($v);
    }
    $canonical    = implode('&', $pairs);
    $stringToSign = 'POST&' . _pe('/') . '&' . _pe($canonical);
    $sig          = base64_encode(hash_hmac('sha1', $stringToSign, $sk . '&', true));

    $url = rtrim($endpoint, '/') . '/?Signature=' . _pe($sig);
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $canonical,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_USERAGENT      => 'cgtk/1.0',
    ]);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        return ['ok' => false, 'code' => 'NETWORK', 'msg' => $err];
    }
    $j = json_decode((string) $body, true) ?: [];
    return [
        'ok'   => ($j['Code'] ?? '') === 'OK',
        'code' => (string) ($j['Code'] ?? ''),
        'msg'  => (string) ($j['Message'] ?? ''),
        'raw'  => $j,
    ];
}

function sms_endpoint(): string
{
    $cfg = aliyun_cfg();
    return (string) ($cfg['endpoint'] ?? 'https://dypnsapi.aliyuncs.com/');
}

/**
 * 发送验证码。
 * 返回 ['ok'=>bool, 'code'=>纯文本验证码|null(平台托管时), 'error'=>...]
 */
function sms_send_verify(string $phone): array
{
    $cfg  = aliyun_cfg();
    $mode = (string) ($cfg['mode'] ?? 'dev');

    if ($mode === 'dev') {
        $code = sprintf('%06d', random_int(0, 999999));
        @file_put_contents('/tmp/cgtk_sms_dev.log',
            date('c') . "\t" . $phone . "\t" . $code . "\n", FILE_APPEND);
        return ['ok' => true, 'code' => $code, 'mode' => 'dev'];
    }

    $sign     = (string) ($cfg['sign'] ?? '');
    $template = (string) ($cfg['template'] ?? '');
    if ($sign === '' || $template === '') {
        return ['ok' => false, 'error' => '短信签名或模板未配置'];
    }

    $platform = ($mode === 'dypns_platform');
    $code     = $platform ? '##code##' : sprintf('%06d', random_int(0, 999999));

    $r = aliyun_rpc(sms_endpoint(), 'SendSmsVerifyCode', [
        'PhoneNumber'   => $phone,
        'SignName'      => $sign,
        'TemplateCode'  => $template,
        'TemplateParam' => json_encode(['code' => $code, 'min' => '5'], JSON_UNESCAPED_UNICODE),
        'CodeLength'    => 6,
        'ValidTime'     => 300,
        'Interval'      => 60,
    ]);

    if (!$r['ok']) {
        $map = [
            'biz.FREQUENCY'          => '发送过于频繁，请稍后再试',
            'isv.INVALID_PARAMETERS' => '短信签名或模板无效',
            'isv.MOBILE_NUMBER_ILLEGAL' => '手机号格式不正确',
            'isv.BUSINESS_LIMIT_CONTROL' => '发送次数已达上限',
        ];
        $msg = $map[$r['code']] ?? ('短信发送失败(' . $r['code'] . ')');
        return ['ok' => false, 'error' => $msg, 'code' => $r['code'], 'raw' => $r['raw'] ?? []];
    }

    return ['ok' => true, 'code' => $platform ? null : $code, 'mode' => $mode, 'raw' => $r['raw'] ?? []];
}

/** 平台托管模式下校验验证码 */
function sms_platform_check(string $phone, string $code): array
{
    $r = aliyun_rpc(sms_endpoint(), 'CheckSmsVerifyCode', [
        'PhoneNumber' => $phone,
        'VerifyCode'  => $code,
    ]);
    if ($r['ok']) {
        return [true, ''];
    }
    if ($r['code'] === 'isv.ValidateFail') {
        return [false, '验证码错误或已过期'];
    }
    return [false, '验证失败(' . $r['code'] . ')'];
}
