<?php
/** A content page: content/<lang>/pages/<slug>.md */
declare(strict_types=1);

$h    = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$page = load_page($slug, $lang);

if ($page === null) {
    http_response_code(404);
    include ROOT . '/templates/404.php';
    return;
}

$title = (string) ($page['meta']['title'] ?? $slug);

$seo = [
    'lang'        => $lang,
    'title'       => $title . ' — ' . cfg('site.name'),
    'description' => auto_description($page),
    'alternates'  => $alternates,
    'breadcrumbs' => [
        ['name' => t('nav_home'), 'url' => lang_path($lang, 'home')],
        ['name' => $title,        'url' => current_path()],
    ],
];
if (!empty($page['meta']['featured_image'])) {
    $seo['og_image'] = (string) $page['meta']['featured_image'];
    $preload_image   = (string) $page['meta']['featured_image'];
}
?>
<article class="content-page">
  <header class="content-hero">
    <div class="content-hero-inner">
      <h1><?= $h($title) ?></h1>
<?php $excerpt = extract_excerpt($page); if ($excerpt !== ''): ?>
      <p class="content-lead"><?= $h($excerpt) ?></p>
<?php endif; ?>
    </div>
  </header>
<?php if (!empty($page['meta']['featured_image'])): ?>
  <div class="content-hero-media">
    <?= render_picture((string) $page['meta']['featured_image'], (string) ($page['meta']['featured_image_alt'] ?? $title), ['context' => 'hero', 'eager' => true, 'priority' => true, 'class' => 'content-hero-img']) ?>
  </div>
<?php endif; ?>
  <div class="content-body">
    <?= $page['html'] ?>
  </div>
</article>
