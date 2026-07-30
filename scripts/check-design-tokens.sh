#!/usr/bin/env bash
set -euo pipefail

# Check Design Tokens & Visual Identity Lint Guard (Issue #110)
# Ensures component styling adheres strictly to semantic design tokens.

ERRORS=0

echo "🔍 Running Visual Identity CI Guards..."

# 1. Ban raw color literals in components (*.vue in frontend/src/components and frontend/src/App.vue)
echo "  [1/5] Checking for raw color literals in components..."
COLOR_MATCHES=$(grep -rnE '#[0-9a-fA-F]{3,8}\b|rgba?\([^)]+\)|hsla?\([^)]+\)|oklch\([^)]+\)' \
  frontend/src/App.vue frontend/src/components/*.vue 2>/dev/null | grep -v '/* token-ok:' | grep -v '/\*' | grep -v 'transparent' | grep -v 'currentColor' || true)

if [ -n "$COLOR_MATCHES" ]; then
  echo "❌ Error: Found raw color literals in Vue components. Use semantic design tokens (--color-*):"
  echo "$COLOR_MATCHES"
  ERRORS=$((ERRORS + 1))
else
  echo "  ✓ No raw color literals found in components."
fi

# 2. Ban palette tokens in components (except --color-neutral-0)
echo "  [2/5] Checking for direct palette token usage in components..."
PALETTE_MATCHES=$(grep -rnE 'var\(--color-purple-[^)]+\)|var\(--color-neutral-(?!0\b)[0-9]+\)' \
  frontend/src/App.vue frontend/src/components/*.vue 2>/dev/null || true)

if [ -n "$PALETTE_MATCHES" ]; then
  echo "❌ Error: Direct palette tokens found in components. Use semantic tokens instead:"
  echo "$PALETTE_MATCHES"
  ERRORS=$((ERRORS + 1))
else
  echo "  ✓ Components consume semantic tokens exclusively."
fi

# 3. Ban third-party font sources
echo "  [3/5] Checking for unapproved external font sources..."
FONT_MATCHES=$(grep -rnE 'fonts\.googleapis\.com|fonts\.gstatic\.com|fonts\.bunny\.net' \
  frontend/ resources/ public/ 2>/dev/null || true)

if [ -n "$FONT_MATCHES" ]; then
  echo "❌ Error: Found third-party font CDN requests. Use self-hosted fonts:"
  echo "$FONT_MATCHES"
  ERRORS=$((ERRORS + 1))
else
  echo "  ✓ No third-party font CDN sources found."
fi

# 4. Guard visible focus ring (prohibit un-annotated outline: none / outline: 0)
echo "  [4/5] Checking for outline: none / outline: 0 without replacement..."
OUTLINE_MATCHES=$(grep -rnE '^\s*outline:\s*(none|0)\b' \
  frontend/src/App.vue frontend/src/components/*.vue 2>/dev/null | grep -v '/* a11y-ok:' || true)

if [ -n "$OUTLINE_MATCHES" ]; then
  echo "❌ Error: Found outline: none / 0 without replacement focus indicator or a11y-ok comment:"
  echo "$OUTLINE_MATCHES"
  ERRORS=$((ERRORS + 1))
else
  echo "  ✓ No un-annotated outline: none / 0 found."
fi

# 5. Both data-theme blocks must define the same set of --color-* tokens
echo "  [5/5] Checking light/dark theme token parity..."
LIGHT_TOKENS=$(awk '/:root\[data-theme="light"\]/,/^}/' frontend/src/styles/tokens.css | grep -oE '\-\-color-[a-z0-9-]+' | sort -u)
DARK_TOKENS=$(awk '/:root\[data-theme="dark"\]/,/^}/' frontend/src/styles/tokens.css | grep -oE '\-\-color-[a-z0-9-]+' | sort -u)

TOKEN_DIFF=$(diff <(echo "$LIGHT_TOKENS") <(echo "$DARK_TOKENS") || true)

if [ -n "$TOKEN_DIFF" ]; then
  echo "❌ Error: light and dark theme blocks define different --color-* tokens:"
  echo "$TOKEN_DIFF"
  ERRORS=$((ERRORS + 1))
else
  echo "  ✓ Light and dark theme blocks define an identical token set."
fi

if [ "$ERRORS" -gt 0 ]; then
  echo "❌ Visual Identity CI Guard FAILED with $ERRORS error(s)."
  exit 1
fi

echo "✅ All Visual Identity CI Guards PASSED."
exit 0
