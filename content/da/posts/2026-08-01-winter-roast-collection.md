---
title: "Vinterristningerne er landet"
description: "Tre nye single-origin ristninger til de mørke måneder — og et kig på hvordan et indlæg med billeder fungerer i dette framework."
date: 2026-08-01
updated: 2026-08-10
category: news
featured_image: /assets/img/winter-roast.jpg
featured_image_alt: "Abstrakte mørkeblå bølger med gyldne striber, som nordlys over et mørkt hav"
tags:
  - "kaffe"
  - "ristning"
---

<!-- Dette er demo-indhold — opsætningsinterviewet erstatter det. -->

Vinteren er der, hvor kaffen betyder mest, og årets kollektion bringer tre
nye single-origin ristninger på hylden. Alle tre er naturligvis fiktive —
dette indlæg findes for at vise, hvordan et **billedrigt indlæg** ser ud i
dette framework.

## Hvad dette indlæg demonstrerer

Hero-billedet øverst serveres som et `<picture>`-element med WebP-varianter
og et `srcset`, genereret offline af `scripts/optimize-images.ps1`. Den
originale JPEG forbliver i git som fallback og som socialt delingsbillede.

![Gyldne lysbånd over dyb blå, demo-billedet igen inde i brødteksten](/assets/img/winter-roast.jpg)

Billeder i brødteksten som det ovenfor omskrives automatisk: markdownens
`![alt](src)` bliver til samme `<picture>`-markup, lazy-loadet fordi
brødtekstbilleder i sagens natur er under folden.

## Udgivelses- og opdateringsdatoer

Dette indlæg har både `date:` og `updated:` i sin frontmatter, så det viser
et "Udgivet / Opdateret"-par, sorterer efter opdateringsdatoen i arkivet og
rapporterer opdateringen i sitemappets `<lastmod>` — ét frontmatter-felt
driver alle friskhedssignaler på én gang.
