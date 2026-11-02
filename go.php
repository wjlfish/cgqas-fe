<?php
declare(strict_types=1);
/**
 * 广告点击跳转（受控，仅允许白名单目标）
 * 目的：统计点击量，配合对方后台的注册数计算转化
 */
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/ratelimit.php';

const AD_TARGETS = [
    'qiyun'     => 'https://oc.qiyun.site/register?aff=XTCF2MLXZHZB',
    'skyrouter' => 'https://skyrouter.cc/register?aff=GMH6QMREA92Q',
];

$to = preg_replace('/[^a-z0-9_]/', '', (string) ($_GET['to'] ?? ''));
if (!isset(AD_TARGETS[$to])) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'not found';
    exit;
}

// 同一 IP 10 分钟内只记一次，避免被刷计数
try {
    $ipBin = ip_bin(client_ip());
    // 去重按「IP + 广告位」: 同一人点不同广告都要计数
    $st = db()->prepare(
        "SELECT 1 FROM request_log
         WHERE ip = ? AND action = 'ad_click' AND query = ?
           AND created_at >= NOW() - INTERVAL 10 MINUTE
         LIMIT 1"
    );
    $st->execute([$ipBin, $to]);
    if (!$st->fetchColumn()) {
        log_request('ad_click', ['q' => $to], 302, 0);
    }
} catch (Throwable $e) {
    // 统计失败不影响跳转
}

header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('Location: ' . AD_TARGETS[$to], true, 302);
exit;
