<?php
declare(strict_types=1);
/**
 * 离线生成「按科目检索推荐词」索引
 * 输出: assets/suggest.json
 * 用法: php ops/build-suggest.php <docroot>
 *
 * 思路: 科目固定且题量不大, 不需要每次请求查库。
 * 取近 90 天真实检索词 Top N, 一次性算出每个科目能命中的词, 按热度排序落盘。
 */
$root = $argv[1] ?? dirname(__DIR__);
$cfg  = require rtrim($root, '/') . '/lib/config.php';
$db   = $cfg['db'];
$pdo  = new PDO($db['dsn'], $db['user'], $db['pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

// 1) 热门检索词 Top 200
$pop = $pdo->query(
    "SELECT query AS q, COUNT(*) AS c
     FROM search_logs
     WHERE created_at >= NOW() - INTERVAL 90 DAY
       AND CHAR_LENGTH(query) BETWEEN 2 AND 8
     GROUP BY query
     ORDER BY c DESC
     LIMIT 200"
)->fetchAll();
fwrite(STDERR, "热门检索词: " . count($pop) . " 个\n");

// 2) 每个词命中哪些科目
$hitStmt = $pdo->prepare(
    "SELECT DISTINCT x.subject_id
     FROM questions q
     JOIN question_subjects x ON x.question_id = q.id
     WHERE q.stem LIKE ? OR q.answer LIKE ?"
);

$bySubject = [];   // subject_id => [word => score]
$wordSpread = [];  // word => 命中的科目数(用于剔除过泛的词)
foreach ($pop as $row) {
    $w = trim((string) $row['q']);
    // 过滤: 纯数字 / 含数字 / 过短
    if ($w === '' || preg_match('/[0-9]/', $w)) {
        continue;
    }
    $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $w) . '%';
    $hitStmt->execute([$like, $like]);
    $hits = $hitStmt->fetchAll();
    $wordSpread[$w] = count($hits);
    foreach ($hits as $h) {
        $sid = (int) $h['subject_id'];
        $bySubject[$sid][$w] = ($bySubject[$sid][$w] ?? 0) + (int) $row['c'];
    }
}

// 命中科目数过多 = 太泛化(如"裁判员""体育"), 不作为推荐
$tooBroad = [];
foreach ($wordSpread as $w => $n) {
    if ($n >= 12) {
        $tooBroad[$w] = true;
    }
}

// 3) 科目名 + 词表
$subjects = $pdo->query('SELECT id, name FROM subjects ORDER BY id')->fetchAll();
$out = [];
foreach ($subjects as $s) {
    $sid  = (int) $s['id'];
    $name = (string) $s['name'];
    $list = [$name];
    if (!empty($bySubject[$sid])) {
        arsort($bySubject[$sid]);
        foreach (array_keys($bySubject[$sid]) as $w) {
            if ($w === $name || in_array($w, $list, true) || isset($tooBroad[$w])) {
                continue;
            }
            $list[] = $w;
            if (count($list) >= 6) {
                break;
            }
        }
    }
    $out[(string) $sid] = $list;
}

$json = json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
file_put_contents(rtrim($root, '/') . '/assets/suggest.json', $json . "\n");
fwrite(STDERR, "已写入 assets/suggest.json (" . strlen($json) . " 字节)\n");
echo $json, "\n";
