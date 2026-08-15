<?php
declare(strict_types=1);

/**
 * schema.org node builders. Every builder returns an array for
 * render_seo_head() to JSON-encode.
 *
 * The site has ONE canonical identity, selected by cfg('site.profile'):
 *
 *   'org'     an Organization node (@id /#org) whose founder is the owner
 *             Person node (@id /#owner) — two nodes, cross-referenced.
 *   'person'  a single Person node (@id /#owner); the site IS the person.
 *
 * Every article's author references the same @id, so search engines can
 * reconcile the author, the founder and the site owner as one entity
 * instead of guessing across three unlinked Person literals.
 */

/** The identity node(s) for the configured profile. */
function identity_jsonld_nodes(string $lang): array
{
    if ((string) cfg('site.profile') === 'person') {
        return [owner_person_jsonld(true)];
    }
    return [organization_jsonld($lang), owner_person_jsonld(false)];
}

/** The site owner as a Person node (@id /#owner). */
function owner_person_jsonld(bool $standalone): array
{
    $node = [
        '@context' => 'https://schema.org',
        '@type'    => 'Person',
        '@id'      => site_url() . '/#owner',
        'name'     => (string) (cfg('identity.owner_name') ?? cfg('site.name')),
        'url'      => site_url() . '/',
    ];
    $socials = (array) cfg('identity.socials');
    if ($socials !== []) {
        $node['sameAs'] = array_values($socials);
    }
    // On a person site the owner node also carries the contact facts.
    if ($standalone) {
        $email = cfg('identity.email');
        if (is_string($email) && $email !== '') {
            $node['email'] = $email;
        }
    }
    return $node;
}

/** The Organization node (@id /#org) for org-profile sites. */
function organization_jsonld(string $lang): array
{
    $node = [
        '@context' => 'https://schema.org',
        '@type'    => 'Organization',
        '@id'      => site_url() . '/#org',
        'name'     => (string) cfg('site.name'),
        'url'      => site_url() . '/',
        'description' => cfg_lang('site.description', $lang),
    ];

    $legal = cfg('identity.legal_name');
    if (is_string($legal) && $legal !== '') $node['legalName'] = $legal;

    $logo = (string) cfg('identity.logo');
    if ($logo !== '') $node['logo'] = abs_url($logo);

    $email = cfg('identity.email');
    if (is_string($email) && $email !== '') $node['email'] = $email;

    $phone = cfg('identity.phone');
    if (is_string($phone) && $phone !== '') $node['telephone'] = $phone;

    $vat = cfg('identity.vat_id');
    if (is_string($vat) && $vat !== '') $node['vatID'] = $vat;

    $iban = cfg('identity.iban');
    if (is_string($iban) && $iban !== '') $node['iban'] = $iban;

    $street = cfg('identity.street');
    if (is_string($street) && $street !== '') {
        $node['address'] = array_filter([
            '@type'           => 'PostalAddress',
            'streetAddress'   => $street,
            'postalCode'      => cfg('identity.postal_code'),
            'addressLocality' => cfg('identity.locality'),
            'addressCountry'  => cfg('identity.country'),
        ], fn($v) => is_string($v) && $v !== '');
    }

    $socials = (array) cfg('identity.socials');
    if ($socials !== []) $node['sameAs'] = array_values($socials);

    $owner = cfg('identity.owner_name');
    if (is_string($owner) && $owner !== '') {
        $node['founder'] = ['@id' => site_url() . '/#owner'];
    }

    return $node;
}

/** The identity node's @id — what WebSite.publisher and article.publisher point at. */
function identity_ref(): array
{
    $fragment = (string) cfg('site.profile') === 'person' ? '#owner' : '#org';
    return ['@id' => site_url() . '/' . $fragment];
}

function website_jsonld(string $lang): array
{
    return [
        '@context'   => 'https://schema.org',
        '@type'      => 'WebSite',
        '@id'        => site_url() . '/#website',
        'url'        => site_url() . '/',
        'name'       => (string) cfg('site.name'),
        'inLanguage' => str_replace('_', '-', (string) (cfg('languages')[$lang]['locale'] ?? $lang)),
        'publisher'  => identity_ref(),
    ];
}

function article_jsonld(array $article, string $canonical, string $image_url, string $lang): array
{
    $dim = img_dimensions($image_url);
    $image_node = $dim
        ? ['@type' => 'ImageObject', 'url' => $image_url, 'width' => $dim['width'], 'height' => $dim['height']]
        : $image_url;

    $node = [
        '@context'         => 'https://schema.org',
        '@type'            => 'BlogPosting',
        'headline'         => (string) ($article['title'] ?? ''),
        'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $canonical],
        'url'              => $canonical,
        'image'            => $image_node,
        'inLanguage'       => str_replace('_', '-', (string) (cfg('languages')[$lang]['locale'] ?? $lang)),
        'author'           => ['@id' => site_url() . '/#owner'],
        'publisher'        => identity_ref(),
    ];
    if (!empty($article['date'])) {
        $node['datePublished'] = (string) $article['date'];
        $node['dateModified']  = (string) (($article['updated'] ?? '') !== '' ? $article['updated'] : $article['date']);
    }
    if (!empty($article['description'])) {
        $node['description'] = (string) $article['description'];
    }
    if (!empty($article['category'])) {
        $node['articleSection'] = category_label((string) $article['category'], $lang);
    }
    if (!empty($article['tags']) && is_array($article['tags'])) {
        $node['keywords'] = implode(', ', array_filter($article['tags'], 'is_string'));
    }
    return $node;
}

function breadcrumb_jsonld(array $crumbs): array
{
    $items = [];
    foreach (array_values($crumbs) as $i => $c) {
        $items[] = [
            '@type'    => 'ListItem',
            'position' => $i + 1,
            'name'     => (string) ($c['name'] ?? ''),
            'item'     => abs_url((string) ($c['url'] ?? '/')),
        ];
    }
    return [
        '@context'        => 'https://schema.org',
        '@type'           => 'BreadcrumbList',
        'itemListElement' => $items,
    ];
}
