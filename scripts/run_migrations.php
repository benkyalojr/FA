<?php
// Explicit deployment only. --list and --help never connect to a database.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__).'/includes/migration_catalog.inc';
$usage = "Usage: php scripts/run_migrations.php COMPANY_ID --list|--status|--apply [--only=NAME]\n"
    ."Apply runs the current migration code, including previously recorded jobs. Back up first.\n"
    ."location_code_widen uses its dedicated scripts/migrate_location_code_widen.php command.\n";
$args = $argv;
array_shift($args);
if (!$args || $args === array('--help')) { echo $usage; exit(0); }
$company = array_shift($args);
$mode = array_shift($args);
if (!ctype_digit((string)$company) || !in_array($mode, array('--list', '--status', '--apply'), true)
    || count($args)>1 || ($args && strpos($args[0], '--only=') !== 0)) {
    fwrite(STDERR, $usage); exit(2);
}
$jobs = deployment_migration_jobs();
if ($args) {
    $name = substr($args[0], 7);
    if (!isset($jobs[$name])) { fwrite(STDERR, "Unknown migration: $name\n"); exit(2); }
    $jobs = array($name=>$jobs[$name]);
}
if ($mode === '--list') { echo implode("\n", array_keys($jobs))."\n"; exit(0); }
$supplier_company_id = (int)$company;
try {
    require __DIR__.'/supplier_types_cli.inc';
    if ($mode === '--status') {
        $log = deployment_migration_log();
        foreach ($jobs as $name=>$job)
            echo $name."\t".($log[$name]['status'] ?? 'not recorded')."\t".($log[$name]['ran_at'] ?? '')."\n";
        exit(0);
    }
    // One deployment process per company; release automatically on disconnect.
    $lock = 'snaperp-migrations-'.substr(hash('sha256', $c['dbname'].':'.TB_PREF), 0, 40);
    $locked = db_fetch(db_query('SELECT GET_LOCK('.db_escape($lock).',0) acquired'));
    if (!$locked || (int)$locked['acquired'] !== 1) throw new RuntimeException('Another migration deployment is running.');
    try {
        require_once $path_to_root.'/sql/run_migrations_log.php';
        migrate_run_migrations_log();
        foreach ($jobs as $name=>$job) {
            $started = microtime(true);
            $failure = null;
            try {
                require_once $job['file'];
                call_user_func($job['function'], $db, TB_PREF);
            } catch (Throwable $e) { $failure = $e; }
            $status = $failure ? 'failed' : 'ok';
            $message = $failure ? $failure->getMessage() : 'OK';
            db_query('INSERT INTO '.TB_PREF.'migration_log (name,status,message,seconds,ran_at) VALUES ('
                .db_escape($name).','.db_escape($status).','.db_escape($message).','
                .db_escape(round(microtime(true)-$started, 3)).',NOW())'
                .' ON DUPLICATE KEY UPDATE status=VALUES(status),message=VALUES(message),seconds=VALUES(seconds),ran_at=VALUES(ran_at)');
            echo $name.": ".$status."\n";
            // Do not continue into dependent jobs after a failure. DDL can commit
            // implicitly; inspect the recorded error before retrying deployment.
            if ($failure) throw $failure;
        }
    } finally {
        db_query('SELECT RELEASE_LOCK('.db_escape($lock).')');
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Migration command failed: '.$e->getMessage()."\n");
    exit(1);
}
