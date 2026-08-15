<?php
/** 404 Not Found. */
declare(strict_types=1);

$h = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

$seo = [
    'lang'       => $lang,
    'title'      => t('notfound_title') . ' — ' . cfg('site.name'),
    'noindex'    => true,
    'alternates' => [],
];
?>
<section class="error-page">
  <div class="error-inner">
    <p class="error-code">404</p>
    <h1><?= $h(t('notfound_title')) ?></h1>
    <p><?= $h(t('notfound_text')) ?></p>
    <p><a class="button button-primary" href="<?= $h(lang_path($lang, 'home')) ?>"><?= $h(t('notfound_home')) ?></a></p>
  </div>
</section>
