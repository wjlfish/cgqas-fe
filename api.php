<?php
/**
 * 题库 API
 *   GET api.php?action=search&q=&subject=&type=&page=
 *   GET api.php?action=subjects
 *   GET api.php?action=stats
 *   GET api.php?action=quiz&subject=&n=
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

require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/response.php';
require_once __DIR__ . '/lib/ratelimit.php';

$t0     = microtime(true);
$c      = cfg();
$action = preg_replace('/[^a-z_]/', '', (string) ($_GET['action'] ?? ''));
$ipBin  = ip_bin(client_ip());

// ── 蜜罐: 命中直接 24h 封禁, 不返回任何数据 ──
if ($action === '__trap') {
    ban($ipBin, $c['limit']['hard_ban_seconds'], true);
    log_request('trap', [], 403, 0);
    fail('forbidden', 403);
}

if ($action === '') {
    fail('missing action', 400);
}

$isAdmin = is_admin();

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
    if (!verify_token()) {
        log_request($action, $_GET, 401, 0);
        fail('invalid token', 401);
    }
    json_out(['ok' => true] + issue_token());
}
if (!verify_token()) {
    log_request($action, $_GET, 401, 0);
    fail('invalid token', 401);
}

$pdo = db();
$out = null;
$status = 200;

try {
    switch ($action) {

        // ─────────── 科目列表 ───────────
        case 'subjects': {
            $rows = $pdo->query(
                "SELECT s.name, COUNT(qs.question_id) AS n
                 FROM subjects s
                 LEFT JOIN question_subjects qs ON qs.subject_id = s.id
                 GROUP BY s.id, s.name
                 HAVING n > 0
                 ORDER BY n DESC"
            )->fetchAll();
            $total = (int) $pdo->query("SELECT COUNT(*) FROM questions")->fetchColumn();
            $out = ['ok' => true,
                    'total' => $total,
                    'subjects' => array_map(
                        fn($r) => ['name' => $r['name'], 'count' => (int) $r['n']], $rows)];
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
            if ($page >= $c['page_max']) {
                fail('页码超出范围', 400);
            }
            $validTypes = ['判断', '单选', '多选', '填空', '问答'];
            if ($type !== '' && !in_array($type, $validTypes, true)) {
                fail('题型参数非法', 400);
            }

            // 翻页枚举检测
            if ($page > 0 && enum_check($ipBin)) {
                ban($ipBin, $c['limit']['ban_seconds']);
                log_request('search', $_GET, 429, 0);
                fail('too many requests', 429, ['reason' => 'enum']);
            }

            $where  = [];
            $params = [];
            if ($q !== '') {
                $where[]  = '(q.stem LIKE ? OR q.answer LIKE ?)';
                $like     = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
                $params[] = $like;
                $params[] = $like;
            }
            if ($type !== '') {
                $where[]  = 'q.type = ?';
                $params[] = $type;
            }
            if ($subject !== '') {
                $where[]  = 'EXISTS (SELECT 1 FROM question_subjects x
                                     JOIN subjects s2 ON s2.id = x.subject_id
                                     WHERE x.question_id = q.id AND s2.name = ?)';
                $params[] = $subject;
            }
            // 无任何筛选条件时, 不倾倒全库(按 id 排序会连续出现大量同类题),
            // 改为「每日轮换的混合精选」, 兼顾观感与反爬。
            $sample = ($q === '' && $subject === '' && $type === '');
            if (!$where) {
                $where[] = '1 = 1';
            }
            $w = implode(' AND ', $where);

            $cntSt = $pdo->prepare("SELECT COUNT(*) FROM questions q WHERE $w");
            $cntSt->execute($params);
            $total = (int) $cntSt->fetchColumn();

            $lim = (int) $c['page_size'];
            if ($sample) {
                $seed = date('Ymd');
                $off  = 0;
                $order = "ORDER BY MD5(CONCAT(q.id, '$seed'))";
            } else {
                $off   = $page * $lim;
                $order = 'ORDER BY q.id';
            }
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

            $out = [
                'ok'     => true,
                'total'  => $total,
                'page'   => $page,
                'pages'  => $sample ? 1 : min((int) ceil($total / $lim), (int) $c['page_max']),
                'sample' => $sample,
                'items'  => $items,
            ];
            break;
        }

        // ─────────── 随机练习 ───────────
        case 'quiz': {
            $subject = trim((string) ($_GET['subject'] ?? ''));
            $n       = min(max(1, (int) ($_GET['n'] ?? 5)), (int) $c['quiz_max']);
            $params  = [];
            $join    = '';
            $w       = '1=1';
            if ($subject !== '') {
                $join     = 'JOIN question_subjects x ON x.question_id = q.id
                             JOIN subjects s ON s.id = x.subject_id';
                $w        = 's.name = ?';
                $params[] = $subject;
            }
            $sql = "SELECT q.id, q.type, q.stem, q.options, q.answer
                    FROM questions q $join
                    WHERE $w AND q.type IN ('判断','单选','多选')
                    ORDER BY RAND() LIMIT $n";
            $st = $pdo->prepare($sql);
            $st->execute($params);
            $rows = $st->fetchAll();
            $out = ['ok' => true, 'items' => array_map(static function ($r) {
                $r['id']      = (int) $r['id'];
                $r['options'] = $r['options'] ? json_decode($r['options'], true) : [];
                return $r;
            }, $rows)];
            break;
        }

        // ─────────── 统计 ───────────
        case 'stats': {
            $total = (int) $pdo->query("SELECT COUNT(*) FROM questions")->fetchColumn();
            $types = $pdo->query("SELECT type, COUNT(*) n FROM questions GROUP BY type")
                         ->fetchAll();
            $byType = [];
            foreach ($types as $r) {
                $byType[$r['type']] = (int) $r['n'];
            }
            $top = $pdo->query(
                "SELECT s.name, COUNT(x.question_id) n FROM subjects s
                 JOIN question_subjects x ON x.subject_id = s.id
                 GROUP BY s.id, s.name ORDER BY n DESC LIMIT 12"
            )->fetchAll();
            $todayReq = (int) $pdo->query(
                "SELECT COUNT(*) FROM request_log WHERE created_at >= CURDATE()"
            )->fetchColumn();
            $todayIp = (int) $pdo->query(
                "SELECT COUNT(DISTINCT ip) FROM request_log WHERE created_at >= CURDATE()"
            )->fetchColumn();
            // 最后更新时间: 优先取 settings 里人工维护的值, 否则回落题库最大写入时间
            $updated = null;
            try {
                $st2 = $pdo->prepare("SELECT v FROM settings WHERE k = 'bank_updated_at'");
                $st2->execute();
                $updated = $st2->fetchColumn() ?: null;
            } catch (Throwable $e) {}
            if (!$updated) {
                $updated = $pdo->query("SELECT MAX(created_at) FROM questions")->fetchColumn() ?: null;
            }
            $out = ['ok' => true, 'total' => $total, 'by_type' => $byType,
                    'top_subjects' => array_map(
                        fn($r) => ['name' => $r['name'], 'count' => (int) $r['n']], $top),
                    'today_requests' => $todayReq, 'today_ips' => $todayIp,
                    'updated_at' => $updated ? date('Y-m-d', strtotime((string) $updated)) : ''];
            break;
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

        default:
            fail('unknown action', 404);
    }
} catch (Throwable $e) {
    $status = 500;
    $out = ['ok' => false, 'error' => 'server error'];
}

log_request($action, $_GET, $status, (int) ((microtime(true) - $t0) * 1000));
json_out($out ?? ['ok' => false, 'error' => 'server error'], $status);
