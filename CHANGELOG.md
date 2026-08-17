# Changelog

Notable changes to the framework. Sites built on it pull from here, so
anything that could break an existing site is called out explicitly.

The version in `VERSION` is what a module's `core_min` is checked against.

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
