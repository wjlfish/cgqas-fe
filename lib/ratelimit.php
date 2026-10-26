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
function client_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

function ip_bin(string $ip): string
{
    $b = @inet_pton($ip);
    return $b === false ? inet_pton('0.0.0.0') : $b;
}

/** 管理旁路: 请求头 X-CG-Admin 与配置一致时跳过限流与 UA 校验 */
function is_admin(): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    $key = (string) (cfg()['admin_key'] ?? '');
    $got = (string) ($_SERVER['HTTP_X_CG_ADMIN'] ?? '');
    $ok  = ($key !== '' && $got !== '' && hash_equals($key, $got));
    return $ok;
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
    $want = substr(hash_hmac('sha256', client_ip() . '|' . $exp, $c['secret']), 0, 40);
    return hash_equals($want, $sig);
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
