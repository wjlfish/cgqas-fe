<?php
/**
 * 题库 API
 *   GET api.php?action=search&q=&subject=&type=&page=
 *   GET api.php?action=subjects
 *   GET api.php?action=stats
 *   GET api.php?action=detail&id=
 *   GET api.php?action=refresh   刷新令牌
 *   GET api.php?action=__trap    蜜罐
 *
 * 所有接口要求:
 *   - 合法的 X-CG-Token (由 index.php 下发)
 *   - 同源 (Sec-Fetch-Site / Referer)
 *   - 通过 UA 与频率校验
 */
declare(strict_types=1);
ini_set('display_errors', '0');

require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/response.php';
require_once __DIR__ . '/lib/ratelimit.php';
require_once __DIR__ . '/lib/sms.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/captcha2.php';
require_once __DIR__ . '/lib/tcaptcha.php';
require_once __DIR__ . '/lib/geo.php';

$t0     = microtime(true);
$c      = cfg();
maybe_purge_logs();   // 概率性清理超期日志(共享主机空间有限)

$action = preg_replace('/[^a-z_]/', '', (string) ($_GET['action'] ?? ''));
$ipBin  = ip_bin(client_ip());

// ── 蜜罐: 命中直接 24h 封禁, 不返回任何数据 ──
if ($action === '__trap') {
    if (!is_admin()) {
        ban($ipBin, $c['limit']['hard_ban_seconds'], true);
    }
    log_request('trap', [], 403, 0);
    fail('forbidden', 403);
}

if ($action === '') {
    fail('missing action', 400);
}

// ── 含手机号/验证码的动作只接受 POST, 避免敏感参数进入访问日志与 Referer ──
$POST_ONLY = ['sms_send', 'sms_login', 'subject_set'];
if (in_array($action, $POST_ONLY, true) && ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    log_request($action, [], 405, 0);
    fail('页面版本过旧，请刷新后重试', 405, ['reason' => 'post_required']);
}


$isAdmin = is_admin();

// ── 地域限制: 境外 IP 直接拒绝(管理员与白名单放行) ──
$geoExempt = is_admin_key()
    || (!empty($c['geo']['exempt_admin']) && is_admin());
if (!$geoExempt && geo_blocked()) {
    log_request($action, $_GET, 403, 0);
    fail('仅面向中国大陆用户开放，请切换网络或关闭代理后重试', 403,
         ['reason' => 'geo_blocked']);
}

// ── 1. UA 校验(管理旁路跳过) ──
if (!$isAdmin && ua_blacklisted()) {
    ban($ipBin, $c['limit']['hard_ban_seconds'], true);
    log_request($action, $_GET, 403, 0);
    fail('forbidden', 403);
}

// ── 2. 同源校验(浏览器会带 Sec-Fetch-Site, 兼容性缺失时退回 Referer) ──
$sfs = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '';
if ($sfs !== '') {
    if (!in_array($sfs, ['same-origin', 'none'], true)) {
        log_request($action, $_GET, 403, 0);
        fail('cross-site request rejected', 403);
    }
} else {
    $ref = $_SERVER['HTTP_REFERER'] ?? '';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($ref === '' || stripos($ref, '//' . $host) === false) {
        log_request($action, $_GET, 403, 0);
        fail('invalid referer', 403);
    }
}

// ── 3. 限流(管理旁路跳过) ──
[$allowed, $rl] = $isAdmin ? [true, []] : rl_hit($action);
if (!$allowed) {
    log_request($action, $_GET, 429, (int) ((microtime(true) - $t0) * 1000));
    header('Retry-After: ' . (int) ($rl['retry_after'] ?? 60));
    fail('too many requests', 429, [
        'reason'      => $rl['reason'] ?? 'rate',
        'retry_after' => (int) ($rl['retry_after'] ?? 60),
    ]);
}

// ── 4. 令牌校验 (refresh 例外, 它用旧令牌换新令牌) ──
if ($action === 'refresh') {
    // 换发 token 不校验旧签名: 出口 IP 漂移后旧 token 会失效,
    // 此处必须能自助换发, 否则用户只能刷新页面才能恢复。
    // 安全性: 响应只回到同源页面(参考 same-origin 校验), 跨站无法读取。
    json_out(['ok' => true] + issue_token());
}
if (!verify_token()) {
    log_request($action, $_GET, 401, 0);
    fail('页面已过期，请刷新后重试', 401, ['code' => 'token_invalid']);
}

// ── 4.5 登录与额度 ──
$NEED_LOGIN = ['search'];
$me = auth_current_user();

$AUTH_REQUIRED = (bool) (auth_cfg()['require'] ?? true);

if (in_array($action, $NEED_LOGIN, true) && $AUTH_REQUIRED) {
    if (!$me) {
        log_request($action, $_GET, 401, (int) ((microtime(true) - $t0) * 1000));
        fail('请先完成手机号验证', 401, ['reason' => 'login_required']);
    }
    // 先做廉价校验, 避免无效请求也扣额度
    if ($action === 'search') {
        $q0 = trim((string) ($_GET['q'] ?? ''));
        if (mb_strlen($q0) < (int) $c['limit']['kw_min_len']) {
            log_request($action, $_GET, 400, (int) ((microtime(true) - $t0) * 1000));
            fail('请输入至少 ' . (int) $c['limit']['kw_min_len'] . ' 个字的关键词', 400,
                 ['reason' => 'need_keyword']);
        }
    }
    [$qok, $left, $limit] = auth_consume($me['id'], 1);
    if (!$qok) {
        log_request($action, $_GET, 403, (int) ((microtime(true) - $t0) * 1000));
        fail('搜题次数已用完', 403, [
            'reason' => 'quota_exhausted', 'used' => $me['used'], 'limit' => $limit, 'left' => 0,
        ]);
    }
    $me['used']  = $me['used'] + 1;
    $me['limit'] = $limit;
}

// ── 4.6 反馈类: 只需登录, 不消耗搜题额度 ──
$NEED_AUTH = ['quota_request', 'quota_status'];
if (in_array($action, $NEED_AUTH, true) && $AUTH_REQUIRED && !$me) {
    log_request($action, $_GET, 401, (int) ((microtime(true) - $t0) * 1000));
    fail('请先完成手机号验证', 401, ['reason' => 'login_required']);
}

// ── 错误留痕: 记录未捕获异常与致命错误, 便于排查 5xx ──
function api_log_error(string $action, string $msg): void
{
    $line = date('c') . "	[" . $action . "]	" . $msg . "	"
          . ($_SERVER['REMOTE_ADDR'] ?? '-') . "	"
          . mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 80) . "\n";
    $c = @require __DIR__ . '/lib/config.php';
    $dir = (string) ($c['log']['dir'] ?? '/var/log/cgtk');
    @file_put_contents(rtrim($dir, '/') . '/cgtk-api-error.log', $line, FILE_APPEND | LOCK_EX);
}

register_shutdown_function(static function () use (&$action): void {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        api_log_error((string) $action, 'FATAL ' . $e['message'] . ' @ ' . $e['file'] . ':' . $e['line']);
    }
});

$pdo = db();
$out = null;
$status = 200;


/** 最新评论(带脱敏昵称) */
function fb_comments(PDO $pdo, int $limit = 20): array
{
    $st = $pdo->query(
        "SELECT f.content, f.created_at, u.phone
         FROM feedback f JOIN users u ON u.id = f.user_id
         WHERE f.kind = 'comment'
         ORDER BY f.id DESC LIMIT $limit"
    );
    $rows = $st->fetchAll();
    $out  = [];
    foreach ($rows as $r) {
        $out[] = [
            'content' => $r['content'],
            'name'    => phone_mask((string) $r['phone']),
            'at'      => substr((string) $r['created_at'], 5, 11),
        ];
    }
    return $out;
}

try {
    switch ($action) {

        // ─────────── 科目列表 ───────────
        // ─────────── 设置/更换所选科目 ───────────
        case 'subject_set': {
            if (!$me) {
                fail('请先登录', 401, ['reason' => 'login_required']);
            }
            $subjCfg = $c['subject'] ?? ['public_id' => 1, 'max_changes' => 2];
            $publicId = (int) $subjCfg['public_id'];
            $maxChg   = (int) $subjCfg['max_changes'];

            $want = trim((string) ($_POST['subject'] ?? ''));
            if ($want === '') {
                fail('请选择科目', 400);
            }
            // 支持按名称或 id
            if (ctype_digit($want)) {
                $st = $pdo->prepare("SELECT id, name FROM subjects WHERE id = ?");
                $st->execute([(int) $want]);
            } else {
                $st = $pdo->prepare("SELECT id, name FROM subjects WHERE name = ?");
                $st->execute([$want]);
            }
            $sub = $st->fetch();
            if (!$sub) {
                fail('科目不存在', 400);
            }
            if ((int) $sub['id'] === $publicId) {
                fail('公共题库无需选择，直接检索即可', 400);
            }

            $current = $me['subject_id'];
            if ($current !== null && (int) $current === (int) $sub['id']) {
                json_out(['ok' => true, 'subject' => ['id' => (int) $sub['id'], 'name' => $sub['name']],
                          'changes_left' => max(0, $maxChg - $me['subject_changes']), 'unchanged' => true]);
            }
            // 首次选择免费；之后每次更换消耗一次
            $used = $me['subject_changes'];
            if ($current !== null && $used >= $maxChg) {
                fail('更换科目次数已用完（' . $maxChg . ' 次），如需修改请联系管理员', 403,
                     ['reason' => 'change_limit']);
            }
            $newUsed = $current === null ? $used : $used + 1;
            $pdo->prepare("UPDATE users SET subject_id = ?, subject_changes = ? WHERE id = ?")
                ->execute([(int) $sub['id'], $newUsed, $me['id']]);
            json_out(['ok' => true,
                      'subject' => ['id' => (int) $sub['id'], 'name' => $sub['name']],
                      'changes_left' => max(0, $maxChg - $newUsed)]);
        }

        case 'subjects': {
            // 不对外公布题量: 只给科目名与 id
            $rows = $pdo->query(
                "SELECT s.id, s.name
                 FROM subjects s
                 WHERE EXISTS (SELECT 1 FROM question_subjects qs WHERE qs.subject_id = s.id)
                 ORDER BY s.id"
            )->fetchAll();
            $out = ['ok' => true,
                    'public_id' => (int) ($c['subject']['public_id'] ?? 1),
                    'subjects' => array_map(
                        fn($r) => ['id' => (int) $r['id'], 'name' => $r['name']],
                        $rows)];
            break;
        }

        // ─────────── 检索 ───────────
        case 'search': {
            $q       = trim((string) ($_GET['q'] ?? ''));
            $subject = trim((string) ($_GET['subject'] ?? ''));
            $type    = trim((string) ($_GET['type'] ?? ''));
            $page    = max(0, (int) ($_GET['page'] ?? 0));

            if (mb_strlen($q) > $c['query_max_len']) {
                fail('关键词过长', 400);
            }
            $validTypes = ['判断', '单选', '多选', '填空', '问答'];
            if ($type !== '' && !in_array($type, $validTypes, true)) {
                fail('题型参数非法', 400);
            }

            // ① 关键词门槛: 必须有关键字, 不允许空关键词随机浏览
            $hasKw = mb_strlen($q) >= (int) $c['limit']['kw_min_len'];
            if (!$hasKw) {
                fail('请输入至少 ' . (int) $c['limit']['kw_min_len'] . ' 个字的关键词', 400,
                     ['reason' => 'need_keyword']);
            }

            // ② 累计配额预检(未扣减) —— 管理员不受 IP 浏览额度限制
            [$bok, $binfo] = $isAdmin ? [true, []] : budget_guard($ipBin, 0, false);
            if (!$bok) {
                header('Retry-After: ' . (int) ($binfo['retry_after'] ?? 3600));
                log_request('search', $_GET, 429, 0);
                fail('今日浏览额度已用完，请明日再来', 429, $binfo);
            }

            $pageMax = (int) $c['limit']['kw_pages_max'];
            if ($page >= $pageMax) {
                fail('可翻阅页数已达上限，请细化关键词', 400, ['reason' => 'page_cap']);
            }

            // 翻页枚举检测
            if ($page > 0 && !$isAdmin && enum_check($ipBin)) {
                ban($ipBin, $c['limit']['ban_seconds']);
                log_request('search', $_GET, 429, 0);
                fail('too many requests', 429, ['reason' => 'enum']);
            }

            // ③ 科目范围: 公共题库 + 用户所选科目
            $subjCfg  = $c['subject'] ?? ['public_id' => 1, 'max_changes' => 2];
            $publicId = (int) $subjCfg['public_id'];
            $mySubj   = $me['subject_id'] ?? null;
            if ($mySubj === null) {
                $rows = $pdo->query(
                    "SELECT s.id, s.name FROM subjects s
                     WHERE EXISTS (SELECT 1 FROM question_subjects qs WHERE qs.subject_id = s.id)
                     ORDER BY s.id"
                )->fetchAll();
                log_request('search', $_GET, 400, 0);
                fail('请先选择你的体育课科目', 400, [
                    'reason' => 'need_subject',
                    'public_id' => $publicId,
                    'subjects' => array_map(
                        fn($r) => ['id' => (int) $r['id'], 'name' => $r['name']],
                        $rows
                    ),
                ]);
            }

            $where  = [];
            $params = [];
            if ($q !== '') {
                $where[]  = '(q.stem LIKE ? OR q.answer LIKE ?)';
                $like     = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
                $params[] = $like;
                $params[] = $like;
            }
            // 只在可见范围内检索
            $where[]  = 'EXISTS (SELECT 1 FROM question_subjects xs
                                 WHERE xs.question_id = q.id AND xs.subject_id IN (?, ?))';
            $params[] = $publicId;
            $params[] = (int) $mySubj;
            if ($type !== '') {
                $where[]  = 'q.type = ?';
                $params[] = $type;
            }
            if ($subject !== '') {
                $st = $pdo->prepare('SELECT id FROM subjects WHERE name = ?');
                $st->execute([$subject]);
                $sid = (int) ($st->fetchColumn() ?: 0);
                if ($sid !== $publicId && $sid !== (int) $mySubj) {
                    fail('该科目不在你的可见范围内', 400, ['reason' => 'subject_out_of_scope']);
                }
                $where[]  = 'EXISTS (SELECT 1 FROM question_subjects x
                                     WHERE x.question_id = q.id AND x.subject_id = ?)';
                $params[] = $sid;
            }
            if (!$where) {
                $where[] = '1 = 1';
            }
            $w = implode(' AND ', $where);

            $cntSt = $pdo->prepare("SELECT COUNT(*) FROM questions q WHERE $w");
            $cntSt->execute($params);
            $total = (int) $cntSt->fetchColumn();

            $lim = (int) $c['page_size'];

            $off   = $page * $lim;
            $order = 'ORDER BY q.id';

            $sql  = "SELECT q.id, q.type, q.stem, q.options, q.answer, q.source,
                            (SELECT GROUP_CONCAT(s3.name ORDER BY s3.name SEPARATOR ' / ')
                             FROM question_subjects x3
                             JOIN subjects s3 ON s3.id = x3.subject_id
                             WHERE x3.question_id = q.id) AS subjects
                     FROM questions q
                     WHERE $w
                     $order
                     LIMIT $lim OFFSET $off";
            $st = $pdo->prepare($sql);
            $st->execute($params);
            $rows = $st->fetchAll();

            $items = array_map(static function ($r) {
                $r['id']      = (int) $r['id'];
                $r['options'] = $r['options'] ? json_decode($r['options'], true) : [];
                $r['subjects'] = $r['subjects'] ?? '';
                return $r;
            }, $rows);

            // ④ 扣减配额 —— 管理员不计入 IP 预算
            if (!$isAdmin) {
                budget_guard($ipBin, count($items), true);
            }

            // ⑤ 用量审计
            try {
                db()->prepare(
                    "INSERT INTO search_logs (user_id, action, query, n_items) VALUES (?,?,?,?)"
                )->execute([$me['id'] ?? 0, 'search', mb_substr($q, 0, 150), count($items)]);
            } catch (Throwable $e) {
            }

            // ⑥ 可见范围内无结果时, 提示其他科目里是否有
            $hint = null;
            if ($total === 0 && $page === 0 && $q !== '') {
                $hst = $pdo->prepare(
                    "SELECT s.id, s.name, COUNT(DISTINCT q.id) AS n
                     FROM questions q
                     JOIN question_subjects x ON x.question_id = q.id
                     JOIN subjects s ON s.id = x.subject_id
                     WHERE (q.stem LIKE ? OR q.answer LIKE ?)
                       AND s.id NOT IN (?, ?)
                     GROUP BY s.id, s.name
                     ORDER BY n DESC LIMIT 3"
                );
                $hst->execute([$like, $like, $publicId, (int) $mySubj]);
                $hits = $hst->fetchAll();
                if ($hits) {
                    $left = max(0, (int) $subjCfg['max_changes'] - $me['subject_changes']);
                    $hint = [
                        'subjects'     => array_map(
                            fn($r) => ['id' => (int) $r['id'], 'name' => $r['name']],
                            $hits
                        ),
                        'changes_left' => $left,
                        'can_switch'   => $left > 0,
                    ];
                }
            }

            $out = [
                'ok'     => true,
                'total'  => $total,
                'page'   => $page,
                'pages'  => min((int) ceil($total / $lim), (int) $c['limit']['kw_pages_max']),
                'mode'   => 'search',
                'kw_min' => (int) $c['limit']['kw_min_len'],
                'left'   => max(0, $me['limit'] - $me['used']),
                'subject' => ['id' => (int) $mySubj, 'name' => $me['subject_name'] ?? ''],
                'items'  => $items,
            ];
            if ($hint !== null) {
                $out['hint'] = $hint;
            }
            break;
        }

        // ─────────── 当前用户 ───────────
        case 'me': {
            $subjCfg = $c['subject'] ?? ['public_id' => 1, 'max_changes' => 2];
            $out = ['ok' => true, 'user' => $me ? [
                'phone' => phone_mask($me['phone']),
                'used'  => $me['used'],
                'limit' => $me['limit'],
                'left'  => max(0, $me['limit'] - $me['used']),
                'subject_id'      => $me['subject_id'],
                'subject_name'    => $me['subject_name'],
                'changes_left'    => max(0, (int) $subjCfg['max_changes'] - $me['subject_changes']),
                'max_changes'     => (int) $subjCfg['max_changes'],
            ] : null];
            break;
        }

        // ─────────── 退出 ───────────
        case 'logout': {
            auth_logout();
            $out = ['ok' => true];
            break;
        }

        // ─────────── 发送验证码 ───────────
        case 'sms_send': {
            // 人机验证(仅发送短信前校验, 检索不受影响)
            if (tcaptcha_enabled()) {
                // 腾讯云验证码: ticket + randstr
                [$cok, $cmsg] = tcaptcha_verify(
                    (string) ($_POST['ticket'] ?? ''),
                    (string) ($_POST['randstr'] ?? ''),
                    client_ip()
                );
                if (!$cok) {
                    fail($cmsg, 400, ['reason' => 'captcha_failed']);
                }
            } elseif (captcha2_enabled()) {
                $vp = (string) ($_POST['captcha'] ?? '');
                [$cok, $cmsg] = captcha2_verify($vp);
                if (!$cok) {
                    fail($cmsg, 400, ['reason' => 'captcha_failed']);
                }
            }
            $phone = preg_replace('/\D/', '', (string) ($_POST['phone'] ?? ''));
            if (!phone_valid($phone)) {
                fail('请输入正确的 11 位手机号', 400);
            }
            $err = sms_rate_check($phone);
            if ($err !== null) {
                fail($err, 429, ['reason' => 'sms_rate']);
            }
            $r = sms_send_verify($phone);
            if (!$r['ok']) {
                fail($r['error'] ?? '短信发送失败', 502, ['reason' => 'sms_failed']);
            }
            sms_save_code($phone, $r['code'] ?? null);
            $out = ['ok' => true,
                    'managed' => empty($r['code']),
                    'ttl' => (int) (auth_cfg()['sms_ttl'] ?? 300)];
            break;
        }

        // ─────────── 验证码登录(未注册自动注册) ───────────
        case 'sms_login': {
            $phone = preg_replace('/\D/', '', (string) ($_POST['phone'] ?? ''));
            $code  = preg_replace('/\D/', '', (string) ($_POST['code'] ?? ''));
            if (!phone_valid($phone)) {
                fail('请输入正确的 11 位手机号', 400);
            }
            if (strlen($code) < 4) {
                fail('请输入验证码', 400);
            }

            $mode = (string) (aliyun_cfg()['mode'] ?? 'dev');
            if ($mode === 'dypns_platform') {
                [$ok, $msg] = sms_platform_check($phone, $code);
            } else {
                [$ok, $msg] = sms_check_code($phone, $code);
            }
            if (!$ok) {
                fail($msg, 400, ['reason' => 'code_invalid']);
            }

            $u = auth_find_or_create($phone);
            auth_create_session((int) $u['id']);
            $out = ['ok' => true, 'registered' => $u['created'], 'user' => [
                'phone' => phone_mask($phone),
                'used'  => $u['used'],
                'limit' => $u['limit'],
                'left'  => max(0, $u['limit'] - $u['used']),
            ]];
            break;
        }

        // ─────────── 统计 ───────────
        case 'stats': {
            // 统计已下线: 不再对外公布题库规模
            fail('统计功能已下线', 404);
        }

        // ─────────── 单题 ───────────
        case 'detail': {
            $id = (int) ($_GET['id'] ?? 0);
            if ($id <= 0) {
                fail('缺少 id', 400);
            }
            $st = $pdo->prepare(
                "SELECT q.id, q.type, q.stem, q.options, q.answer, q.source,
                        (SELECT GROUP_CONCAT(s.name ORDER BY s.name SEPARATOR ' / ')
                         FROM question_subjects x JOIN subjects s ON s.id = x.subject_id
                         WHERE x.question_id = q.id) AS subjects
                 FROM questions q WHERE q.id = ?"
            );
            $st->execute([$id]);
            $r = $st->fetch();
            if (!$r) {
                fail('题目不存在', 404);
            }
            $r['id'] = (int) $r['id'];
            $r['options'] = $r['options'] ? json_decode($r['options'], true) : [];
            $out = ['ok' => true, 'item' => $r];
            break;
        }

        // ─────────── 提额申请 ───────────
        case 'quota_request': {
            $reason = trim((string) ($_POST['reason'] ?? ''));
            if (mb_strlen($reason) < 10) {
                fail('请说明申请理由（至少 10 个字）', 400, ['reason' => 'reason_short']);
            }
            if (mb_strlen($reason) > 300) {
                fail('理由最多 300 字', 400, ['reason' => 'reason_long']);
            }
            $uid = (int) $me['id'];

            $st = $pdo->prepare('SELECT id FROM quota_requests WHERE user_id = ? AND status = 0 LIMIT 1');
            $st->execute([$uid]);
            if ($st->fetchColumn()) {
                fail('你已有一条待审核的申请，请等待处理', 400, ['reason' => 'pending_exists']);
            }
            $st = $pdo->prepare(
                'SELECT COUNT(*) FROM quota_requests
                 WHERE user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)'
            );
            $st->execute([$uid]);
            if ((int) $st->fetchColumn() >= 3) {
                fail('30 天内最多申请 3 次', 429, ['reason' => 'too_many_requests']);
            }

            $st = $pdo->prepare('SELECT searches_used, searches_limit FROM users WHERE id = ?');
            $st->execute([$uid]);
            $u = $st->fetch() ?: ['searches_used' => 0, 'searches_limit' => 0];

            $pdo->prepare(
                'INSERT INTO quota_requests (user_id, limit_snapshot, used_snapshot, reason)
                 VALUES (?,?,?,?)'
            )->execute([$uid, (int) $u['searches_limit'], (int) $u['searches_used'], $reason]);

            $out = ['ok' => true, 'id' => (int) $pdo->lastInsertId()];
            break;
        }

        // ─────────── 提额状态 ───────────
        case 'quota_status': {
            $uid = (int) $me['id'];
            $st = $pdo->prepare('SELECT searches_used, searches_limit FROM users WHERE id = ?');
            $st->execute([$uid]);
            $u = $st->fetch() ?: ['searches_used' => 0, 'searches_limit' => 0];

            $st = $pdo->prepare(
                'SELECT id, status, reason, granted_limit, admin_note, created_at, decided_at
                 FROM quota_requests WHERE user_id = ? ORDER BY id DESC LIMIT 1'
            );
            $st->execute([$uid]);
            $req = $st->fetch();

            $out = [
                'ok'      => true,
                'limit'   => (int) $u['searches_limit'],
                'used'    => (int) $u['searches_used'],
                'left'    => max(0, (int) $u['searches_limit'] - (int) $u['searches_used']),
                'request' => $req ? [
                    'id'            => (int) $req['id'],
                    'status'        => (int) $req['status'],
                    'reason'        => (string) $req['reason'],
                    'granted_limit' => $req['granted_limit'] !== null ? (int) $req['granted_limit'] : null,
                    'admin_note'    => (string) $req['admin_note'],
                    'created_at'    => (string) $req['created_at'],
                    'decided_at'    => $req['decided_at'],
                ] : null,
            ];
            break;
        }

        // ─────────── 反馈: 提交 ───────────
        case 'feedback_submit': {
            $kind    = (string) ($_POST['kind'] ?? '');
            $content = trim((string) ($_POST['content'] ?? ''));
            $contact = mb_substr(trim((string) ($_POST['contact'] ?? '')), 0, 64);
            $reason  = (string) ($_POST['reason'] ?? '');
            $qid     = (int) ($_POST['question_id'] ?? 0);

            if (!in_array($kind, ['wrong', 'bug'], true)) {
                fail('反馈类型不正确', 400, ['reason' => 'bad_kind']);
            }

            // 错题申报必须登录(涉及题目检索与额度返还)
            if ($kind === 'wrong' && !$me) {
                log_request($action, $_GET, 401, 0);
                fail('请先完成手机号验证', 401, ['reason' => 'login_required']);
            }
            // 匿名提交 Bug 反馈需要人机验证, 防止刷接口
            if ($kind === 'bug' && !$me && tcaptcha_enabled()) {
                [$cok, $cmsg] = tcaptcha_verify(
                    (string) ($_POST['ticket'] ?? ''),
                    (string) ($_POST['randstr'] ?? ''),
                    client_ip()
                );
                if (!$cok) {
                    fail($cmsg, 400, ['reason' => 'captcha_failed']);
                }
            }
            if (mb_strlen($content) > 1000) {
                fail('内容最多 1000 字', 400, ['reason' => 'too_long']);
            }
            if ($kind === 'bug' && mb_strlen($content) < 5) {
                fail('请描述具体问题（至少 5 个字）', 400, ['reason' => 'content_short']);
            }

            $REASONS = ['答案错误', '题干错误', '选项错误', '答案缺失', '其他'];
            if ($kind === 'wrong') {
                if (!in_array($reason, $REASONS, true)) {
                    fail('请选择问题类型', 400, ['reason' => 'need_reason']);
                }
                if ($qid <= 0) {
                    fail('请选择要申报的题目', 400, ['reason' => 'need_question']);
                }
                // 只校验存在性, 绝不回传题目内容 —— 避免绕过额度按 ID 取题
                $chk = $pdo->prepare('SELECT 1 FROM questions WHERE id = ?');
                $chk->execute([$qid]);
                if (!$chk->fetchColumn()) {
                    fail('题目不存在', 404, ['reason' => 'no_question']);
                }
                $dup = $pdo->prepare(
                    "SELECT id FROM feedback
                     WHERE user_id = ? AND kind = 'wrong' AND question_id = ?
                       AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) LIMIT 1"
                );
                $dup->execute([(int) $me['id'], $qid]);
                if ($dup->fetchColumn()) {
                    fail('这道题你已申报过，我们正在核实', 400, ['reason' => 'duplicate']);
                }
            }

            // 频次控制: 每用户每日 + 每 IP 每日
            $uid   = (int) ($me['id'] ?? 0);
            $ipBin = ip_bin(client_ip());
            $perUserDay = $kind === 'wrong' ? 20 : 5;
            $perIpDay   = $kind === 'wrong' ? 40 : 15;

            // 匿名(user_id=0)不按"用户"计数, 否则会变成全站共享额度
            if ($uid > 0) {
                $st = $pdo->prepare(
                    'SELECT COUNT(*) FROM feedback WHERE user_id = ? AND kind = ? AND created_at >= CURDATE()'
                );
                $st->execute([$uid, $kind]);
                if ((int) $st->fetchColumn() >= $perUserDay) {
                    fail('今日提交已达上限（' . $perUserDay . ' 条），请明天再来', 429,
                         ['reason' => 'fb_user_daily']);
                }
            }
            $st = $pdo->prepare(
                'SELECT COUNT(*) FROM feedback WHERE ip = ? AND created_at >= CURDATE()'
            );
            $st->execute([$ipBin]);
            if ((int) $st->fetchColumn() >= $perIpDay) {
                fail('当前网络今日提交过多，请明天再来', 429, ['reason' => 'fb_ip_daily']);
            }

            $pdo->prepare(
                'INSERT INTO feedback (user_id, kind, question_id, reason, content, contact, ip, ua)
                 VALUES (?,?,?,?,?,?,?,?)'
            )->execute([
                $uid, $kind,
                $kind === 'wrong' ? $qid : null,
                $kind === 'wrong' ? $reason : '',
                $content,
                $contact,
                $ipBin,
                mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            ]);
            $out = ['ok' => true, 'id' => (int) $pdo->lastInsertId()];
            break;
        }

        // ─────────── 反馈: 写评论 ───────────
        case 'feedback_comment': {
            $content = trim((string) ($_POST['content'] ?? ''));
            if ($content === '') {
                fail('写点什么吧', 400, ['reason' => 'empty']);
            }
            if (mb_strlen($content) > 300) {
                fail('评论最多 300 字', 400, ['reason' => 'too_long']);
            }
            $uid   = (int) ($me['id'] ?? 0);
            $ipBin = ip_bin(client_ip());

            $st = $pdo->prepare(
                "SELECT COUNT(*) FROM feedback
                 WHERE user_id = ? AND kind = 'comment' AND created_at >= CURDATE()"
            );
            $st->execute([$uid]);
            if ((int) $st->fetchColumn() >= 5) {
                fail('今天已经说够啦，明天再来', 429, ['reason' => 'cm_user_daily']);
            }
            $st = $pdo->prepare(
                "SELECT COUNT(*) FROM feedback
                 WHERE ip = ? AND kind = 'comment' AND created_at >= CURDATE()"
            );
            $st->execute([$ipBin]);
            if ((int) $st->fetchColumn() >= 15) {
                fail('当前网络今天评论过多，明天再来', 429, ['reason' => 'cm_ip_daily']);
            }

            $pdo->prepare(
                "INSERT INTO feedback (user_id, kind, content, ip, ua) VALUES (?, 'comment', ?, ?, ?)"
            )->execute([
                $uid, $content, $ipBin,
                mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            ]);
            $out = [
                'ok'     => true,
                'id'     => (int) $pdo->lastInsertId(),
                'name'   => phone_mask((string) ($me['phone'] ?? '')),
                'items'  => fb_comments($pdo),
            ];
            break;
        }

        // ─────────── 反馈: 评论列表 ───────────
        case 'feedback_comments': {
            $out = ['ok' => true, 'items' => fb_comments($pdo)];
            break;
        }

        // ─────────── 反馈: 点赞 ───────────
        case 'feedback_like': {
            $uid = (int) ($me['id'] ?? 0);
            $has = $pdo->prepare("SELECT id FROM feedback WHERE user_id = ? AND kind = 'like' LIMIT 1");
            $has->execute([$uid]);
            $liked = (bool) $has->fetchColumn();
            if (!$liked) {
                $pdo->prepare(
                    "INSERT INTO feedback (user_id, kind, ip, ua) VALUES (?, 'like', ?, ?)"
                )->execute([
                    $uid,
                    ip_bin(client_ip()),
                    mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
                ]);
                $liked = true;
            }
            $total = (int) $pdo->query("SELECT COUNT(*) FROM feedback WHERE kind = 'like'")->fetchColumn();
            $out = ['ok' => true, 'liked' => $liked, 'total' => $total];
            break;
        }

        // ─────────── 反馈: 点赞数 (公开, 只读) ───────────
        case 'feedback_stat': {
            $total = (int) $pdo->query("SELECT COUNT(*) FROM feedback WHERE kind = 'like'")->fetchColumn();
            $liked = false;
            $thanked = false;
            if ($me) {
                $st = $pdo->prepare(
                    "SELECT status FROM feedback WHERE user_id = ? AND kind = 'like' LIMIT 1"
                );
                $st->execute([(int) $me['id']]);
                $lk = $st->fetch();
                if ($lk) {
                    $liked = true;
                    $thanked = ((int) $lk['status'] === 1);
                }
            }
            $out = ['ok' => true, 'likes' => $total, 'liked' => $liked, 'thanked' => $thanked];
            break;
        }

        default:
            fail('unknown action', 404);
    }
} catch (Throwable $e) {
    $status = 500;
    api_log_error((string) $action, get_class($e) . ': ' . $e->getMessage()
        . ' @ ' . $e->getFile() . ':' . $e->getLine());
    $out = ['ok' => false, 'error' => 'server error'];
}

log_request($action, $_GET, $status, (int) ((microtime(true) - $t0) * 1000));
json_out($out ?? ['ok' => false, 'error' => 'server error'], $status);
