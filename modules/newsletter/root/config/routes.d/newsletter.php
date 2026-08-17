<?php
declare(strict_types=1);

// Installed from modules/newsletter (v1).
//
// Two routes, one template: the signup page, and the target of the
// confirmation and unsubscribe links. Add the signup page to your header by
// putting ['key' => 'nav_newsletter', 'route' => 'newsletter'] in the 'nav'
// array of config/site.php.
return [
    'newsletter' => [
        'template' => 'subscribe',
        'paths'    => ['en' => '/subscribe/', 'da' => '/nyhedsbrev/'],
        'priority' => '0.4',
    ],
    'newsletter_confirm' => [
        'template' => 'subscribe',
        'paths'    => ['en' => '/subscribe/confirm/', 'da' => '/nyhedsbrev/bekraeft/'],
        // A confirmation URL is personal and single-purpose; it has no place
        // in a sitemap.
        'sitemap'  => false,
    ],
];
