<?php
// Sales workspace: one toolbar (tabs, New, Reports, Settings) shown on every
// Sales screen, plus a live overview. Tabs lead to FA's own list screens, so
// posting, approvals and eTIMS behaviour are untouched.
require_once __DIR__ . '/workspace.inc';

define('MA_SALES_APP', 'orders');

function ma_sales_tabs()
{
    return array(
        'overview'   => array(_('Overview'),    'grid',    'index.php?application=orders', null),
        'quotations' => array(_('Quotations'),  'file',    'sales/inquiry/sales_orders_view.php?type='.ST_SALESQUOTE, 'SA_SALESTRANSVIEW'),
        'orders'     => array(_('Orders'),      'cart',    'sales/inquiry/sales_orders_view.php?type='.ST_SALESORDER, 'SA_SALESTRANSVIEW'),
        'deliveries' => array(_('Deliveries'),  'truck',   'sales/inquiry/sales_deliveries_view.php', 'SA_SALESTRANSVIEW'),
        'invoices'   => array(_('Invoices'),    'receipt', 'sales/inquiry/customer_inquiry.php?filterType=1', 'SA_SALESTRANSVIEW'),
        'credits'    => array(_('Credit Notes'),'undo',    'sales/inquiry/customer_inquiry.php?filterType=4', 'SA_SALESTRANSVIEW'),
        'payments'   => array(_('Payments'),    'wallet',  'sales/inquiry/customer_inquiry.php?filterType=3', 'SA_SALESTRANSVIEW'),
        'allocations'=> array(_('Allocations'), 'swap',    'sales/allocations/customer_allocation_main.php', 'SA_SALESALLOC'),
        'reminders'  => array(_('Reminders'),   'bell',    'sales/inquiry/customer_inquiry.php?filterType=2', 'SA_SALESTRANSVIEW'),
    );
}

// Which tab (or 'reports' / 'settings') the current screen belongs to.
function ma_sales_active_tab()
{
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $name = basename($script);
    $dir = basename(dirname($script));
    $get = function ($key) { return is_string($_GET[$key] ?? null) ? $_GET[$key] : null; };
    if ($name === 'index.php') return $get('application') === MA_SALES_APP ? 'overview' : null;
    if ($name === 'reports_main.php') return $get('Class') === '0' ? 'reports' : null;
    if ($dir === 'manage') return strpos($script, '/sales/') !== false ? 'settings' : null;
    if (strpos($script, '/sales/') === false) return null;
    switch ($name) {
        case 'sales_orders_view.php':
            return $get('type') == ST_SALESQUOTE ? 'quotations' : 'orders';
        case 'sales_deliveries_view.php': case 'customer_delivery.php':
            return 'deliveries';
        case 'customer_inquiry.php':
            $filter = $_POST['filterType'] ?? $get('filterType');
            return array('2'=>'reminders', '3'=>'payments', '4'=>'credits')[$filter] ?? 'invoices';
        case 'customer_invoice.php': case 'create_recurrent_invoices.php':
            return 'invoices';
        case 'credit_note_entry.php':
            return 'credits';
        case 'customer_payments.php':
            return 'payments';
        case 'customer_allocation_main.php': case 'customer_allocation_inquiry.php': case 'allocate_payments.php':
            return 'allocations';
        case 'sales_order_entry.php':
            foreach (array('NewQuotation'=>'quotations', 'ModifyQuotationNumber'=>'quotations', 'AddedQU'=>'quotations', 'UpdatedQU'=>'quotations',
                'NewOrder'=>'orders', 'ModifyOrderNumber'=>'orders', 'AddedID'=>'orders', 'UpdatedID'=>'orders', 'NewQuoteToSalesOrder'=>'orders',
                'NewDelivery'=>'deliveries', 'AddedDN'=>'deliveries', 'NewInvoice'=>'invoices', 'AddedDI'=>'invoices') as $key=>$tab)
                if (isset($_GET[$key])) return $tab;
            $type = $_SESSION['Items']->trans_type ?? null;
            return array(ST_SALESQUOTE=>'quotations', ST_SALESORDER=>'orders', ST_CUSTDELIVERY=>'deliveries', ST_SALESINVOICE=>'invoices')[$type] ?? null;
    }
    return null;
}

function ma_sales_is_sales_screen()
{
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    return ma_sales_active_tab() !== null || strpos($script, '/sales/') !== false;
}

// Accessible links of one of the Sales application's three FA modules
// (0 transactions, 1 inquiries and reports, 2 maintenance).
function ma_sales_module_links($index)
{
    $app = $_SESSION['App']->applications[MA_SALES_APP] ?? null;
    $links = array();
    if (!$app || !isset($app->modules[$index])) return $links;
    $module = $app->modules[$index];
    foreach (array_merge($module->lappfunctions, $module->rappfunctions) as $item) {
        if (!$item->label || !$item->link || !$_SESSION['wa_current_user']->can_access_page($item->access)) continue;
        if ($index === 2 && preg_match('~sales/manage/(customers|customer_branches)\.php~', $item->link)) continue;
        $links[] = array('label'=>ma_ui_label($item->label), 'link'=>$item->link);
    }
    return $links;
}

function ma_sales_menu($label, $icon, $links, $primary = false)
{
    if (!$links) return;
    echo '<details class="ma-dropdown ma-sales-menu'.($primary ? ' is-primary' : '').'"><summary>'.ma_ui_icon($icon).' '.ma_ui_escape($label).' '.ma_ui_icon('chevron')
        .'</summary><div class="ma-dropdown-panel">';
    foreach ($links as $item)
        echo '<a href="'.ma_ui_escape(ma_ui_href($item['link'])).'">'.ma_ui_escape($item['label']).'</a>';
    echo '</div></details>';
}

function ma_sales_toolbar()
{
    $active = ma_sales_active_tab();
    echo '<div class="ma-sales-toolbar"><nav class="ma-sales-tabs" aria-label="'.ma_ui_escape(_('Sales sections')).'">';
    foreach (ma_sales_tabs() as $key=>$tab) {
        if ($tab[3] && !$_SESSION['wa_current_user']->can_access_page($tab[3])) continue;
        echo '<a class="ma-sales-tab'.($key === $active ? ' active' : '').'"'.($key === $active ? ' aria-current="page"' : '')
            .' href="'.ma_ui_escape(ma_ui_href($tab[2])).'">'.ma_ui_icon($tab[1]).'<span>'.ma_ui_escape($tab[0]).'</span></a>';
    }
    echo '</nav><div class="ma-sales-actions">';
    ma_sales_menu(_('Reports'), 'chart', ma_sales_module_links(1));
    ma_sales_menu(_('Settings'), 'settings', ma_sales_module_links(2));
    echo '</div></div>';
}

// ---------------------------------------------------------------- overview

function ma_sales_model($today)
{
    require_once __DIR__ . '/dashboard.inc';
    $asof = db_escape($today);
    $month = db_escape(substr($today, 0, 7).'-01');
    $gross = '(ov_amount+ov_gst+ov_freight+ov_freight_tax+ov_discount)';
    $net = '(ov_amount+ov_discount)*rate*IF(type='.ST_CUSTCREDIT.',-1,1)';
    $signed = 'IF(type IN ('.ST_CUSTCREDIT.','.ST_CUSTPAYMENT.','.ST_BANKDEPOSIT.'),-1,1)*('.$gross.'-alloc)*rate';
    $open = ' AND ABS('.$gross.')-alloc>'.FLOAT_COMP_DELTA;
    $m = array();
    $m['revenue'] = ma_dashboard_scalar('SELECT COALESCE(SUM('.$net.'),0) FROM '.TB_PREF.'debtor_trans WHERE type IN ('
        .ST_SALESINVOICE.','.ST_CUSTCREDIT.') AND tran_date BETWEEN '.$month.' AND '.$asof);
    $m['receivable'] = ma_dashboard_scalar('SELECT COALESCE(SUM('.$signed.'),0) FROM '.TB_PREF.'debtor_trans WHERE type<>'
        .ST_CUSTDELIVERY.' AND tran_date<='.$asof.$open);
    $od = ma_dashboard_rows('SELECT COUNT(*) n, COALESCE(SUM(('.$gross.'-alloc)*rate),0) amount FROM '.TB_PREF.'debtor_trans
        WHERE type='.ST_SALESINVOICE.' AND due_date<'.$asof.$open);
    $m['overdue'] = (float)$od[0]['amount']; $m['overdue_n'] = (int)$od[0]['n'];
    $oo = ma_dashboard_rows('SELECT COUNT(DISTINCT o.order_no) n, COALESCE(SUM((d.quantity-d.qty_sent)*d.unit_price*(1-d.discount_percent)),0) amount
        FROM '.TB_PREF.'sales_orders o JOIN '.TB_PREF.'sales_order_details d ON d.order_no=o.order_no AND d.trans_type=o.trans_type
        WHERE o.trans_type='.ST_SALESORDER.' AND d.quantity>d.qty_sent');
    $m['orders'] = (float)$oo[0]['amount']; $m['orders_n'] = (int)$oo[0]['n'];
    $pm = ma_dashboard_rows('SELECT COUNT(*) n, COALESCE(SUM(ov_amount*rate),0) amount FROM '.TB_PREF.'debtor_trans
        WHERE type='.ST_CUSTPAYMENT.' AND tran_date BETWEEN '.$month.' AND '.$asof);
    $m['payments'] = (float)$pm[0]['amount']; $m['payments_n'] = (int)$pm[0]['n'];
    $m['customers'] = (int)ma_dashboard_scalar('SELECT COUNT(*) FROM '.TB_PREF.'debtors_master WHERE inactive=0');
    $from = db_escape(date('Y-m-01', strtotime($today.' -5 months')));
    $m['series'] = array();
    for ($i = 5; $i >= 0; $i--) $m['series'][date('Y-m', strtotime(substr($today, 0, 7).'-01 -'.$i.' months'))] = 0;
    foreach (ma_dashboard_rows('SELECT DATE_FORMAT(tran_date,"%Y-%m") p, SUM('.$net.') v FROM '.TB_PREF.'debtor_trans WHERE type IN ('
        .ST_SALESINVOICE.','.ST_CUSTCREDIT.') AND tran_date BETWEEN '.$from.' AND '.$asof.' GROUP BY p') as $row)
        if (isset($m['series'][$row['p']])) $m['series'][$row['p']] = (float)$row['v'];
    // The same expressions with a table alias, for the joined queries below.
    $g = function ($t) { return '('.$t.'ov_amount+'.$t.'ov_gst+'.$t.'ov_freight+'.$t.'ov_freight_tax+'.$t.'ov_discount)'; };
    $m['top'] = ma_dashboard_rows('SELECT c.debtor_no, c.name,
        SUM(IF(t.type='.ST_SALESINVOICE.','.$g('t.').'*t.rate,0)) billed,
        SUM(IF(t.type IN ('.ST_CUSTCREDIT.','.ST_CUSTPAYMENT.','.ST_BANKDEPOSIT.'),-1,1)*('.$g('t.').'-t.alloc)*t.rate) balance
        FROM '.TB_PREF.'debtor_trans t JOIN '.TB_PREF.'debtors_master c ON c.debtor_no=t.debtor_no
        WHERE t.type<>'.ST_CUSTDELIVERY.' AND t.tran_date<='.$asof.'
        GROUP BY c.debtor_no, c.name HAVING billed>0 ORDER BY billed DESC LIMIT 5');
    $m['recent'] = ma_dashboard_rows('SELECT t.trans_no, t.reference, c.name, t.tran_date, t.due_date, '.$g('t.').'*t.rate amount,
        ('.$g('t.').'-t.alloc) due FROM '.TB_PREF.'debtor_trans t JOIN '.TB_PREF.'debtors_master c ON c.debtor_no=t.debtor_no
        WHERE t.type='.ST_SALESINVOICE.' ORDER BY t.tran_date DESC, t.trans_no DESC LIMIT 8');
    return $m;
}

function ma_sales_chart($series)
{
    $max = max(1, max($series));
    echo '<div class="ma-sales-bars" role="img" aria-label="'.ma_ui_escape(_('Monthly net revenue for the last six months')).'">';
    foreach ($series as $period=>$value) {
        $h = max(0, round($value / $max * 100, 1));
        echo '<div class="ma-sales-bar-slot">'.($value > 0
            ? '<div class="ma-sales-bar" style="height:'.$h.'%"><strong>'.ma_ui_escape(ma_dashboard_number($value, false)).'</strong></div>' : '').'<span>'
            .ma_ui_escape(date('M y', strtotime($period.'-01'))).'</span></div>';
    }
    echo '</div>';
}

function ma_sales_overview()
{
    global $path_to_root;
    if (!$_SESSION['wa_current_user']->can_access_page('SA_SALESTRANSVIEW')) {
        echo '<div class="ma-empty">'.ma_ui_escape(_('You do not have access to sales figures.')).'</div>';
        return;
    }
    $today = date2sql(Today());
    $m = ma_sales_model($today);
    $cur = get_company_pref('curr_default');
    $num = function ($v) { return ma_dashboard_number($v, true); };
    $tiles = array(
        array(_('Revenue (month)'), $num($m['revenue']), sprintf(_('%s billed this month'), $cur), 'chart', 'blue'),
        array(_('Receivables'), $num($m['receivable']), _('Total outstanding'), 'wallet', 'purple'),
        array(_('Overdue'), $num($m['overdue']), $m['overdue_n'] ? sprintf(_('%d invoices past due'), $m['overdue_n']) : _('All invoices on time'), 'alert', 'red'),
        array(_('Open orders'), $num($m['orders']), sprintf(_('Value of %d pending orders'), $m['orders_n']), 'clock', 'orange'),
        array(_('Payments (month)'), $num($m['payments']), sprintf(_('%d receipts this month'), $m['payments_n']), 'check', 'green'),
        array(_('Customers'), number_format($m['customers']), _('Active customer accounts'), 'people', 'teal'),
    );
    echo '<section class="ma-sales-metrics">';
    foreach ($tiles as $t)
        echo '<article class="ma-sales-metric tone-'.$t[4].'"><div class="ma-sales-metric-label"><span class="ma-sales-metric-icon">'.ma_ui_icon($t[3]).'</span>'
            .ma_ui_escape(mb_strtoupper($t[0])).'</div><div class="ma-sales-metric-value">'.ma_ui_escape($t[1]).'</div><p>'.ma_ui_escape($t[2]).'</p></article>';
    echo '</section><div class="ma-sales-columns">';
    echo '<section class="ma-panel"><div class="ma-panel-head"><h2>'.ma_ui_escape(_('Monthly Revenue')).'</h2><span>'
        .ma_ui_escape(sprintf(_('Last 6 months')).' ').'&middot; '.ma_ui_escape($cur).'</span></div>';
    ma_sales_chart($m['series']);
    echo '</section><section class="ma-panel"><div class="ma-panel-head"><h2>'.ma_ui_escape(_('Top Customers')).'</h2><a class="ma-sales-link" href="'
        .ma_ui_escape(ma_ui_href('sales/manage/customers.php')).'">'.ma_ui_escape(_('View all')).' &rsaquo;</a></div>';
    if (!$m['top']) echo '<div class="ma-empty">'.ma_ui_escape(_('No invoices yet.')).'</div>';
    else {
        echo '<div class="ma-table-wrap"><table class="ma-sales-table"><thead><tr><th>'.ma_ui_escape(_('Customer')).'</th><th class="num">'
            .ma_ui_escape(_('Billed')).'</th><th class="num">'.ma_ui_escape(_('Balance')).'</th></tr></thead><tbody>';
        foreach ($m['top'] as $row)
            echo '<tr><td>'.ma_ui_escape($row['name']).'</td><td class="num">'.ma_ui_escape($num($row['billed'])).'</td><td class="num'
                .((float)$row['balance'] > 0.005 ? ' due' : '').'">'.ma_ui_escape($num($row['balance'])).'</td></tr>';
        echo '</tbody></table></div>';
    }
    echo '</section></div><section class="ma-panel"><div class="ma-panel-head"><h2>'.ma_ui_escape(_('Recent Invoices')).'</h2><a class="ma-sales-link" href="'
        .ma_ui_escape(ma_ui_href('sales/inquiry/customer_inquiry.php?filterType=1')).'">'.ma_ui_escape(_('View all')).' &rsaquo;</a></div>';
    if (!$m['recent']) echo '<div class="ma-empty">'.ma_ui_escape(_('No invoices yet.')).'</div>';
    else {
        echo '<div class="ma-table-wrap"><table class="ma-sales-table"><thead><tr><th>#</th><th>'.ma_ui_escape(_('Customer')).'</th><th>'
            .ma_ui_escape(_('Date')).'</th><th>'.ma_ui_escape(_('Due')).'</th><th class="num">'.ma_ui_escape(_('Amount')).'</th><th>'
            .ma_ui_escape(_('Status')).'</th></tr></thead><tbody>';
        foreach ($m['recent'] as $row) {
            $paid = (float)$row['due'] <= FLOAT_COMP_DELTA;
            $late = !$paid && $row['due_date'] < $today;
            $status = $paid ? array(_('Paid'), 'paid') : ($late ? array(_('Overdue'), 'late') : array(_('Open'), 'open'));
            $view = ma_ui_href('sales/view/view_invoice.php?trans_no='.(int)$row['trans_no'].'&trans_type='.ST_SALESINVOICE);
            echo '<tr><td><a href="'.ma_ui_escape($view).'">'.ma_ui_escape($row['reference'] ?: '#'.$row['trans_no']).'</a></td><td>'.ma_ui_escape($row['name'])
                .'</td><td>'.ma_ui_escape(sql2date($row['tran_date'])).'</td><td>'.ma_ui_escape(sql2date($row['due_date'])).'</td><td class="num"><strong>'
                .ma_ui_escape($num($row['amount'])).'</strong></td><td><span class="ma-pill '.$status[1].'">'.ma_ui_escape($status[0]).'</span></td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</section>';
}


// ----------------------------------------------------- top bar "+ New" menu

function ma_sales_topbar_new()
{
    ma_sales_menu(_('New'), 'plus', ma_sales_module_links(0), true);
}

// ------------------------------------------------------------ list screens

// Screens that are lists of documents and get stat cards, a filter bar and a
// "New ..." button; entry forms only get the tab toolbar.
function ma_sales_is_list_screen()
{
    $name = basename($_SERVER['SCRIPT_NAME'] ?? '');
    return in_array($name, array('sales_orders_view.php', 'sales_deliveries_view.php', 'customer_inquiry.php',
        'customer_allocation_main.php'), true) && ma_sales_active_tab() !== null;
}

// Primary "New ..." action for each tab: label, link, permission.
function ma_sales_new_action($tab)
{
    $actions = array(
        'quotations' => array(_('New Quotation'), 'sales/sales_order_entry.php?NewQuotation=Yes', 'SA_SALESQUOTE'),
        'orders'     => array(_('New Order'), 'sales/sales_order_entry.php?NewOrder=Yes', 'SA_SALESORDER'),
        'deliveries' => array(_('New Delivery'), 'sales/sales_order_entry.php?NewDelivery=0', 'SA_SALESDELIVERY'),
        'invoices'   => array(_('New Invoice'), 'sales/sales_order_entry.php?NewInvoice=0', 'SA_SALESINVOICE'),
        'payments'   => array(_('New Payment'), 'sales/customer_payments.php', 'SA_SALESPAYMNT'),
    );
    $a = $actions[$tab] ?? null;
    return ($a && $_SESSION['wa_current_user']->can_access_page($a[2])) ? $a : null;
}

function ma_sales_filter_start()
{
    echo '<div class="ma-sales-filters">';
}

// One labelled filter field. $render echoes one of FA's cell helpers called
// with a null label; its <td> wrapper is dropped so the control keeps its
// name, state and AJAX behaviour.
function ma_sales_field($label, $render, $class = '')
{
    ob_start();
    $render();
    $html = preg_replace('~</?td[^>]*>~i', '', ob_get_clean());
    echo '<div class="ma-sales-field'.($class ? ' '.$class : '').'">'
        .($label !== '' ? '<span class="ma-sales-field-label">'.ma_ui_escape($label).'</span>' : '').'<div>'.$html.'</div></div>';
}

function ma_sales_filter_end($search_name, $search_hint, $new_action = false)
{
    echo '<div class="ma-sales-filter-actions">';
    ob_start();
    submit_cells($search_name, _('Search'), '', $search_hint, 'default');
    $search = preg_replace('~</?td[^>]*>~i', '', ob_get_clean());
    echo preg_replace('~<img[^>]*>~i', ma_ui_icon('search'), $search);
    $tab = ma_sales_active_tab();
    $new = $new_action !== false ? $new_action : ma_sales_new_action($tab);
    if ($new)
        echo '<a class="ma-sales-new" href="'.ma_ui_escape(ma_ui_href($new[1])).'">'.ma_ui_icon('plus').' '.ma_ui_escape($new[0]).'</a>';
    elseif ($tab === 'credits' && $new_action === false)
        echo '<span class="ma-sales-hint">'.ma_ui_escape(_('Credit notes are raised against an invoice: open Invoices and choose Credit This.')).' <a href="'
            .ma_ui_escape(ma_ui_href('sales/inquiry/customer_inquiry.php?filterType=1')).'">'.ma_ui_escape(_('Go to Invoices')).' &rsaquo;</a></span>';
    echo '</div></div>';
}

// ---------------------------------------------------------------- stat cards

function ma_sales_stat_values($tab, $today)
{
    $asof = db_escape($today);
    $month = db_escape(substr($today, 0, 7).'-01');
    $gross = '(ov_amount+ov_gst+ov_freight+ov_freight_tax+ov_discount)';
    $unpaid = ' AND '.$gross.'-alloc>'.FLOAT_COMP_DELTA;
    $one = function ($sql) { $r = ma_dashboard_rows($sql); return $r ? $r[0] : array(); };
    $money = function ($v) { return ma_dashboard_number($v, true); };
    $cur = get_company_pref('curr_default');
    $dt = TB_PREF.'debtor_trans';
    switch ($tab) {
        case 'quotations':
            $r = $one('SELECT COUNT(*) n, COALESCE(SUM(total),0) v, SUM(delivery_date>='.$asof.') open, SUM(delivery_date<'.$asof.') expired
                FROM '.TB_PREF.'sales_orders WHERE trans_type='.ST_SALESQUOTE);
            $n = (int)$r['n'];
            return array(
                array(_('Total quotations'), $n, _('All quotations raised'), 'file', 'purple'),
                array(_('Total value'), $money($r['v']), sprintf(_('Quoted value, %s'), $cur), 'chart', 'blue'),
                array(_('Open quotes'), (int)$r['open'], _('Valid until today or later'), 'clock', 'teal'),
                array(_('Expired'), (int)$r['expired'], _('Past valid-until date'), 'alert', 'red'),
                array(_('Average quote'), $money($n ? $r['v'] / $n : 0), _('Value per quotation'), 'wallet', 'green'),
            );
        case 'orders':
            $r = $one('SELECT COUNT(*) n, COALESCE(SUM(o.total),0) v FROM '.TB_PREF.'sales_orders o WHERE o.trans_type='.ST_SALESORDER);
            $o = $one('SELECT COUNT(DISTINCT o.order_no) n, COALESCE(SUM((d.quantity-d.qty_sent)*d.unit_price*(1-d.discount_percent)),0) v,
                COUNT(DISTINCT IF(o.delivery_date<'.$asof.',o.order_no,NULL)) late
                FROM '.TB_PREF.'sales_orders o JOIN '.TB_PREF.'sales_order_details d ON d.order_no=o.order_no AND d.trans_type=o.trans_type
                WHERE o.trans_type='.ST_SALESORDER.' AND d.quantity>d.qty_sent');
            return array(
                array(_('Total orders'), (int)$r['n'], _('All sales orders'), 'cart', 'purple'),
                array(_('Total value'), $money($r['v']), sprintf(_('Ordered value, %s'), $cur), 'chart', 'blue'),
                array(_('Open orders'), (int)$o['n'], sprintf(_('%s still to deliver'), $money($o['v'])), 'clock', 'teal'),
                array(_('Fully delivered'), max(0, (int)$r['n'] - (int)$o['n']), _('Nothing left to deliver'), 'check', 'green'),
                array(_('Late'), (int)$o['late'], _('Open and past required date'), 'alert', 'red'),
            );
        case 'deliveries':
            $r = $one('SELECT COUNT(*) n, COALESCE(SUM('.$gross.'*rate),0) v, SUM(tran_date>='.$month.') m FROM '.$dt.' WHERE type='.ST_CUSTDELIVERY);
            $o = $one('SELECT COUNT(DISTINCT t.trans_no) n FROM '.$dt.' t JOIN '.TB_PREF.'debtor_trans_details d ON d.debtor_trans_no=t.trans_no
                AND d.debtor_trans_type=t.type WHERE t.type='.ST_CUSTDELIVERY.' AND d.quantity>d.qty_done');
            return array(
                array(_('Total deliveries'), (int)$r['n'], _('All delivery notes'), 'truck', 'purple'),
                array(_('Total value'), $money($r['v']), sprintf(_('Delivered value, %s'), $cur), 'chart', 'blue'),
                array(_('Not invoiced'), (int)$o['n'], _('Awaiting an invoice'), 'clock', 'orange'),
                array(_('Invoiced'), max(0, (int)$r['n'] - (int)$o['n']), _('Fully invoiced'), 'check', 'green'),
                array(_('This month'), (int)$r['m'], _('Deliveries this month'), 'calendar', 'teal'),
            );
        case 'invoices':
            $r = $one('SELECT COUNT(*) n, COALESCE(SUM('.$gross.'*rate),0) v, SUM('.$gross.'-alloc>'.FLOAT_COMP_DELTA.') unpaid,
                COALESCE(SUM(IF('.$gross.'-alloc>'.FLOAT_COMP_DELTA.','.$gross.'-alloc,0)*rate),0) unpaid_v,
                SUM('.$gross.'-alloc>'.FLOAT_COMP_DELTA.' AND due_date<'.$asof.') late FROM '.$dt.' WHERE type='.ST_SALESINVOICE);
            return array(
                array(_('Total invoices'), (int)$r['n'], _('All sales invoices'), 'receipt', 'purple'),
                array(_('Total billed'), $money($r['v']), sprintf(_('Invoiced, %s'), $cur), 'chart', 'blue'),
                array(_('Unpaid'), (int)$r['unpaid'], sprintf(_('%s outstanding'), $money($r['unpaid_v'])), 'clock', 'orange'),
                array(_('Overdue'), (int)$r['late'], _('Unpaid and past due date'), 'alert', 'red'),
                array(_('Paid'), max(0, (int)$r['n'] - (int)$r['unpaid']), _('Fully settled'), 'check', 'green'),
            );
        case 'credits':
            $r = $one('SELECT COUNT(*) n, COALESCE(SUM('.$gross.'*rate),0) v, SUM('.$gross.'-alloc>'.FLOAT_COMP_DELTA.') open,
                COALESCE(SUM(IF('.$gross.'-alloc>'.FLOAT_COMP_DELTA.','.$gross.'-alloc,0)*rate),0) open_v, SUM(tran_date>='.$month.') m
                FROM '.$dt.' WHERE type='.ST_CUSTCREDIT);
            return array(
                array(_('Total credit notes'), (int)$r['n'], _('All credit notes'), 'undo', 'purple'),
                array(_('Total value'), $money($r['v']), sprintf(_('Credited, %s'), $cur), 'chart', 'blue'),
                array(_('Unallocated'), (int)$r['open'], sprintf(_('%s not yet allocated'), $money($r['open_v'])), 'clock', 'orange'),
                array(_('Allocated'), max(0, (int)$r['n'] - (int)$r['open']), _('Fully allocated'), 'check', 'green'),
                array(_('This month'), (int)$r['m'], _('Credit notes this month'), 'calendar', 'teal'),
            );
        case 'payments':
            $r = $one('SELECT COUNT(*) n, COALESCE(SUM(ov_amount*rate),0) v, COALESCE(SUM(IF(tran_date>='.$month.',ov_amount*rate,0)),0) mv,
                SUM(ov_amount+ov_discount-alloc>'.FLOAT_COMP_DELTA.') open FROM '.$dt.' WHERE type='.ST_CUSTPAYMENT);
            $n = (int)$r['n'];
            return array(
                array(_('Total payments'), $n, _('All customer receipts'), 'wallet', 'purple'),
                array(_('Total received'), $money($r['v']), sprintf(_('Received, %s'), $cur), 'chart', 'blue'),
                array(_('This month'), $money($r['mv']), _('Received this month'), 'calendar', 'green'),
                array(_('Unallocated'), (int)$r['open'], _('Receipts not fully allocated'), 'clock', 'orange'),
                array(_('Average receipt'), $money($n ? $r['v'] / $n : 0), _('Value per payment'), 'check', 'teal'),
            );
        case 'allocations':
            $p = $one('SELECT COUNT(*) n, COALESCE(SUM((ov_amount+ov_discount-alloc)*rate),0) v FROM '.$dt.'
                WHERE type='.ST_CUSTPAYMENT.' AND ov_amount+ov_discount-alloc>'.FLOAT_COMP_DELTA);
            $c = $one('SELECT COUNT(*) n, COALESCE(SUM(('.$gross.'-alloc)*rate),0) v FROM '.$dt.'
                WHERE type='.ST_CUSTCREDIT.$unpaid);
            $i = $one('SELECT COUNT(*) n, COALESCE(SUM(('.$gross.'-alloc)*rate),0) v FROM '.$dt.'
                WHERE type='.ST_SALESINVOICE.$unpaid);
            return array(
                array(_('Payments to allocate'), (int)$p['n'], _('Receipts with a balance'), 'wallet', 'purple'),
                array(_('Unallocated receipts'), $money($p['v']), sprintf(_('Value, %s'), $cur), 'chart', 'blue'),
                array(_('Credit notes to allocate'), (int)$c['n'], sprintf(_('%s available'), $money($c['v'])), 'undo', 'orange'),
                array(_('Open invoices'), (int)$i['n'], _('Awaiting settlement'), 'receipt', 'teal'),
                array(_('Open balance'), $money($i['v']), sprintf(_('Invoices still unpaid, %s'), $cur), 'clock', 'red'),
            );
        case 'reminders':
            $r = $one('SELECT COUNT(*) n, COALESCE(SUM(('.$gross.'-alloc)*rate),0) v,
                SUM(due_date<'.$asof.') late, COALESCE(SUM(IF(due_date<'.$asof.',('.$gross.'-alloc)*rate,0)),0) late_v,
                COALESCE(MAX(IF(due_date<'.$asof.',DATEDIFF('.$asof.',due_date),0)),0) oldest
                FROM '.$dt.' WHERE type='.ST_SALESINVOICE.$unpaid);
            return array(
                array(_('Unsettled invoices'), (int)$r['n'], _('Awaiting payment'), 'receipt', 'purple'),
                array(_('Total due'), $money($r['v']), sprintf(_('Outstanding, %s'), $cur), 'chart', 'blue'),
                array(_('Overdue'), (int)$r['late'], _('Past due date'), 'alert', 'red'),
                array(_('Overdue amount'), $money($r['late_v']), sprintf(_('To chase, %s'), $cur), 'bell', 'orange'),
                array(_('Oldest overdue'), (int)$r['oldest'], _('Days past due'), 'calendar', 'teal'),
            );
    }
    return array();
}

function ma_sales_stats($tab)
{
    if (!$_SESSION['wa_current_user']->can_access_page('SA_SALESTRANSVIEW')) return;
    require_once __DIR__ . '/dashboard.inc';
    $stats = ma_sales_stat_values($tab, date2sql(Today()));
    if (!$stats) return;
    echo '<section class="ma-sales-metrics ma-sales-stats">';
    foreach ($stats as $t)
        echo '<article class="ma-sales-metric tone-'.$t[4].'"><div class="ma-sales-metric-label"><span class="ma-sales-metric-icon">'.ma_ui_icon($t[3]).'</span>'
            .ma_ui_escape(mb_strtoupper($t[0])).'</div><div class="ma-sales-metric-value">'.ma_ui_escape(is_int($t[1]) ? number_format($t[1]) : $t[1])
            .'</div><p>'.ma_ui_escape($t[2]).'</p></article>';
    echo '</section>';
}
