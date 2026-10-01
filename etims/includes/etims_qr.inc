<?php
/**********************************************************************
    eTIMS - QR code image for the invoice/credit note printout. This
    codebase's bundled PDF library (reporting/includes/) only has 1D
    barcode support (TCPDFBarcode - Code39/Code128/etc, see
    reporting/includes/barcodes.php) - no QR/2D barcode capability at
    all, and nothing is vendored for it. Rather than hand-write a QR
    encoder (easy to get subtly wrong in ways that produce an
    unscannable code) or vendor an unverified third-party library, this
    calls a free, no-auth public QR generation API at print time with a
    short timeout, and the caller (reporting/rep107.php) falls back to
    printing the verification URL as plain text if it fails - a print
    action must never hang or break because a QR image couldn't be
    fetched.
***********************************************************************/

// Returns a path to a temp PNG file (caller must unlink it when done),
// or false on any failure - never throws, printing a PDF must not break
// because this couldn't be reached.
function etims_fetch_qr_png($url, $size = 150)
{
	$api_url = 'https://api.qrserver.com/v1/create-qr-code/?size=' . (int)$size . 'x' . (int)$size
		. '&data=' . urlencode($url);

	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $api_url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
	curl_setopt($ch, CURLOPT_TIMEOUT, 5);
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
	$data = curl_exec($ch);
	$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	curl_close($ch);

	if ($data === false || $http_code !== 200 || strlen($data) < 100)
		return false;
	// PNG magic bytes - a failed/HTML error response from the API (or a
	// captive portal on a flaky connection) wouldn't have this.
	if (substr($data, 0, 8) !== "\x89PNG\r\n\x1a\n")
		return false;

	$tmp = sys_get_temp_dir() . '/etims_qr_' . uniqid() . '.png';
	if (file_put_contents($tmp, $data) === false)
		return false;

	return $tmp;
}

// Shared by every PDF report that prints a stamped sales invoice or
// credit note (reporting/rep107.php for invoices, rep113.php for credit
// notes) - one place for the "fetch QR, place it, or fall back to
// plain-text URL" logic rather than duplicating it per report. $rep is
// the FrontReport instance mid-render; called right where the report
// wants the QR block to appear (after totals, in both current uses).
// No-op unless eTIMS stamping is in use, or the transaction isn't
// actually stamped yet.
function print_etims_qr($rep, $trans_type, $trans_no)
{
	if (!get_company_pref('use_etims_stamping'))
		return;

	$sub = db_fetch(db_query("SELECT status, short_url FROM " . TB_PREF . "etims_submissions
		WHERE trans_type=" . db_escape($trans_type) . " AND trans_no=" . db_escape($trans_no),
		'Cannot get eTIMS submission'));
	if (!$sub || $sub['status'] !== 'stamped' || !$sub['short_url'])
		return;

	$rep->NewLine(2);
	$qr_row = $rep->row;
	$qr_file = etims_fetch_qr_png($sub['short_url'], 150);
	if ($qr_file) {
		$rep->AddImage($qr_file, $rep->cols[0], $qr_row - 25, 25, 25);
		@unlink($qr_file);
		$rep->TextCol(1, 7, _("Scan to verify with KRA eTIMS: ") . $sub['short_url'], -2);
	} else {
		$rep->TextCol(0, 7, _("KRA eTIMS verification: ") . $sub['short_url'], -2);
	}
}
