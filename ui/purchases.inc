<?php
// Purchases workspace: the same toolbar/list pattern as Sales, over FA's own
// purchasing screens (posting and approvals are untouched).
require_once __DIR__ . '/sales.inc';

define('MA_PURCHASES_APP', 'AP');

function ma_purchases_tabs()
{
    return array(
        'overview'   => array(_('Overview'),        'grid',    'index.php?application=AP', null),
        'orders'     => array(_('Purchase Orders'), 'cart',    'purchasing/inquiry/po_search_completed.php', 'SA_SUPPTRANSVIEW'),
        'receiving'  => array(_('Receiving'),       'package', 'purchasing/inquiry/po_search.php', 'SA_GRN'),
        'invoices'   => array(_('Invoices'),        'receipt', 'purchasing/inquiry/supplier_inquiry.php?filterType=1', 'SA_SUPPTRANSVIEW'),
        'credits'    => array(_('Credit Notes'),    'undo',    'purchasing/inquiry/supplier_inquiry.php?filterType=4', 'SA_SUPPTRANSVIEW'),
        'payments'   => array(_('Payments'),        'wallet',  'purchasing/inquiry/supplier_inquiry.php?filterType=3', 'SA_SUPPTRANSVIEW'),
        'allocations'=> array(_('Allocations'),     'swap',    'purchasing/allocations/supplier_allocation_main.php', 'SA_SUPPLIERALLOC'),
        'overdue'    => array(_('Unsettled'),        'bell',    'purchasing/inquiry/supplier_inquiry.php?filterType=2', 'SA_SUPPTRANSVIEW'),
    );
}

function ma_purchases_active_tab()
{
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $name = basename($script);
    $get = function ($key) { return is_string($_GET[$key] ?? null) ? $_GET[$key] : null; };
    if ($name === 'index.php') return $get('application') === MA_PURCHASES_APP ? 'overview' : null;
    if ($name === 'reports_main.php') return null; // the Reports workspace owns the catalogue
    if (strpos($script, '/purchasing/') === false) return null;
    if (basename(dirname($script)) === 'manage') return 'settings';
    switch ($name) {
        case 'po_search_completed.php':
            return 'orders';
        case 'po_search.php': case 'po_receive_items.php':
            return 'receiving';
        case 'supplier_inquiry.php':
            $filter = $_POST['filterType'] ?? $get('filterType');
            return array('2'=>'overdue', '3'=>'payments', '4'=>'credits', '5'=>'credits', '6'=>'receiving')[$filter] ?? 'invoices';
        case 'supplier_invoice.php':
            return 'invoices';
        case 'supplier_credit.php':
            return 'credits';
        case 'supplier_payment.php':
            return 'payments';
        case 'supplier_allocation_main.php': case 'supplier_allocation_inquiry.php': case 'supplier_allocate.php':
            return 'allocations';
        case 'po_entry_items.php':
            foreach (array('NewOrder'=>'orders', 'ModifyOrderNumber'=>'orders', 'AddedPO'=>'orders', 'UpdatedPO'=>'orders',
                'NewGRN'=>'receiving', 'AddedGRN'=>'receiving', 'NewInvoice'=>'invoices', 'AddedPI'=>'invoices') as $key=>$tab)
                if (isset($_GET[$key])) return $tab;
            return 'orders';
    }
    return null;
}

function ma_purchases_is_purchasing_screen()
{
    return ma_purchases_active_tab() !== null || strpos(str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? ''), '/purchasing/') !== false;
}

function ma_purchases_is_list_screen()
{
    return in_array(basename($_SERVER['SCRIPT_NAME'] ?? ''), array('po_search_completed.php', 'po_search.php',
        'supplier_inquiry.php', 'supplier_allocation_main.php'), true) && ma_purchases_active_tab() !== null;
}

function ma_purchases_module_links($index)
{
    $app = $_SESSION['App']->applications[MA_PURCHASES_APP] ?? null;
    $links = array();
    if (!$app || !isset($app->modules[$index])) return $links;
    $module = $app->modules[$index];
    foreach (array_merge($module->lappfunctions, $module->rappfunctions) as $item) {
        if (!$item->label || !$item->link || !$_SESSION['wa_current_user']->can_access_page($item->access)) continue;
        if ($index === 2 && strpos($item->link, 'purchasing/manage/suppliers.php') === 0) continue;
        $links[] = array('label'=>ma_ui_label($item->label), 'link'=>$item->link);
    }
    return $links;
}

function ma_purchases_toolbar()
{
    $active = ma_purchases_active_tab();
    echo '<div class="ma-sales-toolbar"><nav class="ma-sales-tabs" aria-label="'.ma_ui_escape(_('Purchasing sections')).'">';
    foreach (ma_purchases_tabs() as $key=>$tab) {
        if ($tab[3] && !$_SESSION['wa_current_user']->can_access_page($tab[3])) continue;
        echo '<a class="ma-sales-tab'.($key === $active ? ' active' : '').'"'.($key === $active ? ' aria-current="page"' : '')
            .' href="'.ma_ui_escape(ma_ui_href($tab[2])).'">'.ma_ui_icon($tab[1]).'<span>'.ma_ui_escape($tab[0]).'</span></a>';
    }
    echo '</nav><div class="ma-sales-actions">';
    ma_sales_menu(_('Reports'), 'chart', ma_purchases_module_links(1));
    ma_settings_button(MA_PURCHASES_APP);
    echo '</div></div>';
}

function ma_purchases_topbar_new()
{
    ma_sales_menu(_('New'), 'plus', ma_purchases_module_links(0), true);
}

function ma_purchases_new_action($tab)
{
    $actions = array(
        'orders'    => array(_('New Purchase Order'), 'purchasing/po_entry_items.php?NewOrder=Yes', 'SA_PURCHASEORDER'),
        'receiving' => array(_('New Direct GRN'), 'purchasing/po_entry_items.php?NewGRN=Yes', 'SA_GRN'),
        'invoices'  => array(_('New Supplier Invoice'), 'purchasing/po_entry_items.php?NewInvoice=Yes', 'SA_SUPPLIERINVOICE'),
        'credits'   => array(_('New Credit Note'), 'purchasing/supplier_credit.php?New=1', 'SA_SUPPLIERCREDIT'),
        'payments'  => array(_('New Payment'), 'purchasing/supplier_payment.php', 'SA_SUPPLIERPAYMNT'),
    );
    $a = $actions[$tab] ?? null;
    return ($a && $_SESSION['wa_current_user']->can_access_page($a[2])) ? $a : false;
}

// ----------------------------------------------------------------- figures

// supp_trans: invoices are positive, credit notes and payments negative.
function ma_purchases_sql()
{
    $g = '(ov_amount+ov_gst+ov_discount)';
    return array(
        'gross' => $g,
        // Amount still open on a transaction, positive for money we owe.
        'open'  => 'IF(type IN ('.ST_SUPPINVOICE.','.ST_BANKDEPOSIT.'),'.$g.'-alloc,'.$g.'+alloc)',
        'isopen'=> 'ABS('.$g.')-alloc>'.FLOAT_COMP_DELTA,
    );
}

function ma_purchases_stat_values($tab, $today)
{
    $q = ma_purchases_sql();
    $asof = db_escape($today);
    $month = db_escape(substr($today, 0, 7).'-01');
    $one = function ($sql) { $r = ma_dashboard_rows($sql); return $r ? $r[0] : array(); };
    $money = function ($v) { return ma_dashboard_number($v, true); };
    $cur = get_company_pref('curr_default');
    $st = TB_PREF.'supp_trans';
    $g = $q['gross']; $isopen = $q['isopen'];
    $po = TB_PREF.'purch_orders'; $pod = TB_PREF.'purch_order_details';
    switch ($tab) {
        case 'orders':
            $r = $one('SELECT COUNT(*) n, COALESCE(SUM(total),0) v FROM '.$po);
            $o = $one('SELECT COUNT(DISTINCT o.order_no) n, COALESCE(SUM((d.quantity_ordered-d.quantity_received)*d.unit_price),0) v,
                COUNT(DISTINCT IF(d.delivery_date<'.$asof.',o.order_no,NULL)) late
                FROM '.$po.' o JOIN '.$pod.' d ON d.order_no=o.order_no WHERE d.quantity_ordered>d.quantity_received');
            return array(
                array(_('Total orders'), (int)$r['n'], _('All purchase orders'), 'cart', 'purple'),
                array(_('Total value'), $money($r['v']), sprintf(_('Ordered value, %s'), $cur), 'chart', 'blue'),
                array(_('Open orders'), (int)$o['n'], sprintf(_('%s still to receive'), $money($o['v'])), 'clock', 'teal'),
                array(_('Fully received'), max(0, (int)$r['n'] - (int)$o['n']), _('Nothing left to receive'), 'check', 'green'),
                array(_('Late'), (int)$o['late'], _('Open and past delivery date'), 'alert', 'red'),
            );
        case 'receiving':
            $o = $one('SELECT COUNT(DISTINCT o.order_no) n, COALESCE(SUM((d.quantity_ordered-d.quantity_received)*d.unit_price),0) v,
                COUNT(DISTINCT IF(d.delivery_date<'.$asof.',o.order_no,NULL)) late,
                COUNT(DISTINCT IF(d.delivery_date='.$asof.',o.order_no,NULL)) today
                FROM '.$po.' o JOIN '.$pod.' d ON d.order_no=o.order_no WHERE d.quantity_ordered>d.quantity_received');
            $m = $one('SELECT COUNT(*) n FROM '.TB_PREF.'grn_batch WHERE delivery_date>='.$month);
            $i = $one('SELECT COUNT(DISTINCT order_no) n FROM '.$pod.' WHERE quantity_received>qty_invoiced');
            return array(
                array(_('Awaiting receipt'), (int)$o['n'], _('Orders with items to receive'), 'package', 'purple'),
                array(_('Outstanding value'), $money($o['v']), sprintf(_('To receive, %s'), $cur), 'chart', 'blue'),
                array(_('Due today'), (int)$o['today'], _('Delivery expected today'), 'calendar', 'teal'),
                array(_('Late'), (int)$o['late'], _('Past delivery date'), 'alert', 'red'),
                array(_('Received, not invoiced'), (int)$i['n'], sprintf(_('%d receipts this month'), (int)$m['n']), 'clock', 'orange'),
            );
        case 'invoices':
            $r = $one('SELECT COUNT(*) n, COALESCE(SUM('.$g.'*rate),0) v, SUM('.$isopen.') unpaid,
                COALESCE(SUM(IF('.$isopen.','.$g.'-alloc,0)*rate),0) unpaid_v, SUM('.$isopen.' AND due_date<'.$asof.') late
                FROM '.$st.' WHERE type='.ST_SUPPINVOICE.' AND ov_amount!=0');
            return array(
                array(_('Total invoices'), (int)$r['n'], _('All supplier invoices'), 'receipt', 'purple'),
                array(_('Total billed'), $money($r['v']), sprintf(_('Invoiced, %s'), $cur), 'chart', 'blue'),
                array(_('Unpaid'), (int)$r['unpaid'], sprintf(_('%s outstanding'), $money($r['unpaid_v'])), 'clock', 'orange'),
                array(_('Overdue'), (int)$r['late'], _('Unpaid and past due date'), 'alert', 'red'),
                array(_('Paid'), max(0, (int)$r['n'] - (int)$r['unpaid']), _('Fully settled'), 'check', 'green'),
            );
        case 'credits':
            $r = $one('SELECT COUNT(*) n, COALESCE(SUM(ABS('.$g.')*rate),0) v, SUM('.$isopen.') open,
                COALESCE(SUM(IF('.$isopen.',ABS('.$g.')-alloc,0)*rate),0) open_v, SUM(tran_date>='.$month.') m
                FROM '.$st.' WHERE type='.ST_SUPPCREDIT.' AND ov_amount!=0');
            return array(
                array(_('Total credit notes'), (int)$r['n'], _('All supplier credit notes'), 'undo', 'purple'),
                array(_('Total value'), $money($r['v']), sprintf(_('Credited, %s'), $cur), 'chart', 'blue'),
                array(_('Unallocated'), (int)$r['open'], sprintf(_('%s not yet allocated'), $money($r['open_v'])), 'clock', 'orange'),
                array(_('Allocated'), max(0, (int)$r['n'] - (int)$r['open']), _('Fully allocated'), 'check', 'green'),
                array(_('This month'), (int)$r['m'], _('Credit notes this month'), 'calendar', 'teal'),
            );
        case 'payments':
            $r = $one('SELECT COUNT(*) n, COALESCE(SUM(ABS('.$g.')*rate),0) v,
                COALESCE(SUM(IF(tran_date>='.$month.',ABS('.$g.')*rate,0)),0) mv, SUM('.$isopen.') open
                FROM '.$st.' WHERE type='.ST_SUPPAYMENT.' AND ov_amount!=0');
            $n = (int)$r['n'];
            return array(
                array(_('Total payments'), $n, _('All supplier payments'), 'wallet', 'purple'),
                array(_('Total paid'), $money($r['v']), sprintf(_('Paid, %s'), $cur), 'chart', 'blue'),
                array(_('This month'), $money($r['mv']), _('Paid this month'), 'calendar', 'green'),
                array(_('Unallocated'), (int)$r['open'], _('Payments not fully allocated'), 'clock', 'orange'),
                array(_('Average payment'), $money($n ? $r['v'] / $n : 0), _('Value per payment'), 'check', 'teal'),
            );
        case 'allocations':
            $p = $one('SELECT COUNT(*) n, COALESCE(SUM((ABS('.$g.')-alloc)*rate),0) v FROM '.$st.' WHERE type='.ST_SUPPAYMENT.' AND '.$isopen);
            $c = $one('SELECT COUNT(*) n, COALESCE(SUM((ABS('.$g.')-alloc)*rate),0) v FROM '.$st.' WHERE type='.ST_SUPPCREDIT.' AND '.$isopen);
            $i = $one('SELECT COUNT(*) n, COALESCE(SUM(('.$g.'-alloc)*rate),0) v FROM '.$st.' WHERE type='.ST_SUPPINVOICE.' AND '.$isopen);
            return array(
                array(_('Payments to allocate'), (int)$p['n'], _('Payments with a balance'), 'wallet', 'purple'),
                array(_('Unallocated payments'), $money($p['v']), sprintf(_('Value, %s'), $cur), 'chart', 'blue'),
                array(_('Credit notes to allocate'), (int)$c['n'], sprintf(_('%s available'), $money($c['v'])), 'undo', 'orange'),
                array(_('Open invoices'), (int)$i['n'], _('Awaiting settlement'), 'receipt', 'teal'),
                array(_('Open balance'), $money($i['v']), sprintf(_('Invoices still unpaid, %s'), $cur), 'clock', 'red'),
            );
        case 'overdue':
            $week = db_escape(date('Y-m-d', strtotime($today.' +7 days')));
            $r = $one('SELECT COUNT(*) n, COALESCE(SUM(('.$g.'-alloc)*rate),0) v, SUM(due_date<'.$asof.') late,
                COALESCE(SUM(IF(due_date<'.$asof.',('.$g.'-alloc)*rate,0)),0) late_v,
                SUM(due_date>='.$asof.' AND due_date<='.$week.') soon,
                COALESCE(MAX(IF(due_date<'.$asof.',DATEDIFF('.$asof.',due_date),0)),0) oldest
                FROM '.$st.' WHERE type='.ST_SUPPINVOICE.' AND ov_amount!=0 AND '.$isopen);
            return array(
                array(_('Unpaid invoices'), (int)$r['n'], _('Awaiting payment'), 'receipt', 'purple'),
                array(_('Total payable'), $money($r['v']), sprintf(_('Outstanding, %s'), $cur), 'chart', 'blue'),
                array(_('Overdue'), (int)$r['late'], sprintf(_('%s past due'), $money($r['late_v'])), 'alert', 'red'),
                array(_('Due in 7 days'), (int)$r['soon'], _('Payable this week'), 'clock', 'orange'),
                array(_('Oldest overdue'), (int)$r['oldest'], _('Days past due'), 'calendar', 'teal'),
            );
    }
    return array();
}

function ma_purchases_stats($tab)
{
    if (!$_SESSION['wa_current_user']->can_access_page('SA_SUPPTRANSVIEW')) return;
    require_once __DIR__ . '/dashboard.inc';
    $stats = ma_purchases_stat_values($tab, date2sql(Today()));
    if (!$stats) return;
    echo '<section class="ma-sales-metrics ma-sales-stats">';
    foreach ($stats as $t)
        echo '<article class="ma-sales-metric tone-'.$t[4].'"><div class="ma-sales-metric-label"><span class="ma-sales-metric-icon">'.ma_ui_icon($t[3]).'</span>'
            .ma_ui_escape(mb_strtoupper($t[0])).'</div><div class="ma-sales-metric-value">'.ma_ui_escape(is_int($t[1]) ? number_format($t[1]) : $t[1])
            .'</div><p>'.ma_ui_escape($t[2]).'</p></article>';
    echo '</section>';
}

// ---------------------------------------------------------------- overview

function ma_purchases_overview()
{
    if (!$_SESSION['wa_current_user']->can_access_page('SA_SUPPTRANSVIEW')) {
        echo '<div class="ma-empty">'.ma_ui_escape(_('You do not have access to purchasing figures.')).'</div>';
        return;
    }
    require_once __DIR__ . '/dashboard.inc';
    $q = ma_purchases_sql();
    $g = $q['gross'];
    $today = date2sql(Today());
    $asof = db_escape($today);
    $month = db_escape(substr($today, 0, 7).'-01');
    $st = TB_PREF.'supp_trans';
    $cur = get_company_pref('curr_default');
    $num = function ($v) { return ma_dashboard_number($v, true); };
    $one = function ($sql) { $r = ma_dashboard_rows($sql); return $r ? $r[0] : array(); };

    $m = array();
    $m['purchases'] = ma_dashboard_scalar('SELECT COALESCE(SUM('.$g.'*rate),0) FROM '.$st.' WHERE type IN ('.ST_SUPPINVOICE.','.ST_SUPPCREDIT
        .') AND ov_amount!=0 AND tran_date BETWEEN '.$month.' AND '.$asof);
    $m['payable'] = ma_dashboard_scalar('SELECT COALESCE(SUM('.$q['open'].'*rate),0) FROM '.$st.' WHERE ov_amount!=0 AND tran_date<='.$asof
        .' AND '.$q['isopen']);
    $od = $one('SELECT COUNT(*) n, COALESCE(SUM(('.$g.'-alloc)*rate),0) v FROM '.$st.' WHERE type='.ST_SUPPINVOICE
        .' AND ov_amount!=0 AND due_date<'.$asof.' AND '.$q['isopen']);
    $oo = $one('SELECT COUNT(DISTINCT o.order_no) n, COALESCE(SUM((d.quantity_ordered-d.quantity_received)*d.unit_price),0) v
        FROM '.TB_PREF.'purch_orders o JOIN '.TB_PREF.'purch_order_details d ON d.order_no=o.order_no WHERE d.quantity_ordered>d.quantity_received');
    $pm = $one('SELECT COUNT(*) n, COALESCE(SUM(ABS('.$g.')*rate),0) v FROM '.$st.' WHERE type='.ST_SUPPAYMENT
        .' AND ov_amount!=0 AND tran_date BETWEEN '.$month.' AND '.$asof);
    $suppliers = (int)ma_dashboard_scalar('SELECT COUNT(*) FROM '.TB_PREF.'suppliers WHERE inactive=0');

    $series = array();
    for ($i = 5; $i >= 0; $i--) $series[date('Y-m', strtotime(substr($today, 0, 7).'-01 -'.$i.' months'))] = 0;
    foreach (ma_dashboard_rows('SELECT DATE_FORMAT(tran_date,"%Y-%m") p, SUM('.$g.'*rate) v FROM '.$st.' WHERE type IN ('.ST_SUPPINVOICE.','.ST_SUPPCREDIT
        .') AND ov_amount!=0 AND tran_date BETWEEN '.db_escape(date('Y-m-01', strtotime($today.' -5 months'))).' AND '.$asof.' GROUP BY p') as $row)
        if (isset($series[$row['p']])) $series[$row['p']] = (float)$row['v'];

    $top = ma_dashboard_rows('SELECT s.supplier_id, s.supp_name, SUM(IF(t.type='.ST_SUPPINVOICE.',(t.ov_amount+t.ov_gst+t.ov_discount)*t.rate,0)) billed,
        SUM(IF(t.type IN ('.ST_SUPPINVOICE.','.ST_BANKDEPOSIT.'),(t.ov_amount+t.ov_gst+t.ov_discount)-t.alloc,(t.ov_amount+t.ov_gst+t.ov_discount)+t.alloc)*t.rate) balance
        FROM '.$st.' t JOIN '.TB_PREF.'suppliers s ON s.supplier_id=t.supplier_id WHERE t.ov_amount!=0 AND t.tran_date<='.$asof.'
        GROUP BY s.supplier_id, s.supp_name HAVING billed>0 ORDER BY billed DESC LIMIT 5');
    $recent = ma_dashboard_rows('SELECT t.trans_no, t.reference, t.supp_reference, s.supp_name, t.tran_date, t.due_date,
        (t.ov_amount+t.ov_gst+t.ov_discount)*t.rate amount, ((t.ov_amount+t.ov_gst+t.ov_discount)-t.alloc) due
        FROM '.$st.' t JOIN '.TB_PREF.'suppliers s ON s.supplier_id=t.supplier_id WHERE t.type='.ST_SUPPINVOICE.' AND t.ov_amount!=0
        ORDER BY t.tran_date DESC, t.trans_no DESC LIMIT 8');

    $tiles = array(
        array(_('Purchases (month)'), $num($m['purchases']), sprintf(_('%s invoiced this month'), $cur), 'chart', 'blue'),
        array(_('Payables'), $num($m['payable']), _('Total owed to suppliers'), 'wallet', 'purple'),
        array(_('Overdue'), $num($od['v']), (int)$od['n'] ? sprintf(_('%d invoices past due'), (int)$od['n']) : _('All invoices on time'), 'alert', 'red'),
        array(_('Open orders'), $num($oo['v']), sprintf(_('Value of %d orders to receive'), (int)$oo['n']), 'clock', 'orange'),
        array(_('Payments (month)'), $num($pm['v']), sprintf(_('%d payments this month'), (int)$pm['n']), 'check', 'green'),
        array(_('Suppliers'), number_format($suppliers), _('Active supplier accounts'), 'people', 'teal'),
    );
    echo '<section class="ma-sales-metrics">';
    foreach ($tiles as $t)
        echo '<article class="ma-sales-metric tone-'.$t[4].'"><div class="ma-sales-metric-label"><span class="ma-sales-metric-icon">'.ma_ui_icon($t[3]).'</span>'
            .ma_ui_escape(mb_strtoupper($t[0])).'</div><div class="ma-sales-metric-value">'.ma_ui_escape($t[1]).'</div><p>'.ma_ui_escape($t[2]).'</p></article>';
    echo '</section><div class="ma-sales-columns"><section class="ma-panel"><div class="ma-panel-head"><h2>'.ma_ui_escape(_('Monthly Purchases')).'</h2><span>'
        .ma_ui_escape(_('Last 6 months')).' &middot; '.ma_ui_escape($cur).'</span></div>';
    ma_sales_chart($series);
    echo '</section><section class="ma-panel"><div class="ma-panel-head"><h2>'.ma_ui_escape(_('Top Suppliers')).'</h2><a class="ma-sales-link" href="'
        .ma_ui_escape(ma_ui_href('purchasing/manage/suppliers.php')).'">'.ma_ui_escape(_('View all')).' &rsaquo;</a></div>';
    if (!$top) echo '<div class="ma-empty">'.ma_ui_escape(_('No supplier invoices yet.')).'</div>';
    else {
        echo '<div class="ma-table-wrap"><table class="ma-sales-table"><thead><tr><th>'.ma_ui_escape(_('Supplier')).'</th><th class="num">'
            .ma_ui_escape(_('Billed')).'</th><th class="num">'.ma_ui_escape(_('Balance')).'</th></tr></thead><tbody>';
        foreach ($top as $row)
            echo '<tr><td>'.ma_ui_escape($row['supp_name']).'</td><td class="num">'.ma_ui_escape($num($row['billed'])).'</td><td class="num'
                .((float)$row['balance'] > 0.005 ? ' due' : '').'">'.ma_ui_escape($num($row['balance'])).'</td></tr>';
        echo '</tbody></table></div>';
    }
    echo '</section></div><section class="ma-panel"><div class="ma-panel-head"><h2>'.ma_ui_escape(_('Recent Supplier Invoices')).'</h2><a class="ma-sales-link" href="'
        .ma_ui_escape(ma_ui_href('purchasing/inquiry/supplier_inquiry.php?filterType=1')).'">'.ma_ui_escape(_('View all')).' &rsaquo;</a></div>';
    if (!$recent) echo '<div class="ma-empty">'.ma_ui_escape(_('No supplier invoices yet.')).'</div>';
    else {
        echo '<div class="ma-table-wrap"><table class="ma-sales-table"><thead><tr><th>#</th><th>'.ma_ui_escape(_('Supplier')).'</th><th>'
            .ma_ui_escape(_('Date')).'</th><th>'.ma_ui_escape(_('Due')).'</th><th class="num">'.ma_ui_escape(_('Amount')).'</th><th>'
            .ma_ui_escape(_('Status')).'</th></tr></thead><tbody>';
        foreach ($recent as $row) {
            $paid = (float)$row['due'] <= FLOAT_COMP_DELTA;
            $late = !$paid && $row['due_date'] < $today;
            $status = $paid ? array(_('Paid'), 'paid') : ($late ? array(_('Overdue'), 'late') : array(_('Open'), 'open'));
            $view = ma_ui_href('purchasing/view/view_supp_invoice.php?trans_no='.(int)$row['trans_no']);
            echo '<tr><td><a href="'.ma_ui_escape($view).'">'.ma_ui_escape($row['reference'] ?: '#'.$row['trans_no']).'</a></td><td>'.ma_ui_escape($row['supp_name'])
                .'</td><td>'.ma_ui_escape(sql2date($row['tran_date'])).'</td><td>'.ma_ui_escape(sql2date($row['due_date'])).'</td><td class="num"><strong>'
                .ma_ui_escape($num($row['amount'])).'</strong></td><td><span class="ma-pill '.$status[1].'">'.ma_ui_escape($status[0]).'</span></td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</section>';
}
