<?php
declare(strict_types=1);
/**
 * 公开说明页：创高体育理论考试怎么复习
 * 只写与现有功能一致的复习方法，不虚构功能。
 */
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
<title>创高体育理论考试怎么复习 · 分题型复习与查漏补缺</title>
<meta name="description" content="创高体育理论考试的复习思路：按自己的体育课科目确定范围，按判断、单选、多选、填空、问答分题型过一遍，再用题库检索查漏补缺、整理错题。">
<meta name="robots" content="index, follow">
<link rel="canonical" href="https://cgtk.org/review">
<link rel="icon" href="/assets/favicon.ico" sizes="any">
<link rel="stylesheet" href="<?= asset('style.css') ?>">
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@graph": [
    {
      "@type": "FAQPage",
      "@id": "https://cgtk.org/review#faq",
      "mainEntity": [
        {
          "@type": "Question",
          "name": "创高体育理论考试一般考什么题型？",
          "acceptedAnswer": { "@type": "Answer", "text": "常见题型有判断、单选、多选、填空和问答。不同学校、不同学期的组卷会有差异，请以老师给出的考试说明为准。" }
        },
        {
          "@type": "Question",
          "name": "复习时应该先看题库还是先看教材？",
          "acceptedAnswer": { "@type": "Answer", "text": "建议先按自己的体育课科目把教材里的基本概念过一遍，再用题库检索核对表述；只看答案不看概念，遇到变体题目容易答错。" }
        },
        {
          "@type": "Question",
          "name": "题库答案能直接背吗？",
          "acceptedAnswer": { "@type": "Answer", "text": "题库适合用来核对概念和熟悉题型，不建议只背答案。遇到答案有疑问时，以教材和老师讲解为准，也可以在站内提交错题申报。" }
        },
        {
          "@type": "Question",
          "name": "临近考试怎么安排复习？",
          "acceptedAnswer": { "@type": "Answer", "text": "考前两到三周按科目完整过一遍，考前一周按题型集中复习并整理错题，考前两三天只看错题和易混淆的概念。" }
        }
      ]
    },
    {
      "@type": "Article",
      "@id": "https://cgtk.org/review#article",
      "headline": "创高体育理论考试怎么复习",
      "about": "高校体育理论考试的复习方法与题库使用建议",
      "inLanguage": "zh-CN",
      "mainEntityOfPage": "https://cgtk.org/review"
    },
    {
      "@type": "BreadcrumbList",
      "@id": "https://cgtk.org/review#breadcrumb",
      "itemListElement": [
        {"@type": "ListItem", "position": 1, "name": "首页", "item": "https://cgtk.org/"},
        {"@type": "ListItem", "position": 2, "name": "复习建议", "item": "https://cgtk.org/review"}
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
      <a class="nav-link is-active" href="/review" aria-current="page">复习</a>
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
      <a href="/">首页</a><span class="sep">/</span><span aria-current="page">复习建议</span>
    </nav>
    <div class="view-actions">
      <h1 class="view-title">创高体育理论考试怎么复习</h1>
    </div>

    <section class="about about--page">
      <p class="about-lead">创高体育理论考试是高校体育课常见的线上理论考核，内容以体育常识、项目规则和课程理论为主。下面是一套按现有题库结构和常见题型整理的复习思路，适合考前两三周开始动手。</p>

      <h2 class="about-h2">一、先确认范围和题型</h2>
      <p>先看老师给的考试说明：考哪本教材、哪些章节、有哪些题型。创高题库里常见题型是判断、单选、多选、填空和问答；不同学校、不同学期的组卷会有差异，题库只是复习参考，不等于原题。</p>

      <h2 class="about-h2">二、按自己的体育课科目过一遍</h2>
      <p>在题库里先选自己的体育课科目，检索范围是公共题库加上这门科目。第一轮不要追求记住全部答案，先把概念和术语认全：比如三大球的规则、田径项目的判定、武术和健美操的基本动作名称。遇到完全陌生的词，回到教材对应章节看上下文。</p>

      <h2 class="about-h2">三、分题型练，重点在易混淆概念</h2>
      <div class="about-grid">
        <div>
          <h2 class="about-h2">判断 / 单选</h2>
          <p>这两类多考定义和规则边界。复习时把「一定」「必须」「只能」这类绝对表述和例外情况单独记下来，判断题最容易在这里设坑。</p>
        </div>
        <div>
          <h2 class="about-h2">多选</h2>
          <p>多选考的是完整性。可以按知识点把相关条目成组记忆，比如某项目的违例类型、某类运动的评分要素，避免只记住其中一两条。</p>
        </div>
        <div>
          <h2 class="about-h2">填空</h2>
          <p>填空常考专有名词、人名、地名和数字。把教材里的加粗词和专业术语抄一遍，比反复看答案有效。</p>
        </div>
        <div>
          <h2 class="about-h2">问答</h2>
          <p>问答需要组织语言。先用自己的话把要点说一遍，再对照答案看漏了哪几点；不用逐字背，但关键词要齐全。</p>
        </div>
      </div>

      <h2 class="about-h2">四、用检索查漏补缺</h2>
      <p>教材看完一章后，用题库检索这一章的关键词，检验自己能不能认出题目对应的知识点。检索时关键词取 2—4 个字最有效，比如「拦网」「越位」「奥林匹克精神」；范围太大的词会得到很多无关结果。看到不会的题先标记，不要立刻看答案，回头统一核对。</p>

      <h2 class="about-h2">五、整理错题，别重复踩坑</h2>
      <p>把做错或拿不准的题按「概念没记住」「规则记混」「表述不完整」分类记下来，考前只看这份清单。如果发现题库里的题目或答案本身有问题，可以在站内提交错题申报，核实修正后会返还本次检索额度。</p>

      <h2 class="about-h2">六、时间安排参考</h2>
      <p>考前两到三周：按科目完整过一遍教材和题库；考前一周：按题型集中复习，整理错题；考前两三天：只过错题和易混淆概念，不再开新内容。每天固定一段短时间比考前突击更稳。</p>

      <h2 class="about-h2">七、几条提醒</h2>
      <dl class="about-faq">
        <dt>题库答案能保证正确吗？</dt>
        <dd>不能保证。题库来自公开资料和同学反馈，可能存在录入错误；遇到疑问以教材和老师讲解为准。</dd>
        <dt>可以只背题库答案吗？</dt>
        <dd>不建议。考试题目会有变化，理解概念才能应对变体；题库更适合用来核对表述和熟悉题型。</dd>
        <dt>正式考试能用吗？</dt>
        <dd>不能。本站定位是平时复习工具，正式考试请独立完成，遵守学校的考试纪律。</dd>
        <dt>本站和创高体育官方有关系吗？</dt>
        <dd>没有。本站是个人维护的非官方工具，与创高体育官方无关。</dd>
      </dl>

      <p class="foot-meta" style="margin-top:26px">
        继续阅读：<a href="/guide">题库使用指南</a> ·
        <a href="/about">关于本站</a> ·
        <a href="/feedback">错题申报与提额</a>
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
      <a href="/feedback">问题反馈</a>
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
