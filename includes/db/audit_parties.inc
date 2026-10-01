<?php
// Party identity comes from row evidence or scoped request identifiers, never the actor ID.
function audit_party_refs($row)
{
    $after=json_decode($row['after_data'] ?? '',true) ?: array();
    $before=json_decode($row['before_data'] ?? '',true) ?: array();
    $details=json_decode($row['details'] ?? '',true) ?: array();
    $refs=array();
    $add=function($role,$id,$name='')use(&$refs) {
        if (!is_scalar($id) || !ctype_digit((string)$id) || (int)$id<=0) return;
        $refs[$role.':'.(int)$id]=array('role'=>$role,'id'=>(int)$id,'name'=>is_string($name)?$name:'');
    };
    $table=$row['table_name'] ?? '';
    foreach (array($before,$after) as $data) {
        if ($table==='farmer_share_transactions') $add('Supplier',$data['to_supplier_id'] ?? null);
        if ($table==='users') $add('User',$data['id'] ?? null,$data['user_id'] ?? $data['real_name'] ?? '');
        if ($table==='debtors_master') $add('Customer',$data['debtor_no'] ?? null,$data['name'] ?? '');
        if ($table==='suppliers') $add('Supplier',$data['supplier_id'] ?? null,$data['supp_name'] ?? '');
        if (in_array($table,array('sales_orders','debtor_trans','cust_branch'),true)) $add('Customer',$data['debtor_no'] ?? null);
        if (in_array($table,array('supp_trans','purch_orders','grn_batch','farmer_collections','farmer_payroll','farmer_deductions','farmer_milk_reversal_requests','farmer_milk_qa','farmer_qa_violations','farmer_qa_holds','farmer_share_transactions','farmer_share_ledger','farmer_advance_payments','farmer_advance_recoveries','farmer_membership_requests','farmer_deduction_entries'),true))
            $add('Supplier',$data['supplier_id'] ?? null);
    }
    if (!empty($row['related_user_id'])) $add('User',$row['related_user_id'],$after['user_id'] ?? $before['user_id'] ?? $row['related_name'] ?? '');
    if (in_array($row['event_type'] ?? '',array('request','request_end'),true)) {
        $ids=$details['record_ids'] ?? array();
        $path=parse_url($row['source'] ?? '',PHP_URL_PATH);
        if (preg_match('~(^|/)sales/~',(string)$path)) $add('Customer',$ids['customer_id'] ?? $ids['debtor_no'] ?? null);
        if (preg_match('~(^|/)(purchasing|farmers)/~',(string)$path)) $add('Supplier',$ids['supplier_id'] ?? null);
    }
    return $refs;
}
// Resolve document-view requests and detail rows through their typed parent documents.
function audit_party_document($row)
{
    $data=json_decode($row['after_data'] ?? '',true) ?: (json_decode($row['before_data'] ?? '',true) ?: array());
    $table=$row['table_name'] ?? '';
    $document=null;
    if ($table==='debtor_trans_details') $document=array('debtor_trans',$data['debtor_trans_no'] ?? null,$data['debtor_trans_type'] ?? null);
    if ($table==='sales_order_details') $document=array('sales_orders',$data['order_no'] ?? null,$data['trans_type'] ?? null);
    if ($table==='purch_order_details') $document=array('purch_orders',$data['order_no'] ?? null,0);
    if (in_array($row['event_type'] ?? '',array('request','request_end'),true)) {
        $ids=json_decode($row['details'] ?? '',true)['record_ids'] ?? array();
        $path=parse_url($row['source'] ?? '',PHP_URL_PATH);
        foreach (array('sales/view/view_invoice.php'=>array('debtor_trans',10),
            'sales/view/view_dispatch.php'=>array('debtor_trans',13),
            'sales/view/view_sales_order.php'=>array('sales_orders',$ids['trans_type'] ?? null),
            'purchasing/view/view_supp_invoice.php'=>array('supp_trans',20),
            'purchasing/view/view_supp_credit.php'=>array('supp_trans',21),
            'purchasing/view/view_po.php'=>array('purch_orders',0)) as $endpoint=>$spec)
            if ($path===$endpoint || substr((string)$path,-strlen('/'.$endpoint))==='/'.$endpoint)
                $document=array($spec[0],$ids['trans_no'] ?? null,$spec[1]);
    }
    if (!$document || !is_scalar($document[1]) || !ctype_digit((string)$document[1]) || (int)$document[1]<=0
        || !is_scalar($document[2]) || !ctype_digit((string)$document[2])) return null;
    return array($document[0],(int)$document[1],(int)$document[2]);
}
function audit_party_enrich($rows)
{
    $documents=array();
    foreach ($rows as $row) {
        $doc=audit_party_document($row);
        if ($doc) $documents[$doc[0]][$doc[1].':'.$doc[2]]=$doc;
    }
    $owners=array();
    foreach ($documents as $table=>$items) {
        $customer=in_array($table,array('sales_orders','debtor_trans'),true);
        $id=in_array($table,array('sales_orders','purch_orders'),true)?'order_no':'trans_no';
        $type=$table==='sales_orders'?'trans_type':'type';
        $party=$customer?'debtor_no':'supplier_id';
        $conditions=array();
        foreach ($items as $doc) $conditions[]='('.$id.'='.$doc[1].($table==='purch_orders'?'':' AND '.$type.'='.$doc[2]).')';
        $result=db_query('SELECT '.$id.' document_id,'.($table==='purch_orders'?'0':$type).' document_type,'.$party.' party_id FROM '.TB_PREF.$table.' WHERE '.implode(' OR ',$conditions));
        while ($owner=db_fetch($result)) $owners[$table.':'.$owner['document_id'].':'.$owner['document_type']]=array('role'=>$customer?'Customer':'Supplier','id'=>(int)$owner['party_id'],'name'=>'');
    }
    $wanted=array('User'=>array(),'Customer'=>array(),'Supplier'=>array());
    foreach ($rows as &$row) {
        $row['audit_parties']=audit_party_refs($row);
        $doc=audit_party_document($row);
        $owner=$doc ? ($owners[implode(':',$doc)] ?? null) : null;
        if ($owner && $owner['id']>0) $row['audit_parties'][$owner['role'].':'.$owner['id']]=$owner;
        foreach ($row['audit_parties'] as $ref) if ($ref['name']==='') $wanted[$ref['role']][$ref['id']]=$ref['id'];
    }
    unset($row);
    $names=array();
    foreach (array('User'=>array('users','id','user_id'),'Customer'=>array('debtors_master','debtor_no','name'),
        'Supplier'=>array('suppliers','supplier_id','supp_name')) as $role=>$spec) {
        if (!$wanted[$role]) continue;
        list($table,$id,$name)=$spec;
        $result=db_query('SELECT '.$id.' party_id,'.$name.' party_name FROM '.TB_PREF.$table.' WHERE '.$id.' IN ('.implode(',',$wanted[$role]).')');
        while ($party=db_fetch($result)) $names[$role.':'.$party['party_id']]=$party['party_name'];
    }
    foreach ($rows as &$row) foreach ($row['audit_parties'] as $key=>&$ref)
        if ($ref['name']==='' && isset($names[$key])) $ref['name']=$names[$key].' (current name)';
    unset($row,$ref);
    return $rows;
}
function audit_party_label($row)
{
    $labels=array();
    foreach ($row['audit_parties'] ?? audit_party_refs($row) as $ref)
        $labels[]=$ref['role'].' #'.$ref['id'].($ref['name']!==''?' - '.$ref['name']:'');
    return implode('; ',$labels);
}

function audit_party_filter_sql($role,$id)
{
    if (!in_array($role,array('Customer','Supplier','User'),true) || !is_scalar($id) || !ctype_digit((string)$id) || (int)$id<=0) return '0=1';
    $id=(int)$id;
    $json=function($column,$field) { return "CAST(JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(e.$column) THEN e.$column ELSE '{}' END,'$.".$field."')) AS UNSIGNED)"; };
    $conditions=array();
    $tables=$role==='Customer'?array('debtors_master','sales_orders','debtor_trans','cust_branch'):
        ($role==='Supplier'?array('suppliers','supp_trans','purch_orders','grn_batch','farmer_collections','farmer_payroll','farmer_deductions','farmer_milk_reversal_requests','farmer_milk_qa','farmer_qa_violations','farmer_qa_holds','farmer_share_transactions','farmer_share_ledger','farmer_advance_payments','farmer_advance_recoveries','farmer_membership_requests','farmer_deduction_entries'):array('users'));
    $field=$role==='Customer'?'debtor_no':($role==='Supplier'?'supplier_id':'id');
    $names=implode(',',array_map('db_escape',$tables));
    foreach (array('before_data','after_data') as $column)
        $conditions[]="(e.table_name IN ($names) AND ".$json($column,$field).'='.$id.')';
    if ($role==='Supplier') foreach (array('before_data','after_data') as $column) $conditions[]="(e.table_name='farmer_share_transactions' AND ".$json($column,'to_supplier_id').'='.$id.')';
    if ($role==='User') $conditions[]='e.related_user_id='.$id;
    else {
        $path=$role==='Customer'?'sales':'(purchasing|farmers)';
        $fields=$role==='Customer'?array('customer_id','debtor_no'):array('supplier_id');
        foreach ($fields as $field) $conditions[]="(e.event_type IN ('request','request_end') AND e.source REGEXP '(^|/)$path/' AND ".$json('details','record_ids.'.$field).'='.$id.')';
        $parents=$role==='Customer'?array('debtor_trans'=>array('trans_no','type','debtor_no','debtor_trans_details','debtor_trans_no','debtor_trans_type'),
            'sales_orders'=>array('order_no','trans_type','debtor_no','sales_order_details','order_no','trans_type')):
            array('purch_orders'=>array('order_no',null,'supplier_id','purch_order_details','order_no',null),'supp_trans'=>array('trans_no','type','supplier_id',null,null,null));
        foreach ($parents as $table=>$spec) {
            list($pk,$type,$party,$line,$line_id,$line_type)=$spec;
            if ($line) foreach (array('before_data','after_data') as $column)
                $conditions[]="(e.table_name=".db_escape($line).' AND EXISTS (SELECT 1 FROM '.TB_PREF.$table.' ap WHERE ap.'.$party.'='.$id.' AND ap.'.$pk.'='.$json($column,$line_id).($type?' AND ap.'.$type.'='.$json($column,$line_type):'').'))';
            $views=array('debtor_trans'=>array('sales/view/view_invoice.php'=>10,'sales/view/view_dispatch.php'=>13),
                'sales_orders'=>array('sales/view/view_sales_order.php'=>null),'purch_orders'=>array('purchasing/view/view_po.php'=>0),
                'supp_trans'=>array('purchasing/view/view_supp_invoice.php'=>20,'purchasing/view/view_supp_credit.php'=>21))[$table];
            foreach ($views as $view=>$view_type)
                $conditions[]="(e.event_type IN ('request','request_end') AND (e.source=".db_escape($view).' OR e.source LIKE '.db_escape('%/'.$view).') AND EXISTS (SELECT 1 FROM '.TB_PREF.$table.' ap WHERE ap.'.$party.'='.$id.' AND ap.'.$pk.'='.$json('details','record_ids.trans_no').($type?' AND ap.'.$type.'='.($view_type===null?$json('details','record_ids.trans_type'):$view_type):'').'))';
        }
    }
    return '('.implode(' OR ',$conditions).')';
}
