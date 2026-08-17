# Security policy

## Reporting a vulnerability

**Do not open a public issue.** Use GitHub's private reporting:
**Security → Report a vulnerability** on this repository.

Include what you did, what happened, and which modules were installed. A
proof of concept against a local `docker compose up` is ideal; you do not
need to find a live site to demonstrate it on, and please do not go looking
for one.

Expect an acknowledgement within a week. This is a small project maintained
by one person — there is no bounty, and no service-level agreement I could
honestly promise to meet.

## What is in scope

The framework as it ships, including its modules:

- **Path traversal** through a slug, a module payload, or a content path.
- **Bypassing the admin module's authentication**, session fixation, CSRF, or
  anything that makes the rate limit ineffective.
- **Bypassing all four contact-form spam layers** in a way an automated tool
  could reproduce at scale. (Getting past *one* layer is expected — that is
  why there are four.)
- **Injection** of any kind: markdown content that becomes executable, a
  frontmatter value that escapes its context, header injection through the
  mailer.
- **Disclosure** of `.env`, `config/admin-password.hash`, `_logs/`, or
  anything else outside `public/`.
- **A default that is unsafe** — a setting whose out-of-the-box value exposes
  something a reasonable person would not expect.

## What is not

- **A site the owner misconfigured.** Publishing the placeholder privacy
  policy, committing `.env`, or leaving `site.url` as `example.com` are
  documented setup steps, not vulnerabilities.
- **Third-party modules.** Anything outside `modules/` in this repository is
  the author's to fix — see
  [docs/COMMUNITY-MODULES.md](docs/COMMUNITY-MODULES.md), which states
  plainly that installing one runs their code with your site's privileges.
- **Anything requiring the admin password**, or filesystem access to the
  server. If you already have those, the game is over by definition.
- **Missing hardening that is the deployment's job**: TLS, HSTS preloading,
  a WAF, DDoS protection. The container speaks plain HTTP on purpose and
  expects a reverse proxy — see [docs/DEPLOY.md](docs/DEPLOY.md).
- **Volumetric denial of service.** The rate limits are there to make abuse
  expensive, not to survive a botnet.

## Design decisions that look like bugs

Three things get reported that are deliberate. They are explained here so you
can decide whether you have found something better:

1. **A blocked contact-form submission returns `404`, not `403`.** That is
   the point: a scanner cannot tell "blocked" from "no such URL". The 404
   body is byte-identical to a genuine one.
2. **There is no `Disallow: /admin/` in `robots.txt`.** A Disallow line
   publishes the path to everyone who reads robots.txt. Admin responses
   carry `X-Robots-Tag: noindex` instead, and `ADMIN_STEALTH=1` makes the
   area answer 404 to unauthenticated visitors entirely.
3. **`Content-Security-Policy` allows `'unsafe-inline'`.** The layout ships a
   small inline script and templates use inline style attributes. A
   hash-based policy would be stricter but breaks on every markup edit; a
   nonce-based one needs per-request state this framework does not have. If
   you can show a practical exploit path this creates here, that is a real
   finding and I want it.

## Keeping a site patched

There are no dependencies to update — no package manager, no lockfile, one
vendored markdown parser. Updating means pulling this repository and
restarting:

```bash
git pull && docker compose up -d --build
```

Watch [CHANGELOG.md](CHANGELOG.md); anything security-relevant is called out
there explicitly rather than left for you to infer from a diff.
