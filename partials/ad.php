<?php
/** 顶部广告位 · 直接访问该文件时不做任何输出 */
if (!defined('CG_SITE')) {
    http_response_code(404);
    exit;
}

// 每块广告: key 对应 go.php 的 AD_TARGETS
$ADS = [
    [
        'to'   => 'skyrouter',
        'main' => 'Skyrouter · GPT/Claude 不降智、价格低',
        'sub'  => '注册免费送3元，联系客服免费远程接入',
    ],
    [
        'to'   => 'qiyun',
        'main' => 'Claude 老封号？GPT 订阅贵还降智？',
        'sub'  => '中转站国内直连 · 一个 Key 用遍 DeepSeek / GPT-6 Astra / Claude Fable / Opus',
    ],
];
?>
<div class="z7q4">
  <?php foreach ($ADS as $ad): ?>
    <a class="m3x9" href="/go.php?to=<?= htmlspecialchars($ad['to'], ENT_QUOTES) ?>"
       target="_blank" rel="noopener noreferrer nofollow sponsored">
      <span class="p8v2">
        <span class="k5r1">广告</span>
        <span class="w2n6"><?= htmlspecialchars($ad['main'], ENT_QUOTES) ?></span>
        <span class="h9t3">免费注册
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"
               stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M5 12h14M13 6l6 6-6 6"></path>
          </svg>
        </span>
      </span>
      <span class="d4j8"><?= htmlspecialchars($ad['sub'], ENT_QUOTES) ?></span>
    </a>
  <?php endforeach; ?>
</div>
