<?php
/**
 * Diagnostic + repair script for the FA session-timeout preference.
 *
 * Run once from the command line:
 *   php api/tools/fix_login_tout.php
 *
 * What it does:
 *   1. Prints the current login_tout value from 0_sys_prefs.
 *   2. If the value is missing or less than 300 seconds (5 min), updates it
 *      to 3600 seconds (1 hour) and prints confirmation.
 *
 * Background:
 *   login_tout is stored in SECONDS in 0_sys_prefs.  The FA admin panel label
 *   says "Login Timeout: (seconds)".  If it was accidentally set to 60 the web
 *   session expires every minute — exactly the symptom we saw.
 */

require dirname(__DIR__) . '/bootstrap.php';

$P = TB_PREF;

// ── Read current value ───────────────────────────────────────────────────────
$res = db_query("SELECT value FROM {$P}sys_prefs WHERE name = 'login_tout'", 'read login_tout');
$row = $res ? db_fetch_assoc($res) : null;
$current = $row ? (int)$row['value'] : null;

if ($current === null) {
    echo "login_tout is NOT SET in {$P}sys_prefs.\n";
} else {
    echo "Current login_tout = {$current} seconds";
    if ($current < 60)         echo " ← suspiciously small!";
    elseif ($current < 300)    echo " ← very short (less than 5 min)";
    elseif ($current === 60)   echo " ← THIS IS THE BUG (1 minute)";
    echo "\n";
}

// ── Fix if needed ────────────────────────────────────────────────────────────
$target = 3600; // 1 hour

if ($current === null || $current < 300) {
    db_query(
        "REPLACE INTO {$P}sys_prefs (name, category, type, length, value)
         VALUES ('login_tout', 'setup.company', 'smallint', 6, " . db_escape($target) . ")",
        'set login_tout'
    );
    echo "✓ login_tout updated to {$target} seconds (1 hour).\n";
    echo "  Users must log out and back in for the new timeout to take effect\n";
    echo "  (timeout is stamped into the session at login time).\n";
} else {
    echo "login_tout looks healthy — no change needed.\n";
    echo "If sessions are still expiring, the more likely cause was the API\n";
    echo "bootstrap picking up browser sessions. That is fixed in bootstrap.php.\n";
}

echo "\nDone.\n";
