<?php
// Real MySQL transactions in a unique disposable prefix. Core accounting calls are
// represented by a ledger fixture to inject failures after FA's nested commit.
if (PHP_SAPI !== 'cli') exit;
$supplier_company_id=(int)($argv[1] ?? 0);
$supplier_test_prefix='mpesa_test_'.bin2hex(random_bytes(6)).'_';
require dirname(__DIR__).'/scripts/supplier_types_cli.inc';
require $path_to_root.'/includes/db/sql_functions.inc';
define('ST_SALESINVOICE',10); define('ST_CUSTPAYMENT',12); define('ST_SUPPAYMENT',22);
require $path_to_root.'/includes/access_levels.inc';
require $path_to_root.'/sql/mpesa.php';
require_once $path_to_root.'/mpesa/includes/mpesa_inbox.inc';
require_once $path_to_root.'/mpesa/includes/mpesa_http.inc';
require_once $path_to_root.'/mpesa/includes/mpesa_reconcile.inc';
require_once $path_to_root.'/mpesa/includes/mpesa_payout.inc';
function ok($condition,$message) { if (!$condition) throw new RuntimeException('FAIL: '.$message); echo 'PASS: '.$message."\n"; }
function get_supplier($id) { return array('inactive'=>0,'curr_code'=>'KES'); }
function write_supp_payment($no,$supplier,$bank,$date,$ref,$amount,$discount,$memo,$charge,$bank_amount) {
    begin_transaction(); db_query('INSERT INTO '.TB_PREF.'ledger (amount) VALUES (-'.db_escape($amount).')');
    $id=db_insert_id(); commit_transaction(); if (!empty($GLOBALS['inject_payout_failure'])) throw new RuntimeException('Injected supplier payment failure'); return $id;
}
function get_customer($id) { return $id===7 ? array('inactive'=>0,'curr_code'=>'KES') : null; }
function get_default_branch($id) { return array('branch_code'=>1); }
function get_bank_account($id) { return array('inactive'=>0,'bank_curr_code'=>'KES'); }
function get_customer_trans($no,$type) { return array('debtor_no'=>7,'ov_amount'=>100,'ov_gst'=>0,'ov_freight'=>0,'ov_freight_tax'=>0,'ov_discount'=>0,'alloc'=>0); }
function get_voided_entry($type,$no) { return false; }
function sql2date($date) { return $date; }
function write_customer_payment($no,$debtor,$branch,$bank,$date,$ref,$amount,$discount,$memo,$rate,$fee,$bank_amount) {
    begin_transaction();
    db_query('INSERT INTO '.TB_PREF.'ledger (amount) VALUES ('.db_escape($amount).')');
    $id=db_insert_id(); commit_transaction(); return $id;
}
function allocate_payment($a,$b,$c,$d,$e,$f,$g) { if (!empty($GLOBALS['inject_failure'])) throw new RuntimeException('Injected allocation failure'); }
$Refs=new class { function get_next($type) { return 'test'; } };
try {
    db_query('CREATE TABLE '.TB_PREF.'sys_prefs (name varchar(100) PRIMARY KEY,category varchar(100),type varchar(20),length int,value text) ENGINE=InnoDB');
    db_query('CREATE TABLE '.TB_PREF.'stock_master (id int) ENGINE=InnoDB');
    db_query('CREATE TABLE '.TB_PREF.'ledger (id int AUTO_INCREMENT PRIMARY KEY,amount double) ENGINE=InnoDB');
    migrate_mpesa(); migrate_mpesa();
    ok(get_company_pref('mpesa_schema_version')==='2','migration and repeated upgrade');
    db_query('UPDATE '.TB_PREF.'mpesa_config SET bank_account_id=1,fee_enabled=0');
    $id=mpesa_tx_create_stk(7,ST_SALESINVOICE,1,'254712345678',100,'INV-1',1);
    mpesa_tx_update($id,array('status'=>'received','receipt'=>'TEST0001','trans_time'=>'2026-10-01 10:00:00'));
    $inject_failure=true;
    list($posted,$message)=mpesa_post_payment($id,7,array(ST_SALESINVOICE,1),1);
    $count=db_fetch(db_query('SELECT COUNT(*) AS n FROM '.TB_PREF.'ledger'));
    ok(!$posted && (int)$count['n']===0 && mpesa_tx_get($id)['payment_no']===null,'allocation failure rolls back ledger and receipt claim');
    $inject_failure=false;
    list($posted,$message)=mpesa_post_payment($id,7,array(ST_SALESINVOICE,1),1);
    ok($posted && mpesa_tx_get($id)['status']==='posted','retry posts successfully');
    mpesa_post_payment($id,7,null,1);
    $count=db_fetch(db_query('SELECT COUNT(*) AS n FROM '.TB_PREF.'ledger'));
    ok((int)$count['n']===1,'duplicate receipt cannot post twice');
    $cb=array('Body'=>array('stkCallback'=>array('CheckoutRequestID'=>'EARLY','ResultCode'=>0,'CallbackMetadata'=>array('Item'=>array(array('Name'=>'MpesaReceiptNumber','Value'=>'TEST0002'),array('Name'=>'Amount','Value'=>100))))));
    $inbox=mpesa_inbox_add('stk',json_encode($cb),'127.0.0.1');
    $row=db_fetch(db_query('SELECT * FROM '.TB_PREF.'mpesa_inbox WHERE id='.(int)$inbox));
    ok(mpesa_process_inbox_row($row)!==null,'early STK callback stays queued');
    $early=mpesa_tx_create_stk(7,ST_SALESINVOICE,1,'254712345678',100,'INV-1',1);
    mpesa_tx_update($early,array('checkout_request_id'=>'EARLY'));
    ok(mpesa_process_inbox_row($row)===null && mpesa_tx_get($early)['status']==='posted','early callback posts once request is registered');
    mpesa_process_inbox_row($row);
    $count=db_fetch(db_query('SELECT COUNT(*) AS n FROM '.TB_PREF.'ledger'));
    ok((int)$count['n']===2,'repeated STK callback is idempotent');
    foreach (array('0712345678','+254712345678','712345678') as $phone) ok(mpesa_msisdn($phone)==='254712345678','normalize '.$phone);
    ok(mpesa_msisdn('254999999999')==='' && mpesa_msisdn('0999999999')==='','reject invalid mobile prefixes');
    try { mpesa_whole_amount(100.50); ok(false,'fraction rejected'); } catch (InvalidArgumentException $expected) { ok(true,'fractional charge rejected without rounding'); }
    $csv=fopen('php://temp','r+'); fwrite($csv,"Receipt,Date,Details,Paid In,Withdrawn,Charge\nTEST0001,2026-10-01,Receipt,100,0,0\n"); rewind($csv);
    $rows=mpesa_statement_parse($csv); fclose($csv);
    list($added,$skipped)=mpesa_statement_import($rows);
    list($added2,$skipped2)=mpesa_statement_import($rows);
    ok($added===1 && $added2===0 && $skipped2===1,'statement import is repeatable');
    $matched=db_fetch(db_query('SELECT matched_tx_id FROM '.TB_PREF.'mpesa_statement_lines'));
    ok((int)$matched['matched_tx_id']===$id,'statement matches posted receipt');
    $rows[0]['paid_in']=101;
    try { mpesa_statement_import($rows); ok(false,'conflict rejected'); } catch (RuntimeException $expected) { ok(true,'conflicting statement import rejected'); }
    $codes=array(); foreach ($security_areas as $key=>$area) if (strpos($key,'SA_MPESA')===0) $codes[]=$area[0];
    ok(count($codes)===6 && count(array_unique($codes))===6,'six distinct M-Pesa permissions');
    $collisions=array(); foreach ($security_areas as $key=>$area) if (strpos($key,'SA_MPESA')!==0 && in_array($area[0],$codes,true)) $collisions[]=$key;
    ok(!$collisions,'M-Pesa permission codes do not overlap existing rights');
    db_query('UPDATE '.TB_PREF.'mpesa_config SET b2c_bank_account_id=2');
    db_query("INSERT INTO ".TB_PREF."mpesa_payouts (status,supplier_id,phone,amount,reason,requested_by,bank_account_id,originator_conversation_id,created_at,updated_at) VALUES ('sent',1,'254712345678',50,'Test',1,2,'PAYOUT1',NOW(),NOW())");
    $payout=(int)db_insert_id();
    $callback=array('Result'=>array('OriginatorConversationID'=>'PAYOUT1','ResultCode'=>0,'TransactionID'=>'TESTOUT1','ResultDesc'=>'Success','ResultParameters'=>array('ResultParameter'=>array(array('Key'=>'TransactionAmount','Value'=>50)))));
    $inject_payout_failure=true;
    try { mpesa_handle_b2c('b2c',$callback); ok(false,'payout rollback'); } catch (RuntimeException $expected) { ok(mpesa_payout_get($payout)['status']==='sent','supplier posting failure keeps payout retryable'); }
    $count=db_fetch(db_query('SELECT COUNT(*) AS n FROM '.TB_PREF.'ledger'));
    ok((int)$count['n']===2,'supplier posting failure rolls back accounting');
    $inject_payout_failure=false;
    mpesa_handle_b2c('b2c',$callback); mpesa_handle_b2c('b2c',$callback);
    $count=db_fetch(db_query('SELECT COUNT(*) AS n FROM '.TB_PREF.'ledger'));
    ok((int)$count['n']===3 && mpesa_payout_get($payout)['status']==='success','duplicate B2C success posts one supplier payment');
    db_query("INSERT INTO ".TB_PREF."mpesa_payouts (supplier_id,phone,amount,requested_by,created_at,updated_at) VALUES (1,'254712345678',50,1,NOW(),NOW())");
    $request=(int)db_insert_id();
    try { mpesa_payout_decide($request,1,true); ok(false,'self approval'); } catch (RuntimeException $expected) { ok(mpesa_payout_get($request)['status']==='requested','requester cannot approve their own payout'); }
    mpesa_payout_decide($request,2,false);
    ok(mpesa_payout_get($request)['status']==='rejected','another approver can reject payout');
    echo "M-Pesa integration checks passed.\n";
} finally {
    cancel_transaction();
    $res=db_query('SHOW TABLES LIKE '.db_escape(TB_PREF.'%')); $tables=array();
    while ($row=db_fetch($res)) if (strpos($row[0],TB_PREF)===0) $tables[]=$row[0];
    foreach ($tables as $table) db_query('DROP TABLE `'.$table.'`');
}
