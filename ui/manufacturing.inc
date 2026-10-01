<?php
// Manufacturing workspace: work order and costing inquiries as tabs; bills of
// material and work centres are its settings screens (see ui/settings.inc).
require_once __DIR__ . '/finance.inc';

define('MA_MANUFACTURING_APP', 'manuf');

function ma_manufacturing_tabs()
{
    return array(
        'orders'    => array(_('Outstanding Work Orders'), 'factory', 'manufacturing/search_work_orders.php?outstanding_only=1', 'SA_MANUFTRANSVIEW'),
        'inquiry'   => array(_('Work Order Inquiry'), 'search', 'manufacturing/search_work_orders.php', 'SA_MANUFTRANSVIEW'),
        'cost'      => array(_('Costed BOM'), 'chart', 'manufacturing/inquiry/bom_cost_inquiry.php', 'SA_WORKORDERCOST'),
        'whereused' => array(_('Where Used'), 'layers', 'manufacturing/inquiry/where_used_inquiry.php', 'SA_WORKORDERANALYTIC'),
    );
}

function ma_manufacturing_active_tab()
{
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $name = basename($script);
    if ($name === 'index.php') return (is_string($_GET['application'] ?? null) && $_GET['application'] === MA_MANUFACTURING_APP) ? 'orders' : null;
    if (strpos($script, '/manufacturing/') === false) return null;
    if (basename(dirname($script)) === 'manage') return 'settings';
    switch ($name) {
        case 'search_work_orders.php': return !empty($_GET['outstanding_only']) ? 'orders' : 'inquiry';
        case 'bom_cost_inquiry.php': return 'cost';
        case 'where_used_inquiry.php': return 'whereused';
    }
    return 'entry';   // work order entry, release, issue, production, costs...
}

function ma_manufacturing_is_list_screen()
{
    return in_array(ma_manufacturing_active_tab(), array('orders', 'inquiry', 'cost', 'whereused'), true);
}

function ma_manufacturing_new_links() { return ma_finance_app_links(MA_MANUFACTURING_APP, 0, array('manufacturing/search_work_orders.php')); }
function ma_manufacturing_reports_links() { return ma_finance_app_links(MA_MANUFACTURING_APP, 1, array_map(function ($t) { return strtok($t[2], '?'); }, ma_manufacturing_tabs())); }
