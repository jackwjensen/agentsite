<?php
/**
 * Feed autodiscovery — installed from modules/rss (v1).
 *
 * Included in <head> on every page by the layout's head-partial discovery.
 * One pair of links per configured language, so a reader subscribing from
 * the Danish side of a bilingual site gets the Danish feed.
 */
declare(strict_types=1);

$feed_escape = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$feed_site   = (string) cfg('site.name');

foreach (site_langs() as $feed_lang) {
    $label = $feed_site;
    if (count(cfg('languages')) > 1) {
        $label .= ' (' . (string) (cfg('languages')[$feed_lang]['label'] ?? $feed_lang) . ')';
    }
    echo '  <link rel="alternate" type="application/rss+xml" title="' . $feed_escape($label)
       . '" href="' . $feed_escape(lang_path($feed_lang, 'feed_rss')) . "\">\n";
    echo '  <link rel="alternate" type="application/atom+xml" title="' . $feed_escape($label . ' — Atom')
       . '" href="' . $feed_escape(lang_path($feed_lang, 'feed_atom')) . "\">\n";
}
