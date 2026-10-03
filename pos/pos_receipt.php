<?php
// 80 mm till receipt for a point-of-sale invoice. ?no=<invoice>&tendered=<cash received>&autoprint=1
$page_security = 'SA_POS';
$path_to_root = "..";
include_once($path_to_root . "/includes/session.inc");
include_once($path_to_root . "/includes/date_functions.inc");
include_once($path_to_root . "/includes/ui.inc");
include_once($path_to_root . "/sales/includes/sales_db.inc");
include_once($path_to_root . "/sales/includes/db/cust_trans_details_db.inc");
include_once($path_to_root . "/etims/includes/etims_qr.inc");

$no = (int)($_GET['no'] ?? 0);
$t = get_customer_trans($no, ST_SALESINVOICE);
if (!$t || get_voided_entry(ST_SALESINVOICE, $no)) { http_response_code(404); echo _('Invoice not found.'); exit; }
$e = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
$money = function ($v) { return number_format((float)$v, user_price_dec()); };
$co = get_company_prefs();
$total = $t['ov_amount'] + $t['ov_gst'] + $t['ov_freight'] + $t['ov_freight_tax'] + $t['ov_discount'];
$included = !empty($t['tax_included']);

$pay = db_query("SELECT p.reference, a.amt, a.date_alloc FROM ".TB_PREF."cust_allocations a JOIN ".TB_PREF."debtor_trans p
	ON p.type=a.trans_type_from AND p.trans_no=a.trans_no_from WHERE a.trans_type_to=".ST_SALESINVOICE." AND a.trans_no_to=$no", 'payments');
$payments = array();
while ($p = db_fetch($pay)) {
	$memo = db_fetch(db_query("SELECT memo_ FROM ".TB_PREF."comments WHERE type=".ST_CUSTPAYMENT." AND id=(SELECT trans_no FROM ".TB_PREF."debtor_trans WHERE type=".ST_CUSTPAYMENT." AND reference=".db_escape($p['reference'])." LIMIT 1)", 'memo'));
	$payments[] = array(trim(preg_replace('/^POS [^-]*- /', '', (string)($memo['memo_'] ?? ''))) ?: _('Payment'), (float)$p['amt']);
}
$tendered = (float)($_GET['tendered'] ?? 0);
$res = db_query("SELECT * FROM ".TB_PREF."debtor_trans_details WHERE debtor_trans_type=".ST_SALESINVOICE." AND debtor_trans_no=$no AND quantity<>0 ORDER BY id", 'lines');
$rows = array(); $line_tax = 0.0;
while ($l = db_fetch($res)) { $rows[] = $l; $line_tax += $l['unit_tax'] * $l['quantity']; }
$qty_total = 0.0; foreach ($rows as $l) $qty_total += (float)$l['quantity'];
$logo = !empty($co['coy_logo']) && is_file(company_path().'/images/'.$co['coy_logo']) ? company_path().'/images/'.$co['coy_logo'] : '';
$qty = function ($v) { return rtrim(rtrim(number_format((float)$v, 4), '0'), '.'); };
// KRA eTIMS stamp, when the invoice has been stamped: control unit details and the verification QR code.
$kra = null; $kra_qr = '';
if (get_company_pref('use_etims_stamping')) {
	$kra = db_fetch(db_query("SELECT status, short_url, invc_no, rcpt_sign, intrl_data, cur_rcpt_no, tot_rcpt_no, sdc_date_time FROM ".TB_PREF."etims_submissions
		WHERE trans_type=".ST_SALESINVOICE." AND trans_no=$no", 'etims'));
	if (!$kra || $kra['status'] !== 'stamped' || !$kra['short_url']) $kra = null;
	else { $f = etims_fetch_qr_png($kra['short_url'], 220); if ($f) { $kra_qr = 'data:image/png;base64,'.base64_encode(file_get_contents($f)); @unlink($f); } }
}
// Tax summary: one row per tax code. eTIMS item mapping gives KRA's code (A exempt, B 16%, C zero-rated, D non-VAT, E 8%);
// items without a mapping are grouped by the rate the invoice actually charged.
$kra_codes = array('A' => array(_('Exempt'), 0), 'B' => array(_('VAT'), 16), 'C' => array(_('Zero-rated'), 0), 'D' => array(_('Non-VAT'), 0), 'E' => array(_('VAT'), 8));
$use_codes = (bool)get_company_pref('use_etims_stamping');
$summary = array(); $codes = array();
foreach ($rows as $n => $l) {
	$gross = round2((1 - $l['discount_percent']) * $l['unit_price'] * $l['quantity'], user_price_dec());
	$code = '';
	if ($use_codes) {
		$m = db_fetch(db_query("SELECT tax_ty_cd FROM ".TB_PREF."etims_item_map WHERE stock_id=".db_escape($l['stock_id']), 'etims map'));
		$code = $m && isset($kra_codes[$m['tax_ty_cd']]) ? $m['tax_ty_cd'] : '';
	}
	if ($code !== '') $rate = $kra_codes[$code][1];
	else {
		$net_unit = $included ? $l['unit_price'] - $l['unit_tax'] : $l['unit_price'];
		$rate = $net_unit > 0 ? round($l['unit_tax'] / $net_unit * 100, 1) : 0;
	}
	if ($included) { $taxable = round($gross / (1 + $rate / 100), 2); $vat = round($gross - $taxable, 2); }
	else { $taxable = $gross; $vat = round($gross * $rate / 100, 2); }
	$key = $code !== '' ? $code : 'r'.$rate;
	$label = $code !== '' ? $kra_codes[$code][0].($kra_codes[$code][1] ? ' '.$kra_codes[$code][1].'%' : '') : ($rate > 0 ? _('VAT').' '.rtrim(rtrim(number_format($rate, 1), '0'), '.').'%' : _('Zero tax'));
	if (!isset($summary[$key])) $summary[$key] = array('code' => $code, 'label' => $label, 'rate' => $rate, 'taxable' => 0.0, 'tax' => 0.0);
	$summary[$key]['taxable'] += $taxable; $summary[$key]['tax'] += $vat;
	$codes[$n] = $code;
}
ksort($summary);
$tax = $included ? $line_tax : (float)$t['ov_gst'];   // tax-inclusive prices keep the tax inside the line price
?><!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $e(_('Receipt').' '.$t['reference']) ?></title>
<style>
@page { size: 80mm auto; margin: 0 }
* { box-sizing: border-box }
body { margin: 0; font: 12px/1.4 -apple-system, "Helvetica Neue", Arial, sans-serif; color: #000; -webkit-print-color-adjust: exact; print-color-adjust: exact }
.paper { width: 80mm; padding: 6mm 5mm 7mm; background: #fff; margin: 0 auto }
.num { font-variant-numeric: tabular-nums; font-feature-settings: "tnum"; text-align: right; white-space: nowrap }
.head { text-align: center }
.logo { display: block; max-width: 46mm; max-height: 18mm; margin: 0 auto 6px; filter: grayscale(1) contrast(1.4) }
.name { font-size: 17px; font-weight: 800; letter-spacing: .02em; text-transform: uppercase; line-height: 1.15 }
.info { margin-top: 4px; font-size: 11px; color: #222; line-height: 1.45 }
.badge { margin: 10px 0 8px; padding: 5px 0; border: 1.5px solid #000; border-radius: 3px; text-align: center; font-weight: 800; font-size: 12px; letter-spacing: .32em; text-indent: .32em }
.meta { width: 100%; border-collapse: collapse; font-size: 11.5px }
.meta td { padding: 1.5px 0; vertical-align: top }
.meta td:first-child { color: #333; padding-right: 8px }
.meta td:last-child { text-align: right; font-weight: 600; word-break: break-word }
.rule { border: 0; border-top: 1px dashed #000; margin: 9px 0 }
.rule.solid { border-top: 1.5px solid #000 }
.cols { display: flex; justify-content: space-between; font-size: 10px; font-weight: 700; letter-spacing: .12em; text-transform: uppercase; margin-bottom: 5px }
.item { margin-bottom: 7px; break-inside: avoid }
.item .top { display: flex; justify-content: space-between; gap: 10px; font-weight: 600 }
.item .top span:first-child { flex: 1; word-break: break-word }
.item .sub { margin-top: 1px; font-size: 11px; color: #333 }
.tot { width: 100%; border-collapse: collapse; font-size: 12px }
.tot td { padding: 2px 0 }
.tot td:first-child { color: #222 }
.tot td:last-child { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap }
.grand td { padding: 7px 0 5px; font-size: 18px; font-weight: 800; color: #000 !important }
.grand td:first-child { letter-spacing: .06em }
.paid td:first-child { font-weight: 600; color: #000 }
.due { margin-top: 6px; padding: 6px 8px; background: #000; color: #fff; border-radius: 3px; display: flex; justify-content: space-between; font-weight: 800; font-size: 13px; letter-spacing: .04em }
.thanks { margin-top: 12px; text-align: center }
.thanks strong { display: block; font-size: 13px; letter-spacing: .04em }
.thanks span { display: block; margin-top: 3px; font-size: 10.5px; color: #333 }
.count { margin-top: 4px; font-size: 11px; color: #333; text-align: center }
.taxes h3 { margin: 0 0 5px; font-size: 10px; letter-spacing: .14em; text-transform: uppercase }
.taxes table { width: 100%; border-collapse: collapse; font-size: 10.5px }
.taxes td { padding: 1.5px 0; vertical-align: top; text-align: right; font-variant-numeric: tabular-nums }
.taxes td:first-child { text-align: left; padding-right: 4px }
.taxes .th td { font-weight: 700; font-size: 9.5px; text-transform: uppercase; letter-spacing: .06em; border-bottom: 1px solid #000; padding-bottom: 2px }
.taxes .sum td { font-weight: 800; border-top: 1px solid #000; padding-top: 3px }
.served { font-weight: 700; font-size: 12px; margin-bottom: 6px }
.kra { margin-top: 10px; text-align: center; break-inside: avoid }
.kra h3 { margin: 0 0 6px; font-size: 11px; letter-spacing: .14em; text-transform: uppercase }
.kra img { width: 36mm; height: 36mm; display: block; margin: 0 auto 5px; image-rendering: pixelated }
.kra .scan { font-size: 10.5px; color: #222 }
.kra table { width: 100%; border-collapse: collapse; margin-top: 7px; font-size: 10.5px; text-align: left }
.kra td { padding: 1px 0; vertical-align: top }
.kra td:first-child { color: #333; padding-right: 8px; white-space: nowrap }
.kra td:last-child { text-align: right; word-break: break-all; font-weight: 600 }
.actions { display: none }
@media screen {
  html { background: #dfe6e2 }
  body { padding: 24px 0 90px }
  .paper { box-shadow: 0 1px 2px #0003, 0 14px 40px #0002; border-radius: 3px 3px 0 0; position: relative }
  .paper::after { content: ""; position: absolute; left: 0; right: 0; bottom: -8px; height: 8px;
    background: linear-gradient(135deg, #fff 50%, transparent 50%) 0 0/12px 8px repeat-x, linear-gradient(225deg, #fff 50%, transparent 50%) 0 0/12px 8px repeat-x; filter: drop-shadow(0 4px 4px #0002) }
  .actions { display: flex; gap: 8px; position: fixed; left: 0; right: 0; bottom: 0; justify-content: center; padding: 14px; background: #fffe; backdrop-filter: blur(6px); border-top: 1px solid #0001 }
  .actions button { font: 600 14px -apple-system, "Helvetica Neue", Arial, sans-serif; padding: 10px 22px; border-radius: 8px; border: 1px solid #008c4f; background: #008c4f; color: #fff; cursor: pointer }
  .actions button.alt { background: #fff; color: #14201a; border-color: #b9c6bf }
}
@media print { html, body { background: #fff } .paper { margin: 0; padding-bottom: 4mm } }
</style></head><body>
<div class="paper">
  <div class="head">
    <?php if ($logo): ?><img class="logo" src="<?= $e($logo) ?>" alt=""><?php endif; ?>
    <div class="name"><?= $e($co['coy_name'] ?? '') ?></div>
    <div class="info"><?= nl2br($e(trim((string)($co['postal_address'] ?? '')))) ?><?= !empty($co['phone']) ? '<br>'.$e(_('Tel').': '.$co['phone']) : '' ?><?= !empty($co['email']) ? '<br>'.$e($co['email']) : '' ?><?= !empty($co['gst_no']) ? '<br>'.$e(_('PIN').': '.$co['gst_no']) : '' ?></div>
  </div>

  <div class="badge"><?= $e(strtoupper(_('Sales receipt'))) ?></div>

  <table class="meta">
    <tr><td><?= $e(_('Receipt no.')) ?></td><td><?= $e($t['reference']) ?></td></tr>
    <tr><td><?= $e(_('Date')) ?></td><td><?= $e(sql2date($t['tran_date'])) ?></td></tr>
    <tr><td><?= $e(_('Customer')) ?></td><td><?= $e($t['DebtorName'] ?? '') ?></td></tr>
  </table>

  <hr class="rule">
  <div class="cols"><span><?= $e(_('Item')) ?></span><span><?= $e(_('Amount')) ?></span></div>
  <?php foreach ($rows as $n => $l): $line = round2((1 - $l['discount_percent']) * $l['unit_price'] * $l['quantity'], user_price_dec()); ?>
  <div class="item">
    <div class="top"><span><?= $e($l['description']) ?></span><span class="num"><?= $e($money($line)) ?><?= $codes[$n] !== '' ? ' '.$e($codes[$n]) : '' ?></span></div>
    <div class="sub num" style="text-align:left"><?= $e($qty($l['quantity']).' x '.$money($l['unit_price']).($l['discount_percent'] ? '  (-'.round($l['discount_percent'] * 100, 1).'%)' : '')) ?></div>
  </div>
  <?php endforeach; ?>
  <div class="count"><?= $e(sprintf(_('%s items, %s units'), count($rows), $qty($qty_total))) ?></div>

  <hr class="rule solid">
  <table class="tot">
    <?php if ($t['ov_discount'] != 0): ?><tr><td><?= $e(_('Discount')) ?></td><td><?= $e($money($t['ov_discount'])) ?></td></tr><?php endif; ?>
    <tr><td><?= $e($included ? _('Includes tax') : _('Tax')) ?></td><td><?= $e($money($tax)) ?></td></tr>
    <tr class="grand"><td><?= $e(strtoupper(_('Total'))) ?></td><td><?= $e($co['curr_default'] ?? '') ?> <?= $e($money($total)) ?></td></tr>
    <?php foreach ($payments as $p): ?><tr class="paid"><td><?= $e(_('Paid')) ?> - <?= $e($p[0]) ?></td><td><?= $e($money($p[1])) ?></td></tr><?php endforeach; ?>
    <?php if ($tendered > 0): ?>
    <tr><td><?= $e(_('Cash received')) ?></td><td><?= $e($money($tendered)) ?></td></tr>
    <tr class="paid"><td><?= $e(_('Change')) ?></td><td><?= $e($money(max(0, $tendered - $total))) ?></td></tr>
    <?php endif; ?>
  </table>
  <?php if (floatcmp($total, $t['alloc']) > 0): ?><div class="due"><span><?= $e(strtoupper(_('Balance due'))) ?></span><span class="num"><?= $e($money($total - $t['alloc'])) ?></span></div><?php endif; ?>

  <?php if ($summary): ?>
  <hr class="rule">
  <div class="taxes">
    <h3><?= $e(_('Tax summary')) ?></h3>
    <table>
      <tr class="th"><td><?= $e(_('Code')) ?></td><td><?= $e(_('Rate')) ?></td><td><?= $e(_('Taxable')) ?></td><td><?= $e(_('Tax')) ?></td></tr>
      <?php foreach ($summary as $r): ?>
      <tr><td><?= $e($r['code'] !== '' ? $r['code'].' - '.$r['label'] : $r['label']) ?></td><td><?= $e(rtrim(rtrim(number_format($r['rate'], 1), '0'), '.')) ?>%</td><td><?= $e($money($r['taxable'])) ?></td><td><?= $e($money($r['tax'])) ?></td></tr>
      <?php endforeach; ?>
      <tr class="sum"><td colspan="2"><?= $e(_('Total')) ?></td><td><?= $e($money(array_sum(array_column($summary, 'taxable')))) ?></td><td><?= $e($money(array_sum(array_column($summary, 'tax')))) ?></td></tr>
    </table>
  </div>
  <?php endif; ?>

  <hr class="rule">
  <div class="thanks"><div class="served"><?= $e(sprintf(_('You were served by %s'), $_SESSION['wa_current_user']->name)) ?></div><strong><?= $e(_('Thank you for your business')) ?></strong><span><?= $e(_('Keep this receipt as proof of purchase.')) ?></span></div>
  <?php if ($kra): ?>
  <hr class="rule">
  <div class="kra">
    <h3><?= $e(_('KRA eTIMS verified')) ?></h3>
    <?php if ($kra_qr): ?><img src="<?= $e($kra_qr) ?>" alt="KRA QR code"><div class="scan"><?= $e(_('Scan to verify this receipt with KRA')) ?></div>
    <?php else: ?><div class="scan"><?= $e(_('Verify this receipt with KRA')) ?>:<br><?= $e($kra['short_url']) ?></div><?php endif; ?>
    <table>
      <?php if ($kra['invc_no']): ?><tr><td><?= $e(_('Invoice no.')) ?></td><td><?= $e($kra['invc_no']) ?></td></tr><?php endif; ?>
      <?php if ($kra['cur_rcpt_no']): ?><tr><td><?= $e(_('Receipt no.')) ?></td><td><?= $e($kra['cur_rcpt_no'].($kra['tot_rcpt_no'] ? '/'.$kra['tot_rcpt_no'] : '')) ?></td></tr><?php endif; ?>
      <?php if ($kra['sdc_date_time']): ?><tr><td><?= $e(_('Stamped')) ?></td><td><?= $e($kra['sdc_date_time']) ?></td></tr><?php endif; ?>
      <?php if ($kra['rcpt_sign']): ?><tr><td><?= $e(_('Signature')) ?></td><td><?= $e($kra['rcpt_sign']) ?></td></tr><?php endif; ?>
      <?php if ($kra['intrl_data']): ?><tr><td><?= $e(_('Internal data')) ?></td><td><?= $e($kra['intrl_data']) ?></td></tr><?php endif; ?>
    </table>
  </div>
  <?php endif; ?>
</div>

<div class="actions"><button type="button" onclick="window.print()"><?= $e(_('Print')) ?></button><button type="button" class="alt" onclick="window.close()"><?= $e(_('Close')) ?></button></div>
<?php if (!empty($_GET['autoprint'])): ?><script>window.addEventListener('load',function(){setTimeout(function(){window.print()},250)})</script><?php endif; ?>
</body></html>
