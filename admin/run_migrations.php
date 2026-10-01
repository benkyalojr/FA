<?php
// Opening this page only reads history. Migration execution requires the
// explicit, permission-checked and CSRF-checked POST scope below.
$page_security = 'SA_RUNMIGRATIONS';
$path_to_root = "..";
include_once($path_to_root . "/includes/session.inc");
include_once($path_to_root . "/includes/ui.inc");
require_once($path_to_root . "/sql/run_migrations_log.php");
require_once($path_to_root . '/includes/migration_catalog.inc');

page(_($help_context = "Run Database Migrations"));

function run_migrations_jobs() { return deployment_migration_jobs(); }
function run_migrations_log_all() { return deployment_migration_log(); }

function run_migrations_log_record($name, $ok, $message, $seconds)
{
    db_query('INSERT INTO '.TB_PREF.'migration_log (name,status,message,seconds,ran_at) VALUES ('
        .db_escape($name).','.db_escape($ok ? 'ok' : 'failed').','.db_escape((string)$message).','.db_escape(round($seconds, 3)).',NOW())'
        .' ON DUPLICATE KEY UPDATE status=VALUES(status), message=VALUES(message), seconds=VALUES(seconds), ran_at=VALUES(ran_at)');
}

$jobs = run_migrations_jobs();
$results = null;
if (isset($_POST['run_migrations']) || isset($_POST['force_run_migrations'])) {
    try {
        MigrationExecution::runAdmin(function () use ($jobs, &$results) {
            global $db_connections;
            migrate_run_migrations_log();
            global $db;
            $force = isset($_POST['force_run_migrations']);
            $log = run_migrations_log_all();
            $results = array();
            foreach ($jobs as $name=>$job) {
                if (!$force && ($log[$name]['status'] ?? null) === 'ok') {
                    $results[] = array('name'=>$name, 'status'=>'skipped',
                        'message'=>sprintf(_('Already applied %s'), $log[$name]['ran_at']), 'seconds'=>0.0);
                    continue;
                }
                $started = microtime(true);
                try {
                    require_once($job['file']);
                    if (!function_exists($job['function'])) throw new RuntimeException('Function not found after include.');
                    // A handful of migrate_*() take an explicit ($db, prefix) pair
                    // (e.g. migrate_system_audit()) instead of using TB_PREF alone;
                    // passing both here covers either signature, since PHP ignores
                    // extra arguments a function doesn't declare. TB_PREF itself is
                    // never the real prefix here - it's db_query()'s runtime
                    // placeholder literal ('&TB_PREF&', includes/current_user.inc),
                    // swapped for the real one inside db_query() on every call. A
                    // function like migrate_system_audit() that builds table names
                    // from this second argument and runs them via raw $db->query()
                    // (bypassing that substitution) needs the real prefix resolved
                    // the same way admin/system_audit.php itself does, or it
                    // silently creates a table literally named "&TB_PREF&events".
                    $real_prefix = $db_connections[$_SESSION['wa_current_user']->cur_con ?? 0]['tbpref'];
                    call_user_func($job['function'], $db, $real_prefix);
                    $seconds = microtime(true)-$started;
                    run_migrations_log_record($name, true, 'OK', $seconds);
                    $results[] = array('name'=>$name, 'status'=>'ok', 'message'=>_('OK'), 'seconds'=>$seconds);
                } catch (Throwable $e) {
                    $seconds = microtime(true)-$started;
                    run_migrations_log_record($name, false, $e->getMessage(), $seconds);
                    $results[] = array('name'=>$name, 'status'=>'failed', 'message'=>$e->getMessage(), 'seconds'=>$seconds);
                }
            }
            global $SysPrefs;
            $SysPrefs->refresh(); // newly seeded sys_prefs should be visible immediately
        });
    } catch (Throwable $e) {
        display_error(html_specials_encode($e->getMessage()));
    }
}

$log = run_migrations_log_all();
$applied = 0;
foreach ($jobs as $name=>$job) if (($log[$name]['status'] ?? null) === 'ok') $applied++;
$pending = count($jobs) - $applied;

start_form();
display_note(_('Migrations run only when you submit an action below. Opening screens never applies them. Back up the company database before deployment. Some migrations alter existing data or rebuild tables; a failed migration can leave partial changes.'));
display_note(sprintf(_('%d of %d migrations previously recorded as successful. %d unrecorded or failed. History does not verify the current schema.'), $applied, count($jobs), $pending));
submit_center_first('run_migrations', _('Run Migrations'),
    _('Runs anything not yet applied (new files, and anything that failed last time).'), false);
submit_center_last('force_run_migrations', _('Force Re-run All'),
    _('Ignores the ledger and re-executes every migration, including ones already applied.'), false);
end_form();

if ($results !== null) {
    $failed = 0;
    foreach ($results as $r) if ($r['status'] === 'failed') $failed++;
    if ($failed)
        display_error(sprintf(_('%d of %d migrations failed. See details below.'), $failed, count($results)));
    else
        display_notification(sprintf(_('%d migrations processed successfully (see status below for what actually ran).'), count($results)));

    $labels = array('ok'=>_('OK'), 'failed'=>_('Failed'), 'skipped'=>_('Skipped'));
    start_table(TABLESTYLE);
    table_header(array(_('Migration'), _('Status'), _('Details'), _('Time')));
    foreach ($results as $r) {
        start_row();
        label_cell(html_specials_encode($r['name']));
        label_cell($labels[$r['status']]);
        label_cell(html_specials_encode($r['message']));
        label_cell($r['seconds'] > 0 ? number_format($r['seconds'], 3).'s' : '');
        end_row();
    }
    end_table(1);
}

end_page();
