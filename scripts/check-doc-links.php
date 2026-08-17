<?php
declare(strict_types=1);

/**
 * Check that every relative link in the repository's DOCUMENTATION resolves
 * to a real file.
 *
 * Docs here are read by AI assistants as much as by people, and an assistant
 * following a dead link does not shrug — it invents what the file probably
 * said. A broken cross-reference is therefore a correctness bug, not a
 * cosmetic one, which is why this runs in CI alongside the code checks.
 *
 * `content/` is deliberately excluded: links in a page or post are SITE
 * URLs, not filesystem paths, and are a different question entirely (the
 * content validator checks image references; a link checker for site URLs
 * would need to resolve routes, which is worth doing but is not this).
 */

$root = dirname(__DIR__);

$markdown_files = array_merge(
    glob($root . '/*.md') ?: [],
    glob($root . '/docs/*.md') ?: [],
    glob($root . '/docs/**/*.md') ?: [],
    glob($root . '/modules/*/MODULE.md') ?: [],
    glob($root . '/.github/*.md') ?: []
);
sort($markdown_files);

$problems = [];
foreach ($markdown_files as $file) {
    $relative_file = str_replace('\\', '/', substr($file, strlen($root) + 1));
    $source = file_get_contents($file);
    if ($source === false) {
        continue;
    }
    // Prose that DOCUMENTS link syntax inside a code span is prose, not a
    // link — `[label](path)` in a guide about writing documentation must not
    // be chased. But a link whose LABEL happens to be code,
    // [`file.md`](file.md), is a real link and is still checked. So code
    // spans are blanked before matching, and a link left with an empty label
    // by that blanking is restored.
    $searchable = preg_replace_callback(
        '/(?<!`)`[^`\n]+`(?!`)/',
        static fn(array $m): string => str_repeat(' ', strlen($m[0])),
        $source
    ) ?? $source;
    // …then put back any code span that was only the label of a link.
    $searchable = preg_replace_callback(
        '/\[\s+\]\(/',
        static fn(array $m): string => '[x](',
        $searchable
    ) ?? $searchable;

    if (!preg_match_all('/\[([^\]]*)\]\(([^)\s]+)\)/', $searchable, $matches, PREG_SET_ORDER)) {
        continue;
    }
    foreach ($matches as $match) {
        $target = $match[2];
        // External links, anchors, and site-absolute URLs (which are routes,
        // not paths) are somebody else's problem.
        if (preg_match('#^(https?:|mailto:|//|\#|/)#i', $target)) {
            continue;
        }
        $target = strtok($target, '#');
        if ($target === false || $target === '') {
            continue;
        }
        if (realpath(dirname($file) . '/' . $target) === false) {
            $problems[] = "$relative_file -> $target";
        }
    }
}

if ($problems !== []) {
    fwrite(STDERR, 'Dead documentation links (' . count($problems) . "):\n");
    foreach ($problems as $problem) {
        fwrite(STDERR, "  - $problem\n");
    }
    exit(1);
}

echo 'Documentation links OK — ' . count($markdown_files) . " markdown files checked.\n";
exit(0);
