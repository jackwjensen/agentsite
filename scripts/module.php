<?php
declare(strict_types=1);

/**
 * Module installer.
 *
 *   php scripts/module.php list
 *   php scripts/module.php install <name|path> [--yes]
 *   php scripts/module.php uninstall <name> [--yes]
 *   php scripts/module.php status
 *
 * The whole design rests on one rule: **installing a module never edits a
 * shared file.** An install is a set of file copies into paths that belong to
 * that module alone, plus appended .env lines. Routes and UI strings are
 * discovered from per-module files (config/routes.d/<module>.php,
 * i18n/modules/<module>.<lang>.php) rather than merged into config/site.php.
 *
 * That is what makes modules safe to install, uninstall and contribute:
 *   - Two modules cannot conflict except by claiming the same filename,
 *     which is loud and caught before any file is written.
 *   - Uninstall is exact: delete what the payload lists, and the tree is
 *     byte-identical to before.
 *   - `git pull` on the framework never conflicts with an installed module.
 *   - CI can round-trip every module automatically (see check.yml).
 *
 * This script never executes module code. It copies files and reads JSON.
 */

define('ROOT', dirname(__DIR__));

const MODULE_MANIFEST = 'module.json';
const MODULE_PAYLOAD  = 'root';
// Committed, not in _logs/: a site is deployed by cloning its repo, so the
// record of what is installed has to travel with it. It ships as {} and
// returns to {} after an uninstall, which is what lets CI assert that a
// module round-trip leaves the tree byte-identical.
const INSTALL_RECORD  = '/config/modules.json';

$argv = $_SERVER['argv'];
$command = $argv[1] ?? 'help';
$target  = $argv[2] ?? '';
$assume_yes = in_array('--yes', $argv, true) || in_array('-y', $argv, true);

switch ($command) {
    case 'list':      exit(cmd_list());
    case 'status':    exit(cmd_status());
    case 'install':   exit(cmd_install($target, $assume_yes));
    case 'uninstall': exit(cmd_uninstall($target, $assume_yes));
    default:          exit(cmd_help());
}

function cmd_help(): int
{
    echo <<<TXT
    agentsite module installer

      php scripts/module.php list                     available modules
      php scripts/module.php status                   installed modules
      php scripts/module.php install <name|path> [-y] install a module
      php scripts/module.php uninstall <name> [-y]    remove a module

    <name> is a directory under modules/. <path> may also be a path to a
    module directory elsewhere on disk (a third-party module you have
    cloned and read — see docs/COMMUNITY-MODULES.md).

    TXT;
    return 0;
}

function cmd_list(): int
{
    $installed = installed_modules();
    $dirs = glob(ROOT . '/modules/*', GLOB_ONLYDIR) ?: [];
    if ($dirs === []) {
        echo "No modules in modules/.\n";
        return 0;
    }
    echo "Available modules:\n\n";
    foreach ($dirs as $dir) {
        $name     = basename($dir);
        $manifest = read_manifest($dir);
        $mark     = isset($installed[$name]) ? '[installed]' : '[         ]';
        $desc     = (string) ($manifest['description'] ?? '');
        printf("  %s %-20s %s\n", $mark, $name, $desc);
        $requires = (array) ($manifest['requires'] ?? []);
        if ($requires !== []) {
            printf("  %s %-20s requires: %s\n", '           ', '', implode(', ', $requires));
        }
    }
    echo "\nInstall with:  php scripts/module.php install <name>\n";
    return 0;
}

function cmd_status(): int
{
    $installed = installed_modules();
    if ($installed === []) {
        echo "No modules installed.\n";
        return 0;
    }
    echo "Installed modules:\n\n";
    foreach ($installed as $name => $record) {
        printf("  %-20s v%s  (%d files)\n", $name, (string) ($record['version'] ?? '?'), count((array) ($record['files'] ?? [])));
    }
    return 0;
}

function cmd_install(string $target, bool $assume_yes): int
{
    if ($target === '') {
        fwrite(STDERR, "Which module? See: php scripts/module.php list\n");
        return 2;
    }

    $dir = resolve_module_dir($target);
    if ($dir === null) {
        fwrite(STDERR, "Module not found: $target\n");
        return 2;
    }

    $manifest = read_manifest($dir);
    $name     = (string) ($manifest['name'] ?? basename($dir));
    $version  = (string) ($manifest['version'] ?? '1');

    $installed = installed_modules();
    if (isset($installed[$name])) {
        echo "$name is already installed. Uninstall first to reinstall.\n";
        return 0;
    }

    // Dependencies first (a module may declare `requires: ["admin"]`).
    foreach ((array) ($manifest['requires'] ?? []) as $dependency) {
        if (isset($installed[$dependency])) {
            continue;
        }
        echo "$name requires '$dependency', which is not installed.\n";
        if (!$assume_yes && !confirm("Install '$dependency' first?")) {
            fwrite(STDERR, "Aborted: dependency '$dependency' not installed.\n");
            return 1;
        }
        $result = cmd_install($dependency, $assume_yes);
        if ($result !== 0) {
            return $result;
        }
        $installed = installed_modules();
    }

    // Collect the payload, refusing anything that tries to escape the tree.
    $payload_root = $dir . '/' . MODULE_PAYLOAD;
    if (!is_dir($payload_root)) {
        fwrite(STDERR, "Module '$name' has no " . MODULE_PAYLOAD . "/ payload directory.\n");
        return 2;
    }
    $files = payload_files($payload_root);
    if ($files === []) {
        fwrite(STDERR, "Module '$name' payload is empty.\n");
        return 2;
    }

    // Refuse to overwrite anything that already exists. A collision is either
    // two modules claiming one path or a partial previous install — both need
    // a human, and silently overwriting would make uninstall destructive.
    $collisions = [];
    foreach ($files as $relative) {
        if (file_exists(ROOT . '/' . $relative)) {
            $collisions[] = $relative;
        }
    }
    if ($collisions !== []) {
        fwrite(STDERR, "Refusing to install '$name' — these files already exist:\n");
        foreach ($collisions as $c) {
            fwrite(STDERR, "  - $c\n");
        }
        fwrite(STDERR, "Remove them, or uninstall the module that owns them, and try again.\n");
        return 1;
    }

    echo "Installing $name v$version — " . count($files) . " file(s):\n";
    foreach ($files as $relative) {
        echo "  + $relative\n";
    }
    $env_vars = (array) ($manifest['env'] ?? []);
    if ($env_vars !== []) {
        echo "  .env additions: " . implode(', ', array_keys($env_vars)) . "\n";
    }
    if (!$assume_yes && !confirm('Proceed?')) {
        echo "Aborted.\n";
        return 1;
    }

    foreach ($files as $relative) {
        $source = $payload_root . '/' . $relative;
        $destination = ROOT . '/' . $relative;
        $parent = dirname($destination);
        if (!is_dir($parent) && !mkdir($parent, 0775, true) && !is_dir($parent)) {
            fwrite(STDERR, "Could not create directory: $parent\n");
            return 1;
        }
        if (!copy($source, $destination)) {
            fwrite(STDERR, "Could not copy: $relative\n");
            return 1;
        }
    }

    append_env_vars($env_vars, $name);
    record_install($name, $version, $files);

    echo "\n$name installed.\n";
    $manifest_path = $dir . '/MODULE.md';
    if (is_file($manifest_path)) {
        echo "Next steps are in " . str_replace(ROOT . '/', '', $manifest_path) . " — read the Verification section.\n";
    }
    if ($env_vars !== []) {
        echo "Set the new .env values before using it.\n";
    }
    echo "Then restart the site:  docker compose up -d\n";
    return 0;
}

function cmd_uninstall(string $name, bool $assume_yes): int
{
    if ($name === '') {
        fwrite(STDERR, "Which module? See: php scripts/module.php status\n");
        return 2;
    }
    $installed = installed_modules();
    if (!isset($installed[$name])) {
        fwrite(STDERR, "Not installed: $name\n");
        return 2;
    }

    // Refuse if another installed module depends on this one.
    foreach ($installed as $other => $record) {
        if ($other === $name) continue;
        $other_dir = resolve_module_dir($other);
        $requires  = $other_dir !== null ? (array) (read_manifest($other_dir)['requires'] ?? []) : [];
        if (in_array($name, $requires, true)) {
            fwrite(STDERR, "Cannot uninstall '$name': '$other' depends on it. Uninstall '$other' first.\n");
            return 1;
        }
    }

    $files = (array) ($installed[$name]['files'] ?? []);
    echo "Removing $name — " . count($files) . " file(s):\n";
    foreach ($files as $relative) {
        echo "  - $relative\n";
    }
    if (!$assume_yes && !confirm('Proceed?')) {
        echo "Aborted.\n";
        return 1;
    }

    foreach ($files as $relative) {
        $path = ROOT . '/' . $relative;
        if (is_file($path)) {
            unlink($path);
        }
    }
    // Remove directories the module created, deepest first, but only when empty.
    $dirs = [];
    foreach ($files as $relative) {
        $dir = dirname($relative);
        while ($dir !== '.' && $dir !== '/' && $dir !== '') {
            $dirs[$dir] = true;
            $dir = dirname($dir);
        }
    }
    $dir_list = array_keys($dirs);
    usort($dir_list, fn(string $a, string $b) => substr_count($b, '/') <=> substr_count($a, '/'));
    foreach ($dir_list as $dir) {
        $path = ROOT . '/' . $dir;
        if (is_dir($path) && (scandir($path) ?: []) === ['.', '..']) {
            rmdir($path);
        }
    }

    forget_install($name);
    echo "\n$name removed. Its .env variables were left in place — delete them by hand if you no longer want them.\n";
    return 0;
}

// ── helpers ──────────────────────────────────────────────────────────────

function resolve_module_dir(string $target): ?string
{
    $in_tree = ROOT . '/modules/' . basename($target);
    if (is_dir($in_tree) && is_file($in_tree . '/' . MODULE_MANIFEST)) {
        return $in_tree;
    }
    // A path to a module directory elsewhere (a third-party module).
    if (is_dir($target) && is_file(rtrim($target, '/\\') . '/' . MODULE_MANIFEST)) {
        return rtrim($target, '/\\');
    }
    return null;
}

function read_manifest(string $dir): array
{
    $file = $dir . '/' . MODULE_MANIFEST;
    if (!is_file($file)) {
        return [];
    }
    $raw = file_get_contents($file);
    if ($raw === false) {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * Every file in a module payload, as tree-relative paths. Anything that could
 * escape the repository root is rejected outright rather than sanitised —
 * a payload that contains '..' is not a mistake worth guessing about.
 */
function payload_files(string $payload_root): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($payload_root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        /** @var SplFileInfo $file */
        if (!$file->isFile()) continue;
        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($payload_root) + 1));
        if ($relative === '' || str_contains($relative, '..') || $relative[0] === '/' || preg_match('/^[a-zA-Z]:/', $relative)) {
            fwrite(STDERR, "Refusing payload path: $relative\n");
            exit(1);
        }
        $files[] = $relative;
    }
    sort($files);
    return $files;
}

function installed_modules(): array
{
    $file = ROOT . INSTALL_RECORD;
    if (!is_file($file)) {
        return [];
    }
    $decoded = json_decode((string) file_get_contents($file), true);
    return is_array($decoded) ? $decoded : [];
}

function record_install(string $name, string $version, array $files): void
{
    $installed = installed_modules();
    $installed[$name] = ['version' => $version, 'files' => $files];
    write_install_record($installed);
}

function forget_install(string $name): void
{
    $installed = installed_modules();
    unset($installed[$name]);
    write_install_record($installed);
}

function write_install_record(array $installed): void
{
    $file = ROOT . INSTALL_RECORD;
    $dir  = dirname($file);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    ksort($installed);
    // An empty PHP array encodes as `[]`, which would make the file's shape
    // flip between object and array as the last module is removed — and a
    // byte difference there is enough to fail CI's round-trip check. Cast to
    // an object so "nothing installed" is always `{}`.
    $payload = $installed === [] ? new stdClass() : $installed;
    file_put_contents($file, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
}

/**
 * Append any missing variables to .env (creating it from .env.example first
 * if needed). Existing values are never touched — a reinstall must not reset
 * a password you already set.
 *
 * Each variable is written with its description as a comment above it. A
 * configuration file that explains itself is the end state worth having:
 * the person who has to set `SMTP_USER` six months from now is reading
 * .env, not the module's documentation.
 *
 * A manifest may write either form:
 *   "KEY": "default"
 *   "KEY": { "default": "…", "description": "what it does" }
 */
function append_env_vars(array $env_vars, string $module_name): void
{
    if ($env_vars === []) {
        return;
    }
    $env_file = ROOT . '/.env';
    if (!is_file($env_file)) {
        $example = ROOT . '/.env.example';
        if (is_file($example)) {
            copy($example, $env_file);
        } else {
            file_put_contents($env_file, "# Site configuration.\n");
        }
    }
    $current = (string) file_get_contents($env_file);
    $append  = '';
    foreach ($env_vars as $key => $spec) {
        if (preg_match('/^' . preg_quote((string) $key, '/') . '=/m', $current)) {
            continue;
        }
        $default     = is_array($spec) ? (string) ($spec['default'] ?? '') : (string) $spec;
        $description = is_array($spec) ? trim((string) ($spec['description'] ?? '')) : '';
        if ($description !== '') {
            foreach (explode("\n", wordwrap($description, 72)) as $comment_line) {
                $append .= '# ' . $comment_line . "\n";
            }
        }
        $append .= $key . '=' . $default . "\n";
    }
    if ($append !== '') {
        $header = "\n# --- $module_name " . str_repeat('-', max(1, 60 - strlen($module_name))) . "\n";
        file_put_contents($env_file, rtrim($current, "\n") . "\n" . $header . $append, LOCK_EX);
    }
}

function confirm(string $question): bool
{
    echo "$question [y/N] ";
    $answer = fgets(STDIN);
    return $answer !== false && in_array(strtolower(trim($answer)), ['y', 'yes'], true);
}
