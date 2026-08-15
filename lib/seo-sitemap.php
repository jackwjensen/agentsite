<?php
declare(strict_types=1);

/**
 * Crawler-facing indexes and policy: /sitemap.xml, its human-readable
 * /sitemap.xsl stylesheet, and /robots.txt. All three are served directly by
 * public/index.php, bypassing the layout.
 *
 * robots.txt lives here rather than with the agent surfaces because it
 * declares the sitemap URL and is the crawler-policy half of the same
 * conversation — even though it also allow-lists the AI crawlers.
 *
 * Freshness is the point of the sitemap: every URL carries a <lastmod> and a
 * <changefreq> DERIVED from it, because Google ignores a hardcoded
 * changefreq and reads lastmod. Posts use their updated:/date: frontmatter,
 * pages use the markdown file's mtime (content/ is bind-mounted, so mtime
 * tracks real edits), and aggregate pages (home, archive) use the newest
 * post in scope.
 */

/**
 * Derive a sitemap <changefreq> from a last-modified date: freshly-changed
 * pages are likely to change again soon; pages untouched for months rarely
 * do. $fallback is used only when no usable date is available.
 */
function changefreq_for(?string $lastmod, string $fallback = 'monthly'): string
{
    if ($lastmod === null || $lastmod === '') return $fallback;
    $ts = strtotime($lastmod);
    if ($ts === false) return $fallback;

    $age_days = (time() - $ts) / 86400;
    return match (true) {
        $age_days <= 7  => 'daily',
        $age_days <= 30 => 'weekly',
        $age_days <= 90 => 'monthly',
        default         => 'yearly',
    };
}

/** Newest published-post effective date (Y-m-d) for a language, or null. */
function sitemap_newest_post_date(string $lang): ?string
{
    $newest = null;
    foreach (load_posts($lang) as $p) {
        $d = effective_post_date($p['meta']);
        if ($d !== '' && ($newest === null || $d > $newest)) {
            $newest = $d;
        }
    }
    return $newest;
}

/** Last-modified date (Y-m-d) of a page's backing markdown file, or null. */
function page_file_mtime(string $slug, string $lang): ?string
{
    $file = ROOT . "/content/$lang/pages/$slug.md";
    if (!is_file($file)) return null;
    $ts = filemtime($file);
    return $ts !== false ? date('Y-m-d', $ts) : null;
}

/**
 * Build the sitemap URL entries. Each entry:
 *   ['loc', 'lastmod' (nullable), 'priority', 'changefreq', 'alternates']
 * where alternates maps hreflang => absolute URL (plus x-default).
 *
 * Three sources: static routes from config (home, archive, module routes
 * with 'paths' and 'sitemap' not disabled), pages (every markdown file, in
 * every language it exists in), and published posts.
 */
function build_sitemap_entries(): array
{
    $urls    = [];
    $default = default_lang();

    $alt_map = function (array $lang_paths) use ($default): array {
        $alts = [];
        foreach ($lang_paths as $lang => $path) {
            $alts[$lang] = abs_url($path);
        }
        if (count($alts) > 1) {
            $alts['x-default'] = $alts[$default] ?? reset($alts);
        }
        return $alts;
    };

    // 1. Static routes (paths, no slug). A route entry can opt out with
    //    'sitemap' => false (e.g. an admin area) or set its own 'priority'.
    $newest = [];
    foreach (site_langs() as $lang) {
        $newest[$lang] = sitemap_newest_post_date($lang);
    }
    foreach (routes_all() as $route_name => $entry) {
        if (!isset($entry['paths']) || ($entry['sitemap'] ?? true) === false) {
            continue;
        }
        $prio = (string) ($entry['priority'] ?? ($route_name === 'home' ? '1.0' : '0.8'));
        $lang_paths = [];
        foreach (site_langs() as $lang) {
            if (isset($entry['paths'][$lang])) {
                $lang_paths[$lang] = lang_path($lang, $route_name);
            }
        }
        $alts = $alt_map($lang_paths);
        foreach ($lang_paths as $lang => $path) {
            $mod = $newest[$lang];
            $urls[] = [
                'loc'        => abs_url($path),
                'lastmod'    => $mod,
                'priority'   => $prio,
                'changefreq' => changefreq_for($mod, 'weekly'),
                'alternates' => $alts,
            ];
        }
    }

    // 2. Pages — every slug found in ANY language directory, emitted for each
    //    language whose file exists.
    $slugs = [];
    foreach (site_langs() as $lang) {
        foreach (glob(ROOT . "/content/$lang/pages/*.md") as $file) {
            $slugs[basename($file, '.md')] = true;
        }
    }
    foreach (array_keys($slugs) as $slug) {
        if (!valid_slug((string) $slug)) continue;
        $lang_paths = [];
        foreach (site_langs() as $lang) {
            if (is_file(ROOT . "/content/$lang/pages/$slug.md")) {
                $lang_paths[$lang] = lang_path($lang, 'page', ['slug' => $slug]);
            }
        }
        $alts = $alt_map($lang_paths);
        foreach ($lang_paths as $lang => $path) {
            $mod = page_file_mtime((string) $slug, $lang);
            $urls[] = [
                'loc'        => abs_url($path),
                'lastmod'    => $mod,
                'priority'   => '0.7',
                'changefreq' => changefreq_for($mod, 'yearly'),
                'alternates' => $alts,
            ];
        }
    }

    // 3. Published posts, alternates only for languages where the translation
    //    is itself published (a scheduled sibling must not be advertised).
    foreach (site_langs() as $lang) {
        foreach (load_posts($lang) as $p) {
            $slug = (string) ($p['meta']['slug'] ?? '');
            if ($slug === '') continue;
            $date = effective_post_date($p['meta']);

            $lang_paths = [];
            foreach (site_langs() as $alt_lang) {
                if ($alt_lang === $lang || translation_exists('post', $slug, $alt_lang)) {
                    $lang_paths[$alt_lang] = post_url($slug, $alt_lang);
                }
            }
            $urls[] = [
                'loc'        => abs_url(post_url($slug, $lang)),
                'lastmod'    => $date !== '' ? $date : null,
                'priority'   => '0.5',
                'changefreq' => changefreq_for($date, 'yearly'),
                'alternates' => $alt_map($lang_paths),
            ];
        }
    }

    return $urls;
}

/** Render the full sitemap.xml document. */
function build_sitemap_xml(): string
{
    $h = fn(string $s) => htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');

    $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= '<?xml-stylesheet type="text/xsl" href="/sitemap.xsl"?>' . "\n";
    $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"' . "\n";
    $xml .= '        xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n";

    foreach (build_sitemap_entries() as $u) {
        $xml .= "  <url>\n";
        $xml .= "    <loc>" . $h((string) $u['loc']) . "</loc>\n";
        if (!empty($u['lastmod'])) {
            $xml .= "    <lastmod>" . $h((string) $u['lastmod']) . "</lastmod>\n";
        }
        $xml .= "    <changefreq>" . $h((string) $u['changefreq']) . "</changefreq>\n";
        $xml .= "    <priority>"   . $h((string) $u['priority'])   . "</priority>\n";
        foreach (($u['alternates'] ?? []) as $hreflang => $href) {
            $xml .= '    <xhtml:link rel="alternate" hreflang="' . $h((string) $hreflang)
                  . '" href="' . $h((string) $href) . '"/>' . "\n";
        }
        $xml .= "  </url>\n";
    }
    $xml .= "</urlset>\n";
    return $xml;
}

/**
 * sitemap.xsl — a human-friendly view of /sitemap.xml. When a person opens
 * the sitemap in a browser, the <?xml-stylesheet?> PI applies this and
 * renders a styled table instead of raw XML. Crawlers ignore the PI, and
 * browsers without XSLT just show the XML — pure progressive enhancement.
 * NOWDOC so the `$`-free markup is emitted verbatim; the site name is
 * substituted afterwards.
 */
function build_sitemap_xsl(): string
{
    $xsl = <<<'XSL'
<?xml version="1.0" encoding="UTF-8"?>
<xsl:stylesheet version="1.0"
  xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
  xmlns:s="http://www.sitemaps.org/schemas/sitemap/0.9"
  xmlns:xhtml="http://www.w3.org/1999/xhtml">
  <xsl:output method="html" encoding="UTF-8" indent="yes"
              doctype-system="about:legacy-compat"/>

  <xsl:template match="/">
    <html lang="en">
      <head>
        <meta charset="UTF-8"/>
        <meta name="viewport" content="width=device-width, initial-scale=1"/>
        <meta name="robots" content="noindex"/>
        <title>XML Sitemap — SITE_NAME</title>
        <style>
          *,*::before,*::after { box-sizing:border-box; }
          body { margin:0; line-height:1.6; color:#1f2937; background:#f8fafc;
                 font-family:system-ui,-apple-system,'Segoe UI',sans-serif; }
          header { background:#111827; color:#fff; padding:2rem 1.5rem; }
          header h1 { margin:0 0 .35rem; font-size:1.5rem; }
          header p { margin:0; color:#cbd5e1; font-size:.95rem; }
          header a { color:#fff; }
          main { max-width:1100px; margin:0 auto; padding:1.5rem; }
          .count { margin:0 0 1rem; color:#64748b; font-size:.95rem; }
          table { width:100%; border-collapse:collapse; background:#fff;
                  border:1px solid #e2e8f0; border-radius:8px; overflow:hidden; }
          th,td { padding:.6rem .75rem; text-align:left; vertical-align:top;
                  border-bottom:1px solid #e2e8f0; font-size:.9rem; }
          th { background:#f1f5f9; color:#334155; font-weight:600;
               text-transform:uppercase; letter-spacing:.03em; font-size:.72rem; }
          tr:last-child td { border-bottom:0; }
          tr:hover td { background:#f8fafc; }
          td.url { word-break:break-all; }
          a { color:#1d4ed8; text-decoration:none; }
          a:hover { text-decoration:underline; }
          .num { color:#94a3b8; font-variant-numeric:tabular-nums; }
          .tag { display:inline-block; margin:0 .15rem .15rem 0; padding:.05rem .4rem;
                 background:#e2e8f0; color:#334155; border-radius:4px; font-size:.7rem; }
          footer { max-width:1100px; margin:0 auto; padding:1rem 1.5rem 2rem;
                   color:#94a3b8; font-size:.8rem; }
        </style>
      </head>
      <body>
        <header>
          <h1>XML Sitemap</h1>
          <p>Machine-readable sitemap for <a href="/">SITE_NAME</a>, used by search engines.</p>
        </header>
        <main>
          <p class="count"><xsl:value-of select="count(s:urlset/s:url)"/> URLs</p>
          <table>
            <tr>
              <th>#</th>
              <th>URL</th>
              <th>Languages</th>
              <th>Last modified</th>
              <th>Change freq.</th>
              <th>Priority</th>
            </tr>
            <xsl:for-each select="s:urlset/s:url">
              <tr>
                <td class="num"><xsl:value-of select="position()"/></td>
                <td class="url"><a href="{s:loc}"><xsl:value-of select="s:loc"/></a></td>
                <td>
                  <xsl:for-each select="xhtml:link[@rel='alternate']">
                    <span class="tag"><xsl:value-of select="@hreflang"/></span>
                  </xsl:for-each>
                </td>
                <td><xsl:value-of select="s:lastmod"/></td>
                <td><xsl:value-of select="s:changefreq"/></td>
                <td><xsl:value-of select="s:priority"/></td>
              </tr>
            </xsl:for-each>
          </table>
        </main>
        <footer>Crawlers read the raw XML; this styled view is for humans.</footer>
      </body>
    </html>
  </xsl:template>
</xsl:stylesheet>
XSL;
    return str_replace(
        'SITE_NAME',
        htmlspecialchars((string) cfg('site.name'), ENT_QUOTES | ENT_XML1, 'UTF-8'),
        $xsl
    );
}

/**
 * robots.txt — declares the sitemap, emits Content Signals, and explicitly
 * allow-lists AI crawlers so the site is discoverable in AI-assisted search
 * (Google AI Overviews, Bing Copilot, Perplexity, ChatGPT browse, Claude…)
 * alongside traditional search engines. Being agent-readable is this
 * framework's point; flip the config values to opt out of specific uses.
 */
function build_robots_txt(): string
{
    $lines = [
        '# robots.txt for ' . site_url(),
        'User-agent: *',
        // Content Signals (contentsignals.org / IETF draft): machine-readable
        // usage preferences for AI and automated consumers.
        '# Content usage preferences for AI / automated consumers (contentsignals.org):',
        'Content-Signal: ' . (string) cfg('seo.content_signal'),
        'Allow: /',
    ];
    foreach ((array) cfg('seo.robots_disallow') as $disallow) {
        if (is_string($disallow) && $disallow !== '') {
            $lines[] = 'Disallow: ' . $disallow;
        }
    }
    $lines[] = '';
    $lines[] = '# AI search / assistant crawlers — explicitly allowed so this site';
    $lines[] = '# appears in AI-assisted search results.';
    $ai_bots = [
        'GPTBot',            // OpenAI training / indexing
        'OAI-SearchBot',     // OpenAI search
        'ChatGPT-User',      // ChatGPT user browsing
        'ClaudeBot',         // Anthropic training / indexing
        'Claude-Web',        // Anthropic browsing
        'anthropic-ai',      // Anthropic legacy
        'PerplexityBot',     // Perplexity crawler
        'Perplexity-User',   // Perplexity user-triggered fetch
        'Google-Extended',   // Google Gemini / AI Overviews opt-in
        'Applebot-Extended', // Apple Intelligence opt-in
        'CCBot',             // Common Crawl (used by many LLMs)
        'meta-externalagent',// Meta AI
        'cohere-ai',         // Cohere
        'Bytespider',        // ByteDance
    ];
    foreach ($ai_bots as $ua) {
        $lines[] = '';
        $lines[] = "User-agent: $ua";
        $lines[] = 'Allow: /';
    }
    $lines[] = '';
    $lines[] = 'Sitemap: ' . abs_url('/sitemap.xml');
    $lines[] = '';
    return implode("\n", $lines);
}
