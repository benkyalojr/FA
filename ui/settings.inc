<?php
// Module Settings: each workspace's Settings button opens its maintenance
// screens as one tab strip (Sales Types, Sales Persons, ...). The tabs come from
// the module's own menu, so a screen added to FA's menu shows up automatically.

// Workspace application id => prefix of its ma_<prefix>_tabs() main tabs.
function ma_settings_prefixes()
{
    return array('orders' => 'sales', 'customers' => 'customers', 'AP' => 'purchases', 'suppliers' => 'suppliers',
        'stock' => 'inventory', 'banking' => 'banking', 'GL' => 'ledger', 'statements' => 'statements', 'taxes' => 'taxes', 'communications' => 'communications',
        'manuf' => 'manufacturing', 'assets' => 'assets', 'proj' => 'dimensions');
}

function ma_settings_path($link)
{
    $parts = parse_url($link);
    return array(ltrim($parts['path'] ?? '', './'), $parts['query'] ?? '');
}

// Accessible maintenance screens of an application, minus the ones that are
// already main tabs of its workspace. Returns array(key => array(label, link)).
function ma_settings_tabs($app_id)
{
    static $cache = array();
    if (isset($cache[$app_id])) return $cache[$app_id];
    $app = $_SESSION['App']->applications[$app_id] ?? null;
    $tabs = array();
    if (!$app || $app_id === 'system') return $cache[$app_id] = $tabs;
    $main = array();
    $prefix = ma_settings_prefixes()[$app_id] ?? null;
    if ($prefix && function_exists('ma_'.$prefix.'_tabs'))
        foreach (call_user_func('ma_'.$prefix.'_tabs') as $t) $main[] = ma_settings_path($t[2])[0];
    foreach ($app->modules as $i => $module) {
        // Everything except the Transactions and Inquiries/Reports modules is settings.
        if (in_array($module->name, array(_('Transactions'), _('Inquiries and Reports'), _('Tax Returns')), true)) continue;
        foreach (array_merge($module->lappfunctions, $module->rappfunctions) as $item) {
            if (!$item->label || !$item->link || !$_SESSION['wa_current_user']->can_access_page($item->access)) continue;
            $path = ma_settings_path($item->link)[0];
            if (in_array($path, $main, true)) continue;
            // Screens a workspace shows elsewhere: the Items editor opens from the item list, and the
            // Purchases workspace has no settings of its own (suppliers live in the Suppliers workspace).
            $skip = array('stock' => array('inventory/manage/items.php'), 'AP' => array('purchasing/manage/suppliers.php'));
            if (in_array($path, $skip[$app_id] ?? array(), true) && strpos($item->link, 'FixedAsset') === false) continue;
            if ($app_id === 'orders' && preg_match('~sales/manage/(customers|customer_branches)\.php~', $path)) continue;
            $tabs[] = array(ma_ui_label($item->label), $item->link);
        }
    }
    // Preferred order per workspace; anything else keeps FA's menu order after these.
    $order = array('orders' => array('sales_types', 'sales_people', 'sales_areas', 'sales_groups', 'credit_status'));
    if (isset($order[$app_id])) {
        $rank = function ($tab) use ($order, $app_id) {
            $r = array_search(basename(ma_settings_path($tab[1])[0], '.php'), $order[$app_id], true);
            return $r === false ? 99 : $r;
        };
        $i = 0; $keyed = array();
        foreach ($tabs as $t) $keyed[] = array($rank($t), $i++, $t);
        usort($keyed, function ($a, $b) { return $a[0] <=> $b[0] ?: $a[1] <=> $b[1]; });
        $tabs = array_map(function ($k) { return $k[2]; }, $keyed);
    }
    return $cache[$app_id] = $tabs;
}

function ma_settings_match($link)
{
    list($path, $query) = ma_settings_path($link);
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    if (!$path || substr($script, -strlen('/'.$path)) !== '/'.$path) return -1;
    parse_str($query, $params);
    $score = 0;
    // Tag screens post their type as a number once a form has been submitted.
    if ($path === 'admin/tags.php' && !isset($_GET['type']) && isset($_POST['type']) && isset($params['type']))
        $params['type'] = array('account' => 1, 'dimension' => 2)[$params['type']] ?? $params['type'];
    foreach ($params as $k => $v) {
        if (!isset($_GET[$k]) && isset($_POST[$k]) && $path === 'admin/tags.php' && $_POST[$k] == $v) $score++;
        elseif (isset($_GET[$k]) && $_GET[$k] == $v) $score++;
        else return -1;
    }
    return $score;
}

// The settings screen being shown, as array(app id, tabs, active index), or null.
function ma_settings_context($active_app)
{
    static $ctx = false;
    if ($ctx !== false) return $ctx;
    $ctx = null;
    if (!isset($_SESSION['App'])) return $ctx;
    $ids = array_keys(ma_settings_prefixes());
    if ($active_app && !in_array($active_app, $ids, true)) return $ctx; // setup, manufacturing, ... keep their own look
    if ($active_app) $ids = array($active_app);
    foreach ($ids as $id) {
        $best = -1; $found = null;
        foreach (ma_settings_tabs($id) as $i => $tab) {
            $score = ma_settings_match($tab[1]);
            if ($score > $best) { $best = $score; $found = $i; }
        }
        if ($found !== null) { $ctx = array($id, ma_settings_tabs($id), $found); break; }
    }
    return $ctx;
}

// Settings button of a workspace toolbar: opens the first settings screen.
function ma_settings_button($app_id, $active = false)
{
    $tabs = ma_settings_tabs($app_id);
    if (!$tabs) return;
    echo '<a class="ma-settings-btn'.($active ? ' active' : '').'" href="'.ma_ui_escape(ma_ui_href($tabs[0][1])).'">'
        .ma_ui_escape(_('Settings')).' '.ma_ui_icon('settings').'</a>';
}

function ma_settings_toolbar($ctx)
{
    list($app_id, $tabs, $active) = $ctx;
    $app = $_SESSION['App']->applications[$app_id];
    $back = array('orders' => 'index.php?application=orders', 'customers' => 'sales/manage/customer_list.php', 'suppliers' => 'purchasing/manage/supplier_list.php',
        'stock' => 'inventory/manage/item_list.php', 'taxes' => 'taxes/tax_periods.php', 'communications' => 'communications/inquiry/comm_log_inquiry.php',
        'manuf' => 'manufacturing/search_work_orders.php?outstanding_only=1', 'assets' => 'fixed_assets/inquiry/stock_inquiry.php', 'proj' => 'dimensions/inquiry/search_dimensions.php?outstanding_only=1');
    echo '<div class="ma-sales-toolbar"><nav class="ma-sales-tabs" aria-label="'.ma_ui_escape(_('Settings')).'">';
    echo '<a class="ma-sales-tab ma-settings-back" href="'.ma_ui_escape(ma_ui_href($back[$app_id] ?? 'index.php?application='.rawurlencode($app_id))).'" title="'
        .ma_ui_escape(sprintf(_('Back to %s'), ma_ui_label($app->name))).'">&lsaquo; <span>'.ma_ui_escape(ma_ui_label($app->name)).'</span></a>';
    foreach ($tabs as $i => $tab)
        echo '<a class="ma-sales-tab'.($i === $active ? ' active' : '').'"'.($i === $active ? ' aria-current="page"' : '')
            .' href="'.ma_ui_escape(ma_ui_href($tab[1])).'"><span>'.ma_ui_escape($tab[0]).'</span></a>';
    echo '</nav><div class="ma-sales-actions">';
    if (function_exists('ma_finance_app_links')) ma_sales_menu(_('Reports'), 'chart', ma_finance_app_links($app_id, 1));
    ma_settings_button($app_id, true);
    echo '</div></div>';
}

// "Add New" bar of a settings list; the editor opens as a modal (?new=1).
function ma_settings_new_bar($label, $keep = array())
{
    $query = array_merge(array_intersect_key($_GET, array_flip($keep)), array('new' => 1));
    echo '<div class="ma-sales-filters ma-new-bar"><div class="ma-sales-filter-actions"><a class="ma-sales-new" href="'
        .ma_ui_escape($_SERVER['PHP_SELF'].'?'.http_build_query($query)).'">'.ma_ui_icon('plus').' '.ma_ui_escape($label).'</a></div></div>';
}

// Short help beside a field label (used by the eTIMS and Communications set-up
// screens): an info marker whose text shows on hover/focus and to screen readers.
if (!function_exists('field_hint')) {
    function field_hint($text)
    {
        return ' <span class="ma-hint" tabindex="0" role="note" title="'.ma_ui_escape($text).'" aria-label="'.ma_ui_escape($text).'">i</span>';
    }
}

// A screen that needs some master data first: explain what to create, with a link,
// instead of FrontAccounting's bare red error. Ends the page when $ok is false.
function ma_require_data($ok, $title, $text, $link_label, $link)
{
    if ($ok) return;
    echo '<div class="ma-empty"><strong>'.ma_ui_escape($title).'</strong><p>'.ma_ui_escape($text).'</p>'
        .'<p><a class="ma-sales-new" href="'.ma_ui_escape(ma_ui_href($link)).'">'.ma_ui_escape($link_label).'</a></p></div>';
    end_page();
    exit;
}
