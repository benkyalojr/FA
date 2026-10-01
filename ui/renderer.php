<?php
require_once __DIR__ . '/workspace.inc';
require_once __DIR__ . '/sales.inc';
require_once __DIR__ . '/customers.inc';
require_once __DIR__ . '/purchases.inc';
require_once __DIR__ . '/suppliers.inc';
require_once __DIR__ . '/inventory.inc';
require_once __DIR__ . '/banking.inc';
require_once __DIR__ . '/ledger.inc';
require_once __DIR__ . '/statements.inc';
require_once __DIR__ . '/taxes.inc';
require_once __DIR__ . '/communications.inc';
require_once __DIR__ . '/manufacturing.inc';
require_once __DIR__ . '/assets.inc';
require_once __DIR__ . '/dimensions.inc';
require_once __DIR__ . '/settings.inc';
require_once __DIR__ . '/setup.inc';

class ma_renderer
{
    function menu_header($title, $no_menu, $is_index)
    {
        global $path_to_root, $SysPrefs, $db_connections;
        $identity = require __DIR__ . '/config.php';
        $navigation = ma_ui_navigation($_SESSION['App']->applications ?? array());
        $quick_actions = ma_ui_quick_actions($navigation);
        $active_app = ma_ui_active_application($navigation);
        $user_name = $_SESSION['wa_current_user']->name ?? '';
        $company_name = $db_connections[user_company()]['name'] ?? $identity['name'];
        $initial = function_exists('mb_substr') ? mb_substr($user_name, 0, 1) : substr($user_name, 0, 1);
        if ($active_app) $_SESSION['sel_app'] = $active_app;
        $finance_module = !$no_menu ? ma_finance_active_module() : null;
        $customers_toolbar = !$no_menu && !$finance_module && ma_customers_active_tab() !== null;
        $suppliers_toolbar = !$no_menu && ma_suppliers_active_tab() !== null;
        $sales_toolbar = !$no_menu && !$customers_toolbar && ma_sales_is_sales_screen();
        $sales_list = $sales_toolbar && ma_sales_is_list_screen();
        $inventory_toolbar = !$no_menu && !$customers_toolbar && !$suppliers_toolbar && !$sales_toolbar && ma_inventory_is_inventory_screen();
        $inventory_list = $inventory_toolbar && ma_inventory_is_list_screen();
        $purchases_toolbar = !$no_menu && !$customers_toolbar && !$suppliers_toolbar && !$sales_toolbar && !$inventory_toolbar && ma_purchases_is_purchasing_screen();
        $purchases_list = $purchases_toolbar && ma_purchases_is_list_screen();
        if ($finance_module) {
            // A finance-style workspace owns the screen (e.g. fixed assets opened from the inventory screens).
            $customers_toolbar = $suppliers_toolbar = $sales_toolbar = $inventory_toolbar = $purchases_toolbar = false;
            $sales_list = $purchases_list = $inventory_list = false;
        }
        $finance_list = $finance_module && ma_finance_is_list_screen($finance_module);
        $list_screen = $finance_list || $sales_list || $purchases_list || $inventory_list || ($customers_toolbar && ma_customers_is_list_screen()) || ($suppliers_toolbar && ma_suppliers_is_list_screen());
        // A module's settings screens share one tab strip and the card-style list layout.
        $settings_ctx = !$no_menu ? ma_settings_context($active_app) : null;
        if ($settings_ctx) {
            $finance_module = null; $customers_toolbar = $suppliers_toolbar = $sales_toolbar = $inventory_toolbar = $purchases_toolbar = false;
            $finance_list = $sales_list = $purchases_list = $inventory_list = false;
            $list_screen = true;
        }
        include __DIR__ . '/views/workspace_header.php';
    }

    function menu_footer($no_menu, $is_index)
    {
        global $Ajax, $Pagehelp;
        $identity = require __DIR__ . '/config.php';
        if (!in_ajax()) {
            if (!$is_index) echo '</section>';
            echo '</main><footer class="ma-workspace-footer"><span>'.ma_ui_escape($identity['description']).'</span>';
            echo '<span id="hotkeyshelp"></span></footer></div></div>';
        }
        $Ajax->addUpdate(true, 'hotkeyshelp', implode('; ', $Pagehelp ?? array()));
    }

    function display_applications(&$app)
    {
        $navigation = ma_ui_navigation($app->applications);
        if (empty($_GET['application'])) {
            require_once __DIR__ . '/dashboard.inc';
            ma_dashboard($navigation);
            return;
        }
        $id = is_string($_GET['application']) ? $_GET['application'] : '';
        if (!isset($navigation[$id])) {
            echo '<div class="ma-empty">'.ma_ui_escape(_('No available actions in this module. Choose a module from the sidebar.')).'</div>';
            return;
        }
        if ($id === 'system') {
            ma_setup_overview($navigation);
            return;
        }
        if ($id === MA_SALES_APP) {
            ma_sales_overview();
            return;
        }
        if ($id === MA_PURCHASES_APP) {
            ma_purchases_overview();
            return;
        }
        if (in_array($id, array(MA_BANKING_APP, MA_LEDGER_APP, MA_STATEMENTS_APP), true)) {
            call_user_func(array(MA_BANKING_APP => 'ma_banking_overview', MA_LEDGER_APP => 'ma_ledger_overview', MA_STATEMENTS_APP => 'ma_statements_overview')[$id]);
            return;
        }
        $module = $navigation[$id];
        if (method_exists($app->applications[$id], 'render_index')) {
            $app->applications[$id]->render_index();
            return;
        }
        include __DIR__ . '/views/module.php';
    }
}
