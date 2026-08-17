<?php
/**
 * Contact form — installed from modules/contact-form (v1).
 *
 * Four spam layers run before validation; every rejection converges on ONE
 * closure ($reject_as_bot) so a layer added later cannot forget the
 * quarantine write or the strike accounting by omission.
 *
 * Bot-detected requests render the site's ordinary 404 — not an error, not a
 * redirect. A scanner cannot tell "blocked" from "this URL never existed",
 * and the 404 page's contact hint (see i18n key notfound_form_hint) is the
 * route back for a human caught by mistake.
 */
declare(strict_types=1);

require_once ROOT . '/lib/spam.php';

$h = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

$form_errors  = [];
$form_values  = ['name' => '', 'email' => '', 'subject' => '', 'message' => ''];
$sent         = isset($_GET['sent']);
$failed       = false;
$rate_limited = false;
$mail_debug   = '';

$client_ip   = client_ip();
$contact_url = lang_path($lang, 'contact');

// The bot response: the site's own 404, with whatever has been rendered
// discarded. `&& ob_end_clean()` rather than the level check alone — a
// buffer that refuses deletion leaves the level unchanged, so testing only
// the level would spin forever.
$serve_bot_404 = static function () use ($lang, $alternates) {
    while (ob_get_level() > 0 && ob_end_clean()) {
        // Discard partial output; the 404 replaces the whole response.
    }
    http_response_code(404);
    ob_start();
    include ROOT . '/templates/404.php';
    $page_content = ob_get_clean();
    include ROOT . '/templates/layout.php';
    exit;
};

// EVERY detection layer goes through this closure. The RESPONSE is identical
// on every strike — a bot sees the same 404 whether it is its first offence
// or its tenth — so nothing tells a scanner how close it is to being listed.
// Only the bookkeeping escalates, which is what lets a false positive be
// forgiven without giving anything away.
$reject_as_bot = static function (string $reason, bool $block_immediately = false)
    use ($client_ip, $serve_bot_404) {

    spam_quarantine($client_ip, $reason, $_POST);
    if ($block_immediately || spam_record_bot_strike($client_ip)) {
        spam_block_ip($client_ip, $reason);
    }
    $serve_bot_404();
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_once ROOT . '/lib/mailer.php';

    // Layer 0: the permanent blocklist. Known bots never reach the logic
    // below again, and learn nothing they could tune against. No quarantine
    // record here — their earlier submissions are already on file, and
    // writing one per attempt would hand an attacker an unbounded disk write
    // (this runs before the rate limiter).
    if (spam_is_blocked($client_ip)) {
        $serve_bot_404();
    }

    // Layer 1: honeypot. The field name threads two needles: NOT
    // "honeypot"/"website"/"url" (published spambot skip-lists name those),
    // and NOT anything in browser autofill's vocabulary — autofill fills
    // hidden fields and ignores autocomplete="off" for address-profile data,
    // so a field called "company_url" gets filled by Chrome for a real human.
    // Never rename this to anything containing contact/address/organization/
    // URL keywords.
    //
    // `!== ''` rather than `!empty()`: empty('0') is TRUE in PHP, so a bot
    // that filled the honeypot with the single character "0" would walk
    // straight past this layer.
    if (($_POST['order_ref'] ?? '') !== '') {
        $reject_as_bot('honeypot');
    }

    // Layer 2: HMAC time token. Missing, forged or sub-3-second submissions.
    $token_state = spam_verify_token((string) ($_POST['t'] ?? ''));
    if ($token_state !== 'ok') {
        $reject_as_bot('token:' . $token_state);
    }

    // Layer 3: behavioural signal. JS sets this on the first real input
    // event (mousemove / touch / key / pointer). A headless bot that does not
    // simulate input never sets it. No-JS humans get the <noscript> mailto
    // fallback below.
    if (($_POST['hb'] ?? '') !== '1') {
        $reject_as_bot('no_human_signal');
    }

    // Layer 4: escalating per-IP limit. This one CAN catch a real human
    // (someone refreshing an unsent form), so the first two offences show a
    // friendly message; only the third — clearly automated — is blocked.
    $rate = spam_ratelimit_check($client_ip);
    if (!$rate['allowed']) {
        if ($rate['block']) {
            $reject_as_bot('ratelimit_repeat', true);
        }
        $rate_limited = true;
    }

    $form_values['name']    = trim((string) ($_POST['name']    ?? ''));
    $form_values['email']   = trim((string) ($_POST['email']   ?? ''));
    $form_values['subject'] = trim((string) ($_POST['subject'] ?? ''));
    $form_values['message'] = trim((string) ($_POST['message'] ?? ''));

    if (!$rate_limited) {
        if ($form_values['name'] === '' || mb_strlen($form_values['name']) > 100) {
            $form_errors['name'] = t('contact_error_name');
        }
        if ($form_values['email'] === '' || !filter_var($form_values['email'], FILTER_VALIDATE_EMAIL)) {
            $form_errors['email'] = t('contact_error_email');
        }
        if ($form_values['subject'] === '' || mb_strlen($form_values['subject']) > 200) {
            $form_errors['subject'] = t('contact_error_subject');
        }
        // The message is REQUIRED. It costs a serious sender nothing and
        // removes the case that is impossible to answer: a submission whose
        // intent has to be guessed from a subject line.
        if ($form_values['message'] === '') {
            $form_errors['message'] = t('contact_error_message');
        } elseif (mb_strlen($form_values['message']) > 5000) {
            $form_errors['message'] = t('contact_error_message_long');
        }

        if ($form_errors === []) {
            $ok = send_contact_mail(
                $form_values['name'],
                $form_values['email'],
                $form_values['subject'],
                $form_values['message'],
                $lang,
                $mail_debug
            );
            if ($ok) {
                // POST/redirect/GET so a refresh cannot resubmit.
                header('Location: ' . $contact_url . '?sent=1', true, 303);
                exit;
            }
            $failed = true;
        }
    }
}

$intro = load_page('contact', $lang);
$title = $intro !== null ? (string) ($intro['meta']['title'] ?? t('contact_title')) : t('contact_title');

$seo = [
    'lang'        => $lang,
    'title'       => $title . ' — ' . cfg('site.name'),
    'description' => $intro !== null ? auto_description($intro) : t('contact_intro'),
    'alternates'  => $alternates,
    'breadcrumbs' => [
        ['name' => t('nav_home'), 'url' => lang_path($lang, 'home')],
        ['name' => $title,        'url' => current_path()],
    ],
];
?>
<section class="content-hero">
  <div class="content-hero-inner">
    <h1><?= $h($title) ?></h1>
    <?php if ($intro !== null): ?>
      <div class="content-lead"><?= $intro['html'] ?></div>
    <?php else: ?>
      <p class="content-lead"><?= $h(t('contact_intro')) ?></p>
    <?php endif; ?>
  </div>
</section>

<section class="contact-body">
  <div class="contact-inner">
<?php if ($sent): ?>
    <p class="form-notice form-notice-ok" role="status"><?= $h(t('contact_sent')) ?></p>
<?php endif; ?>
<?php if ($rate_limited): ?>
    <p class="form-notice form-notice-warn" role="alert"><?= $h(t('contact_error_rate_limited')) ?></p>
<?php endif; ?>
<?php if ($failed): ?>
    <p class="form-notice form-notice-error" role="alert">
      <?= $h(t('contact_error_send')) ?>
      <?php if ($mail_debug !== '' && getenv('APP_DEBUG')): ?>
        <br><small><?= $h($mail_debug) ?></small>
      <?php endif; ?>
    </p>
<?php endif; ?>

    <form method="post" action="<?= $h($contact_url) ?>" class="contact-form" novalidate>
      <?php /* Layer 2: the HMAC time token. */ ?>
      <input type="hidden" name="t" value="<?= $h(spam_make_token()) ?>">
      <?php /* Layer 3: set to "1" by JS on the first real input event. */ ?>
      <input type="hidden" name="hb" id="cf_hb" value="">

      <?php /* Layer 1: the honeypot. Offscreen via CSS, never seen by a human. */ ?>
      <div class="form-meta" aria-hidden="true">
        <label for="order_ref">Order reference</label>
        <input type="text" name="order_ref" id="order_ref" tabindex="-1" autocomplete="off">
      </div>

      <div class="form-row">
        <label for="cf_name"><?= $h(t('contact_field_name')) ?></label>
        <input type="text" name="name" id="cf_name" maxlength="100" required
               value="<?= $h($form_values['name']) ?>"
               <?= isset($form_errors['name']) ? 'aria-invalid="true"' : '' ?>>
        <?php if (isset($form_errors['name'])): ?><p class="form-error"><?= $h($form_errors['name']) ?></p><?php endif; ?>
      </div>

      <div class="form-row">
        <label for="cf_email"><?= $h(t('contact_field_email')) ?></label>
        <input type="email" name="email" id="cf_email" maxlength="200" required
               value="<?= $h($form_values['email']) ?>"
               <?= isset($form_errors['email']) ? 'aria-invalid="true"' : '' ?>>
        <?php if (isset($form_errors['email'])): ?><p class="form-error"><?= $h($form_errors['email']) ?></p><?php endif; ?>
      </div>

      <div class="form-row">
        <label for="cf_subject"><?= $h(t('contact_field_subject')) ?></label>
        <input type="text" name="subject" id="cf_subject" maxlength="200" required
               value="<?= $h($form_values['subject']) ?>"
               <?= isset($form_errors['subject']) ? 'aria-invalid="true"' : '' ?>>
        <?php if (isset($form_errors['subject'])): ?><p class="form-error"><?= $h($form_errors['subject']) ?></p><?php endif; ?>
      </div>

      <div class="form-row">
        <label for="cf_message"><?= $h(t('contact_field_message')) ?></label>
        <textarea name="message" id="cf_message" rows="7" maxlength="5000" required
                  <?= isset($form_errors['message']) ? 'aria-invalid="true"' : '' ?>><?= $h($form_values['message']) ?></textarea>
        <?php if (isset($form_errors['message'])): ?><p class="form-error"><?= $h($form_errors['message']) ?></p><?php endif; ?>
      </div>

      <div class="form-row form-row-check">
        <input type="checkbox" id="cf_human" name="human" disabled>
        <label for="cf_human"><?= $h(t('contact_human_check')) ?></label>
      </div>

      <button type="submit" id="cf_submit" class="button button-primary" disabled>
        <?= $h(t('contact_submit')) ?>
      </button>

      <noscript>
        <p class="form-notice form-notice-warn">
          <?= $h(t('contact_js_required')) ?>
          <a href="mailto:<?= $h((string) cfg('identity.email')) ?>"><?= $h((string) cfg('identity.email')) ?></a>
        </p>
      </noscript>
    </form>
  </div>
</section>

<script>
// Layer 3, client half: arm the form on the first genuine input event. A
// headless bot that never moves a pointer or presses a key leaves the marker
// empty and is rejected server-side. Arming happens within ~1s of load, but
// Layer 2's 3-second floor is on TOKEN AGE at submit time, so an
// instant-arming bot still has to wait like everybody else.
(function () {
  var marker   = document.getElementById('cf_hb');
  var checkbox = document.getElementById('cf_human');
  var submit   = document.getElementById('cf_submit');
  if (!marker || !checkbox || !submit) return;

  function arm() {
    marker.value    = '1';
    checkbox.checked = true;
    checkbox.disabled = false;
    submit.disabled  = false;
  }
  ['mousemove', 'touchstart', 'keydown', 'pointerdown'].forEach(function (evt) {
    window.addEventListener(evt, arm, { once: true, passive: true });
  });
})();
</script>
