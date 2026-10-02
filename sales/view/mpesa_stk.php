<?php
// Request an M-Pesa payment for an invoice (STK Push) and poll its result. JSON, POST/GET with CSRF on POST.
$page_security = 'SA_MPESAREQUEST';
$path_to_root = "../..";
include_once($path_to_root . "/includes/session.inc");
include_once($path_to_root . "/includes/date_functions.inc");
include_once($path_to_root . "/includes/ui.inc");
include_once($path_to_root . "/sales/includes/sales_db.inc");
include_once($path_to_root . "/mpesa/includes/mpesa_setup.inc");
include_once($path_to_root . "/mpesa/includes/mpesa_http.inc");
include_once($path_to_root . "/mpesa/includes/mpesa_post.inc");

header('Content-Type: application/json; charset=UTF-8');
function stk_reply($data, $code = 200) { http_response_code($code); echo json_encode($data); exit; }

if (!mpesa_schema_ready()) stk_reply(array('error' => mpesa_setup_message()), 503);
$cfg = mpesa_get_config();

// Poll: the state of one request, so the invoice modal can show the result.
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
	$tx = mpesa_tx_get((int)($_GET['poll'] ?? 0));
	if (!$tx || $tx['kind'] !== 'stk') stk_reply(array('error' => _('Unknown request.')), 404);
	stk_reply(array('status' => $tx['status'], 'receipt' => $tx['receipt'], 'message' => $tx['result_desc'] ?: $tx['note'], 'allocated' => (float)$tx['allocated']));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') stk_reply(array('error' => 'Method not allowed.'), 405);
if (empty($_SESSION['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], (string)($_POST['_token'] ?? '')))
	stk_reply(array('error' => _('Request from outside of this page is forbidden.')), 403);
if (!mpesa_configured($cfg))
	stk_reply(array('error' => _('M-Pesa is not configured yet. An administrator must complete M-Pesa Configuration.')), 409);

$no = (int)($_POST['trans_no'] ?? 0);
$inv = get_customer_trans($no, ST_SALESINVOICE);
if (!$inv || get_voided_entry(ST_SALESINVOICE, $no)) stk_reply(array('error' => _('Invoice not found.')), 404);
$due = mpesa_invoice_outstanding(ST_SALESINVOICE, $no);
if ($due <= 0) stk_reply(array('error' => _('This invoice has nothing left to pay.')), 409);

$amount = round((float)str_replace(',', '', $_POST['amount'] ?? $due), 2);
if ($amount < 1 || $amount > $due + 0.005) stk_reply(array('error' => sprintf(_('Enter an amount between 1 and %s.'), number_format($due, 2))), 422);
try { mpesa_whole_amount($amount); } catch (Throwable $e) { stk_reply(array('error' => $e->getMessage()), 422); }
if (get_customer_currency($inv['debtor_no']) !== 'KES') stk_reply(array('error' => _('M-Pesa payments require a KES customer.')), 422);
$phone = mpesa_msisdn($_POST['phone'] ?? '');
if ($phone === '') stk_reply(array('error' => _('Enter a valid Safaricom number, for example 0712345678.')), 422);

$tx_id = mpesa_tx_create_stk((int)$inv['debtor_no'], ST_SALESINVOICE, $no, $phone, $amount, $inv['reference'], (int)$_SESSION['wa_current_user']->user);
$r = mpesa_stk_push($cfg, $phone, $amount, $inv['reference'], 'Invoice');
if (!$r['ok'] || ($r['data']['ResponseCode'] ?? '') !== '0') {
	mpesa_tx_update($tx_id, array('status' => 'failed', 'result_desc' => $r['error'] !== '' ? $r['error'] : ($r['data']['ResponseDescription'] ?? 'Request refused')));
	stk_reply(array('error' => sprintf(_('The request was not accepted: %s'), $r['error'] !== '' ? $r['error'] : ($r['data']['ResponseDescription'] ?? ''))), 502);
}
mpesa_tx_update($tx_id, array('merchant_request_id' => $r['data']['MerchantRequestID'] ?? null, 'checkout_request_id' => $r['data']['CheckoutRequestID'] ?? null));
stk_reply(array('ok' => true, 'tx' => $tx_id, 'message' => $r['data']['CustomerMessage'] ?? _('Request sent. Ask the customer to enter their M-Pesa PIN.')));
