<?php
declare(strict_types=1);

define('ROOT', dirname(__DIR__));

// config first (everything reads it), request (used by site_url), images
// before content (body-image rewriting), seo.php before its siblings.
require ROOT . '/lib/config.php';
require ROOT . '/lib/request.php';
require ROOT . '/lib/ratelimit.php';
require ROOT . '/lib/i18n.php';
require ROOT . '/lib/images.php';
require ROOT . '/lib/content.php';
require ROOT . '/lib/router.php';
require ROOT . '/lib/seo.php';
require ROOT . '/lib/seo-jsonld.php';
require ROOT . '/lib/seo-sitemap.php';
require ROOT . '/lib/seo-agents.php';

// ── Machine-readable endpoints ───────────────────────────────────────────
// Served directly, bypassing templates/layout. nginx's trailing-slash
// redirect skips paths containing a dot, so these reach us untouched.
$raw_path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$seo_path = rtrim($raw_path, '/') ?: '/';

if ($seo_path === '/sitemap.xml') {
    header('Content-Type: application/xml; charset=utf-8');
    header('X-Robots-Tag: noindex');
    echo build_sitemap_xml();
    exit;
}
if ($seo_path === '/robots.txt') {
    header('Content-Type: text/plain; charset=utf-8');
    echo build_robots_txt();
    exit;
}
if ($seo_path === '/llms.txt') {
    header('Content-Type: text/plain; charset=utf-8');
    echo build_llms_txt();
    exit;
}
if ($seo_path === '/sitemap.xsl') {
    // Browsers refuse an XSLT served with the wrong MIME type.
    header('Content-Type: text/xsl; charset=utf-8');
    header('X-Robots-Tag: noindex');
    echo build_sitemap_xsl();
    exit;
}

// Raw markdown URLs: /<page-path>.md serves the page's source markdown.
if (str_ends_with($seo_path, '.md')) {
    $base      = substr($seo_path, 0, -3);
    $html_path = ($base === '' ? '/' : $base . '/');
    $md_match  = route($html_path);
    if ($md_match !== null) {
        $file = resolve_content_path($md_match['template'], $md_match['params'], $md_match['lang']);
        if ($file !== null) {
            header('Content-Type: text/markdown; charset=utf-8');
            readfile($file);
            exit;
        }
    }
}

// Normalise URI: strip the query string, ensure a leading slash, and add a
// trailing slash — EXCEPT on paths containing a dot, which are files rather
// than directories (/feed.xml, /about.md). That exception mirrors nginx's
// redirect rule exactly; without it, a route whose path ends in a file
// extension can never be matched, because the router would be handed
// "/feed.xml/" and the route says "/feed.xml".
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$uri = '/' . trim((string) $uri, '/');
if ($uri !== '/' && !str_contains($uri, '.')) {
    $uri .= '/';
}

// ── Permanent redirects (301) and retired URLs (410 Gone), from config ───
$redirects = (array) cfg('redirects');
if (isset($redirects[$uri])) {
    header('Location: ' . $redirects[$uri], true, 301);
    exit;
}

// Dispatch. The router decides the language — it is whichever configured
// language's path matched.
$match = route($uri);
if ($match === null) {
    // Language for the error page: longest matching configured prefix wins,
    // default language otherwise.
    $error_lang = default_lang();
    $best_len   = 0;
    foreach (cfg('languages') as $code => $meta) {
        $prefix = (string) $meta['prefix'];
        if ($prefix !== '' && str_starts_with($uri, $prefix . '/') && strlen($prefix) > $best_len) {
            $error_lang = (string) $code;
            $best_len   = strlen($prefix);
        }
    }
    // Retired URLs → 410 Gone (removed for good; de-indexes faster than a
    // 404); genuine typos/unknowns → 404.
    if (in_array($uri, (array) cfg('gone'), true)) {
        http_response_code(410);
        $match = ['template' => '410', 'params' => [], 'lang' => $error_lang, 'route' => ''];
    } else {
        http_response_code(404);
        $match = ['template' => '404', 'params' => [], 'lang' => $error_lang, 'route' => ''];
    }
}

$lang = (string) $match['lang'];
load_i18n($lang);

// ── Markdown content negotiation ("Markdown for Agents") ─────────────────
// An agent sending `Accept: text/markdown` gets the page's markdown on its
// canonical URL. Browsers send `Accept: text/html…` and keep HTML.
// `Vary: Accept` (here and on the HTML responses below) stops caches from
// cross-serving the two representations.
if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'text/markdown')) {
    if ($match['template'] === 'home') {
        header('Content-Type: text/markdown; charset=utf-8');
        header('Vary: Accept');
        echo build_home_markdown($lang);
        exit;
    }
    $md_file = resolve_content_path($match['template'], $match['params'], $lang);
    if ($md_file !== null) {
        header('Content-Type: text/markdown; charset=utf-8');
        header('Vary: Accept');
        readfile($md_file);
        exit;
    }
}

// CSS code-splitting: every page loads tokens + base, then at most one slice
// named after the template, so a page never downloads another page type's
// CSS. The slice is discovered by filename rather than declared in a map, so
// a module that ships templates/<name>.php + assets/css/<name>.css gets its
// styles loaded on its own pages and nowhere else — no core file to edit.
// $css_shared lets several templates deliberately share one slice.
$css_shared = ['post' => 'content', 'page' => 'content'];
$css_slice  = $css_shared[$match['template']] ?? $match['template'];
$css_files  = ['tokens', 'base'];
if (is_file(ROOT . "/public/assets/css/$css_slice.css")) {
    $css_files[] = $css_slice;
}

$params           = $match['params'];
$params['lang']   = $lang;
$params['route']  = (string) $match['route'];
$params['md_url'] = resolve_content_path($match['template'], $match['params'], $lang) !== null
    ? rtrim($uri, '/') . '.md'
    : null;

// Language alternates for this page — hreflang, the language switcher and
// the sitemap all derive from this one computation.
$alternates            = route_alternates($match);
$params['alternates']  = $alternates;

// HTTP Link headers (RFC 8288) advertising the markdown alternates, so
// agents can discover them from response headers without parsing HTML:
// a sitewide pointer to the LLM site overview, plus a per-page pointer.
header('Link: </llms.txt>; rel="alternate"; type="text/markdown"; title="Site overview for LLMs"', false);
if ($params['md_url'] !== null) {
    header('Link: <' . $params['md_url'] . '>; rel="alternate"; type="text/markdown"; title="This page in Markdown"', false);
}
// Vary: Accept on every URL that has a markdown representation.
if ($match['template'] === 'home' || $params['md_url'] !== null) {
    header('Vary: Accept');
}

// Render the inner template into a variable, then wrap it in the layout.
ob_start();
extract($params, EXTR_SKIP);
include ROOT . '/templates/' . $match['template'] . '.php';
$page_content = ob_get_clean();

include ROOT . '/templates/layout.php';
