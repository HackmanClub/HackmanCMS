#!/usr/bin/env bash
set -euo pipefail

REPO="$(cd "$(dirname "$0")/.." && pwd)"

echo "→ deploy $REPO"
git -C "$REPO" pull --ff-only

vmm=$(tr -d '[:space:]' < "$REPO/VERSION" 2>/dev/null || echo '0.0')
vbump=$(git -C "$REPO" log -1 --format=%H -- VERSION 2>/dev/null || echo '')
if [ -n "$vbump" ]; then
  bnum=$(git -C "$REPO" rev-list --count "${vbump}..HEAD" 2>/dev/null || echo '0')
else
  bnum=$(git -C "$REPO" rev-list --count HEAD 2>/dev/null || echo '0')
fi
version="${vmm}.${bnum}"
sha=$(git -C "$REPO"    rev-parse --short HEAD      2>/dev/null || echo 'unknown')
branch=$(git -C "$REPO" rev-parse --abbrev-ref HEAD 2>/dev/null || echo 'unknown')
built=$(date '+%Y-%m-%d %H:%M:%S')
cat > "$REPO/web/BUILD" <<BUILD
version=$version
sha=$sha
branch=$branch
built=$built
BUILD
echo "  ✓ BUILD: v$version · $sha · $built"

php "$REPO/bin/migrate.php"
echo "→ done"
