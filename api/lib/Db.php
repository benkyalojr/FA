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

    /** Run a query, returning FA's result handle. */
    public static function query($sql, $err = 'API query failed')
    {
        return db_query($sql, $err);
    }

    /** All rows as associative arrays. */
    public static function rows($sql)
    {
        $res = db_query($sql, 'API query failed');
        $out = array();
        while ($row = db_fetch_assoc($res)) {
            $out[] = $row;
        }
        return $out;
    }

    /** First row as an associative array, or null. */
    public static function row($sql)
    {
        $res = db_query($sql, 'API query failed');
        $row = db_fetch_assoc($res);
        return $row ? $row : null;
    }

    /** First column of the first row, or null. */
    public static function val($sql)
    {
        $res = db_query($sql, 'API query failed');
        $row = db_fetch($res);
        return $row ? $row[0] : null;
    }

    /** Run an INSERT/UPDATE/DELETE; returns last insert id for inserts. */
    public static function exec($sql)
    {
        db_query($sql, 'API write failed');
        return db_insert_id();
    }
}
