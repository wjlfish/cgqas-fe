<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/ratelimit.php';

define('CG_SITE', true);

require_once __DIR__ . '/lib/geo.php';
require_once __DIR__ . '/lib/crawler.php';
require_once __DIR__ . '/lib/asset.php';
require_once __DIR__ . '/lib/tcaptcha.php';

// 页面不加缓存, 避免 WebView/浏览器读到旧版(比如还没广告的版本)
header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');

// 境外 IP: 不渲染任何功能, 直接给提示页; 只放行验证过的搜索引擎/AI 爬虫
if (geo_blocked() && !crawler_public_bypass()) {
    geo_blocked_page();
}


$tcOn  = tcaptcha_enabled();
$tcCfg = $tcOn ? tcaptcha_cfg() : [];
$ip = client_ip();
$t  = issue_token($ip);
$esc = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
?><!DOCTYPE html>
<html lang="zh-CN" data-theme="paper">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
<meta name="csrf-token" content="<?= $esc($t['token']) ?>">
<meta name="theme-color" content="#F5F3ED">
<title>问题反馈与额度申请 · 创高题库</title>
<meta name="description" content="创高题库的错题申报、问题反馈与额度申请入口。">
<meta name="robots" content="noindex, follow">
<link rel="canonical" href="https://cgtk.org/feedback">
<link rel="icon" href="/assets/favicon.ico" sizes="any">
<link rel="stylesheet" href="<?= asset('style.css') ?>">
<script>
  (function(){
    try{
      var t = localStorage.getItem('cgtk-theme');
      if (t === 'ink' || t === 'paper') document.documentElement.dataset.theme = t;
      else if (window.matchMedia('(prefers-color-scheme: dark)').matches) document.documentElement.dataset.theme = 'ink';
    }catch(e){}
  })();
</script>
<?php if ($tcOn): ?>
<script src="https://turing.captcha.qcloud.com/TJCaptcha.js"></script>
<script>window.__CG_TCAPTCHA = { appId: <?= json_encode((string) $tcCfg['app_id']) ?> };</script>
<?php endif; ?>
</head>
<body>
<header class="topbar">
  <div class="shell topbar-in">
    <a class="brand" href="/" aria-label="创高题库"><span>创高题库</span></a>
    <nav class="nav" aria-label="主导航">
      <a class="nav-link nav-link--ext" href="/">检索</a>
      <a class="nav-link nav-link--ext" href="/guide">指南</a>
      <a class="nav-link nav-link--ext" href="/review">复习</a>
      <a class="nav-link nav-link--ext" href="/about">关于</a>
    </nav>
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

<?php require __DIR__ . '/partials/ad.php'; ?>

<main class="fb-page">
  <div class="shell">
    <nav class="crumb" aria-label="面包屑">
      <a href="/">首页</a><span class="sep">/</span><span aria-current="page">问题反馈</span>
    </nav>
    <div class="view-actions">
      <h1 class="view-title">反馈与额度</h1>
    </div>
    <div id="fbRoot"></div>
  </div>
</main>

<footer class="foot">
  <div class="shell foot-in">
    <p class="foot-sign">
      <a href="/">检索</a>
      <span class="dot">·</span>
      <a href="/about">关于</a>
      <span class="dot">·</span>
      <a href="/guide">使用指南</a>
      <span class="dot">·</span>
      <a href="/review">复习建议</a>
      <span class="dot">·</span>
      <a href="https://github.com/wjlfish" target="_blank" rel="noopener noreferrer">初音过去<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 17L17 7M9 7h8v8"></path></svg></a>
    </p>
  </div>
</footer>

<div class="toast" id="toast" role="status" aria-live="polite"></div>
<script src="<?= asset('feedback.js') ?>"></script>
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
  })();
</script>
</body>
</html>
