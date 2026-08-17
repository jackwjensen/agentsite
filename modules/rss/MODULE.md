---
module: rss
version: 1
requires: []
core_min: "1"
---

# RSS and Atom feeds

Feeds for the blog — RSS 2.0 and Atom, one pair per configured language,
with autodiscovery so a browser or reader finds them from any page.

    php scripts/module.php install rss

## What it adds

| Path | What it is |
|---|---|
| `lib/feeds.php` | The two document builders |
| `templates/feed.php` | Emits XML and exits, so the HTML layout never runs |
| `templates/partials/head/rss.php` | Autodiscovery `<link>` tags on every page |
| `config/routes.d/rss.php` | `/feed.xml` and `/atom.xml` per language |

No configuration and no environment variables.

## URLs

| | Default language | Other languages |
|---|---|---|
| RSS | `/feed.xml` | `/<prefix>/feed.xml` |
| Atom | `/atom.xml` | `/<prefix>/atom.xml` |

Both carry the twenty most recent published posts, newest first, with the
full article body as well as a summary.

## Things it gets right that are easy to get wrong

- **Scheduled posts cannot leak.** Feeds are built from the same
  `load_posts()` the archive uses, so the publish-date gate is applied once,
  in one place, rather than reimplemented per surface.
- **Relative URLs are made absolute.** A feed reader has no page context, so
  `src="/assets/…"` would resolve against the reader's own host. Every
  `href`, `src` and `srcset` in the body is rewritten — including each
  candidate inside a `srcset`, which is the part usually missed.
- **The path keeps its dot.** `/feed.xml`, not `/feed/`: nginx's
  trailing-slash redirect skips paths containing a dot, so the request
  arrives intact.
- **Feeds are excluded from the sitemap** — the posts they list are already
  indexed individually.

## Verification

```
php scripts/validate-content.php
curl -sf http://localhost:8080/feed.xml | head -5
curl -sf http://localhost:8080/atom.xml | head -5
curl -s http://localhost:8080/ | grep 'application/rss+xml'    # autodiscovery
```

Paste `http://localhost:8080/feed.xml` into any feed reader, or validate it
at <https://validator.w3.org/feed/>.

## Uninstall

    php scripts/module.php uninstall rss
