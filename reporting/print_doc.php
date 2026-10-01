<?php
// A4 printout of a sales document (invoice, credit note, delivery note, order,
// quotation, customer payment receipt). Opens as a page; use Print / Save as PDF.
$page_security = 'SA_SALESTRANSVIEW';
$path_to_root = "..";
include_once($path_to_root . "/includes/session.inc");
include_once($path_to_root . "/includes/date_functions.inc");
include_once($path_to_root . "/includes/ui.inc");
include_once($path_to_root . "/sales/includes/sales_db.inc");
include_once($path_to_root . "/sales/includes/db/custalloc_db.inc");
include_once($path_to_root . "/gl/includes/gl_db.inc");
include_once($path_to_root . "/includes/db/audit_trail_db.inc");

function pd_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function pd_d($sqldate) { return $sqldate ? date('d M Y', strtotime($sqldate)) : ''; }
function pd_money($v) { return number_format((float)$v, user_price_dec()); }
function pd_qty($v, $stock_id) { return number_format((float)$v, get_qty_dec($stock_id)); }

$type = (int)($_GET['trans_type'] ?? 0);
$no = (int)($_GET['trans_no'] ?? 0);
$types = array(ST_SALESINVOICE, ST_CUSTCREDIT, ST_CUSTDELIVERY, ST_SALESORDER, ST_SALESQUOTE, ST_CUSTPAYMENT);
if (!in_array($type, $types, true) || $no <= 0) { http_response_code(404); die(_('Unknown document.')); }

$cur_home = get_company_pref('curr_default');
$titles = array(ST_SALESINVOICE => array('INVOICE', _('Sales Invoice')), ST_CUSTCREDIT => array('CREDIT NOTE', _('Credit Note')),
	ST_CUSTDELIVERY => array('DELIVERY NOTE', _('Delivery Note')), ST_SALESORDER => array('SALES ORDER', _('Sales Order')),
	ST_SALESQUOTE => array('QUOTATION', _('Sales Quotation')), ST_CUSTPAYMENT => array('PAYMENT RECEIPT', _('Payment Receipt')));
$is_order = in_array($type, array(ST_SALESORDER, ST_SALESQUOTE), true);

$status = null; $lines = array(); $meta = array(); $billto = array(); $delivery = array(); $ref = ''; $cur = $cur_home;
$sub = $tax = $total = $paid = 0; $taxinc = false; $terms_text = ''; $due_date = ''; $notes = ''; $taxrows = array();
$show_prices = $type !== ST_CUSTDELIVERY; $line_heads = array(_('DESCRIPTION'), _('QTY'), _('UNIT PRICE'));
$void = get_voided_entry($type, $no);

if ($is_order) {
	$o = get_sales_order_header($no, $type);
	if (!$o) die(_('Document not found.'));
	$ref = $o['reference']; $cur = $o['curr_code']; $taxinc = !empty($o['tax_included']);
	$res = get_sales_order_details($no, $type);
	while ($l = db_fetch($res)) {
		$net = round2((1 - $l['discount_percent']) * $l['unit_price'] * $l['quantity'], user_price_dec());
		$sub += $net;
		$lines[] = array('name' => $l['description'], 'code' => $l['stk_code'], 'units' => $l['units'], 'qty' => pd_qty($l['quantity'], $l['stk_code']),
			'price' => pd_money($l['unit_price']), 'amount' => pd_money($net), 'disc' => $l['discount_percent']);
	}
	$total = $sub + $o['freight_cost'];
	$billto = array($o['name'], $o['address'], $o['contact_phone']);
	$delivery = array($o['deliver_to'], $o['delivery_address'], $o['location_name']);
	$meta = array(_('Order date') => pd_d($o['ord_date']), ($type === ST_SALESQUOTE ? _('Valid until') : _('Requested delivery')) => pd_d($o['delivery_date']),
		_('Currency') => $cur, _('Sales type') => $o['sales_type'], _('Customer ref.') => $o['customer_ref']);
	$notes = $o['comments'];
	$status = $void ? _('VOIDED') : null;
	$pay_terms = null;
} else {
	$t = get_customer_trans($no, $type);
	if (!$t) die(_('Document not found.'));
	$ref = $t['reference']; $cur = $t['curr_code']; $taxinc = !empty($t['tax_included']);
	$order = $t['order_'] ? get_sales_order_header($t['order_'], ST_SALESORDER) : null;
	$branch = in_array($type, array(ST_SALESINVOICE, ST_CUSTCREDIT, ST_CUSTDELIVERY), true) ? get_branch($t['branch_code']) : null;
	$billto = array($t['DebtorName'], $t['address'], $order['contact_phone'] ?? '');
	$delivery = array($order['deliver_to'] ?? $t['DebtorName'], $order['delivery_address'] ?? ($branch['br_address'] ?? ''), $branch['br_name'] ?? '');
	if ($type === ST_CUSTPAYMENT) {
		$line_heads = array(_('APPLIED TO'), _('DATE'), _('DOCUMENT TOTAL'));
		$total = $t['Total'] - $t['ov_discount'];
		$res = get_allocatable_to_cust_transactions($t['debtor_no'], $no, $type);
		global $systypes_array;
		while ($res && ($a = db_fetch($res))) {
			$paid += $a['amt'];
			$lines[] = array('name' => ($systypes_array[$a['type']] ?? '').' '.$a['reference'], 'code' => '', 'units' => '', 'qty' => pd_d($a['tran_date']),
				'price' => pd_money($a['Total']), 'amount' => pd_money($a['amt']), 'disc' => 0);
		}
		global $bank_transfer_types;
		$meta = array(_('Date received') => pd_d($t['tran_date']), _('Currency') => $cur, _('Paid into') => $t['bank_account_name'],
			_('Payment type') => $bank_transfer_types[$t['BankTransType']] ?? '', _('Left to allocate') => pd_money(max(0, $total - $paid)));
		$status = $void ? _('VOIDED') : (floatcmp($total, $paid) <= 0 ? _('ALLOCATED') : _('UNALLOCATED'));
		$notes = $t['memo_'];
	} else {
		$res = get_customer_trans_details($type, $no);
		$sign = 1;
		while ($l = db_fetch($res)) {
			if ((float)$l['quantity'] == 0) continue;
			$price = $l['unit_price']; // already the full price when tax is included
			$net = round2((1 - $l['discount_percent']) * $price * $l['quantity'], user_price_dec());
			$sub += $net;
			$lines[] = array('name' => $l['StockDescription'], 'code' => $l['stock_id'], 'units' => $l['units'], 'qty' => pd_qty($l['quantity'], $l['stock_id']),
				'price' => pd_money($price), 'amount' => pd_money($net), 'disc' => $l['discount_percent']);
		}
		$total = $t['ov_amount'] + $t['ov_gst'] + $t['ov_freight'] + $t['ov_freight_tax'];
		$paid = (float)$t['alloc'];
		$due_date = $type === ST_CUSTDELIVERY ? '' : $t['due_date'];
		$pay_terms = $type === ST_SALESINVOICE ? get_payment_terms($t['payment_terms']) : null;
		$meta = array(($type === ST_CUSTDELIVERY ? _('Delivery date') : _('Date')) => pd_d($t['tran_date']), _('Due date') => pd_d($due_date), _('Currency') => $cur,
			_('Sales type') => $t['sales_type'], _('Order reference') => $order['reference'] ?? '');
		if ($type === ST_SALESINVOICE)
			$status = $void ? _('VOIDED') : (floatcmp($paid, $total) >= 0 ? _('PAID') : ($paid > 0 ? _('PARTLY PAID') : _('UNPAID')));
		elseif ($void) $status = _('VOIDED');
		$notes = $t['memo_'];
		$tr = get_trans_tax_details($type, $no);
		while ($x = db_fetch($tr)) { $tax += $x['amount']; $taxrows[] = array($x['tax_type_name'], $x['rate'], $x['amount']); }
	}
	$terms_text = $pay_terms['terms'] ?? '';
}
if ($is_order) $tax = 0;
$meta = array_filter($meta, function ($v) { return $v !== '' && $v !== null; });
$due = round($total - $paid, user_price_dec());

// Company block and logo.
$coy_name = trim((string)get_company_pref('coy_name'));
if ($coy_name === '' || $coy_name === 'Company name') $coy_name = $db_connections[user_company()]['name']; // setup never filled in
$coy = array('name' => $coy_name, 'address' => get_company_pref('postal_address'), 'phone' => get_company_pref('phone'),
	'email' => get_company_pref('email'), 'pin' => get_company_pref('gst_no'));
$logo = '';
$logo_file = get_company_pref('coy_logo');
$f = ($logo_file && is_file(company_path().'/images/'.basename($logo_file))) ? company_path().'/images/'.basename($logo_file) : $path_to_root.'/ui/logo.png';
if (is_file($f)) {
	$mime = function_exists('mime_content_type') ? mime_content_type($f) : 'image/png';
	if (strpos($mime, 'image/') === 0) $logo = 'data:'.$mime.';base64,'.base64_encode(file_get_contents($f));
}
$bank = in_array($type, array(ST_SALESINVOICE), true) ? get_default_bank_account($cur) : null;

// eTIMS verification link (invoices and credit notes).
$etims = null;
if (in_array($type, array(ST_SALESINVOICE, ST_CUSTCREDIT), true) && get_company_pref('use_etims_stamping')) {
	$e = db_fetch(db_query("SELECT status, short_url FROM ".TB_PREF."etims_submissions WHERE trans_type=".db_escape($type)." AND trans_no=".db_escape($no), 'etims'));
	if ($e && $e['status'] === 'stamped' && $e['short_url']) {
		include_once($path_to_root . '/etims/includes/etims_qr.inc');
		$png = etims_fetch_qr_png($e['short_url'], 150);
		$etims = array('url' => $e['short_url'], 'qr' => $png ? 'data:image/png;base64,'.base64_encode(file_get_contents($png)) : '');
		if ($png) @unlink($png);
	}
}

$tint = array(_('PAID') => 'ok', _('ALLOCATED') => 'ok', _('UNPAID') => 'bad', _('UNALLOCATED') => 'warn', _('PARTLY PAID') => 'warn', _('VOIDED') => 'void');
$title = $titles[$type][0]; $label = $titles[$type][1];
$addr = function ($lines_) { $o = array(); foreach ($lines_ as $v) if (trim((string)$v) !== '') $o[] = nl2br(pd_h(trim($v))); return implode('<br>', $o); };

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store');
?><!doctype html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo pd_h($label.' '.$ref); ?></title>
<style>
:root{--blue:#2462ff;--ink:#202c40;--muted:#71809a;--line:#e1e7f0}*{box-sizing:border-box}
body{margin:0;background:#edf1f7;color:var(--ink);font-family:Arial,Helvetica,sans-serif;font-size:12px;line-height:1.55}
.preview-bar{max-width:210mm;margin:22px auto 14px;display:flex;align-items:center;justify-content:space-between;gap:15px;padding:0 3px}
.preview-bar strong{font-size:13px}.preview-bar small{display:block;color:var(--muted);font-size:11px}
.preview-bar button{border:0;border-radius:7px;background:var(--blue);color:#fff;padding:10px 17px;font-size:12px;font-weight:700;cursor:pointer}
.page{width:210mm;min-height:297mm;margin:0 auto 30px;background:#fff;padding:19mm 18mm 14mm;box-shadow:0 8px 35px #24344d14;display:flex;flex-direction:column;position:relative}
.top{display:flex;justify-content:space-between;align-items:start;padding-bottom:24px;border-bottom:2px solid var(--ink);gap:30px}
.brand{display:flex;align-items:center;gap:12px;font-size:28px;letter-spacing:-1.2px;font-weight:800;line-height:1.1}.brand img{height:48px;width:auto;max-width:120px;display:block}
.brand-mark{display:inline-block;width:9px;height:27px;background:var(--blue);border-radius:2px;margin-right:8px;vertical-align:baseline}
.company-info{font-size:10px;color:var(--muted);margin-top:10px;line-height:1.8}
.document{text-align:right}.document h1{margin:0;color:var(--blue);font-size:30px;letter-spacing:3px;font-weight:700;line-height:1.1}
.number{font-size:13px;font-weight:700;margin-top:8px}
.status{display:inline-block;background:#eef3ff;color:var(--blue);padding:4px 10px;border-radius:4px;font-size:9px;font-weight:700;letter-spacing:.7px;margin-top:9px}
.status.ok{background:#e6f6ec;color:#15803d}.status.bad{background:#fde8e8;color:#c81e1e}.status.warn{background:#fef3dc;color:#b45309}.status.void{background:#eceff3;color:#4b5563}
.overview{display:grid;grid-template-columns:1.15fr 1fr;gap:40px;padding:24px 0}
.label{font-size:9px;letter-spacing:1.3px;font-weight:700;color:var(--muted);margin:0 0 8px}
.customer{font-size:14px;font-weight:700;margin:0 0 5px}.address{color:#526079;font-size:11px;line-height:1.8}
.meta{display:grid;grid-template-columns:1fr 1fr;gap:8px 20px;margin:0;font-size:11px}.meta dt{color:var(--muted)}.meta dd{margin:0;text-align:right;font-weight:700}
.delivery-strip{display:flex;justify-content:space-between;gap:15px;background:#f7f9fc;border:1px solid var(--line);border-radius:5px;padding:10px 12px;font-size:10px;margin:0 0 26px}
.delivery-strip b{color:var(--muted);font-weight:500;margin-right:6px}
table{width:100%;border-collapse:collapse;font-size:11px}
thead th{background:#f1f5fc;color:#526079;padding:11px 10px;text-align:left;font-size:9px;font-weight:700;letter-spacing:.4px;border-top:1px solid var(--line);border-bottom:1px solid var(--line)}
tbody td{padding:17px 10px;border-bottom:1px solid var(--line);vertical-align:top}
.num{text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums}th.num{text-align:right}
.item-name{font-weight:700}.item-code{font-size:9px;color:var(--muted);margin-top:4px}
.summary{display:grid;grid-template-columns:1fr 265px;gap:28px;margin-top:24px}
.tax-note{font-size:10px;color:var(--muted);line-height:1.75}.tax-note strong{display:block;font-size:10px;color:#526079;margin-bottom:3px}
.total-row{display:flex;justify-content:space-between;gap:15px;padding:7px 0;font-size:11px;font-variant-numeric:tabular-nums}.total-row .muted{color:var(--muted)}
.total-row.grand{border-top:1px solid var(--line);margin-top:6px;padding-top:12px;font-size:14px;font-weight:700}
.amount-due{display:flex;justify-content:space-between;align-items:center;background:#edf3ff;border-left:3px solid var(--blue);border-radius:4px;padding:14px 13px;margin-top:12px;color:var(--blue)}
.amount-due span{font-size:10px;font-weight:700}.amount-due strong{font-size:19px;letter-spacing:-.5px;font-variant-numeric:tabular-nums}
.amount-due.settled{background:#e6f6ec;border-color:#15803d;color:#15803d}
.terms{margin-top:34px;border-top:1px solid var(--line);padding-top:18px;display:grid;grid-template-columns:1fr 1fr;gap:30px}
.terms p{font-size:10px;color:#526079;margin:0;line-height:1.8}
.etims{display:flex;gap:12px;align-items:center;margin-top:18px;font-size:10px;color:#526079}.etims img{width:70px;height:70px}
.thank-you{margin-top:auto;padding-top:45px;font-size:12px;font-weight:700}
.footer{border-top:1px solid var(--line);margin-top:14px;padding-top:10px;display:flex;justify-content:space-between;gap:15px;color:var(--muted);font-size:9px}
@page{size:A4;margin:0}
@media print{body{background:#fff;-webkit-print-color-adjust:exact;print-color-adjust:exact}.preview-bar{display:none}.page{width:210mm;min-height:297mm;margin:0;box-shadow:none}
.top,.overview,.summary,.terms,tbody tr{break-inside:avoid}thead{display:table-header-group}.footer{break-inside:avoid}}
@media screen and (max-width:820px){.preview-bar{margin-left:15px;margin-right:15px}.page{width:calc(100% - 24px);min-height:1000px;padding:28px}.overview{gap:25px}.summary{grid-template-columns:1fr 250px}}
@media screen and (max-width:550px){.top{gap:12px}.brand{font-size:27px}.document h1{font-size:23px;letter-spacing:2px}.overview{grid-template-columns:1fr;gap:20px}.summary,.terms{grid-template-columns:1fr}.page{overflow-x:auto;padding:22px}table{min-width:480px}.delivery-strip{flex-direction:column;gap:4px}}
</style></head><body>
<div class="preview-bar"><div><strong><?php echo pd_h($label.' '.$ref); ?></strong><small>A4 · Print or save as PDF</small></div><button type="button" id="print">Print / Save PDF</button></div>
<main class="page" aria-label="<?php echo pd_h($label.' '.$ref); ?>">
<header class="top"><div>
	<div class="brand"><?php echo ($logo ? '<img src="'.$logo.'" alt="">' : '<span class="brand-mark"></span>').'<span>'.pd_h($coy['name']).'</span>'; ?></div>
	<div class="company-info"><?php
		$info = array();
		if (trim($coy['address']) !== '' && $coy['address'] !== 'N/A') $info[] = nl2br(pd_h($coy['address']));
		$pe = array_filter(array($coy['phone'], $coy['email'])); if ($pe) $info[] = pd_h(implode(' · ', $pe));
		if ($coy['pin']) $info[] = pd_h(_('PIN / Tax ID').': '.$coy['pin']);
		echo implode('<br>', $info); ?></div>
</div><div class="document"><h1><?php echo pd_h($title); ?></h1><div class="number"># <?php echo pd_h($ref !== '' ? $ref : $no); ?></div>
<?php if ($status) echo '<span class="status '.($tint[$status] ?? '').'">'.pd_h($status).'</span>'; ?></div></header>

<section class="overview"><div><h2 class="label"><?php echo $type === ST_CUSTPAYMENT ? _('RECEIVED FROM') : ($type === ST_CUSTDELIVERY ? _('CUSTOMER') : _('BILL TO')); ?></h2>
	<p class="customer"><?php echo pd_h($billto[0]); ?></p><div class="address"><?php echo $addr(array_slice($billto, 1)); ?></div></div>
	<dl class="meta"><?php foreach ($meta as $k => $v) echo '<dt>'.pd_h($k).'</dt><dd>'.pd_h($v).'</dd>'; ?></dl></section>

<?php if ($type !== ST_CUSTPAYMENT): ?>
<div class="delivery-strip"><span><b><?php echo _('Deliver to'); ?></b> <?php echo pd_h(trim($delivery[0].(trim((string)$delivery[1]) !== '' ? ' · '.preg_replace('/\s*[\r\n]+\s*/', ', ', trim($delivery[1])) : ''))); ?></span>
<?php if (trim((string)$delivery[2]) !== '') echo '<span><b>'.($is_order ? _('Location') : _('Branch')).'</b> '.pd_h($delivery[2]).'</span>'; ?></div>
<?php endif; ?>

<table><thead><tr><th style="width:7%">#</th><th style="width:43%"><?php echo pd_h($line_heads[0]); ?></th><th class="num"><?php echo pd_h($line_heads[1]); ?></th>
<?php if ($show_prices): ?><th class="num"><?php echo pd_h($line_heads[2]); ?></th><th class="num"><?php echo pd_h(sprintf(_('AMOUNT (%s)'), $cur)); ?></th><?php endif; ?></tr></thead><tbody>
<?php foreach ($lines as $i => $l): ?>
<tr><td style="color:var(--muted)"><?php echo sprintf('%02d', $i + 1); ?></td>
<td><div class="item-name"><?php echo pd_h($l['name']); ?></div><?php
	$code = trim($l['code'].($l['units'] ? ' · '.$l['units'] : ''), ' ·');
	if ($code !== '' || $l['disc']) echo '<div class="item-code">'.pd_h($code).($l['disc'] ? ' · '.pd_h(sprintf(_('%s%% discount'), round($l['disc'] * 100, 1))) : '').'</div>'; ?></td>
<td class="num"><?php echo pd_h($l['qty']); ?></td>
<?php if ($show_prices): ?><td class="num"><?php echo pd_h($l['price']); ?></td><td class="num"><strong><?php echo pd_h($l['amount']); ?></strong></td><?php endif; ?></tr>
<?php endforeach; if (!$lines) echo '<tr><td colspan="5" style="color:var(--muted);text-align:center">'.pd_h(_('No line items.')).'</td></tr>'; ?>
</tbody></table>

<?php if ($show_prices): ?>
<section class="summary"><div class="tax-note"><?php
	if ($taxrows) {
		echo '<strong>'.pd_h($taxinc ? _('Tax included in price') : _('Tax')).'</strong>';
		foreach ($taxrows as $x) echo pd_h($x[0].': '.$cur.' '.pd_money($x[2])).'<br>';
		echo pd_h(sprintf(_('Total %s tax: %s %s'), $taxinc ? _('included') : '', $cur, pd_money($tax)));
	} elseif ($notes) echo '<strong>'.pd_h(_('Notes')).'</strong>'.nl2br(pd_h($notes)); ?></div>
<div>
<?php if ($type === ST_CUSTPAYMENT): ?>
	<div class="total-row"><span class="muted"><?php echo _('Allocated to documents'); ?></span><span><?php echo pd_money($paid); ?></span></div>
	<div class="total-row grand"><span><?php echo _('Amount received'); ?></span><span><?php echo pd_h($cur.' '.pd_money($total)); ?></span></div>
<?php else:
	$before = $taxinc ? $sub - $tax : ($is_order ? $sub : $t['ov_amount']);
	?>
	<div class="total-row"><span class="muted"><?php echo _('Amount before tax'); ?></span><span><?php echo pd_money($before); ?></span></div>
	<?php if (!$is_order && (float)$t['ov_freight']) echo '<div class="total-row"><span class="muted">'.pd_h(_('Shipping')).'</span><span>'.pd_money($t['ov_freight']).'</span></div>'; ?>
	<?php if ($tax) echo '<div class="total-row"><span class="muted">'.pd_h(_('Tax')).'</span><span>'.pd_money($tax).'</span></div>'; ?>
	<div class="total-row grand"><span><?php echo pd_h($type === ST_CUSTCREDIT ? _('Credit total') : _('Total')); ?></span><span><?php echo pd_h($cur.' '.pd_money($total)); ?></span></div>
	<?php if ($type === ST_SALESINVOICE && !$void): ?>
		<?php if ($paid > 0) echo '<div class="total-row"><span class="muted">'.pd_h(_('Paid')).'</span><span>'.pd_money($paid).'</span></div>'; ?>
		<div class="amount-due<?php echo $due <= 0 ? ' settled' : ''; ?>"><span><?php echo $due <= 0 ? _('PAID IN FULL') : _('AMOUNT DUE'); ?></span><strong><?php echo pd_h($cur.' '.pd_money(max(0, $due))); ?></strong></div>
	<?php endif; ?>
<?php endif; ?>
</div></section>
<?php endif; ?>

<?php if ($type === ST_SALESINVOICE): ?>
<section class="terms"><div><h2 class="label"><?php echo _('PAYMENT DETAILS'); ?></h2>
<?php if ($bank && ($bank['bank_name'] !== 'N/A' || $bank['bank_account_number'] !== 'N/A')): ?>
	<p><?php echo pd_h(implode(' · ', array_filter(array($bank['bank_name'] !== 'N/A' ? $bank['bank_name'] : '', $bank['bank_account_name']), 'strlen'))); ?>
	<?php if ($bank['bank_account_number'] !== 'N/A') echo '<br>'.pd_h(_('Account').': '.$bank['bank_account_number']); ?></p>
<?php endif; ?>
	<p style="margin-top:7px"><?php echo sprintf(_('Please use %s as your payment reference.'), '<strong>'.pd_h($ref).'</strong>'); ?></p></div>
<div><h2 class="label"><?php echo _('PAYMENT TERMS'); ?></h2><p><?php if ($terms_text) echo pd_h($terms_text).'<br>'; if ($due_date) echo sprintf(_('Payment due: %s.'), '<strong>'.pd_h(pd_d($due_date)).'</strong>'); ?></p></div></section>
<?php elseif ($notes && !$show_prices): ?>
<section class="terms"><div><h2 class="label"><?php echo _('NOTES'); ?></h2><p><?php echo nl2br(pd_h($notes)); ?></p></div></section>
<?php endif; ?>

<?php if ($etims): ?>
<div class="etims"><?php if ($etims['qr']) echo '<img src="'.$etims['qr'].'" alt="QR">'; ?><span><?php echo pd_h(($etims['qr'] ? _('Scan to verify with KRA eTIMS: ') : _('KRA eTIMS verification: ')).$etims['url']); ?></span></div>
<?php endif; ?>

<div class="thank-you"><?php echo $type === ST_CUSTPAYMENT ? _('Thank you for your payment.') : _('Thank you for your business.'); ?></div>
<footer class="footer"><span><?php echo pd_h($coy['name'].' · '.$label.' '.$ref); ?></span><span><?php echo pd_h(sprintf(_('Printed %s'), date('d M Y'))); ?></span></footer>
</main>
<script>document.getElementById('print').onclick=function(){window.print()};<?php if (!empty($_GET['autoprint'])) echo 'window.addEventListener("load",function(){setTimeout(function(){window.print()},300)});'; ?></script>
</body></html>
