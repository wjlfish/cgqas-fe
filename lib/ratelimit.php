<?php
/**
 * 限流 / 反爬
 *  - 每个 IP 每分钟、每天、10秒突发的三维计数
 *  - 触发阈值累计 strikes, 达到后封禁
 *  - 前端令牌(HMAC)校验, 挡住不会解析页面的裸脚本
 *  - UA 黑名单直接重封
 *  - 翻页枚举检测
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/response.php';

function cfg(): array
{
    static $c = null;
    if ($c === null) {
        $c = require __DIR__ . '/config.php';
    }
    return $c;
}

/** 取真实客户端 IP。本站无 CDN, 只信任 REMOTE_ADDR, 不采信 XFF(可伪造) */

/** 判断是否 Cloudflare 边缘 IP（只有这些来源才允许信任 CF-Connecting-IP） */
function cf_ip_range(string $ip): bool
{
    static $v4 = [
        '173.245.48.0/20','103.21.244.0/22','103.22.200.0/22','103.31.4.0/22',
        '141.101.64.0/18','108.162.192.0/18','190.93.240.0/20','188.114.96.0/20',
        '197.234.240.0/22','198.41.128.0/17','162.158.0.0/15','104.16.0.0/13',
        '104.24.0.0/14','172.64.0.0/13','131.0.72.0/22',
    ];
    static $v6 = [
        '2400:cb00::/32','2606:4700::/32','2803:f800::/32','2405:b500::/32',
        '2405:8100::/32','2a06:98c0::/29','2c0f:f248::/32',
    ];
    $isV4 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    $list = $isV4 ? $v4 : (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? $v6 : []);
    $bin = @inet_pton($ip);
    if ($bin === false) {
        return false;
    }
    foreach ($list as $cidr) {
        [$net, $bits] = explode('/', $cidr);
        $netBin = @inet_pton($net);
        if ($netBin === false || strlen($netBin) !== strlen($bin)) {
            continue;
        }
        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        $rem   = $bits % 8;
        if ($bytes > 0 && substr($bin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
            continue;
        }
        if ($rem === 0) {
            return true;
        }
        $mask = chr(0xFF << (8 - $rem));
        if ((ord($bin[$bytes]) & ord($mask)) === (ord($netBin[$bytes]) & ord($mask))) {
            return true;
        }
    }
    return false;
}

function client_ip(): string
{
    $c = cfg();
    // 部署在 CDN/反代后面时，REMOTE_ADDR 是边缘节点地址，必须取真实客户端 IP
    if (!empty($c['proxy']['trust'])) {
        // 仅当直连方确实是 Cloudflare 边缘时才信任该头，否则可被伪造
        $peer = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $fromCf = $peer !== '' && cf_ip_range($peer);
        $cf = $fromCf ? (string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? '') : '';
        if ($cf !== '' && filter_var($cf, FILTER_VALIDATE_IP)) {
            return $cf;
        }
        if (!$fromCf) {
            $ip = $peer !== '' ? $peer : '0.0.0.0';
            return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
        }
        $xff = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($xff !== '') {
            foreach (explode(',', $xff) as $part) {
                $part = trim($part);
                if (filter_var(
                    $part,
                    FILTER_VALIDATE_IP,
                    FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
                )) {
                    return $part;
                }
            }
        }
    }
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

/** 当前请求是否 HTTPS（含反代终结 TLS 的情况） */
function is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }
    $proto = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    return $proto === 'https';
}

function ip_bin(string $ip): string
{
    $b = @inet_pton($ip);
    return $b === false ? inet_pton('0.0.0.0') : $b;
}

/** 管理旁路: 请求头 X-CG-Admin 与配置一致时跳过限流与 UA 校验 */
/** 仅凭 X-CG-Admin 头(API 密钥方式)鉴权 */
function is_admin_key(): bool
{
    $key = (string) (cfg()['admin_key'] ?? '');
    $got = (string) ($_SERVER['HTTP_X_CG_ADMIN'] ?? '');
    return $key !== '' && $got !== '' && hash_equals($key, $got);
}

/**
 * 管理员身份: API 密钥头 或 后台登录 cookie 均可。
 * 用于豁免 IP 限流/封禁/预算等"按 IP 统计"的限制。
 */
function is_admin(): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    if (is_admin_key()) {
        return $ok = true;
    }
    // 后台登录 cookie(与 lib/admin.php 的 admin_cookie_value 一致)
    $cookie = (string) ($_COOKIE['cg_admin'] ?? '');
    if ($cookie !== '') {
        $want = hash_hmac('sha256', 'cgtk-admin-v1', (string) cfg()['secret']);
        return $ok = hash_equals($want, $cookie);
    }
    return $ok = false;
}

/** 签发前端令牌 */
function issue_token(?string $ip = null, ?int $ttl = null): array
{
    $c   = cfg();
    $ip  = $ip ?? client_ip();
    $ttl = $ttl ?? (int) $c['token_ttl'];
    $exp = time() + $ttl;
    $sig = substr(hash_hmac('sha256', $ip . '|' . $exp, $c['secret']), 0, 40);
    return ['token' => $exp . '.' . $sig, 'expires_in' => $ttl];
}

/** IP 的容错键：完整地址 + 网段前缀，容忍运营商 CGNAT / 代理出口小幅漂移 */
function token_ip_keys(string $ip): array
{
    $keys = [$ip];
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $p = explode('.', $ip);
        $keys[] = $p[0] . '.' . $p[1] . '.' . $p[2] . '.0/24';
    } elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        $bin = @inet_pton($ip);
        if ($bin !== false) {
            $keys[] = inet_ntop(substr($bin, 0, 8) . str_repeat("\0", 8)) . '/64';
        }
    }
    return $keys;
}

function verify_token(): bool
{
    $c   = cfg();
    $raw = $_SERVER['HTTP_X_CG_TOKEN'] ?? '';
    if ($raw === '' || strpos($raw, '.') === false) {
        return false;
    }
    [$exp, $sig] = explode('.', $raw, 2);
    if (!ctype_digit($exp) || (int) $exp < time()) {
        return false;
    }
    $sig = (string) $sig;
    foreach (token_ip_keys(client_ip()) as $key) {
        $want = substr(hash_hmac('sha256', $key . '|' . $exp, $c['secret']), 0, 40);
        if (hash_equals($want, $sig)) {
            return true;
        }
    }
    return false;
}

function ua_blacklisted(): bool
{
    $ua = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
    if ($ua === '' || strlen($ua) < 12) {
        return true; // 空 UA / 极短 UA 不是正常浏览器
    }
    foreach (cfg()['ua_blacklist'] as $bad) {
        if (strpos($ua, $bad) !== false) {
            return true;
        }
    }
    return false;
}

function ban(string $ipBin, int $seconds, bool $hard = false): void
{
    $pdo = db();
    $st  = $pdo->prepare(
        "INSERT INTO rate_limits (ip, blocked_until, strikes) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE blocked_until = VALUES(blocked_until), strikes = strikes + 1"
    );
    $st->execute([$ipBin, time() + $seconds, $hard ? 99 : 1]);
}

/**
 * 计数并判断是否放行。返回 [allowed(bool), info(array)]
 */
function rl_hit(string $action): array
{
    $c   = cfg();
    $ip  = client_ip();
    $bin = ip_bin($ip);
    $now = time();
    $ws  = intdiv($now, 60);
    $bs  = intdiv($now, 10);
    $ds  = intdiv($now, 86400);

    $pdo = db();
    $sql = "INSERT INTO rate_limits (ip, win_start, win_count, burst_start, burst_count, day_start, day_count)
            VALUES (?, ?, 1, ?, 1, ?, 1) AS new
            ON DUPLICATE KEY UPDATE
              win_count   = IF(rate_limits.win_start   = new.win_start,   rate_limits.win_count   + 1, 1),
              win_start   = new.win_start,
              burst_count = IF(rate_limits.burst_start = new.burst_start, rate_limits.burst_count + 1, 1),
              burst_start = new.burst_start,
              day_count   = IF(rate_limits.day_start   = new.day_start,   rate_limits.day_count   + 1, 1),
              day_start   = new.day_start";
    $pdo->prepare($sql)->execute([$bin, $ws, $bs, $ds]);

    $st = $pdo->prepare(
        "SELECT win_count, burst_count, day_count, blocked_until, strikes
         FROM rate_limits WHERE ip = ?"
    );
    $st->execute([$bin]);
    $r = $st->fetch() ?: ['win_count' => 1, 'burst_count' => 1, 'day_count' => 1,
                          'blocked_until' => 0, 'strikes' => 0];

    $info = [
        'win'   => (int) $r['win_count'],
        'burst' => (int) $r['burst_count'],
        'day'   => (int) $r['day_count'],
        'blocked_until' => (int) $r['blocked_until'],
        'strikes' => (int) $r['strikes'],
    ];

    if ($info['blocked_until'] > $now) {
        return [false, $info + ['reason' => 'banned', 'retry_after' => $info['blocked_until'] - $now]];
    }

    $lim = $c['limit'];

    // 严重滥用: 单分钟远超阈值 -> 直接重封
    if ($info['win'] > $lim['hard_threshold']) {
        ban($bin, $lim['hard_ban_seconds'], true);
        return [false, $info + ['reason' => 'hard_ban', 'retry_after' => $lim['hard_ban_seconds']]];
    }

    // 突发只做降速提示, 不计入 strike(避免正常用户点快了被封)
    if ($info['burst'] > $lim['burst_per_10s']) {
        return [false, $info + ['reason' => 'burst', 'retry_after' => 5]];
    }

    $over = null;
    $retry = 60;
    if ($info['win'] > $lim['api_per_min']) {
        $over = 'minute';
        $retry = 60 - (time() % 60);
    } elseif ($action === 'search' && $info['win'] > $lim['search_per_min']) {
        $over = 'search';
        $retry = 60 - (time() % 60);
    } elseif ($info['day'] > $lim['per_day']) {
        $over = 'day';
        $retry = 3600;
    }

    if ($over !== null) {
        if ($info['strikes'] + 1 >= $lim['strike_limit']) {
            ban($bin, $lim['ban_seconds']);
            return [false, $info + ['reason' => 'banned', 'retry_after' => $lim['ban_seconds']]];
        }
        $pdo->prepare("UPDATE rate_limits SET strikes = strikes + 1 WHERE ip = ?")->execute([$bin]);
        return [false, $info + ['reason' => $over, 'retry_after' => $retry]];
    }

    return [true, $info];
}

/** 翻页枚举检测: 5 分钟内不同页码请求过多 */
function enum_check(string $ipBin): bool
{
    $c = cfg();
    $st = db()->prepare(
        "SELECT COUNT(DISTINCT page) FROM request_log
         WHERE ip = ? AND action = 'search' AND created_at > (NOW() - INTERVAL 5 MINUTE)"
    );
    $st->execute([$ipBin]);
    return ((int) $st->fetchColumn()) > $c['limit']['enum_pages_5min'];
}

function log_request(string $action, array $data, int $status, int $costMs): void
{
    try {
        $pdo = db();
        $pdo->prepare(
            "INSERT INTO request_log (ip, action, query, subject, qtype, page, status, ua, referer, cost_ms)
             VALUES (?,?,?,?,?,?,?,?,?,?)"
        )->execute([
            ip_bin(client_ip()),
            substr($action, 0, 24),
            mb_substr((string) ($data['q'] ?? ''), 0, 150),
            mb_substr((string) ($data['subject'] ?? ''), 0, 60),
            (string) ($data['type'] ?? ''),
            (int) ($data['page'] ?? 0),
            $status,
            mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250),
            mb_substr((string) ($_SERVER['HTTP_REFERER'] ?? ''), 0, 250),
            $costMs,
        ]);
    } catch (Throwable $e) {
        // 审计失败不影响业务
    }
}

/**
 * 记录配额: 每次返回 n 条结果累加到 serve_budget。
 * 返回 [是否允许, 明细]。超限时拒绝, 不做任何扣减。
 */
function budget_guard(string $ipBin, int $n, bool $commit = true): array
{
    $c    = cfg();
    $lim  = $c['limit'];
    $day  = intdiv(time(), 86400);
    $win  = intdiv(time(), 300);           // 5 分钟窗口
    $pdo  = db();

    $st = $pdo->prepare("SELECT items, win_start, win_items FROM serve_budget WHERE ip = ? AND day = ?");
    $st->execute([$ipBin, $day]);
    $r = $st->fetch() ?: ['items' => 0, 'win_start' => $win, 'win_items' => 0];

    $items = (int) $r['items'];
    $winItems = ((int) $r['win_start'] === $win) ? (int) $r['win_items'] : 0;

    if ($items + $n > (int) $lim['daily_items']) {
        return [false, ['reason' => 'daily_budget', 'used' => $items,
                        'limit' => (int) $lim['daily_items'], 'retry_after' => 86400 - (time() % 86400)]];
    }
    if ($winItems + $n > (int) $lim['window_items']) {
        // 5 分钟内索取过多 → 直接封禁, 比单纯拒绝更有效
        ban($ipBin, (int) $lim['ban_seconds']);
        return [false, ['reason' => 'burst_items', 'retry_after' => (int) $lim['ban_seconds']]];
    }

    if ($commit) {
        $pdo->prepare(
            "INSERT INTO serve_budget (ip, day, items, win_start, win_items)
             VALUES (?,?,?,?,?) AS new
             ON DUPLICATE KEY UPDATE
               items     = serve_budget.items + new.items,
               win_items = IF(serve_budget.win_start = new.win_start,
                              serve_budget.win_items + new.win_items, new.win_items),
               win_start = new.win_start"
        )->execute([$ipBin, $day, $n, $win, $n]);
    }

    return [true, ['used' => $items + $n, 'limit' => (int) $lim['daily_items']]];
}

/**
 * 共享主机空间有限: 概率性清理超期日志
 * 每个请求约 1/50 触发, 每次限量删除, 避免长事务
 */
function maybe_purge_logs(): void
{
    $c = cfg();
    $days = (int) ($c['retention']['days'] ?? 0);
    if ($days <= 0) {
        return;
    }
    if (random_int(1, 50) !== 1) {
        return;
    }
    try {
        $pdo = db();
        $cut = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        foreach (['request_log', 'search_logs', 'access_logs'] as $t) {
            $pdo->prepare("DELETE FROM {$t} WHERE created_at < ? LIMIT 3000")->execute([$cut]);
        }
        $pdo->prepare('DELETE FROM sms_codes WHERE created_at < ? LIMIT 1000')
            ->execute([time() - 86400]);
        $pdo->exec('DELETE FROM auth_sessions WHERE expires < UNIX_TIMESTAMP() LIMIT 500');
    } catch (Throwable $e) {
        // 清理失败不影响业务
    }
}
