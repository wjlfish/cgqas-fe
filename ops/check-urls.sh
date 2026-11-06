#!/bin/bash
# 部署前自检: 前端调用的每个接口动作都必须存在于 api.php, 且不得出现旧路径。
# 用法: bash ops/check-urls.sh [项目根目录]
set -u
ROOT="${1:-$(cd "$(dirname "$0")/.." && pwd)}"
fail=0

echo "== 1) 检查旧的接口/页面路径 =="
LEGACY=$(grep -rnE "/api\.php|/go\.php|href=\"/(about|feedback|index)\.php|action=[a-z_]+" \
  "$ROOT/assets" "$ROOT"/*.php 2>/dev/null \
  | grep -vE "api\.php:[0-9]+: \*|^\S+: *//" || true)
if [ -n "$LEGACY" ]; then
  echo "  ✗ 发现旧路径:"
  echo "$LEGACY" | sed 's/^/    /' | head -20
  fail=1
else
  echo "  ✓ 无旧路径"
fi

echo "== 2) 检查前端调用的动作是否都在 api.php 中实现 =="
ACTIONS=$(grep -ohE "(request|api)\('[a-z_]+'" "$ROOT/assets"/*.js 2>/dev/null \
  | sed -E "s/.*'(.*)'/\1/" | sort -u)
MISSING=""
for a in $ACTIONS; do
  if ! grep -qE "case '$a':" "$ROOT/api.php"; then
    MISSING="$MISSING $a"
  fi
done
if [ -n "$MISSING" ]; then
  echo "  ✗ api.php 中缺少这些动作:$MISSING"
  fail=1
else
  echo "  ✓ 全部动作已实现 ($(echo "$ACTIONS" | wc -l | tr -d ' ') 个)"
fi

echo "== 3) 检查 GET 查询串是否用 ? 拼接 =="
BADQS=$(grep -rnE "url \+= '&'|url \+= \"&\"" "$ROOT/assets"/*.js 2>/dev/null || true)
if [ -n "$BADQS" ]; then
  echo "  ✗ 查询串拼接错误(应为 '?'; 否则 /api/<action>&... 会被 nginx 当未知路径返回 404):"
  echo "$BADQS" | sed 's/^/    /'
  fail=1
else
  echo "  ✓ GET 查询串拼接正确"
fi

echo "== 4) 检查 JS 语法 =="
for f in "$ROOT"/assets/*.js; do
  if command -v node >/dev/null 2>&1; then
    node --check "$f" || { echo "  ✗ 语法错误: $f"; fail=1; }
  fi
done
[ $fail -eq 0 ] && echo "  ✓ JS 语法正常"

echo
if [ $fail -ne 0 ]; then
  echo "检查未通过, 请修复后再部署"
  exit 1
fi
echo "全部检查通过, 可以部署"
