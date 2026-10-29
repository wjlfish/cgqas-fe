<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/ratelimit.php';

require_once __DIR__ . '/lib/geo.php';

// 境外 IP: 不渲染任何功能, 直接给提示页
if (geo_blocked()) {
    geo_blocked_page();
}


$ip = client_ip();
$t  = issue_token($ip);
$esc = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
?><!DOCTYPE html>
<html lang="zh-CN" data-theme="paper">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="csrf-token" content="<?= $esc($t['token']) ?>">
<meta name="theme-color" content="#F5F3ED">
<title>反馈与额度 · 创高题库</title>
<meta name="description" content="创高体育理论考试题库：错题申报、问题反馈">
<meta name="robots" content="noindex, nofollow">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' fill='%23F5F3ED'/><text x='16' y='23' font-size='18' text-anchor='middle' fill='%23B03A2E' font-family='serif'>题</text></svg>">
<link rel="stylesheet" href="/assets/style.css?v=9">
<script>
  (function(){
    try{
      var t = localStorage.getItem('cgtk-theme');
      if (t === 'ink' || t === 'paper') document.documentElement.dataset.theme = t;
      else if (window.matchMedia('(prefers-color-scheme: dark)').matches) document.documentElement.dataset.theme = 'ink';
    }catch(e){}
  })();
</script>
</head>
<body>
<header class="topbar">
  <div class="shell topbar-in">
    <a class="brand" href="/" aria-label="创高题库"><span>创高题库</span><em>SCU</em></a>
    <nav class="nav" aria-label="主导航"><a class="nav-link" href="/">返回检索</a></nav>
    <button class="theme-btn" id="themeBtn" title="切换主题" aria-label="切换主题">
      <svg class="ic-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"
           stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <circle cx="12" cy="12" r="4.2"></circle>
        <path d="M12 2.6v2.2M12 19.2v2.2M2.6 12h2.2M19.2 12h2.2M5.3 5.3l1.6 1.6M17.1 17.1l1.6 1.6M18.7 5.3l-1.6 1.6M6.9 17.1l-1.6 1.6"></path>
      </svg>
      <svg class="ic-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"
           stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M20.5 14.3A8.6 8.6 0 1 1 9.7 3.5a6.9 6.9 0 0 0 10.8 10.8z"></path>
      </svg>
    </button>
  </div>
</header>

<main class="fb-page">
  <div class="shell">
    <div class="view-actions">
      <h1 class="view-title">反馈与额度</h1>
    </div>
    <div id="fbRoot"></div>
  </div>
</main>

<footer class="foot">
  <div class="shell foot-in">
    <p class="foot-meta"><span>题库总计 <b id="footTotal">—</b> 题</span></p>
    <p class="foot-sign">
      <a href="https://github.com/wjlfish" target="_blank" rel="noopener noreferrer">初音过去<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 17L17 7M9 7h8v8"></path></svg></a>
    </p>
  </div>
</footer>

<div class="toast" id="toast" role="status" aria-live="polite"></div>
<script src="/assets/feedback.js?v=9"></script>
<script>
(function () {
  /* 主题 */
  var root = document.documentElement;
  var btn = document.getElementById('themeBtn');
  if (btn) btn.addEventListener('click', function () {
    var next = root.dataset.theme === 'ink' ? 'paper' : 'ink';
    root.dataset.theme = next;
    try { localStorage.setItem('cgtk-theme', next); } catch (e) {}
  });
  if (window.CGFB) window.CGFB.mount(document.getElementById('fbRoot'), 'page');

  /* 题库总数 */
  var tk = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
  fetch('/api.php?action=stats', {
    headers: { Accept: 'application/json', 'X-CG-Token': tk },
    credentials: 'same-origin'
  })
    .then(function (r) { return r.json(); })
    .then(function (j) {
      if (j && j.total) document.getElementById('footTotal').textContent = j.total.toLocaleString();
    })
    .catch(function () {});
})();
</script>
</body>
</html>
