<?php
/**
 * 渐进式图形验证码
 *
 * 设计原则: 正常用户永不打扰。
 * 只有当同一 IP 的「索取强度」超过阈值时才要求验证, 通过后 10 分钟内免验。
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ratelimit.php';

const CAPTCHA_CHARS = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';  // 去掉 0O1I 等易混字符

function captcha_cfg(): array
{
    return cfg()['captcha'] ?? [];
}

/** 判断当前 IP 是否需要先过验证码 */
function captcha_needed(string $ipBin): bool
{
    $c = captcha_cfg();
    if (empty($c['enable'])) {
        return false;
    }
    $pdo = db();
    $now = time();

    // 1) 近期已通过验证 → 放行
    $st = $pdo->prepare("SELECT cleared_until FROM rate_limits WHERE ip = ?");
    $st->execute([$ipBin]);
    if ((int) ($st->fetchColumn() ?: 0) > $now) {
        return false;
    }

    // 2) 每分钟请求数
    $st = $pdo->prepare("SELECT win_count FROM rate_limits WHERE ip = ?");
    $st->execute([$ipBin]);
    $winCount = (int) ($st->fetchColumn() ?: 0);

    // 3) 5 分钟内返回的题目条数
    $day = intdiv($now, 86400);
    $st = $pdo->prepare("SELECT win_items FROM serve_budget WHERE ip = ? AND day = ?");
    $st->execute([$ipBin, $day]);
    $winItems = (int) ($st->fetchColumn() ?: 0);

    return $winCount > (int) ($c['req_per_min'] ?? 40)
        || $winItems > (int) ($c['items_5min'] ?? 120);
}

/** 签发一个验证码挑战: 生成验证码 + 渲染 PNG 入库, 返回 id */
function captcha_issue(string $ipBin): string
{
    $c   = captcha_cfg();
    $pdo = db();
    $pdo->prepare("DELETE FROM captcha WHERE expires < ? OR ip = ?")->execute([time() - 60, $ipBin]);

    $len  = max(3, (int) ($c['length'] ?? 4));
    $code = '';
    $pool = strlen(CAPTCHA_CHARS);
    for ($i = 0; $i < $len; $i++) {
        $code .= CAPTCHA_CHARS[random_int(0, $pool - 1)];
    }
    $png = captcha_png($code);

    $id = bin2hex(random_bytes(12));
    $pdo->prepare(
        "INSERT INTO captcha (id, ip, code_hash, image, expires) VALUES (?,?,?,?,?)"
    )->execute([$id, $ipBin, hash('sha256', strtoupper($code)), $png,
                time() + (int) ($c['ttl'] ?? 120)]);
    return $id;
}

/** 取出图片字节并按 IP 校验 */
function captcha_stream(string $id, string $ipBin): void
{
    $st = db()->prepare("SELECT ip, image, expires, used FROM captcha WHERE id = ?");
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row || $row['ip'] !== $ipBin || (int) $row['expires'] < time() || (int) $row['used'] === 1) {
        http_response_code(410);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'captcha expired';
        exit;
    }
    header('Content-Type: image/png');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    echo $row['image'];
    exit;
}

/** 生成验证码 PNG 字节 */
function captcha_font(): string
{
    $c = captcha_cfg();
    if (!empty($c['font']) && @is_file($c['font'])) {
        return $c['font'];
    }
    // 站内子集字体(仅含验证码字符集), 约 4KB
    $local = dirname(__DIR__) . '/assets/captcha.ttf';
    return @is_file($local) ? $local : '';
}

function captcha_png(string $code): string
{
    $font = captcha_font();
    $w    = 132;
    $h    = 46;

    $im = imagecreatetruecolor($w, $h);
    $bg = imagecolorallocate($im, 247, 245, 240);
    imagefill($im, 0, 0, $bg);

    for ($i = 0; $i < 5; $i++) {
        $c1 = imagecolorallocate($im, random_int(200, 225), random_int(195, 220), random_int(185, 210));
        imageline($im, random_int(0, $w), random_int(0, $h), random_int(0, $w), random_int(0, $h), $c1);
    }
    for ($i = 0; $i < 90; $i++) {
        $c2 = imagecolorallocate($im, random_int(170, 215), random_int(165, 210), random_int(155, 200));
        imagesetpixel($im, random_int(0, $w - 1), random_int(0, $h - 1), $c2);
    }

    $n = strlen($code);
    $step = (int) (($w - 24) / max(1, $n));
    for ($i = 0; $i < $n; $i++) {
        $fg = imagecolorallocate($im, random_int(20, 70), random_int(18, 60), random_int(15, 55));
        $size = random_int(19, 23);
        $x = 14 + $i * $step;
        $y = random_int(31, 37);
        $ang = random_int(-16, 16);
        if ($font !== '') {
            imagettftext($im, $size, $ang, $x, $y, $fg, $font, $code[$i]);
        } else {
            imagestring($im, 5, $x, 14, $code[$i], $fg);
        }
    }

    ob_start();
    imagepng($im);
    $png = ob_get_clean();
    imagedestroy($im);
    return $png;
}

/** 校验答案; 成功则给该 IP 免验窗口 */
function captcha_verify(string $id, string $answer, string $ipBin): array
{
    $c   = captcha_cfg();
    $pdo = db();
    $st  = $pdo->prepare("SELECT ip, code_hash, attempts, expires, used FROM captcha WHERE id = ?");
    $st->execute([$id]);
    $row = $st->fetch();

    if (!$row) {
        return ['ok' => false, 'error' => '验证码已失效，请重新获取'];
    }
    if ($row['ip'] !== $ipBin) {
        return ['ok' => false, 'error' => '验证码无效'];
    }
    if ((int) $row['used'] === 1) {
        return ['ok' => false, 'error' => '验证码已使用'];
    }
    if ((int) $row['expires'] < time()) {
        return ['ok' => false, 'error' => '验证码已过期，请重新获取'];
    }
    if ((int) $row['attempts'] >= (int) ($c['max_attempts'] ?? 3)) {
        ban($ipBin, (int) (cfg()['limit']['ban_seconds'] ?? 900));
        return ['ok' => false, 'error' => '尝试次数过多'];
    }

    $ans = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $answer));
    if (!hash_equals($row['code_hash'], hash('sha256', $ans))) {
        $pdo->prepare("UPDATE captcha SET attempts = attempts + 1 WHERE id = ?")->execute([$id]);
        return ['ok' => false, 'error' => '验证码错误'];
    }

    $pdo->prepare("UPDATE captcha SET used = 1 WHERE id = ?")->execute([$id]);
    $until = time() + (int) ($c['clear_seconds'] ?? 600);
    $pdo->prepare(
        "INSERT INTO rate_limits (ip, cleared_until) VALUES (?,?)
         ON DUPLICATE KEY UPDATE cleared_until = VALUES(cleared_until)"
    )->execute([$ipBin, $until]);

    return ['ok' => true, 'cleared_for' => (int) ($c['clear_seconds'] ?? 600)];
}

