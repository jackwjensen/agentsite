#!/bin/sh
# scripts/optimize-images.sh
#
# Auto-discovering, idempotent image optimizer for public/assets/img/.
# (Windows: use scripts/optimize-images.ps1 — same behaviour.)
#
# For every committed raster (*.jpg/*.jpeg/*.png) it:
#   1. Right-sizes the ORIGINAL in place when it is heavier than the budget
#      ceiling or wider than the target (shrink-only, so re-runs are no-ops).
#      Originals are never deleted: they are the <picture> fallback and the
#      social-share image.
#   2. Generates/refreshes the sibling .webp variants lib/images.php looks for:
#        <name>.webp      full  (<=1480px wide)
#        <name>-sm.webp   small (<=760px wide)
#      Each is kept only when it is actually smaller than the original.
#
# Encoding uses a native `magick` if present, else a throwaway
# dpokidov/imagemagick Docker container. Constants below must stay in sync
# with scripts/check-image-budget.php and scripts/optimize-images.ps1.
#
# Usage:  sh scripts/optimize-images.sh [--force]
set -e

MAX_WIDTH=1600
QUALITY=80
WEBP_QUALITY=80
WEBP_FULL_WIDTH=1480
WEBP_SMALL_WIDTH=760
MAX_RASTER_BYTES=921600   # 900 KB — matches check-image-budget.php

FORCE=0
[ "${1:-}" = "--force" ] && FORCE=1

cd "$(dirname "$0")/.."
IMG_ROOT="$(pwd)/public/assets/img"
[ -d "$IMG_ROOT" ] || { echo "Image dir not found: $IMG_ROOT" >&2; exit 2; }

if command -v magick > /dev/null 2>&1; then
  echo "ImageMagick: native (magick on PATH)"
  run_magick() { magick "$@"; }
  mp() { printf '%s' "$1"; }
elif command -v docker > /dev/null 2>&1; then
  echo "ImageMagick: docker dpokidov/imagemagick"
  run_magick() { docker run --rm -v "$IMG_ROOT:/img" --entrypoint magick dpokidov/imagemagick "$@"; }
  mp() { printf '/img/%s' "${1#"$IMG_ROOT"/}"; }
else
  echo "Need ImageMagick ('magick') or Docker on PATH to encode images." >&2
  exit 2
fi

file_size() { wc -c < "$1" | tr -d ' '; }

find "$IMG_ROOT" -type f \( -name '*.jpg' -o -name '*.jpeg' -o -name '*.png' \) | sort | while read -r file; do
  name="$(basename "$file")"
  rel="${file#"$IMG_ROOT"/}"

  case "$name" in
    favicon-16.png|favicon-32.png|apple-touch-icon.png)
      echo "skip   $rel (skip-list)"; continue ;;
  esac

  # 1) right-size the original in place when it is over budget
  size=$(file_size "$file")
  if [ "$FORCE" -eq 1 ] || [ "$size" -gt "$MAX_RASTER_BYTES" ]; then
    run_magick "$(mp "$file")" -resize "${MAX_WIDTH}x>" -strip -quality "$QUALITY" "$(mp "$file")"
    echo "sized  $rel -> $(( $(file_size "$file") / 1024 )) KB"
  fi

  # 2) (re)generate the .webp variants
  dir="$(dirname "$file")"
  base="${name%.*}"
  for variant in "::$WEBP_FULL_WIDTH" "-sm::$WEBP_SMALL_WIDTH"; do
    suffix="${variant%%::*}"
    width="${variant##*::}"
    webp="$dir/$base$suffix.webp"
    webp_rel="${webp#"$IMG_ROOT"/}"

    if [ "$FORCE" -eq 0 ] && [ -f "$webp" ] && [ ! "$file" -nt "$webp" ]; then
      echo "skip   $webp_rel (up to date)"
      continue
    fi

    run_magick "$(mp "$file")" -resize "${width}x>" -strip -quality "$WEBP_QUALITY" "$(mp "$webp")"
    if [ "$(file_size "$webp")" -ge "$(file_size "$file")" ]; then
      rm -f "$webp"
      echo "webp   $webp_rel    not smaller than original - dropped"
    else
      echo "webp   $webp_rel -> $(( $(file_size "$webp") / 1024 )) KB"
    fi
  done
done

echo ""
echo "Done. Verify with:  php scripts/check-image-budget.php"
