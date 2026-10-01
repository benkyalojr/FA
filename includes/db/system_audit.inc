<?php
// No SQL text, request values, cookies or authentication secrets are logged here.
// Record only an allowlisted mode, never the raw query string or submitted values.
function system_audit_sales_source($source, $get, $session)
{
    if (!preg_match('~(^|/)sales/sales_order_entry\.php$~', $source)) return $source;
    $modes=array('NewQuotation'=>'quotation','ModifyQuotationNumber'=>'quotation','AddedQU'=>'quotation','UpdatedQU'=>'quotation',
        'NewOrder'=>'order','ModifyOrderNumber'=>'order','AddedID'=>'order','UpdatedID'=>'order',
        'NewQuoteToSalesOrder'=>'order','NewDelivery'=>'delivery','AddedDN'=>'delivery','NewInvoice'=>'invoice','AddedDI'=>'invoice');
    $selected=array();
    foreach ($modes as $flag=>$mode) if (isset($get[$flag])) $selected[$mode]=true;
    if (count($selected)>1) return $source;
    $mode=$selected ? key($selected) : (array(10=>'invoice',13=>'delivery',30=>'order',32=>'quotation')[$session['Items']->trans_type ?? -1] ?? null);
    return $mode ? $source.'?audit_mode='.$mode : $source;
}
function system_audit_context($db,$prefix)
{
    static $state=array();
    if (!$db instanceof mysqli) return;
    $user=$_SESSION['wa_current_user'] ?? null;
    $actor=($user && !empty($user->logged)) ? (int)$user->user : null;
    $name=$actor ? (string)($user->username ?? $user->loginname ?? '') : (PHP_SAPI==='cli'?'CLI / system':'Unauthenticated');
    $source=PHP_SAPI==='cli' ? 'CLI: '.basename($_SERVER['SCRIPT_FILENAME'] ?? '') : ($_SERVER['SCRIPT_NAME'] ?? '');
    if (PHP_SAPI!=='cli') {
        if (!isset($GLOBALS['system_audit_sales_source']))
            $GLOBALS['system_audit_sales_source']=system_audit_sales_source($source,$_GET,$_SESSION ?? array());
        $source=$GLOBALS['system_audit_sales_source'];
    }
    $ip=PHP_SAPI==='cli'?'':($_SERVER['REMOTE_ADDR'] ?? '');
    if (empty($GLOBALS['system_audit_request'])) $GLOBALS['system_audit_request']=bin2hex(random_bytes(16));
    $values=array($prefix,$actor,$name,$source,$ip,$GLOBALS['system_audit_request']);
    $id=spl_object_id($db).':'.$db->thread_id;
    if (($state[$id] ?? null)===$values) return;
    $quote=function($value)use($db){return $value===null?'NULL':"'".$db->real_escape_string((string)$value)."'";};
    $db->query('SET @jamii_audit_actor='.$quote($actor).',@jamii_audit_name='.$quote(substr($name,0,120))
        .',@jamii_audit_source='.$quote(substr($source,0,255)).',@jamii_audit_ip='.$quote(substr($ip,0,45))
        .',@jamii_audit_request='.$quote($GLOBALS['system_audit_request']));
    $state[$id]=$values;
}
function system_audit_available($db,$prefix,$refresh=false)
{
    static $cache=array();
    $key=spl_object_id($db).':'.$db->thread_id.':'.$prefix;
    if ($refresh || !isset($cache[$key])) {
        $q="SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='"
            .$db->real_escape_string($prefix.'system_audit_events')."'";
        $cache[$key]=(bool)$db->query($q)->num_rows;
    }
    return $cache[$key];
}
function system_audit_refresh_schema($db,$prefix,$sql)
{
    // Refresh audit triggers only inside a deliberate migration deployment.
    require_once __DIR__.'/../migration_policy.inc';
    if (!MigrationExecution::allowed()) return;
    if (!preg_match('/^\s*(?:CREATE|ALTER)\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([a-zA-Z0-9_]+)`?/i',$sql,$match)) return;
    if (strpos($match[1],$prefix)!==0 || !system_audit_available($db,$prefix)) return;
    require_once dirname(__DIR__,2).'/sql/system_audit.php';
    if (audit_excluded_table(substr($match[1],strlen($prefix)))) return;
    migrate_system_audit($db,$prefix,$match[1]);
}
function system_audit_event($event,$details=array())
{
    global $db,$db_connections;
    if (!$db instanceof mysqli) return;
    $company=$_SESSION['wa_current_user']->cur_con ?? 0;
    $prefix=isset($_SESSION['wa_current_user']->cur_con)
        ? ($db_connections[$company]['tbpref'] ?? '') : (defined('TB_PREF')?TB_PREF:($db_connections[$company]['tbpref'] ?? ''));
    if (!system_audit_available($db,$prefix)) return;
    system_audit_context($db,$prefix);
    $quote=function($v)use($db){return "'".$db->real_escape_string((string)$v)."'";};
    $db->query('INSERT INTO `'.str_replace('`','``',$prefix.'system_audit_events').'`
        (request_id,actor_id,actor_name,db_actor,source,ip_address,event_type,details)
        VALUES (@jamii_audit_request,@jamii_audit_actor,@jamii_audit_name,USER(),@jamii_audit_source,@jamii_audit_ip,'
        .$quote($event).','.$quote(json_encode($details,JSON_INVALID_UTF8_SUBSTITUTE)).')');
}
function system_audit_request_party_ids($ids)
{
    $source=$_SERVER['SCRIPT_NAME'] ?? '';
    if (preg_match('~(^|/)sales/sales_order_entry\.php$~',$source)) {
        $id=$_POST['customer_id'] ?? $_SESSION['Items']->customer_id ?? null;
        if (is_scalar($id) && ctype_digit((string)$id) && (int)$id>0) $ids['customer_id']=(string)$id;
    }
    if (preg_match('~(^|/)purchasing/po_entry_items\.php$~',$source)) {
        $id=$_POST['supplier_id'] ?? $_SESSION['PO']->supplier_id ?? null;
        if (is_scalar($id) && ctype_digit((string)$id) && (int)$id>0) $ids['supplier_id']=(string)$id;
    }
    foreach (array('sales/inquiry/customer_inquiry.php'=>'customer_id','purchasing/inquiry/supplier_inquiry.php'=>'supplier_id') as $path=>$key)
        if (substr($source,-strlen($path))===$path) {
            $id=$_POST[$key] ?? $_GET[$key] ?? null;
            unset($ids[$key]);
            if (is_scalar($id) && ctype_digit((string)$id) && (int)$id>0) $ids[$key]=(string)$id;
        }
    return $ids;
}
function system_audit_request_start()
{
    global $db,$db_connections;
    static $started=false;
    if ($started || PHP_SAPI==='cli' || !$db instanceof mysqli) return;
    $started=true;
    $company=$_SESSION['wa_current_user']->cur_con ?? 0;
    $config=$db_connections[$company];
    if (!system_audit_available($db,$config['tbpref'])) return;
    $metadata=array('method'=>$_SERVER['REQUEST_METHOD'] ?? 'GET','record_ids'=>array(),'actions'=>array());
    foreach (array('id','trans_no','trans_id','type','type_id','trans_type','supplier_id','customer_id','debtor_no','order_no','order_number','batch_id','run_id') as $key) {
        $value=$_POST[$key] ?? $_GET[$key] ?? null;
        if (is_scalar($value) && preg_match('/^[0-9]+$/D',(string)$value)) $metadata['record_ids'][$key]=(string)$value;
    }
    foreach (array_keys($_POST) as $key)
        if (count($metadata['actions'])<20 && preg_match('/^(save|submit|approve|reject|delete|update|add|process|print|export|cancel|search|post)(?:[_0-9A-Z]|$)/', (string)$key))
            $metadata['actions'][]=substr((string)$key,0,64);
    $metadata['record_ids']=system_audit_request_party_ids($metadata['record_ids']);
    system_audit_event('request',$metadata);
    $context=$db->query('SELECT @jamii_audit_request request_id,@jamii_audit_actor actor_id,@jamii_audit_name actor_name,
        @jamii_audit_source source,@jamii_audit_ip ip_address')->fetch_assoc();
    $start=microtime(true);
    register_shutdown_function(function()use($config,$context,$start,$metadata) {
        // Separate connection: retain failure/access events without committing abandoned work.
        try {
            $connection=new mysqli($config['host'],$config['dbuser'],$config['dbpassword'],$config['dbname'],!empty($config['port'])?(int)$config['port']:3306);
            $connection->set_charset('utf8mb4');
            $error=error_get_last();
            $fatal=$error && in_array($error['type'],array(E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR),true);
            $details=json_encode(array('record_ids'=>system_audit_request_party_ids($metadata['record_ids']),'http_status'=>http_response_code() ?: 200,
                'outcome'=>$fatal?'fatal_error':($GLOBALS['system_audit_outcome'] ?? 'completed'),
                'duration_ms'=>(int)round((microtime(true)-$start)*1000)));
            $q=function($v)use($connection){return $v===null?'NULL':"'".$connection->real_escape_string((string)$v)."'";};
            $values=array_map($q,array_values($context));
            $connection->query('INSERT INTO `'.str_replace('`','``',$config['tbpref'].'system_audit_events').'`
                (request_id,actor_id,actor_name,source,ip_address,db_actor,event_type,details) VALUES ('
                .implode(',',$values).",USER(),'request_end',".$q($details).')');
            $connection->close();
        } catch (Throwable $error) { error_log('System audit request completion could not be recorded.'); }
    });
}
