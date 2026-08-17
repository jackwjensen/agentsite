---
module: contact-form
version: 1
requires: []
core_min: "1"
---

# Contact form

A contact page with a four-layer spam defence and SMTP delivery. No database,
no third-party service, no CAPTCHA — a visitor never has to prove anything to
a robot, and no visitor data is disclosed to anyone.

    php scripts/module.php install contact-form

## What it adds

| Path | What it is |
|---|---|
| `lib/spam.php` | The four layers, the blocklist, the quarantine |
| `lib/mailer.php` | Minimal SMTP client (STARTTLS by capability, AUTH only when encrypted) |
| `templates/contact.php` | The page, the form, and the POST handler |
| `config/routes.d/contact-form.php` | `/contact/` (en) and `/kontakt/` (da) |
| `i18n/modules/contact-form.{en,da}.php` | All of its UI strings |
| `public/assets/css/contact.css` | Form styling (loaded only on this page) |
| `scripts/prune-mail-logs.php` | Retention sweep — see **Retention** below |
| `docker-compose.override.yml` | MailHog, so development can never send real mail |

## Configuration

The installer appends these to `.env`:

| Variable | What it does |
|---|---|
| `SMTP_HOST` | Leave **empty** in development — the compose override points the site at MailHog. |
| `SMTP_PORT` | Default `587`. |
| `SMTP_USER` / `SMTP_PASS` | Set **both** or **neither**. Both empty means an unauthenticated relay (the dev catcher); one of each is a misconfiguration and the mailer refuses to send. |
| `MAIL_TO` | Where submissions are delivered. |
| `MAIL_FROM` / `MAIL_FROM_NAME` | The envelope sender. Use an address on your own domain, or SPF/DKIM will reject it. |
| `FORM_TOKEN_SECRET` | Optional. Left empty, the module generates and stores one. |

**Add the page to your navigation** by editing the `nav` array in
`config/site.php` — module installs never touch that file, because menu order
is your decision:

```php
['key' => 'nav_contact', 'route' => 'contact'],
```

**Optional intro text**: create `content/<lang>/pages/contact.md`. If it
exists, its title and body render above the form; otherwise the built-in
strings are used.

## Going to production

Development gets the mail catcher; production has to opt in to real SMTP
**explicitly**. Add this to `docker-compose.production.yml`:

```yaml
services:
  web:
    # Undo the development override's pin. Everything else already reaches
    # the container from .env, because the base compose file loads it.
    environment:
      SMTP_HOST: "${SMTP_HOST:-}"
      SMTP_PORT: "${SMTP_PORT:-587}"
      SMTP_USER: "${SMTP_USER:-}"
      SMTP_PASS: "${SMTP_PASS:-}"
```

**If your SMTP password contains a `$`**, write it as `$$` in that
interpolated form — or leave these four out entirely and let `.env` supply
them, which does not interpolate at all.

That direction is deliberate: development cannot be made to send real mail by
a `.env` mistake, because the file development always loads names the catcher
literally.

## How the four layers work

Each layer rejects the submission and quarantines it. **None of them bans an
IP on a single hit**, and that is the most important property in the module.

1. **Honeypot** — a hidden `order_ref` field. Bots fill every input; humans
   never see it. The name avoids two traps at once: it is not
   `honeypot`/`website`/`url` (published spambot skip-lists name those), and
   it is not in browser autofill's vocabulary. **Never rename it to anything
   containing contact, address, organization or URL keywords** — autofill
   fills hidden fields and ignores `autocomplete="off"` for address-profile
   data, which is exactly how a honeypot ends up flagging a real person.
2. **HMAC time token** — a signed timestamp; accepted between 3 seconds and
   2 hours old. Catches instant submissions and forged or replayed forms.
3. **Behavioural signal** — JavaScript sets a marker on the first real input
   event. A headless bot that never moves a pointer leaves it empty. No-JS
   visitors get a `mailto:` fallback.
4. **Escalating per-IP limit** — 5/hour, then 5/day, then 5/week, then
   blocked. This one *can* catch a real human, so the first two tiers show a
   friendly message rather than a block.

Layers 1–3 escalate to a permanent block only after **three strikes in seven
days**. A detector that can be wrong must not have an irreversible first
strike: for most sites, one lost enquiry costs more than every piece of spam
the blocklist has ever stopped.

A blocked request renders the site's ordinary **404** — not an error page,
not a redirect. A scanner cannot tell "blocked" from "no such URL". The 404
also carries a line inviting anyone who was using the form to email instead
(`notfound_form_hint`), shown on *every* 404 so the two responses stay
byte-identical.

## Retention and your privacy policy

Every submission is written to `_logs/mail/`, and every *blocked* submission
to `_logs/spam/quarantine/`. Both hold personal data.

The quarantine exists so a false positive is recoverable — the blocklist
records only IP, time and reason, which cannot answer "was that a human?".
The honeypot value in the record answers it directly: an autofilled address
means a browser filled it.

**You must:**

1. Run the retention sweep. Daily cron on the host:
   ```
   0 3 * * * cd /srv/mysite && docker compose exec -T web php scripts/prune-mail-logs.php >> _logs/prune.log 2>&1
   ```
2. **Declare both directories in your privacy policy** — including the
   quarantine, its purpose, its legal basis (legitimate interest in
   preventing abuse), and its retention period. A retention promise with an
   undeclared exception is not a promise.

Review quarantined submissions with:

```
docker compose exec -T web sh -c 'grep -H Honeypot _logs/spam/quarantine/*.txt'
```

## Verification

After installing, restart (`docker compose up -d --build`) and check:

```
php scripts/validate-content.php
curl -sf http://localhost:8080/contact/ | grep -q 'name="order_ref"'   # honeypot present
curl -s -o /dev/null -w '%{http_code}\n' -X POST http://localhost:8080/contact/ \
     -d 'name=b&email=b@example.com&subject=x&message=y&order_ref=filled'   # expect 404
```

Then submit the form in a browser and confirm the message appears in MailHog
at <http://localhost:1080>. A file in `_logs/mail/` means the mailer was
*reached* — only a redirect to `?sent=1` means it was *sent*.

## Uninstall

    php scripts/module.php uninstall contact-form

Removes every file listed above. Your `.env` variables and anything already
written to `_logs/` are left alone — delete them by hand if you want them
gone. Remove the `nav` entry from `config/site.php` yourself, since you added
it.
