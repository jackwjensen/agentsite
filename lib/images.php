<?php
declare(strict_types=1);

/**
 * Canonical image emitter — every image on the site routes through
 * render_picture() (templates call it directly; markdown body images via
 * content.php::rewrite_body_images()). CI rejects raw <img> tags in
 * templates so this stays the single choke-point.
 *
 * WebP adoption is incremental and self-healing: variants are emitted ONLY
 * when the sibling files exist on disk (produced offline by
 * scripts/optimize-images.*), so the same call site works before and after
 * conversion — the filesystem is the source of truth, with no second list
 * to drift. Beside each /assets/img/<name>.<ext> the optimizer generates:
 *
 *   <name>.webp     full-size  (≤1480px wide)
 *   <name>-sm.webp  small      (≤760px wide)
 *
 * The original JPG/PNG stays committed as the <img> fallback and the
 * OG/social image (social scrapers cache by URL and want a stable JPG).
 *
 * Every URL emitted carries a ?v=<mtime> version stamp: assets are served
 * with a 1-year immutable Cache-Control, so re-optimizing an image in place
 * would otherwise never reach cached visitors. Changing the bytes changes
 * the URL.
 */

/** Map a site-absolute web path (/assets/img/x.jpg) to its file under public/, or null. */
function img_fs_path(string $src): ?string
{
    if ($src === '' || $src[0] !== '/' || str_contains($src, '..')) return null;
    $fs = ROOT . '/public' . $src;
    return is_file($fs) ? $fs : null;
}

/** Swap a path's extension (/a/b.jpg + 'webp' → /a/b.webp). */
function img_swap_ext(string $src, string $ext): string
{
    $dot = strrpos($src, '.');
    return $dot === false ? "$src.$ext" : substr($src, 0, $dot) . '.' . $ext;
}

/** Append ?v=<mtime> to a local asset URL; remote/missing files unchanged. */
function img_version_url(string $src): string
{
    $fs = img_fs_path($src);
    if ($fs === null) return $src;
    $mtime = filemtime($fs);
    return $mtime !== false ? $src . '?v=' . $mtime : $src;
}

/** Intrinsic ['width','height'] of a local image (cached per request), or null. */
function img_dimensions(string $src): ?array
{
    static $cache = [];
    if (array_key_exists($src, $cache)) return $cache[$src];
    $fs = img_fs_path($src);
    // getimagesize warnings are NOT suppressed: the file exists (img_fs_path
    // checked), so a warning means a corrupt or non-image asset — signal
    // worth having in the error log, not noise.
    $info = $fs !== null ? getimagesize($fs) : false;
    return $cache[$src] = (is_array($info) && !empty($info[0]) && !empty($info[1]))
        ? ['width' => (int) $info[0], 'height' => (int) $info[1]]
        : null;
}

/** WebP sibling paths for a source image. */
function img_webp_variants(string $src): array
{
    $dir  = rtrim(dirname($src), '/');
    $name = pathinfo($src, PATHINFO_FILENAME);
    return ['full' => "$dir/$name.webp", 'sm' => "$dir/$name-sm.webp"];
}

/**
 * WebP srcset + sizes for a display context, version-stamped. Shared by
 * render_picture() and the LCP preload in the layout, so the preload can
 * never drift from what <picture> actually fetches.
 *
 * Contexts: 'hero' (full-width article/page hero), 'card' (grid thumbnail),
 * 'avatar' (small fixed-size portrait), 'full' (single full variant).
 *
 * @return array{srcset:string,sizes:string}
 */
function img_srcset_sizes(string $src, string $context = 'card', ?string $sizes = null): array
{
    $v       = img_webp_variants($src);
    $haveSm  = img_fs_path($v['sm'])   !== null;
    $haveFul = img_fs_path($v['full']) !== null;

    if ($context === 'hero') {
        $parts = [];
        if ($haveSm)  $parts[] = img_version_url($v['sm'])   . ' 760w';
        if ($haveFul) $parts[] = img_version_url($v['full']) . ' 1480w';
        return ['srcset' => implode(', ', $parts), 'sizes' => $sizes ?? '(max-width: 800px) 100vw, 760px'];
    }
    if ($context === 'avatar') {
        $one = $haveSm ? $v['sm'] : ($haveFul ? $v['full'] : '');
        return ['srcset' => $one !== '' ? img_version_url($one) : '', 'sizes' => $sizes ?? '160px'];
    }
    if ($context === 'full') {
        $one = $haveFul ? $v['full'] : ($haveSm ? $v['sm'] : '');
        return ['srcset' => $one !== '' ? img_version_url($one) : '', 'sizes' => $sizes ?? '100vw'];
    }
    // card: the small variant is ample for any thumbnail and keeps bytes low.
    $one = $haveSm ? $v['sm'] : ($haveFul ? $v['full'] : '');
    return ['srcset' => $one !== '' ? img_version_url($one) : '', 'sizes' => $sizes ?? '(max-width: 640px) 100vw, 360px'];
}

/**
 * Render an image as <picture> (WebP source + original fallback) when WebP
 * siblings exist, otherwise a plain <img>. Always stamps width/height to
 * reserve layout space (the CLS fix), and lazy-loads by default.
 *
 * $opts:
 *   'context'  string hero|card|avatar|full — picks the srcset/sizes recipe
 *   'eager'    bool   above-the-fold image → loading="eager" (default lazy)
 *   'priority' bool   with eager → fetchpriority="high" (the LCP image only)
 *   'class'    string class attribute
 *   'width'    int    explicit width  (else probed from the original)
 *   'height'   int    explicit height (else probed from the original)
 *   'sizes'    string explicit sizes attribute override
 */
function render_picture(string $src, string $alt, array $opts = []): string
{
    $h        = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    $context  = (string) ($opts['context'] ?? 'card');
    $class    = (string) ($opts['class'] ?? '');
    $eager    = (bool)   ($opts['eager'] ?? false);
    $priority = (bool)   ($opts['priority'] ?? false);
    $ext      = strtolower(pathinfo($src, PATHINFO_EXTENSION));

    // Width/height reserve layout space. Explicit opts win; otherwise probe
    // the original. Skipped for SVG (getimagesize is unreliable there, and
    // SVGs scale without CLS anyway).
    $width  = (int) ($opts['width'] ?? 0);
    $height = (int) ($opts['height'] ?? 0);
    if (($width === 0 || $height === 0) && $ext !== 'svg') {
        $dim = img_dimensions($src);
        if ($dim) {
            $width  = $width  ?: $dim['width'];
            $height = $height ?: $dim['height'];
        }
    }

    $attrs = ' src="' . $h(img_version_url($src)) . '" alt="' . $h($alt) . '"';
    if ($class !== '') $attrs .= ' class="' . $h($class) . '"';
    if ($width > 0)    $attrs .= ' width="' . $width . '"';
    if ($height > 0)   $attrs .= ' height="' . $height . '"';
    $attrs .= ' decoding="async"';
    if ($eager) {
        $attrs .= ' loading="eager"';
        if ($priority) $attrs .= ' fetchpriority="high"';
    } else {
        $attrs .= ' loading="lazy"';
    }

    $img = '<img' . $attrs . '>';

    // SVG (and extension-less) images: no raster variants, serve as-is.
    if ($ext === 'svg' || $ext === '') return $img;

    $ss = img_srcset_sizes($src, $context, isset($opts['sizes']) ? (string) $opts['sizes'] : null);
    if ($ss['srcset'] === '') {
        return $img;   // no WebP siblings yet → graceful plain <img>
    }

    return '<picture><source type="image/webp" srcset="' . $h($ss['srcset'])
         . '" sizes="' . $h($ss['sizes']) . '">' . $img . '</picture>';
}
