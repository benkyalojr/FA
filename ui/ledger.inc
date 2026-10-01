<?php
// General Ledger workspace: posting activity at a glance, with tabs over FA's
// journal and GL inquiries and the period closing screen.
require_once __DIR__ . '/finance.inc';

define('MA_LEDGER_APP', 'GL');

function ma_ledger_tabs()
{
    return array(
        'overview' => array(_('Overview'), 'grid', 'index.php?application=GL', null),
        'journal'  => array(_('Journal Inquiry'), 'book', 'gl/inquiry/journal_inquiry.php', 'SA_GLANALYTIC'),
        'glinq'    => array(_('GL Inquiry'), 'book', 'gl/inquiry/gl_account_inquiry.php', 'SA_GLTRANSVIEW'),
        'closing'  => array(_('GL Closing'), 'lock', 'gl/manage/close_period.php', 'SA_GLCLOSE'),
    );
}

function ma_ledger_active_tab()
{
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $name = basename($script);
    if ($name === 'index.php') return (is_string($_GET['application'] ?? null) && $_GET['application'] === MA_LEDGER_APP) ? 'overview' : null;
    if ($name === 'tags.php') return (($_GET['type'] ?? '') === 'account') ? 'settings' : null;
    if (strpos($script, '/gl/') === false) return null;
    switch ($name) {
        case 'journal_inquiry.php': return 'journal';
        case 'gl_account_inquiry.php': return 'glinq';
        case 'close_period.php': return 'closing';
        case 'gl_journal.php': case 'gl_budget.php': case 'accruals.php': return 'entry';
        case 'gl_accounts.php': case 'gl_account_types.php': case 'gl_account_classes.php': case 'gl_quick_entries.php': return 'settings';
    }
    return null;
}

function ma_ledger_new_links() { return ma_finance_app_links(MA_LEDGER_APP, 0); }
function ma_ledger_reports_links()
{
    return ma_finance_app_links(MA_LEDGER_APP, 1, array('gl/inquiry/journal_inquiry.php', 'gl/inquiry/gl_account_inquiry.php'));
}
function ma_ledger_settings_links()
{
    return ma_finance_app_links(MA_LEDGER_APP, 2, array('gl/manage/close_period.php'));
}

function ma_ledger_overview()
{
    if (!$_SESSION['wa_current_user']->can_access_page('SA_GLTRANSVIEW') && !$_SESSION['wa_current_user']->can_access_page('SA_GLANALYTIC')) {
        echo '<div class="ma-empty">'.ma_ui_escape(_('You do not have access to ledger figures.')).'</div>';
        return;
    }
    global $systypes_array;
    require_once __DIR__ . '/dashboard.inc';
    $today = date2sql(Today());
    $asof = db_escape($today);
    $month = db_escape(substr($today, 0, 7).'-01');
    $gl = TB_PREF.'gl_trans';
    $cur = get_company_pref('curr_default');
    $num = function ($v) { return ma_dashboard_number($v, true); };
    $one = function ($sql) { $r = ma_dashboard_rows($sql); return $r ? $r[0] : array(); };

    $j = $one('SELECT COUNT(DISTINCT type_no) n, COALESCE(SUM(IF(amount>0,amount,0)),0) v FROM '.$gl.' WHERE type='.ST_JOURNAL.' AND tran_date BETWEEN '.$month.' AND '.$asof);
    $mv = $one('SELECT COUNT(*) n, COALESCE(SUM(IF(amount>0,amount,0)),0) v FROM '.$gl.' WHERE tran_date BETWEEN '.$month.' AND '.$asof);
    $active = (int)ma_dashboard_scalar('SELECT COUNT(DISTINCT account) FROM '.$gl.' WHERE tran_date BETWEEN '.$month.' AND '.$asof);
    $total_accounts = (int)ma_dashboard_scalar('SELECT COUNT(*) FROM '.TB_PREF.'chart_master');
    $td = $one('SELECT COUNT(*) n, COALESCE(SUM(IF(amount>0,amount,0)),0) v FROM '.$gl.' WHERE tran_date='.$asof);
    $voided = (int)ma_dashboard_scalar('SELECT COUNT(*) FROM '.TB_PREF.'voided WHERE date_ BETWEEN '.$month.' AND '.$asof);
    $closing = get_company_pref('gl_closing_date');
    $days = $closing ? (int)floor((strtotime($today) - strtotime($closing)) / 86400) : null;

    ma_fin_tiles(array(
        array(_('Journals (month)'), (int)$j['n'], sprintf(_('%s %s debit volume'), $num($j['v']), $cur), 'book', 'purple'),
        array(_('GL movements (month)'), (int)$mv['n'], sprintf(_('%s %s total volume'), $num($mv['v']), $cur), 'swap', 'blue'),
        array(_('Active accounts'), $active, sprintf(_('of %d total accounts'), $total_accounts), 'people', 'green'),
        array(_('Today'), (int)$td['n'], _("Today's posting volume").': '.$num($td['v']), 'clock', 'teal', (string)(int)$td['n'], 'open'),
        array(_('Voided (month)'), $voided, $voided ? _('Voided entries this month') : _('No voided entries'), 'close', 'red'),
        array(_('Last GL close'), $closing ? date('j M Y', strtotime($closing)) : '-', $days === null ? '' : sprintf(_('%d days ago'), $days), 'check', 'orange'),
    ));

    $count = ma_fin_period_count(6);
    $debit = ma_fin_months($count, $today); $credit = $debit;
    foreach (ma_dashboard_rows('SELECT DATE_FORMAT(tran_date,"%Y-%m") p, SUM(IF(amount>0,amount,0)) d, SUM(IF(amount<0,-amount,0)) c FROM '.$gl
        .' WHERE tran_date>='.db_escape(date('Y-m-01', strtotime($today.' -'.($count - 1).' months'))).' AND tran_date<='.$asof.' GROUP BY p') as $r)
        if (isset($debit[$r['p']])) { $debit[$r['p']] = (float)$r['d']; $credit[$r['p']] = (float)$r['c']; }
    $labels = array_map(function ($p) { return date('M y', strtotime($p.'-01')); }, array_keys($debit));
    echo '<div class="ma-sales-columns"><section class="ma-panel"><div class="ma-panel-head"><h2>'.ma_ui_escape(_('Debit / Credit Activity')).'</h2>';
    ma_finance_period_select(array(6=>_('Last 6 Months'), 3=>_('Last 3 Months'), 1=>_('This Month')), $count);
    echo '</div>';
    ma_fin_chart($labels, array(array(_('Debit'), 's1', array_values($debit)), array(_('Credit'), 's2', array_values($credit))), null,
        sprintf(_('Debit and credit activity for the last %d month(s) in %s'), $count, $cur));
    ma_fin_legend(array(array(_('Debit'), 's1'), array(_('Credit'), 's2')));
    echo '</section><section class="ma-panel"><div class="ma-panel-head"><h2>'.ma_ui_escape(_('Most-Active Accounts')).'</h2><span>'.ma_ui_escape(_('This month')).'</span></div>';
    $top = ma_dashboard_rows('SELECT g.account, c.account_name, COUNT(*) n, SUM(IF(g.amount>0,g.amount,-g.amount)) v FROM '.$gl.' g
        JOIN '.TB_PREF.'chart_master c ON c.account_code=g.account WHERE g.tran_date BETWEEN '.$month.' AND '.$asof.' GROUP BY g.account, c.account_name ORDER BY v DESC LIMIT 6');
    if (!$top) echo '<div class="ma-empty">'.ma_ui_escape(_('No account activity this month')).'</div>';
    else {
        $max = max(1, (float)$top[0]['v']);
        foreach ($top as $a)
            echo '<div class="ma-fin-account-row"><div class="ma-fin-account-data"><span class="ma-fin-code">'.ma_ui_escape($a['account']).'</span><b>'
                .ma_ui_escape($a['account_name']).'</b><strong>'.ma_ui_escape($num($a['v'])).'</strong></div><div class="ma-fin-account-bar"><i style="width:'
                .round($a['v'] / $max * 100).'%"></i></div></div>';
    }
    echo '</section></div>';

    ma_fin_quick_actions(array(
        array(_('New Journal Entry'), _('Manual GL posting'), 'gl/gl_journal.php?NewJournal=Yes', 'SA_JOURNALENTRY', 'plus', 'purple'),
        array(_('Budget Entry'), _('Set GL budget figures'), 'gl/gl_budget.php', 'SA_BUDGETENTRY', 'chart', 'blue'),
        array(_('GL Inquiry'), _('Drill into accounts'), 'gl/inquiry/gl_account_inquiry.php', 'SA_GLTRANSVIEW', 'search', 'green'),
        array(_('Journal Inquiry'), _('Browse all journals'), 'gl/inquiry/journal_inquiry.php', 'SA_GLANALYTIC', 'book', 'orange'),
        array(_('Chart of Accounts'), _('Manage GL accounts'), 'gl/manage/gl_accounts.php', 'SA_GLACCOUNT', 'settings', 'gray'),
        array(_('GL Reports'), _('Statements & ledgers'), 'reporting/reports_main.php?Class=6', 'SA_GLREP', 'file', 'teal'),
    ));

    $recent = ma_dashboard_rows('SELECT g.type, g.type_no, g.tran_date, g.account, c.account_name, g.memo_, g.amount FROM '.$gl.' g
        JOIN '.TB_PREF.'chart_master c ON c.account_code=g.account WHERE g.amount<>0 ORDER BY g.tran_date DESC, g.counter DESC LIMIT 10');
    echo '<section class="ma-panel"><div class="ma-panel-head"><h2>'.ma_ui_escape(_('Recent GL Movements')).'</h2><span>'.ma_ui_escape(_('All transaction sources')).'</span></div>';
    if (!$recent) echo '<div class="ma-empty">'.ma_ui_escape(_('No ledger postings yet.')).'</div>';
    else {
        echo '<div class="ma-table-wrap"><table class="ma-sales-table"><thead><tr><th>'.ma_ui_escape(_('Date')).'</th><th>'.ma_ui_escape(_('Source')).'</th><th>#</th><th>'
            .ma_ui_escape(_('Account')).'</th><th>'.ma_ui_escape(_('Memo')).'</th><th class="num">'.ma_ui_escape(_('Debit')).'</th><th class="num">'.ma_ui_escape(_('Credit')).'</th></tr></thead><tbody>';
        foreach ($recent as $r) {
            $a = (float)$r['amount'];
            echo '<tr><td>'.ma_ui_escape(sql2date($r['tran_date'])).'</td><td><span class="ma-pill open">'.ma_ui_escape($systypes_array[$r['type']] ?? $r['type']).'</span></td><td>'
                .get_trans_view_str($r['type'], $r['type_no'], '#'.$r['type_no']).'</td><td>'.ma_ui_escape($r['account']).' &middot; '.ma_ui_escape($r['account_name']).'</td><td>'
                .ma_ui_escape($r['memo_'] !== '' && $r['memo_'] !== null ? $r['memo_'] : '-').'</td><td class="num">'
                .($a > 0 ? '<strong class="ma-fin-pos">'.ma_ui_escape($num($a)).'</strong>' : '').'</td><td class="num">'
                .($a < 0 ? '<strong class="ma-fin-neg">'.ma_ui_escape($num(-$a)).'</strong>' : '').'</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</section>';
}


// Stat cards for the GL Inquiry and Journal Inquiry tabs.
function ma_ledger_stats($tab)
{
    if (!in_array($tab, array('glinq', 'journal'), true)) return;
    require_once __DIR__ . '/dashboard.inc';
    $today = date2sql(Today());
    $asof = db_escape($today);
    $month = db_escape(substr($today, 0, 7).'-01');
    $gl = TB_PREF.'gl_trans';
    $cur = get_company_pref('curr_default');
    $num = function ($v) { return ma_dashboard_number($v, true); };
    $one = function ($sql) { $r = ma_dashboard_rows($sql); return $r ? $r[0] : array(); };
    if ($tab === 'glinq') {
        $r = $one('SELECT COUNT(DISTINCT account) active, COALESCE(SUM(IF(amount>0,amount,0)),0) d, COALESCE(SUM(IF(amount<0,-amount,0)),0) c, COUNT(*) n,
            COALESCE(SUM(tran_date BETWEEN '.$month.' AND '.$asof.'),0) m FROM '.$gl);
        $total = (int)ma_dashboard_scalar('SELECT COUNT(*) FROM '.TB_PREF.'chart_master');
        ma_fin_tiles(array(
            array(_('Active accounts'), (int)$r['active'], _('Accounts with postings'), 'people', 'green', sprintf(_('%d total'), $total), 'open'),
            array(_('Total debits'), $num($r['d']), sprintf(_('All time debit postings, %s'), $cur), 'arrow-up', 'blue'),
            array(_('Total credits'), $num($r['c']), sprintf(_('All time credit postings, %s'), $cur), 'arrow-down', 'red'),
            array(_('Total movements'), (int)$r['n'], _('All GL postings'), 'swap', 'purple'),
            array(_('Moves this month'), (int)$r['m'], _('Movements this month'), 'calendar', 'teal'),
        ));
        return;
    }
    // Every posted entry the Journal Inquiry lists (all sources), voided ones excluded.
    $r = $one('SELECT COUNT(DISTINCT CONCAT(g.type,"-",g.type_no)) n, COALESCE(SUM(IF(g.amount>0,g.amount,0)),0) d, COALESCE(SUM(IF(g.amount<0,-g.amount,0)),0) c,
        COUNT(DISTINCT IF(g.tran_date BETWEEN '.$month.' AND '.$asof.',CONCAT(g.type,"-",g.type_no),NULL)) m,
        COUNT(DISTINCT IF(g.tran_date='.$asof.',CONCAT(g.type,"-",g.type_no),NULL)) t
        FROM '.$gl.' g LEFT JOIN '.TB_PREF.'voided v ON v.type=g.type AND v.id=g.type_no WHERE v.date_ IS NULL AND g.amount<>0');
    ma_fin_tiles(array(
        array(_('Total journals'), (int)$r['n'], _('All journal entries'), 'book', 'purple'),
        array(_('Total debit'), $num($r['d']), sprintf(_('Sum of debit postings, %s'), $cur), 'arrow-up', 'blue'),
        array(_('Total credit'), $num($r['c']), sprintf(_('Sum of credit postings, %s'), $cur), 'arrow-down', 'red'),
        array(_('This month'), (int)$r['m'], _('Journals posted this month'), 'calendar', 'teal'),
        array(_('Today'), (int)$r['t'], _('Journals posted today'), 'clock', 'orange', (int)$r['t'] ? (string)(int)$r['t'] : _('None today'), 'open'),
    ));
}
