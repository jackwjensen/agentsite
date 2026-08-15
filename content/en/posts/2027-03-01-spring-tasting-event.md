---
title: "Spring tasting event — save the date"
description: "A future-dated post demonstrating scheduled publishing: invisible everywhere until its date arrives."
date: 2027-03-01
category: news
---

<!-- This is demo content — and deliberately FUTURE-DATED. -->

If you can read this on the website, the date in this post's frontmatter has
arrived and scheduled publishing works exactly as designed.

## How scheduled publishing works

This post's frontmatter says `date: 2027-03-01`. Until that day, the
framework hides it **everywhere at once**: the archive, the homepage strip,
the sitemap, llms.txt, the hreflang alternates, the `.md` URL and content
negotiation. There is no "draft" flag to forget — the date is the switch.

Write a post ahead of time, commit it, and it goes live on its date with no
deploy and no cron job.
