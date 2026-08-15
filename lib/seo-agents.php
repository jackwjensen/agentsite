<?php
declare(strict_types=1);

/**
 * LLM/agent surfaces: /llms.txt (the llmstxt.org Markdown site index that
 * LLM crawlers prefer over an XML sitemap) and the generated Markdown
 * representation of the homepage served under `Accept: text/markdown`
 * content negotiation.
 *
 * Content pages need no builder here — they stream their own source
 * markdown via resolve_content_path(). Only the homepage, which has no
 * backing .md file, is generated.
 *
 * Both builders draw every fact from config + content — there is no copy in
 * this file, so nothing here needs editing when the site changes hands.
 */

/** Site overview blockquote + contact line, shared by both builders. */
function site_overview_markdown(string $lang): string
{
    $out = '> ' . cfg_lang('site.description', $lang) . "\n\n";

    $email = cfg('identity.email');
    $parts = [];
    if (is_string($email) && $email !== '') $parts[] = $email;
    $phone = cfg('identity.phone');
    if (is_string($phone) && $phone !== '') $parts[] = $phone;
    if ($parts !== []) {
        $out .= 'Contact: ' . implode(' · ', $parts) . "\n\n";
    }
    return $out;
}

/**
 * Markdown representation of the homepage, served on the canonical URL when
 * an agent sends `Accept: text/markdown`. Mirrors the homepage: name,
 * tagline, overview, navigation, latest posts, and a pointer to the full
 * machine-readable index.
 */
function build_home_markdown(string $lang): string
{
    $out  = '# ' . cfg('site.name') . "\n\n";
    $out .= '*' . cfg_lang('site.tagline', $lang) . "*\n\n";
    $out .= site_overview_markdown($lang);

    // Navigation, from the same config the header renders.
    $out .= "## Pages\n\n";
    foreach ((array) cfg('nav') as $item) {
        $route  = (string) ($item['route'] ?? '');
        if ($route === '' || $route === 'home') continue;
        $params = isset($item['slug']) ? ['slug' => (string) $item['slug']] : [];
        $out   .= '- [' . t((string) ($item['key'] ?? $route)) . '](' . abs_url(lang_path($lang, $route, $params)) . ")\n";
    }
    $out .= "\n";

    $posts = array_slice(load_posts($lang), 0, 5);
    if ($posts) {
        $out .= "## Latest posts\n\n";
        foreach ($posts as $post) {
            $slug  = (string) ($post['meta']['slug'] ?? '');
            $title = (string) ($post['meta']['title'] ?? '');
            if ($slug === '' || $title === '') continue;
            $out .= '- [' . $title . '](' . abs_url(post_url($slug, $lang)) . ")\n";
        }
        $out .= "\n";
    }

    $out .= "## More\n\n";
    $out .= '- [Full site overview for LLMs](' . abs_url('/llms.txt') . ")\n";

    return $out;
}

/**
 * llms.txt — the Markdown site index proposed at https://llmstxt.org/.
 * Emitted in the site's default language; other languages are linked from
 * the Optional section. Every content URL is also available with a .md
 * suffix (raw markdown) and via `Accept: text/markdown` negotiation —
 * stated up front so an agent knows to ask.
 */
function build_llms_txt(): string
{
    $lang = default_lang();

    $out  = '# ' . cfg('site.name') . "\n\n";
    $out .= site_overview_markdown($lang);

    $out .= "Every content page on this site is also available as raw Markdown: append `.md` to its URL, "
          . "or request the canonical URL with an `Accept: text/markdown` header.\n\n";

    $languages = cfg('languages');
    if (count($languages) > 1) {
        $labels = [];
        foreach ($languages as $code => $meta) {
            $prefix   = (string) $meta['prefix'];
            $labels[] = $meta['label'] . ' (`' . ($prefix === '' ? '/' : $prefix . '/') . '`)';
        }
        $out .= 'Languages: ' . implode(', ', $labels)
              . '. Links below are in ' . $languages[$lang]['label'] . ".\n\n";
    }

    // Pages, with title + description from their own frontmatter.
    $pages = glob(ROOT . "/content/$lang/pages/*.md");
    if ($pages) {
        $out .= "## Pages\n\n";
        $out .= '- [Home](' . abs_url(lang_path($lang, 'home')) . '): ' . cfg_lang('site.tagline', $lang) . "\n";
        foreach ($pages as $file) {
            $slug   = basename($file, '.md');
            if (!valid_slug($slug)) continue;
            $parsed = load_content($file);
            if (!$parsed) continue;
            $title = (string) ($parsed['meta']['title'] ?? $slug);
            $desc  = auto_description($parsed, 200);
            $line  = '- [' . $title . '](' . abs_url(lang_path($lang, 'page', ['slug' => $slug])) . ')';
            if ($desc !== '') $line .= ': ' . $desc;
            $out .= $line . "\n";
        }
        $out .= "\n";
    }

    // Published posts, newest first.
    $posts = load_posts($lang);
    if ($posts) {
        $out .= "## Posts\n\n";
        $out .= '- [All posts](' . abs_url(lang_path($lang, 'archive')) . ")\n";
        foreach ($posts as $p) {
            $slug  = (string) ($p['meta']['slug'] ?? '');
            $title = (string) ($p['meta']['title'] ?? '');
            if ($slug === '' || $title === '') continue;
            $date = (string) ($p['meta']['date'] ?? '');
            $desc = auto_description($p, 200);
            $line = '- [' . $title . '](' . abs_url(post_url($slug, $lang)) . ')';
            if ($date !== '') $line .= " ($date)";
            if ($desc !== '') $line .= ': ' . $desc;
            $out .= $line . "\n";
        }
        $out .= "\n";
    }

    $out .= "## Optional\n\n";
    foreach ($languages as $code => $meta) {
        if ($code === $lang) continue;
        $out .= '- [' . $meta['label'] . ' version](' . abs_url(lang_path((string) $code, 'home')) . ")\n";
    }
    $out .= '- [Sitemap](' . abs_url('/sitemap.xml') . "): Full machine-readable sitemap with hreflang alternates.\n";

    return $out;
}
