<?php
declare(strict_types=1);
/**
 * 静态资源引用：按内容哈希取文件名，改内容就自动换名，不依赖缓存头
 * manifest 由 ops/build-assets.php 生成
 */
function asset(string $file): string
{
    static $manifest = null;
    if ($manifest === null) {
        $p = dirname(__DIR__) . '/assets/manifest.json';
        $manifest = [];
        if (@is_readable($p)) {
            $j = json_decode((string) @file_get_contents($p), true);
            if (is_array($j)) {
                $manifest = $j;
            }
        }
    }
    $hashed = $manifest[$file] ?? null;
    return '/assets/' . (is_string($hashed) && $hashed !== '' ? $hashed : $file);
}
