<?php
/** A single post: content/<lang>/posts/YYYY-MM-DD-<slug>.md */
declare(strict_types=1);

$h    = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$post = load_post($slug, $lang);

if ($post === null) {
    http_response_code(404);
    include ROOT . '/templates/404.php';
    return;
}

$meta  = $post['meta'];
$title = (string) ($meta['title'] ?? $slug);
$cat   = (string) ($meta['category'] ?? '');
$hero  = (string) ($meta['featured_image'] ?? '');
$tags  = is_array($meta['tags'] ?? null) ? $meta['tags'] : [];

$seo = [
    'lang'        => $lang,
    'title'       => $title . ' — ' . cfg('site.name'),
    'description' => auto_description($post),
    'og_type'     => 'article',
    'article'     => [
        'title'       => $title,
        'date'        => (string) ($meta['date'] ?? ''),
        'updated'     => (string) ($meta['updated'] ?? ''),
        'description' => auto_description($post),
        'category'    => $cat,
        'tags'        => $tags,
    ],
    'alternates'  => $alternates,
    'breadcrumbs' => [
        ['name' => t('nav_home'),      'url' => lang_path($lang, 'home')],
        ['name' => t('archive_title'), 'url' => lang_path($lang, 'archive')],
        ['name' => $title,             'url' => current_path()],
    ],
];
if (post_og_image($meta) !== '') {
    $seo['og_image']     = post_og_image($meta);
    $seo['og_image_alt'] = (string) ($meta['featured_image_alt'] ?? $title);
}
if ($hero !== '') {
    $preload_image = $hero;
}
?>
<article class="content-page post-page">
  <header class="content-hero">
    <div class="content-hero-inner">
      <p class="post-meta">
        <time datetime="<?= $h((string) ($meta['date'] ?? '')) ?>"><?= $h(t('label_published')) ?> <?= $h((string) ($meta['date'] ?? '')) ?></time>
<?php if (post_is_updated($meta)): ?>
        · <span class="post-updated"><?= $h(t('label_updated')) ?> <?= $h((string) $meta['updated']) ?></span>
<?php endif; ?>
<?php if ($cat !== ''): ?>
        · <a class="post-cat" href="<?= $h(lang_path($lang, 'archive')) ?>?cat=<?= $h($cat) ?>"><?= $h(category_label($cat, $lang)) ?></a>
<?php endif; ?>
      </p>
      <h1><?= $h($title) ?></h1>
<?php $excerpt = extract_excerpt($post); if ($excerpt !== ''): ?>
      <p class="content-lead"><?= $h($excerpt) ?></p>
<?php endif; ?>
    </div>
  </header>
<?php if ($hero !== ''): ?>
  <div class="content-hero-media">
    <?= render_picture($hero, (string) ($meta['featured_image_alt'] ?? $title), ['context' => 'hero', 'eager' => true, 'priority' => true, 'class' => 'content-hero-img']) ?>
  </div>
<?php endif; ?>
  <div class="content-body">
    <?= $post['html'] ?>
  </div>
  <footer class="post-footer">
<?php include ROOT . '/templates/partials/post-share.php'; ?>
    <p class="post-back"><a href="<?= $h(lang_path($lang, 'archive')) ?>">← <?= $h(t('post_back_to_archive')) ?></a></p>
  </footer>
</article>
