<?php
/**
 * Align the collation of the 0_avogs_* tables with FrontAccounting's own tables.
 *
 * Run once from the command line:
 *   php api/tools/fix_collation.php
 *
 * Background:
 *   On MariaDB 11.5+/12.x, creating a table with "DEFAULT CHARSET=utf8" (no
 *   explicit COLLATE) yields utf8mb3_uca1400_ai_ci, while FA's tables use the
 *   older utf8mb3_general_ci.  Any JOIN between an avogs text column and an FA
 *   text column (e.g. 0_avogs_shifts.opened_by = 0_users.user_id) then fails
 *   with "Illegal mix of collations", which makes the shift inquiries return
 *   nothing.  This tool converts every 0_avogs_* table to FA's collation so
 *   the joins work.  Safe to re-run.
 */

require dirname(__DIR__) . '/bootstrap.php';

function out($m) { fwrite(STDOUT, $m . "\n"); }

$P = TB_PREF;

// Detect FA's collation from a known FA table column.
$row = db_fetch_assoc(db_query("SHOW FULL COLUMNS FROM {$P}users LIKE 'user_id'", 'read FA collation'));
$faColl = ($row && !empty($row['Collation'])) ? $row['Collation'] : 'utf8mb3_general_ci';
$charset = (strpos($faColl, 'utf8mb4') === 0) ? 'utf8mb4' : 'utf8';
out("FA target collation: {$faColl} (charset {$charset})");

// Collect every avogs table. We list all tables and filter in PHP because
// TB_PREF is a placeholder token FA only substitutes for real query bodies,
// not inside a quoted LIKE pattern.
$tables = array();
$res = db_query("SHOW TABLES", 'list tables');
while ($r = db_fetch($res)) {
    if (strpos($r[0], 'avogs_') !== false) {
        $tables[] = $r[0];
    }
}

if (!$tables) {
    out("No avogs_ tables found — nothing to do.");
    out("\nDone.");
    return;
}

$n = 0;
$skipped = 0;
foreach ($tables as $t) {
    $status = db_fetch_assoc(db_query("SHOW TABLE STATUS LIKE " . db_escape($t), "status {$t}"));
    $cur = $status && !empty($status['Collation']) ? $status['Collation'] : '';
    if ($cur === $faColl) {
        out("  = {$t} already {$faColl}");
        $skipped++;
        continue;
    }
    db_query("ALTER TABLE `{$t}` CONVERT TO CHARACTER SET {$charset} COLLATE {$faColl}", "convert {$t}");
    out("  \xE2\x9C\x93 {$t} ({$cur}) -> {$faColl}");
    $n++;
}

out("\nDone. {$n} table(s) converted, {$skipped} already aligned.");
