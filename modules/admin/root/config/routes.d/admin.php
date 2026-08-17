<?php
declare(strict_types=1);

// Installed from modules/admin (v1).
//
// The admin area is one route with an optional {slug}, dispatched by
// templates/admin.php. It is the same path in every language — an admin
// area has one owner, not an audience, so translating its URL would only
// create a second address to protect.
//
// `sitemap => false` keeps it out of sitemap.xml. Note there is deliberately
// no robots.txt Disallow either: that would publish the path to everyone who
// reads robots.txt. The X-Robots-Tag: noindex header on every admin response
// is what actually keeps it out of search results.
//
// If you set ADMIN_PATH in .env to something other than /admin, change the
// paths here to match.
$admin_base = rtrim(getenv('ADMIN_PATH') ?: '/admin', '/');

return [
    'admin' => [
        'template' => 'admin',
        'paths'    => [ROUTE_ANY_LANG => $admin_base . '/'],
        'sitemap'  => false,
    ],
    'admin_page' => [
        'template' => 'admin',
        'patterns' => [ROUTE_ANY_LANG => $admin_base . '/{slug}/'],
        'sitemap'  => false,
    ],
];
