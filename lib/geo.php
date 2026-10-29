<?php
declare(strict_types=1);
/**
 * 地域限制（软 ban）：仅面向中国大陆开放
 *
 * 判定依据：离线 ip2region xdb（不依赖任何外部 API，无网络开销）
 * 命中境外时：
 *   - 页面不渲染任何功能，只给提示页
 *   - API 返回 403 geo_blocked
 *
 * 例外：
 *   - 管理员（admin cookie）始终放行，方便境外运维
 *   - 私网/保留地址放行（健康检查走 127.0.0.1）
 *   - config.geo.allow_ips 中的 IP 放行
 *   - 库文件缺失或解析异常时【放行】并记日志（可用性优先）
 */

require_once __DIR__ . '/config.php';

function geo_cfg(): array
{
    $c = require __DIR__ . '/config.php';
    return $c['geo'] ?? [];
}

function geo_enabled(): bool
{
    $g = geo_cfg();
    return !empty($g['enable']);
}

/** 取客户端 IP（可被 config.geo.test_ip 覆盖，仅用于联调） */
function geo_client_ip(): string
{
    $g = geo_cfg();
    if (!empty($g['test_ip'])) {
        return (string) $g['test_ip'];
    }
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

function geo_log(string $msg): void
{
    $g = geo_cfg();
    $file = (string) ($g['log'] ?? '/www/wwwlogs/cgtk-geo-block.log');
    @file_put_contents(
        $file,
        date('c') . "\t" . $msg . "\t" . ($_SERVER['REQUEST_URI'] ?? '-') . "\n",
        FILE_APPEND | LOCK_EX
    );
}

/** 私网 / 本机 / 保留地址 */
function geo_is_local(string $ip): bool
{
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        return true; // 解析失败按本机处理，避免误伤
    }
    return filter_var(
        $ip,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    ) === false;
}

/**
 * 查询 IP 归属，返回 region 字符串（形如 "中国|江苏省|南京市|0|CN"）
 * 失败返回 null
 */
function geo_region(string $ip): ?string
{
    static $searcher = null;
    static $tried = false;

    if ($tried && $searcher === null) {
        return null;
    }
    if ($searcher === null) {
        $tried = true;
        $g = geo_cfg();
        $vendor = (string) ($g['vendor'] ?? '/www/wwwroot/cgtk-ops/vendor/Searcher.class.php');
        $xdb    = (string) ($g['xdb'] ?? '/www/wwwroot/cgtk-ops/data/ip2region_v4.xdb');
        if (!@is_readable($vendor) || !@is_readable($xdb)) {
            geo_log('GEO_DB_MISSING vendor=' . (int) @is_readable($vendor) . ' xdb=' . (int) @is_readable($xdb));
            return null;
        }
        try {
            require_once $vendor;
            $searcher = \ip2region\xdb\Searcher::newWithFileOnly(
                \ip2region\xdb\Util::versionFromName('v4'),
                $xdb
            );
        } catch (Throwable $e) {
            geo_log('GEO_INIT_FAIL ' . $e->getMessage());
            $searcher = null;
            return null;
        }
    }

    try {
        $r = $searcher->search($ip);
        return is_string($r) ? $r : null;
    } catch (Throwable $e) {
        geo_log('GEO_SEARCH_FAIL ' . $ip . ' ' . $e->getMessage());
        return null;
    }
}

/** 是否中国大陆（排除港澳台） */
function geo_is_mainland(string $ip): bool
{
    $region = geo_region($ip);
    if ($region === null) {
        return true; // 查不到 → 放行
    }
    $parts = explode('|', $region);
    $country  = $parts[0] ?? '';
    $province = $parts[1] ?? '';

    if (strpos($country, '中国') !== 0) {
        return false;
    }
    foreach (['香港', '澳门', '澳門', '台湾', '臺灣'] as $ex) {
        if (strpos($province, $ex) !== false) {
            return false;
        }
    }
    return true;
}

/** 管理员或白名单放行 */
function geo_exempt(string $ip): bool
{
    $g = geo_cfg();
    foreach ((array) ($g['allow_ips'] ?? []) as $x) {
        if ($x !== '' && $x === $ip) {
            return true;
        }
    }
    // 管理员 cookie 放行（境外也能运维后台）
    try {
        require_once __DIR__ . '/admin.php';
        if (function_exists('admin_logged_in') && admin_logged_in()) {
            return true;
        }
    } catch (Throwable $e) {
        // 忽略
    }
    return false;
}

/** 是否应拦截 */
function geo_blocked(): bool
{
    if (!geo_enabled()) {
        return false;
    }
    $ip = geo_client_ip();
    if (geo_is_local($ip)) {
        return false;
    }
    // 无 IPv6 库：域名也没有 AAAA，IPv6 直连极少见，放行并记录
    if (strpos($ip, ':') !== false) {
        geo_log('GEO_IPV6_ALLOW ' . $ip);
        return false;
    }
    if (geo_exempt($ip)) {
        return false;
    }
    if (geo_is_mainland($ip)) {
        return false;
    }
    geo_log('GEO_BLOCK ' . $ip . ' ' . (string) geo_region($ip));
    return true;
}

/** 输出拦截页（无任何功能、无 JS、不签发令牌） */
function geo_blocked_page(): void
{
    $ip = geo_client_ip();
    $region = geo_region($ip);
    $where = '';
    if ($region !== null) {
        $p = explode('|', $region);
        $where = trim(implode(' ', array_filter([$p[0] ?? '', $p[1] ?? '', $p[2] ?? ''])));
    }
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    ?><!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>仅面向中国大陆用户开放</title>
<meta name="robots" content="noindex,nofollow">
<style>
  :root{--paper:#F7F5F0;--ink:#17150F;--ink-2:#4A453B;--ink-3:#6F685B;
        --rule:#DCD5C7;--seal:#B03A2E}
  *{box-sizing:border-box}
  body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;
       background:var(--paper);color:var(--ink);padding:24px;
       font:400 14px/1.7 "Inter","Helvetica Neue",-apple-system,"PingFang SC",
            "Hiragino Sans GB","Microsoft YaHei",sans-serif}
  .box{max-width:520px;width:100%;text-align:center}
  .seal{display:inline-flex;align-items:center;justify-content:center;width:56px;height:56px;
        border:1px solid var(--seal);color:var(--seal);font-size:24px;margin-bottom:26px;
        font-family:"Songti SC",STSong,SimSun,serif}
  h1{font-family:"Songti SC","Source Han Serif SC",STSong,SimSun,serif;
     font-size:22px;font-weight:600;margin:0 0 14px;letter-spacing:.01em}
  p{margin:0 0 10px;color:var(--ink-2);font-size:13.5px}
  .hint{color:var(--ink-3);font-size:12.5px;margin-top:22px}
  .meta{margin-top:34px;padding-top:16px;border-top:1px solid var(--rule);
        color:var(--ink-3);font-size:11px;letter-spacing:.04em}
  .meta code{font-family:ui-monospace,Menlo,monospace;color:var(--ink-2)}
</style>
</head>
<body>
  <div class="box">
    <div class="seal">限</div>
    <h1>仅面向中国大陆用户开放</h1>
    <p>检测到你的网络出口位于中国大陆以外，本站暂不提供访问。</p>
    <p class="hint">请切换网络，或关闭代理 / VPN 后重试。</p>
    <div class="meta">
      <?php if ($ip !== '0.0.0.0'): ?>IP <code><?= htmlspecialchars($ip, ENT_QUOTES) ?></code><?php endif; ?>
      <?php if ($where !== ''): ?> · 归属地 <code><?= htmlspecialchars($where, ENT_QUOTES) ?></code><?php endif; ?>
    </div>
  </div>
</body>
</html><?php
    exit;
}
