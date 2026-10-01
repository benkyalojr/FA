<?php
// Inventory workspace: item list with stock figures plus tabs for the other
// inventory screens. Tabs lead to FA's own screens, so posting is untouched.
require_once __DIR__ . '/customers.inc';

define('MA_INVENTORY_APP', 'stock');

function ma_inventory_tabs()
{
    return array(
        'inventory'   => array(_('Inventory'),            'package', 'inventory/manage/item_list.php', 'SA_ITEM'),
        'categories'  => array(_('Categories'),           'layers',  'inventory/manage/item_categories.php', 'SA_ITEMCATEGORY'),
        'kits'        => array(_('Kits'),                 'package', 'inventory/manage/sales_kits.php', 'SA_SALESKIT'),
        'movements'   => array(_('Inventory Movements'),  'swap',    'inventory/inquiry/stock_movements.php', 'SA_ITEMSTRANSVIEW'),
        'virtual'     => array(_('Virtual Item'),         'file',    'inventory/manage/item_codes.php', 'SA_FORITEMCODE'),
        'adjustments' => array(_('Adjustments'),          'settings','inventory/inquiry/adjustments_list.php', 'SA_INVENTORYADJUSTMENT'),
        'disposals'   => array(_('Disposals'),            'undo',    'inventory/inquiry/adjustments_list.php?mode=disposals', 'SA_INVENTORYADJUSTMENT'),
        'transfers'   => array(_('Inventory Transfer'),   'truck',   'inventory/inquiry/transfers_list.php', 'SA_LOCATIONTRANSFER'),
        'costs'       => array(_('Cost Updates'),         'chart',   'inventory/cost_update.php', 'SA_STANDARDCOST'),
        'reorder'     => array(_('Reorder Levels'),       'bell',    'inventory/reorder_level.php', 'SA_REORDER'),
    );
}

function ma_inventory_active_tab()
{
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    if (strpos($script, '/inventory/') === false) return null;
    $name = basename($script);
    $get = function ($key) { return is_string($_GET[$key] ?? null) ? $_GET[$key] : null; };
    switch ($name) {
        case 'item_list.php': case 'items.php': case 'etims_item.php': return 'inventory';
        case 'item_categories.php': return 'categories';
        case 'sales_kits.php': return 'kits';
        case 'stock_movements.php':
            // The same screen also serves Fixed Assets, which keeps its own layout.
            return (isset($_GET['FixedAsset']) || !empty($_POST['fixed_asset'])) ? null : 'movements';
        case 'item_codes.php': return 'virtual';
        case 'adjustments.php': return 'adjustments';
        case 'adjustments_list.php': return $get('mode') === 'disposals' ? 'disposals' : 'adjustments';
        case 'transfers.php': case 'transfers_list.php': return 'transfers';
        case 'cost_update.php': return 'costs';
        case 'reorder_level.php': return 'reorder';
        case 'stock_status.php': case 'stock_list.php': return 'reports';
    }
    return basename(dirname($script)) === 'inquiry' ? 'reports' : 'settings';
}

function ma_inventory_is_inventory_screen()
{
    return ma_inventory_active_tab() !== null;
}

function ma_inventory_is_list_screen()
{
    return in_array(basename($_SERVER['SCRIPT_NAME'] ?? ''), array('item_list.php', 'adjustments_list.php', 'transfers_list.php',
        'item_categories.php', 'sales_kits.php', 'item_codes.php', 'stock_movements.php'), true);
}

// Links of the Inventory application's FA modules, left out when a tab already
// leads to the same screen.
function ma_inventory_module_links($indexes)
{
    $app = $_SESSION['App']->applications[MA_INVENTORY_APP] ?? null;
    $links = array();
    if (!$app) return $links;
    $tab_links = array();
    foreach (ma_inventory_tabs() as $t) $tab_links[] = strtok($t[2], '?');
    foreach ((array)$indexes as $index) {
        if (!isset($app->modules[$index])) continue;
        $module = $app->modules[$index];
        foreach (array_merge($module->lappfunctions, $module->rappfunctions) as $item) {
            if (!$item->label || !$item->link || !$_SESSION['wa_current_user']->can_access_page($item->access)) continue;
            if ($index !== 0 && in_array(strtok($item->link, '?'), $tab_links, true)) continue;
            $links[] = array('label'=>ma_ui_label($item->label), 'link'=>$item->link);
        }
    }
    return $links;
}

function ma_inventory_new_links()
{
    $links = array();
    if ($_SESSION['wa_current_user']->can_access_page('SA_ITEM'))
        $links[] = array('label'=>_('New Item | Service'), 'link'=>'inventory/manage/items.php');
    return array_merge($links, ma_inventory_module_links(0));
}

function ma_inventory_toolbar()
{
    $active = ma_inventory_active_tab();
    echo '<div class="ma-sales-toolbar"><nav class="ma-sales-tabs" aria-label="'.ma_ui_escape(_('Inventory sections')).'">';
    foreach (ma_inventory_tabs() as $key=>$tab) {
        if ($tab[3] && !$_SESSION['wa_current_user']->can_access_page($tab[3])) continue;
        echo '<a class="ma-sales-tab'.($key === $active ? ' active' : '').'"'.($key === $active ? ' aria-current="page"' : '')
            .' href="'.ma_ui_escape(ma_ui_href($tab[2])).'">'.ma_ui_icon($tab[1]).'<span>'.ma_ui_escape($tab[0]).'</span></a>';
    }
    echo '</nav><div class="ma-sales-actions">';
    ma_sales_menu(_('Reports'), 'chart', ma_inventory_module_links(1));
    ma_settings_button(MA_INVENTORY_APP);
    echo '</div></div>';
}

function ma_inventory_topbar_new()
{
    ma_sales_menu(_('New'), 'plus', ma_inventory_new_links(), true);
}

function ma_inventory_new_action($tab)
{
    $actions = array(
        'inventory'   => array(_('New Item | Service'), 'inventory/manage/items.php', 'SA_ITEM'),
        'categories'  => array(_('New Category'), 'inventory/manage/item_categories.php?new=1', 'SA_ITEMCATEGORY'),
        'kits'        => array(_('New Kit'), 'inventory/manage/sales_kits.php?new=1', 'SA_SALESKIT'),
        'virtual'     => array(_('New Item Code'), 'inventory/manage/item_codes.php?new=1', 'SA_FORITEMCODE'),
        'movements'   => array(_('New Adjustment'), 'inventory/adjustments.php?NewAdjustment=1', 'SA_INVENTORYADJUSTMENT'),
        'costs'       => array(_('New Item | Service'), 'inventory/manage/items.php', 'SA_ITEM'),
        'reorder'     => array(_('New Item | Service'), 'inventory/manage/items.php', 'SA_ITEM'),
        'adjustments' => array(_('New Adjustment'), 'inventory/adjustments.php?NewAdjustment=1', 'SA_INVENTORYADJUSTMENT'),
        'disposals'   => array(_('New Disposal'), 'inventory/adjustments.php?NewAdjustment=1', 'SA_INVENTORYADJUSTMENT'),
        'transfers'   => array(_('New Transfer'), 'inventory/transfers.php?NewTransfer=1', 'SA_LOCATIONTRANSFER'),
    );
    $a = $actions[$tab] ?? null;
    return ($a && $_SESSION['wa_current_user']->can_access_page($a[2])) ? $a : false;
}

function ma_render_stat_cards($stats, $badge = null)
{
    echo '<section class="ma-sales-metrics ma-sales-stats ma-inv-stats">';
    foreach ($stats as $i=>$t) {
        echo '<article class="ma-sales-metric tone-'.$t[4].'"><div class="ma-sales-metric-label"><span class="ma-sales-metric-icon">'.ma_ui_icon($t[3]).'</span>'
            .ma_ui_escape(mb_strtoupper($t[0]));
        if (isset($t[5])) echo '<span class="ma-pill '.ma_ui_escape($t[6] ?? 'open').'">'.ma_ui_escape($t[5]).'</span>';
        echo '</div><div class="ma-sales-metric-value">'.ma_ui_escape(is_int($t[1]) ? number_format($t[1]) : $t[1])
            .'</div><p>'.ma_ui_escape($t[2]).'</p></article>';
    }
    echo '</section>';
}

// ------------------------------------------------------------------- stats

function ma_inventory_stats($tab)
{
    if (!$_SESSION['wa_current_user']->can_access_page('SA_ITEM') && !$_SESSION['wa_current_user']->can_access_page('SA_ITEMSTRANSVIEW')) return;
    require_once __DIR__ . '/dashboard.inc';
    $one = function ($sql) { $r = ma_dashboard_rows($sql); return $r ? $r[0] : array(); };
    $money = function ($v) { return ma_dashboard_number($v, true); };
    $month = db_escape(date('Y-m-01'));
    $sm = TB_PREF.'stock_moves';
    switch ($tab) {
        case 'inventory':
            $r = $one("SELECT COUNT(*) n, SUM(!inactive) active, SUM(mb_flag='B') bought, SUM(mb_flag='M') made, SUM(mb_flag='D') svc
                FROM ".TB_PREF."stock_master WHERE mb_flag<>'F'");
            $low = (int)ma_dashboard_scalar(ma_inventory_low_stock_sql());
            return array(
                array(_('Total items'), (int)$r['n'], _('All stock items defined'), 'package', 'blue'),
                array(_('Active items'), (int)$r['active'], _('Currently active'), 'check', 'green', _('In use'), 'paid'),
                array(_('Purchased items'), (int)$r['bought'], _('Bought from supplier'), 'cart', 'purple'),
                array(_('Manufactured'), (int)$r['made'], _('Made in-house items'), 'factory', 'teal'),
                array(_('Services'), (int)$r['svc'], _('Service type items'), 'file', 'gray'),
                array(_('Low stock'), $low, _('Items at or below reorder level'), 'alert', 'red',
                    $low ? _('Reorder needed') : _('Stock OK'), $low ? 'late' : 'paid'),
            );
        case 'movements':
            $m0 = db_escape(date('Y-m-01'));
            $prev = db_escape(date('Y-m-01', strtotime('first day of last month')));
            $r = $one('SELECT COUNT(*) n, COALESCE(SUM(m.qty>0),0) stock_in, COALESCE(SUM(m.qty<0),0) stock_out,
                COALESCE(SUM(m.tran_date>='.$m0.'),0) this_month, COALESCE(SUM(m.tran_date>='.$prev.' AND m.tran_date<'.$m0.'),0) last_month,
                COUNT(DISTINCT IF(m.tran_date>='.db_escape(date('Y-m-d', strtotime('-30 days'))).',m.loc_code,NULL)) locs
                FROM '.$sm.' m JOIN '.TB_PREF.'locations l ON l.loc_code=m.loc_code WHERE l.fixed_asset=0');
            return array(
                array(_('Total movements'), (int)$r['n'], _('All inventory moves'), 'chart', 'blue'),
                array(_('Stock in'), (int)$r['stock_in'], _('Positive quantity moves'), 'arrow-up', 'green'),
                array(_('Stock out'), (int)$r['stock_out'], _('Negative quantity moves'), 'arrow-down', 'red'),
                array(_('This month'), (int)$r['this_month'], _('Movements this month'), 'calendar', 'purple'),
                array(_('Last month'), (int)$r['last_month'], _('Movements last month'), 'clock', 'orange'),
                array(_('Active locations'), (int)$r['locs'], _('Locations with moves (30 days)'), 'bank', 'teal'),
            );
        case 'adjustments': case 'disposals':
            $r = $one('SELECT COUNT(DISTINCT trans_no) n, COUNT(DISTINCT IF(tran_date>='.$month.',trans_no,NULL)) m,
                COALESCE(SUM(IF(qty>0,qty*standard_cost,0)),0) added, COALESCE(SUM(IF(qty<0,-qty*standard_cost,0)),0) removed,
                COUNT(DISTINCT stock_id) items FROM '.$sm.' WHERE type='.ST_INVADJUST);
            return array(
                array(_('Total adjustments'), (int)$r['n'], _('All stock adjustments'), 'settings', 'purple'),
                array(_('This month'), (int)$r['m'], _('Adjustments this month'), 'calendar', 'teal'),
                array(_('Stock added'), $money($r['added']), _('Value at standard cost'), 'arrow-up', 'green'),
                array(_('Stock written off'), $money($r['removed']), _('Value at standard cost'), 'arrow-down', 'red'),
                array(_('Items affected'), (int)$r['items'], _('Distinct items adjusted'), 'package', 'blue'),
            );
        case 'transfers':
            $r = $one('SELECT COUNT(DISTINCT trans_no) n, COUNT(DISTINCT IF(tran_date>='.$month.',trans_no,NULL)) m,
                COALESCE(SUM(IF(qty>0,qty,0)),0) units, COUNT(DISTINCT stock_id) items, COUNT(DISTINCT loc_code) locs
                FROM '.$sm.' WHERE type='.ST_LOCTRANSFER);
            return array(
                array(_('Total transfers'), (int)$r['n'], _('All location transfers'), 'truck', 'purple'),
                array(_('This month'), (int)$r['m'], _('Transfers this month'), 'calendar', 'teal'),
                array(_('Units moved'), number_format((float)$r['units'], 2), _('Quantity transferred'), 'package', 'blue'),
                array(_('Items moved'), (int)$r['items'], _('Distinct items transferred'), 'layers', 'green'),
                array(_('Locations'), (int)$r['locs'], _('Locations involved'), 'bank', 'orange'),
            );
    }
    return array();
}

// Active, non-service items whose available stock is at or below reorder level.
function ma_inventory_low_stock_sql()
{
    return "SELECT COUNT(*) FROM ".TB_PREF."stock_master s WHERE s.inactive=0 AND s.mb_flag NOT IN ('D','F')
        AND (COALESCE((SELECT SUM(qty) FROM ".TB_PREF."stock_moves m WHERE m.stock_id=s.stock_id),0)
          - COALESCE((SELECT SUM(d.quantity-d.qty_sent) FROM ".TB_PREF."sales_order_details d
              JOIN ".TB_PREF."sales_orders o ON o.order_no=d.order_no AND o.trans_type=d.trans_type
              WHERE d.stk_code=s.stock_id AND o.trans_type=".ST_SALESORDER."),0))
          <= COALESCE((SELECT SUM(reorder_level) FROM ".TB_PREF."loc_stock l WHERE l.stock_id=s.stock_id),0)";
}

function ma_inventory_stats_for_screen()
{
    $tab = ma_inventory_active_tab();
    $stats = ma_inventory_stats($tab);
    if ($stats) ma_render_stat_cards($stats);
}


// A row with just the tab's "+ New ..." button, for screens that have no filter bar.
function ma_new_bar($tab = null)
{
    $new = ma_inventory_new_action($tab ?? ma_inventory_active_tab());
    if (!$new) return;
    echo '<div class="ma-sales-filters ma-new-bar"><div class="ma-sales-filter-actions"><a class="ma-sales-new" href="'
        .ma_ui_escape(ma_ui_href($new[1])).'">'.ma_ui_icon('plus').' '.ma_ui_escape($new[0]).'</a></div></div>';
}

// Tabs whose FA screen is itself an editor get the same bar from the header.
function ma_inventory_header_new_bar()
{
    $tab = ma_inventory_active_tab();
    if (!ma_inventory_is_list_screen() && in_array($tab, array('movements', 'costs', 'reorder'), true))
        ma_new_bar($tab);
}

// Server-driven modal: the form is rendered only while open, so it keeps working
// with FA's AJAX refreshes. Closing is a plain link back to the list.
function ma_modal_begin()
{
    ob_start();
}

function ma_modal_end($title, $open, $keep = array())
{
    $html = ob_get_clean();
    if (!$open) return;
    // $keep: GET parameters the list itself needs (e.g. the tag type) to survive closing.
    $query = array_intersect_key($_GET, array_flip($keep));
    $close = $_SERVER['PHP_SELF'].($query ? '?'.http_build_query($query) : '');
    echo '<div class="ma-modal" role="dialog" aria-modal="true" aria-label="'.ma_ui_escape($title).'">'
        .'<a class="ma-modal-backdrop" href="'.ma_ui_escape($close).'" tabindex="-1" aria-hidden="true"></a>'
        .'<div class="ma-modal-panel"><div class="ma-modal-head"><h2>'.ma_ui_escape($title).'</h2>'
        .'<a class="ma-modal-close" href="'.ma_ui_escape($close).'" aria-label="'.ma_ui_escape(_('Close')).'">&times;</a></div>'
        .'<div class="ma-modal-body">'.$html.'</div></div></div>';
}
