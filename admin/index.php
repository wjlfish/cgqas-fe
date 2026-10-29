<?php
declare(strict_types=1);
/** 后台管理面板: 用户 / 访客 / 检索 / 短信 / 限流 / 去重 */
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/admin.php';
require_once __DIR__ . '/../lib/geo.php';

// 后台入口目录名即密钥: 路径不写死, 跟随实际部署目录
$BASE = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
if ($BASE === '' || $BASE === '.') { $BASE = ''; }

if (($_GET['logout'] ?? '') === '1') {
    admin_logout();
    header('Location: ' . $BASE . '/');
    exit;
}
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pass'])) {
    if (admin_login((string) $_POST['pass'])) {
        header('Location: ' . $BASE . '/');
        exit;
    }
    $err = '口令错误';
}

$esc = static fn($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

// 后台不做地域拦截(管理员可能在境外), 仅记录
if (!admin_logged_in()) {
    if (function_exists('geo_blocked') && geo_enabled() && !geo_is_local(geo_client_ip())) {
        geo_log('ADMIN_LOGIN_FROM ' . geo_client_ip() . ' ' . (string) geo_region(geo_client_ip()));
    }
    ?><!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>后台 · 登录</title><link rel="stylesheet" href="/assets/style.css?v=5">
    <style>
    .login{max-width:340px;margin:16vh auto 0;text-align:center}
    .login h2{font-family:"Songti SC","Source Han Serif SC",STSong,SimSun,serif;
      font-size:28px;font-weight:500;margin:0 0 6px}
    .login p{margin:0 0 26px;font-size:12.5px;letter-spacing:.14em;color:var(--ink-3)}
    .login input{width:100%;height:44px;padding:0 2px;border:0;border-bottom:1px solid var(--ink);
      background:none;color:var(--ink);font-size:17px;outline:none;text-align:center;letter-spacing:.1em}
    .login input::placeholder{color:var(--ink-3);letter-spacing:.06em;font-size:14px}
    .login .btn{width:100%;height:44px;margin-top:26px}
    .login .err{color:var(--seal);font-size:12.5px;margin-top:12px;letter-spacing:.06em}
    </style></head><body>
    <div class="login"><h2>后台管理</h2><p class="sub">需要管理口令</p>
    <form method="post"><input type="password" name="pass" placeholder="管理口令" autofocus>
    <?php if ($err !== ''): ?><p class="err"><?= $esc($err) ?></p><?php endif; ?>
    <button class="btn primary" type="submit">进入</button></form></div></body></html><?php
    exit;
}

$pdo  = db();
$tab  = (string) ($_GET['tab'] ?? 'overview');
$TABS = [
    'overview' => '概览',
    'users'    => '用户',
    'visits'   => '访问日志',
    'requests' => '访客请求',
    'searches' => '检索记录',
    'sms'      => '短信记录',
    'limits'   => '限流封禁',
    'feedback' => '反馈',
    'quota'    => '提额',
    'dedupe'   => '去重审阅',
];
if (!isset($TABS[$tab])) {
    $tab = 'overview';
}
$page = max(0, (int) ($_GET['page'] ?? 0));
$LIM  = 30;

// 健康检查状态 (ops/health.php 每 5 分钟写入)
$health = ['at' => '', 'state' => '', 'detail' => ''];
try {
    $hs = $pdo->query("SELECT k, v FROM settings WHERE k LIKE 'health_last%'")->fetchAll();
    foreach ($hs as $row) {
        if ($row['k'] === 'health_last_at')     { $health['at'] = (string) $row['v']; }
        if ($row['k'] === 'health_last_state')  { $health['state'] = (string) $row['v']; }
        if ($row['k'] === 'health_last_detail') { $health['detail'] = (string) $row['v']; }
    }
} catch (Throwable $e) { /* 忽略 */ }

// 全局计数(各页签都要用到)
$G = [
    'questions' => (int) $pdo->query("SELECT COUNT(*) FROM questions")->fetchColumn(),
    'qreq'      => (int) $pdo->query("SELECT COUNT(*) FROM quota_requests WHERE status = 0")->fetchColumn(),
    'pending'   => (int) $pdo->query(
        "SELECT COUNT(*) FROM dedupe_pairs p
         JOIN questions qa ON qa.id = p.a_id
         JOIN questions qb ON qb.id = p.b_id
         LEFT JOIN dedupe_decisions d ON d.pair_key = p.pair_key
         WHERE d.id IS NULL")->fetchColumn(),
];

/** 分页条 */
function nav(string $tab, int $cur, int $pages, array $extra = []): string
{
    if ($pages <= 1) {
        return '';
    }
    $qs = $extra;
    $mk = static function (int $i, string $label, bool $on = false) use ($tab, $qs): string {
        $qs['tab']  = $tab;
        $qs['page'] = $i;
        $href = $BASE . '/?' . http_build_query($qs);
        return '<a class="pg' . ($on ? ' on' : '') . '" href="' . htmlspecialchars($href, ENT_QUOTES) . '">'
             . $label . '</a>';
    };
    $out = '<div class="pgnav">';
    if ($cur > 0) {
        $out .= $mk($cur - 1, '←');
    }
    $start = max(0, $cur - 3);
    $end   = min($pages, $start + 7);
    for ($i = $start; $i < $end; $i++) {
        $out .= $mk($i, (string) ($i + 1), $i === $cur);
    }
    if ($cur < $pages - 1) {
        $out .= $mk($cur + 1, '→');
    }
    return $out . '<span class="pgtot">共 ' . $pages . ' 页</span></div>';
}

/* ══════════ 各页数据 ══════════ */
$ov = [];
if ($tab === 'overview') {
    $one = static function (string $sql) use ($pdo): int {
        return (int) $pdo->query($sql)->fetchColumn();
    };
    $ov = [
        'users'        => $one("SELECT COUNT(*) FROM users"),
        'users_today'  => $one("SELECT COUNT(*) FROM users WHERE created_at >= CURDATE()"),
        'req_today'    => $one("SELECT COUNT(*) FROM request_log WHERE created_at >= CURDATE()"),
        'ips_today'    => $one("SELECT COUNT(DISTINCT ip) FROM request_log WHERE created_at >= CURDATE()"),
        'search_today' => $one("SELECT COUNT(*) FROM search_logs WHERE created_at >= CURDATE()"),
        'items_today'  => $one("SELECT COALESCE(SUM(n_items),0) FROM search_logs WHERE created_at >= CURDATE()"),
        'sms_today'    => $one("SELECT COUNT(*) FROM sms_codes WHERE created_at >= CURDATE()"),
        'pv_today'     => $one("SELECT COUNT(*) FROM access_logs WHERE created_at >= CURDATE()"),
        'uv_today'     => $one("SELECT COUNT(DISTINCT ip) FROM access_logs WHERE created_at >= CURDATE()"),
        'online'       => $one("SELECT COUNT(*) FROM auth_sessions WHERE expires > UNIX_TIMESTAMP()"),
        'questions'    => 0,
        'subjects'     => $one("SELECT COUNT(*) FROM subjects"),
        'dedupe'       => 0,
        'banned'       => $one("SELECT COUNT(*) FROM rate_limits WHERE blocked_until > UNIX_TIMESTAMP()"),
        'fb_pending'   => $one("SELECT COUNT(*) FROM feedback WHERE status = 0"),
        'fb_today'     => $one("SELECT COUNT(*) FROM feedback WHERE created_at >= CURDATE()"),
        'ad_clicks'    => $one("SELECT COUNT(*) FROM request_log WHERE action = 'ad_click' AND created_at >= CURDATE()"),
        'ad_clicks_7'  => $one("SELECT COUNT(*) FROM request_log WHERE action = 'ad_click' AND created_at >= CURDATE() - INTERVAL 6 DAY"),
    ];
    $ov['questions'] = $G['questions'];
    $ov['dedupe']    = $G['pending'];
    $topUsers = $pdo->query(
        "SELECT phone, searches_used, searches_limit, last_login
         FROM users ORDER BY searches_used DESC LIMIT 8"
    )->fetchAll();
    $newUsers = $pdo->query(
        "SELECT phone, created_at, inet6_ntoa(last_ip) ip
         FROM users ORDER BY id DESC LIMIT 8"
    )->fetchAll();
    $adStats = $pdo->query(
        "SELECT query AS target,
                SUM(created_at >= CURDATE()) AS today,
                COUNT(*) AS days7,
                MAX(created_at) AS last_at
         FROM request_log
         WHERE action = 'ad_click' AND created_at >= CURDATE() - INTERVAL 6 DAY
         GROUP BY query ORDER BY days7 DESC"
    )->fetchAll();
    $topQueries = $pdo->query(
        "SELECT query, COUNT(*) n FROM search_logs
         WHERE query <> '' GROUP BY query ORDER BY n DESC LIMIT 10"
    )->fetchAll();
}

$rows = [];
$total = 0;
if ($tab === 'visits') {
    $dev = (string) ($_GET['dev'] ?? '');
    $ipf = trim((string) ($_GET['ip'] ?? ''));
    $w = ['1=1'];
    $par = [];
    if ($dev !== '') { $w[] = 'device_type = ?'; $par[] = $dev; }
    if ($ipf !== '') { $w[] = 'ip = ?'; $par[] = $ipf; }
    $where = implode(' AND ', $w);
    $st = $pdo->prepare("SELECT COUNT(*) FROM access_logs WHERE $where");
    $st->execute($par);
    $total = (int) $st->fetchColumn();
    $st = $pdo->prepare(
        "SELECT id, ip, device_type, device_model, user_agent, created_at
         FROM access_logs WHERE $where ORDER BY id DESC LIMIT $LIM OFFSET " . ($page * $LIM)
    );
    $st->execute($par);
    $rows = $st->fetchAll();
    $devs = $pdo->query(
        "SELECT COALESCE(device_type,'未知') d, COUNT(*) n FROM access_logs
         GROUP BY d ORDER BY n DESC"
    )->fetchAll();
}

if ($tab === 'users') {
    $kw = trim((string) ($_GET['kw'] ?? ''));
    $where = '1=1';
    $par = [];
    if ($kw !== '') {
        $where = 'phone LIKE ?';
        $par[] = '%' . $kw . '%';
    }
    $st = $pdo->prepare("SELECT COUNT(*) FROM users WHERE $where");
    $st->execute($par);
    $total = (int) $st->fetchColumn();
    $st = $pdo->prepare(
        "SELECT id, phone, searches_used, searches_limit, status, created_at, last_login,
                inet6_ntoa(last_ip) ip
         FROM users WHERE $where ORDER BY id DESC LIMIT $LIM OFFSET " . ($page * $LIM)
    );
    $st->execute($par);
    $rows = $st->fetchAll();
}

if ($tab === 'requests') {
    $act = (string) ($_GET['act'] ?? '');
    $sc  = (string) ($_GET['sc'] ?? '');
    $ip  = trim((string) ($_GET['ip'] ?? ''));
    $w = ['1=1'];
    $par = [];
    if ($act !== '') { $w[] = 'action = ?'; $par[] = $act; }
    if ($sc !== '')  { $w[] = 'status = ?'; $par[] = (int) $sc; }
    if ($ip !== '')  { $w[] = 'ip = INET6_ATON(?)'; $par[] = $ip; }
    $where = implode(' AND ', $w);
    $st = $pdo->prepare("SELECT COUNT(*) FROM request_log WHERE $where");
    $st->execute($par);
    $total = (int) $st->fetchColumn();
    $st = $pdo->prepare(
        "SELECT id, inet6_ntoa(ip) ip, action, query, subject, qtype, page, status, ua, cost_ms, created_at
         FROM request_log WHERE $where ORDER BY id DESC LIMIT $LIM OFFSET " . ($page * $LIM)
    );
    $st->execute($par);
    $rows = $st->fetchAll();
    $acts = $pdo->query("SELECT action, COUNT(*) n FROM request_log GROUP BY action ORDER BY n DESC")
                ->fetchAll();
}

if ($tab === 'searches') {
    $st = $pdo->query("SELECT COUNT(*) FROM search_logs");
    $total = (int) $st->fetchColumn();
    $rows = $pdo->query(
        "SELECT s.id, s.user_id, s.action, s.query, s.n_items, s.created_at, u.phone
         FROM search_logs s LEFT JOIN users u ON u.id = s.user_id
         ORDER BY s.id DESC LIMIT $LIM OFFSET " . ($page * $LIM)
    )->fetchAll();
}

if ($tab === 'sms') {
    $st = $pdo->query("SELECT COUNT(*) FROM sms_codes");
    $total = (int) $st->fetchColumn();
    $rows = $pdo->query(
        "SELECT id, phone, inet6_ntoa(ip) ip, attempts, used, expires, created_at
         FROM sms_codes ORDER BY id DESC LIMIT $LIM OFFSET " . ($page * $LIM)
    )->fetchAll();
}

if ($tab === 'limits') {
    $rows = $pdo->query(
        "SELECT inet6_ntoa(ip) ip, win_count, burst_count, day_count, strikes,
                blocked_until, cleared_until, updated_at
         FROM rate_limits ORDER BY day_count DESC, win_count DESC LIMIT 200"
    )->fetchAll();
    $budget = $pdo->query(
        "SELECT inet6_ntoa(ip) ip, day, items, win_items, updated_at
         FROM serve_budget ORDER BY day DESC, items DESC LIMIT 100"
    )->fetchAll();
}

if ($tab === 'quota') {
    $qst = (string) ($_GET['st'] ?? '');
    $w = ['1=1'];
    $par = [];
    if ($qst !== '') { $w[] = 'r.status = ?'; $par[] = (int) $qst; }
    $where = implode(' AND ', $w);
    $st = $pdo->prepare("SELECT COUNT(*) FROM quota_requests r WHERE $where");
    $st->execute($par);
    $total = (int) $st->fetchColumn();
    $st = $pdo->prepare(
        "SELECT r.id, r.limit_snapshot, r.used_snapshot, r.reason, r.status,
                r.granted_limit, r.admin_note, r.created_at, r.decided_at,
                u.phone, u.searches_used, u.searches_limit
         FROM quota_requests r LEFT JOIN users u ON u.id = r.user_id
         WHERE $where ORDER BY r.status ASC, r.id DESC LIMIT $LIM OFFSET " . ($page * $LIM)
    );
    $st->execute($par);
    $rows = $st->fetchAll();
}

if ($tab === 'feedback') {
    $fkind = (string) ($_GET['kind'] ?? '');
    $fstat = (string) ($_GET['st'] ?? '');
    $w = ['1=1'];
    $par = [];
    if ($fkind !== '') { $w[] = 'f.kind = ?'; $par[] = $fkind; }
    if ($fstat !== '') { $w[] = 'f.status = ?'; $par[] = (int) $fstat; }
    $where = implode(' AND ', $w);
    $st = $pdo->prepare("SELECT COUNT(*) FROM feedback f WHERE $where");
    $st->execute($par);
    $total = (int) $st->fetchColumn();
    $st = $pdo->prepare(
        "SELECT f.id, f.kind, f.question_id, f.reason, f.content, f.contact,
                f.status, f.refunded, f.created_at, u.phone
         FROM feedback f LEFT JOIN users u ON u.id = f.user_id
         WHERE $where ORDER BY f.status ASC, f.id DESC LIMIT $LIM OFFSET " . ($page * $LIM)
    );
    $st->execute($par);
    $rows = $st->fetchAll();
    $fkinds = $pdo->query(
        "SELECT kind, COUNT(*) n FROM feedback GROUP BY kind"
    )->fetchAll();
}

/* ── 去重 ── */
$CATNAME = [
    'near_diff'      => '① 近同题·答案不同',
    'near_same'      => '② 近同题·答案相同',
    'prefix_only'    => '③ 仅科目前缀不同',
    'similar'        => '④ 内容相近',
    'legacy_overlap' => '⑤ 旧库重复(2026已有)',
];
$dcat = (string) ($_GET['cat'] ?? 'near_diff');
if (!isset($CATNAME[$dcat])) {
    $dcat = 'near_diff';
}
$dcnt = [];
$dpairs = [];
$dundec = 0;
$dtotal = 0;
if ($tab === 'dedupe') {
    $sql = "SELECT p.category, COUNT(*) AS total, SUM(d.id IS NULL) AS undecided
            FROM dedupe_pairs p
            JOIN questions qa ON qa.id = p.a_id
            JOIN questions qb ON qb.id = p.b_id
            LEFT JOIN dedupe_decisions d ON d.pair_key = p.pair_key
            GROUP BY p.category";
    foreach ($pdo->query($sql) as $r) {
        $dcnt[$r['category']] = ['total' => (int) $r['total'], 'undecided' => (int) $r['undecided']];
    }
    $DL = 10;
    $st = $pdo->prepare(
        "SELECT p.pair_key, p.a_id, p.b_id, p.j_full, p.j_strip, p.ans_same,
                qa.stem sa_stem, qa.answer sa_ans, qa.type sa_type, qa.source sa_src,
                qb.stem sb_stem, qb.answer sb_ans, qb.type sb_type, qb.source sb_src
         FROM dedupe_pairs p
         JOIN questions qa ON qa.id = p.a_id
         JOIN questions qb ON qb.id = p.b_id
         LEFT JOIN dedupe_decisions d ON d.pair_key = p.pair_key
         WHERE d.id IS NULL AND p.category = ?
         ORDER BY p.j_full DESC, p.id ASC
         LIMIT $DL OFFSET " . ($page * $DL)
    );
    $st->execute([$dcat]);
    $dpairs = $st->fetchAll();
    $dundec = $dcnt[$dcat]['undecided'] ?? 0;
    $dtotal = $dcnt[$dcat]['total'] ?? 0;
}
?><!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>后台管理</title>
<link rel="stylesheet" href="/assets/style.css?v=5">
<style>
.masthead{ position:sticky; top:0; z-index:60; background:var(--paper);
  border-bottom:1px solid var(--rule-soft); height:48px }
.masthead .shell{ display:flex; align-items:center; gap:20px; height:100% }
.mark{ display:flex; align-items:baseline; gap:7px; text-decoration:none;
  font-family:"Songti SC","Source Han Serif SC",STSong,SimSun,serif;
  font-weight:600; font-size:14.5px; color:var(--ink); letter-spacing:.02em }
.mark .dot{ color:var(--rule); font-weight:400 }
.mark em{ font-style:normal; font-size:9.5px; letter-spacing:.2em; color:var(--ink-3) }
.adm .nav{ margin-left:auto }
.fb-ok{ font-size:11.5px; letter-spacing:.04em; color:var(--ink-2);
  border:1px solid var(--rule); padding:2px 8px; margin-right:5px; transition:.2s var(--ease) }
.fb-ok:hover{ border-color:var(--ink); color:var(--ink) }
.q-ok{ font-size:11.5px; letter-spacing:.04em; color:var(--ink-2);
  border:1px solid var(--rule); padding:2px 8px; margin-right:5px; transition:.2s var(--ease) }
.q-ok:hover{ border-color:var(--ink); color:var(--ink) }
.qmodal{ position:fixed; inset:0; z-index:400; display:grid; place-items:center; padding:20px }
.qmodal[hidden]{ display:none }
.qmodal-mask{ position:absolute; inset:0; background:rgba(23,21,15,.42) }
.qmodal-card{ position:relative; width:min(760px,100%); max-height:88vh; display:flex;
  flex-direction:column; background:var(--paper); border:1px solid var(--ink);
  box-shadow:0 24px 60px -20px rgba(23,21,15,.35) }
.qmodal-head{ display:flex; align-items:center; gap:10px; padding:12px 16px;
  border-bottom:1px solid var(--rule); flex:0 0 auto }
.qmodal-head>span{ font-size:13px; letter-spacing:.1em }
.qmodal-close{ margin-left:auto; font-size:20px; line-height:1; color:var(--ink-3) }
.qmodal-close:hover{ color:var(--ink) }
.qmodal-body{ overflow-y:auto; padding:16px; flex:1 1 auto }
.qmodal-foot{ display:flex; align-items:center; gap:10px; padding:12px 16px;
  border-top:1px solid var(--rule); flex:0 0 auto; flex-wrap:wrap }
.qmodal-foot .qsave{ height:34px; padding:0 18px; background:var(--ink); color:var(--paper);
  font-size:12.5px; letter-spacing:.06em }
.qmodal-foot .qsave:hover{ background:var(--seal) }
.qmodal-foot .qsave:disabled{ opacity:.5 }
.qmodal-foot .qcancel{ height:34px; padding:0 16px; border:1px solid var(--rule);
  font-size:12.5px; color:var(--ink-2) }
.qmodal-foot .qdel{ height:34px; padding:0 14px; border:1px solid var(--seal);
  font-size:12.5px; color:var(--seal); letter-spacing:.04em }
.qmodal-foot .qdel:hover{ background:var(--seal); color:var(--paper) }
.qmodal-foot .qdel:disabled{ opacity:.5 }
.qm-stem-edit textarea{ width:100%; min-height:74px; padding:9px 10px; line-height:1.75;
  border:1px solid var(--rule); background:var(--paper-2); color:var(--ink);
  font:inherit; font-size:14px; outline:none; resize:vertical }
.qm-stem-edit textarea:focus{ border-color:var(--seal); background:var(--paper) }
.qm-opt-row{ display:flex; gap:7px; align-items:center; margin-bottom:6px }
.qm-opt-row input[data-ok]{ width:38px; flex:0 0 auto; text-align:center; height:32px;
  border:1px solid var(--rule); background:var(--paper-2); color:var(--seal);
  font:inherit; font-size:12.5px; font-weight:600; outline:none }
.qm-opt-row input[data-ot]{ flex:1; min-width:0; height:32px; padding:0 9px;
  border:1px solid var(--rule); background:var(--paper-2); color:var(--ink);
  font:inherit; font-size:12.5px; outline:none }
.qm-opt-row input:focus{ border-color:var(--seal); background:var(--paper) }
.qm-opt-del{ flex:0 0 auto; width:26px; height:32px; color:var(--ink-3); font-size:16px }
.qm-opt-del:hover{ color:var(--seal) }
.qm-opt-add{ font-size:11.5px; color:var(--ink-3); border:1px dashed var(--rule);
  padding:5px 12px; margin-top:4px }
.qm-opt-add:hover{ border-color:var(--ink-3); color:var(--ink-2) }
.qmsg{ font-size:12px; color:var(--seal) }
.qm-meta{ display:flex; gap:8px; align-items:center; flex-wrap:wrap;
  font-size:11px; color:var(--ink-3); margin-bottom:10px; letter-spacing:.06em }
.qm-stem{ font-family:"Songti SC","Source Han Serif SC",STSong,SimSun,serif;
  font-size:15.5px; line-height:1.75; color:var(--ink); margin:0 0 12px;
  white-space:pre-wrap; word-break:break-word }
.qm-opts{ list-style:none; margin:0 0 14px; padding:0; display:flex;
  flex-direction:column; gap:5px }
.qm-opts li{ display:flex; gap:8px; font-size:13px; line-height:1.7; color:var(--ink-2) }
.qm-opts .k{ flex:0 0 auto; color:var(--seal); font-weight:600;
  font-variant-numeric:tabular-nums }
.qm-lab{ font-size:10.5px; letter-spacing:.14em; color:var(--ink-3);
  margin:16px 0 7px; padding-top:12px; border-top:1px solid var(--rule-soft) }
.qm-ans-row{ display:flex; gap:8px; align-items:center; flex-wrap:wrap }
.qm-ans-row input,.qm-ans-row textarea{
  flex:1 1 220px; min-width:0; height:36px; padding:0 10px; border:1px solid var(--rule);
  background:var(--paper-2); color:var(--ink); font:inherit; font-size:13px; outline:none }
.qm-ans-row textarea{ height:auto; min-height:76px; padding:9px 10px; line-height:1.7;
  resize:vertical }
.qm-ans-row input:focus,.qm-ans-row textarea:focus{ border-color:var(--seal); background:var(--paper) }
.qm-chips{ display:flex; gap:6px; flex-wrap:wrap; margin-bottom:9px }
.qm-chip{ font-size:11.5px; padding:3px 10px; border:1px solid var(--rule);
  color:var(--ink-3); transition:.2s var(--ease) }
.qm-chip:hover{ border-color:var(--ink-3); color:var(--ink-2) }
.qm-chip.on{ border-color:var(--seal); color:var(--seal) }
.qm-old{ font-size:12.5px; color:var(--ink-2) }
.qm-old b{ color:var(--seal); font-weight:600 }
.qm-fb{ margin-top:16px; padding:11px 12px; background:var(--paper-2);
  border-left:2px solid var(--seal); font-size:12px; line-height:1.8; color:var(--ink-2) }
.qm-fb .h{ color:var(--ink-3); font-size:10.5px; letter-spacing:.12em; margin-bottom:5px }
.healthbar{ display:flex; align-items:baseline; gap:10px; flex-wrap:wrap;
  margin:18px 0 0; padding:9px 12px; border-left:2px solid var(--rule);
  background:var(--paper-2); font-size:12px; color:var(--ink-2) }
.healthbar.ok{ border-left-color:var(--ok) }
.healthbar.bad{ border-left-color:var(--seal); background:var(--seal-wash) }
.healthbar .lbl{ font-size:10.5px; letter-spacing:.14em; color:var(--ink-3) }
.healthbar.bad .lbl{ color:var(--seal) }
.healthbar .at{ margin-left:auto; color:var(--ink-3); font-size:11px;
  font-variant-numeric:tabular-nums }
.adm{padding:8px 0 80px}
.adm-head{display:flex;align-items:baseline;justify-content:space-between;gap:16px;
  flex-wrap:wrap;margin:34px 0 4px}
.adm-head h1{font-family:"Songti SC","Source Han Serif SC",STSong,SimSun,serif;
  font-size:clamp(22px,2.6vw,30px);font-weight:500;margin:0;letter-spacing:-.01em}
.adm-head .sub{color:var(--ink-3);font-size:12px;letter-spacing:.1em}
.tabs{display:flex;gap:20px;flex-wrap:wrap;margin:22px 0 0;
  padding-bottom:11px;border-bottom:1px solid var(--rule)}
.tabs a{font-size:13px;color:var(--ink-3);padding:3px 0;border-bottom:1px solid transparent;
  transition:.2s var(--ease)}
.tabs a.on{color:var(--ink);border-bottom-color:var(--seal)}
.tabs a b{color:var(--seal);margin-left:4px;font-weight:600;font-variant-numeric:tabular-nums}

.cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(148px,1fr));
  gap:1px;background:var(--rule);border:1px solid var(--rule);margin:26px 0 0}
.card{background:var(--paper);padding:15px 14px}
.card .n{font-size:24px;font-weight:600;font-variant-numeric:tabular-nums;line-height:1.15;
  letter-spacing:-.02em}
.card .l{color:var(--ink-3);font-size:11.5px;margin-top:5px;letter-spacing:.08em}
.card.seal .n{color:var(--seal)}

.sec{display:flex;align-items:baseline;justify-content:space-between;gap:12px;
  margin:34px 0 12px;flex-wrap:wrap}
.sec h2{font-family:"Songti SC","Source Han Serif SC",STSong,SimSun,serif;
  font-size:16px;font-weight:600;margin:0}
.sec .meta{color:var(--ink-3);font-size:11.5px}

table{width:100%;border-collapse:collapse;font-size:12.5px}
th,td{text-align:left;padding:8px 10px;border-bottom:1px solid var(--rule-soft);
  vertical-align:top}
th{color:var(--ink-3);font-weight:500;font-size:11px;letter-spacing:.1em;
  white-space:nowrap;border-bottom-color:var(--rule)}
td{color:var(--ink-2)}
td.num{font-variant-numeric:tabular-nums;white-space:nowrap}
td.mono{font-variant-numeric:tabular-nums;white-space:nowrap;font-size:12px}
.tw{overflow-x:auto;-webkit-overflow-scrolling:touch}
.tag{display:inline-block;padding:1px 6px;border:1px solid var(--rule);font-size:10.5px;
  letter-spacing:.06em;color:var(--ink-3);white-space:nowrap}
.tag.ok{border-color:rgba(46,125,84,.45);color:#2E7D54}
.tag.bad{border-color:rgba(176,58,46,.45);color:var(--seal)}
.q{max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.uamax{max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
  color:var(--ink-3);font-size:11px}

.filt{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:18px 0 6px}
.filt input,.filt select{height:32px;padding:0 9px;border:1px solid var(--rule);
  background:var(--paper-2);color:var(--ink);font-size:12.5px;outline:none}
.filt input:focus,.filt select:focus{border-color:var(--seal);background:var(--paper)}
.filt button{height:32px;padding:0 14px;border:1px solid var(--ink);background:none;
  color:var(--ink);font-size:12.5px;letter-spacing:.06em}
.filt button:hover{background:var(--ink);color:var(--paper)}
.pgnav{display:flex;gap:6px;align-items:center;margin:18px 0 0;flex-wrap:wrap}
.pgnav a.pg{min-width:28px;height:28px;display:inline-flex;align-items:center;
  justify-content:center;border:1px solid var(--rule);font-size:12px;color:var(--ink-3);
  text-decoration:none;padding:0 6px}
.pgnav a.pg:hover{border-color:var(--ink);color:var(--ink)}
.pgnav a.pg.on{border-color:var(--seal);color:var(--seal)}
.pgtot{color:var(--ink-3);font-size:11.5px;margin-left:6px}
.empty{color:var(--ink-3);padding:40px 0;text-align:center;font-size:13px}

.cats{display:flex;gap:10px 22px;flex-wrap:wrap;margin:20px 0 8px;
  padding-bottom:11px;border-bottom:1px solid var(--rule)}
.cats a{font-size:12.5px;color:var(--ink-3);padding:2px 0;
  border-bottom:1px solid transparent;text-decoration:none}
.cats a.on{color:var(--ink);border-bottom-color:var(--seal)}
.cats a b{color:var(--seal);margin-left:4px;font-weight:600;font-variant-numeric:tabular-nums}
.pair{border-bottom:1px solid var(--rule-soft);padding:22px 0}
.pair .head{display:flex;justify-content:space-between;align-items:baseline;gap:10px;
  flex-wrap:wrap;margin-bottom:12px;color:var(--ink-3);font-size:11px;letter-spacing:.1em}
.pair .sim{color:var(--seal);font-weight:700;letter-spacing:0}
.pcols{display:grid;grid-template-columns:1fr 1fr;gap:clamp(14px,3vw,30px)}
.pcol .id{color:var(--ink-3);font-size:10.5px;letter-spacing:.14em;margin-bottom:8px}
.pcol .st{font-family:"Songti SC","Source Han Serif SC",STSong,SimSun,serif;
  font-size:15px;line-height:1.7;color:var(--ink)}
.pcol .an{margin-top:9px;font-size:12.5px;color:var(--seal);font-weight:600}
.pcol .meta{color:var(--ink-3);font-size:11px;margin-top:5px}
.pacts{display:flex;gap:8px;margin-top:14px;flex-wrap:wrap}
.pacts button{height:31px;padding:0 14px;border:1px solid var(--rule);background:none;
  color:var(--ink-2);font-size:12px;letter-spacing:.06em}
.pacts button:hover{border-color:var(--ink);color:var(--ink)}
.pacts button.todel{border-color:var(--seal);color:var(--seal)}
.pacts button.todel:hover{background:var(--seal);color:var(--paper)}
.pbar{height:1px;background:var(--rule);overflow:hidden;margin:6px 0 12px}
.pbar i{display:block;height:2px;background:var(--seal);position:relative;top:-.5px}
@media(max-width:720px){
  .pcols{grid-template-columns:1fr;gap:16px}
  .cards{grid-template-columns:repeat(auto-fill,minmax(120px,1fr))}
}
</style>
</head>
<body>
<header class="masthead"><div class="shell">
  <a class="mark" href="<?= $BASE ?>/"><span>创高题库</span><span class="dot">·</span><em>后台</em></a>
  <nav class="nav">
    <a class="nav-link" href="/">前台</a>
    <a class="nav-link" href="<?= $BASE ?>/?logout=1">退出</a>
  </nav>
</div></header>

<main class="shell adm">
  <div class="adm-head">
    <h1>站点管理</h1>
    <span class="sub">题库 <?= number_format($G['questions']) ?> 题</span>
  </div>

  <?php if ($health['at'] !== ''): ?>
    <div class="healthbar <?= $health['state'] === 'bad' ? 'bad' : 'ok' ?>">
      <span class="lbl"><?= $health['state'] === 'bad' ? '异常' : '正常' ?></span>
      <span><?= $esc($health['detail']) ?></span>
      <span class="at">检查于 <?= $esc($health['at']) ?></span>
    </div>
  <?php else: ?>
    <div class="healthbar">
      <span class="lbl">未检查</span>
      <span>健康检查尚未运行（cron 每 5 分钟一次）</span>
    </div>
  <?php endif; ?>

  <nav class="tabs">
    <?php foreach ($TABS as $k => $label): ?>
      <a class="<?= $k === $tab ? 'on' : '' ?>" href="<?= $BASE ?>/?tab=<?= $k ?>">
        <?= $esc($label) ?><?php
          if ($k === 'dedupe' && $G['pending'] > 0) {
              echo '<b>' . (int) $G['pending'] . '</b>';
          }
          if ($k === 'quota' && $G['qreq'] > 0) {
              echo '<b>' . (int) $G['qreq'] . '</b>';
          }
        ?>
      </a>
    <?php endforeach; ?>
  </nav>

<?php if ($tab === 'overview'): ?>

  <div class="cards">
    <div class="card"><div class="n"><?= number_format($ov['users']) ?></div><div class="l">注册用户</div></div>
    <div class="card"><div class="n"><?= number_format($ov['users_today']) ?></div><div class="l">今日新增</div></div>
    <div class="card"><div class="n"><?= number_format($ov['online']) ?></div><div class="l">在线会话</div></div>
    <div class="card"><div class="n"><?= number_format($ov['pv_today']) ?></div><div class="l">今日页面浏览</div></div>
    <div class="card"><div class="n"><?= number_format($ov['uv_today']) ?></div><div class="l">今日访客 IP</div></div>
    <div class="card"><div class="n"><?= number_format($ov['req_today']) ?></div><div class="l">今日接口请求</div></div>
    <div class="card"><div class="n"><?= number_format($ov['ips_today']) ?></div><div class="l">今日接口 IP</div></div>
    <div class="card"><div class="n"><?= number_format($ov['search_today']) ?></div><div class="l">今日检索次数</div></div>
    <div class="card"><div class="n"><?= number_format($ov['items_today']) ?></div><div class="l">今日返回题数</div></div>
    <div class="card"><div class="n"><?= number_format($ov['sms_today']) ?></div><div class="l">今日短信</div></div>
    <div class="card<?= $ov['banned'] > 0 ? ' seal' : '' ?>"><div class="n"><?= number_format($ov['banned']) ?></div><div class="l">当前封禁</div></div>
    <div class="card<?= $ov['dedupe'] > 0 ? ' seal' : '' ?>"><div class="n"><?= number_format($ov['dedupe']) ?></div><div class="l">待审重复</div></div>
    <div class="card<?= $ov['fb_pending'] > 0 ? ' seal' : '' ?>"><div class="n"><?= number_format($ov['fb_pending']) ?></div><div class="l">待处理反馈</div></div>
    <div class="card"><div class="n"><?= number_format($ov['fb_today']) ?></div><div class="l">今日反馈</div></div>
    <div class="card"><div class="n"><?= number_format($ov['ad_clicks']) ?></div><div class="l">今日广告点击</div></div>
    <div class="card"><div class="n"><?= number_format($ov['ad_clicks_7']) ?></div><div class="l">近 7 天广告点击</div></div>
    <div class="card<?= $G['qreq'] > 0 ? ' seal' : '' ?>"><div class="n"><?= number_format($G['qreq']) ?></div><div class="l">待审提额</div></div>
  </div>

  <div class="sec"><h2>用量最高的用户</h2><span class="meta">按已用次数排序</span></div>
  <div class="tw"><table>
    <tr><th>手机号</th><th>已用</th><th>额度</th><th>使用率</th><th>最后登录</th></tr>
    <?php foreach ($topUsers as $u):
      $pct = $u['searches_limit'] > 0 ? round($u['searches_used'] / $u['searches_limit'] * 100) : 0; ?>
      <tr>
        <td class="mono"><?= $esc($u['phone']) ?></td>
        <td class="num"><?= (int) $u['searches_used'] ?></td>
        <td class="num"><?= (int) $u['searches_limit'] ?></td>
        <td class="num"><?= $pct ?>%</td>
        <td class="num"><?= $esc($u['last_login'] ?? '—') ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$topUsers): ?><tr><td colspan="5" class="empty">暂无数据</td></tr><?php endif; ?>
  </table></div>

  <div class="sec"><h2>最近注册</h2></div>
  <div class="tw"><table>
    <tr><th>手机号</th><th>注册时间</th><th>注册 IP</th></tr>
    <?php foreach ($newUsers as $u): ?>
      <tr>
        <td class="mono"><?= $esc($u['phone']) ?></td>
        <td class="num"><?= $esc($u['created_at']) ?></td>
        <td class="mono"><?= $esc($u['ip'] ?? '—') ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$newUsers): ?><tr><td colspan="3" class="empty">暂无数据</td></tr><?php endif; ?>
  </table></div>

  <div class="sec"><h2>广告点击</h2><span class="meta">按广告位拆分，近 7 天</span></div>
  <div class="tw"><table>
    <tr><th>广告位</th><th>今日点击</th><th>近 7 天</th><th>最近点击</th></tr>
    <?php foreach ($adStats as $a): ?>
      <tr>
        <td><?= $esc($a['target']) ?></td>
        <td class="num"><?= (int) $a['today'] ?></td>
        <td class="num"><?= (int) $a['days7'] ?></td>
        <td class="num"><?= $esc((string) $a['last_at']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$adStats): ?><tr><td colspan="4" class="empty">暂无广告点击</td></tr><?php endif; ?>
  </table></div>

  <div class="sec"><h2>高频检索词</h2></div>
  <div class="tw"><table>
    <tr><th>关键词</th><th>次数</th></tr>
    <?php foreach ($topQueries as $q): ?>
      <tr><td><?= $esc($q['query']) ?></td><td class="num"><?= (int) $q['n'] ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$topQueries): ?><tr><td colspan="2" class="empty">暂无数据</td></tr><?php endif; ?>
  </table></div>

<?php elseif ($tab === 'visits'): ?>

  <form class="filt" method="get">
    <input type="hidden" name="tab" value="visits">
    <select name="dev">
      <option value="">全部设备</option>
      <?php foreach ($devs as $d): ?>
        <option value="<?= $esc($d['d']) ?>" <?= (($_GET['dev'] ?? '') === $d['d']) ? 'selected' : '' ?>>
          <?= $esc($d['d']) ?> (<?= (int) $d['n'] ?>)
        </option>
      <?php endforeach; ?>
    </select>
    <input type="text" name="ip" placeholder="按 IP 精确筛选" value="<?= $esc($_GET['ip'] ?? '') ?>">
    <button type="submit">筛选</button>
    <span class="meta">共 <?= number_format($total) ?> 条</span>
  </form>
  <div class="tw"><table>
    <tr><th>时间</th><th>IP</th><th>设备</th><th>型号</th><th>User-Agent</th></tr>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td class="num"><?= $esc($r['created_at']) ?></td>
        <td class="mono"><?= $esc($r['ip'] ?? '—') ?></td>
        <td><?= $esc($r['device_type'] ?? '—') ?></td>
        <td class="uamax" title="<?= $esc($r['device_model']) ?>"><?= $esc(mb_substr((string) $r['device_model'], 0, 24)) ?></td>
        <td class="uamax" title="<?= $esc($r['user_agent']) ?>"><?= $esc(mb_substr((string) $r['user_agent'], 0, 46)) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="5" class="empty">没有匹配的记录</td></tr><?php endif; ?>
  </table></div>
  <?= nav('visits', $page, (int) ceil($total / $LIM),
          ['dev' => $_GET['dev'] ?? '', 'ip' => $_GET['ip'] ?? '']) ?>

<?php elseif ($tab === 'users'): ?>

  <form class="filt" method="get">
    <input type="hidden" name="tab" value="users">
    <input type="text" name="kw" placeholder="按手机号搜索" value="<?= $esc($_GET['kw'] ?? '') ?>">
    <button type="submit">筛选</button>
    <span class="meta">共 <?= number_format($total) ?> 位用户</span>
  </form>
  <div class="tw"><table>
    <tr><th>ID</th><th>手机号</th><th>已用 / 额度</th><th>状态</th>
        <th>注册时间</th><th>最后登录</th><th>最后 IP</th></tr>
    <?php foreach ($rows as $u): ?>
      <tr>
        <td class="num"><?= (int) $u['id'] ?></td>
        <td class="mono"><?= $esc($u['phone']) ?></td>
        <td class="num"><?= (int) $u['searches_used'] ?> / <?= (int) $u['searches_limit'] ?></td>
        <td><?= (int) $u['status'] === 1 ? '<span class="tag ok">正常</span>' : '<span class="tag bad">停用</span>' ?></td>
        <td class="num"><?= $esc($u['created_at']) ?></td>
        <td class="num"><?= $esc($u['last_login'] ?? '—') ?></td>
        <td class="mono"><?= $esc($u['ip'] ?? '—') ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="7" class="empty">没有匹配的用户</td></tr><?php endif; ?>
  </table></div>
  <?= nav('users', $page, (int) ceil($total / $LIM), ['kw' => $_GET['kw'] ?? '']) ?>

<?php elseif ($tab === 'requests'): ?>

  <form class="filt" method="get">
    <input type="hidden" name="tab" value="requests">
    <select name="act">
      <option value="">全部 action</option>
      <?php foreach ($acts as $a): ?>
        <option value="<?= $esc($a['action']) ?>" <?= (($_GET['act'] ?? '') === $a['action']) ? 'selected' : '' ?>>
          <?= $esc($a['action']) ?> (<?= (int) $a['n'] ?>)
        </option>
      <?php endforeach; ?>
    </select>
    <select name="sc">
      <option value="">全部状态</option>
      <?php foreach ([200, 400, 401, 403, 404, 429, 500] as $s): ?>
        <option value="<?= $s ?>" <?= (($_GET['sc'] ?? '') === (string) $s) ? 'selected' : '' ?>><?= $s ?></option>
      <?php endforeach; ?>
    </select>
    <input type="text" name="ip" placeholder="按 IP 精确筛选" value="<?= $esc($_GET['ip'] ?? '') ?>">
    <button type="submit">筛选</button>
    <span class="meta">共 <?= number_format($total) ?> 条</span>
  </form>
  <div class="tw"><table>
    <tr><th>时间</th><th>IP</th><th>动作</th><th>关键词</th><th>状态</th><th>耗时</th><th>UA</th></tr>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td class="num"><?= $esc($r['created_at']) ?></td>
        <td class="mono"><?= $esc($r['ip'] ?? '—') ?></td>
        <td><?= $esc($r['action']) ?></td>
        <td class="q" title="<?= $esc($r['query']) ?>"><?= $esc(mb_substr((string) $r['query'], 0, 30)) ?></td>
        <td><span class="tag <?= (int) $r['status'] < 400 ? 'ok' : 'bad' ?>"><?= (int) $r['status'] ?></span></td>
        <td class="num"><?= (int) $r['cost_ms'] ?>ms</td>
        <td class="uamax" title="<?= $esc($r['ua']) ?>"><?= $esc(mb_substr((string) $r['ua'], 0, 40)) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="7" class="empty">没有匹配的记录</td></tr><?php endif; ?>
  </table></div>
  <?= nav('requests', $page, (int) ceil($total / $LIM),
          ['act' => $_GET['act'] ?? '', 'sc' => $_GET['sc'] ?? '', 'ip' => $_GET['ip'] ?? '']) ?>

<?php elseif ($tab === 'searches'): ?>

  <div class="sec"><h2>检索记录</h2><span class="meta">共 <?= number_format($total) ?> 条</span></div>
  <div class="tw"><table>
    <tr><th>时间</th><th>用户</th><th>动作</th><th>关键词</th><th>返回题数</th></tr>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td class="num"><?= $esc($r['created_at']) ?></td>
        <td class="mono"><?= $esc($r['phone'] ?? ('#' . $r['user_id'])) ?></td>
        <td><?= $esc($r['action']) ?></td>
        <td class="q" title="<?= $esc($r['query']) ?>"><?= $esc(mb_substr((string) $r['query'], 0, 30)) ?></td>
        <td class="num"><?= (int) $r['n_items'] ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="5" class="empty">暂无记录</td></tr><?php endif; ?>
  </table></div>
  <?= nav('searches', $page, (int) ceil($total / $LIM)) ?>

<?php elseif ($tab === 'sms'): ?>

  <div class="sec"><h2>短信发送记录</h2><span class="meta">共 <?= number_format($total) ?> 条</span></div>
  <div class="tw"><table>
    <tr><th>时间</th><th>手机号</th><th>IP</th><th>校验次数</th><th>已使用</th><th>过期时间</th></tr>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td class="num"><?= $esc($r['created_at']) ?></td>
        <td class="mono"><?= $esc($r['phone']) ?></td>
        <td class="mono"><?= $esc($r['ip'] ?? '—') ?></td>
        <td class="num"><?= (int) $r['attempts'] ?></td>
        <td><?= (int) $r['used'] === 1 ? '<span class="tag ok">是</span>' : '<span class="tag">否</span>' ?></td>
        <td class="num"><?= date('Y-m-d H:i:s', (int) $r['expires']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="6" class="empty">暂无记录</td></tr><?php endif; ?>
  </table></div>
  <?= nav('sms', $page, (int) ceil($total / $LIM)) ?>

<?php elseif ($tab === 'limits'): ?>

  <div class="sec"><h2>限流状态</h2><span class="meta">按日请求量排序</span></div>
  <div class="tw"><table>
    <tr><th>IP</th><th>日请求</th><th>窗口请求</th><th>突发</th><th>违规</th>
        <th>封禁至</th><th>验证豁免至</th><th>更新时间</th></tr>
    <?php foreach ($rows as $r): $blk = (int) $r['blocked_until']; $clr = (int) $r['cleared_until']; ?>
      <tr>
        <td class="mono"><?= $esc($r['ip'] ?? '—') ?></td>
        <td class="num"><?= (int) $r['day_count'] ?></td>
        <td class="num"><?= (int) $r['win_count'] ?></td>
        <td class="num"><?= (int) $r['burst_count'] ?></td>
        <td class="num"><?= (int) $r['strikes'] ?></td>
        <td class="num"><?= $blk > time() ? '<span class="tag bad">' . date('H:i:s', $blk) . '</span>' : '—' ?></td>
        <td class="num"><?= $clr > time() ? date('H:i:s', $clr) : '—' ?></td>
        <td class="num"><?= $esc($r['updated_at']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="8" class="empty">暂无数据</td></tr><?php endif; ?>
  </table></div>

  <div class="sec"><h2>条数额度</h2><span class="meta">serve_budget，按 IP + 自然日</span></div>
  <div class="tw"><table>
    <tr><th>IP</th><th>日期</th><th>当日累计题数</th><th>窗口题数</th><th>更新时间</th></tr>
    <?php foreach ($budget as $r): ?>
      <tr>
        <td class="mono"><?= $esc($r['ip'] ?? '—') ?></td>
        <td class="num"><?= $esc($r['day']) ?></td>
        <td class="num"><?= (int) $r['items'] ?></td>
        <td class="num"><?= (int) $r['win_items'] ?></td>
        <td class="num"><?= $esc($r['updated_at']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$budget): ?><tr><td colspan="5" class="empty">暂无数据</td></tr><?php endif; ?>
  </table></div>

<?php elseif ($tab === 'quota'): ?>

  <form class="filt" method="get">
    <input type="hidden" name="tab" value="quota">
    <select name="st">
      <option value="">全部状态</option>
      <option value="0" <?= (($_GET['st'] ?? '') === '0') ? 'selected' : '' ?>>待审核</option>
      <option value="1" <?= (($_GET['st'] ?? '') === '1') ? 'selected' : '' ?>>已通过</option>
      <option value="2" <?= (($_GET['st'] ?? '') === '2') ? 'selected' : '' ?>>已拒绝</option>
    </select>
    <button type="submit">筛选</button>
    <span class="meta">共 <?= number_format($total) ?> 条</span>
  </form>
  <div class="tw"><table>
    <tr><th>时间</th><th>用户</th><th>额度(当时/已用)</th><th>理由</th>
        <th>状态</th><th>操作</th></tr>
    <?php foreach ($rows as $r): $st = (int) $r['status']; ?>
      <tr>
        <td class="num"><?= $esc($r['created_at']) ?></td>
        <td class="mono"><?= $esc($r['phone'] ?? '—') ?></td>
        <td class="num"><?= (int) $r['limit_snapshot'] ?> / <?= (int) $r['used_snapshot'] ?>
          <?php if ($r['searches_limit'] !== null): ?>
            <span style="color:var(--ink-3)">（现 <?= (int) $r['searches_limit'] ?>）</span>
          <?php endif; ?>
        </td>
        <td style="max-width:340px;white-space:pre-wrap"><?= $esc($r['reason']) ?></td>
        <td>
          <?= $st === 0 ? '<span class="tag bad">待审核</span>'
             : ($st === 1 ? '<span class="tag ok">已通过 → ' . (int) $r['granted_limit'] . '</span>'
             : '<span class="tag">已拒绝</span>') ?>
          <?php if ($r['admin_note'] !== ''): ?>
            <div style="color:var(--ink-3);font-size:11px;margin-top:3px"><?= $esc($r['admin_note']) ?></div>
          <?php endif; ?>
        </td>
        <td class="num">
          <?php if ($st === 0): ?>
            <button class="q-ok" data-id="<?= (int) $r['id'] ?>" data-act="approve"
                    data-cur="<?= (int) $r['limit_snapshot'] ?>" type="button">通过</button>
            <button class="q-ok" data-id="<?= (int) $r['id'] ?>" data-act="reject" type="button">拒绝</button>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="6" class="empty">暂无申请</td></tr><?php endif; ?>
  </table></div>
  <?= nav('quota', $page, (int) ceil($total / $LIM), ['st' => $_GET['st'] ?? '']) ?>

<?php elseif ($tab === 'feedback'): ?>

  <form class="filt" method="get">
    <input type="hidden" name="tab" value="feedback">
    <select name="kind">
      <option value="">全部类型</option>
      <?php foreach ($fkinds as $k): ?>
        <option value="<?= $esc($k['kind']) ?>" <?= (($_GET['kind'] ?? '') === $k['kind']) ? 'selected' : '' ?>>
          <?= $esc(['wrong'=>'错题申报','bug'=>'Bug 反馈','like'=>'点赞','comment'=>'评论'][$k['kind']] ?? $k['kind']) ?>
          (<?= (int) $k['n'] ?>)
        </option>
      <?php endforeach; ?>
    </select>
    <select name="st">
      <option value="">全部状态</option>
      <option value="0" <?= (($_GET['st'] ?? '') === '0') ? 'selected' : '' ?>>待处理 / 未答谢</option>
      <option value="1" <?= (($_GET['st'] ?? '') === '1') ? 'selected' : '' ?>>已处理 / 已答谢</option>
      <option value="2" <?= (($_GET['st'] ?? '') === '2') ? 'selected' : '' ?>>已忽略 / 不管</option>
    </select>
    <button type="submit">筛选</button>
    <span class="meta">共 <?= number_format($total) ?> 条</span>
  </form>
  <div class="tw"><table>
    <tr><th>时间</th><th>类型</th><th>用户</th><th>题目</th><th>问题/内容</th>
        <th>联系方式</th><th>状态</th><th>操作</th></tr>
    <?php foreach ($rows as $r):
      $kn = ['wrong'=>'错题申报','bug'=>'Bug','like'=>'点赞','comment'=>'评论'][$r['kind']] ?? $r['kind']; ?>
      <tr>
        <td class="num"><?= $esc($r['created_at']) ?></td>
        <td><?= $esc($kn) ?></td>
        <td class="mono"><?= $esc($r['phone'] ?? '—') ?></td>
        <td class="num"><?= $r['question_id'] ? '#' . (int) $r['question_id'] : '—' ?></td>
        <td style="max-width:320px">
          <?php if ($r['reason'] !== ''): ?><span class="tag bad"><?= $esc($r['reason']) ?></span> <?php endif; ?>
          <span style="color:var(--ink-2)"><?= $esc(mb_substr((string) $r['content'], 0, 80)) ?></span>
        </td>
        <td class="mono"><?= $esc($r['contact']) ?></td>
        <td>
          <?php
            $isLike = ($r['kind'] === 'like');
            $st = (int) $r['status'];
            if ($isLike) {
                echo $st === 0 ? '<span class="tag">未答谢</span>'
                   : ($st === 1 ? '<span class="tag ok">已答谢</span>' : '<span class="tag">不管</span>');
            } else {
                echo $st === 0 ? '<span class="tag bad">待处理</span>'
                   : ($st === 1 ? '<span class="tag ok">已处理</span>' : '<span class="tag">已忽略</span>');
                if ((int) ($r['refunded'] ?? 0) === 1) {
                    echo ' <span class="tag">已返还</span>';
                }
            }
          ?>
        </td>
        <td class="num">
          <?php
            $isWrong = ($r['kind'] === 'wrong' && (int) $r['question_id'] > 0);
            $isLike  = ($r['kind'] === 'like');
            $st      = (int) $r['status'];
          ?>
          <?php if ($isLike): ?>
            <?php if ($st === 0): ?>
              <button class="fb-ok" data-id="<?= (int) $r['id'] ?>" data-st="1"
                      data-note="已答谢" type="button">答谢</button>
              <button class="fb-ok" data-id="<?= (int) $r['id'] ?>" data-st="2"
                      data-note="无需处理" type="button">不管</button>
            <?php else: ?>
              <button class="fb-ok" data-id="<?= (int) $r['id'] ?>" data-st="0"
                      data-note="" type="button">撤销</button>
            <?php endif; ?>
          <?php elseif ($st === 0): ?>
            <?php if ($isWrong): ?>
              <button class="fb-ok fb-fix" data-id="<?= (int) $r['id'] ?>"
                      data-qid="<?= (int) $r['question_id'] ?>" type="button">查看并改答案</button>
            <?php else: ?>
              <button class="fb-ok" data-id="<?= (int) $r['id'] ?>" data-st="1" type="button">处理</button>
            <?php endif; ?>
            <button class="fb-ok" data-id="<?= (int) $r['id'] ?>" data-st="2" type="button">忽略</button>
          <?php else: ?>
            <?php if ($isWrong): ?>
              <button class="fb-ok fb-fix" data-id="<?= (int) $r['id'] ?>"
                      data-qid="<?= (int) $r['question_id'] ?>" type="button">查看/改答案</button>
            <?php endif; ?>
            <button class="fb-ok" data-id="<?= (int) $r['id'] ?>" data-st="0" type="button">重开</button>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="8" class="empty">暂无反馈</td></tr><?php endif; ?>
  </table></div>
  <?= nav('feedback', $page, (int) ceil($total / $LIM),
          ['kind' => $_GET['kind'] ?? '', 'st' => $_GET['st'] ?? '']) ?>

<?php elseif ($tab === 'dedupe'): ?>

  <div class="sec"><h2>重复题审阅</h2>
    <span class="meta">按综合相似度从高到低；删除会把被删题的科目归属并入保留题</span></div>

  <div class="cats">
    <?php foreach ($CATNAME as $k => $label): $u = $dcnt[$k]['undecided'] ?? 0; ?>
      <a class="<?= $k === $dcat ? 'on' : '' ?>" href="<?= $BASE ?>/?tab=dedupe&cat=<?= $k ?>">
        <?= $esc($label) ?><?= $u > 0 ? "<b>$u</b>" : ' ✓' ?>
      </a>
    <?php endforeach; ?>
  </div>

  <div class="pbar"><i style="width:<?= $dtotal ? round((1 - $dundec / max(1, $dtotal)) * 100) : 100 ?>%"></i></div>
  <p class="meta" style="color:var(--ink-3);font-size:11.5px">
    当前分类：已处理 <?= $dtotal - $dundec ?> / <?= $dtotal ?>，待处理 <?= $dundec ?> 对
  </p>

  <?php if ($dcat === 'legacy_overlap'): ?>
    <div class="pair">
      <p class="meta" style="color:var(--ink-3);font-size:11.5px;margin:0 0 10px">
        旧库数据是 2019/2020 年扒的，答案不可靠；2026 已有对应题目时建议整批删除。
      </p>
      <div class="pacts">
        <button id="bulk-legacy" class="todel" type="button">删除全部「旧库重复」题（保留 2026 版）</button>
      </div>
    </div>
  <?php endif; ?>

  <?php if (!$dpairs): ?>
    <div class="empty">这个分类已经全部处理完了</div>
  <?php endif; ?>

  <?php foreach ($dpairs as $r): ?>
    <div class="pair" data-key="<?= $esc($r['pair_key']) ?>"
         data-a="<?= (int) $r['a_id'] ?>" data-b="<?= (int) $r['b_id'] ?>">
      <div class="head">
        <span>题干相似度 <span class="sim"><?= round((float) $r['j_full'] * 100, 1) ?>%</span>
          <?php if ((float) $r['j_strip'] > (float) $r['j_full'] + 0.02): ?>
            · 去科目前缀 <?= round((float) $r['j_strip'] * 100, 1) ?>%
          <?php endif; ?>
          · 答案<?= $r['ans_same'] ? '相同' : '<span style="color:var(--seal)">不同</span>' ?>
        </span>
        <span>#<?= (int) $r['a_id'] ?> vs #<?= (int) $r['b_id'] ?></span>
      </div>
      <div class="pcols">
        <div class="pcol">
          <div class="id">A · #<?= (int) $r['a_id'] ?> · <?= $esc($r['sa_type']) ?><?= $r['sa_src'] === 'legacy' ? ' · 旧库' : '' ?></div>
          <div class="st"><?= $esc($r['sa_stem']) ?></div>
          <div class="an">答案：<?= $esc($r['sa_ans']) ?></div>
        </div>
        <div class="pcol">
          <div class="id">B · #<?= (int) $r['b_id'] ?> · <?= $esc($r['sb_type']) ?><?= $r['sb_src'] === 'legacy' ? ' · 旧库' : '' ?></div>
          <div class="st"><?= $esc($r['sb_stem']) ?></div>
          <div class="an">答案：<?= $esc($r['sb_ans']) ?></div>
        </div>
      </div>
      <div class="pacts">
        <button type="button" data-act="keep_a">保留 A 删除 B</button>
        <button type="button" data-act="keep_b">保留 B 删除 A</button>
        <button type="button" data-act="both">两者都保留（不是重复）</button>
      </div>
    </div>
  <?php endforeach; ?>

  <?php if ($dpairs): ?>
    <div class="pgnav">
      <?php for ($i = 0; $i < min((int) ceil($dundec / 10), 12); $i++): ?>
        <a class="pg <?= $i === $page ? 'on' : '' ?>"
           href="<?= $BASE ?>/?tab=dedupe&cat=<?= $dcat ?>&page=<?= $i ?>"><?= $i + 1 ?></a>
      <?php endfor; ?>
      <span class="pgtot">共 <?= (int) ceil($dundec / 10) ?> 页</span>
    </div>
  <?php endif; ?>

<?php endif; ?>
</main>

<div class="qmodal" id="qModal" hidden>
  <div class="qmodal-mask" data-qclose="1"></div>
  <div class="qmodal-card" role="dialog" aria-modal="true" aria-labelledby="qmTitle">
    <div class="qmodal-head">
      <span id="qmTitle">题目详情</span>
      <button class="qmodal-close" type="button" data-qclose="1" aria-label="关闭">×</button>
    </div>
    <div class="qmodal-body" id="qmBody"><p class="empty">加载中…</p></div>
    <div class="qmodal-foot">
      <button class="qsave" id="qmSave" type="button">保存并标记已处理</button>
      <button class="qdel" id="qmDel" type="button">删除此题</button>
      <button class="qcancel" type="button" data-qclose="1">取消</button>
      <span class="qmsg" id="qmMsg"></span>
    </div>
  </div>
</div>

<div class="toast" id="toast" role="status" aria-live="polite"></div>
<script>
(function () {
  const toast = (m) => {
    const t = document.getElementById('toast');
    if (!t) return;
    t.textContent = m;
    t.classList.add('is-on');
    clearTimeout(window._tt);
    window._tt = setTimeout(() => t.classList.remove('is-on'), 2200);
  };
  document.querySelectorAll('.pair[data-key]').forEach((card) => {
    card.querySelectorAll('[data-act]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        btn.disabled = true;
        try {
          const fd = new FormData();
          fd.append('action', 'decide');
          fd.append('pair_key', card.dataset.key);
          fd.append('a', card.dataset.a);
          fd.append('b', card.dataset.b);
          fd.append('choice', btn.dataset.act);
          const r = await fetch('<?= $BASE ?>/api.php', { method: 'POST', body: fd, credentials: 'same-origin' });
          const j = await r.json();
          if (!j.ok) throw new Error(j.error || '失败');
          card.style.transition = 'opacity .2s';
          card.style.opacity = '0';
          setTimeout(() => {
            card.remove();
            if (!document.querySelector('.pair[data-key]')) location.reload();
          }, 200);
          toast(j.deleted ? ('已删除 #' + j.deleted) : '已标记保留');
        } catch (e) {
          toast(e.message);
          btn.disabled = false;
        }
      });
    });
  });

  /* ── 错题处理弹框 ── */
  var qm = document.getElementById('qModal');
  var qmBody = document.getElementById('qmBody');
  var qmSave = document.getElementById('qmSave');
  var qmMsg = document.getElementById('qmMsg');
  var QS = { qid: 0, fid: 0, type: '' };

  function escQ(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }
  function closeQ() { qm.hidden = true; }

  function renderQ(d) {
    var q = d.q, fb = d.fb;
    QS.qid = q.id; QS.fid = (fb && fb.id) ? fb.id : 0; QS.type = q.type;
    var opts = '';
    if (q.options && q.options.length) {
      opts = '<ul class="qm-opts">' + q.options.map(function (o) {
        return '<li><span class="k">' + escQ(o.key || '') + '</span><span>' +
          escQ(o.text || '') + '</span></li>';
      }).join('') + '</ul>';
    }
    var chips = '';
    if (q.type === '判断') {
      chips = ['正确', '错误'].map(function (v) {
        return '<button type="button" class="qm-chip" data-val="' + v + '">' + v + '</button>';
      }).join('');
    } else if (q.type === '单选' || q.type === '多选') {
      var keys = (q.options || []).map(function (o) { return o.key; }).filter(Boolean);
      if (!keys.length) keys = ['A', 'B', 'C', 'D'];
      chips = keys.map(function (k) {
        return '<button type="button" class="qm-chip" data-val="' + escQ(k) + '">' + escQ(k) + '</button>';
      }).join('');
    }
    var canEditOpts = (q.type === '单选' || q.type === '多选' || q.type === '问答');
    var long = (q.type === '填空' || q.type === '问答');
    var editor = long
      ? '<textarea id="qmAns">' + escQ(q.answer) + '</textarea>'
      : '<input id="qmAns" type="text" value="' + escQ(q.answer) + '">';

    var fbh = '';
    if (fb) {
      fbh = '<div class="qm-fb"><div class="h">申报信息</div>' +
        '用户：' + escQ(fb.phone || '—') + '　时间：' + escQ(fb.created_at) +
        (fb.reason ? '<br>问题类型：' + escQ(fb.reason) : '') +
        (fb.content ? '<br>补充：' + escQ(fb.content) : '') +
        (fb.contact ? '<br>联系方式：' + escQ(fb.contact) : '') +
        '</div>';
    }

    qmBody.innerHTML =
      '<div class="qm-meta"><span class="tag">' + escQ(q.type) + '</span>' +
        (q.subjects ? '<span>' + escQ(q.subjects) + '</span>' : '') +
        (q.source === 'legacy' ? '<span class="tag">旧库</span>' : '') +
        '<span>#' + q.id + '</span></div>' +
      '<div class="qm-lab">题干</div>' +
      '<div class="qm-stem-edit"><textarea id="qmStem">' + escQ(q.stem) + '</textarea></div>' +
      (canEditOpts ? '<div class="qm-lab">选项</div><div id="qmOpts"></div>' +
        '<button type="button" class="qm-opt-add" id="qmOptAdd">＋ 添加选项</button>' : '') +
      '<div class="qm-lab">当前答案</div>' +
      '<p class="qm-old" style="margin:0 0 10px"><b>' + escQ(q.answer) + '</b></p>' +
      '<div class="qm-lab">修改为</div>' +
      (chips ? '<div class="qm-chips">' + chips + '</div>' : '') +
      '<div class="qm-ans-row">' + editor + '</div>' +
      fbh;

    function syncChips() {
      var inp = document.getElementById('qmAns');
      if (!inp) return;
      var v = inp.value.toUpperCase();
      qmBody.querySelectorAll('.qm-chip').forEach(function (x) {
        var kv = x.dataset.val.toUpperCase();
        var on = QS.type === '多选' ? v.indexOf(kv) >= 0 : v === kv;
        x.classList.toggle('on', on);
      });
    }

    /* 选项编辑器 */
    if (canEditOpts) {
      var optBox = document.getElementById('qmOpts');
      var optsData = (q.options && q.options.length) ? q.options.slice() : [];
      var LETTERS = 'ABCDEFGHIJ';
      function addRow(k, t) {
        var row = document.createElement('div');
        row.className = 'qm-opt-row';
        row.innerHTML = '<input data-ok maxlength="1" value="' + escQ(k) + '">' +
                        '<input data-ot value="' + escQ(t) + '" placeholder="选项内容">' +
                        '<button type="button" class="qm-opt-del" title="删除该选项">×</button>';
        row.querySelector('.qm-opt-del').addEventListener('click', function () {
          row.remove();
        });
        optBox.appendChild(row);
      }
      optsData.forEach(function (o) { addRow(o.key || '', o.text || ''); });
      if (!optsData.length) { addRow('A', ''); addRow('B', ''); }
      var addBtn = document.getElementById('qmOptAdd');
      if (addBtn) addBtn.addEventListener('click', function () {
        var used = Array.prototype.map.call(
          optBox.querySelectorAll('input[data-ok]'),
          function (i) { return i.value.toUpperCase(); });
        var next = LETTERS.split('').filter(function (c) { return used.indexOf(c) < 0; })[0] || '';
        addRow(next, '');
        optBox.lastElementChild.querySelector('input[data-ot]').focus();
      });
      QS.hasOpts = true;
    } else {
      QS.hasOpts = false;
    }

    qmBody.querySelectorAll('.qm-chip').forEach(function (c) {
      c.addEventListener('click', function () {
        var inp = document.getElementById('qmAns');
        if (QS.type === '多选') {
          var cur = inp.value.toUpperCase().replace(/[^A-Z]/g, '');
          var v = c.dataset.val.toUpperCase();
          inp.value = cur.indexOf(v) >= 0
            ? cur.split('').filter(function (x) { return x !== v; }).join('')
            : (cur + v).split('').sort().join('');
        } else {
          inp.value = c.dataset.val;
        }
        syncChips();
      });
    });

    var ansInp = document.getElementById('qmAns');
    if (ansInp) {
      ansInp.addEventListener('input', syncChips);
      ansInp.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); qmSave.click(); }
      });
    }
    syncChips();
    qmMsg.textContent = '';
  }

  async function openQ(qid, fid) {
    QS.qid = qid; QS.fid = fid;
    qm.hidden = false;
    qmBody.innerHTML = '<p class="empty">加载中…</p>';
    qmSave.disabled = true;
    try {
      var fd = new FormData();
      fd.append('action', 'q_get');
      fd.append('id', qid);
      fd.append('fb_id', fid || 0);
      var r = await fetch('<?= $BASE ?>/api.php', { method: 'POST', body: fd, credentials: 'same-origin' });
      var j = await r.json();
      if (!j.ok) throw new Error(j.error || '加载失败');
      renderQ(j);
      qmSave.disabled = false;
    } catch (e) {
      qmBody.innerHTML = '<p class="empty">' + escQ(e.message) + '</p>';
    }
  }

  qmSave.addEventListener('click', async function () {
    var inp = document.getElementById('qmAns');
    if (!inp) return;
    var val = inp.value.trim();
    if (!val) { qmMsg.textContent = '答案不能为空'; return; }
    qmSave.disabled = true;
    qmMsg.textContent = '保存中…';
    try {
      var fd = new FormData();
      fd.append('action', 'q_save');
      fd.append('id', QS.qid);
      fd.append('answer', val);
      fd.append('fb_id', QS.fid || 0);
      var stemEl = document.getElementById('qmStem');
      if (stemEl) fd.append('stem', stemEl.value);
      if (QS.hasOpts) {
        var optArr = [];
        document.querySelectorAll('#qmOpts .qm-opt-row').forEach(function (row) {
          var k = row.querySelector('input[data-ok]').value.trim().toUpperCase();
          var t = row.querySelector('input[data-ot]').value.trim();
          if (k === '' && t === '') return;
          optArr.push({ key: k, text: t });
        });
        fd.append('options', JSON.stringify(optArr));
      }
      var r = await fetch('<?= $BASE ?>/api.php', { method: 'POST', body: fd, credentials: 'same-origin' });
      var j = await r.json();
      if (!j.ok) throw new Error(j.error || '保存失败');
      qmMsg.textContent = '已保存';
      setTimeout(function () { closeQ(); location.reload(); }, 600);
    } catch (e) {
      qmMsg.textContent = e.message;
      qmSave.disabled = false;
    }
  });

  /* 删除题目 */
  var qmDel = document.getElementById('qmDel');
  var delBusy = false;
  if (qmDel) {
    qmDel.addEventListener('click', async function () {
      if (delBusy) return;
      if (QS.qid <= 0) { qmMsg.textContent = '未选中题目'; return; }
      if (!confirm('确定删除题目 #' + QS.qid + '？\n\n原文会存入 questions_archive，可人工恢复。')) return;
      delBusy = true;
      qmDel.disabled = true;
      qmMsg.textContent = '删除中…';
      try {
        var fd = new FormData();
        fd.append('action', 'q_delete');
        fd.append('id', QS.qid);
        fd.append('fb_id', QS.fid || 0);
        var r = await fetch('<?= $BASE ?>/api.php', { method: 'POST', body: fd, credentials: 'same-origin' });
        var j = await r.json();
        if (!j.ok) throw new Error(j.error || '删除失败');
        qmMsg.textContent = '已删除 #' + j.deleted;
        setTimeout(function () { closeQ(); location.reload(); }, 600);
      } catch (e) {
        qmMsg.textContent = e.message;
        qmDel.disabled = false;
        delBusy = false;
      }
    });
  }

  qm.querySelectorAll('[data-qclose]').forEach(function (el) {
    el.addEventListener('click', closeQ);
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !qm.hidden) closeQ();
  });

  document.querySelectorAll('.fb-fix').forEach(function (b) {
    b.addEventListener('click', function () { openQ(b.dataset.qid, b.dataset.id); });
  });

  document.querySelectorAll('.q-ok').forEach(function (b) {
    b.addEventListener('click', async function () {
      var act = b.dataset.act;
      var fd = new FormData();
      fd.append('action', 'qr_decide');
      fd.append('id', b.dataset.id);
      fd.append('act', act);
      if (act === 'approve') {
        var v = prompt('设置该用户的新额度（当前 ' + b.dataset.cur + '）', '150');
        if (v === null) return;
        var n = parseInt(v, 10);
        if (!n || n < 1 || n > 1000) { alert('额度需为 1-1000 的整数'); return; }
        fd.append('limit', n);
      } else {
        var note = prompt('拒绝理由（会展示给用户，可留空）', '');
        if (note === null) return;
        fd.append('note', note);
      }
      b.disabled = true;
      try {
        const r = await fetch('<?= $BASE ?>/api.php', { method: 'POST', body: fd, credentials: 'same-origin' });
        const j = await r.json();
        if (!j.ok) throw new Error(j.error || '失败');
        location.reload();
      } catch (e) { toast(e.message); b.disabled = false; }
    });
  });

  document.querySelectorAll('.fb-ok:not(.fb-fix)').forEach(function (b) {
    b.addEventListener('click', async function () {
      b.disabled = true;
      try {
        const fd = new FormData();
        fd.append('action', 'fb_status');
        fd.append('id', b.dataset.id);
        fd.append('status', b.dataset.st);
        if (b.dataset.note !== undefined) fd.append('note', b.dataset.note);
        const r = await fetch('<?= $BASE ?>/api.php', { method: 'POST', body: fd, credentials: 'same-origin' });
        const j = await r.json();
        if (!j.ok) throw new Error(j.error || '失败');
        location.reload();
      } catch (e) { toast(e.message); b.disabled = false; }
    });
  });

  const bulk = document.getElementById('bulk-legacy');
  if (bulk) {
    bulk.addEventListener('click', async () => {
      if (!confirm('确定删除全部与 2026 重复的旧库题？此操作不可撤销。')) return;
      bulk.disabled = true;
      bulk.textContent = '处理中…';
      try {
        const fd = new FormData();
        fd.append('action', 'bulk_legacy');
        const r = await fetch('<?= $BASE ?>/api.php', { method: 'POST', body: fd, credentials: 'same-origin' });
        const j = await r.json();
        if (!j.ok) throw new Error(j.error || '失败');
        toast('已删除 ' + j.deleted + ' 条旧库题');
        setTimeout(() => location.reload(), 900);
      } catch (e) {
        toast(e.message);
        bulk.disabled = false;
        bulk.textContent = '重试';
      }
    });
  }
})();
</script>
</body>
</html>
