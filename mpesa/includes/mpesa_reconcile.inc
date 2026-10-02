<?php
// Normalized CSV export: Receipt, Date, Details, Paid In, Withdrawn, Charge.
function mpesa_statement_parse($stream)
{
    $header = fgetcsv($stream, 0, ',', '"', '');
    if (!$header) throw new RuntimeException('The statement is empty.');
    $map = array();
    foreach ($header as $i=>$name) $map[strtolower(trim(str_replace("\xEF\xBB\xBF", '', $name)))] = $i;
    // The M-Pesa portal names its columns differently from this system's own export; accept both.
    $aliases = array('receipt'=>array('receipt','receipt no','receipt no.','receipt number'), 'date'=>array('date','completion time','completion date','time'),
        'details'=>array('details','description'), 'paid in'=>array('paid in','credit','amount in'), 'withdrawn'=>array('withdrawn','debit','amount out','paid out'), 'charge'=>array('charge','charges'));
    foreach ($aliases as $canonical=>$names) {
        if (isset($map[$canonical])) continue;
        foreach ($names as $n) if (isset($map[$n])) { $map[$canonical] = $map[$n]; break; }
    }
    foreach (array('receipt','date','paid in') as $name)
        if (!isset($map[$name])) throw new RuntimeException('Missing CSV column: '.$name.'. Use the statement CSV from the M-Pesa portal, or columns Receipt, Date, Details, Paid In, Withdrawn, Charge.');
    $rows=array(); $seen=array(); $line=1;
    while (($cols=fgetcsv($stream,0,',','"','')) !== false) {
        $line++; if ($cols === array(null)) continue;
        if (count($rows) >= 5000) throw new RuntimeException('Import at most 5,000 rows at once.');
        $get=function($key) use ($cols,$map) { return isset($map[$key]) ? trim($cols[$map[$key]] ?? '') : ''; };
        $receipt=$get('receipt');
        if ($receipt === '') continue;   // totals / blank lines
        if (!preg_match('/^[A-Za-z0-9_-]{1,30}$/',$receipt)) throw new RuntimeException('Invalid receipt on line '.$line.'.');
        $duplicate = isset($seen[$receipt]);
        $date=$get('date');
        $parsed=DateTime::createFromFormat('!Y-m-d H:i:s',$date) ?: (DateTime::createFromFormat('!Y-m-d',$date) ?: (DateTime::createFromFormat('!d-m-Y H:i:s',$date) ?: DateTime::createFromFormat('!d/m/Y H:i:s',$date)));
        $errors=DateTime::getLastErrors();
        if (!$parsed || ($errors && ($errors['warning_count'] || $errors['error_count']))) throw new RuntimeException('Use YYYY-MM-DD or YYYY-MM-DD HH:MM:SS dates (line '.$line.').');
        $row=array('receipt'=>$receipt,'date'=>$parsed->format('Y-m-d H:i:s'),'details'=>substr($get('details'),0,255));
        foreach (array('paid in'=>'paid_in','withdrawn'=>'withdrawn','charge'=>'charge') as $col=>$key) {
            $value=str_replace(',','',$get($col));
            if ($value==='') $value='0';
            if (!preg_match('/^\d+(?:\.\d{1,2})?$/',$value) || !is_finite((float)$value)) throw new RuntimeException('Invalid amount on line '.$line.'.');
            $row[$key]=(float)$value;
        }
        if ($duplicate) {
            // The portal lists a transaction's charge as a second row with the same receipt: add it to the first row.
            if ($row['paid_in'] > 0) throw new RuntimeException('Duplicate receipt on line '.$line.'.');
            $rows[$seen[$receipt]]['charge'] += max($row['charge'], $row['withdrawn']);
            continue;
        }
        $seen[$receipt]=count($rows);
        $rows[]=$row;
    }
    return $rows;
}

function mpesa_statement_import(array $rows)
{
    $batch=bin2hex(random_bytes(12)); $added=0; $skipped=0;
    begin_transaction();
    try {
        foreach ($rows as $r) {
            $existing=db_fetch(db_query('SELECT * FROM '.TB_PREF.'mpesa_statement_lines WHERE receipt='.db_escape($r['receipt']).' FOR UPDATE'));
            if ($existing) {
                foreach (array('paid_in','withdrawn','charge') as $k)
                    if (abs((float)$existing[$k]-$r[$k])>.005) throw new RuntimeException('Receipt '.$r['receipt'].' already has different amounts. No rows were imported.');
                $skipped++; continue;
            }
            db_query('INSERT INTO '.TB_PREF.'mpesa_statement_lines (receipt,trans_time,details,paid_in,withdrawn,charge,batch,created_at) VALUES ('.db_escape($r['receipt']).','.db_escape($r['date']).','.db_escape($r['details']).','.db_escape($r['paid_in']).','.db_escape($r['withdrawn']).','.db_escape($r['charge']).','.db_escape($batch).',NOW())','Cannot import statement line');
            $added++;
        }
        mpesa_statement_match();
        commit_transaction();
    } catch (Throwable $e) { cancel_transaction(); throw $e; }
    return array($added,$skipped);
}

function mpesa_statement_match()
{
    // Only posted incoming payments qualify. Differences remain visible rather than altering the ledger.
    db_query('UPDATE '.TB_PREF.'mpesa_statement_lines s LEFT JOIN '.TB_PREF."mpesa_transactions t ON t.receipt=s.receipt AND t.status='posted' SET s.matched_tx_id=t.id",'Cannot match statement');
}
