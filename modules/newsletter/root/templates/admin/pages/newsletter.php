<?php
/**
 * Newsletter admin page — installed from modules/newsletter (v1).
 *
 * Dropped into templates/admin/pages/ and discovered automatically by the
 * admin module. There is no registry to edit, so installing this cannot
 * conflict with another module's page.
 *
 * Included twice: once with $admin_describe set, to return its menu entry,
 * and once to render. Authentication has already been enforced by the admin
 * front controller before this file is reached.
 */
declare(strict_types=1);

if (isset($admin_describe)) {
    return ['title_key' => 'newsletter_admin_title', 'order' => 20];
}

require_once ROOT . '/lib/newsletter.php';

$h = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

// Export is a POST, not a GET link: it is a bulk read of personal data, so
// it carries the CSRF token like every other admin action.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'export') {
    if (!admin_csrf_valid($_POST['csrf'] ?? null)) {
        http_response_code(400);
        echo '<p class="form-notice form-notice-error">' . $h(t('admin_error_csrf')) . '</p>';
        return;
    }
    while (ob_get_level() > 0 && ob_end_clean()) {
        // The CSV is the whole response.
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="subscribers.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['email', 'status', 'language', 'confirmed_at']);
    foreach (newsletter_confirmed() as $record) {
        fputcsv($out, [$record['email'] ?? '', $record['status'] ?? '', $record['lang'] ?? '', $record['time'] ?? '']);
    }
    fclose($out);
    exit;
}

$all       = newsletter_subscribers();
$confirmed = newsletter_confirmed();
$pending   = array_filter($all, static fn(array $r) => ($r['status'] ?? '') === 'pending');
?>
<h1><?= $h(t('newsletter_admin_title')) ?></h1>

<p>
  <strong><?= count($confirmed) ?></strong> <?= $h(t('newsletter_admin_confirmed')) ?> ·
  <strong><?= count($pending) ?></strong> <?= $h(t('newsletter_admin_pending')) ?>
</p>

<?php if ($confirmed !== []): ?>
<form method="post" action="">
  <input type="hidden" name="csrf" value="<?= $h(admin_csrf_token()) ?>">
  <input type="hidden" name="action" value="export">
  <button type="submit" class="button button-primary"><?= $h(t('newsletter_admin_export')) ?></button>
</form>
<?php endif; ?>

<table class="admin-table">
  <tr>
    <th><?= $h(t('newsletter_admin_email')) ?></th>
    <th><?= $h(t('newsletter_admin_status')) ?></th>
    <th><?= $h(t('newsletter_admin_lang')) ?></th>
    <th><?= $h(t('newsletter_admin_when')) ?></th>
  </tr>
<?php foreach ($all as $record): ?>
  <tr>
    <td><?= $h((string) ($record['email'] ?? '')) ?></td>
    <td><?= $h((string) ($record['status'] ?? '')) ?></td>
    <td><?= $h((string) ($record['lang'] ?? '')) ?></td>
    <td><?= $h((string) ($record['time'] ?? '')) ?></td>
  </tr>
<?php endforeach; ?>
<?php if ($all === []): ?>
  <tr><td colspan="4"><?= $h(t('newsletter_admin_empty')) ?></td></tr>
<?php endif; ?>
</table>

<p class="admin-note"><?= $h(t('newsletter_admin_sending_note')) ?></p>
