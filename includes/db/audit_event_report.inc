<?php
require_once __DIR__.'/audit_parties.inc';
// Moodle-style event dimensions derived from immutable evidence, not from guessed business outcomes.
function audit_event_dimensions()
{
    $component="CASE
        WHEN source REGEXP '(^|/)farmers/' THEN 'Farmers'
        WHEN source REGEXP '(^|/)sales/' THEN 'Sales'
        WHEN source REGEXP '(^|/)purchasing/' THEN 'Purchases'
        WHEN source REGEXP '(^|/)inventory/' THEN 'Inventory'
        WHEN source REGEXP '(^|/)manufacturing/' THEN 'Manufacturing'
        WHEN source REGEXP '(^|/)fixed_assets/' THEN 'Fixed Assets'
        WHEN source REGEXP '(^|/)dimensions/' THEN 'Dimensions'
        WHEN source REGEXP '(^|/)gl/' THEN 'General Ledger'
        WHEN source REGEXP '(^|/)reporting/' THEN 'Reports'
        WHEN source REGEXP '(^|/)(admin|access|taxes)/' THEN 'Administration'
        WHEN table_name REGEXP '^(farmer|milk|transporter|bulk_milk|supplier_temp_milk)' THEN 'Farmers'
        WHEN table_name REGEXP '^(purch|supp_|suppliers|grn)' THEN 'Purchases'
        WHEN table_name REGEXP '^(sales|debtor|cust_)' THEN 'Sales'
        WHEN table_name REGEXP '^(stock|item_|locations)' THEN 'Inventory'
        WHEN table_name REGEXP '^(gl_|bank_|journal|chart_|fiscal)' THEN 'General Ledger'
        WHEN table_name REGEXP '^(wo_|workorders|bom)' THEN 'Manufacturing'
        ELSE 'System' END";
    $action="CASE
        WHEN event_type='insert' AND table_name LIKE '%history' AND JSON_VALID(after_data)
            AND JSON_UNQUOTE(JSON_EXTRACT(after_data,'$.action')) IS NOT NULL
            THEN LEFT(LOWER(JSON_UNQUOTE(JSON_EXTRACT(after_data,'$.action'))),40)
        WHEN event_type='update' AND JSON_VALID(after_data) AND JSON_VALID(before_data)
            AND NOT (JSON_EXTRACT(after_data,'$.status') <=> JSON_EXTRACT(before_data,'$.status'))
            AND JSON_UNQUOTE(JSON_EXTRACT(after_data,'$.status')) IN ('approved','rejected','cancelled','posted','paid','voided')
            THEN JSON_UNQUOTE(JSON_EXTRACT(after_data,'$.status'))
        WHEN event_type='insert' THEN 'created' WHEN event_type='update' THEN 'updated' WHEN event_type='delete' THEN 'deleted'
        WHEN event_type='request' THEN 'requested'
        WHEN event_type='request_end' THEN CASE WHEN JSON_UNQUOTE(JSON_EXTRACT(details,'$.outcome'))='completed'
            AND CAST(JSON_UNQUOTE(JSON_EXTRACT(details,'$.http_status')) AS UNSIGNED)<400 THEN 'completed' ELSE 'failed' END
        ELSE event_type END";
    return array(
        'component'=>array('VARCHAR(32)',$component),
        'origin'=>array('VARCHAR(16)',"CASE WHEN source LIKE 'CLI:%' THEN 'cli' WHEN source='External database connection' THEN 'database' ELSE 'web' END"),
        'event_action'=>array('VARCHAR(40)',$action),
        'crud'=>array('CHAR(1)',"CASE event_type WHEN 'insert' THEN 'c' WHEN 'update' THEN 'u' WHEN 'delete' THEN 'd' WHEN 'request' THEN 'r' ELSE 'x' END"),
        'related_user_id'=>array('INT',"CASE WHEN table_name='users' AND JSON_VALID(record_key)
            THEN CAST(JSON_UNQUOTE(JSON_EXTRACT(record_key,'$.id')) AS UNSIGNED) ELSE NULL END")
    );
}
function audit_event_title($value) { return ucwords(str_replace(array('_','-'), ' ', (string)$value)); }
function audit_event_present($row)
{
    $after=json_decode($row['after_data'] ?? '',true) ?: array();
    $before=json_decode($row['before_data'] ?? '',true) ?: array();
    $key=json_decode($row['record_key'] ?? '',true) ?: array();
    $details=json_decode($row['details'] ?? '',true) ?: array();
    $data=$after ?: $before;
    $target=$row['table_name'] ? audit_event_title($row['table_name']) : 'System';
    $labels=array('purch_orders'=>'Purchase order','grn_batch'=>'Goods receipt','farmer_collections'=>'Milk collection',
        'farmer_payroll_runs'=>'Farmer payroll','farmer_milk_reversal_requests'=>'Milk reversal request',
        'farmer_milk_transfers'=>'Milk location transfer','farmer_membership_requests'=>'Farmer registration / activation','farmer_deduction_imports'=>'Farmer deduction import','farmer_deduction_entries'=>'Farmer deduction','farmer_milk_reversals'=>'Milk reversal','farmer_advance_payments'=>'Farmer advance payment','farmer_advance_recoveries'=>'Farmer advance recovery','farmer_share_transactions'=>'Farmer share transaction','farmer_share_ledger'=>'Farmer share movement','farmer_milk_qa'=>'Milk QA','farmer_route_qa'=>'Route QA','farmer_qa_violations'=>'Milk QA violation','farmer_qa_holds'=>'Farmer QA hold','debtors_master'=>'Customer','users'=>'User account',
        'security_roles'=>'Access role','sys_prefs'=>'System setting','stock_master'=>'Stock item');
    $target=$labels[$row['table_name']] ?? $target;
    if ($row['table_name']==='suppliers') $target=strpos($row['source'],'/farmers/manage/farmers.php')!==false?'Farmer':
        (strpos($row['source'],'/farmers/manage/transporters.php')!==false?'Transporter':'Supplier');
    if (in_array($row['table_name'],array('supp_trans','debtor_trans','audit_trail'),true) && isset($data['type'],$data['trans_no'])) {
        $types=array(0=>'Journal',1=>'Bank payment',2=>'Bank deposit',4=>'Bank transfer',10=>'Customer invoice',11=>'Customer credit note',
            12=>'Customer receipt',13=>'Customer delivery',16=>'Stock transfer',17=>'Stock adjustment',18=>'Purchase order',
            20=>'Supplier invoice',21=>'Supplier credit note',22=>'Supplier payment',25=>'Goods receipt',26=>'Work order');
        $target=$types[(int)$data['type']] ?? 'Transaction';
        $key=array('type'=>$data['type'],'number'=>$data['trans_no']);
    }
    $context=$row['source'];
    $source_path=parse_url($row['source'],PHP_URL_PATH);
    $matches=array();
    if (function_exists('menu_rights_catalog')) foreach (menu_rights_catalog() as $entry) {
        $path=parse_url($entry[4],PHP_URL_PATH);
        if ($path && ($source_path===$path || substr($source_path,-strlen('/'.$path))==='/'.$path)) $matches[]=$entry[3];
    }
    if (count($matches)===1) $context=$matches[0];
    if (preg_match('~(^|/)sales/sales_order_entry\.php$~',(string)$source_path)) {
        parse_str(parse_url($row['source'],PHP_URL_QUERY) ?? '',$mode);
        $labels=array('quotation'=>'Sales Quotation Entry','order'=>'Sales Order Entry','delivery'=>'Direct Delivery','invoice'=>'Direct Invoice');
        $context=is_string($mode['audit_mode'] ?? null) ? ($labels[$mode['audit_mode']] ?? 'Sales Transaction Entry') : 'Sales Transaction Entry';
    }
    if (substr($row['table_name'],-7)==='history') {
        $target=audit_event_title($data['entity'] ?? str_replace(array('_transaction_history','_history'),'',$row['table_name']));
        foreach (array('entity_id','document_id','workflow_id','order_no','trans_no') as $field)
            if (isset($data[$field])) { $key=array($field=>$data[$field]); break; }
    }
    $identity=array();
    foreach ($key as $field=>$value) if (is_scalar($value)) $identity[]=$field.'='.$value;
    $record=implode(', ',$identity);
    $action=$row['event_action'] ?? $row['event_type'];
    $event=$target.' '.str_replace('_',' ',$action);
    $description=($row['actor_name'] ?: 'Unidentified actor').' '.str_replace('_',' ',$action).' '.$target.($record!==''?' ('.$record.')':'').'.';
    if (in_array($row['event_type'],array('request','request_end'),true)) {
        $event=$row['event_type']==='request'?'Screen request':'Request '.($action==='failed'?'failed':'completed');
        $description=($row['actor_name'] ?: 'Unidentified actor').': '.$event.' for '.$context.'.';
        if (!empty($details['record_ids'])) $description.=' Records: '.json_encode($details['record_ids'],JSON_UNESCAPED_UNICODE).'.';
        if (!empty($details['actions'])) $description.=' Requested actions: '.implode(', ',$details['actions']).'.';
        if (!empty($details['outcome'])) $description.=' Outcome: '.$details['outcome'].'.';
    }
    if (isset($data['reference']) && is_scalar($data['reference'])) $description.=' Reference: '.$data['reference'].'.';
    return array('event_name'=>$event,'description'=>$description,'context'=>$context,'record'=>$record,
        'affected_user'=>audit_party_label($row));
}
function audit_csv_cell($value)
{
    $value=(string)$value;
    return preg_match('/^[\s]*[=+@-]/u',$value)?"'".$value:$value;
}
function audit_event_conditions($filter,$from,$to)
{
    $where=array();
    if (!empty($filter['request_id'])) $where[]='e.request_id='.db_escape($filter['request_id']);
    else {
        $where[]='e.occurred_at>='.db_escape($from.' 00:00:00');
        $where[]='e.occurred_at<DATE_ADD('.db_escape($to).',INTERVAL 1 DAY)';
    }
    foreach (array('actor_id','related_user_id') as $field)
        if (isset($filter[$field]) && $filter[$field]!=='') $where[]='e.'.$field.'='.(int)$filter[$field];
    foreach (array('audit_table'=>'table_name','event_type'=>'event_type','component'=>'component','origin'=>'origin','event_action'=>'event_action','crud'=>'crud','ip_address'=>'ip_address') as $input=>$field)
        if (!empty($filter[$input]) && is_scalar($filter[$input])) $where[]='e.'.$field.'='.db_escape($filter[$input]);
    if (isset($filter['party_id']) && $filter['party_id']!=='') $where[]=audit_party_filter_sql($filter['party_type'] ?? '',$filter['party_id']);
    if (!empty($filter['record_key'])) $where[]='e.record_key LIKE '.db_escape('%'.$filter['record_key'].'%');
    return implode(' AND ',$where);
}
function audit_event_select()
{
    return 'SELECT e.*,u.user_id related_name FROM '.TB_PREF.'system_audit_events e LEFT JOIN '.TB_PREF.'users u ON u.id=e.related_user_id';
}
