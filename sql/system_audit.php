<?php
require_once __DIR__.'/../includes/migration_policy.inc';
require_once dirname(__DIR__).'/includes/db/audit_event_report.inc';
// Company-wide row audit. Run explicitly after schema migrations, never during a business transaction.
function audit_identifier($name) { return '`'.str_replace('`','``',$name).'`'; }
function audit_literal($db,$value) { return "'".$db->real_escape_string((string)$value)."'"; }
function audit_excluded_table($name)
{
    return in_array($name,array('system_audit_events','system_audit_coverage','sql_trail'),true);
}
function audit_row_expression($db,$columns,$alias,$keys=false)
{
    if ($keys && !array_filter($columns,function($column){return $column['Key']==='PRI';}))
        return "JSON_OBJECT('row_fingerprint',SHA2(".audit_row_expression($db,$columns,$alias).',256))';
    $parts=array();
    foreach ($columns as $column) {
        $name=$column['Field'];
        if ($keys && $column['Key']!=='PRI') continue;
        $value=$alias.'.'.audit_identifier($name);
        if (preg_match('/^(decimal|numeric)/i',$column['Type'])) $value='CAST('.$value.' AS CHAR)';
        if (preg_match('/password|passwd|secret|token|api_key|apikey|private_key|session|credential/i',$name)
            || preg_match('/blob|binary/i',$column['Type'])) $value="'[REDACTED]'";
        // sys_prefs and extension key/value tables can store credentials in generic value fields.
        if (in_array(strtolower($name),array('value','setting_value','config_value'),true)) {
            foreach ($columns as $keyColumn) if (in_array(strtolower($keyColumn['Field']),array('name','key','setting','config_key'),true)) {
                $value='IF(LOWER('.$alias.'.'.audit_identifier($keyColumn['Field']).") REGEXP 'password|passwd|secret|token|api.?key|private.?key|session|credential', '[REDACTED]', ".$value.')';
                break;
            }
        }
        $parts[]=audit_literal($db,$name); $parts[]=$value;
    }
    return 'JSON_OBJECT('.implode(',',$parts).')';
}
function migrate_system_audit($db,$prefix,$only_table=null)
{
    migration_require_authorized();
    $events=audit_identifier($prefix.'system_audit_events');
    $coverage=audit_identifier($prefix.'system_audit_coverage');
    $db->query("CREATE TABLE IF NOT EXISTS $events (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        occurred_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
        request_id CHAR(32) NOT NULL, actor_id INT NULL, actor_name VARCHAR(120) NOT NULL,
        db_actor VARCHAR(288) NOT NULL, source VARCHAR(255) NOT NULL, ip_address VARCHAR(45) NOT NULL,
        event_type VARCHAR(32) NOT NULL, table_name VARCHAR(128) NOT NULL DEFAULT '',
        record_key LONGTEXT NULL, before_data LONGTEXT NULL, after_data LONGTEXT NULL,
        details LONGTEXT NULL,
        KEY audit_time (occurred_at,id), KEY audit_actor (actor_id,occurred_at),
        KEY audit_table (table_name,occurred_at), KEY audit_request (request_id,id),
        KEY audit_event (event_type,occurred_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $existing=$db->query("SHOW COLUMNS FROM $events")->fetch_all(MYSQLI_ASSOC);
    $names=array_column($existing,'Field');
    foreach (audit_event_dimensions() as $name=>$definition) if (!in_array($name,$names,true))
        $db->query("ALTER TABLE $events ADD ".audit_identifier($name).' '.$definition[0].' AS ('.$definition[1].') PERSISTENT');
    $indexes=array_column($db->query("SHOW INDEX FROM $events")->fetch_all(MYSQLI_ASSOC),'Key_name');
    foreach (array('component','origin','event_action','related_user_id') as $field)
        if (!in_array('audit_'.$field,$indexes,true)) $db->query("ALTER TABLE $events ADD INDEX audit_$field ($field,occurred_at,id)");
    $db->query("CREATE TABLE IF NOT EXISTS $coverage (table_name VARCHAR(128) PRIMARY KEY,
        schema_hash CHAR(64) NOT NULL, installed_at DATETIME NOT NULL, engine VARCHAR(30) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if (function_exists('system_audit_available')) system_audit_available($db,$prefix,true);
    foreach (array('UPDATE','DELETE') as $action) {
        $name=audit_identifier('ja_guard_'.substr(hash('sha256',$prefix.$action),0,32));
        $db->query("CREATE TRIGGER IF NOT EXISTS $name BEFORE $action ON $events FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Audit history is append-only'");
    }
    $tables=$db->query('SHOW FULL TABLES WHERE Table_type = \'BASE TABLE\'')->fetch_all(MYSQLI_NUM);
    $count=0;
    foreach ($tables as $row) {
        $table=$row[0];
        if ($only_table!==null && $table!==$only_table) continue;
        if (strpos($table,$prefix)!==0) continue;
        $short=substr($table,strlen($prefix));
        if (audit_excluded_table($short)) continue;
        $columns=$db->query('SHOW FULL COLUMNS FROM '.audit_identifier($table))->fetch_all(MYSQLI_ASSOC);
        // v3 changes UPDATE triggers to ignore no-op updates. Bumping this
        // version forces existing installations to replace their old triggers.
        $hash=hash('sha256','v3'.json_encode($columns));
        $old=$db->query("SELECT schema_hash FROM $coverage WHERE table_name=".audit_literal($db,$short))->fetch_assoc();
        $installed=$old && $old['schema_hash']===$hash;
        foreach (array('INSERT','UPDATE','DELETE') as $action) {
            $trigger='ja_'.substr(hash('sha256',$table.$action),0,40);
            if ($installed && $db->query('SELECT 1 FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME='.audit_literal($db,$trigger))->num_rows) continue;
            $before=$action==='INSERT'?'NULL':audit_row_expression($db,$columns,'OLD');
            $after=$action==='DELETE'?'NULL':audit_row_expression($db,$columns,'NEW');
            $key=audit_row_expression($db,$columns,$action==='DELETE'?'OLD':'NEW',true);
            $changed=array();
            $changed_checks=array();
            foreach ($columns as $column) {
                $changed[]=audit_literal($db,$column['Field']);
                $field=audit_identifier($column['Field']);
                $changed_checks[]='NOT (OLD.'.$field.' <=> NEW.'.$field.')';
                $changed[]=$changed_checks[count($changed_checks)-1];
            }
            $details=$action==='UPDATE' ? "JSON_OBJECT('changed_fields',JSON_OBJECT(".implode(',',$changed).'))' : 'NULL';
            $insert='INSERT INTO '.$events.' (request_id,actor_id,actor_name,db_actor,source,ip_address,event_type,table_name,record_key,before_data,after_data,details)'
                .' VALUES (COALESCE(@jamii_audit_request,\'\'),@jamii_audit_actor,COALESCE(@jamii_audit_name,\'Database / unidentified\'),'
                .' USER(),COALESCE(@jamii_audit_source,\'External database connection\'),COALESCE(@jamii_audit_ip,\'\'),'
                .audit_literal($db,strtolower($action)).','.audit_literal($db,$short).",$key,$before,$after,$details)";
            $body=$action==='UPDATE' ? 'IF '.implode(' OR ',$changed_checks).' THEN '.$insert.'; END IF;' : $insert;
            $db->query('CREATE OR REPLACE TRIGGER '.audit_identifier($trigger).' AFTER '.$action.' ON '.audit_identifier($table)." FOR EACH ROW ".$body);
        }
        $engine=$db->query('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='.audit_literal($db,$table))->fetch_assoc()['ENGINE'];
        $db->query("INSERT INTO $coverage VALUES (".audit_literal($db,$short).','.audit_literal($db,$hash).',NOW(),'.audit_literal($db,$engine)
            .') ON DUPLICATE KEY UPDATE schema_hash=VALUES(schema_hash),installed_at=NOW(),engine=VALUES(engine)');
        $count++;
    }
    return $count;
}
