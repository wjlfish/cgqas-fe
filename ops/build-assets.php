<?php
declare(strict_types=1);
/**
 * 为静态资源生成内容哈希文件名并写 manifest.json
 * 用法: /www/server/php/81/bin/php ops/build-assets.php
 */
$root = dirname(__DIR__);
$assets = $root . '/assets';
$files = ['style.css', 'app.js', 'feedback.js'];
$map = [];

// 清理历史哈希副本
foreach (glob($assets . '/*') as $f) {
    if (preg_match('/\.[0-9a-f]{8}\.(css|js)$/', basename($f))) {
        @unlink($f);
    }
}

foreach ($files as $name) {
    $src = $assets . '/' . $name;
    if (!is_file($src)) {
        continue;
    }
    $hash = substr(sha1_file($src), 0, 8);
    $out = preg_replace('/\.([a-z0-9]+)$/i', '.' . $hash . '.$1', $name);
    if (!@copy($src, $assets . '/' . $out)) {
        fwrite(STDERR, "copy failed: $name\n");
        continue;
    }
    $map[$name] = $out;
}

file_put_contents($assets . '/manifest.json', json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
echo json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
