<?php
// Shared pieces of the Banking, General Ledger and Financial Statements
// workspaces: module detection, tab toolbar, charts and quick actions.
require_once __DIR__ . '/inventory.inc';

function ma_finance_modules()
{
    return array('banking', 'ledger', 'statements', 'taxes', 'communications');
}

// The finance workspace the current screen belongs to, or null.
function ma_finance_active_module()
{
    foreach (ma_finance_modules() as $m)
        if (call_user_func('ma_'.$m.'_active_tab') !== null) return $m;
    return null;
}

// Accessible links of one of an FA application's modules (0 transactions,
// 1 inquiries and reports, 2 maintenance), minus links a tab already covers.
function ma_finance_app_links($app_id, $index, $skip = array())
{
    $app = $_SESSION['App']->applications[$app_id] ?? null;
    $links = array();
    if (!$app || !isset($app->modules[$index])) return $links;
    $module = $app->modules[$index];
    foreach (array_merge($module->lappfunctions, $module->rappfunctions) as $item) {
        if (!$item->label || !$item->link || !$_SESSION['wa_current_user']->can_access_page($item->access)) continue;
        if (in_array(strtok($item->link, '?'), $skip, true)) continue;
        $links[] = array('label'=>ma_ui_label($item->label), 'link'=>$item->link);
    }
    return $links;
}

// Inquiry screens that get a stats strip, a filter bar and card-style tables.
function ma_finance_is_list_screen($m)
{
    $name = basename($_SERVER['SCRIPT_NAME'] ?? '');
    if ($m === 'ledger') return in_array($name, array('gl_account_inquiry.php', 'journal_inquiry.php'), true);
    if ($m === 'banking') return in_array($name, array('bank_inquiry.php', 'bank_account_reconcile.php'), true);
    if ($m === 'taxes') return ma_taxes_active_tab() !== 'periods';
    if ($m === 'communications') return true;
    return false;
}

function ma_finance_stats($m)
{
    $fn = 'ma_'.$m.'_stats';
    if (function_exists($fn)) call_user_func($fn, call_user_func('ma_'.$m.'_active_tab'));
}

function ma_finance_toolbar($m)
{
    $active = call_user_func('ma_'.$m.'_active_tab');
    $tabs = call_user_func('ma_'.$m.'_tabs');
    echo '<div class="ma-sales-toolbar"><nav class="ma-sales-tabs" aria-label="'.ma_ui_escape(_('Sections')).'">';
    foreach ($tabs as $key=>$tab) {
        if ($tab[3] && !$_SESSION['wa_current_user']->can_access_page($tab[3])) continue;
        echo '<a class="ma-sales-tab'.($key === $active ? ' active' : '').'"'.($key === $active ? ' aria-current="page"' : '')
            .' href="'.ma_ui_escape(ma_ui_href($tab[2])).'">'.ma_ui_icon($tab[1]).'<span>'.ma_ui_escape($tab[0]).'</span></a>';
    }
    echo '</nav><div class="ma-sales-actions">';
    ma_sales_menu(_('Reports'), 'chart', call_user_func('ma_'.$m.'_reports_links'));
    ma_settings_button(array('banking'=>'banking','ledger'=>'GL','statements'=>'statements','taxes'=>'taxes','communications'=>'communications')[$m]);
    echo '</div></div>';
}

function ma_finance_topbar_new($m)
{
    ma_sales_menu(_('New'), 'plus', call_user_func('ma_'.$m.'_new_links'), true);
}

// "Last N months" / "This month" selector; reloads the page with ?period=.
function ma_finance_period_select($options, $current)
{
    echo '<form method="get" class="ma-fin-period"><input type="hidden" name="application" value="'.ma_ui_escape($_GET['application'] ?? '').'">'
        .'<select name="period" aria-label="'.ma_ui_escape(_('Chart period')).'" onchange="this.form.submit()">';
    foreach ($options as $value=>$label)
        echo '<option value="'.ma_ui_escape($value).'"'.((string)$value === (string)$current ? ' selected' : '').'>'.ma_ui_escape($label).'</option>';
    echo '</select></form>';
}

function ma_fin_axis_label($v)
{
    $v = abs($v);
    if ($v >= 1000000) return round($v / 1000000, 1).'m';
    if ($v >= 1000) return round($v / 1000, $v >= 10000 ? 0 : 1).'k';
    return (string)round($v);
}

// Grouped bars per period. $series: list of array(label, css class, values);
// $line: optional array(label, values) drawn over the bars (e.g. net profit).
function ma_fin_chart($labels, $series, $line = null, $aria = '')
{
    $max = 0;
    foreach ($series as $s) foreach ($s[2] as $v) $max = max($max, abs((float)$v));
    if ($line) foreach ($line[1] as $v) $max = max($max, abs((float)$v));
    if ($max <= 0) $max = 1;
    $n = count($labels);
    echo '<div class="ma-fin-chart" role="img" aria-label="'.ma_ui_escape($aria).'"><div class="ma-fin-grid">';
    for ($i = 0; $i <= 4; $i++)
        echo '<div class="ma-fin-gridline" style="bottom:'.($i / 4 * 100).'%"><span>'.ma_ui_escape(ma_fin_axis_label($max * $i / 4)).'</span></div>';
    echo '</div><div class="ma-fin-bars" style="grid-template-columns:repeat('.$n.',1fr)">';
    foreach ($labels as $i=>$label) {
        echo '<div class="ma-fin-slot">';
        foreach ($series as $s) {
            $v = max(0, (float)($s[2][$i] ?? 0));
            if ($v > 0)
                echo '<div class="ma-fin-bar '.ma_ui_escape($s[1]).'" style="height:'.round($v / $max * 100, 2).'%" title="'
                    .ma_ui_escape($s[0].': '.ma_dashboard_number($v, true)).'"></div>';
        }
        echo '<span class="ma-fin-month">'.ma_ui_escape($label).'</span></div>';
    }
    echo '</div>';
    if ($line && $n) {
        $pts = array();
        foreach ($line[1] as $i=>$v)
            $pts[] = round(($i + .5) / $n * 100, 3).','.round(100 - max(0, (float)$v) / $max * 100, 3);
        echo '<svg class="ma-fin-line" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true"><polyline points="'.implode(' ', $pts).'"/></svg>';
    }
    echo '</div>';
}

function ma_fin_legend($items)
{
    echo '<div class="ma-fin-legend">';
    foreach ($items as $i)
        echo '<span><b class="'.ma_ui_escape($i[1]).'"></b>'.ma_ui_escape($i[0]).'</span>';
    echo '</div>';
}

// Tiles: array(label, value, hint, icon, tone, [badge, badge class]).
function ma_fin_tiles($tiles)
{
    ma_render_stat_cards($tiles);
}

// Quick actions: array(label, hint, link, permission, icon, tone).
function ma_fin_quick_actions($actions)
{
    echo '<section class="ma-panel ma-fin-quick"><div class="ma-panel-head"><h2>'.ma_ui_escape(_('Quick Actions')).'</h2></div><div class="ma-fin-quick-grid">';
    foreach ($actions as $a) {
        if ($a[3] && !$_SESSION['wa_current_user']->can_access_page($a[3])) continue;
        echo '<a class="ma-fin-quick-action tone-'.ma_ui_escape($a[5]).'" href="'.ma_ui_escape(ma_ui_href($a[2])).'"><span class="ma-fin-quick-icon">'
            .ma_ui_icon($a[4]).'</span><strong>'.ma_ui_escape($a[0]).'</strong><small>'.ma_ui_escape($a[1]).'</small></a>';
    }
    echo '</div></section>';
}

// Month buckets ending this month: array('Y-m' => 0 ...) oldest first.
function ma_fin_months($count, $today)
{
    $out = array();
    for ($i = $count - 1; $i >= 0; $i--) $out[date('Y-m', strtotime(substr($today, 0, 7).'-01 -'.$i.' months'))] = 0;
    return $out;
}

function ma_fin_period_count($default = 6)
{
    $p = (int)($_GET['period'] ?? $default);
    return in_array($p, array(1, 3, 6), true) ? $p : $default;
}
