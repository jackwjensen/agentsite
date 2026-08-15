<?php
/**
 * One post card — shared by the archive grid and the homepage strip.
 * Expects: $card_post (a load_posts() entry), $lang.
 */
declare(strict_types=1);

$h          = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$card_meta  = $card_post['meta'];
$card_slug  = (string) ($card_meta['slug'] ?? '');
$card_title = (string) ($card_meta['title'] ?? $card_slug);
$card_thumb = post_thumb($card_meta);
$card_cat   = (string) ($card_meta['category'] ?? '');
?>
<article class="post-card">
  <a class="post-card-link" href="<?= $h(post_url($card_slug, $lang)) ?>">
<?php if ($card_thumb !== ''): ?>
    <div class="post-card-media">
      <?= render_picture($card_thumb, $card_title, ['context' => 'card', 'class' => 'post-card-img']) ?>
    </div>
<?php endif; ?>
    <div class="post-card-body">
      <h3 class="post-card-title"><?= $h($card_title) ?></h3>
      <p class="post-card-meta">
        <time datetime="<?= $h((string) ($card_meta['date'] ?? '')) ?>"><?= $h((string) ($card_meta['date'] ?? '')) ?></time>
<?php if (post_is_updated($card_meta)): ?>
        · <span class="post-card-updated"><?= $h(t('label_updated')) ?> <?= $h((string) $card_meta['updated']) ?></span>
<?php endif; ?>
<?php if (post_is_new($card_meta)): ?>
        <span class="post-new-badge"><?= $h(t('label_new')) ?></span>
<?php endif; ?>
      </p>
<?php if ($card_cat !== ''): ?>
      <p class="post-card-cat"><?= $h(category_label($card_cat, $lang)) ?></p>
<?php endif; ?>
<?php $card_desc = auto_description($card_post, 140); if ($card_desc !== ''): ?>
      <p class="post-card-desc"><?= $h($card_desc) ?></p>
<?php endif; ?>
    </div>
  </a>
</article>
