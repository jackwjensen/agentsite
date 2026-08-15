<?php
/**
 * 410 Gone — a page that existed and was deliberately removed. Distinct
 * from 404 because the status line tells crawlers to de-index faster; the
 * human-facing body is nearly the same.
 */
declare(strict_types=1);

$h = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

$seo = [
    'lang'       => $lang,
    'title'      => t('gone_title') . ' — ' . cfg('site.name'),
    'noindex'    => true,
    'alternates' => [],
];
?>
<section class="error-page">
  <div class="error-inner">
    <p class="error-code">410</p>
    <h1><?= $h(t('gone_title')) ?></h1>
    <p><?= $h(t('gone_text')) ?></p>
    <p><a class="button button-primary" href="<?= $h(lang_path($lang, 'home')) ?>"><?= $h(t('notfound_home')) ?></a></p>
  </div>
</section>
