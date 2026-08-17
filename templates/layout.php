<?php
/**
 * Layout wrapper — every HTML response passes through here. The inner
 * template has already run (public/index.php buffers it into $page_content)
 * and has set:
 *
 *   $seo            the array render_seo_head() consumes (one variable, not
 *                   a dozen globals — see lib/seo.php for the keys)
 *   $preload_image  optional site-relative path of the LCP image to preload
 *   $css_files      slice list, set by index.php's template→CSS map
 *
 * Head ordering is deliberate: the LCP preload and stylesheets come before
 * the SEO meta block, so the browser discovers render-critical resources in
 * the first bytes of the response.
 */
declare(strict_types=1);

$h = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

$site_name  = (string) cfg('site.name');
$alternates = $alternates ?? [];
$languages  = cfg('languages');
?><!DOCTYPE html>
<html lang="<?= $h(str_replace('_', '-', (string) ($languages[$lang]['locale'] ?? $lang))) ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="<?= $h((string) cfg('site.theme_color')) ?>">
<?php if (!empty($preload_image)): ?>
<?php
    // Preload the LCP image, preferring the WebP variants. The srcset here is
    // built by the same helper <picture> uses, so the preload can never fetch
    // a different URL than the markup.
    $preload = img_srcset_sizes((string) $preload_image, 'hero');
    if ($preload['srcset'] !== ''): ?>
  <link rel="preload" as="image" imagesrcset="<?= $h($preload['srcset']) ?>" imagesizes="<?= $h($preload['sizes']) ?>" fetchpriority="high">
<?php else: ?>
  <link rel="preload" as="image" href="<?= $h(img_version_url((string) $preload_image)) ?>" fetchpriority="high">
<?php endif; ?>
<?php endif; ?>
<?php
// One <link> per CSS slice, version-stamped by file mtime so the 1-year
// immutable cache busts when the file changes. A missing slice emits an
// unversioned link (visible 404 in devtools) rather than stamping with
// "now" — which would change the URL every second and destroy caching.
foreach (($css_files ?? ['tokens', 'base']) as $css):
    $css_path = ROOT . "/public/assets/css/$css.css";
    $css_url  = "/assets/css/$css.css";
    if (is_file($css_path)) {
        $mtime = filemtime($css_path);
        if ($mtime !== false) $css_url .= '?v=' . $mtime;
    }
?>
  <link rel="stylesheet" href="<?= $h($css_url) ?>">
<?php endforeach; ?>
  <link rel="icon" href="/assets/img/favicon.svg" type="image/svg+xml">
  <link rel="alternate" type="text/markdown" href="/llms.txt" title="Site overview for LLMs">
<?php if (!empty($md_url)): ?>
  <link rel="alternate" type="text/markdown" href="<?= $h((string) $md_url) ?>" title="This page in Markdown">
<?php endif; ?>
<?php render_seo_head($seo ?? ['lang' => $lang, 'alternates' => $alternates]); ?>
<?php
// Anything an installed module wants in <head> — feed autodiscovery, a
// verification meta tag, a preconnect. Discovered by filename, like routes
// and i18n: a module drops one file here and nothing central is edited, so
// two modules cannot collide except on a filename, which the installer
// refuses loudly.
foreach (glob(ROOT . '/templates/partials/head/*.php') ?: [] as $head_partial) {
    include $head_partial;
}
?>
</head>
<body>
  <a class="skip-link" href="#main"><?= $h(t('skip_to_content')) ?></a>
  <header class="site-header">
    <div class="site-header-inner">
      <a class="site-brand" href="<?= $h(lang_path($lang, 'home')) ?>"><?= $h($site_name) ?></a>
      <button class="nav-toggle" type="button" aria-expanded="false" aria-controls="site-nav" aria-label="<?= $h(t('nav_toggle')) ?>">
        <span></span><span></span><span></span>
      </button>
      <nav id="site-nav" class="site-nav" aria-label="<?= $h(t('nav_main_label')) ?>">
<?php foreach ((array) cfg('nav') as $item):
        $nav_route  = (string) ($item['route'] ?? '');
        if ($nav_route === '') continue;
        $nav_params = isset($item['slug']) ? ['slug' => (string) $item['slug']] : [];
        $nav_path   = lang_path($lang, $nav_route, $nav_params);
        $is_current = $nav_path === current_path();
?>
        <a href="<?= $h($nav_path) ?>"<?= $is_current ? ' aria-current="page"' : '' ?>><?= $h(t((string) ($item['key'] ?? $nav_route))) ?></a>
<?php endforeach; ?>
<?php if (count($alternates) > 1): ?>
        <span class="lang-switch" role="group" aria-label="<?= $h(t('lang_switch_label')) ?>">
<?php foreach ($alternates as $alt_lang => $alt_path):
            if ($alt_lang === $lang) continue; ?>
          <a href="<?= $h((string) $alt_path) ?>" hreflang="<?= $h((string) $alt_lang) ?>" lang="<?= $h((string) $alt_lang) ?>"><?= $h((string) ($languages[$alt_lang]['label'] ?? $alt_lang)) ?></a>
<?php endforeach; ?>
        </span>
<?php endif; ?>
      </nav>
    </div>
  </header>

  <main id="main">
<?= $page_content ?>
  </main>

  <footer class="site-footer">
    <div class="site-footer-inner">
      <p class="footer-brand"><?= $h($site_name) ?></p>
      <p class="footer-tagline"><?= $h(cfg_lang('site.tagline', $lang)) ?></p>
<?php $footer_email = cfg('identity.email'); if (is_string($footer_email) && $footer_email !== ''): ?>
      <p class="footer-contact"><a href="mailto:<?= $h($footer_email) ?>"><?= $h($footer_email) ?></a></p>
<?php endif; ?>
      <p class="footer-meta">&copy; <?= date('Y') ?> <?= $h((string) (cfg('identity.legal_name') ?? $site_name)) ?> ·
        <a href="/llms.txt" class="footer-ai"><?= $h(t('footer_ai_friendly')) ?></a></p>
    </div>
  </footer>

  <script>
  // Mobile nav toggle — the only JavaScript in the core framework.
  (function () {
    var toggle = document.querySelector('.nav-toggle');
    var nav    = document.getElementById('site-nav');
    if (!toggle || !nav) return;
    toggle.addEventListener('click', function () {
      var open = nav.classList.toggle('is-open');
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  })();
  </script>
</body>
</html>
