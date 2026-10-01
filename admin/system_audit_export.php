<?php
$page_security='SA_SYSTEMAUDIT';
$path_to_root='..';
include_once $path_to_root.'/includes/session.inc';
include_once $path_to_root.'/includes/ui.inc';
include_once $path_to_root.'/includes/db/audit_event_report.inc';
check_page_security($page_security);
if (!is_date(get_post('from')) || !is_date(get_post('to')) || date2sql(get_post('from'))>date2sql(get_post('to'))) {
    page(_('Export Audit Logs'));display_error(_('Enter a valid date range.'));end_page();exit;
}
$conditions=audit_event_conditions($_POST,date2sql(get_post('from')),date2sql(get_post('to')));
// Bound export size explicitly; never silently truncate an audit download.
$ids=db_query('SELECT e.id FROM '.TB_PREF.'system_audit_events e WHERE '.$conditions.' ORDER BY e.id DESC LIMIT 10001');
if (db_num_rows($ids)>10000) {
    page(_('Export Audit Logs'));display_error(_('More than 10,000 entries match. Narrow the filters before exporting.'));end_page();exit;
}
$selected=array();while ($row=db_fetch($ids)) $selected[]=(int)$row['id'];
system_audit_event('export_requested',array('format'=>'csv','entry_count'=>count($selected)));
// End the framework's HTML output buffering before streaming a plain CSV response.
while (ob_get_level()) ob_end_clean();
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="snaperp-audit-'.date('Ymd-His').'.csv"');
header('Cache-Control: no-store');
$out=fopen('php://output','w');
fputcsv($out,array('ID','Time (database timezone)','User','User ID','Affected party','Context','Module','Event name','Description','Origin','IP address','Action','CRUD','Record','Request ID'),',','"','');
foreach (array_chunk($selected,200) as $chunk) {
    $rows=db_query(audit_event_select().' WHERE e.id IN ('.implode(',',$chunk).') ORDER BY e.id DESC');
    $batch=array();
    while ($row=db_fetch($rows)) $batch[]=$row;
    foreach (audit_party_enrich($batch) as $row) {
        $p=audit_event_present($row);
        $values=array($row['id'],$row['occurred_at'],$row['actor_name'],$row['actor_id'],$p['affected_user'],$p['context'],$row['component'],
            $p['event_name'],$p['description'],$row['origin'],$row['ip_address'],$row['event_action'],$row['crud'],$p['record'],$row['request_id']);
        // Spreadsheet applications must treat user-entered names/references as text.
        $values=array_map('audit_csv_cell',$values);
        fputcsv($out,$values,',','"','');
    }
}
fclose($out);exit;
