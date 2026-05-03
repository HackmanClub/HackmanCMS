#!/usr/bin/env bash
set -euo pipefail

REPO="$(cd "$(dirname "$0")" && pwd)"
DEST="/var/www/hackmancms"
PORT=8082
VHOST="/etc/apache2/sites-available/hackmancms.conf"

# ── sync ──────────────────────────────────────────────────────────────────────
echo "→ syncing to $DEST"
sudo rsync -a --delete \
  --exclude='.git' \
  --exclude='data/' \
  --exclude='config/config.local.php' \
  --exclude='deploylocal.sh' \
  --exclude='web/BUILD' \
  "$REPO/" "$DEST/"

# ── BUILD file ────────────────────────────────────────────────────────────────
# VERSION carries MAJOR.MINOR ("0.3"); patch number is the commit count since
# the last touch of VERSION, so bumping resets to .0 and each commit
# auto-increments. SHA + branch + ISO build timestamp ride along for the
# footer. Mirrors /opt/agenda/deploy.sh — keeps the formatting identical.
VERSION_FILE="${REPO}/VERSION"
VERSION_MM=$(cat "$VERSION_FILE" 2>/dev/null | tr -d '[:space:]' || echo '0.0')
VERSION_BUMP_SHA=$(git -C "$REPO" log -1 --format=%H -- VERSION 2>/dev/null || echo '')
if [ -n "$VERSION_BUMP_SHA" ]; then
  BUILD_NUM=$(git -C "$REPO" rev-list --count "${VERSION_BUMP_SHA}..HEAD" 2>/dev/null || echo '0')
else
  BUILD_NUM=$(git -C "$REPO" rev-list --count HEAD 2>/dev/null || echo '0')
fi
VERSION="${VERSION_MM}.${BUILD_NUM}"
GIT_SHA=$(git -C "$REPO" rev-parse --short HEAD 2>/dev/null || echo 'unknown')
GIT_BRANCH=$(git -C "$REPO" rev-parse --abbrev-ref HEAD 2>/dev/null || echo 'unknown')
BUILT_AT=$(date '+%Y-%m-%d %H:%M:%S')
sudo tee "$DEST/web/BUILD" >/dev/null <<BUILD
version=$VERSION
sha=$GIT_SHA
branch=$GIT_BRANCH
built=$BUILT_AT
BUILD
echo "  ✓ BUILD: v$VERSION · $GIT_SHA · $BUILT_AT"

# ── migrate ───────────────────────────────────────────────────────────────────
echo "→ running migrations"
sudo chown -R www-data:www-data "$DEST/"
sudo -u www-data php "$DEST/bin/migrate.php"

# ── reload apache ─────────────────────────────────────────────────────────────
sudo systemctl reload apache2

echo "→ done — http://localhost:$PORT"
