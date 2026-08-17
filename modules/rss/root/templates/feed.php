<?php
/**
 * Feed responder — installed from modules/rss (v1).
 *
 * Emits XML and exits, so the HTML layout never runs. Buffers opened by the
 * front controller are discarded first; `&& ob_end_clean()` rather than the
 * level check alone, because a buffer that refuses deletion would otherwise
 * spin here forever.
 */
declare(strict_types=1);

require_once ROOT . '/lib/feeds.php';

while (ob_get_level() > 0 && ob_end_clean()) {
    // Discard anything already buffered; the feed is the whole response.
}

$is_atom = ($route ?? '') === 'feed_atom';

header('Content-Type: ' . ($is_atom ? 'application/atom+xml' : 'application/rss+xml') . '; charset=utf-8');
// Feed readers poll; a short cache window is polite to both sides.
header('Cache-Control: public, max-age=1800');

echo $is_atom ? build_atom_feed($lang) : build_rss_feed($lang);
exit;
