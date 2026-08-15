<?php
declare(strict_types=1);

// config/site.php — the ONLY file that knows who this site belongs to.
//
// Everything brand-shaped lives here (plus the colour tokens in
// public/assets/css/tokens.css). lib/ and templates/ read this file through
// cfg() and never hard-code an identity fact. The setup interview
// (docs/SETUP.md) rewrites this file wholesale; `php -l config/site.php`
// validates it afterwards.
//
// The values below describe the demo site, "Nordlys Kaffe" — a fictional
// Scandinavian coffee roastery. Replace them with your own.
return [
    'site' => [
        'name'         => 'Nordlys Kaffe',
        // Canonical production URL. Used as the fallback host when a request
        // has no Host header (CLI scripts), and by the validator.
        'url'          => 'https://example.com',
        // 'org' (a company/organization site) or 'person' (a personal site).
        // Selects the JSON-LD shape search engines see.
        'profile'      => 'org',
        'default_lang' => 'en',
        // Short tagline per language — hero + meta fallbacks.
        'tagline'      => [
            'en' => 'Small-batch coffee, roasted under the northern lights.',
            'da' => 'Kaffe ristet i små portioner under nordlyset.',
        ],
        // One-paragraph site description per language. Feeds llms.txt and the
        // homepage's markdown representation for AI agents.
        'description'  => [
            'en' => 'Nordlys Kaffe is a fictional demo roastery. This paragraph describes what the site is about, for both search engines and AI agents. Replace it with a real description of your business or yourself.',
            'da' => 'Nordlys Kaffe er et fiktivt demo-risteri. Dette afsnit beskriver hvad sitet handler om, til både søgemaskiner og AI-agenter. Erstat det med en rigtig beskrivelse.',
        ],
        // Used for <meta name="theme-color"> only; the full palette lives in
        // public/assets/css/tokens.css.
        'theme_color'  => '#1d5c8f',
        // Timezone for "is this post published yet?" decisions.
        'timezone'     => 'Europe/Copenhagen',
    ],

    'identity' => [
        // The person or legal entity behind the site. Optional fields are
        // null — omit them and the corresponding markup is simply not emitted.
        'legal_name'  => 'Nordlys Kaffe ApS',   // org profile; ignored for person
        'owner_name'  => 'Astrid Holm',          // author/founder; the Person node
        'email'       => 'hello@example.com',
        'phone'       => null,                   // e.g. '+4512345678'
        'vat_id'      => null,                   // e.g. 'DK12345678' — emitted in JSON-LD
        'iban'        => null,                   // published payment account, if you want one public
        'street'      => null,
        'postal_code' => null,
        'locality'    => null,
        'country'     => null,                   // ISO 3166-1 alpha-2, e.g. 'DK'
        'logo'        => '/assets/img/logo.svg',
        'default_og_image' => '/assets/img/og-default.jpg',
        // Public profile URLs (LinkedIn, GitHub, Mastodon…) — JSON-LD sameAs.
        'socials'     => [],
    ],

    // 1..n languages. The default language lives at the URL root (empty
    // prefix); every other language gets a path prefix. Adding a language =
    // adding a row here + an i18n/<lang>.php file + content/<lang>/ files.
    'languages' => [
        'en' => ['label' => 'English', 'prefix' => '',    'locale' => 'en_US'],
        'da' => ['label' => 'Dansk',   'prefix' => '/da', 'locale' => 'da_DK'],
    ],

    // Route table. Each route names a template and either exact 'paths' or
    // 'patterns' (with {slug}) per language; the language prefix is prepended
    // automatically. The 'page' catch-all is matched LAST — keep it last here.
    // Modules drop additional route files into config/routes.d/ (merged at
    // load; they never edit this file).
    'routes' => [
        'home'    => ['template' => 'home',    'paths'    => ['en' => '/',       'da' => '/']],
        'archive' => ['template' => 'archive', 'paths'    => ['en' => '/posts/', 'da' => '/indlaeg/']],
        'post'    => ['template' => 'post',    'patterns' => ['en' => '/posts/{slug}/', 'da' => '/indlaeg/{slug}/']],
        'page'    => ['template' => 'page',    'patterns' => ['en' => '/{slug}/', 'da' => '/{slug}/']],
    ],

    // Header navigation, in order. 'route' + optional 'slug' for page routes;
    // 'key' is the i18n label key.
    'nav' => [
        ['key' => 'nav_home',  'route' => 'home'],
        ['key' => 'nav_posts', 'route' => 'archive'],
        ['key' => 'nav_about', 'route' => 'page', 'slug' => 'about'],
    ],

    // Post categories: code => label per language. Posts reference the code
    // in frontmatter (`category: news`); labels render at the boundary.
    'categories' => [
        'news'    => ['en' => 'News',    'da' => 'Nyheder'],
        'recipes' => ['en' => 'Recipes', 'da' => 'Opskrifter'],
    ],

    // SEO / crawler policy.
    'seo' => [
        // Content Signals (contentsignals.org) emitted in robots.txt.
        // Flip any value to =no to opt out of that use.
        'content_signal' => 'search=yes, ai-input=yes, ai-train=yes',
        // Extra Disallow lines for the `User-agent: *` group (modules may
        // ask you to add entries here, e.g. a form's ?sent= confirmation).
        'robots_disallow' => [],
    ],

    // Legacy-URL handling: exact paths that return 410 Gone (removed pages)
    // and 301 redirects (moved pages). The wp-migration module fills these
    // from an old WordPress site's URL inventory.
    'gone'      => [],
    'redirects' => [],

    // Record of installed modules (name => true). Written by
    // scripts/module.php; templates may consult it. Not a runtime loader.
    'modules' => [],
];
