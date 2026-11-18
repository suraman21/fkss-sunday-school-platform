<?php
/**
 * Deploy preflight: PHP version + extension asserts (L1).
 *
 * Usage (on the DEPLOY TARGET, via cPanel Terminal / cron PHP-CLI / ssh):
 *   php tools/preflight_extensions.php
 *
 * Exit 0 = every requirement present; exit 1 = something missing (each gap
 * names what breaks, so the deploy stops BEFORE users hit clean-but-dead
 * features). Read-only: loads nothing, connects nowhere.
 *
 * The required set mirrors README.md ("Local development" prerequisites);
 * the what-breaks column is the L1 finding (ID cards need gd, XLSX needs
 * xml+zip) plus each extension's primary consumer.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

$failures = 0;

$check = static function (string $label, bool $ok, string $breaks) use (&$failures): void {
    printf("[%s] %-28s %s\n", $ok ? 'OK  ' : 'FAIL', $label, $ok ? '' : ('<- ' . $breaks));
    if (!$ok) {
        $failures++;
    }
};

echo "PHP runtime preflight (" . PHP_VERSION . " on " . php_uname('s') . ")\n\n";

$check(
    'PHP >= 8.4',
    version_compare(PHP_VERSION, '8.4.0', '>='),
    'production parity is 8.4+; older runtimes reject current syntax (CONTRIBUTING).'
);

$extensions = [
    // [extension, what breaks without it]
    ['mysqli', 'admin DB layer ($conn) — almost every admin page.'],
    ['pdo_mysql', 'directory/export APIs ($pdo) — member lists, exports.'],
    ['mbstring', 'Amharic text handling (length/substr) — names, search, labels.'],
    ['curl', 'Telegram bot + webhook calls — registrations, notifications.'],
    ['gd', 'ID-card photos + QR codes — cards 500 for members WITH codes (L1).'],
    ['xml', 'XLSX export/import (PhpSpreadsheet reader core) — 500s (L1).'],
    ['xmlwriter', 'XLSX export streaming — 500s (L1).'],
    ['dom', 'XLSX parsing (PhpSpreadsheet) — imports fail (L1).'],
    ['zip', 'XLSX packaging (OOXML is a zip) — exports/imports 500 (L1).'],
    ['bcmath', 'precision money/fee math — finance + fee totals.'],
];

foreach ($extensions as [$ext, $breaks]) {
    $check('ext-' . $ext, extension_loaded($ext), $breaks);
}

// Optional: reported, never release-blocking. The member search engine
// (ADR-0022) degrades to plain MySQL when pdo_sqlite is unavailable.
foreach (['pdo_sqlite'] as $ext) {
    $ok = extension_loaded($ext);
    printf(
        "[%s] %-28s %s\n",
        $ok ? 'OK  ' : 'WARN',
        'ext-' . $ext . ' (optional)',
        $ok ? '' : '<- member search falls back to plain MySQL (slower, no typo tolerance).'
    );
}

echo "\n" . ($failures === 0
    ? "PASS: all " . (count($extensions) + 1) . " checks green.\n"
    : "FAIL: {$failures} check(s) missing — do not deploy until the host provides them.\n");

exit($failures === 0 ? 0 : 1);
