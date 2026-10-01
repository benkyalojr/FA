<?php
require_once __DIR__ . '/workspace.inc';

function ma_dashboard_rows($sql)
{
    $result = db_query($sql, 'Could not read dashboard data');
    $rows = array();
    while ($row = db_fetch_assoc($result)) $rows[] = $row;
    return $rows;
}

function ma_dashboard_scalar($sql)
{
    $row = ma_dashboard_rows($sql);
    return $row ? (float)reset($row[0]) : 0;
}

function ma_dashboard_table_exists($table)
{
    global $db_connections;
    // db_escape intentionally protects the TB_PREF placeholder in string values.
    // Metadata lookups therefore need the actual active connection's prefix.
    $company = $_SESSION['wa_current_user']->cur_con ?? user_company();
    $name = ($db_connections[$company]['tbpref'] ?? '').$table;
    return ma_dashboard_scalar('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='.db_escape($name)) > 0;
}

function ma_dashboard_fiscal_progress($begin, $end, $today)
{
    try {
        $start = new DateTimeImmutable($begin);
        $finish = new DateTimeImmutable($end);
        $now = new DateTimeImmutable($today);
    } catch (Exception $error) { return null; }
    if ($finish < $start) return null;
    $total = (int)$start->diff($finish)->format('%a') + 1;
    $elapsed = max(0, min($total, (int)$start->diff($now)->format('%r%a')));
    return array('begin'=>$begin, 'end'=>$end, 'elapsed'=>$elapsed, 'remaining'=>$total-$elapsed,
        'percent'=>(int)round(100*$elapsed/$total));
}

function ma_dashboard_period($period, $today, $fiscal)
{
    $month = substr($today, 0, 7).'-01';
    if (!in_array($period, array('month', 'year', 'all'), true)) $period = 'year';
    if ($period === 'month') return array($period, $month, $today);
    if ($period === 'all') return array($period, '1900-01-01', $today);
    return array('year', $fiscal['begin'] ?? substr($today, 0, 4).'-01-01', min($today, $fiscal['end'] ?? $today));
}

function ma_dashboard_can($navigation, $link, $permission)
{
    return ma_ui_find_link($navigation, $link) && $_SESSION['wa_current_user']->can_access_page($permission);
}

function ma_dashboard_model($navigation, $period, $today)
{
    $fiscal_rows = ma_dashboard_rows('SELECT begin, end, closed FROM '.TB_PREF.'fiscal_year WHERE id='.db_escape(get_company_pref('f_year')));
    $fiscal = $fiscal_rows[0] ?? null;
    $progress = $fiscal ? ma_dashboard_fiscal_progress($fiscal['begin'], $fiscal['end'], $today) : null;
    list($period, $from, $to) = ma_dashboard_period($period, $today, $fiscal);
    $data = array('snapshot'=>array(), 'overview'=>array(), 'series'=>array(), 'classes'=>array(),
        'period'=>$period, 'from'=>$from, 'to'=>$to, 'fiscal'=>$progress, 'analytics'=>false,
        'currency'=>get_company_pref('curr_default'));
    $asof = db_escape($today);
    $month = db_escape(substr($today, 0, 7).'-01');
    $inquiry = 'sales/inquiry/customer_inquiry.php';
    if (ma_dashboard_can($navigation, $inquiry, 'SA_SALESTRANSVIEW')) {
        $net = '(ov_amount+ov_discount)*rate*IF(type='.ST_CUSTCREDIT.',-1,1)';
        $sales = ma_dashboard_rows('SELECT COALESCE(SUM(IF(tran_date='.$asof.','.$net.',0)),0) AS today,
            COALESCE(SUM('.$net.'),0) AS month FROM '.TB_PREF.'debtor_trans
            WHERE type IN ('.ST_SALESINVOICE.','.ST_CUSTCREDIT.') AND tran_date BETWEEN '.$month.' AND '.$asof)[0];
        $gross = '(ov_amount+ov_gst+ov_freight+ov_freight_tax+ov_discount)';
        $unpaid = ma_dashboard_scalar('SELECT COUNT(*) FROM '.TB_PREF.'debtor_trans WHERE type='.ST_SALESINVOICE.
            ' AND tran_date<='.$asof.' AND '.$gross.'-alloc>'.FLOAT_COMP_DELTA);
        $receivable = ma_dashboard_scalar('SELECT COALESCE(SUM(IF(type IN ('.ST_CUSTCREDIT.','.ST_CUSTPAYMENT.','.ST_BANKDEPOSIT.'),-1,1)*('.$gross.'-alloc)*rate),0)
            FROM '.TB_PREF.'debtor_trans WHERE type<>'.ST_CUSTDELIVERY.' AND tran_date<='.$asof.
            ' AND ABS('.$gross.')-alloc>'.FLOAT_COMP_DELTA);
        $data['snapshot'] = array(
            array('label'=>_("Today's sales"), 'value'=>$sales['today'], 'money'=>true, 'icon'=>'chart', 'tone'=>'blue', 'link'=>$inquiry, 'hint'=>_('Net sales excluding tax, after credit notes')),
            array('label'=>_('This month'), 'value'=>$sales['month'], 'money'=>true, 'icon'=>'calendar', 'tone'=>'green', 'link'=>$inquiry, 'hint'=>_('Month-to-date net sales excluding tax')),
            array('label'=>_('Total receivable'), 'value'=>$receivable, 'money'=>true, 'icon'=>'wallet', 'tone'=>'orange', 'link'=>$inquiry, 'hint'=>_('Outstanding customer balance, net of unallocated credits and payments')),
            array('label'=>_('Unpaid invoices'), 'value'=>$unpaid, 'money'=>false, 'icon'=>'receipt', 'tone'=>'purple', 'link'=>$inquiry, 'hint'=>_('Invoices with an outstanding balance')),
        );
    }
    $counts = array(
        array('Customers','people','blue','sales/manage/customers.php','SA_CUSTOMER','debtors_master', 'inactive=0', false),
        array('Suppliers','user','orange','purchasing/manage/suppliers.php','SA_SUPPLIER','suppliers','inactive=0', false),
        array('Items and inventory','package','purple','inventory/manage/items.php','SA_ITEM','stock_master',"inactive=0 AND mb_flag<>'F'",false),
        array('Employees','people','green','payroll/inquiry/employees_inquiry.php','SA_EMPLOYEEVIEW','employees','inactive=0',true),
    );
    foreach ($counts as $entry) {
        if (!ma_dashboard_can($navigation, $entry[3], $entry[4])) continue;
        if ($entry[7] && !ma_dashboard_table_exists($entry[5])) continue;
        $data['overview'][] = array('label'=>_($entry[0]), 'icon'=>$entry[1], 'tone'=>$entry[2], 'link'=>$entry[3], 'money'=>false,
            'value'=>ma_dashboard_scalar('SELECT COUNT(*) FROM '.TB_PREF.$entry[5].' WHERE '.$entry[6]));
    }
    if (ma_dashboard_can($navigation, 'farmers/manage/farmers.php', 'SA_LINK_FARMERS_FARMERS') && ma_dashboard_table_exists('supplier_types')) {
        $data['overview'][] = array('label'=>_('Farmers'), 'icon'=>'leaf', 'tone'=>'green', 'link'=>'farmers/manage/farmers.php', 'money'=>false,
            'value'=>ma_dashboard_scalar('SELECT COUNT(*) FROM '.TB_PREF.'suppliers s JOIN '.TB_PREF."supplier_types t ON t.id=s.supplier_type_id WHERE t.name='Farmer' AND s.inactive=0"));
    }
    if (ma_dashboard_can($navigation, 'gl/inquiry/bank_inquiry.php', 'SA_BANKTRANSVIEW')) {
        $balance = ma_dashboard_scalar('SELECT COALESCE(SUM(g.amount),0) FROM '.TB_PREF.'gl_trans g WHERE g.tran_date<='.$asof.
            ' AND EXISTS (SELECT 1 FROM '.TB_PREF.'bank_accounts b WHERE b.account_code=g.account)');
        array_splice($data['overview'], 1, 0, array(array('label'=>_('Bank & cash balance'), 'icon'=>'wallet', 'tone'=>'green', 'link'=>'gl/inquiry/bank_inquiry.php', 'money'=>true, 'value'=>$balance)));
    }
    if (ma_dashboard_can($navigation, 'gl/inquiry/profit_loss.php', 'SA_GLANALYTIC')
        && ma_dashboard_can($navigation, 'gl/inquiry/balance_sheet.php', 'SA_GLANALYTIC')) {
        $data['analytics'] = true;
        $join = ' FROM '.TB_PREF.'gl_trans g JOIN '.TB_PREF.'chart_master a ON a.account_code=g.account
            JOIN '.TB_PREF.'chart_types t ON t.id=a.account_type JOIN '.TB_PREF.'chart_class c ON c.cid=t.class_id ';
        $group = $period === 'all' ? '%Y' : '%Y-%m';
        $data['series'] = ma_dashboard_rows("SELECT DATE_FORMAT(g.tran_date,'".$group."') AS period,
            SUM(IF(c.ctype=".CL_INCOME.',-g.amount,0)) AS sales,
            SUM(IF(c.ctype IN ('.CL_COGS.','.CL_EXPENSE.'),g.amount,0)) AS costs'.$join.
            ' WHERE c.ctype IN ('.CL_INCOME.','.CL_COGS.','.CL_EXPENSE.') AND g.tran_date BETWEEN '.db_escape($from).' AND '.db_escape($to).
            ' GROUP BY period ORDER BY period');
        $data['classes'] = ma_dashboard_rows('SELECT c.cid, c.class_name, c.ctype, SUM(g.amount) AS balance'.$join.
            ' WHERE g.tran_date<='.db_escape($to).' AND (c.ctype<'.CL_INCOME.' OR g.tran_date>='.db_escape($from).')
            GROUP BY c.cid,c.class_name,c.ctype ORDER BY c.cid');
        foreach ($data['classes'] as &$class)
            $class['balance'] *= get_class_type_convert($class['ctype']);
        unset($class);
    }
    return $data;
}

function ma_dashboard_number($value, $money = true)
{
    return number_format2((float)$value, $money ? user_price_dec() : 0);
}

function ma_dashboard_chart($series, $currency)
{
    if (!$series) {
        echo '<div class="ma-empty">'.ma_ui_escape(_('No posted income or costs in this period.')).'</div>';
        return;
    }
    $min = 0; $max = 0;
    foreach ($series as $row) { $min = min($min, (float)$row['sales'], (float)$row['costs']); $max = max($max, (float)$row['sales'], (float)$row['costs']); }
    if ($min === $max) $max = $min + 1;
    $range = $max - $min;
    $zero = 195 - (0-$min)/$range*160;
    $step = 470/count($series);
    $bar = min(16, $step*.3);
    echo '<div class="ma-chart-wrap"><svg class="ma-chart" viewBox="0 0 560 236" role="img" aria-label="'.ma_ui_escape(_('Income and costs by period; exact figures in the data table below')).'">';
    for ($i=0; $i<=4; $i++) {
        $y = 195-$i*40;
        $value = $min+$range*$i/4;
        $label = abs($value)>=1000000 ? round($value/1000000,1).'m' : (abs($value)>=1000 ? round($value/1000,1).'k' : round($value));
        echo '<line class="ma-chart-grid" x1="62" x2="540" y1="'.$y.'" y2="'.$y.'"/><text x="54" y="'.($y+3).'" text-anchor="end">'.ma_ui_escape($label).'</text>';
    }
    foreach ($series as $i=>$row) {
        $x = 66+$step*$i+$step/2;
        foreach (array('sales', 'costs') as $j=>$kind) {
            $value = (float)$row[$kind];
            $y = 195-($value-$min)/$range*160;
            echo '<rect class="ma-chart-'.$kind.'" x="'.round($x+($j-1)*($bar+2),2).'" y="'.round(min($y,$zero),2).'" width="'.$bar.'" height="'.round(abs($zero-$y),2).'" rx="2"><title>'.ma_ui_escape($row['period'].' / '.($kind==='sales'?_('Income'):_('Costs')).': '.ma_dashboard_number($value).' '.$currency).'</title></rect>';
        }
        if (count($series)<=12 || $i % (int)ceil(count($series)/12)===0) {
            $label = strlen($row['period'])===7 ? date('M', strtotime($row['period'].'-01')) : $row['period'];
            echo '<text x="'.round($x,2).'" y="218" text-anchor="middle">'.ma_ui_escape($label).'</text>';
        }
    }
    echo '</svg></div><div class="ma-chart-legend"><span>'._('Income').'</span><span>'._('Costs').'</span></div>';
}

function ma_dashboard($navigation)
{
    global $path_to_root;
    $period = is_string($_GET['period'] ?? null) ? $_GET['period'] : 'year';
    $today = date2sql(Today());
    $data = ma_dashboard_model($navigation, $period, $today);
    $actions = ma_ui_quick_actions($navigation);
    $user_name = $_SESSION['wa_current_user']->name;
    $hour = (int)date('G');
    $greeting = $hour < 12 ? _('Good morning,') : ($hour < 18 ? _('Good afternoon,') : _('Good evening,'));
    include __DIR__ . '/views/dashboard.php';
}
