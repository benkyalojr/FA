<?php
/**
 * Thin helpers over FrontAccounting's DB layer (available after bootstrap).
 *
 * Uses FA's db_query/db_fetch/db_escape so the API shares FA's connection and
 * benefits from its escaping. AVOGS_PREF prefixes the app's own tables.
 */
class Db
{
    /** App table name with the FA prefix, e.g. Db::t('shifts') => "0_avogs_shifts". */
    public static function t($name)
    {
        return AVOGS_PREF . $name;
    }

    /** Escape + quote a value for SQL (FA's db_escape). */
    public static function esc($v, $nullable = false)
    {
        return db_escape($v, $nullable);
    }

    /** Run a query through FA, logging failures (and the SQL) when they happen. */
    private static function run($sql, $err)
    {
        $res = db_query($sql, $err);
        if ($res === false && class_exists('Logger')) {
            $dberr = function_exists('db_error_msg') ? db_error_msg(self::handle()) : null;
            Logger::error('SQL failed: ' . $err, array('sql' => $sql, 'db_error' => $dberr));
        }
        return $res;
    }

    /** Best-effort access to FA's active db connection handle for error reporting. */
    private static function handle()
    {
        global $db;
        return isset($db) ? $db : null;
    }

    /** Run a query, returning FA's result handle. */
    public static function query($sql, $err = 'API query failed')
    {
        return self::run($sql, $err);
    }

    /** All rows as associative arrays. */
    public static function rows($sql)
    {
        $res = self::run($sql, 'API query failed');
        $out = array();
        while ($res && $row = db_fetch_assoc($res)) {
            $out[] = $row;
        }
        return $out;
    }

    /** First row as an associative array, or null. */
    public static function row($sql)
    {
        $res = self::run($sql, 'API query failed');
        $row = $res ? db_fetch_assoc($res) : null;
        return $row ? $row : null;
    }

    /** First column of the first row, or null. */
    public static function val($sql)
    {
        $res = self::run($sql, 'API query failed');
        $row = $res ? db_fetch($res) : null;
        return $row ? $row[0] : null;
    }

    /** Run an INSERT/UPDATE/DELETE; returns last insert id for inserts. */
    public static function exec($sql)
    {
        self::run($sql, 'API write failed');
        return db_insert_id();
    }
}
