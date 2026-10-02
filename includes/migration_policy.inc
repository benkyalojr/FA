<?php
// Migrations are deployment work: CLI or a deliberate, authorized admin POST.
final class MigrationExecution
{
    private static $adminAction = false;

    public static function allowed()
    {
        return PHP_SAPI === 'cli' || self::$adminAction;
    }

    public static function runAdmin($callback)
    {
        $routes = array(
            realpath(__DIR__.'/../admin/run_migrations.php')=>'SA_RUNMIGRATIONS',
            realpath(__DIR__.'/../mpesa/manage/mpesa_config.php')=>'SA_MPESASETUP',
            realpath(__DIR__.'/../admin/inst_upgrade.php')=>'SA_SOFTWAREUPGRADE'
        );
        $route = realpath($_SERVER['SCRIPT_FILENAME'] ?? '');
        $permission = $routes[$route] ?? null;
        $user = $_SESSION['wa_current_user'] ?? null;
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !$permission || !$user
            || !$user->can_access_page($permission)
            || (function_exists('menu_rights_request_allowed') && !menu_rights_request_allowed($permission))
            || !function_exists('check_csrf_token') || !check_csrf_token())
            throw new RuntimeException('Migrations require an authorized admin action with a valid form token.');
        $previous = self::$adminAction;
        self::$adminAction = true;
        try { return $callback(); }
        finally { self::$adminAction = $previous; }
    }
}

function migration_require_authorized()
{
    if (!MigrationExecution::allowed())
        throw new RuntimeException('Automatic database migrations are disabled. Use deployment CLI or an explicit admin migration action.');
}
