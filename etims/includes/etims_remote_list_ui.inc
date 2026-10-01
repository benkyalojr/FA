<?php
/**********************************************************************
    eTIMS - shared helpers for the read-only "what does Stanbest itself
    have on file" inquiry screens (Products/Sales/Credit Notes). These
    list Stanbest's own paginated data directly, not a local FA table,
    so they don't use FA's db_pager (SQL-query-based) - a small,
    self-contained page-number pager instead.
***********************************************************************/

// Stanbest's own dates come back as YYYYMMDDHHMMSS strings (e.g.
// "20260921084040") - reformat for display, tolerant of anything
// shorter/malformed rather than erroring.
function etims_fmt_dt($s)
{
	$s = (string)$s;
	if (strlen($s) < 8)
		return html_specials_encode($s);
	$out = substr($s, 0, 4) . '-' . substr($s, 4, 2) . '-' . substr($s, 6, 2);
	if (strlen($s) >= 14)
		$out .= ' ' . substr($s, 8, 2) . ':' . substr($s, 10, 2) . ':' . substr($s, 12, 2);
	return html_specials_encode($out);
}

// Merely opening an inquiry never waits for the provider. Pagination and
// refresh explicitly request current data; no cached fiscal totals are shown.
function etims_remote_load_requested($page = 1)
{
	display_note(_('Live data is fetched from the eTIMS provider only when requested. Loading may take up to 30 seconds per request (login may require another request).'));
	echo "<center><a href='?load=1&amp;page=" . max(1, (int)$page) . "'>"
		. _('Load / refresh live data') . "</a></center><br>";
	return isset($_GET['load']) && $_GET['load'] === '1';
}

// Shift previews use POST, separately from the confirmed Close Day action.
function etims_shift_preview_requested()
{
	display_note(_('Load the current shift from the eTIMS provider when needed. Opening this page does not contact the provider.'));
	start_form();
	submit_center('LOAD_SHIFT', _('Load / refresh current shift'));
	end_form();
	return isset($_POST['LOAD_SHIFT']) && !isset($_POST['CONFIRM_CLOSE']);
}

function etims_remote_pager_nav($page, $total_pages, $total)
{
	echo "<center>";
	echo sprintf(_('Page %d of %d (%d total)'), $page, max((int)$total_pages, 1), (int)$total);
	echo "&nbsp;&nbsp;";
	if ($page > 1)
		echo "<a href='?load=1&amp;page=" . ($page - 1) . "'>&laquo; " . _('Previous') . "</a>&nbsp;&nbsp;";
	if ($page < $total_pages)
		echo "<a href='?load=1&amp;page=" . ($page + 1) . "'>" . _('Next') . " &raquo;</a>";
	echo "</center>";
}

function etims_status_cell($status)
{
	if ($status === 'active')
		return "<span style='color: green;'>" . html_specials_encode($status) . "</span>";
	return html_specials_encode((string)$status);
}
