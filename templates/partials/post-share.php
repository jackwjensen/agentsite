<?php
/**
 * Share bar — plain intent URLs, no third-party scripts, plus a copy-link
 * button. Expects $title and $lang from the including post template.
 */
declare(strict_types=1);

$h          = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$share_url  = abs_url(current_path());
$share_text = rawurlencode($title ?? '');
$share_enc  = rawurlencode($share_url);
?>
<div class="post-share" role="group" aria-label="<?= $h(t('share_label')) ?>">
  <span class="post-share-title"><?= $h(t('share_label')) ?>:</span>
  <a href="https://www.linkedin.com/sharing/share-offsite/?url=<?= $share_enc ?>" target="_blank" rel="noopener">LinkedIn</a>
  <a href="https://x.com/intent/post?url=<?= $share_enc ?>&amp;text=<?= $share_text ?>" target="_blank" rel="noopener">X</a>
  <a href="https://www.facebook.com/sharer/sharer.php?u=<?= $share_enc ?>" target="_blank" rel="noopener">Facebook</a>
  <a href="mailto:?subject=<?= $share_text ?>&amp;body=<?= $share_enc ?>"><?= $h(t('share_email')) ?></a>
  <button type="button" class="post-share-copy" data-url="<?= $h($share_url) ?>"><?= $h(t('share_copy')) ?></button>
</div>
<script>
(function () {
  var btn = document.querySelector('.post-share-copy');
  if (!btn) return;
  btn.addEventListener('click', function () {
    var url  = btn.getAttribute('data-url');
    var done = function () {
      btn.textContent = <?= json_encode(t('share_copied'), JSON_HEX_TAG) ?>;
      setTimeout(function () { btn.textContent = <?= json_encode(t('share_copy'), JSON_HEX_TAG) ?>; }, 2000);
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(url).then(done);
    } else {
      var input = document.createElement('input');
      input.value = url;
      document.body.appendChild(input);
      input.select();
      document.execCommand('copy');
      document.body.removeChild(input);
      done();
    }
  });
})();
</script>
