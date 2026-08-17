<?php
/**
 * Subscribe + confirm page — installed from modules/newsletter (v1).
 *
 * One template serves both routes: the form (POST signs up) and the
 * confirmation link target (GET with ?email= and ?token=).
 */
declare(strict_types=1);

require_once ROOT . '/lib/newsletter.php';

$h = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

$notice       = '';
$notice_kind  = 'ok';
$confirm_link = '';
$is_confirm   = ($route ?? '') === 'newsletter_confirm';

if ($is_confirm) {
    $email  = (string) ($_GET['email'] ?? '');
    $token  = (string) ($_GET['token'] ?? '');
    $action = (string) ($_GET['action'] ?? 'confirm');

    if ($action === 'unsubscribe') {
        $ok = newsletter_unsubscribe($email, $token, $lang);
        $notice      = $ok ? t('newsletter_unsubscribed') : t('newsletter_link_invalid');
        $notice_kind = $ok ? 'ok' : 'error';
    } else {
        $ok = newsletter_confirm($email, $token, $lang);
        $notice      = $ok ? t('newsletter_confirmed') : t('newsletter_link_invalid');
        $notice_kind = $ok ? 'ok' : 'error';
    }
} elseif (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $result = newsletter_signup((string) ($_POST['email'] ?? ''), $lang);
    switch ($result) {
        case 'pending':
            $notice      = t('newsletter_check_inbox');
            $notice_kind = 'ok';
            // Without a mail sender installed there is no way to deliver the
            // link, so it is shown here in development. See MODULE.md — in
            // production you email it instead.
            if (getenv('APP_DEBUG')) {
                $confirm_link = newsletter_confirm_url((string) $_POST['email'], $lang);
            }
            break;
        case 'already':
            // Deliberately the same wording as 'pending': this form must not
            // reveal whether an address is already on the list.
            $notice      = t('newsletter_check_inbox');
            $notice_kind = 'ok';
            break;
        case 'throttled':
            $notice      = t('newsletter_throttled');
            $notice_kind = 'warn';
            break;
        default:
            $notice      = t('newsletter_invalid_email');
            $notice_kind = 'error';
    }
}

$seo = [
    'lang'        => $lang,
    'title'       => t('newsletter_title') . ' — ' . cfg('site.name'),
    'description' => t('newsletter_intro'),
    'alternates'  => $alternates,
    'noindex'     => $is_confirm,
];
?>
<section class="content-hero">
  <div class="content-hero-inner">
    <h1><?= $h(t('newsletter_title')) ?></h1>
    <p class="content-lead"><?= $h(t('newsletter_intro')) ?></p>
  </div>
</section>

<section class="contact-body">
  <div class="contact-inner">
<?php if ($notice !== ''): ?>
    <p class="form-notice form-notice-<?= $h($notice_kind) ?>" role="status"><?= $h($notice) ?></p>
<?php endif; ?>
<?php if ($confirm_link !== ''): ?>
    <p class="form-notice form-notice-warn">
      <?= $h(t('newsletter_dev_link')) ?><br>
      <a href="<?= $h($confirm_link) ?>"><?= $h($confirm_link) ?></a>
    </p>
<?php endif; ?>

<?php if (!$is_confirm): ?>
    <form method="post" action="<?= $h(lang_path($lang, 'newsletter')) ?>" class="contact-form">
      <div class="form-row">
        <label for="nl_email"><?= $h(t('newsletter_field_email')) ?></label>
        <input type="email" name="email" id="nl_email" maxlength="200" required autocomplete="email">
      </div>
      <button type="submit" class="button button-primary"><?= $h(t('newsletter_submit')) ?></button>
      <p class="form-hint"><?= $h(t('newsletter_consent')) ?></p>
    </form>
<?php else: ?>
    <p><a href="<?= $h(lang_path($lang, 'home')) ?>">← <?= $h(t('notfound_home')) ?></a></p>
<?php endif; ?>
  </div>
</section>
