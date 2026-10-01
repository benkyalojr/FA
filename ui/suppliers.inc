<?php
// Suppliers workspace: Suppliers and Contacts tabs (suppliers have no branches).
// Lists link to FA's own supplier form; deletes keep FA's rules.
require_once __DIR__ . '/customers.inc';

define('MA_SUPPLIERS_APP', 'suppliers');

function ma_suppliers_tabs()
{
    return array(
        'suppliers' => array(_('Suppliers'), 'user',  'purchasing/manage/supplier_list.php'),
        'contacts'  => array(_('Contacts'),  'people', 'purchasing/manage/supplier_contact_list.php'),
    );
}

function ma_suppliers_active_tab()
{
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    if (strpos($script, '/purchasing/manage/') === false) return null;
    return array('supplier_list.php'=>'suppliers', 'suppliers.php'=>'suppliers',
        'supplier_contact_list.php'=>'contacts')[basename($script)] ?? null;
}

function ma_suppliers_is_list_screen()
{
    return in_array(basename($_SERVER['SCRIPT_NAME'] ?? ''), array('supplier_list.php', 'supplier_contact_list.php'), true);
}

function ma_suppliers_new_links()
{
    $links = array();
    if ($_SESSION['wa_current_user']->can_access_page('SA_SUPPLIER')) {
        $links[] = array('label'=>_('New Supplier'), 'link'=>'purchasing/manage/suppliers.php');
        $links[] = array('label'=>_('New Contact'), 'link'=>'purchasing/manage/suppliers.php?_tabs_sel=contacts');
    }
    return $links;
}

function ma_suppliers_toolbar()
{
    $active = ma_suppliers_active_tab();
    echo '<div class="ma-sales-toolbar"><nav class="ma-sales-tabs" aria-label="'.ma_ui_escape(_('Supplier sections')).'">';
    foreach (ma_suppliers_tabs() as $key=>$tab)
        echo '<a class="ma-sales-tab'.($key === $active ? ' active' : '').'"'.($key === $active ? ' aria-current="page"' : '')
            .' href="'.ma_ui_escape(ma_ui_href($tab[2])).'">'.ma_ui_icon($tab[1]).'<span>'.ma_ui_escape($tab[0]).'</span></a>';
    echo '</nav></div>';
}

function ma_suppliers_topbar_new()
{
    ma_sales_menu(_('New'), 'plus', ma_suppliers_new_links(), true);
}

// FA's rules for removing a supplier; returns an error text or ''.
function ma_supplier_delete_blocker($id)
{
    if (key_in_foreign_table($id, 'supp_trans', 'supplier_id'))
        return _('there are transactions that refer to it');
    if (key_in_foreign_table($id, 'purch_orders', 'supplier_id'))
        return _('purchase orders have been created against it');
    return '';
}
