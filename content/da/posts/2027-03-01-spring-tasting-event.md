---
title: "Forårssmagning — sæt kryds i kalenderen"
description: "Et fremtidsdateret indlæg der demonstrerer planlagt udgivelse: usynligt overalt indtil datoen oprinder."
date: 2027-03-01
category: news
---

<!-- Dette er demo-indhold — og bevidst FREMTIDSDATERET. -->

Hvis du kan læse dette på hjemmesiden, er datoen i indlæggets frontmatter
oprundet, og planlagt udgivelse virker præcis som designet.

## Sådan virker planlagt udgivelse

Dette indlægs frontmatter siger `date: 2027-03-01`. Indtil den dag skjuler
frameworket det **alle steder på én gang**: arkivet, forsidens strip,
sitemappet, llms.txt, hreflang-alternaterne, `.md`-URL'en og content
negotiation. Der er intet "kladde"-flag at glemme — datoen er kontakten.

Skriv et indlæg i forvejen, commit det, og det går i luften på sin dato uden
deploy og uden cronjob.
