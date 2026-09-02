<?php
/**
 * admin/migrations/migrate.php
 *
 * Schema migration runner — the ONLY sanctioned path for DDL in this project.
 *
 * Schema parity with the Rosalyn platform is locked, so the application must not
 * alter its own schema during a request (see config/database.php,
 * rh_auto_migrate_enabled()). Every schema change lands here instead: one
 * numbered file per change, applied once, recorded in the existing
 * `migration_log` table.
 *
 * Usage (CLI only):
 *   php admin/migrations/migrate.php --status    Show applied / pending
 *   php admin/migrations/migrate.php --dry-run   Print what would run
 *   php admin/migrations/migrate.php --run       Apply pending migrations
 *
 * Migration file format — admin/migrations/NNN_snake_name.php returning:
 *   return [
 *       'name' => 'create_room_inspections',
 *       'up'   => function (PDO $pdo): string { ...; return 'what happened'; },
 *   ];
 *
 * Each 'up' must be idempotent: check before it creates or alters, and return a
 * short human-readable summary. Never write destructive DDL here.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain');
    echo "Forbidden: migrations are CLI-only.\n";
    exit(1);
}

$args    = array_slice($argv, 1);
$doRun   = in_array('--run', $args, true);
$dryRun  = in_array('--dry-run', $args, true);
$status  = in_array('--status', $args, true) || (!$doRun && !$dryRun);

// The runner connects through the app's own config so credentials resolve
// identically. This is safe: the auto-migration bootstrap is gated off by
// default, so including database.php performs no DDL.
require_once __DIR__ . '/../../config/database.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    fwrite(STDERR, "No database connection.\n");
    exit(1);
}

/** Load migration definitions from this directory, in filename order. */
function rh_load_migrations(): array
{
    $files = glob(__DIR__ . '/[0-9][0-9][0-9]_*.php') ?: [];
    sort($files, SORT_STRING);

    $out = [];
    foreach ($files as $file) {
        $def = require $file;
        $base = basename($file, '.php');
        if (!is_array($def) || !isset($def['up']) || !is_callable($def['up'])) {
            fwrite(STDERR, "Skipping malformed migration: {$base}\n");
            continue;
        }
        $out[] = [
            'id'   => (int)substr($base, 0, 3),
            'file' => $base,
            'name' => (string)($def['name'] ?? $base),
            'up'   => $def['up'],
        ];
    }
    return $out;
}

/** Names already recorded as completed. */
function rh_applied(PDO $pdo): array
{
    $done = [];
    $stmt = $pdo->query("SELECT migration_name FROM migration_log WHERE status = 'completed'");
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $n) {
        $done[(string)$n] = true;
    }
    return $done;
}

$migrations = rh_load_migrations();
$applied    = rh_applied($pdo);

$pending = array_values(array_filter(
    $migrations,
    static fn(array $m): bool => !isset($applied[$m['name']])
));

printf("Migrations found: %d | applied: %d | pending: %d\n\n", count($migrations), count($applied), count($pending));

foreach ($migrations as $m) {
    printf("  [%s] %-40s %s\n", isset($applied[$m['name']]) ? 'x' : ' ', $m['name'], $m['file']);
}
echo "\n";

if ($status) {
    echo "Read-only. Use --dry-run to preview, --run to apply.\n";
    exit(0);
}

if (!$pending) {
    echo "Nothing to do — all migrations applied.\n";
    exit(0);
}

if ($dryRun) {
    echo "DRY RUN — would apply:\n";
    foreach ($pending as $m) {
        printf("  - %s (%s)\n", $m['name'], $m['file']);
    }
    echo "\nNo changes made.\n";
    exit(0);
}

$failed = 0;
foreach ($pending as $m) {
    printf("Applying %s ... ", $m['name']);

    $mark = $pdo->prepare(
        "INSERT INTO migration_log (migration_name, migration_date, status, created_at)
         VALUES (?, NOW(), 'in_progress', NOW())"
    );
    $mark->execute([$m['name']]);
    $logId = (int)$pdo->lastInsertId();

    try {
        $summary = (string)($m['up'])($pdo);
        $pdo->prepare("UPDATE migration_log SET status = 'completed', migration_date = NOW() WHERE migration_id = ?")
            ->execute([$logId]);
        echo "OK — {$summary}\n";
    } catch (Throwable $e) {
        $pdo->prepare("UPDATE migration_log SET status = 'failed' WHERE migration_id = ?")
            ->execute([$logId]);
        $failed++;
        echo "FAILED\n";
        fwrite(STDERR, "  {$m['name']}: " . $e->getMessage() . "\n");
        break; // stop on first failure — do not run later migrations against a half-migrated schema
    }
}

printf("\nDone. applied=%d failed=%d\n", count($pending) - $failed, $failed);
exit($failed > 0 ? 1 : 0);
