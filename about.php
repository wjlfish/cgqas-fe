<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/ratelimit.php';

define('CG_SITE', true);

require_once __DIR__ . '/lib/geo.php';
require_once __DIR__ . '/lib/crawler.php';
require_once __DIR__ . '/lib/asset.php';

header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');

if (geo_blocked() && !crawler_public_bypass()) {
    geo_blocked_page();
}

$ip  = client_ip();
$t   = issue_token($ip);
$esc = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
?><!DOCTYPE html>
<html lang="zh-CN" data-theme="paper">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
<meta name="csrf-token" content="<?= $esc($t['token']) ?>">
<meta name="theme-color" content="#F5F3ED">
<title>关于本站 · 创高体育理论考试题库</title>
<meta name="description" content="创高题库说明：这是创高体育理论考试的题库检索工具，免费、需手机号验证码登录，可按关键词、科目、题型查题；个人维护的非官方工具，题库仅供平时复习参考。">
<meta name="robots" content="index, follow">
<link rel="canonical" href="https://cgtk.org/about">
<link rel="icon" href="/assets/favicon.ico" sizes="any">
<link rel="stylesheet" href="<?= asset('style.css') ?>">
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@graph": [
    {
      "@type": "FAQPage",
      "@id": "https://cgtk.org/about#faq",
      "mainEntity": [
        {
          "@type": "Question",
          "name": "创高体育理论考试题库是什么？",
          "acceptedAnswer": { "@type": "Answer", "text": "创高体育理论考试是高校体育课常见的线上理论考核形式。本站是其题库的在线检索工具，可按关键词、科目、题型查找题目与标准答案。" }
        },
        {
          "@type": "Question",
          "name": "只有某一个学校的学生能用吗？",
          "acceptedAnswer": { "@type": "Answer", "text": "不是。凡是使用创高体育理论考试系统的高校，题库内容基本相同，均可使用本站检索。" }
        },
        {
          "@type": "Question",
          "name": "本站收费吗？",
          "acceptedAnswer": { "@type": "Answer", "text": "免费。使用手机号验证码登录即可，每个账号初始可检索 110 次，用完可申请提额。" }
        },
        {
          "@type": "Question",
          "name": "题库覆盖哪些题型和科目？",
          "acceptedAnswer": { "@type": "Answer", "text": "题型包含判断、单选、多选、填空、问答；科目覆盖三大球、小球、武术、健美操、游泳、田径等公共体育课程。" }
        },
        {
          "@type": "Question",
          "name": "发现题目或答案有误怎么办？",
          "acceptedAnswer": { "@type": "Answer", "text": "可在页面右下角「反馈」中申报，核实修正后会返还本次检索额度。" }
        },
        {
          "@type": "Question",
          "name": "使用本站需要登录吗？",
          "acceptedAnswer": { "@type": "Answer", "text": "查题需要登录，用手机号接收验证码即可，未注册会自动创建账号。本站的说明、指南和复习建议页面不需要登录。" }
        },
        {
          "@type": "Question",
          "name": "创高题库是官方工具吗？",
          "acceptedAnswer": { "@type": "Answer", "text": "不是。本站是个人维护的非官方工具，与创高体育官方无关，题库和答案仅供平时复习参考。" }
        }
      ]
    },
    {
      "@type": "WebSite",
      "@id": "https://cgtk.org/#website",
      "name": "创高题库",
      "alternateName": "创高体育理论考试题库",
      "url": "https://cgtk.org/",
      "inLanguage": "zh-CN"
    },
    {
      "@type": "BreadcrumbList",
      "@id": "https://cgtk.org/about#breadcrumb",
      "itemListElement": [
        {"@type": "ListItem", "position": 1, "name": "首页", "item": "https://cgtk.org/"},
        {"@type": "ListItem", "position": 2, "name": "关于本站", "item": "https://cgtk.org/about"}
      ]
    }
  ]
}
</script>
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
      <a class="nav-link is-active" href="/about" aria-current="page">关于</a>
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
      <a href="/">首页</a><span class="sep">/</span><span aria-current="page">关于本站</span>
    </nav>
    <div class="view-actions">
      <h1 class="view-title">关于本站</h1>
    </div>

    <section class="about about--page">
      <h2 class="about-h2">创高体育理论考试题库检索</h2>
      <p class="about-lead">创高体育理论考试是高校体育课常见的线上理论考核。本站把常见题目与标准答案整理成可检索的题库，支持按关键词、科目、题型查找，用于平时复习时核对概念和表述。凡是使用创高体育理论考试系统的高校均可使用；本站是个人维护的非官方工具，与创高体育官方无关。</p>

      <div class="about-grid">
        <div>
          <h2 class="about-h2">怎么用</h2>
          <p>用手机号验证码登录（未注册自动建号），首次进入选择自己的体育课科目；输入题干或答案里的关键词（至少 2 个字）检索，可再叠加科目与题型筛选。结果给出题目与标准答案。</p>
        </div>
        <div>
          <h2 class="about-h2">内容范围</h2>
          <p>题型含判断、单选、多选、填空、问答；科目覆盖篮球、排球、足球、乒乓球、羽毛球、网球、武术、健美操、游泳、田径等公共体育课程。</p>
        </div>
        <div>
          <h2 class="about-h2">登录与费用</h2>
          <p>查题需要登录，免费使用；每个账号初始 110 次检索额度，每检索一次消耗一次。额度用完可在反馈页说明用途申请提额，由后台人工审批。</p>
        </div>
        <div>
          <h2 class="about-h2">科目范围</h2>
          <p>检索范围是公共题库加上你选的科目。科目可以更换，但次数有限；换科目后原科目专属题目不再出现在结果里。</p>
        </div>
        <div>
          <h2 class="about-h2">反馈与纠错</h2>
          <p>登录后可在「反馈」提交错题申报，核实修正后返还本次检索额度；Bug 反馈与留言无需登录，但需要完成人机验证。</p>
        </div>
        <div>
          <h2 class="about-h2">使用边界</h2>
          <p>题库与答案仅供平时复习参考，不保证等于考试原题，也可能存在录入错误；遇到疑问以教材和老师讲解为准。请勿在正式考试中使用。</p>
        </div>
      </div>

      <h2 class="about-faq-title">常见问题</h2>
      <dl class="about-faq">
        <dt>这个网站是做什么的？</dt>
        <dd>创高体育理论考试的题库检索工具，用于平时复习时快速找到题目与答案。</dd>
        <dt>只有某个学校的学生能用吗？</dt>
        <dd>不是。创高体育系统在多所高校通用，题库内容基本相同，任何使用该系统的高校学生都可以用；具体考试范围以各校老师的要求为准。</dd>
        <dt>收费吗？</dt>
        <dd>免费。手机号验证码登录，初始 110 次检索额度，用完可申请提额。</dd>
        <dt>使用本站需要登录吗？</dt>
        <dd>查题需要登录；本站的说明、使用指南和复习建议页面不需要登录。</dd>
        <dt>题库覆盖哪些题型和科目？</dt>
        <dd>题型包含判断、单选、多选、填空、问答；科目覆盖三大球、小球、武术、健美操、游泳、田径等公共体育课程。</dd>
        <dt>发现题目或答案有误怎么办？</dt>
        <dd>登录后在页面右下角「反馈」中提交错题申报，核实修正后会返还本次检索额度。</dd>
        <dt>创高题库是官方工具吗？</dt>
        <dd>不是。本站是个人维护的非官方工具，与创高体育官方无关。</dd>
        <dt>会不会影响考试的公平性？</dt>
        <dd>本站定位是复习工具，请在平时复习时使用；正式考试请独立完成。</dd>
      </dl>

      <p class="foot-meta" style="margin-top:22px">
        延伸阅读：<a href="/guide">题库使用指南</a> ·
        <a href="/review">创高体育理论考试怎么复习</a> ·
        <a href="/feedback">问题反馈</a>
      </p>
    </section>
  </div>
</main>

<footer class="foot">
  <div class="shell foot-in">
    
    <p class="foot-meta"><span>最后更新 <b>2026-11-06</b></span></p>
    <p class="foot-sign">
      <a href="/">检索</a>
      <span class="dot">·</span>
      <a href="/guide">使用指南</a>
      <span class="dot">·</span>
      <a href="/review">复习建议</a>
      <span class="dot">·</span>
      <a href="/feedback">问题反馈</a>
      <span class="dot">·</span>
      <a href="https://github.com/wjlfish" target="_blank" rel="noopener noreferrer">初音过去<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 17L17 7M9 7h8v8"></path></svg></a>
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
