<?php
/**********************************************************************
    eTIMS Report PDF proxy - Stanbest's PDF endpoints need a Bearer
    token, which a plain browser <a href> can't attach, so this fetches
    server-side (with our cached token) and streams the bytes back.
    ?type=xreport|zreport|zperiod (zperiod also takes &from=&to=,
    YYYY-MM-DD). xreport and zperiod are known-broken on Stanbest's own
    side right now (confirmed: 500 and 404 respectively) - shown as a
    plain error page rather than a corrupt "PDF" download.
***********************************************************************/
$page_security = 'SA_ETIMSXREPORT';
$path_to_root = "../..";
include_once($path_to_root . "/includes/session.inc");
include_once($path_to_root . "/includes/ui.inc");

include_once($path_to_root . "/etims/includes/etims_setup.inc");
if (!etims_require_setup())
	return;
include_once($path_to_root . "/etims/includes/db/etims_config_db.inc");
include_once($path_to_root . "/etims/includes/db/etims_client_db.inc");

// etims_error_message() (etims_config_db.inc) expects $result['data'] to
// already be a decoded array (the shape etims_http_request()/
// etims_http_post()/etims_http_get() return) - etims_http_get_raw()'s
// failure path instead leaves $result['data'] as the raw response body
// (a JSON string like {"statusCode":500,"message":"..."} here, since
// Stanbest's own error responses for these endpoints come back as JSON
// even though a success would be a PDF), so it needs its own decode step
// rather than reusing that helper directly.
function etims_pdf_result_error($result)
{
	if (isset($result['error']))
		return $result['error'];
	$decoded = isset($result['data']) ? json_decode($result['data'], true) : null;
	if (is_array($decoded) && isset($decoded['message']))
		return is_array($decoded['message']) ? implode('; ', $decoded['message']) : (string)$decoded['message'];
	return 'Unknown error (HTTP ' . (isset($result['http_code']) ? $result['http_code'] : '?') . ')';
}

function etims_pdf_error_page($message)
{
	header('Content-Type: text/html; charset=utf-8');
	echo '<html><body style="font-family:sans-serif;padding:2em;">'
		. '<h3>' . _('Could not generate this report') . '</h3><p>' . html_specials_encode($message) . '</p>'
		. '<p><a href="javascript:history.back()">' . _('Back') . '</a></p></body></html>';
	exit;
}

$cfg = etims_get_config();
if (!$cfg || $cfg['pin'] === '' || $cfg['password'] === '')
	etims_pdf_error_page(_('eTIMS is not configured.'));

$type = isset($_GET['type']) ? $_GET['type'] : '';

try {
	switch ($type) {
		case 'xreport':
			$result = etims_xreport_pdf($cfg);
			$filename = 'eTIMS-X-Report.pdf';
			break;
		case 'zreport':
			$result = etims_zreport_pdf($cfg);
			$filename = 'eTIMS-Z-Report.pdf';
			break;
		case 'zperiod':
			$from = isset($_GET['from']) ? $_GET['from'] : date('Y-m-d');
			$to = isset($_GET['to']) ? $_GET['to'] : date('Y-m-d');
			$result = etims_zreport_periodical_pdf($cfg, $from, $to);
			$filename = 'eTIMS-Z-Report-Period.pdf';
			break;
		default:
			etims_pdf_error_page(_('Unknown report type.'));
	}
} catch (Exception $e) {
	etims_pdf_error_page($e->getMessage());
}

if (!$result['ok'])
	etims_pdf_error_page(etims_pdf_result_error($result));

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $filename . '"');
header('Content-Length: ' . strlen($result['data']));
echo $result['data'];
exit;
