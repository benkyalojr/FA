<?php
require_once __DIR__.'/compat.inc';
// Application navigation is derived from FA's enabled modules and link rights.
function ma_ui_escape($value)
{
    $encoding = $_SESSION['language']->encoding ?? 'UTF-8';
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE,
        strtolower($encoding) === 'iso-8859-2' ? 'ISO-8859-1' : $encoding, false);
}

function ma_ui_label($label)
{
    return html_entity_decode(strip_tags(access_string($label, true)), ENT_QUOTES, $_SESSION['language']->encoding ?? 'UTF-8');
}

function ma_ui_href($link)
{
    global $path_to_root;
    if (preg_match('~^https?://~i', $link) || substr($link, 0, 1) === '/') return $link;
    return $path_to_root . '/' . ltrim($link, './');
}

function ma_ui_navigation($applications)
{
    $result = array();
    $icons = array('orders'=>'receipt', 'customers'=>'people', 'AP'=>'cart', 'farmers'=>'leaf', 'stock'=>'package',
        'assets'=>'pie', 'manuf'=>'factory', 'transport'=>'truck', 'PR'=>'wallet', 'HRM'=>'people',
        'communications'=>'message', 'proj'=>'layers', 'GL'=>'bank', 'system'=>'settings');
    foreach ($applications as $app) {
        if (!$app->enabled) continue;
        $groups = array();
        foreach ($app->modules as $module) {
            $links = array();
            foreach (array_merge($module->lappfunctions, $module->rappfunctions) as $item) {
                if (!$item->label || !$item->link || !$_SESSION['wa_current_user']->can_access_page($item->access)) continue;
                $links[] = array('label'=>ma_ui_label($item->label), 'link'=>$item->link, 'access'=>$item->access);
            }
            if ($links) $groups[] = array('label'=>ma_ui_label($module->name), 'links'=>$links);
        }
        if ($groups) $result[$app->id] = array('id'=>$app->id, 'label'=>ma_ui_label($app->name),
            'icon'=>$icons[$app->id] ?? 'grid', 'groups'=>$groups);
    }
    return $result;
}

function ma_ui_find_link($navigation, $link)
{
    $needle = menu_rights_normalize($link);
    foreach ($navigation as $app)
        foreach ($app['groups'] as $group)
            foreach ($group['links'] as $item)
                if (menu_rights_normalize($item['link']) === $needle) return $item;
    return null;
}

function ma_ui_quick_actions($navigation)
{
    $candidates = array(
        array('Quotation', 'file', 'sales/sales_order_entry.php?NewQuotation=Yes'),
        array('Sales Order', 'cart', 'sales/sales_order_entry.php?NewOrder=Yes'),
        array('Sales Invoice', 'receipt', 'sales/sales_order_entry.php?NewInvoice=0'),
        array('Cust. Payment', 'wallet', 'sales/customer_payments.php'),
        array('Milk Collection', 'leaf', 'farmers/milk_collection.php'),
        array('Purchase Order', 'package', 'purchasing/po_entry_items.php?NewOrder=Yes'),
        array('Purchase Order', 'package', 'purchasing/procurement_entry.php?kind=PO&new=1'),
        array('Purch. Invoice', 'receipt', 'purchasing/supplier_invoice.php?New=1'),
        array('Supp. Payment', 'wallet', 'purchasing/supplier_payment.php'),
        array('Bank Payment', 'arrow-up', 'gl/gl_bank.php?NewPayment=Yes'),
        array('Bank Deposit', 'arrow-down', 'gl/gl_bank.php?NewDeposit=Yes'),
        array('Journal Entry', 'book', 'gl/gl_journal.php?NewJournal=Yes'),
    );
    $actions = array();
    foreach ($candidates as $candidate) {
        $item = ma_ui_find_link($navigation, $candidate[2]);
        if ($item && !isset($actions[$candidate[0]])) {
            $item['label'] = _($candidate[0]);
            $item['icon'] = $candidate[1];
            $actions[$candidate[0]] = $item;
        }
    }
    return array_values($actions);
}

function ma_ui_active_application($navigation)
{
    if (isset($_GET['application']) && is_string($_GET['application']))
        return isset($navigation[$_GET['application']]) ? $_GET['application'] : '';
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    if (preg_match('~/(?:index|admin/dashboard)\.php$~', $script)) return '';
    $selected = '';
    $best = -1;
    foreach ($navigation as $id => $app) {
        foreach ($app['groups'] as $group) foreach ($group['links'] as $item) {
            $parts = parse_url($item['link']);
            $path = ltrim($parts['path'] ?? '', './');
            if (!$path || substr($script, -strlen('/'.$path)) !== '/'.$path) continue;
            parse_str($parts['query'] ?? '', $params);
            $score = 0;
            foreach ($params as $key=>$value) {
                if (isset($_GET[$key]) && $_GET[$key] == $value) $score++;
                else { $score = -1; break; }
            }
            if ($score > $best) { $best = $score; $selected = $id; }
        }
    }
    return $selected ?: (isset($navigation[$_SESSION['sel_app'] ?? '']) ? $_SESSION['sel_app'] : '');
}

function ma_ui_icon($name)
{
    static $paths = array(
        'grid'=>'<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
        'gauge'=>'<path d="M4 19a10 10 0 1 1 16 0Z"/><path d="m12 13 4-4M6 12h1m5-6v1m5 5h1"/>',
        'bank'=>'<path d="m3 9 9-6 9 6H3Zm2 3v6m5-6v6m4-6v6m5-6v6M3 21h18"/>',
        'receipt'=>'<path d="M5 3h14v18l-3-2-4 2-4-2-3 2V3Zm4 5h6m-6 4h6"/>',
        'cart'=>'<path d="M3 3h2l3 12h10l3-9H6"/><circle cx="9" cy="20" r="1"/><circle cx="18" cy="20" r="1"/>',
        'package'=>'<path d="m3 7 9-4 9 4v10l-9 4-9-4V7Zm0 0 9 4 9-4M12 11v10M8 5l9 4"/>',
        'people'=>'<circle cx="9" cy="8" r="3"/><path d="M3 21v-2a6 6 0 0 1 12 0v2M16 5a3 3 0 0 1 0 6m2 4a5 5 0 0 1 3 4v2"/>',
        'user'=>'<circle cx="12" cy="8" r="4"/><path d="M4 21v-1a8 8 0 0 1 16 0v1"/>',
        'leaf'=>'<path d="M20 3C8 2 2 8 5 15s16 5 15-12ZM4 21l10-11"/>',
        'truck'=>'<path d="M3 5h11v12H3V5Zm11 5h4l3 4v3h-7"/><circle cx="7" cy="18" r="2"/><circle cx="17" cy="18" r="2"/>',
        'factory'=>'<path d="M3 21V10l6 3V8l6 3V3h4l2 18H3Zm4-4h1m4 0h1m4 0h1"/>',
        'wallet'=>'<path d="M20 8V5H5a2 2 0 0 0 0 4h16v12H5a2 2 0 0 1-2-2V7m18 6h-5v4h5"/>',
        'pie'=>'<path d="M12 3v9h9a9 9 0 1 1-9-9Zm4 0a9 9 0 0 1 5 5h-5V3Z"/>',
        'layers'=>'<path d="m3 7 9-4 9 4-9 4-9-4Zm0 5 9 4 9-4M3 17l9 4 9-4"/>',
        'message'=>'<path d="M21 11a9 9 0 0 1-9 9H3l2-5a9 9 0 1 1 16-4Z"/>',
        'settings'=>'<path d="m10 3-1 3-3 1-3 3 2 3-1 3 3 3 3-1 3 2 3-2 3-1 1-3-1-3 1-3-3-3-3 1-3-1Z"/><circle cx="12" cy="12" r="3"/>',
        'file'=>'<path d="M5 3h9l5 5v13H5V3Zm9 0v5h5M9 12h6m-6 4h6"/>',
        'book'=>'<path d="M4 3h16v18H6a2 2 0 0 1-2-2V3Zm0 14h16M8 7h8m-8 4h5"/>',
        'arrow-up'=>'<path d="M12 20V4m-6 6 6-6 6 6"/>',
        'arrow-down'=>'<path d="M12 4v16m-6-6 6 6 6-6"/>',
        'chevron'=>'<path d="m6 9 6 6 6-6"/>',
        'close'=>'<path d="m6 6 12 12M6 18 18 6"/>',
        'sun'=>'<circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M2 12h2m16 0h2M5 5l1 1m12 12 1 1M5 19l1-1M18 6l1-1"/>',
        'calendar'=>'<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4m8-4v4"/>',
        'chart'=>'<path d="M3 3v18h18M7 16l5-6 4 3 5-7"/>',
        'help'=>'<circle cx="12" cy="12" r="9"/><path d="M9 9a3 3 0 1 1 5 2c-2 1-2 2-2 3m0 3h.01"/>',
        'search'=>'<circle cx="10" cy="10" r="7"/><path d="m15 15 6 6"/>',
        'logout'=>'<path d="M9 3H4v18h5m5-16 7 7-7 7M8 12h13"/>',
        'lock'=>'<rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3m-4 5v2"/>',
        'undo'=>'<path d="M9 14 4 9l5-5"/><path d="M4 9h10a6 6 0 0 1 0 12h-3"/>',
        'bell'=>'<path d="M6 8a6 6 0 0 1 12 0c0 7 3 8 3 8H3s3-1 3-8Zm4 11a2 2 0 0 0 4 0"/>',
        'swap'=>'<path d="M17 4l4 4-4 4M21 8H8m-1 4-4 4 4 4m-4-4h13"/>',
        'plus'=>'<path d="M12 5v14M5 12h14"/>',
        'clock'=>'<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'alert'=>'<path d="M12 3 2 20h20L12 3Zm0 6v5m0 3h.01"/>',
        'check'=>'<circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/>',
    );
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.65" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.($paths[$name] ?? $paths['grid']).'</svg>';
}
