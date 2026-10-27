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
