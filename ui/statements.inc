<?php
// Financial Statements workspace: year-to-date profit and loss, balance sheet
// position and key ratios, with tabs over FA's trial balance and drilldowns.
require_once __DIR__ . '/finance.inc';

define('MA_STATEMENTS_APP', 'statements');

function ma_statements_tabs()
{
    return array(
        'overview' => array(_('Overview'), 'grid', 'index.php?application=statements', null),
        'trial'    => array(_('Trial Balance'), 'chart', 'gl/inquiry/gl_trial_balance.php', 'SA_GLANALYTIC'),
        'balance'  => array(_('Balance Sheet Drill down'), 'file', 'gl/inquiry/balance_sheet.php', 'SA_GLANALYTIC'),
        'profit'   => array(_('Profit and Loss Drill down'), 'chart', 'gl/inquiry/profit_loss.php', 'SA_GLANALYTIC'),
    );
}

function ma_statements_active_tab()
{
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $name = basename($script);
    if ($name === 'index.php') return (is_string($_GET['application'] ?? null) && $_GET['application'] === MA_STATEMENTS_APP) ? 'overview' : null;
    if (strpos($script, '/gl/inquiry/') === false) return null;
    return array('gl_trial_balance.php'=>'trial', 'balance_sheet.php'=>'balance', 'profit_loss.php'=>'profit')[$name] ?? null;
}

function ma_statements_new_links() { return array(); }
function ma_statements_reports_links()
{
    $links = array();
    if ($_SESSION['wa_current_user']->can_access_page('SA_GLREP'))
        $links[] = array('label'=>_('General Ledger Reports'), 'link'=>'reporting/reports_main.php?Class=6');
    return $links;
}
function ma_statements_settings_links() { return array(); }

function ma_statements_overview()
{
    if (!$_SESSION['wa_current_user']->can_access_page('SA_GLANALYTIC')) {
        echo '<div class="ma-empty">'.ma_ui_escape(_('You do not have access to financial statements.')).'</div>';
        return;
    }
    require_once __DIR__ . '/dashboard.inc';
    $today = date2sql(Today());
    $fy = ma_dashboard_rows('SELECT begin, end FROM '.TB_PREF.'fiscal_year WHERE id='.db_escape(get_company_pref('f_year')));
    $begin = $fy ? $fy[0]['begin'] : date('Y-01-01');
    $end = $fy ? $fy[0]['end'] : date('Y-12-31');
    $to = min($today, $end);
    $gl = TB_PREF.'gl_trans g JOIN '.TB_PREF.'chart_master c ON c.account_code=g.account JOIN '.TB_PREF.'chart_types t ON t.id=c.account_type JOIN '.TB_PREF.'chart_class cl ON cl.cid=t.class_id';
    $cur = get_company_pref('curr_default');
    $num = function ($v) { return ma_dashboard_number($v, true); };
    $pct = function ($v) { return number_format($v, 1).'%'; };
    $b = db_escape($begin); $e = db_escape($to); $t = db_escape($today);
    $month_from = db_escape(substr($today, 0, 7).'-01');

    // Signed totals by account class type over a date range (income and
    // liabilities/equity are credit balances, so they are shown negated).
    $by_class = function ($from, $until) use ($gl) {
        $rows = ma_dashboard_rows('SELECT cl.ctype, COALESCE(SUM(g.amount),0) v FROM '.$gl.' WHERE g.tran_date BETWEEN '.$from.' AND '.$until.' GROUP BY cl.ctype');
        $out = array();
        foreach ($rows as $r) $out[(int)$r['ctype']] = (float)$r['v'];
        return $out;
    };
    $ytd = $by_class($b, $e);
    $revenue = -($ytd[CL_INCOME] ?? 0);
    $cogs = $ytd[CL_COGS] ?? 0;
    $opex = $ytd[CL_EXPENSE] ?? 0;
    $expenses = $cogs + $opex;
    $net = $revenue - $expenses;
    $gross = $revenue - $cogs;
    $this_month = $by_class($month_from, $t);
    $month_rev = -($this_month[CL_INCOME] ?? 0);
    $month_exp = ($this_month[CL_COGS] ?? 0) + ($this_month[CL_EXPENSE] ?? 0);

    $pos = $by_class(db_escape('1900-01-01'), $t);
    $assets = $pos[CL_ASSETS] ?? 0;
    $liab = -($pos[CL_LIABILITIES] ?? 0);
    $equity_rec = -($pos[CL_EQUITY] ?? 0);
    $all_net = -(($pos[CL_INCOME] ?? 0) + ($pos[CL_COGS] ?? 0) + ($pos[CL_EXPENSE] ?? 0));
    $implied = $assets - $liab;
    $balanced = abs($assets - $liab - $equity_rec - $all_net) < 0.01;

    ma_fin_tiles(array(
        array(_('Revenue (YTD)'), $num($revenue), sprintf(_('%s this month'), $num($month_rev)), 'chart', 'green'),
        array(_('Expenses (YTD)'), $num($expenses), sprintf(_('%s this month'), $num($month_exp)), 'arrow-up', 'red'),
        array(_('Net profit (YTD)'), $num($net), $revenue > 0 ? sprintf(_('%s net margin'), $pct($net / $revenue * 100)) : _('No revenue yet'), 'wallet', 'purple'),
        array(_('Total assets'), $num($assets), sprintf(_('As of %s'), sql2date($today)), 'bank', 'blue'),
        array(_('Liabilities'), $num($liab), _('Total obligations'), 'book', 'orange'),
        array(_('Equity'), $num($implied), $balanced ? _('Balanced: A = L + E') : _('Assets - liabilities'), 'layers', 'teal'),
    ));

    // Revenue and expenses by month across the fiscal year (or this month only).
    $period = ($_GET['period'] ?? 'year') === 'month' ? 'month' : 'year';
    $months = array();
    if ($period === 'year')
        for ($d = strtotime($begin); $d <= strtotime($end) && count($months) < 12; $d = strtotime('+1 month', $d)) $months[date('Y-m', $d)] = array(0, 0);
    else
        $months[substr($today, 0, 7)] = array(0, 0);
    $from = $period === 'year' ? $b : $month_from;
    foreach (ma_dashboard_rows('SELECT DATE_FORMAT(g.tran_date,"%Y-%m") p, SUM(IF(cl.ctype='.CL_INCOME.',-g.amount,0)) rev,
        SUM(IF(cl.ctype IN ('.CL_COGS.','.CL_EXPENSE.'),g.amount,0)) exp FROM '.$gl.' WHERE cl.ctype IN ('.CL_INCOME.','.CL_COGS.','.CL_EXPENSE.')
        AND g.tran_date BETWEEN '.$from.' AND '.($period === 'year' ? $e : $t).' GROUP BY p') as $r)
        if (isset($months[$r['p']])) $months[$r['p']] = array((float)$r['rev'], (float)$r['exp']);
    $labels = array_map(function ($p) { return date('M y', strtotime($p.'-01')); }, array_keys($months));
    $rev_s = array_column($months, 0); $exp_s = array_column($months, 1);
    $net_s = array_map(function ($r, $x) { return $r - $x; }, $rev_s, $exp_s);

    echo '<div class="ma-sales-columns"><section class="ma-panel"><div class="ma-panel-head"><h2>'.ma_ui_escape(_('Revenue vs Expenses')).'</h2>';
    ma_finance_period_select(array('year'=>_('This Fiscal Year'), 'month'=>_('This Month')), $period);
    echo '</div>';
    ma_fin_chart($labels, array(array(_('Revenue'), 's1', $rev_s), array(_('Expenses'), 's2', $exp_s)), array(_('Net Profit'), $net_s),
        sprintf(_('Revenue, expenses and net profit in %s'), $cur));
    ma_fin_legend(array(array(_('Net Profit'), 's3'), array(_('Revenue'), 's1'), array(_('Expenses'), 's2')));
    echo '</section><section class="ma-panel"><div class="ma-panel-head"><h2>'.ma_ui_escape(_('Key Ratios')).'</h2><span>'.ma_ui_escape(_('YTD')).'</span></div><div class="ma-fin-ratios">';
    $ratios = array(
        array(_('Gross margin'), $revenue > 0 ? $pct($gross / $revenue * 100) : '-', _('Revenue - COGS'), 'green'),
        array(_('Net margin'), $revenue > 0 ? $pct($net / $revenue * 100) : '-', _('Net profit / revenue'), 'green'),
        array(_('Expense ratio'), $revenue > 0 ? $pct($expenses / $revenue * 100) : '-', _('Costs / revenue'), 'orange'),
        array(_('D/E ratio'), $implied > 0.005 ? $pct($liab / $implied * 100) : '-', _('Liabilities / equity'), 'purple'),
    );
    foreach ($ratios as $r)
        echo '<article class="ma-fin-ratio tone-'.$r[3].'"><h3>'.ma_ui_escape(mb_strtoupper($r[0])).'</h3><strong>'.ma_ui_escape($r[1]).'</strong><p>'.ma_ui_escape($r[2]).'</p></article>';
    echo '</div></section></div>';

    $row = function ($label, $value, $class = '') use ($num) {
        return '<div class="ma-fin-statement-row '.$class.'"><span>'.ma_ui_escape($label).'</span><strong>'.ma_ui_escape($num($value)).'</strong></div>';
    };
    echo '<div class="ma-sales-columns"><section class="ma-panel"><div class="ma-panel-head"><h2>'.ma_ui_escape(_('Income Statement (YTD)')).'</h2>'
        .'<a class="ma-sales-link" href="'.ma_ui_escape(ma_ui_href('gl/inquiry/profit_loss.php')).'">'.ma_ui_escape(_('Full P&L')).' &rsaquo;</a></div>'
        .$row(_('Revenue'), $revenue, 'tone-green').$row(_('Cost of Goods Sold'), $cogs, 'indent').$row(_('Gross Profit'), $gross, 'subtotal tone-green')
        .$row(_('Operating Expenses'), $opex, 'indent').$row(_('Net Profit'), $net, 'profit tone-green').'</section>'
        .'<section class="ma-panel"><div class="ma-panel-head"><h2>'.ma_ui_escape(_('Balance Sheet (As of Today)')).'</h2>'
        .'<a class="ma-sales-link" href="'.ma_ui_escape(ma_ui_href('gl/inquiry/balance_sheet.php')).'">'.ma_ui_escape(_('Full BS')).' &rsaquo;</a></div>'
        .$row(_('Total Assets'), $assets).$row(_('Total Liabilities'), $liab).$row(_('Recorded Equity'), $equity_rec)
        .$row(_('Retained Earnings (YTD)'), $net, 'indent').$row(_('Implied Equity (A - L)'), $implied, 'subtotal').'</section></div>';

    $top = function ($types, $sign) use ($gl, $b, $e) {
        return ma_dashboard_rows('SELECT g.account, c.account_name, '.$sign.'SUM(g.amount) v FROM '.$gl.' WHERE cl.ctype IN ('.$types.') AND g.tran_date BETWEEN '.$b.' AND '.$e
            .' GROUP BY g.account, c.account_name HAVING v>0 ORDER BY v DESC LIMIT 5');
    };
    $panel = function ($title, $rows) use ($num) {
        echo '<section class="ma-panel"><div class="ma-panel-head"><h2>'.ma_ui_escape($title).'</h2><span>'.ma_ui_escape(_('Fiscal YTD')).'</span></div>';
        if (!$rows) echo '<div class="ma-empty">'.ma_ui_escape(_('No activity this fiscal year.')).'</div>';
        else {
            $max = max(1, (float)$rows[0]['v']);
            foreach ($rows as $a)
                echo '<div class="ma-fin-account-row"><div class="ma-fin-account-data"><span class="ma-fin-code">'.ma_ui_escape($a['account']).'</span><b>'.ma_ui_escape($a['account_name'])
                    .'</b><strong>'.ma_ui_escape($num($a['v'])).'</strong></div><div class="ma-fin-account-bar"><i style="width:'.round($a['v'] / $max * 100).'%"></i></div></div>';
        }
        echo '</section>';
    };
    echo '<div class="ma-sales-columns">';
    $panel(_('Top Revenue Accounts'), $top(CL_INCOME, '-'));
    $panel(_('Top Expense Accounts'), $top(CL_COGS.','.CL_EXPENSE, ''));
    echo '</div>';

    ma_fin_quick_actions(array(
        array(_('Trial Balance'), _('Account balances'), 'gl/inquiry/gl_trial_balance.php', 'SA_GLANALYTIC', 'chart', 'blue'),
        array(_('Balance Sheet Drill down'), _('Assets, liabilities, equity'), 'gl/inquiry/balance_sheet.php', 'SA_GLANALYTIC', 'file', 'green'),
        array(_('Profit and Loss Drill down'), _('Income and costs'), 'gl/inquiry/profit_loss.php', 'SA_GLANALYTIC', 'wallet', 'purple'),
    ));
}
