# Creating a module

A module is a directory that mirrors the repository root, plus two files
describing it. Installing one copies files into place; nothing shared is ever
edited. Get that right and everything else — clean uninstall, no conflicts,
automated review — follows.

## Layout

```
modules/my-feature/
├── module.json          machine-readable manifest
├── MODULE.md            what it does, what it costs, how to verify
└── root/                copied verbatim into the site
    ├── lib/my-feature.php
    ├── templates/my-feature.php
    ├── config/routes.d/my-feature.php
    ├── i18n/modules/my-feature.en.php
    ├── i18n/modules/my-feature.da.php
    └── public/assets/css/my-feature.css
```

Every path under `root/` is where the file lands. **Name your files after
your module.** A file called `lib/helpers.php` is a collision waiting to
happen; `lib/my-feature.php` cannot collide with anything.

## `module.json`

```json
{
  "name": "my-feature",
  "version": "1",
  "description": "One line, shown by `module.php list`.",
  "requires": [],
  "core_min": "1",
  "env": { "MY_FEATURE_TOKEN": "" },
  "verify": [
    "php scripts/validate-content.php",
    "curl -sf http://localhost:8080/my-feature/ | grep -q something"
  ]
}
```

`env` entries are appended to `.env` if absent — **existing values are never
overwritten**, so a reinstall cannot reset a password somebody already set.

## Never edit a shared file

This is the whole contract. Instead of editing, drop a file where the core
looks:

| You want to | Drop this file |
|---|---|
| Add a URL | `config/routes.d/<name>.php` returning a route array |
| Add UI strings | `i18n/modules/<name>.<lang>.php` returning key => string |
| Add something to `<head>` | `templates/partials/head/<name>.php` |
| Add an admin page | `templates/admin/pages/<name>.php` (needs `"requires": ["admin"]`) |
| Add page-specific CSS | `public/assets/css/<template-name>.css` |

The CSS one is worth spelling out: the front controller loads
`public/assets/css/<template>.css` when a page uses `templates/<template>.php`.
Name them the same and your styles load on your pages and nowhere else.

**Optional strings in a core template.** If you need a core page to show
something when your module is present, use `t_opt('your_key')`, which returns
`''` when the key is not defined. The contact-form module uses this to put a
line on the 404 page. Do not ask for a hook to be added — the string's
presence *is* the hook.

## Routes

```php
<?php
declare(strict_types=1);

return [
    'my_feature' => [
        'template' => 'my-feature',
        'paths'    => ['en' => '/my-feature/', 'da' => '/min-funktion/'],
        'priority' => '0.5',
    ],
];
```

- Paths are **prefix-relative**: the language prefix is prepended for you, so
  `'/my-feature/'` under a `/da` language becomes `/da/my-feature/`.
- Use `'patterns'` with `{slug}` instead of `'paths'` for a dynamic URL.
- `'sitemap' => false` keeps a route out of `sitemap.xml` — right for admin
  pages, feeds, and anything personal.
- For a page that should have **one URL regardless of language** (an admin
  area, a webhook), use `ROUTE_ANY_LANG` as the key instead of a language
  code.
- A path containing a dot (`/feed.xml`) is treated as a file, not a
  directory, and keeps its exact shape.

## Templates

A module template is an ordinary template: set `$seo`, echo HTML, and let the
layout wrap it. Two variations:

- To emit something that is **not** an HTML page (a feed, a CSV), discard the
  output buffers, send your headers, echo, and `exit` — see
  `modules/rss/root/templates/feed.php`.
- Images must go through `render_picture()`. CI rejects a raw `<img>`.

## Storing data

Runtime state goes under `_logs/` (bind-mounted, gitignored). Create your own
subdirectory. If you store anything about a visitor, your `MODULE.md` **must**
tell the user what to put in their privacy policy, and say whether it is
pruned.

For rate limiting, use the core primitive rather than writing another one:

```php
require_once ROOT . '/lib/ratelimit.php';
$limit = ratelimit_check('my-feature', client_ip(), 5, 3600);
if (!$limit['allowed']) { /* … */ }
```

## `MODULE.md`

Frontmatter, then prose. The sections that matter:

- **What it adds** — a table of paths, so a user can see the blast radius.
- **Configuration** — every variable, and what happens if it is wrong.
- **What it stores** — personal data, retention, and the privacy-policy
  wording it obliges.
- **Verification** — commands that prove it works, and what a correct result
  looks like.
- **Uninstall** — what is removed and what is deliberately left behind.

Write down *why* anything non-obvious is the way it is. The reasoning is the
part that stops the next person "simplifying" a safety property away.

## Testing it

```bash
php scripts/module.php install my-feature
./dev.sh check
# ... run your own verify commands ...
php scripts/module.php uninstall my-feature
git status --porcelain          # MUST be empty
```

That last line is the test that matters, and CI runs it for every in-tree
module on every pull request. If the tree is not byte-identical after a
round-trip, something wrote outside the payload.

## Publishing

Contribute it here (see [CONTRIBUTING.md](../CONTRIBUTING.md)), or publish it
yourself — see [COMMUNITY-MODULES.md](COMMUNITY-MODULES.md).
