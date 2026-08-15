<?php
/** Homepage: hero from config (name + tagline + description) + latest posts. */
declare(strict_types=1);

$h     = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$posts = array_slice(load_posts($lang), 0, 6);

$seo = [
    'lang'        => $lang,
    'title'       => cfg('site.name') . ' — ' . cfg_lang('site.tagline', $lang),
    'description' => auto_description(['meta' => [], 'html' => '<p>' . $h(cfg_lang('site.description', $lang)) . '</p>']),
    'alternates'  => $alternates,
];
?>
<section class="hero">
  <div class="hero-inner">
    <p class="hero-eyebrow"><?= $h(cfg('site.name')) ?></p>
    <h1 class="hero-title"><?= $h(cfg_lang('site.tagline', $lang)) ?></h1>
    <p class="hero-lead"><?= $h(cfg_lang('site.description', $lang)) ?></p>
    <div class="hero-actions">
      <a class="button button-primary" href="<?= $h(lang_path($lang, 'archive')) ?>"><?= $h(t('home_cta_posts')) ?></a>
      <a class="button button-ghost" href="/llms.txt"><?= $h(t('footer_ai_friendly')) ?></a>
    </div>
  </div>
</section>

<?php if ($posts): ?>
<section class="home-posts">
  <div class="home-posts-inner">
    <h2 class="section-title"><?= $h(t('home_latest')) ?></h2>
    <div class="post-grid">
<?php foreach ($posts as $card_post): ?>
<?php include ROOT . '/templates/partials/post-card.php'; ?>
<?php endforeach; ?>
    </div>
    <p class="home-posts-more"><a href="<?= $h(lang_path($lang, 'archive')) ?>"><?= $h(t('home_all_posts')) ?> →</a></p>
  </div>
</section>
<?php endif; ?>
