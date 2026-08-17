# Modules

The core is a website: pages, posts, languages, SEO, and the agent surfaces.
Everything else is a module you install deliberately.

That is a security position as much as a design one. **A feature you have not
installed has no code, no route, no URL and no attack surface.** A markdown
site with no contact form cannot leak a contact form; a site with no admin
area cannot have its admin password guessed.

```bash
php scripts/module.php list                 # what is available
php scripts/module.php status               # what is installed
php scripts/module.php install contact-form
php scripts/module.php uninstall contact-form
```

Or through Docker, with no PHP on your machine:

```bash
./dev.sh module install contact-form        # Windows: dev.bat module install contact-form
```

Restart afterwards (`docker compose up -d`) so the new routes are loaded.

## Available modules

| Module | Adds | Costs you |
|---|---|---|
| [`contact-form`](../modules/contact-form/MODULE.md) | Contact page, four-layer spam defence, SMTP delivery, retention sweep | SMTP settings, and a privacy-policy paragraph covering the message log and the spam quarantine |
| [`rss`](../modules/rss/MODULE.md) | RSS 2.0 + Atom feeds per language, autodiscovery | Nothing |
| [`admin`](../modules/admin/MODULE.md) | Password-protected admin area, page discovery for other modules | A password to set, and one more thing to keep patched |
| [`newsletter`](../modules/newsletter/MODULE.md) | Double opt-in subscriber list, CSV export, admin page | Requires `admin`; a privacy-policy paragraph; you send the mail yourself |

Read a module's `MODULE.md` **before** installing it. Each one states what it
adds, what you must configure, what it stores about your visitors, and how to
verify it works.

## How installing works

An install is **file copies plus appended `.env` lines**. Nothing shared is
edited — not `config/site.php`, not `templates/layout.php`, not the router.

That single constraint buys everything else:

- **Modules cannot conflict.** The only possible collision is two modules
  claiming the same filename, and the installer refuses loudly before writing
  anything rather than overwriting.
- **Uninstall is exact.** It deletes the files it recorded at install time
  and nothing else, so the tree returns byte-identical. CI asserts this for
  every module on every pull request.
- **`git pull` never fights your site.** Framework updates touch framework
  files; your installed modules live at different paths.
- **You can read the diff.** Everything a module did is visible in
  `git status`.

Modules reach the site by **discovery, not registration** — the core globs
these directories, so there is no central list for two modules to fight over:

| A module drops | The core finds it in |
|---|---|
| `config/routes.d/<name>.php` | the route table |
| `i18n/modules/<name>.<lang>.php` | the string lookup |
| `templates/partials/head/<name>.php` | every page's `<head>` |
| `templates/admin/pages/<name>.php` | the admin menu |
| `public/assets/css/<template>.css` | that template's pages only |

The one thing a module deliberately does **not** do is add itself to your
navigation. Menu order is the site owner's decision, so each `MODULE.md`
tells you the one line to add to `nav` in `config/site.php`.

## Dependencies

A module may require another (`newsletter` requires `admin`). The installer
offers to install the dependency first, and refuses to uninstall something
another installed module still needs.

## Updating a module

Pull the framework, then re-install:

```bash
php scripts/module.php uninstall contact-form
php scripts/module.php install contact-form
```

**If you have edited an installed file, your change is lost.** Installed
files carry a provenance comment saying so. To change a module's behaviour
permanently, edit the source under `modules/<name>/root/` and re-install —
then your change survives, and you can contribute it back.

## Writing your own

See [CREATING-MODULES.md](CREATING-MODULES.md), and
[COMMUNITY-MODULES.md](COMMUNITY-MODULES.md) for publishing one.
