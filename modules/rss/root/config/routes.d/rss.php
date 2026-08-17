<?php
declare(strict_types=1);

// Installed from modules/rss (v1).
//
// One feed pair per language. The paths carry a dot, which matters: nginx's
// trailing-slash redirect skips paths containing a dot, so /feed.xml reaches
// the front controller untouched instead of being redirected to /feed.xml/.
//
// `sitemap => false` — a feed is for readers, not for the index; the posts
// it lists are already in the sitemap individually.
return [
    'feed_rss' => [
        'template' => 'feed',
        'paths'    => ['en' => '/feed.xml', 'da' => '/feed.xml'],
        'sitemap'  => false,
    ],
    'feed_atom' => [
        'template' => 'feed',
        'paths'    => ['en' => '/atom.xml', 'da' => '/atom.xml'],
        'sitemap'  => false,
    ],
];
