<?php
/**********************************************************************
    eTIMS Submissions Inquiry - every FA sales invoice/credit note queued
    for KRA stamping, its live queue status (joined from bg_tasks - see
    etims/includes/db/etims_submit_db.inc for why status isn't
    duplicated here), and its KRA stamp data (QR link, receipt
    signature) once stamped. Retry re-arms a failed bg_tasks row.
***********************************************************************/
$page_security = 'SA_ETIMSVIEW';
$path_to_root = "../..";
include_once($path_to_root . "/includes/session.inc");
include_once($path_to_root . "/includes/ui.inc");

include_once($path_to_root . "/etims/includes/etims_setup.inc");
if (!etims_require_setup())
	return;
include_once($path_to_root . "/etims/includes/db/etims_submit_db.inc");

page(_($help_context = "eTIMS Submissions"));

if (isset($_GET['retry'])) {
	etims_retry_stamp((int)$_GET['retry']);
	display_notification(_('The submission will be retried on the next scheduler run.'));
}

start_table(TABLESTYLE, "width='100%'");
table_header(array(_("Trans"), _("Type"), _("Queue Status"), _("Attempts"), _("Last Error"),
	_("KRA Trader Invoice No"), _("Receipt Sign"), _("Stamped At"), _("QR"), ''));

$sql = "SELECT s.*, t.status AS task_status, t.attempts, t.max_attempts, t.last_error AS task_error
	FROM " . TB_PREF . "etims_submissions s
	LEFT JOIN " . TB_PREF . "bg_tasks t ON t.id = s.bg_task_id
	ORDER BY s.id DESC LIMIT 200";
$result = db_query($sql, 'Cannot list eTIMS submissions');

$doc_labels = array('invoice' => _('Sales Invoice'), 'credit_note' => _('Credit Note'));
$k = 0;
$count = 0;
while ($row = db_fetch($result)) {
	$count++;
	alt_table_row_color($k);
	label_cell($row['trans_no']);
	label_cell(isset($doc_labels[$row['doc_kind']]) ? $doc_labels[$row['doc_kind']] : html_specials_encode($row['doc_kind']));

	if ($row['status'] === 'stamped')
		label_cell("<span class='ma-pill paid'>" . _('Stamped') . "</span>");
	elseif ($row['task_status'] === 'failed')
		label_cell("<span class='ma-pill late'>" . _('Failed') . "</span>");
	else
		label_cell(html_specials_encode((string)$row['task_status']));

	label_cell($row['status'] === 'stamped' ? '' : ($row['attempts'] . ' / ' . $row['max_attempts']));
	label_cell($row['status'] === 'stamped' ? '' :
		'<span title="' . html_specials_encode((string)$row['task_error']) . '">'
		. html_specials_encode(mb_strimwidth((string)$row['task_error'], 0, 60, '...')) . '</span>');
	label_cell(html_specials_encode((string)$row['trd_invc_no']));
	label_cell(html_specials_encode((string)$row['rcpt_sign']));
	label_cell(html_specials_encode((string)$row['stamped_at']));

	if ($row['short_url'])
		label_cell("<a href='" . html_specials_encode($row['short_url']) . "' target='_blank'>" . _('View') . "</a>");
	else
		label_cell('');

	if ($row['status'] !== 'stamped' && $row['task_status'] === 'failed')
		echo "<td><a class='ma-sales-link' href='" . $_SERVER['PHP_SELF'] . "?retry=" . $row['id'] . "'>" . _('Retry') . "</a></td>";
	else
		label_cell('');

	end_row();
}
end_table(1);

if (!$count)
	display_note(_('No invoices or credit notes have been queued for eTIMS stamping yet.'));

end_page();
