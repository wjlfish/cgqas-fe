<?php
/** 顶部广告位 · 直接访问该文件时不做任何输出 */
if (!defined('CG_SITE')) {
    http_response_code(404);
    exit;
}

// 经 /go.php 跳转, 便于统计点击(同一 IP 10 分钟只记一次)
// 推广码 XTCF2MLXZHZB (需站主开启 affiliate_enabled 才会记录归属)
$AD_URL   = 'https://oc.qiyun.site/register?aff=XTCF2MLXZHZB';
$AD_LABEL = 'api.qiyun.site';
?>
<a class="adtop" href="/go.php?to=qiyun" target="_blank" rel="noopener noreferrer nofollow sponsored"
   aria-label="广告：企云 API 中转站">
  <span class="adtop-tag">广告</span>
  <span class="adtop-main">Claude 老封号？GPT 订阅贵还降智？</span>
  <span class="adtop-sub">中转站国内直连：不用梯子，一个 Key 用遍 DeepSeek / GPT-6 Astra / Claude CC Max</span>
  <span class="adtop-go"><?= htmlspecialchars($AD_LABEL, ENT_QUOTES) ?><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
     stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 17L17 7M9 7h8v8"></path></svg></span>
</a>
