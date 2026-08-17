<?php
declare(strict_types=1);

// Installed from modules/contact-form (v1).
//
// Module routes live in their own file. lib/config.php merges every
// config/routes.d/*.php into the route table, so installing a module never
// edits config/site.php and two modules can never conflict except by
// claiming the same URL — which the installer catches as a filename
// collision before anything is written.
//
// Add the page to your header by putting an entry in the 'nav' array of
// config/site.php: ['key' => 'nav_contact', 'route' => 'contact'].
// Nav order is the site owner's decision, so no module sets it.
return [
    'contact' => [
        'template' => 'contact',
        'paths'    => [
            'en' => '/contact/',
            'da' => '/kontakt/',
        ],
        'priority' => '0.6',
    ],
];
