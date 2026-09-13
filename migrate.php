<?php
declare(strict_types=1);
// One-time deployment helper for the alpha.3.13 update.
// Safe to run more than once: it only adds new tables/columns (via config.php's
// run_app_migrations), it never touches existing inventory/sales/customer rows.
// Run it once by visiting this file in a browser (or `php migrate.php` over CLI),
// confirm the output looks right, then delete this file from the server.
require __DIR__ . '/config.php';
ensure_dirs();

$isCli = PHP_SAPI === 'cli';
$out = static function (string $line) use ($isCli) {
    echo $isCli ? $line . "\n" : htmlspecialchars($line) . "<br>\n";
};

if (!$isCli) {
    echo "<!doctype html><meta charset='utf-8'><title>LEGO Resale — migrate</title>";
    echo "<body style='font:14px/1.5 system-ui;max-width:640px;margin:40px auto;padding:0 16px'>";
    echo "<h2>LEGO Resale — schema migration</h2>";
}

try {
    $pdo = db(); // db() runs run_app_migrations() as a side effect.
    $out('Connected to: ' . DB_PATH);

    $tables = ['catalog_sets','inventory_units','unit_photos','valuations','pricing_recommendations',
        'listings','sales','customers','customer_interests','listing_metrics','users','import_batches'];
    $out('');
    $out('Table status:');
    foreach ($tables as $t) {
        try {
            $n = (int)$pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn();
            $out("  [ok] $t ($n rows)");
        } catch (Throwable $e) {
            $out("  [MISSING] $t — " . $e->getMessage());
        }
    }

    $userCount = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $out('');
    $out("schema_version: " . APP_VERSION);
    $out('');
    if ($userCount === 0) {
        $out('No accounts exist yet. Next step: run create_user.php (CLI) or SETUP_accounts.php (browser) to create your owner login.');
    } else {
        $out("$userCount account(s) already exist. Nothing further needed here.");
    }
    $out('');
    $out('Migration complete. Existing inventory/sales/customer data was not modified. Delete this file from the server now.');
} catch (Throwable $e) {
    $out('ERROR: ' . $e->getMessage());
}

if (!$isCli) echo "</body>";
