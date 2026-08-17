<?php
declare(strict_types=1);

// Installed from modules/rss (v1). To update: re-run the installer.
//
// RSS 2.0 and Atom feeds, one pair per configured language. Feeds are built
// from exactly the same published-post list the archive renders, so a
// scheduled post cannot leak through a feed before its date — the publish
// gate lives in load_posts(), not in three separate places.
//
// Served by templates/feed.php, which the module's route file points at.

const FEED_MAX_ITEMS = 20;

/** Absolute URL of a language's feed. */
function feed_url(string $lang, string $format = 'rss'): string
{
    return abs_url(lang_path($lang, $format === 'atom' ? 'feed_atom' : 'feed_rss'));
}

/**
 * Full HTML for a post, with relative URLs made absolute. A feed reader has
 * no page context, so a src="/assets/…" would resolve against the reader's
 * own host and break every image.
 */
function feed_absolute_html(string $html): string
{
    return (string) preg_replace_callback(
        '#\b(href|src|srcset)="(/[^"]*)"#i',
        static function (array $m): string {
            if (strtolower($m[1]) !== 'srcset') {
                return $m[1] . '="' . abs_url($m[2]) . '"';
            }
            // srcset is a comma-separated list of "url descriptor" pairs.
            $parts = array_map(static function (string $candidate): string {
                $candidate = trim($candidate);
                $bits = preg_split('/\s+/', $candidate, 2);
                if ($bits === false || $bits === []) {
                    return $candidate;
                }
                $url  = $bits[0];
                $rest = isset($bits[1]) ? ' ' . $bits[1] : '';
                return (str_starts_with($url, '/') ? abs_url($url) : $url) . $rest;
            }, explode(',', $m[2]));
            return $m[1] . '="' . implode(', ', $parts) . '"';
        },
        $html
    );
}

/** RSS 2.0 document for one language. */
function build_rss_feed(string $lang): string
{
    $x = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');

    $posts     = array_slice(load_posts($lang), 0, FEED_MAX_ITEMS);
    $site_name = (string) cfg('site.name');
    $self      = feed_url($lang, 'rss');
    $home      = abs_url(lang_path($lang, 'home'));

    // RFC 822 date of the newest post, or now for an empty blog.
    $newest = $posts !== [] ? effective_post_date($posts[0]['meta']) : '';
    $built  = $newest !== '' ? date(DATE_RSS, (int) strtotime($newest)) : date(DATE_RSS);

    $out  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $out .= '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:content="http://purl.org/rss/1.0/modules/content/">' . "\n";
    $out .= "  <channel>\n";
    $out .= '    <title>' . $x($site_name) . "</title>\n";
    $out .= '    <link>' . $x($home) . "</link>\n";
    $out .= '    <description>' . $x(cfg_lang('site.description', $lang)) . "</description>\n";
    $out .= '    <language>' . $x(str_replace('_', '-', (string) (cfg('languages')[$lang]['locale'] ?? $lang))) . "</language>\n";
    $out .= '    <lastBuildDate>' . $x($built) . "</lastBuildDate>\n";
    $out .= '    <atom:link href="' . $x($self) . '" rel="self" type="application/rss+xml"/>' . "\n";

    foreach ($posts as $post) {
        $meta  = $post['meta'];
        $slug  = (string) ($meta['slug'] ?? '');
        $title = (string) ($meta['title'] ?? '');
        if ($slug === '' || $title === '') continue;

        $link = abs_url(post_url($slug, $lang));
        $date = (string) ($meta['date'] ?? '');

        $out .= "    <item>\n";
        $out .= '      <title>' . $x($title) . "</title>\n";
        $out .= '      <link>' . $x($link) . "</link>\n";
        // A permanent, unique identifier. The URL serves, and isPermaLink
        // says so honestly.
        $out .= '      <guid isPermaLink="true">' . $x($link) . "</guid>\n";
        if ($date !== '') {
            $out .= '      <pubDate>' . $x(date(DATE_RSS, (int) strtotime($date))) . "</pubDate>\n";
        }
        $category = (string) ($meta['category'] ?? '');
        if ($category !== '') {
            $out .= '      <category>' . $x(category_label($category, $lang)) . "</category>\n";
        }
        $out .= '      <description>' . $x(auto_description($post, 300)) . "</description>\n";
        // Full article body for readers that show it; CDATA so the HTML
        // survives intact.
        $out .= '      <content:encoded><![CDATA[' . feed_absolute_html($post['html']) . "]]></content:encoded>\n";
        $out .= "    </item>\n";
    }

    $out .= "  </channel>\n</rss>\n";
    return $out;
}

/** Atom 1.0 document for one language. */
function build_atom_feed(string $lang): string
{
    $x = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');

    $posts     = array_slice(load_posts($lang), 0, FEED_MAX_ITEMS);
    $site_name = (string) cfg('site.name');
    $self      = feed_url($lang, 'atom');
    $home      = abs_url(lang_path($lang, 'home'));
    $author    = (string) (cfg('identity.owner_name') ?? $site_name);

    $newest  = $posts !== [] ? effective_post_date($posts[0]['meta']) : '';
    $updated = $newest !== '' ? date(DATE_ATOM, (int) strtotime($newest)) : date(DATE_ATOM);

    $out  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $out .= '<feed xmlns="http://www.w3.org/2005/Atom" xml:lang="' . $x($lang) . '">' . "\n";
    $out .= '  <title>' . $x($site_name) . "</title>\n";
    $out .= '  <subtitle>' . $x(cfg_lang('site.tagline', $lang)) . "</subtitle>\n";
    $out .= '  <id>' . $x($home) . "</id>\n";
    $out .= '  <link href="' . $x($home) . '"/>' . "\n";
    $out .= '  <link href="' . $x($self) . '" rel="self" type="application/atom+xml"/>' . "\n";
    $out .= '  <updated>' . $x($updated) . "</updated>\n";
    $out .= '  <author><name>' . $x($author) . "</name></author>\n";

    foreach ($posts as $post) {
        $meta  = $post['meta'];
        $slug  = (string) ($meta['slug'] ?? '');
        $title = (string) ($meta['title'] ?? '');
        if ($slug === '' || $title === '') continue;

        $link      = abs_url(post_url($slug, $lang));
        $published = (string) ($meta['date'] ?? '');
        $modified  = effective_post_date($meta);

        $out .= "  <entry>\n";
        $out .= '    <title>' . $x($title) . "</title>\n";
        $out .= '    <id>' . $x($link) . "</id>\n";
        $out .= '    <link href="' . $x($link) . '"/>' . "\n";
        if ($published !== '') {
            $out .= '    <published>' . $x(date(DATE_ATOM, (int) strtotime($published))) . "</published>\n";
        }
        if ($modified !== '') {
            $out .= '    <updated>' . $x(date(DATE_ATOM, (int) strtotime($modified))) . "</updated>\n";
        }
        $category = (string) ($meta['category'] ?? '');
        if ($category !== '') {
            $out .= '    <category term="' . $x(category_label($category, $lang)) . '"/>' . "\n";
        }
        $out .= '    <summary>' . $x(auto_description($post, 300)) . "</summary>\n";
        $out .= '    <content type="html"><![CDATA[' . feed_absolute_html($post['html']) . "]]></content>\n";
        $out .= "  </entry>\n";
    }

    $out .= "</feed>\n";
    return $out;
}
