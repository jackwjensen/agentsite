<?php
declare(strict_types=1);

/**
 * Image budget gate — the single source of truth for "is a committed image OK?".
 *
 * Fails (exit 1, prints offenders) if any committed raster under
 * public/assets/img/ exceeds the byte ceiling, or is large enough to need a
 * next-gen sibling but has no .webp next to it. Runs identically locally
 * (`php scripts/check-image-budget.php`) and in CI (.github/workflows/check.yml).
 *
 * scripts/optimize-images.ps1 / .sh are built so their output ALWAYS passes
 * this checker — the optimizer's "leave it alone" condition matches this
 * gate's "this is fine" condition, so you never get "I ran the optimizer but
 * CI still fails". Keep the constants in sync across all three files.
 */

const MAX_RASTER_BYTES          = 900 * 1024; // committed JPEG/PNG hard ceiling
const WEBP_REQUIRED_ABOVE_BYTES = 150 * 1024; // above this, a .webp sibling is mandatory

// Tiny fixed-purpose rasters that are exempt (favicons and touch icons are
// intentionally small originals with no next-gen benefit).
const EXEMPT = ['favicon-16.png', 'favicon-32.png', 'apple-touch-icon.png'];

$imgRoot = __DIR__ . '/../public/assets/img';
if (!is_dir($imgRoot)) {
    fwrite(STDERR, "Image dir not found: $imgRoot\n");
    exit(2);
}

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($imgRoot, FilesystemIterator::SKIP_DOTS)
);

$problems = [];
$checked  = 0;

foreach ($iterator as $file) {
    /** @var SplFileInfo $file */
    if (!in_array(strtolower($file->getExtension()), ['jpg', 'jpeg', 'png'], true)) {
        continue;
    }
    if (in_array($file->getFilename(), EXEMPT, true)) {
        continue;
    }

    $checked++;
    $rel   = str_replace('\\', '/', substr($file->getPathname(), strlen($imgRoot) + 1));
    $bytes = $file->getSize();

    if ($bytes > MAX_RASTER_BYTES) {
        $problems[] = sprintf(
            '%s is %d KB (> %d KB ceiling). Run scripts/optimize-images.ps1 (or .sh); convert photographic PNGs to JPEG.',
            $rel, (int) round($bytes / 1024), (int) round(MAX_RASTER_BYTES / 1024)
        );
    }

    if ($bytes > WEBP_REQUIRED_ABOVE_BYTES) {
        $webp = preg_replace('/\.(jpe?g|png)$/i', '.webp', $file->getPathname());
        if (!is_file($webp)) {
            $problems[] = sprintf(
                '%s (%d KB) has no .webp sibling. Run scripts/optimize-images.ps1 (or .sh).',
                $rel, (int) round($bytes / 1024)
            );
        }
    }
}

if ($problems) {
    fwrite(STDERR, "Image budget check FAILED ($checked rasters scanned):\n");
    foreach ($problems as $problem) {
        fwrite(STDERR, "  - $problem\n");
    }
    exit(1);
}

echo "Image budget OK — $checked rasters scanned, all within budget and large ones have .webp siblings.\n";
exit(0);
