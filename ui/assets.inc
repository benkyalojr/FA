<?php
// Fixed Assets workspace. Several of its screens are FA's inventory, purchasing
// and sales screens opened for assets (FixedAsset=1); those belong here, not to
// the Inventory workspace.
require_once __DIR__ . '/finance.inc';

define('MA_ASSETS_APP', 'assets');

function ma_assets_tabs()
{
    return array(
        'inquiry'      => array(_('Fixed Assets Inquiry'), 'search', 'fixed_assets/inquiry/stock_inquiry.php', 'SA_ASSETSANALYTIC'),
        'movements'    => array(_('Movements'), 'swap', 'inventory/inquiry/stock_movements.php?FixedAsset=1', 'SA_ASSETSTRANSVIEW'),
        'depreciation' => array(_('Process Depreciation'), 'chart', 'fixed_assets/process_depreciation.php', 'SA_DEPRECIATION'),
    );
}

// True for requests made on behalf of fixed assets (also after a form post).
function ma_assets_request()
{
    return isset($_GET['FixedAsset']) || !empty($_POST['fixed_asset']) || (($_POST['mb_flag'] ?? '') === 'F');
}

function ma_assets_active_tab()
{
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $name = basename($script);
    if ($name === 'index.php') return (is_string($_GET['application'] ?? null) && $_GET['application'] === MA_ASSETS_APP) ? 'inquiry' : null;
    if (strpos($script, '/fixed_assets/') !== false) {
        switch ($name) {
            case 'stock_inquiry.php': return 'inquiry';
            case 'process_depreciation.php': return 'depreciation';
            case 'fixed_asset_classes.php': return 'settings';
        }
        return 'entry';
    }
    if (!ma_assets_request()) return null;
    if ($name === 'stock_movements.php') return 'movements';
    if (basename(dirname($script)) === 'manage') return 'settings';
    return 'entry';   // purchase, transfer, disposal, sale
}

function ma_assets_is_list_screen() { return in_array(ma_assets_active_tab(), array('inquiry', 'movements'), true); }

function ma_assets_new_links() { return ma_finance_app_links(MA_ASSETS_APP, 0, array('fixed_assets/process_depreciation.php')); }
function ma_assets_reports_links() { return ma_finance_app_links(MA_ASSETS_APP, 1, array('fixed_assets/inquiry/stock_inquiry.php', 'inventory/inquiry/stock_movements.php')); }
