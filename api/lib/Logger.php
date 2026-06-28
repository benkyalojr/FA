<?php
/**
 * Minimal file logger for the AVO'Gs API.
 *
 * Deliberately free of any FrontAccounting dependency so it can be initialised
 * at the very top of bootstrap.php and still capture failures that happen while
 * FA itself is starting up (e.g. the service-account login failing, which makes
 * FA render its HTML login page and exit before the API ever runs).
 *
 * Each request gets a short correlation id so related lines can be grepped
 * together. Lines look like:
 *   [2026-06-28 15:04:45] [a1b2c3d4] ERROR: message {"context":"..."}
 */
class Logger
{
    private static $file = null;
    private static $threshold = 10;
    private static $rid = null;

    private static $levels = array(
        'debug'   => 10,
        'info'    => 20,
        'warning' => 30,
        'error'   => 40,
    );

    /** Configure the destination file and minimum level to record. */
    public static function init($file, $level = 'debug')
    {
        self::$file = $file;
        $lvl = strtolower((string) $level);
        self::$threshold = isset(self::$levels[$lvl]) ? self::$levels[$lvl] : 10;

        $dir = dirname($file);
        if ($dir && !is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        // The logger may run before FA sets its timezone; avoid date() warnings.
        if (!ini_get('date.timezone') && function_exists('date_default_timezone_set')) {
            @date_default_timezone_set('Africa/Nairobi');
        }
    }

    public static function enabled()
    {
        return self::$file !== null;
    }

    /** Stable per-request id used to correlate log lines. */
    public static function requestId()
    {
        if (self::$rid === null) {
            self::$rid = substr(bin2hex(openssl_random_pseudo_bytes(4)), 0, 8);
        }
        return self::$rid;
    }

    public static function log($level, $message, $context = array())
    {
        if (self::$file === null) {
            return;
        }
        $lvl = strtolower((string) $level);
        $weight = isset(self::$levels[$lvl]) ? self::$levels[$lvl] : 10;
        if ($weight < self::$threshold) {
            return;
        }

        $line = '[' . date('Y-m-d H:i:s') . '] [' . self::requestId() . '] '
            . strtoupper($lvl) . ': ' . $message;
        if (!empty($context)) {
            $json = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($json !== false) {
                $line .= ' ' . $json;
            }
        }

        @file_put_contents(self::$file, $line . "\n", FILE_APPEND | LOCK_EX);
    }

    public static function debug($m, $c = array())   { self::log('debug', $m, $c); }
    public static function info($m, $c = array())     { self::log('info', $m, $c); }
    public static function warning($m, $c = array())  { self::log('warning', $m, $c); }
    public static function error($m, $c = array())    { self::log('error', $m, $c); }
}
