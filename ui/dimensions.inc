<?php
// Dimensions workspace: outstanding dimensions and the dimension inquiry as tabs;
// dimension tags are its settings screen.
require_once __DIR__ . '/finance.inc';

define('MA_DIMENSIONS_APP', 'proj');

function ma_dimensions_tabs()
{
    return array(
        'outstanding' => array(_('Outstanding Dimensions'), 'layers', 'dimensions/inquiry/search_dimensions.php?outstanding_only=1', 'SA_DIMTRANSVIEW'),
        'inquiry'     => array(_('Dimension Inquiry'), 'search', 'dimensions/inquiry/search_dimensions.php', 'SA_DIMTRANSVIEW'),
    );
}

function ma_dimensions_active_tab()
{
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $name = basename($script);
    if ($name === 'index.php') return (is_string($_GET['application'] ?? null) && $_GET['application'] === MA_DIMENSIONS_APP) ? 'outstanding' : null;
    if ($name === 'tags.php') return (($_GET['type'] ?? '') === 'dimension' || (string)($_POST['type'] ?? '') === '2') ? 'settings' : null;
    if (strpos($script, '/dimensions/') === false) return null;
    if ($name === 'search_dimensions.php') return !empty($_GET['outstanding_only']) ? 'outstanding' : 'inquiry';
    return 'entry';   // dimension entry, view, closing
}

function ma_dimensions_is_list_screen() { return in_array(ma_dimensions_active_tab(), array('outstanding', 'inquiry'), true); }

function ma_dimensions_new_links() { return ma_finance_app_links(MA_DIMENSIONS_APP, 0, array('dimensions/inquiry/search_dimensions.php')); }
function ma_dimensions_reports_links() { return ma_finance_app_links(MA_DIMENSIONS_APP, 1, array('dimensions/inquiry/search_dimensions.php')); }
