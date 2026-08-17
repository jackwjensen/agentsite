---
module: newsletter
version: 1
requires: [admin]
core_min: "1"
---

# Newsletter

A double opt-in subscriber list kept in a file, with an admin page to review
and export it. **No third-party account, no tracking pixels, no data
processor to declare** — the same shape as the rest of the framework.

    php scripts/module.php install newsletter

This requires the `admin` module; the installer offers to add it for you.

## What it deliberately does not do

**It does not send the newsletter.** It collects and confirms addresses, and
exports them as CSV. Sending is left to whatever you already use, which is
the honest split: a mailing tool is a real product with deliverability,
bounce handling and list hygiene to worry about, and a website that pretends
to be one will burn your sending domain.

What it *does* give you is the part a sending tool cannot: a list where every
address was confirmed by its owner, on your own infrastructure.

## What it adds

| Path | What it is |
|---|---|
| `lib/newsletter.php` | The list, tokens, and signup/confirm/unsubscribe logic |
| `templates/subscribe.php` | Signup page, and the confirm/unsubscribe link target |
| `templates/admin/pages/newsletter.php` | The admin page (discovered automatically) |
| `config/routes.d/newsletter.php` | `/subscribe/` and `/subscribe/confirm/` |
| `i18n/modules/newsletter.{en,da}.php` | Its UI strings |

Add it to your header by putting this in the `nav` array of
`config/site.php`:

```php
['key' => 'nav_newsletter', 'route' => 'newsletter'],
```

## Double opt-in, and why it is not optional

An address only becomes `confirmed` when its owner clicks a link carrying an
HMAC token this site signed. Until then it is `pending` and no export
includes it.

That is not ceremony. A single-opt-in list is how you end up mailing an
address somebody else typed into your form — which under the GDPR is
processing without consent, and in practice is how a sending domain loses its
reputation. The confirmation step also means a typo simply expires instead of
sitting on your list forever.

Two smaller decisions worth knowing about:

- **The form says the same thing whether or not the address is already on the
  list.** Otherwise the form becomes a way to test whether somebody is
  subscribed.
- **Confirmation links expire after seven days**, and unsubscribe links are
  signed the same way, so an unsubscribe URL in a mailing cannot be edited to
  remove somebody else.

## Delivering the confirmation email

The module writes the link but does not send it. Two options:

1. **Install the `contact-form` module too** — it brings an SMTP client, and
   you can call `send_contact_mail()` from your own hook.
2. **Send it yourself** from whatever mail tooling you have, using
   `newsletter_confirm_url($email, $lang)`.

In development, with `APP_DEBUG` set, the confirmation link is displayed on
the page after signup so you can test the whole flow without any mail
configuration at all.

**Put `newsletter_unsubscribe_url($email, $lang)` in every mailing you
send.** It is legally required in most jurisdictions and expected everywhere
else.

## Storage, privacy and retention

The list lives at `_logs/newsletter/subscribers.jsonl` — one JSON record per
line, appended, so concurrent writes are safe and the file is greppable.
Later records supersede earlier ones for the same address, so the file is a
log and the current state is derived from it.

Each record holds the address, its status, the language it signed up in, the
time, and the IP that submitted it. **Declare this in your privacy policy**:
what you store, why (consent for the newsletter), and how someone gets
removed (the unsubscribe link, or by asking you).

Unlike the contact form's logs, this file is **not** pruned automatically — a
subscriber list is meant to persist. Removing someone means recording an
`unsubscribed` state, which keeps the evidence that they asked; if you need
to erase them entirely, delete their lines from the file.

## Verification

```
php scripts/validate-content.php
curl -sf http://localhost:8080/subscribe/ | grep -q 'name="email"'
curl -s -o /dev/null -w '%{http_code}\n' http://localhost:8080/admin/newsletter/   # 200 after signing in
```

Then, with `APP_DEBUG=1` in `.env`: subscribe in a browser, click the link
shown on the page, and confirm the admin page moves the address from
"awaiting confirmation" to "confirmed".

## Uninstall

    php scripts/module.php uninstall newsletter

The subscriber file is left in place — uninstalling a module must never
delete a list somebody consented to be on.
