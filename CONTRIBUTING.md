# Contributing

## Scope: what this repo is, and is not

This comes first as a courtesy — it will save you writing something that gets
declined for reasons that have nothing to do with its quality.

**Welcome:**

- **New modules.** This is the best thing you can contribute. See
  [docs/CREATING-MODULES.md](docs/CREATING-MODULES.md).
- **Translations** — a new `i18n/<lang>.php`, or a fix to an existing one.
- **Documentation**, including corrections to the reasoning. If a comment
  explains *why* something is done and the explanation is wrong, that is a
  real bug.
- **New checks in `scripts/validate-content.php`**, for classes of mistake
  that currently fail silently.
- **Portability fixes** — other operating systems, other web servers, PHP
  versions, hosts without Docker.
- **Additions to [LIMITATIONS.md](LIMITATIONS.md).** If an assistant misled
  you in a way that file does not cover, that is especially valuable.

**Not accepted:**

- A JavaScript build step, bundler, or dependency manager.
- A database, an ORM, or a schema.
- A frontend framework, or a rewrite in another language.
- A template engine. PHP is one.
- Style changes to existing code: brace placement, naming, file length,
  "modernising" working code.

**That second list is a scope decision, not a judgement about your stack.**
Every item on it is a reasonable thing to want and a good choice in another
project. They are excluded here because "no build step, no database, no
dependencies" is not an implementation detail of this framework — it *is* the
framework. A site you can still start in five years is what it sells, and
every dependency added is a year subtracted.

If you want something this cannot be, fork it. You do not need permission and
you do not need to contribute anything back — see **Forking** below.

## There is deliberately no house style

The CI here checks that the site **works**: syntax, config consistency,
translations, image budgets, and that every module can install and uninstall
cleanly. It does not check how you write PHP, and no pull request will be
declined over formatting, variable names or file length.

Match the surrounding code well enough that the next reader is not surprised.
That is the whole standard.

## Reporting a bug

Open an issue with the template. The most valuable reports are the ones where
**a check passed and the site was still broken** — that is a gap in the
validator, and it affects everyone.

Before reporting a setup problem, check the troubleshooting table at the
bottom of [docs/SETUP.md](docs/SETUP.md).

## Contributing a module

A module is one directory and one row in `docs/MODULES.md`. It touches
nothing else — that is the design, and it is why module PRs are easy to
review and easy to accept.

CI installs every in-tree module, runs the checks, uninstalls it, and asserts
the tree is byte-identical afterwards. If your module survives that, most of
the review is already done.

Read [docs/CREATING-MODULES.md](docs/CREATING-MODULES.md). The short version:

- `modules/<name>/root/**` mirrors the repo root and is copied verbatim.
- Routes go in `config/routes.d/<name>.php`, strings in
  `i18n/modules/<name>.<lang>.php`, head tags in
  `templates/partials/head/<name>.php`. Never edit a shared file.
- `MODULE.md` documents what it adds, what it costs, and how to verify it —
  including anything the user must put in their privacy policy.
- English and one other language minimum, so the i18n mechanism is exercised.

You can also publish a module in your own repository without contributing it
here; see [docs/COMMUNITY-MODULES.md](docs/COMMUNITY-MODULES.md).

## Pull requests

1. **Open an issue first** for anything beyond a typo or a translation. It is
   cheaper to hear "out of scope" in a comment than after you have written
   it.
2. **One concern per pull request.**
3. **Say how you verified it.** "It should work" is not verification — paste
   the output of `./dev.sh check`, and for a module, the output of its
   install/uninstall round-trip.
4. **Update the docs in the same commit.** Code and prose drifting apart is
   how a project starts lying to its users.
5. All pull requests are reviewed before merging.

## Forking

You are free to fork this and maintain your own variant, publicly or
privately, without asking. That is how the framework is meant to spread, and
a fork that goes somewhere this repo will not is a good outcome, not a
defection.

## Conduct

See [CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md). The short version: argue about
the code, not the person, and do not relitigate the stack.
