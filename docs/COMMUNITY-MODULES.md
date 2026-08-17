# Community modules

Modules do not have to live in this repository. Anyone can publish one, and
the installer takes a path or a git URL as readily as an in-tree name.

## Publishing yours

1. Build it as described in [CREATING-MODULES.md](CREATING-MODULES.md). The
   layout is identical whether it ends up in-tree or not.
2. Put it in a repository named **`agentsite-module-<name>`**.
3. Tag the repository with the GitHub topic **`agentsite-module`**.

That is the whole registry: a naming convention and a topic. Anyone can find
every published module by searching that topic, and there is no index for
anyone to gatekeep, host, or forget to update.

## Installing one

```bash
git clone https://github.com/someone/agentsite-module-analytics /tmp/analytics
# read it first — see below
php scripts/module.php install /tmp/analytics
```

## Two tiers of trust, stated plainly

**In-tree modules** (`modules/*` in this repository) are reviewed before
merging, and CI installs, checks and uninstalls every one of them on every
pull request. They are maintained here.

**Third-party modules are not vouched for by anyone.** A module is PHP that
runs inside your site with the same privileges as the rest of it. It can read
your `.env`, write anywhere in the tree, and make outbound requests. Nothing
in the installer sandboxes that, and nothing could.

So, before installing one:

- **Read it, or have your assistant read it and summarise what it does.**
  Ask specifically: what does it send anywhere, what does it store about
  visitors, and does it touch any file outside its own name?
- Prefer modules whose `MODULE.md` explains its trade-offs. An author who
  documents why is an author who thought about it.
- Check the install list the installer prints before you confirm. Files
  outside `<module-name>`-shaped paths deserve a second look.

The installer helps a little: it refuses payload paths containing `..` or an
absolute path, and it never overwrites an existing file. That prevents
accidents, not intent.

## Known third-party modules

Add yours with a one-line pull request to this file.

| Module | Repository | What it does |
|---|---|---|
| *(none yet)* | | |

**A listing here is not an endorsement.** It says the module exists and
claims to do that; it does not say anyone reviewed it. The two-tier
distinction above is the whole point of keeping this a plain list rather than
a registry.

## Why there is no central registry

The moment a registry exists, whoever runs it becomes the arbiter of which
modules are acceptable to publish — and every answer to that is bad. Host it
and you appear to endorse what is on it; remove something and you are
adjudicating; write acceptance criteria and you have taken on reviewing code
you did not write, forever, for free.

A naming convention plus a GitHub topic solves discovery without anyone
sitting in the middle. Trust stays where it belongs: between you and the
person whose code you are about to run.
