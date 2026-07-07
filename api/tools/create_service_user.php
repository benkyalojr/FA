<?php
/**
 * Create or reset the FA service account used by api/config.php (no web UI / CSRF).
 *
 *   php api/tools/create_service_user.php
 *
 * Reads fa_service_user and fa_service_pass from api/config.php and clones
 * display/security settings from the admin user.
 */
require dirname(__DIR__) . '/bootstrap.php';

function out($m) { fwrite(STDOUT, $m . "\n"); }

global $db_connections, $AVOGS_CFG;
$comp = isset($_SESSION['wa_current_user']) ? $_SESSION['wa_current_user']->cur_con : 0;
$P = isset($db_connections[$comp]['tbpref']) ? $db_connections[$comp]['tbpref'] : TB_PREF;

$user = isset($AVOGS_CFG['fa_service_user']) ? $AVOGS_CFG['fa_service_user'] : 'apiuser';
$pass = isset($AVOGS_CFG['fa_service_pass']) ? $AVOGS_CFG['fa_service_pass'] : 'apiuser';

out("=== Create FA API service user ===\n");
out("Login: {$user}");

$admin = db_fetch_assoc(db_query(
    "SELECT role_id, language, date_format, date_sep, tho_sep, dec_sep, theme, page_size,
            prices_dec, qty_dec, rates_dec, percent_dec, show_gl, show_codes, show_hints,
            query_size, graphic_links, pos, print_profile, rep_popup, sticky_doc_date,
            startup_tab, transaction_days, save_report_selections, use_date_picker,
            def_print_destination, def_print_orientation
     FROM {$P}users WHERE user_id = 'admin' LIMIT 1",
    'admin template'
));

if (!$admin) {
    out("ERROR: admin user not found in {$P}users");
    exit(1);
}

db_query(
    "INSERT INTO {$P}users
        (user_id, password, real_name, role_id, phone, email, language, date_format, date_sep, tho_sep, dec_sep,
         theme, page_size, prices_dec, qty_dec, rates_dec, percent_dec, show_gl, show_codes, show_hints, query_size,
         graphic_links, pos, print_profile, rep_popup, sticky_doc_date, startup_tab, transaction_days,
         save_report_selections, use_date_picker, def_print_destination, def_print_orientation, inactive)
     VALUES ("
        . db_escape($user) . ", MD5(" . db_escape($pass) . "), 'API Service User', "
        . db_escape($admin['role_id']) . ", '', 'api@localhost', "
        . db_escape($admin['language']) . ", " . db_escape($admin['date_format']) . ", "
        . db_escape($admin['date_sep']) . ", " . db_escape($admin['tho_sep']) . ", "
        . db_escape($admin['dec_sep']) . ", " . db_escape($admin['theme']) . ", "
        . db_escape($admin['page_size']) . ", " . db_escape($admin['prices_dec']) . ", "
        . db_escape($admin['qty_dec']) . ", " . db_escape($admin['rates_dec']) . ", "
        . db_escape($admin['percent_dec']) . ", " . db_escape($admin['show_gl']) . ", "
        . db_escape($admin['show_codes']) . ", " . db_escape($admin['show_hints']) . ", "
        . db_escape($admin['query_size']) . ", " . db_escape($admin['graphic_links']) . ", "
        . db_escape($admin['pos']) . ", " . db_escape($admin['print_profile']) . ", "
        . db_escape($admin['rep_popup']) . ", " . db_escape($admin['sticky_doc_date']) . ", "
        . db_escape($admin['startup_tab']) . ", " . db_escape($admin['transaction_days']) . ", "
        . db_escape($admin['save_report_selections']) . ", " . db_escape($admin['use_date_picker']) . ", "
        . db_escape($admin['def_print_destination']) . ", " . db_escape($admin['def_print_orientation']) . ", 0)
     ON DUPLICATE KEY UPDATE password = MD5(" . db_escape($pass) . "), inactive = 0, role_id = "
        . db_escape($admin['role_id']),
    'service user upsert'
);

out("\n✓ Service user ready. Match api/config.php fa_service_user / fa_service_pass.");
out("  Test: curl -X POST https://your-host/api/auth/login -H 'Content-Type: application/json' \\");
out("        -d '{\"identifier\":\"{$user}\",\"password\":\"***\"}'\n");
