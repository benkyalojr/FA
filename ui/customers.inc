<?php
// Customers workspace: Customers / Branches / Contacts tabs shown on every
// screen of the directory. Lists link to FA's own edit forms; deletes keep
// FA's rules (nothing with transactions, orders or branches is removed).
require_once __DIR__ . '/sales.inc';

define('MA_CUSTOMERS_APP', 'customers');

function ma_customers_tabs()
{
    return array(
        'customers' => array(_('Customers'), 'people', 'sales/manage/customer_list.php'),
        'branches'  => array(_('Branches'),  'bank',   'sales/manage/branch_list.php'),
        'contacts'  => array(_('Contacts'),  'user',   'sales/manage/contact_list.php'),
    );
}

function ma_customers_active_tab()
{
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    if (strpos($script, '/sales/manage/') === false) return null;
    return array('customer_list.php'=>'customers', 'customers.php'=>'customers',
        'branch_list.php'=>'branches', 'customer_branches.php'=>'branches',
        'contact_list.php'=>'contacts')[basename($script)] ?? null;
}

// Pages that are lists (filter bar + table) rather than FA's edit forms.
function ma_customers_is_list_screen()
{
    return in_array(basename($_SERVER['SCRIPT_NAME'] ?? ''), array('customer_list.php', 'branch_list.php', 'contact_list.php'), true);
}

function ma_customers_new_links()
{
    $links = array();
    if ($_SESSION['wa_current_user']->can_access_page('SA_CUSTOMER')) {
        $links[] = array('label'=>_('New Customer'), 'link'=>'sales/manage/customers.php');
        $links[] = array('label'=>_('New Branch'), 'link'=>'sales/manage/customer_branches.php');
        $links[] = array('label'=>_('New Contact'), 'link'=>'sales/manage/customers.php?_tabs_sel=contacts');
    }
    return $links;
}

function ma_customers_toolbar()
{
    $active = ma_customers_active_tab();
    echo '<div class="ma-sales-toolbar"><nav class="ma-sales-tabs" aria-label="'.ma_ui_escape(_('Customer sections')).'">';
    foreach (ma_customers_tabs() as $key=>$tab)
        echo '<a class="ma-sales-tab'.($key === $active ? ' active' : '').'"'.($key === $active ? ' aria-current="page"' : '')
            .' href="'.ma_ui_escape(ma_ui_href($tab[2])).'">'.ma_ui_icon($tab[1]).'<span>'.ma_ui_escape($tab[0]).'</span></a>';
    echo '</nav></div>';
}

function ma_customers_topbar_new()
{
    ma_sales_menu(_('New'), 'plus', ma_customers_new_links(), true);
}

// ------------------------------------------------------------------ deletes

// FA's own rules for removing a customer; returns an error text or ''.
function ma_customer_delete_blocker($id)
{
    if (key_in_foreign_table($id, 'debtor_trans', 'debtor_no'))
        return _('there are transactions that refer to it');
    if (key_in_foreign_table($id, 'sales_orders', 'debtor_no'))
        return _('orders have been created against it');
    if (key_in_foreign_table($id, 'cust_branch', 'debtor_no'))
        return _('it still has branches');
    return '';
}

function ma_branch_delete_blocker($customer_id, $branch_code)
{
    if (branch_in_foreign_table($customer_id, $branch_code, 'debtor_trans'))
        return _('customer transactions exist for it');
    if (branch_in_foreign_table($customer_id, $branch_code, 'sales_orders'))
        return _('sales orders exist for it');
    return '';
}

// Selected ids come from per-row delete buttons (del_ID) or the bulk button.
function ma_selected_ids($prefix, $bulk_button, $selection)
{
    $ids = array();
    foreach ($_POST as $key=>$value)
        if (strpos($key, $prefix) === 0 && $value) $ids[] = substr($key, strlen($prefix));
    if (isset($_POST[$bulk_button]) && is_array($_POST[$selection] ?? null))
        foreach ($_POST[$selection] as $id=>$on) if ($on) $ids[] = $id;
    return array_values(array_unique($ids));
}

function ma_row_actions($edit_url, $delete_name, $label)
{
    return '<a class="ma-row-edit" href="'.ma_ui_escape(ma_ui_href($edit_url)).'">'.ma_ui_icon('file').' '._('Edit').'</a>';
}

function ma_row_delete($delete_name, $label)
{
    return '<button type="submit" class="ma-row-delete" name="'.ma_ui_escape($delete_name).'" value="1" aria-label="'
        .ma_ui_escape(sprintf(_('Delete %s'), $label)).'" onclick="return confirm(\''.ma_ui_escape(addslashes(sprintf(_('Delete %s?'), $label))).'\')">&times;</button>';
}

function ma_row_select($name, $label)
{
    return '<input type="checkbox" name="'.ma_ui_escape($name).'" value="1" aria-label="'.ma_ui_escape(sprintf(_('Select %s'), $label)).'">';
}

// Bulk delete + page size, shown under the table.
function ma_list_bottom($bulk_button, $show_page_size = true)
{
    echo '<div class="ma-sales-bulk">';
    if ($bulk_button)
        echo '<button type="submit" class="ma-bulk-delete" name="'.ma_ui_escape($bulk_button).'" value="1" onclick="return confirm(\''
            .ma_ui_escape(addslashes(_('Delete the selected records?'))).'\')">'.ma_ui_escape(_('Delete selected')).'</button>';
    if ($show_page_size) {
        echo '<label class="ma-sales-pagesize">'.ma_ui_escape(_('Per page')).' <select name="page_size" onchange="this.form.submit()">';
        foreach (array(10, 25, 50, 100) as $n)
            echo '<option value="'.$n.'"'.((int)get_post('page_size', user_query_size()) === $n ? ' selected' : '').'>'.$n.'</option>';
        echo '</select></label>';
    }
    echo '</div>';
}
