---
module: admin
version: 1
requires: []
core_min: "1"
---

# Admin area

A small password-protected admin area. **The default site has none, and that
is the point** — an admin URL that does not exist cannot be attacked, and a
markdown website usually has nothing to administer. Install this only when a
module needs a screen, or when you want one.

    php scripts/module.php install admin

Modules that need an admin page declare `"requires": ["admin"]` in their
manifest, and the installer pulls this in for them.

## Set a password before anything else

    php scripts/admin-password.php

It prompts twice and writes the bcrypt hash to
`config/admin-password.hash`, which is gitignored. **The plaintext password
is never stored anywhere** — not in that file, not in `.env`, not in the
repo, not in a log. Changing or resetting it means running the script again.
There is deliberately no "forgot password" flow: for a single-owner area, a
reset flow is an attack surface that buys nothing.

**Why a file and not an environment variable.** A bcrypt hash is full of `$`
characters, and Docker Compose interpolates `$` sequences in `.env` — a hash
pasted there reaches the application with a chunk silently eaten, and the
only symptom is that the correct password stops working. A file is read by
PHP alone and cannot be mangled in transit. `ADMIN_PASSWORD_HASH` is still
honoured for hosts where a secret can only arrive as an environment
variable; there, write every `$` as `$$` in any compose-interpolated context.

Until a hash exists, the login page says so and no password will work.

## What it adds

| Path | What it is |
|---|---|
| `config/admin-password.hash` | *(created by the script, gitignored)* the bcrypt hash |
| `lib/admin.php` | Sessions, login, CSRF, page discovery, audit log |
| `templates/admin.php` | The one entry point: authenticates, then dispatches |
| `config/routes.d/admin.php` | `/admin/` and `/admin/{slug}/` |
| `i18n/modules/admin.{en,da}.php` | Its UI strings |
| `public/assets/css/admin.css` | Admin styling (loaded only on admin pages) |
| `scripts/admin-password.php` | The password-hash generator |

## Settings

| Variable | Default | What it does |
|---|---|---|
| `ADMIN_PATH` | `/admin` | Move the admin area to a different path. Change `config/routes.d/admin.php` to match. |
| `ADMIN_STEALTH` | *(off)* | Set to `1` and an unauthenticated visitor gets the site's ordinary **404** instead of a login form. |

## How it is protected

- **Session cookie**: `HttpOnly`, `SameSite=Strict`, and `Secure` whenever the
  request actually arrived over TLS (checked through the proxy header, so a
  developer on plain `http://localhost` can still sign in).
- **Session id is regenerated on login**, so an id planted before sign-in
  cannot become an authenticated one.
- **Two timeouts**: one hour idle, twelve hours absolute.
- **CSRF token** on every POST.
- **Login rate limiting**: five attempts per fifteen minutes per IP, using
  the same core primitive (`lib/ratelimit.php`) the contact form uses — one
  implementation of "N attempts per window" in the codebase, not two.
- **Audit log** at `_logs/admin/access.log`: time, IP, event, user agent.
  Never the password.
- **`Cache-Control: no-store` and `X-Robots-Tag: noindex`** on every admin
  response.
- **No `Disallow: /admin/` in robots.txt.** A Disallow line publishes the
  path to everyone who reads robots.txt — which is precisely the people you
  did not want finding it. The `noindex` header is what actually keeps admin
  pages out of search results.

## Adding a page (for module authors)

Drop **one file** into `templates/admin/pages/<slug>.php`. It is discovered
automatically — there is no registry to edit, so two modules can never
conflict over one.

```php
<?php
declare(strict_types=1);

// Called twice: once with $admin_describe set (return your metadata), and
// once to render. The front controller has already checked authentication
// before this file is included.
if (isset($admin_describe)) {
    return ['title_key' => 'newsletter_admin_title', 'order' => 10];
}
?>
<h1><?= htmlspecialchars(t('newsletter_admin_title'), ENT_QUOTES, 'UTF-8') ?></h1>
```

Every POST from your page must carry the CSRF token and verify it:

```php
<input type="hidden" name="csrf" value="<?= htmlspecialchars(admin_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
```
```php
if (!admin_csrf_valid($_POST['csrf'] ?? null)) { /* reject */ }
```

## Verification

```
php scripts/validate-content.php
curl -s -o /dev/null -w '%{http_code}\n' http://localhost:8080/admin/     # 200 login (or 404 in stealth mode)
curl -sI http://localhost:8080/admin/ | grep -i 'x-robots-tag'            # noindex
curl -s http://localhost:8080/sitemap.xml | grep -c '/admin/'             # 0
```

Then sign in through a browser, confirm the dashboard renders, sign out, and
confirm six wrong passwords in a row start returning the throttle message.

## Uninstall

    php scripts/module.php uninstall admin

Refused while a module that requires it is still installed. Your
`ADMIN_PASSWORD_HASH` and `_logs/admin/` are left alone.
