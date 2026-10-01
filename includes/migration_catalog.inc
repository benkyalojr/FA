<?php
// Read-only discovery: never include migration files while rendering a screen.
function deployment_migration_jobs()
{
    $jobs = array();
    $files = glob(dirname(__DIR__).'/sql/*.php');
    natsort($files);
    foreach ($files as $file) {
        $name = pathinfo($file, PATHINFO_FILENAME);
        // Requires its dedicated maintenance-window command and length argument.
        if ($name === 'location_code_widen') continue;
        $function = 'migrate_'.$name;
        if (preg_match('/function\s+'.preg_quote($function, '/').'\s*\(/', file_get_contents($file)))
            $jobs[$name] = array('file'=>$file, 'function'=>$function);
    }
    // Build shared tables before dependent modules. Individual migrations also
    // load their own dependencies. Audit triggers and data backfills run last.
    $first = array('run_migrations_log', 'bg_tasks');
    $ordered = array();
    foreach ($first as $name) if (isset($jobs[$name])) {
        $ordered[$name] = $jobs[$name];
        unset($jobs[$name]);
    }
    $audit = $jobs['system_audit'];
    unset($jobs['system_audit']);
    $ordered += $jobs;
    $ordered['system_audit'] = $audit;
    return $ordered;
}

function deployment_migration_log()
{
    $log = array();
    // Missing ledger is a valid pre-deployment state, never create it here.
    // In a web request TB_PREF is db_query()'s placeholder, which db_escape()
    // deliberately leaves untouched inside string values, so metadata lookups
    // need the active connection's real prefix. The CLI runner's TB_PREF is real.
    global $db_connections;
    $company = $_SESSION['wa_current_user']->cur_con ?? null;
    $prefix = ($company !== null && isset($db_connections[$company]['tbpref'])) ? $db_connections[$company]['tbpref'] : TB_PREF;
    $exists = db_query('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='
        .db_escape($prefix.'migration_log'));
    if (!db_num_rows($exists)) return $log;
    $result = db_query('SELECT name,status,message,seconds,ran_at FROM '.TB_PREF.'migration_log');
    while ($row = db_fetch($result)) $log[$row['name']] = $row;
    return $log;
}
