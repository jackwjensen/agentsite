<?php
declare(strict_types=1);

/**
 * Installed from modules/contact-form (v2).
 *
 * Retention sweep for contact-form records. Deletes .txt files older than
 * RETENTION_DAYS from BOTH _logs/mail/ (delivered) and
 * _logs/spam/quarantine/ (blocked).
 *
 * **Both directories, deliberately.** They hold the same personal data — a
 * name, an email address, a message, an IP — and a retention promise with an
 * undeclared exception is not a promise. If your privacy policy states a
 * retention period, it must cover the quarantine too, and say that blocked
 * submissions are stored at all.
 *
 * Idempotent and safe to re-run. Wire it to cron on the host, e.g. daily:
 *
 *   0 3 * * * cd /srv/mysite && docker compose exec -T web \
 *             php scripts/prune-mail-logs.php >> _logs/prune.log 2>&1
 *
 * This is a retention floor for the WEBSITE's copy only. If your jurisdiction
 * requires you to keep business correspondence longer (Danish bookkeeping law
 * requires five years for accounting records, for instance), that obligation
 * belongs to whatever mailbox actually received the message — not to this
 * log directory.
 */

const RETENTION_DAYS = 365;

$roots = [
    dirname(__DIR__) . '/_logs/mail',
    dirname(__DIR__) . '/_logs/spam/quarantine',
];

$cutoff  = time() - (RETENTION_DAYS * 86400);
$deleted = 0;
$kept    = 0;

foreach ($roots as $dir) {
    if (!is_dir($dir)) {
        continue;
    }
    foreach (glob($dir . '/*.txt') ?: [] as $file) {
        $mtime = filemtime($file);
        if ($mtime === false) {
            continue;
        }
        if ($mtime < $cutoff) {
            if (unlink($file)) {
                $deleted++;
            } else {
                fwrite(STDERR, "could not delete: $file\n");
            }
        } else {
            $kept++;
        }
    }
}

echo date('c') . " prune: deleted $deleted, kept $kept (retention " . RETENTION_DAYS . " days)\n";
