<?php
require_once __DIR__.'/workspace.inc';

function ma_report_category($id, $name)
{
    $labels = array(0=>_('Sales'), 1=>_('Purchases'), 2=>_('Items & Services'));
    $icons = array(0=>'chart', 1=>'cart', 2=>'package', 3=>'factory', 4=>'layers',
        5=>'bank', 6=>'book', 7=>'pie', 8=>'people', 9=>'truck', 12=>'layers');
    return array('label'=>$labels[$id] ?? ma_ui_label($name), 'icon'=>$icons[$id] ?? 'file');
}

function ma_report_description($report)
{
    // Descriptions supplement the installed catalogue; they never register reports.
    $descriptions = array(
        101=>_('Review customer balances and outstanding amounts.'),
        102=>_('View customer balances grouped by ageing period.'),
        103=>_('List customer details and filter by sales activity.'),
        104=>_('Print item prices by currency, category and sales type.'),
        105=>_('Review sales orders and outstanding deliveries.'),
        106=>_('Compare sales activity by salesperson.'),
        107=>_('Select customer invoices to print or email.'),
        108=>_('Generate account statements for your customers.'),
        109=>_('Select sales orders and print or email their documents.'),
        110=>_('Print delivery notes or packing slips.'),
        111=>_('Select sales quotations to print or email.'),
        112=>_('Print or email receipts for customer payments.'),
        113=>_('Select customer credit notes to print or email.'),
        114=>_('Summarise sales for your selected tax period.'),
        115=>_('Compare customer debit, credit and closing balances.'),
        201=>_('Review supplier account balances and outstanding amounts.'),
        202=>_('View supplier balances grouped by ageing period.'),
        203=>_('Review supplier payments and outstanding balances.'),
        204=>_('Identify goods received that have not yet been invoiced.'),
        205=>_('List supplier details and filter by account activity.'),
        206=>_('Compare supplier debit, credit and closing balances.'),
        209=>_('Choose purchase orders to print or email.'),
        210=>_('Print or email remittance advice for supplier payments.'),
        301=>_('Review stock quantities and inventory values.'),
        302=>_('Review inventory levels for stock planning.'),
        303=>_('Prepare stock count sheets by category and location.'),
        304=>_('Analyse inventory sales for a selected period.'),
        305=>_('Review the value of goods received.'),
        306=>_('Analyse inventory purchases by item and supplier.'),
        307=>_('Track inventory movements over a selected period.'),
        308=>_('Review inventory movements with their associated costs.'),
        309=>_('Summarise sales quantities and values by item.'),
        310=>_('Review inventory purchases using transaction dates.'),
        401=>_('Print component requirements from bills of materials.'),
        402=>_('Review work orders and production activity.'),
        409=>_('Select work orders and print their documents.'),
        451=>_('Review fixed assets, values and depreciation.'),
        501=>_('Compare balances across your dimensions.'),
        601=>_('Review bank account transactions for a selected period.'),
        602=>_('Compare bank balances with reconciled transactions.'),
        701=>_('Print your chart of accounts and account groups.'),
        702=>_('Review posted journal entries by date and type.'),
        704=>_('Inspect transactions across selected ledger accounts.'),
        705=>_('Compare expenses across the months of a financial year.'),
        706=>_('Review assets, liabilities and equity.'),
        707=>_('Compare income and expenses for a selected period.'),
        708=>_('Compare ledger debits, credits and closing balances.'),
        709=>_('Review tax amounts for your selected reporting period.'),
        710=>_('Trace transactions by date, type and user.'),
        801=>_('List employee records, with optional inactive staff.'),
        802=>_('Review staff leave applications for a selected period.'),
        803=>_('Review staff leave entitlements and balances.'),
    );
    return $descriptions[$report->id] ?? _('Choose your filters and generate this report.');
}

function ma_reports_catalogue($reports)
{
    $categories = array();
    foreach ($reports->ar_classes as $id=>$name) {
        $right = menu_right_for_link('reporting/reports_main.php?Class='.$id);
        if ($right && !$_SESSION['wa_current_user']->can_access($right)) continue;
        if (empty($reports->ar_reports[$id])) continue;
        $category = ma_report_category($id, $name);
        $category['id'] = (string)$id;
        $category['reports'] = $reports->ar_reports[$id];
        $categories[(string)$id] = $category;
    }
    return $categories;
}

function ma_report_filter_panel($reports, $report, $class)
{
    global $SysPrefs;
    $title = ma_ui_label($report->name);
    $description = ma_report_description($report);
    // Use FA's form lifecycle to retain CSRF tokens and buffered hidden controls.
    ob_start();
    start_form(false, false, ma_ui_escape($_SERVER['PHP_SELF']));
    echo '<div class="ma-report-generate">';
    submit('Rep'.$report->id, _('Print report'), true, '', $SysPrefs->pdf_debug ? false : 'default process popup');
    echo '</div>';
    echo '<div class="ma-report-fields">'.$reports->getOptions($report->get_controls(), $report->id).'</div>';
    hidden('REP_ID', $report->id);
    hidden('Class', $class);
    end_form();
    $form = preg_replace('/<form\b/', '<form class="ma-report-form" target="_blank"', ob_get_clean(), 1);
    ob_start();
    include __DIR__.'/views/report_filters.php';
    return ob_get_clean();
}

function ma_reports_render($reports, $class = null)
{
    global $Ajax;
    $categories = ma_reports_catalogue($reports);
    $requested = $_REQUEST['Class'] ?? $class;
    $active = is_scalar($requested) && isset($categories[(string)$requested]) ? (string)$requested : (string)array_key_first($categories);
    $report_id = is_scalar($_REQUEST['REP_ID'] ?? null) ? (string)$_REQUEST['REP_ID'] : '';
    $selected = $categories[$active]['reports'][$report_id] ?? null;
    $panel = $selected ? ma_report_filter_panel($reports, $selected, $active) : '';
    if (in_ajax()) {
        $Ajax->addUpdate(true, 'rep_form', $panel);
        $Ajax->addScript(true, 'if (window.maReportLoaded) window.maReportLoaded();');
    }
    $action = $_SERVER['PHP_SELF'];
    ob_start();
    include __DIR__.'/views/reports.php';
    return ob_get_clean();
}
