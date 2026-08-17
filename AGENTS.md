# Instructions for the AI assistant

Most people using this will do everything by talking to you. They may not
read a single file in here, may not know git, and may not have a GitHub
account. **You are the interface.** Act accordingly.

Read this file, then `README.md`, then `docs/SETUP.md` if you are setting up
a new site. `LIMITATIONS.md` describes how you will get this wrong — it is
not a disclaimer for the user's benefit, it is a description of how you
behave unless constrained, and you are expected to work against it.

---

## What this is

A website that is markdown files, PHP, and one container. No database, no
build step, no package manager, no JavaScript framework. A page is a file; a
URL is a route in config; the site is what you can see in the repository.

That simplicity is the product. Every instinct you have to add tooling —
a bundler, a static site generator, npm, a CMS — makes it worse. If a change
seems to need one, the change is wrong.

## First session on a new copy

When `config/site.php` still says "Nordlys Kaffe", the user has just cloned
this and wants their own site.

1. **Check the tooling runs.** `docker compose up -d --build`, then confirm
   <http://localhost:8080> serves the demo site. If Docker is missing, offer
   to help install it; if the port is taken, say which process has it.
2. **Explain what they just got**, in plain language and in one short
   paragraph. Something like: *"This is a complete website you can see and
   edit as text files. Right now it shows a demo coffee roastery. I am going
   to ask you a few questions and turn it into your site."*
3. **Follow `docs/SETUP.md`.** It is the interview. Ask its questions one at
   a time, in order, and wait for each answer — do not batch them, and do
   not guess an answer to save the user a question.
4. **Say what you changed** after each step, in terms of what they will see,
   not which file you edited.

## Hard rules

- **Never commit or push without an explicit instruction.** Each is its own
  gate, and each instruction authorises exactly one action. "Set up my site"
  is not permission to commit; "commit that" is not permission to push.
- **Identity facts live in exactly two files**: `config/site.php` and
  `public/assets/css/tokens.css`. If you find yourself typing the user's
  name, email, brand colour or domain into a template, a lib file or a
  content file, stop — you are creating the drift this design exists to
  prevent.
- **Never edit an installed module file in place.** Files copied by
  `scripts/module.php` carry a provenance comment. Edit the source under
  `modules/<name>/root/` and re-install, or your change disappears the next
  time the module is updated.
- **Never hand-edit `config/modules.json`.** The installer owns it.
- **Images go through `render_picture()`**, never a raw `<img>`. CI rejects
  raw tags in templates, and a raw tag loses WebP, sizing and lazy-loading.
- **Every new UI string goes in every language file.** The validator fails
  the build otherwise, and a missing string renders as its own key on the
  page.
- **Do not add a JavaScript build step, a database, or a dependency
  manager.** See "What this is".

## Before you say you are done

Run these. They are the same checks CI runs, and "it should work" is not
verification:

```
./dev.sh check          # or: dev.bat check
```

which is:

```
sh scripts/lint-php.sh
php scripts/validate-content.php
php scripts/check-image-budget.php
```

Then look at the actual site: load the pages you changed, in every language
the site has. If you changed anything about feeds, markdown negotiation or
the sitemap, check those URLs too — they are the part nobody notices is
broken until a crawler does.

## How the pieces fit

| Directory | What is in it |
|---|---|
| `config/site.php` | Identity, languages, routes, nav, categories. The only file that knows whose site this is. |
| `content/<lang>/pages/<slug>.md` | A page. Same filename in every language = the same page, translated. |
| `content/<lang>/posts/YYYY-MM-DD-<slug>.md` | A post. The date in the filename and the `date:` field are what publish it. |
| `lib/` | The engine. Rarely needs changing to build a site. |
| `templates/` | The HTML. `layout.php` wraps everything. |
| `i18n/<lang>.php` | UI strings — the words that are not content. |
| `modules/` | Optional features, installed with `scripts/module.php`. |
| `public/assets/` | CSS, images, fonts. `tokens.css` holds the palette. |

## Conventions that are not obvious

- **A translation is the same filename in another language directory.**
  There is no translation map to maintain. `translation_exists()` asks the
  filesystem, which is why hreflang and the sitemap cannot drift from
  reality.
- **A future `date:` hides a post everywhere at once** — archive, homepage,
  sitemap, feeds, llms.txt, its own URL. There is no draft flag to forget.
- **`updated:` is what makes a post look fresh.** It drives ordering, the
  "Updated" label, sitemap `lastmod` and JSON-LD `dateModified` together.
- **Adding a language** means: a row in `languages`, an `i18n/<lang>.php`
  with every key, and `content/<lang>/` files. The validator will tell you
  exactly what is missing.
- **Modules never edit shared files.** Routes go in
  `config/routes.d/<module>.php`, strings in
  `i18n/modules/<module>.<lang>.php`, head tags in
  `templates/partials/head/<module>.php`, admin pages in
  `templates/admin/pages/<module>.php`. All discovered by filename.

## When the user asks for something this cannot do

Say so plainly, and say what it would take. Do not quietly bolt on a
database, a build step or a third-party script to make a request work — the
user chose this framework for what it does not have. If they need a web
application, they need a web application, and it is more useful to tell them
that than to turn this into a bad one.
