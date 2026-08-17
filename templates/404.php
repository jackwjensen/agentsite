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
<?php
// An optional slot for installed modules. The contact-form module fills it
// with a line about the form, and does so on EVERY 404 — that
// unconditionality is the mechanism, not an oversight: a mistyped URL and a
// spam-blocked submission then render byte-identical pages, so a scanner
// learns nothing, while the wording means something only to a person who was
// actually using the form.
$module_hint = t_opt('notfound_form_hint');
if ($module_hint !== ''): ?>
    <p class="error-hint"><?= $h($module_hint) ?></p>
<?php endif; ?>
    <p><a class="button button-primary" href="<?= $h(lang_path($lang, 'home')) ?>"><?= $h(t('notfound_home')) ?></a></p>
  </div>
</section>
