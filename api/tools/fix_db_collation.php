<?php
/**
 * Normalise the collation of EVERY table in the FA database to ONE collation,
 * so cross-table JOINs never hit "Illegal mix of collations" (error 1267).
 *
 * Run once on the server (BACK UP THE DATABASE FIRST):
 *   php api/tools/fix_db_collation.php
 *   php api/tools/fix_db_collation.php utf8mb4 utf8mb4_general_ci   # optional override
 *
 * Background:
 *   Creating/importing tables across different MySQL/MariaDB versions or tools
 *   can leave a database with a mix of utf8mb3_general_ci, utf8mb3_unicode_ci
 *   and (on MariaDB 11.5+/12.x) utf8mb3_uca1400_ai_ci.  Any JOIN comparing text
 *   columns of two different collations fails.  FA's canonical collation is
 *   utf8_general_ci, so we converge everything (the DB default + all base
 *   tables) onto it.  The charset stays utf8mb3, so stored data is unchanged.
 *
 *   This converts FA core tables too (not just 0_avogs_*), because the mismatch
 *   can be between two FA tables.  It is safe (same charset) but broad — take a
 *   backup first:  mysqldump <db> > backup.sql
 */

require dirname(__DIR__) . '/bootstrap.php';

function out($m) { fwrite(STDOUT, $m . "\n"); }

$charset = 'utf8';
$collation = 'utf8_general_ci'; // FA canonical
if (isset($argv[1]) && $argv[1] !== '') $charset = preg_replace('/[^a-z0-9]/i', '', $argv[1]);
if (isset($argv[2]) && $argv[2] !== '') $collation = preg_replace('/[^a-z0-9_]/i', '', $argv[2]);

// MariaDB/MySQL store "utf8" as the alias "utf8mb3"; treat them as identical
// so re-running the tool doesn't needlessly re-convert already-aligned tables.
$targetStored = (strpos($collation, 'utf8_') === 0)
    ? 'utf8mb3_' . substr($collation, 5)
    : $collation;

$dbrow = db_fetch(db_query("SELECT DATABASE()", 'current db'));
$dbname = $dbrow ? $dbrow[0] : null;
out("Database: " . ($dbname ?: '(unknown)'));
out("Target:   CHARSET {$charset} COLLATE {$collation}\n");

// Gather base tables and their current collations.
$tables = array();
$res = db_query("SHOW TABLE STATUS", 'table status');
while ($r = db_fetch_assoc($res)) {
    if (empty($r['Engine'])) continue;            // skip views (NULL engine)
    $tables[$r['Name']] = isset($r['Collation']) ? $r['Collation'] : '';
}

// Report current spread so the operator can see what they have.
$spread = array();
foreach ($tables as $coll) {
    $key = $coll === '' ? '(none)' : $coll;
    $spread[$key] = isset($spread[$key]) ? $spread[$key] + 1 : 1;
}
out("Current collation spread (" . count($tables) . " tables):");
foreach ($spread as $coll => $n) out("  {$coll}: {$n}");
out("");

// Relax FK checks for the duration (shared FA connection = one session).
db_query("SET FOREIGN_KEY_CHECKS = 0", 'disable fk checks');

// Converge the database default collation.
if ($dbname) {
    db_query("ALTER DATABASE `{$dbname}` CHARACTER SET {$charset} COLLATE {$collation}", 'alter db default');
    out("Database default set to {$collation}.");
}

// Convert each table that isn't already on the target collation.
$converted = 0;
$skipped = 0;
foreach ($tables as $t => $coll) {
    if ($coll === $collation || $coll === $targetStored) { $skipped++; continue; }
    db_query("ALTER TABLE `{$t}` CONVERT TO CHARACTER SET {$charset} COLLATE {$collation}", "convert {$t}");
    out("  \xE2\x9C\x93 {$t} ({$coll}) -> {$collation}");
    $converted++;
}

db_query("SET FOREIGN_KEY_CHECKS = 1", 'enable fk checks');

out("\nDone. {$converted} table(s) converted, {$skipped} already on {$collation}.");
