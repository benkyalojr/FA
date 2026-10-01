<?php
// Setup landing page: every system screen the user may open, grouped in cards.
// Items are matched against the access-filtered menu, so people only see what
// their role allows; anything in the Setup menu that is not listed below still
// appears under "Other".

function ma_setup_groups()
{
    return array(
        array(_('Company'), 'settings', 'blue', array(
            array(_('Company Setup'), _('Business name, registered address, logo and the contact details printed on invoices and reports.'), 'admin/company_preferences.php?'),
            array(_('System and GL Setup'), _('Default GL accounts for sales, purchases, tax and bank charges, and the system-wide accounting options.'), 'admin/gl_setup.php?'),
            array(_('Financial Year'), _('Create and manage financial years, close completed years and open new ones.'), 'admin/fiscalyears.php?'),
            array(_('Transaction References'), _('The numbering format for invoices, orders, payments and journals.'), 'admin/forms_setup.php?'),
            array(_('Payment Terms'), _('Due days, early-payment terms and overdue rules for customers and suppliers.'), 'admin/payment_terms.php?'),
            array(_('Shipping Companies'), _('The carriers you can choose on orders and invoices.'), 'admin/shipping_companies.php?'),
            array(_('Contact Categories'), _('Types of contact (general, invoice, order...) used on customers, suppliers and branches.'), 'admin/crm_categories.php?'),
            array(_('Taxes'), _('Tax periods and returns, tax rates, tax groups and eTIMS now live in the Taxes workspace.'), 'taxes/tax_periods.php?'),
            array(_('Companies'), _('Create or update the companies (databases) on this installation.'), 'admin/create_coy.php?'),
        )),
        array(_('Display'), 'grid', 'purple', array(
            array(_('Display Setup'), _('Date format, decimals, language, theme and the other display preferences.'), 'admin/display_prefs.php?'),
            array(_('Print Profiles'), _('Named print configurations that map document types to printers and paper sizes.'), 'admin/print_profiles.php?'),
            array(_('Printers'), _('Register the printers used for invoices, reports and documents.'), 'admin/printers.php?'),
            array(_('Points of Sale'), _('POS terminal settings, the cash and bank accounts used, and the location sold from.'), 'sales/manage/sales_points.php?'),
            array(_('Themes'), _('Install and activate visual themes.'), 'admin/inst_theme.php?'),
            array(_('Languages'), _('Install and update the interface languages.'), 'admin/inst_lang.php?'),
        )),
        array(_('Users and Access'), 'user', 'orange', array(
            array(_('Users'), _('Create and manage user accounts, reset passwords and choose each user\'s company and role.'), 'admin/users.php?'),
            array(_('Roles and Permissions'), _('Define which modules, transactions and reports each role can open.'), 'admin/security_roles.php?'),
            array(_('Audit Trail'), _('Browse the history of what was created, changed and deleted, and by whom.'), 'admin/system_audit.php'),
            array(_('Void a Transaction'), _('Reverse a wrongly posted transaction while keeping the audit trail.'), 'admin/void_transaction.php?'),
            array(_('View or Print Transactions'), _('Look up any transaction by type and number, then view or print it.'), 'admin/view_print_transaction.php?'),
            array(_('Attach Documents'), _('Files attached to customers, suppliers, items, bank accounts and transactions.'), 'admin/attachments.php?filterType=20'),
        )),
        array(_('Email and Messaging'), 'message', 'green', array(
            array(_('Gateways'), _('SMTP mail server, the SMS gateway and WhatsApp credentials, with test sends.'), 'communications/manage/gateway_settings.php?'),
            array(_('Message Templates'), _('The subject and body of the automatic messages for invoices, payments and more.'), 'communications/manage/templates.php?'),
            array(_('Notification Rules'), _('Which events send on which channel.'), 'communications/manage/notification_rules.php?'),
            array(_('Delivery Log'), _('Every message sent, queued, failed or skipped.'), 'communications/inquiry/comm_log_inquiry.php?'),
            array(_('Email Log'), _('Outgoing email only.'), 'communications/inquiry/email_log_inquiry.php?'),
            array(_('SMS Log'), _('Outgoing SMS only.'), 'communications/inquiry/sms_log_inquiry.php?'),
        )),
        array(_('System and Maintenance'), 'gauge', 'teal', array(
            array(_('Backup and Restore'), _('Back up the database and restore a previous backup.'), 'admin/backups.php?'),
            array(_('System Diagnostics'), _('Server, PHP and database checks.'), 'admin/system_diagnostics.php?'),
            array(_('Background Tasks'), _('Queued work such as messages and eTIMS stamping: status, errors and retries.'), 'admin/bg_tasks_inquiry.php?'),
            array(_('Database Migrations'), _('Apply pending database updates (take a backup first).'), 'admin/run_migrations.php'),
            array(_('Software Upgrade'), _('Update the database after the software is upgraded.'), 'admin/inst_upgrade.php?'),
            array(_('Extensions'), _('Install and activate extension modules.'), 'admin/inst_module.php?'),
            array(_('Chart of Accounts'), _('Install or activate a chart of accounts.'), 'admin/inst_chart.php?'),
        )),
    );
}

function ma_setup_overview($navigation)
{
    $shown = array();
    echo '<div class="ma-setup"><h2 class="ma-setup-kicker">'.ma_ui_escape(_('System')).'</h2>';
    $groups = ma_setup_groups();
    // Setup menu entries nobody listed above.
    $other = array();
    foreach ($navigation['system']['groups'] ?? array() as $g)
        foreach ($g['links'] as $item) $other[menu_rights_normalize($item['link'])] = array($item['label'], '', $item['link']);
    foreach ($groups as $g) foreach ($g[3] as $it) unset($other[menu_rights_normalize($it[2])]);
    if ($other) $groups[] = array(_('Other'), 'file', 'blue', array_values($other));

    foreach ($groups as $g) {
        $items = array();
        foreach ($g[3] as $it)
            if (ma_ui_find_link($navigation, $it[2])) $items[] = $it;
        if (!$items) continue;
        echo '<section class="ma-panel ma-setup-card"><div class="ma-setup-head"><span class="ma-setup-icon tone-'.ma_ui_escape($g[2]).'">'.ma_ui_icon($g[1]).'</span><h3>'.ma_ui_escape($g[0]).'</h3></div><div class="ma-setup-grid">';
        foreach ($items as $it)
            echo '<a class="ma-setup-item" href="'.ma_ui_escape(ma_ui_href($it[2])).'"><strong>'.ma_ui_escape($it[0]).'</strong>'
                .($it[1] !== '' ? '<span>'.ma_ui_escape($it[1]).'</span>' : '').'</a>';
        echo '</div></section>';
    }
    echo '</div>';
}
