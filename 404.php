<?php
declare(strict_types=1);
/**
 * 404 页面：站内导航 + 回到检索，给用户和爬虫一个有效的落点
 * 由 nginx error_page 内部跳转，状态码保持 404。
 */
require_once __DIR__ . '/lib/asset.php';

http_response_code(404);
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
header('X-Robots-Tag: noindex');

$esc = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
?><!DOCTYPE html>
<html lang="zh-CN" data-theme="paper">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#F5F3ED">
<title>页面不存在 · 创高题库</title>
<meta name="robots" content="noindex, follow">
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

<main class="fb-page">
  <div class="shell">
    <nav class="crumb" aria-label="面包屑">
      <a href="/">首页</a><span class="sep">/</span><span aria-current="page">页面不存在</span>
    </nav>
    <h1 class="view-title">页面不存在</h1>
    <section class="about about--page">
      <p class="about-lead">你访问的链接不存在，或者这个页面已经移动。可以回到检索页继续搜题，或者看看使用指南和复习建议。</p>
      <p class="home-intro-links">
        <a href="/">返回检索</a>
        <a href="/guide">使用指南</a>
        <a href="/review">复习建议</a>
        <a href="/about">关于本站</a>
        <a href="/feedback">问题反馈</a>
      </p>
    </section>
  </div>
</main>

<footer class="foot">
  <div class="shell foot-in">
    <p class="foot-meta"><span>创高题库 · 个人维护的非官方工具</span></p>
    <p class="foot-sign">
      <a href="/">检索</a>
      <span class="dot">·</span>
      <a href="/guide">使用指南</a>
      <span class="dot">·</span>
      <a href="/about">关于</a>
    </p>
  </div>
</footer>

<script>
(function () {
  var root = document.documentElement;
  var btn = document.getElementById('themeBtn');
  if (btn) btn.addEventListener('click', function () {
    var next = root.dataset.theme === 'ink' ? 'paper' : 'ink';
    root.dataset.theme = next;
    try { localStorage.setItem('cgtk-theme', next); } catch (e) {}
  });
})();
</script>
</body>
</html>
