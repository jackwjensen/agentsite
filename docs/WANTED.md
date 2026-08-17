# Wanted

Things worth building that nobody has built yet. Each is a real gap, sized to
be a weekend rather than a career, and each would be genuinely useful to
other people using this.

Open an issue before starting something large — partly to avoid two people
building the same module, partly so scope questions come up before you have
written it.

## Modules

**`wp-migration`** — the missing half of the WordPress-escape pitch. Take a
WordPress export (`.xml`), and produce markdown files with correct
frontmatter, downloaded images, rewritten internal links, and a list of old
URLs to put in the `redirects` and `gone` arrays. **Keeping URLs stable is
the hard and valuable part** — a migration that changes every URL throws away
the site's search ranking.

**`series`** — multi-part posts: a hub page listing the parts, and
previous/next navigation on each. Frontmatter would be `series:` and
`series_part:`. Straightforward; a blog with a long-form series wants it
badly.

**`search`** — client-side search over a generated index. The constraint is
what makes it interesting: no build step, so the index has to be generated at
request time and cached, or written by a script the user runs. Anything that
needs npm is out of scope.

**`tags`** — free-text tags in frontmatter, with tag archive pages, alongside
the single `category` the core has. Filtered views must be `noindex` so every
permutation does not end up in a search index.

**Newsletter senders** — the `newsletter` module deliberately stops at the
list. A module that sends via a real provider (Listmonk, Mailgun, SES,
Brevo…) would complete it. One module per provider, named for it, so they can
coexist.

**`comments`** — genuinely hard without a database, and interesting for that
reason. Moderated file-based comments, or a bridge to an existing service.
The spam problem is the whole problem; the contact form's four layers are a
starting point.

## Core

**More languages.** The engine takes any number, but only English and Danish
ship. A new `i18n/<lang>.php` with every key is a real contribution, and
exercises the parts of the i18n system that two languages do not.

**Accessibility audit.** The templates aim for WCAG AA — landmarks,
focus states, contrast, labels. Nobody has audited them with a screen reader.
Findings are as valuable as fixes.

**Validator checks.** `scripts/validate-content.php` catches what has bitten
so far. Obvious gaps: heading-level jumps in content, links to pages that do
not exist, images without alt text, frontmatter keys that are almost-but-not
a real key (`descriptoin:`).

**A theme story.** Everything visual is `tokens.css` plus five stylesheets.
Whether alternate themes should be modules, a `themes/` directory, or just
"edit the CSS" is undecided — a good argument either way would settle it.

## Documentation

**A worked migration.** Somebody moving a real WordPress site here, writing
down what actually happened, including what went wrong.

**Deployment recipes** for hosts other than a plain VPS — shared hosting,
Fly.io, Railway, a Synology NAS. The framework is unusually portable and the
docs do not show it.

**More `LIMITATIONS.md` entries.** If an AI assistant misled you while
building your site in a way that file does not describe, that is one of the
most valuable things you can contribute — it is the document that keeps the
whole premise honest.
