<?php
/**
 * Post archive with category filter (?cat=<code>). Filtered views are
 * noindexed so every permutation doesn't pollute the search index; the
 * plain archive is the canonical listing.
 */
declare(strict_types=1);

$h = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

$active_cat = (string) ($_GET['cat'] ?? '');
if ($active_cat !== '' && !in_array($active_cat, all_categories(), true)) {
    $active_cat = '';
}
$posts = load_posts($lang, $active_cat !== '' ? $active_cat : null);

$seo = [
    'lang'        => $lang,
    'title'       => t('archive_title') . ' — ' . cfg('site.name'),
    'description' => t('archive_desc'),
    'alternates'  => $alternates,
    'noindex'     => $active_cat !== '',
];
?>
<section class="archive-hero">
  <div class="archive-hero-inner">
    <h1><?= $h(t('archive_title')) ?></h1>
    <p><?= $h(t('archive_desc')) ?></p>
  </div>
</section>

<section class="archive-body">
  <div class="archive-inner">
<?php if (all_categories()): ?>
    <nav class="archive-filters" aria-label="<?= $h(t('archive_filter_label')) ?>">
      <a href="<?= $h(lang_path($lang, 'archive')) ?>" class="filter-chip<?= $active_cat === '' ? ' is-active' : '' ?>"><?= $h(t('archive_all')) ?></a>
<?php foreach (all_categories() as $cat_code): ?>
      <a href="<?= $h(lang_path($lang, 'archive')) ?>?cat=<?= $h($cat_code) ?>" class="filter-chip<?= $active_cat === $cat_code ? ' is-active' : '' ?>"><?= $h(category_label($cat_code, $lang)) ?></a>
<?php endforeach; ?>
    </nav>
<?php endif; ?>

<?php if ($posts): ?>
    <div class="post-grid">
<?php foreach ($posts as $card_post): ?>
<?php include ROOT . '/templates/partials/post-card.php'; ?>
<?php endforeach; ?>
    </div>
<?php else: ?>
    <p class="archive-empty"><?= $h(t('archive_empty')) ?></p>
<?php endif; ?>
  </div>
</section>
