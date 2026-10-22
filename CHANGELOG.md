# Changelog

Notable changes to the framework. Sites built on it pull from here, so
anything that could break an existing site is called out explicitly.

The version in `VERSION` is what a module's `core_min` is checked against.

## Unreleased

**Security**

- PHP **8.3 → 8.5** (`php:8.5-fpm-alpine`; CI and `dev check` on
  `php:8.5-cli-alpine`). 8.5 is supported for years longer than 8.3, whose
  security fixes end on 31 December 2027. The code, every module and the CI
  checks ran unchanged and without a single deprecation notice on 8.5.12.
- Deploys now rebuild with `docker compose build --pull`. A plain `--build`
  reuses the PHP base image cached on the first deploy, so a site never got a
  PHP security release however often it was redeployed — PHP2Shell
  (CVE-2026-109618, CVE-2026-109619, fixed in 8.5.12 / 8.3.36) is the case
  that exposed it. **Existing sites:** run
  `git pull && docker compose build --pull && docker compose up -d` and check
  `docker compose exec web php -v` reports 8.5.12 or later. Change your own
  deploy script the same way if you copied `docs/examples/deploy.yml`.
- **Rate limits could be bypassed with a forged header.** `client_ip()`
  took the leftmost `X-Forwarded-For` entry and believed `CF-Connecting-IP`
  from anyone, both of which a visitor writes themselves. One forged header
  per request was a fresh rate-limit bucket: unlimited admin-login attempts,
  contact-form and newsletter submissions. It now walks the chain from the
  right over the proxies declared in the new `config/trusted-hops.php`, and
  reads `CF-Connecting-IP` only when a Cloudflare address really is in the
  chain. The defaults cover every setup in `docs/DEPLOY.md`. The
  contact-form module also stopped blocklisting `0.0.0.0` ("unknown"),
  which its guard had let through. **Existing sites:** pull, and re-install
  contact-form if you use it. If your reverse proxy has a public address,
  add it to `own_infrastructure` in `config/trusted-hops.php`.

**Changed**

- contact-form module **v2**: the development mail catcher is now Mailpit
  (`axllent/mailpit:v1.31.5`, pinned) instead of MailHog (`:latest`, no
  release since 2020). The inbox stays at <http://127.0.0.1:1080> and SMTP
  at `1025`, both now bound to `127.0.0.1` only. Development-only; production
  mail is unaffected. **Existing sites:** follow "Upgrading from v1" in
  [modules/contact-form/MODULE.md](modules/contact-form/MODULE.md).

## 1.0.0

First release.

**Core**

- Markdown pages and posts, any number of languages, one file per language
  with the same filename — translation pairing is a filesystem fact rather
  than a map to maintain.
- Config-driven routing: URLs, navigation, categories and identity all live
  in `config/site.php`, and nothing brand-shaped lives in code.
- Scheduled publishing: a future `date:` hides a post from every surface at
  once — archive, homepage, sitemap, feeds, its own URL — with no draft flag
  to forget.
- Agent surfaces built in: `Accept: text/markdown` negotiation, `.md` URLs,
  `/llms.txt`, RFC 8288 `Link:` headers, Content Signals in `robots.txt`.
- SEO: canonical URLs, hreflang from real files, Open Graph with image
  dimensions, JSON-LD with one identity node that every article references,
  and a sitemap whose `changefreq` is derived from actual dates.
- Images through one helper: WebP variants when they exist on disk, explicit
  width and height, lazy loading, and cache-busting by file time.
- CSS split per template and loaded by filename discovery; the palette is one
  token file.

**Tooling**

- `scripts/validate-content.php` — config, frontmatter, slugs, categories,
  translation completeness, i18n parity, image references.
- `scripts/check-image-budget.php` — byte ceiling and WebP requirement.
- `scripts/module.php` — install, uninstall, dependency resolution.
- CI checks that the site works, never how the code is written, and
  round-trips every module to prove installs are reversible.

**Modules**

- `contact-form` — four-layer spam defence, SMTP, retention sweep, dev mail
  catcher.
- `rss` — RSS 2.0 and Atom per language, with autodiscovery.
- `admin` — optional password-protected area; page discovery for other
  modules.
- `newsletter` — double opt-in list, CSV export, admin page.
