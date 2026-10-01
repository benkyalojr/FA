<?php
// Public, read-only copy of a shared sales invoice: /share/invoice/<token>.
// Deliberately standalone (no FA session or login): it only ever reads the one
// document its unguessable, revocable token points at.
$root = dirname(__DIR__);
require $root.'/config_db.php';

function share_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function share_page($title, $body, $code = 200)
{
	http_response_code($code);
	header('Content-Type: text/html; charset=UTF-8');
	header('X-Robots-Tag: noindex, nofollow');
	header('Cache-Control: no-store');
	header('Referrer-Policy: no-referrer');
	$nonce = base64_encode(random_bytes(12));
	header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; script-src 'nonce-$nonce'; img-src data:; base-uri 'none'; form-action 'none'");
	echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">'
		.'<title>'.share_h($title).'</title><style>'.file_get_contents(__DIR__.'/share.css').'</style></head><body>'.$body
		.'<script nonce="'.$nonce.'">var b=document.getElementById("sh-print");if(b)b.onclick=function(){window.print()};</script></body></html>';
	exit;
}

function share_invalid()
{
	share_page('Link unavailable', '<main class="sh-wrap"><div class="sh-card sh-empty"><h1>This link is unavailable</h1>'
		.'<p>It may have expired or been withdrawn by the sender. Please ask them for a new link.</p></div></main>', 404);
}

// Reached by rewrite (?t=) or, on servers that fall back to this script, by the /share/invoice/<token> path.
$token = (string)($_GET['t'] ?? '');
if ($token === '' && preg_match('~/share/invoice/([A-Za-z0-9_-]+)/?$~', (string)parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), $m)) $token = $m[1];
if (!preg_match('/^[A-Za-z0-9_-]{20,64}$/', $token)) share_invalid();

$c = $db_connections[$def_coy];
mysqli_report(MYSQLI_REPORT_OFF);
$db = @new mysqli($c['host'], $c['dbuser'], $c['dbpassword'], $c['dbname'], $c['port'] !== '' ? (int)$c['port'] : 3306);
if ($db->connect_errno) share_page('Unavailable', '<main class="sh-wrap"><div class="sh-card sh-empty"><h1>Temporarily unavailable</h1></div></main>', 503);
$db->set_charset('utf8mb4');
$p = $c['tbpref'];
$one = function ($sql) use ($db) { $r = $db->query($sql); return $r ? $r->fetch_assoc() : null; };
$all = function ($sql) use ($db) { $r = $db->query($sql); $o = array(); while ($r && ($row = $r->fetch_assoc())) $o[] = $row; return $o; };
$q = function ($v) use ($db) { return "'".$db->real_escape_string((string)$v)."'"; };

$link = $one("SELECT * FROM {$p}share_links WHERE token=".$q($token)." AND doc_type=10 AND revoked=0 AND (expires_at IS NULL OR expires_at > NOW())");
if (!$link) share_invalid();
$no = (int)$link['trans_no'];
$t = $one("SELECT t.*, d.name, d.address, d.curr_code, b.br_name, pt.terms, o.deliver_to, o.delivery_address, o.contact_phone, v.date_ AS voided_on
	FROM {$p}debtor_trans t JOIN {$p}debtors_master d ON d.debtor_no=t.debtor_no
	LEFT JOIN {$p}cust_branch b ON b.branch_code=t.branch_code
	LEFT JOIN {$p}payment_terms pt ON pt.terms_indicator=t.payment_terms
	LEFT JOIN {$p}sales_orders o ON o.order_no=t.order_ AND o.trans_type=30
	LEFT JOIN {$p}voided v ON v.type=t.type AND v.id=t.trans_no
	WHERE t.type=10 AND t.trans_no=$no");
if (!$t) share_invalid();
$db->query("UPDATE {$p}share_links SET views=views+1, last_viewed=NOW() WHERE id=".(int)$link['id']);

$logo = is_file($root.'/ui/logo.png') ? 'data:image/png;base64,'.base64_encode(file_get_contents($root.'/ui/logo.png')) : '';
$pref = function ($name) use ($one, $p, $q) { $r = $one("SELECT value FROM {$p}sys_prefs WHERE name=".$q($name)); return $r ? $r['value'] : ''; };
$dec = (int)($pref('price_dec') ?: 2);
$m = function ($n) use ($dec) { return number_format((float)$n, $dec); };
$cur = $t['curr_code'];
$taxinc = !empty($t['tax_included']);
$total = $t['ov_amount'] + $t['ov_gst'] + $t['ov_freight'] + $t['ov_freight_tax'];
$paid = (float)$t['alloc'];
$due = round($total - $paid, $dec);
if ($t['voided_on']) $status = array('VOIDED', 'void');
elseif ($due <= 0) $status = array('PAID', 'paid');
elseif ($paid > 0) $status = array('PARTLY PAID', 'part');
else $status = array('UNPAID', 'unpaid');

$lines = $all("SELECT l.*, s.units FROM {$p}debtor_trans_details l LEFT JOIN {$p}stock_master s ON s.stock_id=l.stock_id
	WHERE l.debtor_trans_type=10 AND l.debtor_trans_no=$no AND l.quantity<>0 ORDER BY l.id");
$taxes = $all("SELECT tt.name, d.rate, d.amount FROM {$p}trans_tax_details d JOIN {$p}tax_types tt ON tt.id=d.tax_type_id
	WHERE d.trans_type=10 AND d.trans_no=$no AND d.amount<>0");

$rows = ''; $sub = 0;
foreach ($lines as $l) {
	$price = $l['unit_price']; // already the full price when tax is included
	$value = round((1 - $l['discount_percent']) * $price * $l['quantity'], $dec);
	$sub += $value;
	$rows .= '<tr><td>'.share_h($l['stock_id']).'</td><td>'.share_h($l['description']).'</td><td class="n">'.share_h(rtrim(rtrim(number_format((float)$l['quantity'], 4), '0'), '.').' '.$l['units'])
		.'</td><td class="n">'.$m($price).'</td><td class="n">'.($l['discount_percent'] ? share_h(round($l['discount_percent'] * 100, 1).'%') : '—').'</td><td class="n"><b>'.$m($value).'</b></td></tr>';
}
if ($rows === '') $rows = '<tr><td colspan="6" class="sh-muted">No line items.</td></tr>';

$tot = '<div><span>Subtotal</span><b>'.share_h($cur).' '.$m($taxinc ? $sub : $t['ov_amount']).'</b></div>';
if ((float)$t['ov_freight']) $tot .= '<div><span>Shipping</span><b>'.share_h($cur).' '.$m($t['ov_freight']).'</b></div>';
foreach ($taxes as $x) $tot .= '<div><span>'.share_h($x['name']).($taxinc ? ' (included)' : '').'</span><span>'.share_h($cur).' '.$m($x['amount']).'</span></div>';
$tot .= '<div class="g"><span>Total</span><b>'.share_h($cur).' '.$m($total).'</b></div>';
if ($paid > 0) $tot .= '<div><span>Paid</span><span>'.share_h($cur).' '.$m($paid).'</span></div>';
if ($due > 0 && !$t['voided_on']) $tot .= '<div class="g"><span>Balance due</span><b>'.share_h($cur).' '.$m($due).'</b></div>';

$coy_name = trim($pref('coy_name'));
if ($coy_name === '' || $coy_name === 'Company name') $coy_name = $c['name'];
$d = function ($s) { return date('d M Y', strtotime($s)); };
$body = '<main class="sh-wrap"><article class="sh-card"><div class="sh-ribbon '.$status[1].'" role="status">'.share_h($status[0]).'</div>'
	.'<header class="sh-head"><div>'.($logo ? '<img class="sh-logo" src="'.$logo.'" alt="">' : '').'<div class="sh-eyebrow">SALES INVOICE</div><h1>Invoice '.share_h($t['reference']).'</h1></div>'
	.'<div class="sh-co"><b>'.share_h($coy_name).'</b><br>'.nl2br(share_h($pref('postal_address') !== 'N/A' ? $pref('postal_address') : '')).'<br>'.share_h($pref('phone')).'<br>'.share_h($pref('email')).'</div></header>'
	.'<div class="sh-grid"><dl><dt>Invoice date</dt><dd>'.$d($t['tran_date']).'</dd><dt>Due date</dt><dd>'.$d($t['due_date']).'</dd>'
	.($t['terms'] ? '<dt>Payment terms</dt><dd>'.share_h($t['terms']).'</dd>' : '').'<dt>Currency</dt><dd>'.share_h($cur).'</dd></dl>'
	.'<section><h2>Billed to</h2><p><b>'.share_h($t['name']).'</b></p><p>'.nl2br(share_h($t['address'])).'</p></section>'
	.'<section><h2>Delivered to</h2><p><b>'.share_h($t['deliver_to'] ?: $t['name']).'</b></p><p>'.nl2br(share_h($t['delivery_address'] ?: $t['address'])).'</p></section></div>'
	.($t['voided_on'] ? '<div class="sh-void">This invoice has been voided.</div>' : '')
	.'<div class="sh-scroll"><table><thead><tr><th>Item</th><th>Description</th><th class="n">Qty</th><th class="n">Price</th><th class="n">Disc.</th><th class="n">Total</th></tr></thead><tbody>'.$rows.'</tbody></table></div>'
	.'<div class="sh-tot">'.($taxinc ? '<p class="sh-muted">Tax is included in the displayed prices.</p>' : '<p></p>').'<div class="sh-box">'.$tot.'</div></div>'
	.'<footer class="sh-foot"><span>Thank you for your business.</span><button type="button" id="sh-print">Print</button></footer></article></main>';
share_page('Invoice '.$t['reference'], $body);
