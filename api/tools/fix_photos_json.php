<?php
/**
 * Fix photos_json / notes / photo_ids corrupted by db_escape HTML entity encoding.
 *   php api/tools/fix_photos_json.php
 */
require dirname(__DIR__) . '/bootstrap.php';

$tbl = Db::t('shifts');
$cols = array('photos_json', 'photo_ids', 'notes');
$fixed = 0;

foreach ($cols as $col) {
    $res = db_query("SELECT id, $col FROM $tbl WHERE $col IS NOT NULL AND $col != ''", 'scan shifts');
    while ($row = db_fetch($res)) {
        $raw = $row[$col];
        if (strpos($raw, '&quot;') === false && strpos($raw, '&amp;') === false) {
            continue;
        }
        $decoded = avogs_json_decode($raw);
        if ($decoded === null) {
            continue;
        }
        db_query("UPDATE $tbl SET $col = " . Db::json($decoded) . " WHERE id = " . (int) $row['id'], 'fix json');
        $fixed++;
        echo "Fixed shift #{$row['id']}.$col\n";
    }
}

echo "Done. $fixed column(s) repaired.\n";
