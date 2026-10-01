<?php
require_once __DIR__ . '/workspace.inc';
require_once __DIR__ . '/sales.inc';
require_once __DIR__ . '/customers.inc';

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
        $customers_toolbar = !$no_menu && ma_customers_active_tab() !== null;
        $sales_toolbar = !$no_menu && !$customers_toolbar && ma_sales_is_sales_screen();
        $sales_list = $sales_toolbar && ma_sales_is_list_screen();
        $list_screen = $sales_list || ($customers_toolbar && ma_customers_is_list_screen());
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
        if ($id === MA_SALES_APP) {
            ma_sales_overview();
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
