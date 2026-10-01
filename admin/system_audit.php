<?php
$page_security='SA_SYSTEMAUDIT';
$path_to_root='..';
include_once $path_to_root.'/includes/session.inc';
include_once $path_to_root.'/includes/ui.inc';
include_once $path_to_root.'/includes/db/audit_event_report.inc';
ensure_select2_assets();
add_js_file('system_audit.js');
$js=user_use_date_picker()?get_js_date_picker():'';
page(_('System Audit Trail'),false,false,'',$js);
function audit_h($value) { return htmlspecialchars((string)$value,ENT_QUOTES | ENT_SUBSTITUTE,'UTF-8'); }
if (!system_audit_available($db,$db_connections[$_SESSION['wa_current_user']->cur_con ?? 0]['tbpref'])) {
    display_error(_('System audit has not been installed for this company.'));
    end_page(); exit;
}
$clock=db_fetch(db_query('SELECT CURRENT_DATE() audit_day,TIMESTAMPDIFF(MINUTE,UTC_TIMESTAMP(),NOW()) offset_minutes'));
$offset=(int)$clock['offset_minutes'];
$time_label=sprintf('Time (UTC%s%02d:%02d)',$offset<0?'-':'+',intdiv(abs($offset),60),abs($offset)%60);
if (!empty($_GET['id'])) {
    $row=db_fetch(db_query(audit_event_select().' WHERE e.id='.(int)$_GET['id']));
    if (!$row) { display_error(_('Audit entry not found.')); end_page(); exit; }
    $row=audit_party_enrich(array($row))[0];
    display_heading(_('Audit Entry').' #'.(int)$row['id']);
    $presentation=audit_event_present($row);
    display_note(audit_h($presentation['description']));
    start_table(TABLESTYLE2);
    foreach (array('event_name'=>'Event name','context'=>'Context','affected_user'=>'Affected party') as $key=>$label)
        label_row(_($label),audit_h($presentation[$key]));
    foreach (array('component'=>'Module','event_action'=>'Action','origin'=>'Origin','crud'=>'CRUD') as $key=>$label)
        label_row(_($label),audit_h($row[$key]));
    foreach (array('occurred_at'=>$time_label,'actor_name'=>'Recorded by','actor_id'=>'User ID','event_type'=>'Operation',
        'table_name'=>'Table','record_key'=>'Record','source'=>'Screen / source','ip_address'=>'IP address',
        'db_actor'=>'Database account','request_id'=>'Request ID') as $key=>$label)
        label_row(_($label),audit_h($row[$key]));
    end_table(1);
    $before=json_decode($row['before_data'] ?? '',true) ?: array();
    $after=json_decode($row['after_data'] ?? '',true) ?: array();
    $changes=json_decode($row['details'] ?? '',true)['changed_fields'] ?? null;
	// Milk collection requests can carry a durable device snapshot. Resolve it
	// directly for collection/device audit rows, or through another collection
	// event recorded in the same request so PO/GRN/invoice audit entries can
	// still show the originating mobile device.
	$device=null;$device_snapshot=null;$collection_id=0;
	$device_table=db_num_rows(db_query("SHOW TABLES LIKE '".TB_PREF."farmer_devices'"));
	$device_column=$device_table && db_num_rows(db_query("SHOW TABLES LIKE '".TB_PREF."farmer_collections'"))
		&& db_num_rows(db_query('SHOW COLUMNS FROM '.TB_PREF."farmer_collections LIKE 'device_id'"));
	if ($device_table && $device_column) {
		if ($row['table_name']==='farmer_collections') $collection_id=(int)($after['id']??$before['id']??0);
		if (!$collection_id && $row['request_id']) {
			$related=db_fetch(db_query('SELECT after_data,before_data FROM '.TB_PREF.'system_audit_events WHERE request_id='.
				db_escape($row['request_id'])." AND table_name='farmer_collections' ORDER BY id DESC LIMIT 1"));
			if ($related) {
				$related_after=json_decode($related['after_data']??'',true)?:array();
				$related_before=json_decode($related['before_data']??'',true)?:array();
				$collection_id=(int)($related_after['id']??$related_before['id']??0);
			}
		}
		if (!$collection_id && $row['request_id']) {
			$api_event=db_fetch(db_query('SELECT after_data,before_data FROM '.TB_PREF.'system_audit_events WHERE request_id='.
				db_escape($row['request_id'])." AND table_name='api_request_log' ORDER BY id DESC LIMIT 1"));
			$api_after=$api_event?json_decode($api_event['after_data']??'',true):array();
			$api_before=$api_event?json_decode($api_event['before_data']??'',true):array();
			$api_id=(int)($api_after['id']??$api_before['id']??0);
			if ($api_id) {
				$api_record=db_fetch(db_query('SELECT request_payload FROM '.TB_PREF.'api_request_log WHERE id='.$api_id));
				$api_payload=$api_record?json_decode($api_record['request_payload'],true):null;
				$identifier=is_array($api_payload)?(string)($api_payload['unique_identifier']??''):'';
				if ($identifier!=='') {
					$linked_collection=db_fetch(db_query('SELECT c.id FROM '.TB_PREF.'supplier_temp_milk_orders s
						JOIN '.TB_PREF.'farmer_collections c ON c.source_id=s.id WHERE s.unique_identifier='.db_escape($identifier).' LIMIT 1'));
					$collection_id=(int)($linked_collection['id']??0);
				}
				if (!$collection_id && is_array($api_payload))
					$device_snapshot=json_decode($api_payload['device_info']??'',true);
			}
		}
		if ($collection_id) {
			$device=db_fetch(db_query('SELECT d.*,c.device_snapshot FROM '.TB_PREF.'farmer_collections c
				LEFT JOIN '.TB_PREF.'farmer_devices d ON d.id=c.device_id WHERE c.id='.$collection_id));
			if ($device) $device_snapshot=json_decode($device['device_snapshot']??'',true);
			// Older collections predate the device foreign key, but their original
			// Mobile API audit payload may still contain device_info. Surface that
			// evidence without rewriting historical collection rows.
			if (!is_array($device_snapshot)) {
				$source=db_fetch(db_query('SELECT s.unique_identifier FROM '.TB_PREF.'farmer_collections c
					JOIN '.TB_PREF.'supplier_temp_milk_orders s ON s.id=c.source_id WHERE c.id='.$collection_id));
				if ($source && $source['unique_identifier']!=='') {
					$api_log=db_fetch(db_query('SELECT request_payload FROM '.TB_PREF.'api_request_log WHERE request_payload LIKE '.
						db_escape('%"unique_identifier":"'.$source['unique_identifier'].'"%').' ORDER BY id LIMIT 1'));
					$payload=$api_log?json_decode($api_log['request_payload'],true):null;
					$device_snapshot=is_array($payload)?json_decode($payload['device_info']??'',true):null;
					if (is_array($device_snapshot)) $device=array(
						'id'=>null,'device_identifier'=>$device_snapshot['deviceId']??'',
						'device_name'=>$device_snapshot['deviceName']??'', 'manufacturer'=>$device_snapshot['manufacturer']??'',
						'model'=>$device_snapshot['model']??'', 'system_name'=>$device_snapshot['systemName']??'',
						'system_version'=>$device_snapshot['systemVersion']??'', 'app_version'=>$device_snapshot['appVersion']??'',
						'app_build_number'=>$device_snapshot['appBuildNumber']??'', 'bundle_id'=>$device_snapshot['bundleId']??'',
						'is_emulator'=>!empty($device_snapshot['isEmulator']));
				}
			}
		} elseif ($row['table_name']==='farmer_devices') {
			$device_id=(int)($after['id']??$before['id']??0);
			if ($device_id) $device=db_fetch(db_query('SELECT d.*,NULL device_snapshot FROM '.TB_PREF.'farmer_devices d WHERE d.id='.$device_id));
		}
	}
	if (($device && !empty($device['id'])) || is_array($device_snapshot)) {
		display_heading(_('Collection Device Information'));
		start_table(TABLESTYLE2);
		if ($collection_id) label_row(_('Milk collection'),'<a href="../farmers/inquiry/collections.php">#'.(int)$collection_id.'</a>');
		$device_label=audit_h(($device['device_name']??'')?:($device['device_identifier']??''));
		label_row(_('Device'),!empty($device['id'])?'<a href="device_information.php?id='.(int)$device['id'].'">'.$device_label.'</a>':$device_label.' '._('(historical API payload)'));
		foreach (array('device_identifier'=>'Device ID','manufacturer'=>'Manufacturer','model'=>'Model','system_name'=>'Operating system',
			'system_version'=>'System version','app_version'=>'App version','app_build_number'=>'App build','bundle_id'=>'Application ID') as $field=>$label)
			label_row(_($label),audit_h($device[$field]??''));
		label_row(_('Emulator'),!empty($device['is_emulator'])?_('Yes'):_('No'));
		end_table(1);
		if (is_array($device_snapshot))
			echo '<pre style="max-width:1000px;margin:0 auto 16px;white-space:pre-wrap;overflow-wrap:anywhere">'.audit_h(json_encode($device_snapshot,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)).'</pre>';
	}
    start_table(TABLESTYLE,"width='95%'"); table_header(array(_('Field'),_('Before'),_('After')));
    foreach (array_unique(array_merge(array_keys($before),array_keys($after))) as $key) {
        if ($row['event_type']==='update' && ($changes!==null ? empty($changes[$key]) : ($before[$key] ?? null)===($after[$key] ?? null))) continue;
        start_row(); label_cell(audit_h($key));
        foreach (array($before,$after) as $data) {
            $value=array_key_exists($key,$data)?($data[$key]===null?'NULL':(is_scalar($data[$key])?(string)$data[$key]:json_encode($data[$key]))):'';
            label_cell('<pre style="white-space:pre-wrap;overflow-wrap:anywhere;max-width:600px">'.audit_h($value).'</pre>');
        }
        end_row();
    }
    end_table(1);
    if ($row['details'] && $changes===null) display_note(audit_h($row['details']));
    display_note(_('Passwords, tokens and binary file contents are redacted.'));
    hyperlink_params('system_audit.php',_('All activity in this request'),'request_id='.urlencode($row['request_id']));
    end_page(); exit;
}
if (!empty($_GET['coverage'])) {
    include_once $path_to_root.'/sql/system_audit.php';
    display_heading(_('Audit Coverage'));
    start_table(TABLESTYLE); table_header(array(_('Table'),_('Status'),_('Engine'),_('Installed')));
    $prefix=$db_connections[$_SESSION['wa_current_user']->cur_con ?? 0]['tbpref'];
    foreach ($db->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetch_all(MYSQLI_NUM) as $table) {
        if (strpos($table[0],$prefix)!==0) continue;
        $short=substr($table[0],strlen($prefix)); if (audit_excluded_table($short)) continue;
        $saved=db_fetch(db_query('SELECT * FROM '.TB_PREF.'system_audit_coverage WHERE table_name='.db_escape($short)));
        $columns=$db->query('SHOW FULL COLUMNS FROM '.audit_identifier($table[0]))->fetch_all(MYSQLI_ASSOC);
        $ok=$saved && $saved['schema_hash']===hash('sha256','v2'.json_encode($columns));
        foreach (array('INSERT','UPDATE','DELETE') as $action) {
            $trigger='ja_'.substr(hash('sha256',$table[0].$action),0,40);
            $ok=$ok && (bool)$db->query('SELECT 1 FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME='.audit_literal($db,$trigger))->num_rows;
        }
        start_row(); label_cell(audit_h($short)); label_cell($ok?_('Covered'):_('Install / refresh required'));
        label_cell(audit_h($saved['engine'] ?? '')); label_cell(audit_h($saved['installed_at'] ?? '')); end_row();
    }
    end_table(1); end_page(); exit;
}
$_POST['from']=$_POST['from'] ?? sql2date($clock['audit_day']); $_POST['to']=$_POST['to'] ?? sql2date($clock['audit_day']);
start_form(false,false,"","audit_filters"); start_table(TABLESTYLE_NOBORDER);
start_row(); date_cells(_('From:'),'from'); date_cells(_('To:'),'to'); end_row();
start_row();
echo '<td>'._('User:').'</td><td><select id="actor_id" class="combo fa-select2" data-select2-local="1" name="actor_id"><option value="">'._('All users').'</option>';
$users=db_query('SELECT id,user_id,real_name FROM '.TB_PREF.'users ORDER BY user_id');
while ($user=db_fetch($users)) echo '<option value="'.(int)$user['id'].'"'.((string)get_post('actor_id')===(string)$user['id']?' selected':'').'>'
    .audit_h($user['user_id'].' - '.$user['real_name']).'</option>';
echo '</select></td>';
echo '<td>'._('Record type:').'</td><td><select id="audit_table" class="combo fa-select2" data-select2-local="1" name="audit_table"><option value="">'._('All record types').'</option>';
$tables=db_query('SELECT table_name FROM '.TB_PREF.'system_audit_coverage ORDER BY table_name');
while ($table=db_fetch($tables)) echo '<option value="'.audit_h($table['table_name']).'"'.(get_post('audit_table')===$table['table_name']?' selected':'').'>'
    .audit_h(ucwords(str_replace('_',' ',$table['table_name']))).'</option>';
echo '</select></td>'; end_row();
start_row();
echo '<td>'._('Operation:').'</td><td><select id="event_type" name="event_type" class="combo fa-select2" data-select2-local="1"><option value="">'._('All operations').'</option>';
foreach (array('insert','update','delete','request','request_end','login_success','login_failed','logout','access_denied','schema_attempt','export_requested') as $type)
    echo '<option'.(get_post('event_type')===$type?' selected':'').' value="'.$type.'">'.audit_h(ucwords(str_replace('_',' ',$type))).'</option>';
echo '</select></td>';
text_cells(_('Record key contains:'),'record_key',audit_h(get_post('record_key')),24,128); end_row();
start_row(); text_cells(_('Request ID:'),'request_id',audit_h(get_post('request_id',$_GET['request_id'] ?? '')),34,32); end_row();
$filter_number=0;
foreach (array(
    'component'=>array('Module',array('Farmers','Sales','Purchases','Inventory','Manufacturing','Fixed Assets','Dimensions','General Ledger','Reports','Administration','System')),
    'origin'=>array('Origin',array('web','cli','database')),
    'event_action'=>array('Action',array('created','updated','deleted','submitted','resubmitted','approved','rejected','cancelled','posted','paid','voided','requested','completed','failed','login_success','login_failed','logout','access_denied','export_requested')),
    'crud'=>array('CRUD',array('c','r','u','d','x'))
) as $name=>$definition) {
    if ($filter_number%2===0) start_row();
    echo '<td>'.audit_h($definition[0]).'</td><td><select id="'.$name.'" class="combo fa-select2" data-select2-local="1" name="'.$name.'"><option value="">All</option>';
    foreach ($definition[1] as $value) echo '<option value="'.audit_h($value).'"'.(get_post($name)===$value?' selected':'').'>'
        .audit_h($name==='crud'?(array('c'=>'Create','r'=>'Read / request','u'=>'Update','d'=>'Delete','x'=>'Other')[$value]):audit_event_title($value)).'</option>';
    echo '</select></td>';if (++$filter_number%2===0) end_row();
}
start_row();
$party_type=get_post('party_type','Customer');
if (!in_array($party_type,array('Customer','Supplier','User'),true)) $party_type='Customer';
echo '<td>'._('Party role:').'</td><td><select id="party_type" name="party_type" class="combo fa-select2" data-select2-local="1">';
foreach (array('Customer','Supplier','User') as $role) echo '<option value="'.$role.'"'.($role===$party_type?' selected':'').'>'.$role.'</option>';
echo '</select></td><td>'._('Affected party:').'</td><td><select id="party_id" name="party_id" class="combo fa-select2" data-select2-entity="audit_party" data-select2-type="'.$party_type.'" data-select2-url="system_audit_parties.php"><option value="" data-select2-static="1">All parties</option>';
if ((int)get_post('party_id')>0) {
    $spec=array('Customer'=>array('debtors_master','debtor_no','name'),'Supplier'=>array('suppliers','supplier_id','supp_name'),'User'=>array('users','id','user_id'))[$party_type];
    $party=db_fetch(db_query('SELECT '.$spec[2].' party_name FROM '.TB_PREF.$spec[0].' WHERE '.$spec[1].'='.(int)get_post('party_id')));
    echo '<option selected value="'.(int)get_post('party_id').'">'.audit_h($party_type.' #'.(int)get_post('party_id').' - '.($party['party_name'] ?? 'Unavailable')).'</option>';
}
echo '</select></td>';end_row();
start_row(); text_cells(_('Affected staff user ID:'),'related_user_id',audit_h(get_post('related_user_id')),10,10);
text_cells(_('IP address:'),'ip_address',audit_h(get_post('ip_address')),24,45);end_row();
end_table(1); submit_center('search',_('Get these logs'),true); br();
echo '<div style="text-align:center;margin-bottom:12px"><button type="submit" name="export" value="1" formaction="system_audit_export.php">Download CSV</button> '
    .'<button type="button" id="audit-live">'.(get_post('live')?'Pause live updates':'Start live updates').'</button> '
    .'<span id="audit-live-status" aria-live="polite">'.(get_post('live')?'Refreshes every 15 seconds':'').'</span></div>';
hidden('live',get_post('live',0));
$where=array();
if (!is_date(get_post('from')) || !is_date(get_post('to')) || date2sql(get_post('from'))>date2sql(get_post('to'))) {
    display_error(_('Enter a valid date range.')); end_form(); end_page(); exit;
}
$filters=$_POST;$filters['request_id']=get_post('request_id',$_GET['request_id'] ?? '');
$conditions=audit_event_conditions($filters,date2sql(get_post('from')),date2sql(get_post('to')));
$direction=isset($_POST['older'])?'older':(isset($_POST['newer'])?'newer':'');
$page_number=$direction ? max(1,(int)get_post('page_number',1)+($direction==='older'?1:-1)) : 1;
if ($direction==='older') $conditions.=' AND e.id<'.max(0,(int)get_post('page_last'));
if ($direction==='newer') $conditions.=' AND e.id>'.max(0,(int)get_post('page_first'));
$result=db_query(audit_event_select().' WHERE '.$conditions.' ORDER BY e.id '.($direction==='newer'?'ASC':'DESC').' LIMIT 101');
$audit_rows=array();while ($row=db_fetch($result)) $audit_rows[]=$row;
$has_more=count($audit_rows)>100;
$audit_rows=array_slice($audit_rows,0,100);
if ($direction==='newer') $audit_rows=array_reverse($audit_rows);
$has_previous=$direction==='older' || ($direction==='newer' && $has_more);
$has_next=$direction==='newer' || ($direction!=='newer' && $has_more);
echo '<div style="overflow-x:auto">';
start_table(TABLESTYLE,"width='98%'");
table_header(array('#',_($time_label),_('Recorded by'),_('Affected party'),_('Context'),_('Module'),_('Event name'),_('Description'),_('Origin'),_('IP address')));
$count=0;$last=0;
foreach (audit_party_enrich($audit_rows) as $row) {
    ++$count;
    $last=(int)$row['id'];start_row();
    label_cell('<a href="system_audit.php?id='.$last.'">'.$last.'</a>');
    $presentation=audit_event_present($row);
    foreach (array($row['occurred_at'],$row['actor_name'],$presentation['affected_user'],$presentation['context'],
        $row['component'],$presentation['event_name'],$presentation['description'],$row['origin'],$row['ip_address']) as $value)
        label_cell(audit_h($value),'style="max-width:360px;overflow-wrap:anywhere"');
    end_row();
}
end_table(1);
echo '</div>';
if (!$count) display_note(_('No audit activity matches these filters.'));
hidden('page_number',$page_number);
hidden('page_first',$audit_rows ? $audit_rows[0]['id'] : 0);
hidden('page_last',$last);
echo '<div style="text-align:center">';
if ($has_previous) submit('newer',_('Previous'));
echo ' '.sprintf(_('Page %d - %d entries'),$page_number,$count).' ';
if ($has_next) submit('older',_('Next'));
echo '</div>';
end_form();
hyperlink_params('system_audit.php',_('Check audit coverage'),'coverage=1');
display_note(_('IP address is the connection address seen by the server. It may identify a shared network or proxy, not an individual device. Browser requests do not provide a MAC address.'));
display_note(_('History starts when auditing is installed. Earlier creators are unknown unless existing document history identifies them.'));
end_page();
