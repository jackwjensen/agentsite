<?php
/**
 * Admin front controller — installed from modules/admin (v1).
 *
 * ONE entry point for the whole admin area. It authenticates first and
 * dispatches second, so an individual page can never forget the check. The
 * page slug is validated against the discovered page list rather than used
 * to build a path, so there is no include an attacker can steer.
 *
 * Routed by config/routes.d/admin.php, which maps the base path and a
 * {slug} pattern to this template.
 */
declare(strict_types=1);

require_once ROOT . '/lib/admin.php';

$h = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

admin_send_headers();

$admin_slug   = (string) ($slug ?? '');
$login_error  = '';
$pages        = admin_pages();

// ── Logout ───────────────────────────────────────────────────────────────
if ($admin_slug === 'logout') {
    admin_logout();
    header('Location: ' . admin_url(), true, 303);
    exit;
}

// ── Login POST ───────────────────────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !admin_is_authenticated()) {
    if (!admin_csrf_valid($_POST['csrf'] ?? null)) {
        $login_error = t('admin_error_csrf');
    } else {
        $result = admin_attempt_login((string) ($_POST['password'] ?? ''));
        if ($result === 'ok') {
            // Redirect after login so a refresh cannot repost the password.
            header('Location: ' . admin_url($admin_slug), true, 303);
            exit;
        }
        $login_error = $result === 'throttled' ? t('admin_error_throttled') : t('admin_error_password');
    }
}

// ── Gate ─────────────────────────────────────────────────────────────────
if (!admin_is_authenticated()) {
    // Stealth mode: an unauthenticated visitor cannot tell the admin area
    // from a dead URL. Same philosophy as the contact form's bot-404.
    if (admin_is_stealth() && ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(404);
        include ROOT . '/templates/404.php';
        return;
    }

    $seo = ['lang' => $lang, 'title' => t('admin_login_title'), 'noindex' => true, 'alternates' => []];
    ?>
    <section class="admin-login">
      <div class="admin-login-inner">
        <h1><?= $h(t('admin_login_title')) ?></h1>
<?php if ($login_error !== ''): ?>
        <p class="form-notice form-notice-error" role="alert"><?= $h($login_error) ?></p>
<?php endif; ?>
<?php if (admin_password_hash() === ''): ?>
        <p class="form-notice form-notice-warn">
          <?= $h(t('admin_no_password_set')) ?>
          <code>php scripts/admin-password.php</code>
        </p>
<?php endif; ?>
        <form method="post" action="<?= $h(admin_url($admin_slug)) ?>" class="admin-form">
          <input type="hidden" name="csrf" value="<?= $h(admin_csrf_token()) ?>">
          <div class="form-row">
            <label for="admin_password"><?= $h(t('admin_password_label')) ?></label>
            <input type="password" name="password" id="admin_password" autocomplete="current-password" required autofocus>
          </div>
          <button type="submit" class="button button-primary"><?= $h(t('admin_login_button')) ?></button>
        </form>
      </div>
    </section>
    <?php
    return;
}

// ── Authenticated ────────────────────────────────────────────────────────
$seo = ['lang' => $lang, 'title' => t('admin_title'), 'noindex' => true, 'alternates' => []];

// A page slug is only valid if it was discovered — never a constructed path.
$current = $admin_slug !== '' && isset($pages[$admin_slug]) ? $pages[$admin_slug] : null;
if ($admin_slug !== '' && $current === null) {
    http_response_code(404);
    include ROOT . '/templates/404.php';
    return;
}
?>
<section class="admin-shell">
  <header class="admin-bar">
    <a class="admin-brand" href="<?= $h(admin_url()) ?>"><?= $h(t('admin_title')) ?></a>
    <nav class="admin-nav" aria-label="<?= $h(t('admin_title')) ?>">
<?php foreach ($pages as $page): ?>
      <a href="<?= $h(admin_url($page['slug'])) ?>"<?= $current !== null && $current['slug'] === $page['slug'] ? ' aria-current="page"' : '' ?>><?= $h(t($page['title_key'])) ?></a>
<?php endforeach; ?>
      <a class="admin-logout" href="<?= $h(admin_url('logout')) ?>"><?= $h(t('admin_logout')) ?></a>
    </nav>
  </header>

  <div class="admin-body">
<?php
if ($current !== null) {
    // The page renders itself. $admin_describe is NOT set here, so the file
    // runs its page body rather than returning its description.
    include $current['file'];
} else {
    ?>
    <h1><?= $h(t('admin_dashboard')) ?></h1>
<?php if ($pages === []): ?>
    <p><?= $h(t('admin_no_pages')) ?></p>
<?php else: ?>
    <ul class="admin-page-list">
<?php foreach ($pages as $page): ?>
      <li><a href="<?= $h(admin_url($page['slug'])) ?>"><?= $h(t($page['title_key'])) ?></a></li>
<?php endforeach; ?>
    </ul>
<?php endif;
}
?>
  </div>
</section>
