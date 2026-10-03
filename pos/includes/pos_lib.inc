<?php
/**********************************************************************
    Point of sale - server side.
    A sale is a Direct Invoice (the same Cart::write() path as Sales > Direct Invoice, so stock,
    GL, eTIMS and notifications behave identically) followed by a Customer Payment that is
    allocated to that invoice:
      * Cash  - payment into the register's cash account.
      * Bank  - payment into a chosen bank/card account, with the slip or transfer reference.
      * M-Pesa - the invoice is posted unpaid; the screen then sends an STK Push (sales/view/mpesa_stk.php)
                 and the M-Pesa callback posts and allocates the payment.
    Payment methods offered are whatever is active: the cash account of the user's POS definition,
    active non-cash bank accounts in the home currency, and M-Pesa once it is configured.
***********************************************************************/
function pos_load_fa()
{
	global $path_to_root;
	foreach (array('/includes/date_functions.inc', '/includes/data_checks.inc', '/includes/inventory.inc', '/sales/includes/sales_db.inc',
			'/sales/includes/db/sales_order_db.inc', '/sales/includes/db/sales_invoice_db.inc', '/sales/includes/db/sales_delivery_db.inc',
			'/includes/ui/ui_globals.inc', '/sales/includes/cart_class.inc', '/gl/includes/gl_db.inc', '/includes/db/allocations_db.inc',
			'/sales/includes/db/payment_db.inc', '/sales/includes/db/custalloc_db.inc', '/includes/references.inc',
			'/sales/includes/db/customers_db.inc', '/sales/includes/db/branches_db.inc', '/sales/includes/db/sales_points_db.inc',
			'/admin/db/company_db.inc', '/includes/db/inventory_db.inc', '/inventory/includes/db/items_db.inc') as $f)
		include_once($path_to_root.$f);
}

// ------------------------------------------------------------------ register

// The register the signed-in user sells from: the user's POS definition, else the first active one, else defaults.
function pos_register()
{
	$pos = null;
	if (function_exists('user_pos') && (int)user_pos()) $pos = get_sales_point((int)user_pos());
	if (!$pos || $pos['inactive']) {
		$res = get_all_sales_points(false);
		$pos = db_fetch($res) ?: null;
	}
	$location = $pos['pos_location'] ?? '';
	if ($location === '') {
		$row = db_fetch(db_query("SELECT loc_code FROM ".TB_PREF."locations WHERE !inactive ORDER BY loc_code LIMIT 1", 'location'));
		$location = $row['loc_code'] ?? '';
	}
	$row = db_fetch(db_query("SELECT location_name FROM ".TB_PREF."locations WHERE loc_code=".db_escape($location), 'location'));
	return array('id' => (int)($pos['id'] ?? 0), 'name' => $pos['pos_name'] ?? _('Default branch'),
		'location' => $location, 'location_name' => $row['location_name'] ?? $location, 'account' => (int)($pos['pos_account'] ?? 0));
}

// Payment methods that can be used right now. Each: key (cash|bank|mpesa), id (bank account), label.
function pos_methods($register = null)
{
	global $path_to_root;
	$register = $register ?: pos_register();
	$home = get_company_currency();
	$mpesa_account = 0;
	$methods = array();

	// M-Pesa: only when the integration is set up and the user may request payments.
	if ($_SESSION['wa_current_user']->can_access('SA_MPESAREQUEST')) {
		include_once($path_to_root.'/mpesa/includes/mpesa_setup.inc');
		include_once($path_to_root.'/mpesa/includes/mpesa_post.inc');
		if (mpesa_schema_ready()) {
			$cfg = mpesa_get_config();
			$mpesa_account = (int)($cfg['bank_account_id'] ?? 0);
			if (mpesa_configured($cfg) && $home === 'KES') $methods[] = array('key' => 'mpesa', 'id' => $mpesa_account, 'label' => 'M-Pesa');
		}
	}

	$cash = null; $banks = array();
	$res = db_query("SELECT id, bank_account_name, bank_name, account_type FROM ".TB_PREF."bank_accounts
		WHERE !inactive AND bank_curr_code=".db_escape($home)." ORDER BY account_type DESC, bank_account_name", 'bank accounts');
	while ($b = db_fetch($res)) {
		if ((int)$b['id'] === $mpesa_account) continue;
		if ($register['account'] && (int)$b['id'] === $register['account']) { $cash = $b; continue; }
		if ((int)$b['account_type'] === BT_CASH) { if (!$cash && !$register['account']) $cash = $b; continue; }
		$banks[] = $b;
	}
	if ($cash) array_unshift($methods, array('key' => 'cash', 'id' => (int)$cash['id'], 'label' => _('Cash')));
	foreach ($banks as $b)
		$methods[] = array('key' => 'bank', 'id' => (int)$b['id'], 'label' => $b['bank_account_name']);
	return $methods;
}

// ------------------------------------------------------------------ customers

function pos_walkin_customer()
{
	$id = (int)get_company_pref('pos_walkin_customer');
	if ($id) {
		$row = db_fetch(db_query("SELECT debtor_no FROM ".TB_PREF."debtors_master WHERE debtor_no=$id AND !inactive", 'walk-in'));
		if ($row) return $id;
	}
	$row = db_fetch(db_query("SELECT debtor_no FROM ".TB_PREF."debtors_master WHERE !inactive AND curr_code=".db_escape(get_company_currency())
		." AND (name LIKE 'walk%' OR name LIKE 'cash%' OR name LIKE 'counter%' OR name LIKE 'general%') ORDER BY debtor_no LIMIT 1", 'walk-in'));
	return $row ? (int)$row['debtor_no'] : 0;
}

function pos_customer_info($id)
{
	$row = db_fetch(db_query("SELECT debtor_no, name, curr_code, sales_type FROM ".TB_PREF."debtors_master WHERE debtor_no=".(int)$id." AND !inactive", 'customer'));
	return $row ? array('id' => (int)$row['debtor_no'], 'name' => $row['name'], 'currency' => $row['curr_code']) : null;
}

function pos_customer_search($q)
{
	$like = '%'.str_replace(array('%', '_'), array('\\%', '\\_'), trim($q)).'%';
	$res = db_query("SELECT debtor_no, name, curr_code FROM ".TB_PREF."debtors_master WHERE !inactive AND curr_code=".db_escape(get_company_currency())
		." AND (name LIKE ".db_escape($like)." OR debtor_ref LIKE ".db_escape($like).") ORDER BY name LIMIT 15", 'customer search');
	$out = array();
	while ($r = db_fetch($res)) $out[] = array('id' => (int)$r['debtor_no'], 'name' => $r['name']);
	return $out;
}

// ------------------------------------------------------------------ catalog

function pos_item_image($stock_id)
{
	global $path_to_root;
	$base = preg_replace('/[^A-Za-z0-9_.-]/', '', $stock_id);
	foreach (array('jpg', 'png', 'jpeg', 'gif') as $ext) {
		$file = company_path().'/images/'.$base.'.'.$ext;
		if ($base !== '' && is_file($file)) return $path_to_root.'/company/'.user_company().'/images/'.rawurlencode($base).'.'.$ext.'?v='.filemtime($file);
	}
	return '';
}

// Sellable items with the customer's price and the stock on hand at the register's location.
function pos_catalog($customer_id, $location)
{
	$cust = get_customer_to_order($customer_id);
	if (!$cust) return array();
	$date = Today();
	$qoh = array();
	$res = db_query("SELECT stock_id, SUM(qty) q FROM ".TB_PREF."stock_moves WHERE loc_code=".db_escape($location)
		." AND tran_date<=".db_escape(date2sql($date))." GROUP BY stock_id", 'stock on hand');
	while ($r = db_fetch($res)) $qoh[$r['stock_id']] = (float)$r['q'];
	$codes = array();
	$res = db_query("SELECT stock_id, item_code FROM ".TB_PREF."item_codes WHERE !inactive AND item_code<>stock_id", 'item codes');
	while ($r = db_fetch($res)) $codes[$r['stock_id']][] = $r['item_code'];

	$res = db_query("SELECT s.stock_id, s.description, s.units, s.mb_flag, c.description category, s.category_id
		FROM ".TB_PREF."stock_master s JOIN ".TB_PREF."stock_category c ON c.category_id=s.category_id
		WHERE !s.inactive AND !s.no_sale AND s.mb_flag<>'F' ORDER BY c.description, s.description LIMIT 3000", 'items');
	$items = array();
	while ($r = db_fetch($res)) {
		$tracked = has_stock_holding($r['mb_flag']);
		$items[] = array('id' => $r['stock_id'], 'name' => $r['description'], 'cat' => $r['category'], 'units' => $r['units'],
			'price' => round((float)get_price($r['stock_id'], $cust['curr_code'], $cust['salestype'], $cust['factor'], $date), user_price_dec()),
			'stock' => $tracked ? round($qoh[$r['stock_id']] ?? 0, 4) : null, 'codes' => $codes[$r['stock_id']] ?? array(),
			'img' => pos_item_image($r['stock_id']));
	}
	return array('items' => $items, 'tax_included' => (int)$cust['tax_included'], 'blocked' => (int)$cust['dissallow_invoices']);
}

// ------------------------------------------------------------------ cart / totals

// Cart for a direct invoice. $lines: array(array(id, qty)); $discount: KES off the whole sale.
function pos_build_cart($customer_id, $lines, $discount, $location)
{
	$cust = get_customer_to_order($customer_id);
	if (!$cust) throw new RuntimeException(_('Choose a customer first.'));
	if ($cust['dissallow_invoices']) throw new RuntimeException(_('This customer is on hold: invoices are not allowed.'));
	if ($cust['curr_code'] !== get_company_currency()) throw new RuntimeException(_('The point of sale sells in the home currency only.'));
	$branch_row = get_default_branch($customer_id);
	if (!$branch_row) throw new RuntimeException(_('The customer has no branch.'));
	$branch = db_fetch(get_branch_to_order($customer_id, $branch_row['branch_code']));
	if (!$branch) throw new RuntimeException(_('The customer branch is not complete (tax group or location missing).'));

	$cart = new Cart(ST_SALESINVOICE);
	$cart->set_customer($customer_id, $cust['name'], $cust['curr_code'], $cust['discount'], $cust['payment_terms']);
	if ((int)($cart->payment_terms['days_before_due'] ?? 0) < 0) throw new RuntimeException(_('Prepaid customers cannot be served at the point of sale. Choose another customer.'));
	$cart->set_branch($branch_row['branch_code'], $branch['tax_group_id'], $branch['tax_group_name']);
	$cart->set_sales_type($cust['salestype'], $cust['sales_type'], $cust['tax_included'], $cust['factor']);
	$loc = db_fetch(db_query("SELECT loc_code, location_name FROM ".TB_PREF."locations WHERE loc_code=".db_escape($location), 'location'));
	if (!$loc) throw new RuntimeException(_('The register has no stock location.'));
	$cart->set_location($loc['loc_code'], $loc['location_name']);
	// FrontAccounting books its own payment for "Cash Only" customers into the POS account of the cart; the point
	// of sale records the payment itself so the cashier can choose cash, M-Pesa or bank. No account = no automatic payment.
	$cart->pos = array('id' => 0, 'pos_name' => _('Point of sale'), 'pos_location' => $loc['loc_code'], 'location_name' => $loc['location_name'], 'pos_account' => 0);
	$cart->set_delivery((int)$branch['default_ship_via'], $cust['name'], $cust['address'], 0);
	$cart->document_date = $cart->due_date = Today();
	$cart->dimension_id = (int)$cust['dimension_id'];
	$cart->dimension2_id = (int)$cust['dimension2_id'];
	$cart->Comments = _('Point of sale');

	$raw = 0.0; $prepared = array();
	foreach ($lines as $l) {
		$id = (string)($l['id'] ?? ''); $qty = round((float)($l['qty'] ?? 0), 4);
		if ($id === '' || $qty <= 0) continue;
		$item = get_item($id);
		if (!$item || $item['inactive'] || $item['no_sale'] || $item['mb_flag'] === 'F') throw new RuntimeException(sprintf(_('Item %s cannot be sold.'), $id));
		$price = (float)get_price($id, $cust['curr_code'], $cust['salestype'], $cust['factor'], Today());
		$prepared[] = array($id, $qty, $price);
		$raw += $qty * $price;
	}
	if (!$prepared) throw new RuntimeException(_('The order is empty.'));
	$discount = max(0.0, min((float)$discount, $raw));
	$pct = $raw > 0 ? $discount / $raw : 0.0;
	foreach ($prepared as $n => $p)
		if (!$cart->add_to_cart($n, $p[0], $p[1], $p[2], $pct)) throw new RuntimeException(sprintf(_('Item %s cannot be sold.'), $p[0]));
	return $cart;
}

// Totals as FrontAccounting will post them. All amounts in the home currency.
function pos_totals($cart)
{
	$items = $cart->get_items_total();
	$tax = 0.0;
	foreach ($cart->get_taxes() as $t) $tax += round((float)$t['Value'], user_price_dec());
	$total = $cart->get_trans_total();
	$lines = array();
	foreach ($cart->line_items as $n => $l)
		$lines[] = array('id' => $l->stock_id, 'total' => round($l->quantity * $l->line_price() * (1 - $l->discount_percent), user_price_dec()));
	return array('items' => round($items, 2), 'tax' => round($tax, 2), 'total' => round($total, 2),
		'net' => round($cart->tax_included ? $total - $tax : $items, 2), 'tax_included' => (int)$cart->tax_included, 'lines' => $lines);
}

// ------------------------------------------------------------------ posting

function pos_next_payment_ref() { global $Refs; return $Refs->get_next(ST_CUSTPAYMENT); }

// Customer Payment for an invoice, allocated to it. Returns the payment number.
function pos_post_payment($invoice_no, $account_id, $amount, $memo)
{
	$inv = get_customer_trans($invoice_no, ST_SALESINVOICE);
	if (!$inv) throw new RuntimeException(_('Invoice not found.'));
	$branch = get_default_branch($inv['debtor_no']);
	$bank = get_bank_account($account_id);
	if (!$bank || $bank['inactive'] || $bank['bank_curr_code'] !== get_company_currency()) throw new RuntimeException(_('That payment account is not available.'));
	$amount = round($amount, user_price_dec());
	$payment_no = write_customer_payment(0, $inv['debtor_no'], $branch['branch_code'], $account_id, Today(), pos_next_payment_ref(), $amount, 0, $memo, 0, 0, $amount);
	allocate_payment(ST_CUSTPAYMENT, $payment_no, ST_SALESINVOICE, $invoice_no, $inv['debtor_no'], $amount, Today());
	return $payment_no;
}

function pos_invoice_due($invoice_no)
{
	$t = get_customer_trans($invoice_no, ST_SALESINVOICE);
	if (!$t || get_voided_entry(ST_SALESINVOICE, $invoice_no)) return 0.0;
	return max(0.0, round($t['ov_amount'] + $t['ov_gst'] + $t['ov_freight'] + $t['ov_freight_tax'] + $t['ov_discount'] - $t['alloc'], 2));
}

// Payment memo: method, reference, register.
function pos_memo($method_label, $reference, $register)
{
	return trim('POS '.$register['name'].' - '.$method_label.($reference !== '' ? ' '.$reference : ''));
}
