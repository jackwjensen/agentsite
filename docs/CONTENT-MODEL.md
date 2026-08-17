# The content model: mechanism, convention, and how to change it

This framework came out of two working sites, so some of what it does is a
**mechanism** you would have to rebuild if you removed it, and some is a
**convention** that happens to be how those two sites were organised. This
page separates them, because a framework that does not say which is which
leaves you guessing about what you are allowed to change.

Short version: the URL layer is almost entirely yours already. The content
layer is a convention borrowed from WordPress, implemented in one readable
file.

---

## Already yours: change it in config, nothing else

Everything here lives in `config/site.php`. No code is involved.

| What | How |
|---|---|
| **Every URL** | The `routes` table. `/posts/` can be `/journal/`, `/blog/`, `/artikler/`. |
| **How many languages, and their prefixes** | `languages`. One language, or nine. The default gets an empty prefix and lives at the root. |
| **Categories** | `categories` — a code and a label per language. |
| **Navigation** | `nav`, in order. |
| **Identity shape** | `site.profile`: `org` gives search engines an Organization with a founder, `person` gives a single Person. |
| **The palette** | `public/assets/css/tokens.css`. |

A worked example: a single-language site with the blog at `/journal/` needs
one `languages` entry with `'prefix' => ''` and one changed route path. That
is the whole diff, and every hreflang tag, the sitemap, the feeds and the
language switcher follow automatically — the switcher disappears entirely,
because there is nothing to switch to.

---

## Convention: WordPress's model, and why

Three decisions are baked into `lib/content.php`:

**1. Pages and posts are different things.** A page is timeless and lives at
its own URL (`/about/`); a post has a date, a category, and lives under the
archive. This is exactly WordPress's split, deliberately — it is the mental
model anyone arriving from WordPress already has, and it is why a WP export
maps across cleanly instead of needing a translation layer.

**2. The frontmatter vocabulary.** `title`, `description`, `date`,
`updated`, `category`, `tags`, `featured_image`, `featured_image_alt`,
`og_image`, `thumb_image`, `excerpt`, `untranslated`. Unknown keys are
parsed and carried through untouched, so adding your own costs nothing —
your template can read `$meta['reading_time']` today.

**3. A translation is the same filename in another language directory.**

```
content/en/posts/2026-08-01-winter-roast.md
content/da/posts/2026-08-01-winter-roast.md
```

This one is worth defending, because it is the decision the rest hangs off.
The alternative — a map of slug pairs, which is what one of the two origin
sites actually did — has to be maintained by hand, and drifts. Asking the
filesystem *"does the counterpart exist?"* cannot drift: hreflang, the
sitemap, the language switcher and the validator all read the same fact, so
a missing translation is impossible to advertise by mistake.

The cost is that a translated URL cannot have a translated **slug**
(`/da/indlaeg/winter-roast/`, not `/da/indlaeg/vinterristning/`). If
per-language slugs matter more to you than the guarantee, that is a fair
trade to make differently — see below.

---

## Changing the conventions

`lib/content.php` is about 380 lines with no framework underneath it. There
is no plugin system to fight and no abstraction to satisfy: you are editing
the file that does the thing.

**Different directory layout** (say `content/posts/<lang>/`, or no `pages`
vs `posts` split at all): the paths are built in `load_page()`,
`load_post()`, `load_posts()`, `translation_exists()` and
`resolve_content_path()`. Change those five, and `scripts/validate-content.php`
where it globs the same directories.

**Different frontmatter names**: `parse_frontmatter()` is dialect-agnostic —
it will hand you whatever keys you write. The names are only meaningful in
`effective_post_date()`, `post_is_published()`, `post_thumb()`,
`post_og_image()` and the templates. Rename in those.

**Per-language slugs**: replace `translation_exists()` with a lookup against
a map you maintain, and give `load_post()` a way to resolve a per-language
slug. Everything else already goes through those two functions. You are
trading the drift guarantee for the URLs — knowingly, which is the only way
that trade should be made.

**A different publish rule** (a `draft: true` flag instead of a date):
`post_is_published()` is six lines and every surface calls it. Change it
once and archives, feeds, the sitemap, `llms.txt` and the post's own URL all
follow.

**Something else entirely** — a documentation site, a portfolio, a wiki:
keep `lib/` and the agent surfaces, replace `content.php` and the templates.
That is a fork, and forking is explicitly fine. The parts most likely to be
worth keeping are the ones that took the longest to get right: the image
pipeline, the SEO layer, the spam defence, and the markdown-for-agents
surfaces.

---

## What is genuinely load-bearing

Change these and things break in ways that are not obvious:

- **`valid_slug()`** is the security boundary. Slugs come from URLs and end
  up in filesystem paths; the whitelist is what stops path traversal. The
  router's capture class matches it deliberately.
- **`render_picture()`** is the only image emitter. Bypassing it loses WebP,
  dimensions and lazy loading, and CI rejects raw `<img>` in templates for
  that reason.
- **`cfg()` throws on a missing key** rather than defaulting. A silent
  default is how a config typo becomes a subtly wrong site.
- **Publish gating lives in `load_posts()` / `load_post()`**, not in each
  surface. That is why a scheduled post cannot leak through a feed.
