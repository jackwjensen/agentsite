# How this will go wrong

Read this before letting an assistant build your site, and again after a few
weeks — the second reading lands differently, because by then you will have
seen some of these happen.

None of what follows is a bug that gets fixed in a later model. These are
structural tendencies of building a website by conversation. The framework
does not remove them. It makes several of them **visible**, which is the most
any tool can do.

---

## 1. It will write confident copy about a business it does not know

You will say "I run a small accountancy in Leeds", and you will get a
finished-looking site claiming twenty years of experience, a specialism in
R&D tax credits, and a commitment to personal service. None of it was
checked with you. It reads well, which is exactly the problem: fluent copy
feels verified.

**What the framework does:** the demo content is obviously fictional and
every file says so, so the first version you see is never mistaken for
finished. The setup interview asks about your business before writing
anything.

**What you must do:** read every sentence on the finished site as if a
stranger wrote it about you, because one did. Delete anything you would not
say out loud to a client. Pay particular attention to numbers — years in
business, team size, client counts — which are invented most readily and are
the most damaging to get wrong.

## 2. It will re-derive decisions instead of finding them

Ask for a change in a fresh session and the assistant may add a second way of
doing something that already exists — a new colour that is nearly the brand
colour, a second date format, another place that knows your email address.
Each is locally reasonable. Together they are how a codebase rots.

**What the framework does:** identity lives in exactly two files
(`config/site.php` and `tokens.css`), and modules cannot edit shared files at
all. That leaves far less room to invent a second source of truth.

**What you must do:** when you are told "I've added X", ask where it lives
and whether something already did that job. If the answer names a file
outside those two for anything about your identity, it is in the wrong place.

## 3. It will make the site look finished before it is correct

Layout, colours and spacing produce immediate, satisfying feedback.
Accessibility, error pages, the 404, what happens when a form fails, whether
a translation is missing — do not. An assistant optimising for your visible
approval will spend its effort where you can see it.

**What the framework does:** `scripts/validate-content.php` fails the build
on missing translations, broken image references, unknown categories and
invalid dates, so the invisible half is checked mechanically rather than
noticed by luck.

**What you must do:** run `./dev.sh check` yourself and read the output. Visit
your own 404 page. Submit your own contact form and get the failure case, not
just the success case.

## 4. It will agree with your bad ideas

If you ask for a carousel, an autoplaying video, six fonts, or a cookie
banner you do not need, you will probably get them, with an explanation of
why your instinct was good. Disagreement is not the default behaviour, and a
website is exactly the kind of subjective artefact where an assistant defers.

**What the framework does:** nothing. It cannot.

**What you must do:** ask "what would you cut?" and "what does this cost the
visitor?" as separate questions, and ask them when you are not attached to
the answer. A request phrased as a question gets a more honest reply than one
phrased as an instruction.

## 5. It will treat a passing check as a working site

The gates here check syntax, config consistency, translations and image
budgets. They do not check that your prices are right, that your phone number
is yours, that the tone suits your customers, or that the site says what you
meant. A green checkmark measures the things that are cheap to measure.

**What you must do:** treat `./dev.sh check` as "nothing is obviously
broken", never as "this is ready to publish". The last mile is reading it.

## 6. It will forget the parts that are not on screen

Your privacy policy, your retention promises, your `MAIL_TO` address, the
`config/admin-password.hash` you meant to set. These are invisible in a
browser and therefore easy to leave at their placeholder values — and the
placeholder in your privacy policy says **REPLACE ME**, which is not a good
thing to publish.

**What you must do:** before going live, open `config/site.php` and every
file under `content/*/pages/`, and search the whole site for `example.com`
and `REPLACE ME`. If you installed a module, read its `MODULE.md` section on
what you must declare — the contact form and the newsletter both store
personal data, and both need a line in your privacy policy.

---

## The one that has no fix

Everything above assumes a reader who pushes back. The framework can check
that a translation exists; it cannot check that the translation is any good,
that the claim is true, or that the site is one you would be happy for a
customer to find.

> **A site that passes every check is not evidence that the site is right. It
> is evidence that the checks were satisfied — and the checks were written by
> someone who could not see your business.**

If you are handing this to someone less technical, make sure they can name at
least two of the failures above before they start.

## What it is genuinely good at

This is not an argument against using an assistant here. It is unusually good
at:

- Turning a description of a business into a first draft you can react to,
  which is far easier than starting from an empty page.
- The mechanical half of a migration: converting a WordPress export into
  markdown, fixing every link, keeping URLs stable.
- Remembering the boring correctness — hreflang, image dimensions, the
  sitemap, the things that are invisible until a crawler complains.
- Explaining what a file does before you change it.

Reliable at **structure and consistency**. Unreliable at **judgment about
your business**. Use it accordingly.
