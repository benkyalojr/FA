<?php
// Taxes workspace: tax periods and returns, with the eTIMS screens as tabs.
// Tax set-up (taxes, groups, item tax types) and eTIMS configuration are its
// settings screens (see ui/settings.inc).
require_once __DIR__ . '/finance.inc';

define('MA_TAXES_APP', 'taxes');

function ma_taxes_tabs()
{
    return array(
        'periods'     => array(_('Tax Periods'), 'calendar', 'taxes/tax_periods.php', 'SA_TAXREP'),
        'inquiry'     => array(_('Tax Inquiry'), 'search', 'gl/inquiry/tax_inquiry.php', 'SA_TAXREP'),
        'sales'       => array(_('eTIMS Sales'), 'receipt', 'etims/inquiry/etims_sales.php', 'SA_ETIMSVIEW'),
        'credits'     => array(_('eTIMS Credit Notes'), 'undo', 'etims/inquiry/etims_credit_notes.php', 'SA_ETIMSVIEW'),
        'submissions' => array(_('eTIMS Submissions'), 'swap', 'etims/inquiry/etims_submissions.php', 'SA_ETIMSVIEW'),
        'products'    => array(_('eTIMS Products'), 'package', 'etims/inquiry/etims_products.php', 'SA_ETIMSVIEW'),
        'xreport'     => array(_('X Report'), 'file', 'etims/manage/etims_x_report.php', 'SA_ETIMSXREPORT'),
        'zreport'     => array(_('Z Report'), 'check', 'etims/manage/etims_z_report.php', 'SA_ETIMSZREPORT'),
    );
}

function ma_taxes_active_tab()
{
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $name = basename($script);
    if ($name === 'index.php') return (is_string($_GET['application'] ?? null) && $_GET['application'] === MA_TAXES_APP) ? 'periods' : null;
    foreach (ma_taxes_tabs() as $key => $tab)
        if (substr($script, -strlen('/'.$tab[2])) === '/'.$tab[2]) return $key;
    return null;
}

function ma_taxes_new_links() { return array(); }
function ma_taxes_reports_links() { return ma_finance_app_links(MA_TAXES_APP, 1, array_map(function ($t) { return $t[2]; }, ma_taxes_tabs())); }
function ma_taxes_settings_links() { return array(); }

// Tax periods of a fiscal year as array(array(start, end)) in SQL dates, only those already begun.
function ma_taxes_periods($fy, $today)
{
    $months = max(1, (int)get_company_pref('tax_prd'));
    $periods = array();
    $start = $fy['begin'];
    while ($start <= $fy['end'] && $start <= $today) {
        $end = date('Y-m-t', strtotime($start.' +'.($months - 1).' months'));
        if ($end > $fy['end']) $end = $fy['end'];
        $periods[] = array($start, $end);
        $start = date('Y-m-d', strtotime($end.' +1 day'));
    }
    return $periods;
}

// Output tax, input tax and net due for a period, from FA's own tax summary.
function ma_taxes_figures($start, $end)
{
    $out = $in = 0; $lines = array();
    $res = get_tax_summary(sql2date($start), sql2date($end));
    while ($tx = db_fetch($res)) {
        $o = (float)$tx['payable'];
        $i = -(float)$tx['collectible'];   // as in the Tax Inquiry: inputs shown negative
        $out += $o; $in += -$i;
        $lines[] = array('name' => $tx['name'].' '.$tx['rate'].'%', 'output' => $o, 'input' => -$i, 'net_output' => (float)$tx['net_output'], 'net_input' => -(float)$tx['net_input']);
    }
    return array('output' => $out, 'input' => $in, 'due' => $out - $in, 'lines' => $lines);
}

function ma_taxes_returns_table_exists()
{
    static $exists = null;
    if ($exists === null) {
        global $db_connections;
        $prefix = $db_connections[$_SESSION['wa_current_user']->cur_con]['tbpref'] ?? TB_PREF;
        $exists = (bool)db_fetch(db_query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "
            .db_escape($prefix.'tax_returns'), 'tax returns table'));
    }
    return $exists;
}
