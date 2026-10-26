<?php
/**
 * 手机号免注册登录 + 搜题额度
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ratelimit.php';

function auth_cfg(): array
{
    $c = require __DIR__ . '/config.php';
    return $c['auth'] ?? [];
}

/* ────────── 会话 ────────── */

function auth_token_from_cookie(): string
{
    $name = auth_cfg()['cookie'] ?? 'cg_sess';
    return (string) ($_COOKIE[$name] ?? '');
}

function auth_current_user(): ?array
{
    static $cached = false;
    static $user = null;
    if ($cached) {
        return $user;
    }
    $cached = true;

    $token = auth_token_from_cookie();
    if ($token === '' || strlen($token) < 32) {
        return $user = null;
    }
    $hash = hash('sha256', $token);
    $st = db()->prepare(
        "SELECT u.id, u.phone, u.searches_used, u.searches_limit, u.status, s.expires
         FROM auth_sessions s JOIN users u ON u.id = s.user_id
         WHERE s.token_hash = ? LIMIT 1"
    );
    $st->execute([$hash]);
    $row = $st->fetch();
    if (!$row) {
        return $user = null;
    }
    if ((int) $row['expires'] < time() || (int) $row['status'] !== 1) {
        return $user = null;
    }
    return $user = [
        'id'    => (int) $row['id'],
        'phone' => (string) $row['phone'],
        'used'  => (int) $row['searches_used'],
        'limit' => (int) $row['searches_limit'],
    ];
}

function auth_create_session(int $userId): string
{
    $cfg   = auth_cfg();
    $token = bin2hex(random_bytes(32));
    $ttl   = (int) ($cfg['session_ttl'] ?? 604800);
    $ipBin = ip_bin(client_ip());
    $pdo   = db();
    $pdo->prepare("DELETE FROM auth_sessions WHERE expires < ?")->execute([time()]);
    $pdo->prepare(
        "INSERT INTO auth_sessions (token_hash, user_id, ip, expires) VALUES (?,?,?,?)"
    )->execute([hash('sha256', $token), $userId, $ipBin, time() + $ttl]);

    setcookie($cfg['cookie'] ?? 'cg_sess', $token, [
        'expires'  => time() + $ttl,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off'),
    ]);
    return $token;
}

function auth_logout(): void
{
    $token = auth_token_from_cookie();
    if ($token !== '') {
        db()->prepare("DELETE FROM auth_sessions WHERE token_hash = ?")
            ->execute([hash('sha256', $token)]);
    }
    $cfg = auth_cfg();
    setcookie($cfg['cookie'] ?? 'cg_sess', '', ['expires' => time() - 3600, 'path' => '/']);
}

/* ────────── 用户 ────────── */

/** 按手机号查用户, 不存在则自动注册 */
function auth_find_or_create(string $phone): array
{
    $pdo = db();
    $st  = $pdo->prepare("SELECT id, phone, searches_used, searches_limit, status FROM users WHERE phone = ?");
    $st->execute([$phone]);
    $row = $st->fetch();
    if ($row) {
        $pdo->prepare("UPDATE users SET last_login = NOW(), last_ip = ? WHERE id = ?")
            ->execute([ip_bin(client_ip()), $row['id']]);
        return ['id' => (int) $row['id'], 'phone' => $row['phone'],
                'used' => (int) $row['searches_used'], 'limit' => (int) $row['searches_limit'],
                'created' => false];
    }
    $limit = (int) (auth_cfg()['quota_default'] ?? 150);
    $pdo->prepare(
        "INSERT INTO users (phone, searches_used, searches_limit, last_login, last_ip)
         VALUES (?, 0, ?, NOW(), ?)"
    )->execute([$phone, $limit, ip_bin(client_ip())]);
    return ['id' => (int) $pdo->lastInsertId(), 'phone' => $phone,
            'used' => 0, 'limit' => $limit, 'created' => true];
}

/** 消耗一次搜题机会; 返回 [ok, 剩余] */
function auth_consume(int $userId, int $n = 1): array
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT searches_used, searches_limit FROM users WHERE id = ? FOR UPDATE");
        $st->execute([$userId]);
        $row = $st->fetch();
        if (!$row) {
            $pdo->rollBack();
            return [false, 0, 0];
        }
        $used  = (int) $row['searches_used'];
        $limit = (int) $row['searches_limit'];
        if ($used + $n > $limit) {
            $pdo->rollBack();
            return [false, max(0, $limit - $used), $limit];
        }
        $pdo->prepare("UPDATE users SET searches_used = searches_used + ? WHERE id = ?")
            ->execute([$n, $userId]);
        $pdo->commit();
        return [true, $limit - $used - $n, $limit];
    } catch (Throwable $e) {
        $pdo->rollBack();
        return [false, 0, 0];
    }
}

/* ────────── 短信验证码 ────────── */

function phone_valid(string $phone): bool
{
    return (bool) preg_match('/^1[3-9]\d{9}$/', $phone);
}

function phone_mask(string $phone): string
{
    return strlen($phone) === 11 ? substr($phone, 0, 3) . '****' . substr($phone, 7) : $phone;
}

/** 发送频率检查: 返回错误信息或 null */
function sms_rate_check(string $phone): ?string
{
    $cfg = auth_cfg();
    $pdo = db();
    $ipBin = ip_bin(client_ip());

    // 同号间隔
    $st = $pdo->prepare("SELECT created_at FROM sms_codes WHERE phone = ? ORDER BY id DESC LIMIT 1");
    $st->execute([$phone]);
    $last = $st->fetchColumn();
    if ($last && time() - strtotime((string) $last) < (int) ($cfg['sms_phone_gap'] ?? 60)) {
        return '同一手机号发送过于频繁，请稍后再试';
    }
    // 同号每日
    $st = $pdo->prepare("SELECT COUNT(*) FROM sms_codes WHERE phone = ? AND created_at >= CURDATE()");
    $st->execute([$phone]);
    if ((int) $st->fetchColumn() >= (int) ($cfg['sms_phone_daily'] ?? 5)) {
        return '该手机号今日发送次数已达上限';
    }
    // 同 IP 每小时
    $st = $pdo->prepare("SELECT COUNT(*) FROM sms_codes WHERE ip = ? AND created_at > (NOW() - INTERVAL 1 HOUR)");
    $st->execute([$ipBin]);
    if ((int) $st->fetchColumn() >= (int) ($cfg['sms_ip_hourly'] ?? 10)) {
        return '当前网络发送次数过多，请稍后再试';
    }
    // 全站总量(成本兜底): 防止整体被刷导致账单暴涨
    $st = $pdo->prepare("SELECT COUNT(*) FROM sms_codes WHERE created_at > (NOW() - INTERVAL 10 MINUTE)");
    $st->execute();
    if ((int) $st->fetchColumn() >= (int) ($cfg['sms_global_10min'] ?? 20)) {
        return '当前发送人数较多，请稍后再试';
    }
    // 同 IP 每日
    $st = $pdo->prepare("SELECT COUNT(*) FROM sms_codes WHERE ip = ? AND created_at >= CURDATE()");
    $st->execute([$ipBin]);
    if ((int) $st->fetchColumn() >= (int) ($cfg['sms_ip_daily'] ?? 30)) {
        return '当前网络今日发送次数已达上限';
    }
    return null;
}

function sms_save_code(string $phone, ?string $code): void
{
    $cfg = auth_cfg();
    $pdo = db();
    if ($code === null) {
        // 平台托管模式: 本站不掌握验证码, 仅记录一次发送用于频控与审计
        $pdo->prepare(
            "INSERT INTO sms_codes (phone, code_hash, used, expires, ip) VALUES (?,?,1,?,?)"
        )->execute([$phone, '', time(), ip_bin(client_ip())]);
        return;
    }
    $pdo->prepare("DELETE FROM sms_codes WHERE phone = ? AND used = 0")->execute([$phone]);
    $pdo->prepare(
        "INSERT INTO sms_codes (phone, code_hash, expires, ip) VALUES (?,?,?,?)"
    )->execute([$phone, hash('sha256', $code), time() + (int) ($cfg['sms_ttl'] ?? 300), ip_bin(client_ip())]);
}

/** 校验验证码; 成功返回 true 并标记已用 */
function sms_check_code(string $phone, string $code): array
{
    $cfg = auth_cfg();
    $pdo = db();
    $st = $pdo->prepare(
        "SELECT id, code_hash, attempts, expires FROM sms_codes
         WHERE phone = ? AND used = 0 ORDER BY id DESC LIMIT 1"
    );
    $st->execute([$phone]);
    $row = $st->fetch();
    if (!$row) {
        return [false, '请先获取验证码'];
    }
    if ((int) $row['expires'] < time()) {
        return [false, '验证码已过期，请重新获取'];
    }
    if ((int) $row['attempts'] >= (int) ($cfg['sms_max_attempts'] ?? 5)) {
        return [false, '尝试次数过多，请重新获取'];
    }
    if (!hash_equals($row['code_hash'], hash('sha256', $code))) {
        $pdo->prepare("UPDATE sms_codes SET attempts = attempts + 1 WHERE id = ?")->execute([$row['id']]);
        return [false, '验证码错误'];
    }
    $pdo->prepare("UPDATE sms_codes SET used = 1 WHERE id = ?")->execute([$row['id']]);
    return [true, ''];
}
