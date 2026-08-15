---
title: "The winter roast collection is here"
description: "Three new single-origin roasts for the dark months — and a look at how a post with images works in this framework."
date: 2026-08-01
updated: 2026-08-10
category: news
featured_image: /assets/img/winter-roast.jpg
featured_image_alt: "Abstract deep-blue waves with golden streaks, like northern lights over a dark sea"
tags:
  - "coffee"
  - "roasting"
---

<!-- This is demo content — the setup interview replaces it. -->

Winter is when coffee matters most, and this year's collection brings three
new single-origin roasts to the shelf. All three are fictional, of course —
this post exists to demonstrate what an **image-rich post** looks like in
this framework.

## What this post demonstrates

The hero image above is served as a `<picture>` element with WebP variants
and a `srcset`, generated offline by `scripts/optimize-images.ps1`. The
original JPEG stays committed as the fallback and as the social-share image.

![Golden light bands over deep blue, the demo image again inside the body](/assets/img/winter-roast.jpg)

Body images like the one above are rewritten automatically: the markdown
`![alt](src)` becomes the same `<picture>` markup, lazy-loaded because body
images are below the fold by nature.

## Publish and update dates

This post carries both a `date:` and an `updated:` in its frontmatter, so it
renders a "Published / Updated" pair, sorts by its update date in the
archive, and reports the update in the sitemap's `<lastmod>` — one
frontmatter field driving every freshness signal at once.
