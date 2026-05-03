#!/usr/bin/env bash
set -euo pipefail

TARGET="${1:-local}"
REPO="$(cd "$(dirname "$0")/.." && pwd)"

write_build_file() {
  # $1 = repo dir, $2 = web dir
  local repo="$1" webdir="$2"
  local vmm vbump bnum version sha branch built
  vmm=$(tr -d '[:space:]' < "$repo/VERSION" 2>/dev/null || echo '0.0')
  vbump=$(git -C "$repo" log -1 --format=%H -- VERSION 2>/dev/null || echo '')
  if [ -n "$vbump" ]; then
    bnum=$(git -C "$repo" rev-list --count "${vbump}..HEAD" 2>/dev/null || echo '0')
  else
    bnum=$(git -C "$repo" rev-list --count HEAD 2>/dev/null || echo '0')
  fi
  version="${vmm}.${bnum}"
  sha=$(git -C "$repo"    rev-parse --short HEAD          2>/dev/null || echo 'unknown')
  branch=$(git -C "$repo" rev-parse --abbrev-ref HEAD     2>/dev/null || echo 'unknown')
  built=$(date '+%Y-%m-%d %H:%M:%S')
  cat > "$webdir/BUILD" <<BUILD
version=$version
sha=$sha
branch=$branch
built=$built
BUILD
  echo "  ✓ BUILD: v$version · $sha · $built"
}

case "$TARGET" in
  local)
    DEST="/var/www/hackmancms"
    echo "→ local deploy to $DEST"
    sudo mkdir -p "$DEST"
    sudo rsync -av --delete \
      --exclude='.git' \
      --exclude='data/' \
      --exclude='config/config.local.php' \
      --exclude='web/BUILD' \
      "$REPO/" "$DEST/"
    write_build_file "$REPO" "$DEST/web"
    sudo php "$DEST/bin/migrate.php"
    echo "→ done"
    ;;

  prod)
    # Code lives at /opt/hackmancms on the server; Apache root = /opt/hackmancms/web
    echo "→ prod deploy"
    ssh bashy@37.205.12.57 'set -e
      cd /opt/hackmancms
      git pull --ff-only
      VMM=$(tr -d "[:space:]" < VERSION 2>/dev/null || echo "0.0")
      VBUMP=$(git log -1 --format=%H -- VERSION 2>/dev/null || echo "")
      if [ -n "$VBUMP" ]; then
        BNUM=$(git rev-list --count "${VBUMP}..HEAD" 2>/dev/null || echo "0")
      else
        BNUM=$(git rev-list --count HEAD 2>/dev/null || echo "0")
      fi
      VERSION="${VMM}.${BNUM}"
      SHA=$(git rev-parse --short HEAD 2>/dev/null || echo "unknown")
      BRANCH=$(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo "unknown")
      BUILT=$(date "+%Y-%m-%d %H:%M:%S")
      printf "version=%s\nsha=%s\nbranch=%s\nbuilt=%s\n" "$VERSION" "$SHA" "$BRANCH" "$BUILT" > web/BUILD
      echo "  BUILD: v$VERSION · $SHA · $BUILT"
      php bin/migrate.php
    '
    echo "→ done"
    ;;

  *)
    echo "Usage: $0 [local|prod]"
    exit 1
    ;;
esac
