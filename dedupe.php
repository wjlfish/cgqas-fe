<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/admin.php';

if (($_GET['logout'] ?? '') === '1') {
    admin_logout();
    header('Location: /dedupe.php');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pass'])) {
    if (admin_login((string) $_POST['pass'])) {
        header('Location: /dedupe.php');
        exit;
    }
    $err = '口令错误';
}

if (!admin_logged_in()) {
    ?><!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>去重审阅 · 登录</title><link rel="stylesheet" href="/assets/style.css?v=1">
    <style>
    .login{max-width:340px;margin:16vh auto 0;text-align:center}
    .login h2{
      font-family:"Songti SC","Source Han Serif SC",STSong,SimSun,serif;
      font-size:28px;font-weight:500;margin:0 0 6px;
    }
    .login p{margin:0 0 26px;font-size:12.5px;letter-spacing:.14em;color:var(--ink-3)}
    .login input{
      width:100%;height:44px;padding:0 2px;border:0;border-bottom:1px solid var(--ink);
      background:none;color:var(--ink);font-size:17px;outline:none;text-align:center;
      letter-spacing:.1em;
    }
    .login input::placeholder{color:var(--ink-3);letter-spacing:.06em;font-size:14px}
    .login .btn{width:100%;height:44px;margin-top:26px}
    .login .err{color:var(--seal);font-size:12.5px;margin-top:12px;letter-spacing:.06em}
  </style></head><body>
    <div class="login"><h2>题库去重审阅</h2><p class="sub">需要管理口令</p>
    <form method="post"><input type="password" name="pass" placeholder="管理口令" autofocus>
    <?php if (!empty($err)): ?><p class="err"><?= htmlspecialchars($err) ?></p><?php endif; ?>
    <button class="btn primary" type="submit">进入</button></form></div></body></html><?php
    exit;
}

$pdo = db();
$cat = $_GET['cat'] ?? 'near_diff';
$VALID = ['near_diff', 'near_same', 'similar', 'prefix_only', 'legacy_overlap'];
if (!in_array($cat, $VALID, true)) {
    $cat = 'near_diff';
}
$page = max(0, (int) ($_GET['page'] ?? 0));
$LIM  = 10;

$cnt = [];
$sql = "SELECT p.category,
               COUNT(*) AS total,
               SUM(d.id IS NULL) AS undecided
        FROM dedupe_pairs p
        JOIN questions qa ON qa.id = p.a_id
        JOIN questions qb ON qb.id = p.b_id
        LEFT JOIN dedupe_decisions d ON d.pair_key = p.pair_key
        GROUP BY p.category";
foreach ($pdo->query($sql) as $r) {
    $cnt[$r['category']] = ['total' => (int) $r['total'], 'undecided' => (int) $r['undecided']];
}
$CATNAME = [
    'near_diff'      => '① 近同题·答案不同',
    'near_same'      => '② 近同题·答案相同',
    'prefix_only'    => '③ 仅科目前缀不同',
    'similar'        => '④ 内容相近',
    'legacy_overlap' => '⑤ 旧库重复(2026已有)',
];

$st = $pdo->prepare(
    "SELECT p.pair_key, p.a_id, p.b_id, p.j_full, p.j_strip, p.ans_same,
            qa.stem sa_stem, qa.answer sa_ans, qa.type sa_type, qa.source sa_src,
            qb.stem sb_stem, qb.answer sb_ans, qb.type sb_type, qb.source sb_src,
            na.name sa_subj, nb.name sb_subj
     FROM dedupe_pairs p
     JOIN questions qa ON qa.id = p.a_id
     JOIN questions qb ON qb.id = p.b_id
     LEFT JOIN subjects na ON na.id = qa.subject_id
     LEFT JOIN subjects nb ON nb.id = qb.subject_id
     LEFT JOIN dedupe_decisions d ON d.pair_key = p.pair_key
     WHERE d.id IS NULL AND p.category = ?
     ORDER BY p.j_full DESC, p.id ASC
     LIMIT $LIM OFFSET " . ($page * $LIM)
);
$st->execute([$cat]);
$rows = $st->fetchAll();

$undec = $cnt[$cat]['undecided'] ?? 0;
$total = $cnt[$cat]['total'] ?? 0;
$pages = max(1, (int) ceil($undec / $LIM));
$esc = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>题库去重审阅</title>
<link rel="stylesheet" href="/assets/style.css?v=1">
<style>
    .adm{padding:10px 0 70px}
    .adm h1{
      font-family:"Songti SC","Source Han Serif SC",STSong,SimSun,serif;
      font-size:clamp(26px,3.4vw,40px);font-weight:500;margin:44px 0 10px;letter-spacing:-.01em;
    }
    .cats{display:flex;gap:10px 24px;flex-wrap:wrap;margin:26px 0 10px;
      padding-bottom:12px;border-bottom:1px solid var(--rule)}
    .cats a{font-size:13px;color:var(--ink-3);padding:3px 0;
      border-bottom:1px solid transparent;transition:.24s var(--ease)}
    .cats a.on{color:var(--ink);border-bottom-color:var(--seal)}
    .cats a b{color:var(--seal);margin-left:5px;font-weight:600;font-variant-numeric:tabular-nums}
    .pair{border-bottom:1px solid var(--rule-soft);padding:clamp(24px,3vw,36px) 0}
    .pair .head{display:flex;justify-content:space-between;align-items:baseline;gap:10px;
      flex-wrap:wrap;margin-bottom:16px;color:var(--ink-3);font-size:11.5px;letter-spacing:.12em}
    .pair .head .sim{color:var(--seal);font-weight:700;letter-spacing:0}
    .cols{display:grid;grid-template-columns:1fr 1fr;gap:clamp(14px,3vw,34px)}
    .col{padding:0}
    .col .id{color:var(--ink-3);font-size:10.5px;letter-spacing:.16em;
      text-transform:uppercase;margin-bottom:10px}
    .col .st{font-family:"Songti SC","Source Han Serif SC",STSong,SimSun,serif;
      font-size:16.5px;line-height:1.72;color:var(--ink)}
    .col .an{margin-top:12px;font-size:13px;color:var(--seal);font-weight:600;letter-spacing:.06em}
    .col .meta{color:var(--ink-3);font-size:11.5px;margin-top:6px;letter-spacing:.08em}
    .acts{display:flex;gap:10px;margin-top:18px;flex-wrap:wrap}
    .acts button{height:34px;padding:0 16px;border:1px solid var(--rule);
      background:none;color:var(--ink-2);font-size:12.5px;letter-spacing:.08em;
      transition:.24s var(--ease)}
    .acts button:hover{border-color:var(--ink);color:var(--ink)}
    .acts button.todel{border-color:var(--seal);color:var(--seal)}
    .acts button.todel:hover{background:var(--seal);color:var(--paper)}
    .pbar{height:1px;background:var(--rule);overflow:hidden;margin:8px 0 16px;position:relative}
    .pbar i{display:block;height:2px;background:var(--seal);position:relative;top:-.5px}
    .done{text-align:center;color:var(--ink-3);padding:70px 0;font-size:14px}
    .hint{color:var(--ink-3);font-size:12.5px;margin:6px 0 0}
    @media(max-width:720px){.cols{grid-template-columns:1fr;gap:18px}}
  </style>
</head>
<body>
<header class="masthead"><div class="shell">
  <a class="mark" href="/"><span>题库去重</span><span class="dot">·</span><em>审阅</em></a>
  <nav class="nav">
    <a class="nav-link" href="/">返回站点</a>
    <a class="nav-link" href="/dedupe.php?logout=1">退出</a>
  </nav>
</div></header>

<main class="shell adm">
  <h1>重复题审阅</h1>
  <p class="hint">按综合相似度从高到低排列。删除会同时把被删题的科目归属并入保留题。</p>

  <div class="cats">
    <?php foreach ($CATNAME as $k => $label): $u = $cnt[$k]['undecided'] ?? 0; ?>
      <a class="<?= $k === $cat ? 'on' : '' ?>" href="/dedupe.php?cat=<?= $k ?>">
        <?= $esc($label) ?><?= $u > 0 ? "<b>$u</b>" : ' ✓' ?>
      </a>
    <?php endforeach; ?>
  </div>

  <div class="pbar"><i style="width:<?= $total ? round((1 - $undec / max(1, $total)) * 100) : 100 ?>%"></i></div>
  <p class="hint">当前分类：已处理 <?= $total - $undec ?> / <?= $total ?>，待处理 <?= $undec ?> 对</p>

  <?php if ($cat === 'legacy_overlap'): ?>
    <div class="pair" style="border-color:rgba(255,176,32,.4)">
      <div class="head"><span>批量处理</span></div>
      <p class="hint" style="margin:0 0 12px">旧库数据是 2019/2020 年扒的，答案不可靠；2026 已有对应题目时建议整批删除。</p>
      <div class="acts">
        <button id="bulk-legacy" class="todel">删除全部「旧库重复」题（保留 2026 版）</button>
      </div>
    </div>
  <?php endif; ?>

  <?php if (!$rows): ?>
    <div class="done">🎉 这个分类已经全部处理完了</div>
  <?php endif; ?>

  <?php foreach ($rows as $r): ?>
    <div class="pair" data-key="<?= $esc($r['pair_key']) ?>" data-a="<?= (int) $r['a_id'] ?>" data-b="<?= (int) $r['b_id'] ?>">
      <div class="head">
        <span>题干相似度 <span class="sim"><?= round((float) $r['j_full'] * 100, 1) ?>%</span>
          <?php if ((float) $r['j_strip'] > (float) $r['j_full'] + 0.02): ?>
            · 去科目前缀 <?= round((float) $r['j_strip'] * 100, 1) ?>%
          <?php endif; ?>
          · 答案<?= $r['ans_same'] ? '<span style="color:#7bf0b0">相同</span>' : '<span style="color:#ff9aa3">不同</span>' ?></span>
        <span>#<?= (int) $r['a_id'] ?> vs #<?= (int) $r['b_id'] ?></span>
      </div>
      <div class="cols">
        <div class="col">
          <div class="id">A · #<?= (int) $r['a_id'] ?> · <?= $esc($r['sa_type']) ?>
            <?= $r['sa_src'] === 'legacy' ? '· 旧库' : '' ?></div>
          <div class="st"><?= $esc($r['sa_stem']) ?></div>
          <div class="an">答案：<?= $esc($r['sa_ans']) ?></div>
          <div class="meta"><?= $esc($r['sa_subj'] ?? '') ?></div>
        </div>
        <div class="col">
          <div class="id">B · #<?= (int) $r['b_id'] ?> · <?= $esc($r['sb_type']) ?>
            <?= $r['sb_src'] === 'legacy' ? '· 旧库' : '' ?></div>
          <div class="st"><?= $esc($r['sb_stem']) ?></div>
          <div class="an">答案：<?= $esc($r['sb_ans']) ?></div>
          <div class="meta"><?= $esc($r['sb_subj'] ?? '') ?></div>
        </div>
      </div>
      <div class="acts">
        <button class="tokeep" data-act="keep_a">保留 A 删除 B</button>
        <button class="tokeep" data-act="keep_b">保留 B 删除 A</button>
        <button data-act="both">两者都保留（不是重复）</button>
      </div>
    </div>
  <?php endforeach; ?>

  <?php if ($pages > 1 && $rows): ?>
    <div class="pager">
      <?php for ($i = 0; $i < min($pages, 12); $i++): ?>
        <a href="/dedupe.php?cat=<?= $cat ?>&page=<?= $i ?>" style="text-decoration:none">
          <button class="<?= $i === $page ? 'on' : '' ?>"><?= $i + 1 ?></button></a>
      <?php endfor; ?>
      <span style="color:#6b7a9e;font-size:13px;align-self:center">共 <?= $pages ?> 页</span>
    </div>
  <?php endif; ?>
</main>

<div class="toast" id="toast" hidden></div>
<script>
(function(){
  const toast = (m)=>{const t=document.getElementById('toast');t.textContent=m;
    t.classList.add('is-on');
    clearTimeout(window._tt);window._tt=setTimeout(()=>t.classList.remove('is-on'),2200);};
  document.querySelectorAll('.pair').forEach(card=>{
    card.querySelectorAll('[data-act]').forEach(btn=>{
      btn.addEventListener('click', async ()=>{
        const act=btn.dataset.act;
        btn.disabled=true;
        try{
          const fd=new FormData();
          fd.append('action','decide');
          fd.append('pair_key',card.dataset.key);
          fd.append('a',card.dataset.a);
          fd.append('b',card.dataset.b);
          fd.append('choice',act);
          const r=await fetch('/dedupe_api.php',{method:'POST',body:fd,credentials:'same-origin'});
          const j=await r.json();
          if(!j.ok) throw new Error(j.error||'失败');
          card.style.transition='opacity .2s';card.style.opacity='0';
          setTimeout(()=>{card.remove(); if(!document.querySelector('.pair')) location.reload();},200);
          toast(j.deleted?('已删除 #'+j.deleted):'已标记保留');
        }catch(e){ toast(e.message); btn.disabled=false; }
      });
    });
  });

  const bulk=document.getElementById('bulk-legacy');
  if(bulk){
    bulk.addEventListener('click', async ()=>{
      if(!confirm('确定删除全部与 2026 重复的旧库题？此操作不可撤销。')) return;
      bulk.disabled=true; bulk.textContent='处理中…';
      try{
        const fd=new FormData(); fd.append('action','bulk_legacy');
        const r=await fetch('/dedupe_api.php',{method:'POST',body:fd,credentials:'same-origin'});
        const j=await r.json();
        if(!j.ok) throw new Error(j.error||'失败');
        toast('已删除 '+j.deleted+' 条旧库题');
        setTimeout(()=>location.reload(),900);
      }catch(e){ toast(e.message); bulk.disabled=false; bulk.textContent='重试'; }
    });
  }
})();
</script>
</body>
</html>
