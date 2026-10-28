<?php
declare(strict_types=1);
/**
 * 告警投递: 企业微信 / 钉钉 / Server酱 / Telegram / 通用 webhook
 * 无论是否配置 webhook, 都会追加写 /www/wwwlogs/cgtk-alert.log
 */

function alert_kind(string $url, string $forced = 'auto'): string
{
    if ($forced !== 'auto' && $forced !== '') {
        return $forced;
    }
    if (strpos($url, 'qyapi.weixin.qq.com') !== false) {
        return 'wecom';
    }
    if (strpos($url, 'oapi.dingtalk.com') !== false) {
        return 'dingtalk';
    }
    if (strpos($url, 'api.telegram.org') !== false) {
        return 'telegram';
    }
    if (strpos($url, 'sctapi.ftqq.com') !== false || strpos($url, 'sc.ftqq.com') !== false) {
        return 'serverchan';
    }
    return 'generic';
}

/**
 * @return array{ok:bool, msg:string}
 */
function alert_send(array $alert, string $title, string $body, string $level = 'crit'): array
{
    $log = '/www/wwwlogs/cgtk-alert.log';
    $line = sprintf(
        "%s\t[%s]\t%s\t%s\n",
        date('c'),
        strtoupper($level),
        $title,
        str_replace("\n", ' / ', $body)
    );
    @file_put_contents($log, $line, FILE_APPEND | LOCK_EX);

    $url = trim((string) ($alert['webhook'] ?? ''));
    if ($url === '') {
        return ['ok' => false, 'msg' => '未配置 webhook(已写日志)'];
    }

    $kind = alert_kind($url, (string) ($alert['kind'] ?? 'auto'));
    $text = $title . "\n" . $body;

    $ch = curl_init();
    $opts = [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_POST           => true,
        CURLOPT_SSL_VERIFYPEER => true,
    ];

    switch ($kind) {
        case 'wecom':
        case 'dingtalk':
            $opts[CURLOPT_HTTPHEADER] = ['Content-Type: application/json; charset=utf-8'];
            $opts[CURLOPT_POSTFIELDS] = json_encode([
                'msgtype' => 'text',
                'text'    => ['content' => '【创高题库】' . $text],
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'telegram':
            $opts[CURLOPT_HTTPHEADER] = ['Content-Type: application/x-www-form-urlencoded'];
            $opts[CURLOPT_POSTFIELDS] = http_build_query(['text' => '【创高题库】' . $text]);
            break;

        case 'serverchan':
            $opts[CURLOPT_HTTPHEADER] = ['Content-Type: application/x-www-form-urlencoded'];
            $opts[CURLOPT_POSTFIELDS] = http_build_query([
                'title' => '【创高题库】' . $title,
                'desp'  => $body,
            ]);
            break;

        default:
            $opts[CURLOPT_HTTPHEADER] = ['Content-Type: application/json; charset=utf-8'];
            $opts[CURLOPT_POSTFIELDS] = json_encode([
                'title' => '【创高题库】' . $title,
                'text'  => $text,
                'level' => $level,
                'ts'    => time(),
            ], JSON_UNESCAPED_UNICODE);
    }

    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        return ['ok' => false, 'msg' => 'curl: ' . $err];
    }
    if ($code < 200 || $code >= 300) {
        return ['ok' => false, 'msg' => 'HTTP ' . $code . ' ' . mb_substr((string) $resp, 0, 200)];
    }
    return ['ok' => true, 'msg' => 'HTTP ' . $code];
}
