<?php
$page_security='SA_SYSTEMAUDIT';
$path_to_root='..';
include_once $path_to_root.'/includes/session.inc';
check_page_security($page_security);
$types=array('Customer'=>array('debtors_master','debtor_no','name'),'Supplier'=>array('suppliers','supplier_id','supp_name'),'User'=>array('users','id','user_id'));
$type=is_string($_GET['type'] ?? null)?$_GET['type']:'';
$results=array();
if (isset($types[$type])) {
    list($table,$id,$name)=$types[$type];
    $term=is_string($_GET['term'] ?? null)?substr($_GET['term'],0,100):'';
    $where=$name.' LIKE '.db_escape('%'.$term.'%');
    if (ctype_digit($term)) $where.=' OR '.$id.'='.(int)$term;
    $query=db_query('SELECT '.$id.' party_id,'.$name.' party_name FROM '.TB_PREF.$table.' WHERE '.$where.' ORDER BY '.$name.','.$id.' LIMIT 30');
    while ($party=db_fetch($query)) $results[]=array('id'=>(string)$party['party_id'],'text'=>$type.' #'.$party['party_id'].' - '.$party['party_name']);
}
while (ob_get_level()) ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode(array('results'=>$results),JSON_INVALID_UTF8_SUBSTITUTE);exit;
