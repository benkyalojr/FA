<?php
// Banking workspace: overview of cash, flows and reconciliation, with tabs over
// FA's bank inquiry, reconciliation and currency revaluation screens.
require_once __DIR__ . '/finance.inc';

define('MA_BANKING_APP', 'banking');

function ma_banking_tabs()
{
    return array(
        'overview'    => array(_('Overview'), 'grid', 'index.php?application=banking', null),
        'inquiry'     => array(_('Bank Inquiry'), 'bank', 'gl/inquiry/bank_inquiry.php', 'SA_BANKTRANSVIEW'),
        'reconcile'   => array(_('Reconcile'), 'swap', 'gl/bank_account_reconcile.php', 'SA_RECONCILE'),
        'revaluation' => array(_('Revaluation of Currency Accounts'), 'chart', 'gl/manage/revaluate_currencies.php', 'SA_EXCHANGERATE'),
    );
}

function ma_banking_active_tab()
{
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $name = basename($script);
    if ($name === 'index.php') return (is_string($_GET['application'] ?? null) && $_GET['application'] === MA_BANKING_APP) ? 'overview' : null;
    if (strpos($script, '/gl/') === false) return null;
    switch ($name) {
        case 'bank_inquiry.php': return 'inquiry';
        case 'bank_account_reconcile.php': return 'reconcile';
        case 'revaluate_currencies.php': return 'revaluation';
        case 'gl_bank.php': case 'bank_transfer.php': return 'entry';
        case 'bank_accounts.php': case 'currencies.php': case 'exchange_rates.php': return 'settings';
    }
    return null;
}

function ma_banking_new_links()
{
    $links = ma_finance_app_links(MA_BANKING_APP, 0);
    return $links;
}
function ma_banking_reports_links()
{
    return ma_finance_app_links(MA_BANKING_APP, 1, array('gl/inquiry/bank_inquiry.php'));
}
function ma_banking_settings_links()
{
    return ma_finance_app_links(MA_BANKING_APP, 2, array('gl/manage/revaluate_currencies.php'));
}

function ma_banking_overview()
{
    if (!$_SESSION['wa_current_user']->can_access_page('SA_BANKTRANSVIEW')) {
        echo '<div class="ma-empty">'.ma_ui_escape(_('You do not have access to banking figures.')).'</div>';
        return;
    }
    global $systypes_array;
    require_once __DIR__ . '/dashboard.inc';
    $today = date2sql(Today());
    $asof = db_escape($today);
    $month = db_escape(substr($today, 0, 7).'-01');
    $bt = TB_PREF.'bank_trans';
    $cur = get_company_pref('curr_default');
    $num = function ($v) { return ma_dashboard_number($v, true); };
    $one = function ($sql) { $r = ma_dashboard_rows($sql); return $r ? $r[0] : array(); };
    // Money in/out of the bank: deposits and receipts versus payments (not transfers).
    $flow = ST_BANKDEPOSIT.','.ST_CUSTPAYMENT.','.ST_BANKPAYMENT.','.ST_SUPPAYMENT;

    $cash = $one('SELECT COALESCE(SUM(g.amount),0) v FROM '.TB_PREF.'gl_trans g WHERE g.tran_date<='.$asof.' AND g.account IN
        (SELECT account_code FROM '.TB_PREF.'bank_accounts WHERE inactive=0)');
    $accounts_n = (int)ma_dashboard_scalar('SELECT COUNT(*) FROM '.TB_PREF.'bank_accounts WHERE inactive=0');
    $in = $one('SELECT COALESCE(SUM(amount),0) v, COUNT(*) n FROM '.$bt.' WHERE amount>0 AND type IN ('.$flow.') AND trans_date BETWEEN '.$month.' AND '.$asof);
    $out = $one('SELECT COALESCE(SUM(-amount),0) v, COUNT(*) n FROM '.$bt.' WHERE amount<0 AND type IN ('.$flow.') AND trans_date BETWEEN '.$month.' AND '.$asof);
    $unrec = $one('SELECT COUNT(*) n, COALESCE(DATEDIFF('.$asof.',MIN(trans_date)),0) days FROM '.$bt.' WHERE reconciled IS NULL AND amount<>0');
    $tin = ma_dashboard_scalar('SELECT COALESCE(SUM(amount),0) FROM '.$bt.' WHERE amount>0 AND trans_date='.$asof);
    $tout = ma_dashboard_scalar('SELECT COALESCE(SUM(-amount),0) FROM '.$bt.' WHERE amount<0 AND trans_date='.$asof);
    $tcount = (int)ma_dashboard_scalar('SELECT COUNT(*) FROM '.$bt.' WHERE trans_date='.$asof);
    $net = (float)$in['v'] - (float)$out['v'];

    $tiles = array(
        array(_('Cash on hand'), $num($cash['v']), sprintf(_('%d bank account(s), %s'), $accounts_n, $cur), 'bank', 'green'),
        array(_('Inflow (month)'), $num($in['v']), _('Deposits + receipts this month'), 'arrow-down', 'blue', (string)(int)$in['n'], 'open'),
        array(_('Outflow (month)'), $num($out['v']), _('Payments this month'), 'arrow-up', 'red', (string)(int)$out['n'], 'late'),
        array(_('Net cash flow'), ($net >= 0 ? '+' : '-').$num(abs($net)), _('Inflow minus outflow, this month'), 'chart', 'purple'),
        array(_('Unreconciled'), (int)$unrec['n'], (int)$unrec['n'] ? sprintf(_('Oldest %d days back'), (int)$unrec['days']) : _('Everything reconciled'),
            'alert', 'orange', (string)(int)$unrec['n'], 'pending'),
        array(_('Today'), '+'.$num($tin).' / -'.$num($tout), _("Today's bank movement"), 'clock', 'teal', (string)$tcount, 'open'),
    );
    ma_fin_tiles($tiles);

    $count = ma_fin_period_count(6);
    $series_in = ma_fin_months($count, $today); $series_out = $series_in;
    foreach (ma_dashboard_rows('SELECT DATE_FORMAT(trans_date,"%Y-%m") p, SUM(IF(amount>0,amount,0)) i, SUM(IF(amount<0,-amount,0)) o FROM '.$bt
        .' WHERE type IN ('.$flow.') AND trans_date>='.db_escape(date('Y-m-01', strtotime($today.' -'.($count - 1).' months'))).' AND trans_date<='.$asof.' GROUP BY p') as $r)
        if (isset($series_in[$r['p']])) { $series_in[$r['p']] = (float)$r['i']; $series_out[$r['p']] = (float)$r['o']; }
    $labels = array_map(function ($p) { return date('M y', strtotime($p.'-01')); }, array_keys($series_in));

    echo '<div class="ma-sales-columns"><section class="ma-panel"><div class="ma-panel-head"><h2>'.ma_ui_escape(_('Cash Flow')).'</h2>';
    ma_finance_period_select(array(6=>_('Last 6 Months'), 3=>_('Last 3 Months'), 1=>_('This Month')), $count);
    echo '</div>';
    ma_fin_chart($labels, array(array(_('Inflow'), 's1', array_values($series_in)), array(_('Outflow'), 's2', array_values($series_out))), null,
        sprintf(_('Cash flow for the last %d month(s): inflow and outflow in %s'), $count, $cur));
    ma_fin_legend(array(array(_('Inflow'), 's1'), array(_('Outflow'), 's2')));
    echo '</section><section class="ma-panel"><div class="ma-panel-head"><h2>'.ma_ui_escape(_('Bank Accounts')).'</h2>';
    if ($_SESSION['wa_current_user']->can_access_page('SA_BANKACCOUNT'))
        echo '<a class="ma-sales-link" href="'.ma_ui_escape(ma_ui_href('gl/manage/bank_accounts.php')).'">'.ma_ui_escape(_('Manage')).' &rsaquo;</a>';
    echo '</div>';
    $accounts = ma_dashboard_rows('SELECT b.id, b.bank_account_name, b.bank_name, b.bank_curr_code, COUNT(t.id) n,
        COALESCE(SUM(t.reconciled IS NULL AND t.amount<>0),0) unrec, COALESCE(SUM(t.amount),0) balance
        FROM '.TB_PREF.'bank_accounts b LEFT JOIN '.$bt.' t ON t.bank_act=b.id AND t.trans_date<='.$asof.' WHERE b.inactive=0 GROUP BY b.id ORDER BY b.bank_account_name');
    if (!$accounts) echo '<div class="ma-empty">'.ma_ui_escape(_('No bank accounts yet.')).'</div>';
    foreach ($accounts as $a)
        echo '<div class="ma-fin-account"><span class="ma-fin-account-icon">'.ma_ui_icon('bank').'</span><div><strong>'.ma_ui_escape($a['bank_account_name']).'</strong><p>'
            .implode(' &middot; ', array_filter(array(ma_ui_escape($a['bank_name']), ma_ui_escape(sprintf(_('%d txns'), (int)$a['n'])))))
            .((int)$a['unrec'] ? ' &middot; <span class="ma-fin-warn">'.sprintf(_('%d unrec.'), (int)$a['unrec']).'</span>' : '').'</p></div><span class="ma-fin-balance">'
            .ma_ui_escape($num($a['balance'])).' <small>'.ma_ui_escape($a['bank_curr_code']).'</small></span></div>';
    echo '</section></div>';

    ma_fin_quick_actions(array(
        array(_('New Deposit'), _('Record incoming money'), 'gl/gl_bank.php?NewDeposit=Yes', 'SA_DEPOSIT', 'arrow-down', 'green'),
        array(_('New Payment'), _('Record outgoing money'), 'gl/gl_bank.php?NewPayment=Yes', 'SA_PAYMENT', 'arrow-up', 'red'),
        array(_('Fund Transfer'), _('Move between accounts'), 'gl/bank_transfer.php', 'SA_BANKTRANSFER', 'swap', 'blue'),
        array(_('Reconcile'), _('Match bank statement'), 'gl/bank_account_reconcile.php', 'SA_RECONCILE', 'check', 'orange'),
        array(_('Bank Inquiry'), _('Browse all transactions'), 'gl/inquiry/bank_inquiry.php', 'SA_BANKTRANSVIEW', 'search', 'purple'),
        array(_('Reports'), _('Statements & ledgers'), 'reporting/reports_main.php?Class=5', 'SA_BANKREP', 'chart', 'teal'),
    ));

    $recent = ma_dashboard_rows('SELECT t.type, t.trans_no, t.ref, t.trans_date, t.amount, t.reconciled, b.bank_account_name, b.bank_curr_code,
        COALESCE(dm.name, sp.supp_name, cm.memo_) AS who
        FROM '.$bt.' t JOIN '.TB_PREF.'bank_accounts b ON b.id=t.bank_act
        LEFT JOIN '.TB_PREF.'debtor_trans dt ON dt.type=t.type AND dt.trans_no=t.trans_no AND t.type='.ST_CUSTPAYMENT.'
        LEFT JOIN '.TB_PREF.'debtors_master dm ON dm.debtor_no=dt.debtor_no
        LEFT JOIN '.TB_PREF.'supp_trans st ON st.type=t.type AND st.trans_no=t.trans_no AND t.type='.ST_SUPPAYMENT.'
        LEFT JOIN '.TB_PREF.'suppliers sp ON sp.supplier_id=st.supplier_id
        LEFT JOIN '.TB_PREF.'comments cm ON cm.type=t.type AND cm.id=t.trans_no
        WHERE t.amount<>0 ORDER BY t.trans_date DESC, t.id DESC LIMIT 8');
    echo '<section class="ma-panel"><div class="ma-panel-head"><h2>'.ma_ui_escape(_('Recent Bank Transactions')).'</h2>';
    if ($_SESSION['wa_current_user']->can_access_page('SA_BANKTRANSVIEW'))
        echo '<a class="ma-sales-link" href="'.ma_ui_escape(ma_ui_href('gl/inquiry/bank_inquiry.php')).'">'.ma_ui_escape(_('View all')).' &rsaquo;</a>';
    echo '</div>';
    if (!$recent) echo '<div class="ma-empty">'.ma_ui_escape(_('No bank transactions yet.')).'</div>';
    else {
        echo '<div class="ma-table-wrap"><table class="ma-sales-table"><thead><tr><th>'.ma_ui_escape(_('Date')).'</th><th>'.ma_ui_escape(_('Type')).'</th><th>'
            .ma_ui_escape(_('Reference')).'</th><th>'.ma_ui_escape(_('Bank Account')).'</th><th>'.ma_ui_escape(_('Counterparty / Memo')).'</th><th class="num">'
            .ma_ui_escape(_('Amount')).'</th><th>'.ma_ui_escape(_('Status')).'</th></tr></thead><tbody>';
        foreach ($recent as $r) {
            $amount = (float)$r['amount'];
            echo '<tr><td>'.ma_ui_escape(sql2date($r['trans_date'])).'</td><td><span class="ma-pill open">'.ma_ui_escape($systypes_array[$r['type']] ?? $r['type']).'</span></td><td>'
                .get_trans_view_str($r['type'], $r['trans_no'], $r['ref']).'</td><td>'.ma_ui_escape($r['bank_account_name']).'</td><td>'
                .ma_ui_escape($r['who'] !== null && $r['who'] !== '' ? $r['who'] : '-').'</td><td class="num"><strong class="'.($amount >= 0 ? 'ma-fin-pos' : 'ma-fin-neg').'">'
                .($amount >= 0 ? '+' : '-').ma_ui_escape($num(abs($amount))).'</strong> <small>'.ma_ui_escape($r['bank_curr_code']).'</small></td><td><span class="ma-pill '
                .($r['reconciled'] ? 'paid' : 'pending').'">'.ma_ui_escape($r['reconciled'] ? _('Reconciled') : _('Unreconciled')).'</span></td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</section>';
}


// Stat cards for the Bank Inquiry and Reconcile tabs.
function ma_banking_stats($tab)
{
    if (!in_array($tab, array('inquiry', 'reconcile'), true)) return;
    require_once __DIR__ . '/dashboard.inc';
    $today = date2sql(Today());
    $month = db_escape(substr($today, 0, 7).'-01');
    $asof = db_escape($today);
    $bt = TB_PREF.'bank_trans t LEFT JOIN '.TB_PREF.'voided v ON v.type=t.type AND v.id=t.trans_no';
    $live = ' AND v.date_ IS NULL AND t.amount<>0';
    $num = function ($v) { return ma_dashboard_number($v, true); };
    $one = function ($sql) { $r = ma_dashboard_rows($sql); return $r ? $r[0] : array(); };
    $cur = get_company_pref('curr_default');
    if ($tab === 'inquiry') {
        $r = $one('SELECT COUNT(*) n, COALESCE(SUM(IF(t.amount>0,t.amount,0)),0) tin, COALESCE(SUM(t.amount>0),0) nin,
            COALESCE(SUM(IF(t.amount<0,-t.amount,0)),0) tout, COALESCE(SUM(t.amount<0),0) nout, COALESCE(SUM(t.reconciled IS NULL),0) unrec,
            COALESCE(SUM(t.trans_date BETWEEN '.$month.' AND '.$asof.'),0) m FROM '.$bt.' WHERE 1=1'.$live);
        ma_fin_tiles(array(
            array(_('Total transactions'), (int)$r['n'], _('All bank entries'), 'bank', 'blue'),
            array(_('Total in'), $num($r['tin']), sprintf(_('%d deposits, %s'), (int)$r['nin'], $cur), 'arrow-down', 'green'),
            array(_('Total out'), $num($r['tout']), sprintf(_('%d payments, %s'), (int)$r['nout'], $cur), 'arrow-up', 'red'),
            array(_('Unreconciled'), (int)$r['unrec'], _('Entries pending reconcile'), 'alert', 'orange'),
            array(_('This month'), (int)$r['m'], _('Transactions this month'), 'calendar', 'purple'),
        ));
        return;
    }
    $accounts = (int)ma_dashboard_scalar('SELECT COUNT(*) FROM '.TB_PREF.'bank_accounts WHERE inactive=0');
    $r = $one('SELECT COALESCE(SUM(t.reconciled IS NULL),0) un, COALESCE(SUM(IF(t.reconciled IS NULL,t.amount,0)),0) pending,
        COALESCE(SUM(t.reconciled IS NOT NULL),0) rec, COALESCE(SUM(t.reconciled BETWEEN '.$month.' AND '.$asof.'),0) recm FROM '.$bt.' WHERE 1=1'.$live);
    ma_fin_tiles(array(
        array(_('Bank accounts'), $accounts, _('Active bank accounts'), 'bank', 'blue'),
        array(_('Unreconciled'), (int)$r['un'], sprintf(_('%s pending'), $num($r['pending'])), 'alert', 'orange'),
        array(_('Reconciled'), (int)$r['rec'], _('Already matched entries'), 'check', 'green'),
        array(_('Reconciled (month)'), (int)$r['recm'], _('Matched this month'), 'calendar', 'purple'),
    ));
}
