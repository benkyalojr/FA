<?php
/**
 * Add check-in detail columns + stock lines table on existing installs.
 *   php api/tools/migrate_checkin_schema.php
 */
require dirname(__DIR__) . '/bootstrap.php';

function out($m) { fwrite(STDOUT, $m . "\n"); }

$P = TB_PREF;

$schema = file_get_contents(dirname(__DIR__) . '/sql/avogs_schema.sql');
if (preg_match('/CREATE TABLE IF NOT EXISTS `0_avogs_shift_stock_lines`[\s\S]*?ENGINE=InnoDB/s', $schema, $m)) {
    db_query($m[0], 'create shift stock lines');
    out('Ensured 0_avogs_shift_stock_lines');
}

$cols = array(
    'photos_json' => "ALTER TABLE {$P}avogs_shifts ADD COLUMN photos_json text NULL AFTER photo_ids",
    'calls_deliveries' => "ALTER TABLE {$P}avogs_shifts ADD COLUMN calls_deliveries text NULL AFTER photos_json",
    'pending_orders' => "ALTER TABLE {$P}avogs_shifts ADD COLUMN pending_orders text NULL AFTER calls_deliveries",
);

foreach ($cols as $name => $sql) {
    $exists = db_fetch(db_query(
        "SHOW COLUMNS FROM {$P}avogs_shifts LIKE " . db_escape($name)
    ));
    if ($exists) {
        out("Column $name already exists");
        continue;
    }
    db_query($sql, 'add column ' . $name);
    out("Added column $name");
}

out('Done.');
