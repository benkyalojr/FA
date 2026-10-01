<?php
// HTML fragment for the document modal (orders, deliveries, invoices, credits, payments).
$page_security = 'SA_SALESTRANSVIEW';
$path_to_root = "../..";
include_once($path_to_root . "/includes/session.inc");
include_once($path_to_root . "/includes/date_functions.inc");
include_once($path_to_root . "/includes/ui.inc");
include_once($path_to_root . "/sales/includes/sales_db.inc");
include_once($path_to_root . "/sales/includes/db/custalloc_db.inc");
include_once($path_to_root . "/gl/includes/gl_db.inc");
include_once($path_to_root . "/includes/db/audit_trail_db.inc");
include_once($path_to_root . "/reporting/includes/reporting.inc");
require_once($path_to_root . "/ui/workspace.inc");
require_once($path_to_root . "/ui/doc_modal.inc");

header('Content-Type: text/html; charset=UTF-8');
$out = ma_doc_render($_GET['trans_type'] ?? $_GET['type'] ?? 0, $_GET['trans_no'] ?? 0);
if (isset($out['error'])) {
	http_response_code(404);
	echo '<div class="ma-doc-empty">'.ma_ui_escape($out['error']).'</div>';
	exit;
}
echo '<template data-title="'.ma_ui_escape($out['title']).'"></template>'.$out['html'];
