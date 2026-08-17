# Setup: turning the demo into your site

This is the interview. If you are an AI assistant, follow it in order, **ask
one question at a time, and wait for the answer.** Do not batch the
questions, and do not guess an answer to save the user a step — a guessed
language or a guessed business type costs far more to undo than to ask.

If you are a human doing this yourself, the same order works; every step says
which file it changes.

---

## 0. Before the questions

Get the demo running so there is something on screen:

```bash
./dev.sh up          # Windows: dev.bat up
```

Open <http://localhost:8080>. You should see "Nordlys Kaffe", a fictional
coffee roastery. Tell the user, in one sentence, that this is a placeholder
and you are about to replace it.

If the port is busy, say which process is holding it rather than silently
picking another — port 8080 is what every instruction in this repo assumes.

---

## 1. What kind of site is this?

> Is this for a company or organisation, or is it a personal site?

Sets `site.profile` in `config/site.php` — `org` or `person`. It decides
which identity search engines are shown: an Organization with a founder, or a
single Person.

## 2. What is it called, and what is it for?

> What is the site called? And in one or two sentences, what is it for and
> who is it for?

Sets `site.name`, `site.tagline` and `site.description`.

**Write the description from what they actually said**, not from what a site
of that type usually says. This paragraph is what AI assistants will read
when someone asks about them, so an invented specialism here becomes an
invented specialism in somebody's search results.

If they have a domain, set `site.url`. If they do not, leave `example.com`
and **tell them it must be changed before going live** — it appears in the
sitemap, the feeds and every absolute URL.

## 3. Languages

> Which language should the site be in? Do you want more than one?

Sets `languages` and `site.default_lang`.

- **One language** is the simple case. Delete the languages you do not want
  from `config/site.php`, delete the matching `i18n/<lang>.php` and
  `content/<lang>/` directories, and give the remaining language an empty
  `prefix` so it lives at the root.
- **More than one**: the default language gets `'prefix' => ''`, every other
  gets a path prefix like `/da`. Every page and post then needs the same
  filename in each language directory — the validator will list anything
  missing.

Do not offer to "add the other languages later" as if it were free: the cost
is a translation of every page, and it is better understood now.

## 4. Look

> Do you have a brand colour? A logo?

- **Colour**: set `--brand` in `public/assets/css/tokens.css`, then derive
  the rest of the ramp from it (`--brand-dark` a few shades darker for hover,
  `--brand-tint` and `--brand-faint` as light washes). Set
  `site.theme_color` in `config/site.php` to the same hex. **Check the
  contrast** of text on the brand colour — if it is a light colour, the white
  button text will fail WCAG AA and you must darken it.
- **Logo**: replace `public/assets/img/logo.svg`. If they have no logo, leave
  the demo one or make a simple wordmark; do not make this a blocker.
- **Social image**: replace `public/assets/img/og-default.jpg` (1200×630).
  This is what appears when the site is shared.

After any image change, run `./dev.sh check` — the image budget gate will
tell you if something is too heavy or needs a WebP sibling.

## 5. Features

> Do you want a contact form? A newsletter signup? RSS feeds?

Install only what they say yes to:

```bash
php scripts/module.php list
php scripts/module.php install contact-form
```

Read the module's `MODULE.md` **before** installing and tell the user what it
will cost them — the contact form needs SMTP details and a privacy-policy
paragraph; the newsletter brings an admin area with a password to set. A
feature that arrives with homework should not arrive as a surprise.

Then add its navigation entry to the `nav` array in `config/site.php`. Module
installs never do this themselves, because menu order is the owner's
decision.

## 6. Content

> Do you want me to write the first version of your pages from what you have
> told me, do you have an existing site to move across, or would you rather
> write them yourself?

- **Write them**: replace `content/<lang>/pages/about.md` and the demo posts.
  Keep the frontmatter shape; replace every word of the body. Then **read it
  back to the user and ask what is wrong** — see `LIMITATIONS.md` §1, because
  this is exactly where invented facts enter.
- **Existing site**: ask for a WordPress export (`.xml`) or the URLs, and
  convert. Keep the old URL paths where you can; where you cannot, list the
  old paths in the `redirects` array in `config/site.php`.
- **Keep placeholders**: leave the demo content but change the names, and
  tell them which files to edit.

**Always** replace `content/<lang>/pages/privacy.md`. It ships with
**REPLACE ME** markers and placeholder claims, and publishing it as-is is
worse than having no privacy policy at all.

## 7. Finish

1. `./dev.sh check` — must pass.
2. Load every page, in every language, and click the language switcher.
3. Check the 404 page: visit a URL that does not exist.
4. If a module is installed, run its `MODULE.md` verification steps.
5. Tell the user what is left for them: the privacy policy, the real domain,
   any `.env` values, and anything you were unsure about.

Do **not** commit or push unless they ask you to. Each is its own decision
and each instruction covers exactly one action.

---

## Troubleshooting

| Symptom | Cause and fix |
|---|---|
| `port is already allocated` | Another container has 8080. Stop it, or change the port mapping in `docker-compose.yml`. |
| Page shows a string like `nav_home` | That i18n key is missing from the current language's file. `./dev.sh check` names it. |
| A post does not appear | Its `date:` is in the future — that is the publish gate, working. |
| A translation link is missing | The counterpart file does not exist. Same filename, other language directory. |
| `config key not found: …` | `config/site.php` is missing a key the code needs. The message names it. |
| Image looks wrong or CI fails on it | Run `scripts/optimize-images.sh` (or `.ps1`), then `./dev.sh check`. |
| A module's page 404s | The container has not restarted since the install: `docker compose up -d`. |
| Changes not showing | Hard-refresh: assets are cached for a year and versioned by file time. |
