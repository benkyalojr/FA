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
function etims_live_bar($button_html, $note)
{
	echo '<section class="ma-live-bar"><div class="ma-live-text"><strong>' . _('Live data') . '</strong><span>' . $note . '</span></div>'
		. $button_html . '</section>';
}

function etims_remote_load_requested($page = 1)
{
	$loaded = isset($_GET['load']) && $_GET['load'] === '1';
	etims_live_bar("<a class='ma-sales-new' id='ma-live-load' href='?load=1&amp;page=" . max(1, (int)$page) . "'>"
		. ($loaded ? _('Refresh live data') : _('Load live data')) . "</a>",
		_('Fetched from the eTIMS provider only when you ask. Loading may take up to 30 seconds per request (login may need another request).'));
	echo "<script>(function(){var b=document.getElementById('ma-live-load');if(!b)return;b.addEventListener('click',function(){"
		. "b.textContent=" . json_encode(_('Contacting eTIMS...')) . ";b.setAttribute('aria-busy','true');b.style.pointerEvents='none';b.style.opacity='.7';});})();</script>";
	if (!$loaded)
		echo "<div class='ma-empty'><strong>" . _('Nothing loaded yet') . "</strong><p>"
			. _('Press Load live data to fetch the current records from the eTIMS provider.') . "</p></div>";
	return $loaded;
}

// Shift previews use POST, separately from the confirmed Close Day action.
function etims_shift_preview_requested()
{
	start_form();
	etims_live_bar("<button class='ma-sales-new' type='submit' name='LOAD_SHIFT' value='1'>" . _('Load / refresh current shift') . "</button>",
		_('Loads the current shift from the eTIMS provider when needed. Opening this page does not contact the provider.'));
	end_form();
	return isset($_POST['LOAD_SHIFT']) && !isset($_POST['CONFIRM_CLOSE']);
}

function etims_remote_pager_nav($page, $total_pages, $total)
{
	$total_pages = max((int)$total_pages, 1);
	echo "<nav class='ma-live-pager' aria-label='" . _('Pages') . "'><span>"
		. sprintf(_('Page %d of %d - %d total'), $page, $total_pages, (int)$total) . "</span><span class='ma-live-pager-links'>";
	if ($page > 1)
		echo "<a class='ma-cfg-secondary' href='?load=1&amp;page=" . ($page - 1) . "'>&lsaquo; " . _('Previous') . "</a>";
	if ($page < $total_pages)
		echo "<a class='ma-cfg-secondary' href='?load=1&amp;page=" . ($page + 1) . "'>" . _('Next') . " &rsaquo;</a>";
	echo "</span></nav>";
}

function etims_status_cell($status)
{
	$text = html_specials_encode((string)$status);
	if ($status === 'active')
		return "<span class='ma-pill paid'>" . $text . "</span>";
	return $text === '' ? '' : "<span class='ma-pill pending'>" . $text . "</span>";
}
