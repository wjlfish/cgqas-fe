<?php
declare(strict_types=1);
ini_set('display_errors', '0');
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/admin.php';
require_once __DIR__ . '/lib/response.php';

if (!admin_logged_in()) {
    fail('unauthorized', 401);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail('method not allowed', 405);
}

$pdo   = db();
$act   = (string) ($_POST['action'] ?? '');
$key   = (string) ($_POST['pair_key'] ?? '');
$aid   = (int) ($_POST['a'] ?? 0);
$bid   = (int) ($_POST['b'] ?? 0);
$choice = (string) ($_POST['choice'] ?? '');

// 批量: 删除与 2026 重复的旧库题
if ($act === 'bulk_legacy') {
    $pdo->beginTransaction();
    try {
        $sel = $pdo->query(
            "SELECT p.a_id, p.b_id,
                    qa.source sa, qb.source sb
             FROM dedupe_pairs p
             JOIN questions qa ON qa.id = p.a_id
             JOIN questions qb ON qb.id = p.b_id
             WHERE p.category = 'legacy_overlap'
               AND (qa.source = 'legacy' OR qb.source = 'legacy')"
        )->fetchAll();
        $done = 0; $seen = [];
        foreach ($sel as $r) {
            $keep = $r['sa'] === 'legacy' ? (int) $r['b_id'] : (int) $r['a_id'];
            $drop = $r['sa'] === 'legacy' ? (int) $r['a_id'] : (int) $r['b_id'];
            if (isset($seen[$drop])) {
                continue;
            }
            $seen[$drop] = 1;
            $chk = $pdo->prepare("SELECT source FROM questions WHERE id = ?");
            $chk->execute([$drop]);
            if ($chk->fetchColumn() !== 'legacy') {
                continue;
            }
            $pdo->prepare(
                "INSERT IGNORE INTO question_subjects (question_id, subject_id)
                 SELECT ?, subject_id FROM question_subjects WHERE question_id = ?"
            )->execute([$keep, $drop]);
            $pdo->prepare("DELETE FROM question_subjects WHERE question_id = ?")->execute([$drop]);
            $pdo->prepare("DELETE FROM questions WHERE id = ?")->execute([$drop]);
            $done++;
        }
        $pdo->commit();
        json_out(['ok' => true, 'deleted' => $done]);
    } catch (Throwable $e) {
        $pdo->rollBack();
        fail('db error', 500);
    }
}


/* ── 错题处理: 取题目详情 ── */
if ($act === 'q_get') {
    $qid = (int) ($_POST['id'] ?? 0);
    if ($qid <= 0) {
        fail('invalid id', 400);
    }
    $st = $pdo->prepare(
        "SELECT q.id, q.type, q.stem, q.options, q.answer, q.source,
                GROUP_CONCAT(s.name ORDER BY s.name SEPARATOR '、') AS subjects
         FROM questions q
         LEFT JOIN question_subjects qs ON qs.question_id = q.id
         LEFT JOIN subjects s ON s.id = qs.subject_id
         WHERE q.id = ?
         GROUP BY q.id"
    );
    $st->execute([$qid]);
    $q = $st->fetch();
    if (!$q) {
        fail('题目不存在', 404);
    }
    $opts = null;
    if (!empty($q['options'])) {
        $opts = json_decode((string) $q['options'], true);
    }
    $fb = null;
    $fid = (int) ($_POST['fb_id'] ?? 0);
    if ($fid > 0) {
        $fst = $pdo->prepare(
            "SELECT f.id, f.reason, f.content, f.contact, f.created_at, u.phone
             FROM feedback f LEFT JOIN users u ON u.id = f.user_id
             WHERE f.id = ?"
        );
        $fst->execute([$fid]);
        $fb = $fst->fetch() ?: null;
    }
    json_out([
        'ok' => true,
        'q'  => [
            'id'       => (int) $q['id'],
            'type'     => (string) $q['type'],
            'stem'     => (string) $q['stem'],
            'options'  => $opts,
            'answer'   => (string) $q['answer'],
            'source'   => (string) $q['source'],
            'subjects' => (string) ($q['subjects'] ?? ''),
        ],
        'fb' => $fb,
    ]);
}

/* ── 错题处理: 保存答案 ── */
if ($act === 'q_save') {
    $qid = (int) ($_POST['id'] ?? 0);
    $ans = trim((string) ($_POST['answer'] ?? ''));
    $fid = (int) ($_POST['fb_id'] ?? 0);
    if ($qid <= 0) {
        fail('invalid id', 400);
    }
    if ($ans === '') {
        fail('答案不能为空', 400);
    }
    if (mb_strlen($ans) > 500) {
        fail('答案过长（最多 500 字）', 400);
    }
    $st = $pdo->prepare('SELECT type, answer FROM questions WHERE id = ?');
    $st->execute([$qid]);
    $row = $st->fetch();
    if (!$row) {
        fail('题目不存在', 404);
    }
    $type = (string) $row['type'];
    $old  = (string) $row['answer'];

    if ($type === '判断') {
        $map = ['对' => '正确', '错' => '错误', 'true' => '正确', 'false' => '错误',
                'T' => '正确', 'F' => '错误'];
        if (isset($map[$ans])) {
            $ans = $map[$ans];
        }
        if (!in_array($ans, ['正确', '错误'], true)) {
            fail('判断题答案只能是「正确」或「错误」', 400);
        }
    } elseif ($type === '单选' || $type === '多选') {
        $ans = strtoupper(preg_replace('/[^A-Za-z]/', '', $ans) ?? '');
        if ($ans === '') {
            fail('选择题答案应为选项字母，如 A / AB', 400);
        }
        if ($type === '单选' && mb_strlen($ans) > 1) {
            fail('单选题答案只能是一个字母', 400);
        }
        $letters = array_values(array_unique(str_split($ans)));
        sort($letters);
        $ans = implode('', $letters);

        $st2 = $pdo->prepare('SELECT options FROM questions WHERE id = ?');
        $st2->execute([$qid]);
        $raw = (string) $st2->fetchColumn();
        if ($raw !== '') {
            $opts = json_decode($raw, true);
            if (is_array($opts)) {
                $keys = [];
                foreach ($opts as $o) {
                    if (isset($o['key'])) {
                        $keys[] = strtoupper((string) $o['key']);
                    }
                }
                foreach ($letters as $L) {
                    if ($keys && !in_array($L, $keys, true)) {
                        fail('选项 ' . $L . ' 不存在', 400);
                    }
                }
            }
        }
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE questions SET answer = ? WHERE id = ?')->execute([$ans, $qid]);
        if ($fid > 0) {
            $pdo->prepare(
                'UPDATE feedback SET status = 1, admin_note = ? WHERE id = ?'
            )->execute([mb_substr('原答案：' . $old . ' → 新答案：' . $ans, 0, 240), $fid]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        fail('db error', 500);
    }
    json_out(['ok' => true, 'old' => $old, 'answer' => $ans]);
}

if ($act === 'fb_status') {
    $id  = (int) ($_POST['id'] ?? 0);
    $stt = (int) ($_POST['status'] ?? 0);
    if ($id <= 0 || !in_array($stt, [0, 1, 2], true)) {
        fail('invalid params', 400);
    }
    $pdo->prepare('UPDATE feedback SET status = ? WHERE id = ?')->execute([$stt, $id]);
    json_out(['ok' => true]);
}

if ($act !== 'decide') {
    fail('unknown action', 400);
}
if (!in_array($choice, ['keep_a', 'keep_b', 'both'], true)) {
    fail('invalid choice', 400);
}

$st = $pdo->prepare("SELECT * FROM dedupe_pairs WHERE pair_key = ? AND a_id = ? AND b_id = ?");
$st->execute([$key, $aid, $bid]);
$pair = $st->fetch();
if (!$pair) {
    fail('pair not found', 404);
}

$deleted = 0;
$pdo->beginTransaction();
try {
    if ($choice === 'keep_a' || $choice === 'keep_b') {
        $keep  = $choice === 'keep_a' ? $aid : $bid;
        $drop  = $choice === 'keep_a' ? $bid : $aid;
        // 科目归属并入保留题
        $pdo->prepare(
            "INSERT IGNORE INTO question_subjects (question_id, subject_id)
             SELECT ?, subject_id FROM question_subjects WHERE question_id = ?"
        )->execute([$keep, $drop]);
        $pdo->prepare("DELETE FROM question_subjects WHERE question_id = ?")->execute([$drop]);
        $pdo->prepare("DELETE FROM questions WHERE id = ?")->execute([$drop]);
        $deleted = $drop;
    }
    $pdo->prepare(
        "INSERT INTO dedupe_decisions (pair_key, a_id, b_id, action, category, score)
         VALUES (?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE action = VALUES(action)"
    )->execute([$key, $aid, $bid, $choice, (string) $pair['category'],
                (float) ($pair['j_full'] ?? 0)]);
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fail('db error', 500);
}

$undec = (int) $pdo->query(
    "SELECT COUNT(*) FROM dedupe_pairs p
     JOIN questions qa ON qa.id = p.a_id
     JOIN questions qb ON qb.id = p.b_id
     LEFT JOIN dedupe_decisions d ON d.pair_key = p.pair_key
     WHERE d.id IS NULL"
)->fetchColumn();

json_out(['ok' => true, 'deleted' => $deleted, 'undecided_total' => $undec]);
