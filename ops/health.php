<?php
declare(strict_types=1);
/**
 * 站点健康检查 —— 由 cron 每 5 分钟执行
 *
 * 检查项:
 *   1) 数据库连通
 *   2) sms_send 接口连续失败
 *   3) 全站 5xx 比例
 *   4) PHP-FPM socket / 进程
 *   5) 磁盘使用率
 *   6) 站点 HTTP 自检 (首页 200 且含关键内容)
 *   7) API 错误日志中的致命错误
 *
 * 结果写入 settings 表 (health_last_*), 后台概览页读取展示。
 */

$ROOT  = '/www/wwwroot/cgtk.wjlo.cc';
$OPS   = __DIR__;
$SITE  = 'https://cgtk.wjlo.cc';
$UA    = 'cgtk-health/1.0 (+internal-monitor)';

require_once $ROOT . '/lib/db.php';
require_once $ROOT . '/lib/ratelimit.php';
require_once $OPS . '/notify.php';

$cfg   = require $ROOT . '/lib/config.php';
$alert = $cfg['alert'] ?? [];
if (empty($alert['enable'])) {
    fwrite(STDERR, "alert disabled\n");
    exit(0);
}

$minSmsFail = (int) ($alert['sms_fail_min'] ?? 3);
$issues  = [];   // [key, level, text]
$checks  = [];   // 明细, 用于后台展示

function add_check(array &$checks, string $name, bool $ok, string $note = ''): void
{
    $checks[] = ['n' => $name, 'ok' => $ok, 'note' => $note];
}

/* ── 1. 数据库 ── */
$pdo = null;
try {
    $pdo = db();
    $pdo->query('SELECT 1');
    add_check($checks, '数据库', true);
} catch (Throwable $e) {
    add_check($checks, '数据库', false, $e->getMessage());
    $issues[] = ['db', 'crit', '数据库连接失败：' . $e->getMessage()];
}

if ($pdo) {
    /* ── 2. 短信接口连续失败 ── */
    $st = $pdo->prepare(
        "SELECT COUNT(*) t, COALESCE(SUM(status >= 500), 0) f
         FROM request_log
         WHERE action = 'sms_send' AND created_at >= NOW() - INTERVAL 30 MINUTE"
    );
    $st->execute();
    $r = $st->fetch() ?: ['t' => 0, 'f' => 0];
    $t = (int) $r['t'];
    $f = (int) $r['f'];
    if ($t >= $minSmsFail && $f === $t) {
        add_check($checks, '短信接口', false, "{$t} 次调用全部失败");
        $issues[] = ['sms', 'crit', "短信发送接口近 30 分钟 {$t} 次调用全部返回 5xx，用户无法收到验证码"];
    } elseif ($t > 0 && $f > 0) {
        add_check($checks, '短信接口', true, "{$f}/{$t} 失败");
        if ($f / $t >= 0.5 && $t >= 5) {
            $issues[] = ['sms_partial', 'warn', "短信接口近 30 分钟失败率 " . round($f / $t * 100) . "%（{$f}/{$t}）"];
        }
    } else {
        add_check($checks, '短信接口', true);
    }

    /* ── 3. 全站 5xx ── */
    $st = $pdo->prepare(
        "SELECT COUNT(*) t, COALESCE(SUM(status >= 500), 0) f
         FROM request_log
         WHERE created_at >= NOW() - INTERVAL 30 MINUTE"
    );
    $st->execute();
    $r = $st->fetch() ?: ['t' => 0, 'f' => 0];
    $t = (int) $r['t'];
    $f = (int) $r['f'];
    $ratio = $t > 0 ? $f / $t : 0;
    if ($t >= 20 && $ratio >= 0.2) {
        add_check($checks, '接口错误率', false, round($ratio * 100) . "% ({$f}/{$t})");
        $issues[] = ['http5xx', 'crit', "近 30 分钟接口 5xx 占比 " . round($ratio * 100) . "%（{$f}/{$t}）"];
    } else {
        add_check($checks, '接口错误率', true, $t ? round($ratio * 100) . '%' : '无流量');
    }
}

/* ── 4. PHP-FPM ── */
$socket = '/tmp/php-cgi-81.sock';
$fpmOk  = file_exists($socket);
exec("pgrep -f 'php-fpm: master' 2>/dev/null | wc -l", $o, $rc);
$masters = (int) trim((string) ($o[0] ?? '0'));
add_check($checks, 'PHP-FPM', $fpmOk && $masters > 0, "socket " . ($fpmOk ? 'ok' : '缺失') . " / master {$masters}");
if (!$fpmOk || $masters === 0) {
    $issues[] = ['fpm', 'crit', 'PHP-FPM 不可用（socket ' . ($fpmOk ? '正常' : '缺失') . "，master 进程 {$masters} 个）"];
}

/* ── 5. 磁盘 ── */
$df = @disk_free_space('/');
$dt = @disk_total_space('/');
$usedPct = ($df !== false && $dt) ? round((1 - $df / $dt) * 100, 1) : -1;
add_check($checks, '磁盘', $usedPct >= 0 && $usedPct < 90, $usedPct >= 0 ? $usedPct . '%' : '未知');
if ($usedPct >= 95) {
    $issues[] = ['disk', 'crit', "磁盘剩余不足，已用 {$usedPct}%"];
} elseif ($usedPct >= 90) {
    $issues[] = ['disk_warn', 'warn', "磁盘已用 {$usedPct}%"];
}

/* ── 6. HTTP 自检 ── */
$ch = curl_init($SITE . '/');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 12,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_USERAGENT      => $UA,
    CURLOPT_FOLLOWLOCATION => true,
]);
$body = curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$cerr = curl_error($ch);
curl_close($ch);
$httpOk = ($code === 200 && is_string($body) && strpos($body, '创高题库') !== false);
add_check($checks, '站点访问', $httpOk, $httpOk ? '200' : ('HTTP ' . $code . ($cerr ? ' ' . $cerr : '')));
if (!$httpOk) {
    $issues[] = ['http_down', 'crit', '站点首页异常：HTTP ' . $code . ($cerr ? ' / ' . $cerr : '')];
}

/* ── 7. API 错误日志中的致命错误 ── */
$apiLog = '/www/wwwlogs/cgtk-api-error.log';
$fatal  = 0;
if (is_readable($apiLog)) {
    $fh = fopen($apiLog, 'r');
    if ($fh) {
        $cut = time() - 900; // 15 分钟
        while (($line = fgets($fh)) !== false) {
            if (strpos($line, 'FATAL') === false) {
                continue;
            }
            $ts = strtotime(substr($line, 0, 25));
            if ($ts !== false && $ts >= $cut) {
                $fatal++;
            }
        }
        fclose($fh);
    }
}
add_check($checks, '致命错误', $fatal === 0, $fatal ? "{$fatal} 条/15分钟" : '无');
if ($fatal > 0) {
    $issues[] = ['fatal', 'crit', "近 15 分钟出现 {$fatal} 条 PHP 致命错误（见 cgtk-api-error.log）"];
}

/* ── 汇总写库 ── */
$state = $issues ? 'bad' : 'ok';
$detail = $issues
    ? implode('；', array_map(static fn($i) => $i[2], $issues))
    : implode('，', array_map(static fn($c) => $c['n'] . ':' . ($c['ok'] ? 'ok' : 'fail'), $checks));

if ($pdo) {
    try {
        $save = $pdo->prepare(
            'INSERT INTO settings (k, v) VALUES (?, ?) AS n
             ON DUPLICATE KEY UPDATE v = n.v'
        );
        $save->execute(['health_last_at', date('Y-m-d H:i:s')]);
        $save->execute(['health_last_state', $state]);
        $save->execute(['health_last_detail', mb_substr($detail, 0, 255)]);
        $save->execute(['health_checks', mb_substr(json_encode($checks, JSON_UNESCAPED_UNICODE), 0, 800)]);
    } catch (Throwable $e) {
        // 写状态失败不影响告警
    }
}

if (!$issues) {
    // 恢复正常时通知一次(上次是 bad)
    $prev = null;
    if ($pdo) {
        $st = $pdo->prepare('SELECT v FROM settings WHERE k = ?');
        $st->execute(['health_prev_state']);
        $prev = $st->fetchColumn();
    }
    if ($prev === 'bad') {
        alert_send($alert, '站点已恢复正常', '此前告警的异常已消失。', 'info');
    }
    if ($pdo) {
        try {
            $pdo->prepare('INSERT INTO settings (k,v) VALUES (?,?) AS n ON DUPLICATE KEY UPDATE v=n.v')
                ->execute(['health_prev_state', 'ok']);
        } catch (Throwable $e) {}
    }
    echo "OK\n";
    exit(0);
}

/* 去重: 同一 key 在 min_interval 内只发一次 */
$minInterval = max(300, (int) ($alert['min_interval'] ?? 1800));
$sent = [];
foreach ($issues as [$key, $level, $text]) {
    $stKey = 'alert_at_' . $key;
    $last = 0;
    if ($pdo) {
        $st = $pdo->prepare('SELECT v FROM settings WHERE k = ?');
        $st->execute([$stKey]);
        $last = (int) ($st->fetchColumn() ?: 0);
    }
    if (time() - $last < $minInterval) {
        continue;
    }
    $res = alert_send($alert, '站点告警', $text, $level);
    if ($pdo) {
        try {
            $pdo->prepare('INSERT INTO settings (k,v) VALUES (?,?) AS n ON DUPLICATE KEY UPDATE v=n.v')
                ->execute([$stKey, (string) time()]);
            $pdo->prepare('INSERT INTO settings (k,v) VALUES (?,?) AS n ON DUPLICATE KEY UPDATE v=n.v')
                ->execute(['health_prev_state', 'bad']);
        } catch (Throwable $e) {}
    }
    $sent[] = $key . ':' . ($res['ok'] ? 'sent' : $res['msg']);
}

echo ($sent ? 'ALERT ' . implode(', ', $sent) : 'ALERT suppressed') . "\n";
exit(0);
