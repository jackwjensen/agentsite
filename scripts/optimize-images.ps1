#requires -Version 5.1
<#
  scripts/optimize-images.ps1

  Auto-discovering, idempotent image optimizer for public/assets/img/.
  (macOS/Linux: use scripts/optimize-images.sh — same behaviour.)

  For every committed raster (*.jpg/*.jpeg/*.png) it:
    1. Right-sizes the ORIGINAL in place when it is wider than the target OR
       heavier than the budget ceiling (shrink-only; dimension/byte-guarded so
       re-runs are no-ops with no generation loss). Originals are never
       deleted: they are the <picture> fallback and the social-share image,
       and git preserves prior bytes.
    2. Generates/refreshes the sibling .webp variants that lib/images.php
       looks for:
           <name>.webp      full  (<=1480px wide)
           <name>-sm.webp   small (<=760px wide)
       Each is kept only when it is actually smaller than the original, so
       the existence-gated <picture> never serves a heavier file.

  Encoding is done by ImageMagick: a native 'magick' on PATH if present, else
  a throwaway 'dpokidov/imagemagick' Docker container (nothing is added to the
  production image). Re-run after adding any image; only new/changed files do
  work. There is no per-file manifest — new images are discovered
  automatically.

  The "leave it alone" condition here mirrors scripts/check-image-budget.php,
  so the optimizer's output always passes that gate. Keep the constants below
  in sync with the PHP checker and the .sh variant.

  Usage:  powershell -File scripts\optimize-images.ps1 [-Force]
          ( -Force re-encodes everything, ignoring the up-to-date guards )
#>
[CmdletBinding()]
param([switch]$Force)

$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Drawing

# --- budget / sizing config (keep in sync with check-image-budget.php) ---------
$DefaultMaxWidth = 1600
$DefaultQuality  = 80
$WebpQuality     = 80
$WebpFullWidth   = 1480
$WebpSmallWidth  = 760
$MaxRasterBytes  = 900KB   # originals heavier than this get re-compressed in place

# Never touch (already tiny / fixed-purpose). Matches check-image-budget.php EXEMPT.
$SkipNames = @('favicon-16.png', 'favicon-32.png', 'apple-touch-icon.png')

$imgRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\public\assets\img')).Path

# --- toolchain: native magick preferred, Docker fallback -----------------------
$useNative   = [bool](Get-Command magick -ErrorAction SilentlyContinue)
$dockerMount = ($imgRoot -replace '\\', '/') + ':/img'
if ($useNative) {
    Write-Host "ImageMagick: native (magick on PATH)"
}
elseif (Get-Command docker -ErrorAction SilentlyContinue) {
    Write-Host "ImageMagick: docker dpokidov/imagemagick  (mount $dockerMount)"
}
else {
    throw "Need ImageMagick ('magick') or Docker on PATH to encode images."
}

function ConvertTo-MagickPath([string]$absPath) {
    if ($useNative) { return $absPath }
    $rel = ($absPath.Substring($imgRoot.Length).TrimStart('\', '/')) -replace '\\', '/'
    return "/img/$rel"
}

function Invoke-Magick([string[]]$MagickArgs) {
    if ($useNative) {
        & magick @MagickArgs
    }
    else {
        & docker run --rm -v $dockerMount --entrypoint magick dpokidov/imagemagick @MagickArgs
    }
    if ($LASTEXITCODE -ne 0) { throw "ImageMagick failed: $($MagickArgs -join ' ')" }
}

function Get-ImageWidth([string]$path) {
    $img = [System.Drawing.Image]::FromFile($path)
    try { return [int]$img.Width } finally { $img.Dispose() }
}

# --- main loop -----------------------------------------------------------------
$rasters = Get-ChildItem -Path $imgRoot -Recurse -File -Include *.jpg, *.jpeg, *.png |
    Sort-Object FullName

foreach ($file in $rasters) {
    $rel    = $file.FullName.Substring($imgRoot.Length + 1)
    $relKey = $rel -replace '\\', '/'

    if ($SkipNames -contains $file.Name) { Write-Host "skip   $relKey (skip-list)"; continue }

    # 1) right-size the original in place (guarded, so re-runs cause no loss)
    $width = Get-ImageWidth $file.FullName
    $needsResize = ($width -gt $DefaultMaxWidth) -or ($file.Length -gt $MaxRasterBytes)
    if ($Force -or $needsResize) {
        $resizeArgs = @(
            (ConvertTo-MagickPath $file.FullName),
            '-resize', "${DefaultMaxWidth}x>",      # '>' = shrink only, never upscale
            '-strip', '-quality', "$DefaultQuality",
            (ConvertTo-MagickPath $file.FullName)
        )
        Invoke-Magick $resizeArgs
        $file.Refresh()
        Write-Host ("sized  {0,-40} -> {1,6:N0} KB" -f $relKey, ($file.Length / 1KB))
    }

    # 2) (re)generate the .webp variants; keep each only if it beats the original
    $variants = @(
        @{ Suffix = '';    MaxW = $WebpFullWidth },
        @{ Suffix = '-sm'; MaxW = $WebpSmallWidth }
    )
    foreach ($variant in $variants) {
        $baseName = [IO.Path]::GetFileNameWithoutExtension($file.FullName)
        $webpFs   = Join-Path $file.DirectoryName ($baseName + $variant.Suffix + '.webp')
        $webpKey  = ($webpFs.Substring($imgRoot.Length + 1)) -replace '\\', '/'

        $isStale = $Force -or -not (Test-Path $webpFs) -or
            ((Get-Item $file.FullName).LastWriteTimeUtc -gt (Get-Item $webpFs).LastWriteTimeUtc)
        if (-not $isStale) {
            Write-Host "skip   $webpKey (up to date)"
            continue
        }

        Invoke-Magick @(
            (ConvertTo-MagickPath $file.FullName),
            '-resize', "$($variant.MaxW)x>",
            '-strip', '-quality', "$WebpQuality",
            (ConvertTo-MagickPath $webpFs)
        )
        if ((Get-Item $webpFs).Length -ge $file.Length) {
            Remove-Item $webpFs -Force
            Write-Host ("webp   {0,-40}    not smaller than original - dropped" -f $webpKey)
        }
        else {
            Write-Host ("webp   {0,-40} -> {1,6:N0} KB" -f $webpKey, ((Get-Item $webpFs).Length / 1KB))
        }
    }
}

Write-Host "`nDone. Verify with:  php scripts/check-image-budget.php"
