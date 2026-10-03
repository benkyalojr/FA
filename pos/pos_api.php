<?php
// Point of sale JSON endpoint: catalog, customer search, quote, checkout, settle, walk-in default. CSRF on every POST.
$page_security = 'SA_POS';
$path_to_root = "..";
include_once($path_to_root . "/includes/session.inc");
include_once($path_to_root . "/includes/ui.inc");
include_once($path_to_root . "/pos/includes/pos_lib.inc");
pos_load_fa();

header('Content-Type: application/json; charset=UTF-8');
function pos_reply($data, $code = 200) { http_response_code($code); echo json_encode($data); exit; }

$post = $_SERVER['REQUEST_METHOD'] === 'POST';
if ($post && (empty($_SESSION['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], (string)($_POST['_token'] ?? ''))))
	pos_reply(array('error' => _('Request from outside of this page is forbidden.')), 403);
$action = (string)($post ? ($_POST['action'] ?? '') : ($_GET['action'] ?? ''));
$register = pos_register();

try {
	switch ($action) {
	case 'catalog':
		$id = (int)($_GET['customer'] ?? 0) ?: pos_walkin_customer();
		if (!$id) pos_reply(array('error' => _('Choose a customer first.')), 422);
		pos_reply(array_merge(pos_catalog($id, $register['location']), array('negative' => (bool)$SysPrefs->allow_negative_stock())));

	case 'customers':
		pos_reply(array('customers' => pos_customer_search((string)($_GET['q'] ?? ''))));

	case 'walkin':   // make the chosen customer the default for "Walk-in customer"
		if (!$_SESSION['wa_current_user']->can_access('SA_POSSETUP')) pos_reply(array('error' => _('You may not change point of sale settings.')), 403);
		if (!pos_customer_info((int)($_POST['customer'] ?? 0))) pos_reply(array('error' => _('Customer not found.')), 404);
		set_company_pref('pos_walkin_customer', 'setup.company', 'int', 11, (int)$_POST['customer']);
		pos_reply(array('ok' => true));

	case 'quote': case 'checkout':
		// session.inc html-encodes every $_POST value, which turns the JSON quotes into &quot;.
		$lines = json_decode(html_entity_decode((string)($_POST['lines'] ?? '[]'), ENT_QUOTES), true);
		if (!is_array($lines)) pos_reply(array('error' => _('Invalid order.')), 422);
		$cart = pos_build_cart((int)($_POST['customer'] ?? 0), $lines, (float)($_POST['discount'] ?? 0), $register['location']);
		$totals = pos_totals($cart);
		if ($action === 'quote') pos_reply($totals);

		// ---- checkout
		$methods = pos_methods($register);
		$method = null;
		foreach ($methods as $m)
			if ($m['key'] === (string)($_POST['method'] ?? '') && ($m['key'] !== 'bank' || $m['id'] === (int)($_POST['account'] ?? 0)) || false) { $method = $m; break; }
		if (!$method) pos_reply(array('error' => _('That payment method is not available.')), 422);
		$reference = trim(html_entity_decode((string)($_POST['reference'] ?? ''), ENT_QUOTES));
		if ($method['key'] === 'bank' && $reference === '') pos_reply(array('error' => _('Enter the card slip or transfer reference.')), 422);
		$tendered = round((float)($_POST['tendered'] ?? 0), 2);
		if ($method['key'] === 'cash' && $tendered + 0.005 < $totals['total']) pos_reply(array('error' => _('The amount received is less than the total.')), 422);
		if ($method['key'] === 'mpesa' && $totals['total'] < 1) pos_reply(array('error' => _('M-Pesa needs a total of at least 1.')), 422);
		if ($totals['total'] <= 0) pos_reply(array('error' => _('Nothing to charge.')), 422);

		if (!$SysPrefs->allow_negative_stock()) {
			$low = $cart->check_qoh();
			if ($low) pos_reply(array('error' => sprintf(_('Not enough stock for: %s'), implode(', ', $low))), 409);
		}
		$cart->reference = $Refs->get_next(ST_SALESINVOICE, null, array('customer' => $cart->customer_id, 'branch' => $cart->Branch, 'date' => $cart->document_date));
		$invoice_no = $cart->write(1);
		if ($invoice_no <= 0) pos_reply(array('error' => _('The invoice could not be posted (duplicate reference). Try again.')), 409);
		$_SESSION['pos_open'][(int)$invoice_no] = true;
		$out = array('ok' => true, 'invoice_no' => (int)$invoice_no, 'reference' => $cart->reference, 'total' => $totals['total'], 'method' => $method['key']);

		if ($method['key'] === 'mpesa') {          // paid when the customer confirms on the phone
			$out['pending'] = true;
			pos_reply($out);
		}
		try {
			$out['payment_no'] = pos_post_payment($invoice_no, $method['id'], $totals['total'], pos_memo($method['label'], $reference, $register));
			unset($_SESSION['pos_open'][(int)$invoice_no]);
			$out['change'] = $method['key'] === 'cash' ? round($tendered - $totals['total'], 2) : 0;
			$out['tendered'] = $tendered;
		} catch (Throwable $e) {
			$out['pending'] = true;
			$out['warning'] = sprintf(_('Invoice %s was posted but the payment could not be recorded: %s'), $cart->reference, $e->getMessage());
		}
		pos_reply($out);

	case 'settle':   // pay (the rest of) a POS invoice another way, e.g. after an M-Pesa request fell through
		$no = (int)($_POST['trans_no'] ?? 0);
		if (empty($_SESSION['pos_open'][$no])) pos_reply(array('error' => _('Only invoices started on this screen can be settled here.')), 403);
		$due = pos_invoice_due($no);
		if ($due <= 0) { unset($_SESSION['pos_open'][$no]); pos_reply(array('ok' => true, 'paid' => true)); }
		$method = null;
		foreach (pos_methods($register) as $m)
			if ($m['key'] !== 'mpesa' && $m['key'] === (string)($_POST['method'] ?? '') && ($m['key'] !== 'bank' || $m['id'] === (int)($_POST['account'] ?? 0))) { $method = $m; break; }
		if (!$method) pos_reply(array('error' => _('That payment method is not available.')), 422);
		$reference = trim(html_entity_decode((string)($_POST['reference'] ?? ''), ENT_QUOTES));
		if ($method['key'] === 'bank' && $reference === '') pos_reply(array('error' => _('Enter the card slip or transfer reference.')), 422);
		$tendered = round((float)($_POST['tendered'] ?? $due), 2);
		if ($method['key'] === 'cash' && $tendered + 0.005 < $due) pos_reply(array('error' => _('The amount received is less than the amount due.')), 422);
		$payment_no = pos_post_payment($no, $method['id'], $due, pos_memo($method['label'], $reference, $register));
		unset($_SESSION['pos_open'][$no]);
		pos_reply(array('ok' => true, 'paid' => true, 'payment_no' => $payment_no, 'change' => $method['key'] === 'cash' ? round($tendered - $due, 2) : 0, 'tendered' => $tendered));

	case 'status':   // is a POS invoice paid yet (after an M-Pesa request)?
		$no = (int)($_GET['trans_no'] ?? 0);
		$due = pos_invoice_due($no);
		if ($due <= 0) unset($_SESSION['pos_open'][$no]);
		pos_reply(array('due' => $due, 'paid' => $due <= 0));
	}
} catch (Throwable $e) {
	pos_reply(array('error' => $e->getMessage()), $e instanceof RuntimeException ? 422 : 500);
}
pos_reply(array('error' => _('Unknown request.')), 400);
