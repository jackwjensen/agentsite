# agentsite

[![check](https://github.com/jackwjensen/agentsite/actions/workflows/check.yml/badge.svg)](https://github.com/jackwjensen/agentsite/actions/workflows/check.yml)
[![code: MIT](https://img.shields.io/badge/code-MIT-blue.svg)](LICENSE)
[![docs: CC BY 4.0](https://img.shields.io/badge/docs-CC%20BY%204.0-lightgrey.svg)](LICENSE-DOCS)

A website your AI assistant builds for you, that both Google and AI agents
can read. Markdown files, PHP, one container. No database, no build step, no
JavaScript framework, nothing to keep updated.

Works with any assistant that can read and write files on your computer —
Claude Code, GitHub Copilot, Cursor, Codex. Instructions live in the
cross-tool [`AGENTS.md`](AGENTS.md) format.

## Start here

Open your AI assistant and paste this:

> Clone https://github.com/jackwjensen/agentsite into a folder on my
> computer, and set up anything I'm missing as you go — git, Docker. This
> copy is mine: my site goes into it, and nothing goes back to the original
> repository.
>
> Read `AGENTS.md`, `README.md` and `docs/SETUP.md`, then start the demo site
> and show me it running.
>
> Then interview me and turn it into my site: ask me what the site is for,
> what languages I want, and what it should look like. Ask one question at a
> time.

That's the whole setup. You don't need to know git, you don't need a GitHub
account, and you don't need to read the rest of this page — your assistant
will walk you through it, including installing whatever your machine is
missing.

> **A chatbot in a browser tab will not work.** It can discuss this
> framework, but it cannot clone the repository, create your files, or start
> the container — so there is nothing to build your site *in*. The test is
> simple: if it cannot create a file on your computer, it is the wrong tool
> for this. The assistants named above all can; the same company's website
> chat generally cannot.

The rest is for whoever wants to understand what they just started.

---

## What this is

A complete website you can read as text. Pages and posts are markdown files
with a few lines of frontmatter. Identity — your name, your colours, your
languages, your URLs — lives in one config file. Everything else is the
engine, and you can ignore it.

It ships with a working demo site (a fictional coffee roastery) so there is
always something on screen, and the setup interview replaces it with yours.

The content model is WordPress's, on purpose: pages are timeless, posts have
a date and a category. That is the mental model most people arriving here
already have, and it is why a WordPress export maps across without a
translation layer. Where the framework has opinions beyond that — the
directory layout, the frontmatter names, the rule that a translation is the
same filename in another language — [`docs/CONTENT-MODEL.md`](docs/CONTENT-MODEL.md)
says which parts are load-bearing and which are just how the two origin
sites happened to be organised, and how to change the latter.

## Why PHP (yes, really)

Because this is not a static site, and the alternative is worse.

A site that answers `Accept: text/markdown` differently from a browser, rate
limits a form, checks a password and serves a feed is doing work at request
time. Static site generators cannot do that without bolting on serverless
functions from a specific vendor — at which point you have a build step *and*
a backend, and you are locked to one host.

Given that a backend is needed, PHP earns its place:

- **It runs where WordPress runs.** Every host on earth that serves the CMS
  you are leaving will serve this, unchanged. No new hosting, no new skills.
- **Zero build step.** Edit a file, refresh the browser. There is nothing to
  compile, so there is nothing to break at 2am.
- **No dependency churn.** One vendored markdown parser, no package manager,
  no lockfile, no `npm audit`. A site built today will still start in five
  years.
- **It is boring on purpose.** The interesting parts of this repo are the
  spam defence, the image pipeline and the agent surfaces — not the language.

If you were hoping for TypeScript: this is not that, deliberately, and
[CONTRIBUTING.md](CONTRIBUTING.md) says so up front rather than wasting your
afternoon.

## Agent-ready, out of the box

Not a plugin, not a retrofit — the site serves machines properly because it
was built to:

| Surface | What it does |
|---|---|
| `Accept: text/markdown` | Any page returns clean markdown instead of HTML, on its own canonical URL, with `Vary: Accept`. |
| `/<page>.md` | The raw source of any page, for anything that would rather fetch than negotiate. |
| `/llms.txt` | A generated site index in the [llmstxt.org](https://llmstxt.org/) format. |
| `Link:` headers | RFC 8288 alternates, so an agent finds the markdown without parsing HTML. |
| `robots.txt` | AI crawlers explicitly allowed, with [Content Signals](https://contentsignals.org/) declaring how content may be used. Flip one config value to opt out. |
| `sitemap.xml` | Every URL with `lastmod`, `changefreq` derived from real dates, and hreflang alternates — plus a human-readable view. |
| JSON-LD | One canonical identity node for the site, referenced by every article, so search engines have one entity to reconcile rather than three. |

## What's in the box

| File | What it is |
|---|---|
| [`AGENTS.md`](AGENTS.md) | Instructions your assistant reads. `CLAUDE.md`, `GEMINI.md` and `.github/copilot-instructions.md` point here |
| [`LIMITATIONS.md`](LIMITATIONS.md) | How an AI assistant will get this wrong. Read it |
| [`docs/SETUP.md`](docs/SETUP.md) | The interview that turns the demo into your site |
| [`docs/CONTENT-MODEL.md`](docs/CONTENT-MODEL.md) | What is mechanism, what is just convention, and how to change either |
| [`docs/MODULES.md`](docs/MODULES.md) | The optional features and what each costs you |
| [`docs/CREATING-MODULES.md`](docs/CREATING-MODULES.md) | Writing your own, and contributing it |
| [`docs/DEPLOY.md`](docs/DEPLOY.md) | Putting it on the internet |
| [`docs/WANTED.md`](docs/WANTED.md) | Things worth building that nobody has built yet |
| `config/site.php` | Your identity, languages, routes and navigation |
| `scripts/module.php` | The module installer |

No database. No dependencies. No build step. The files *are* the site.

## Optional modules

The core is a website. Everything else is opt-in, because a feature you did
not install cannot break, leak or be attacked:

| Module | What it adds |
|---|---|
| `contact-form` | A contact page with a four-layer spam defence and SMTP delivery |
| `rss` | RSS 2.0 and Atom feeds per language, with autodiscovery |
| `admin` | A password-protected admin area — off by default |
| `newsletter` | Double opt-in subscriber list with an admin page |

```bash
php scripts/module.php list
php scripts/module.php install contact-form
```

Installing copies files into place and touches nothing shared, so modules
cannot conflict, uninstalling is exact, and `git pull` on the framework never
fights your site. See [`docs/MODULES.md`](docs/MODULES.md).

## Requirements

Docker, or any host with PHP 8.3 and nginx.

```bash
./dev.sh up        # Windows: dev.bat up      -> http://localhost:8080
./dev.sh check     # the same checks CI runs
```

## Built from production

This framework was extracted from two sites that have been running it for
real, and the parts that look over-engineered are the parts that were paid
for in incidents:

- **[allegroit.dk](https://allegroit.dk/)** — a company site: the contact
  form, the spam layers, the agent surfaces, the image budget.
- **[jackscorner.dk](https://jackscorner.dk/)** — a bilingual blog: scheduled
  publishing, responsive images, the newsletter.

It ships with demo content instead of theirs, because those sites' content
belongs to them and yours should be yours.

## Contributing

New modules, translations, documentation and validator checks are all
welcome. Build steps, JavaScript frameworks and databases are not — see
[CONTRIBUTING.md](CONTRIBUTING.md), which leads with the scope so you never
write something that gets declined for reasons unrelated to its quality.

You can also fork this and maintain your own variant, publicly or privately,
without asking. That is how a framework spreads.

### Licence

Dual-licensed, because the repo is two different kinds of work:

- **Code** → [MIT](LICENSE). Use it, change it, ship it.
- **Documentation** → [CC BY 4.0](LICENSE-DOCS). Adapt it freely; credit the
  source.

The prose is a real part of the substance here — the reasoning in
`LIMITATIONS.md` and in each module's `MODULE.md` is most of what makes the
code safe to reuse. CC BY means that if you build on it, the attribution
travels with it.
