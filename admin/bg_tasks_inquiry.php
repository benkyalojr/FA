<?php
/**********************************************************************
    Background Tasks - inquiry over the bg_tasks queue (see
    includes/bg_tasks.inc, sql/bg_tasks.php, cron/run_scheduled_tasks.php).
    Core-level, not Communications-specific - lives under Setup >
    Maintenance next to System Audit Trail rather than inside any one
    module, same reasoning the queue itself was built with.
***********************************************************************/
$page_security = 'SA_BGTASKS';
$path_to_root = "..";
include_once($path_to_root . "/includes/session.inc");
include_once($path_to_root . "/includes/ui.inc");
include_once($path_to_root . "/sql/bg_tasks.php");

page(_($help_context = "Background Tasks"));

//-----------------------------------------------------------------------------------
// "Run now" - queues a pending task to be picked up immediately (skips
// whatever backoff delay it's currently under), or gives a failed task
// (one that already exhausted max_attempts) a fresh set of retries. Only
// resets attempts/last_error for a failed row - a merely-pending row's
// attempt count is left alone, this is "don't make me wait", not "start over".
if (isset($_GET['run_now'])) {
	$id = (int)$_GET['run_now'];
	$row = db_fetch(db_query("SELECT status FROM " . TB_PREF . "bg_tasks WHERE id=" . $id));
	if (!$row || !in_array($row['status'], array('pending', 'failed'), true)) {
		display_error(_("Only pending or failed tasks can be queued to run now."));
	} else {
		if ($row['status'] === 'failed')
			db_query("UPDATE " . TB_PREF . "bg_tasks SET status='pending', attempts=0, last_error=NULL,
				next_attempt_at=NOW(), updated_at=NOW() WHERE id=" . $id);
		else
			db_query("UPDATE " . TB_PREF . "bg_tasks SET next_attempt_at=NOW(), updated_at=NOW() WHERE id=" . $id);
		display_notification(_("The task will be picked up on the next scheduler run."));
	}
}

//-----------------------------------------------------------------------------------

$counts = array('pending' => 0, 'running' => 0, 'done' => 0, 'failed' => 0);
$result = db_query("SELECT status, COUNT(*) AS n FROM " . TB_PREF . "bg_tasks GROUP BY status");
while ($row = db_fetch($result))
	$counts[$row['status']] = (int)$row['n'];

$lock = db_fetch(db_query("SELECT IS_FREE_LOCK('bg_tasks_runner') AS free"));
$runner_idle = $lock && (int)$lock['free'] === 1;

start_table(TABLESTYLE_NOBORDER);
start_row();
label_cell(sprintf(_("Pending: %d"), $counts['pending']));
label_cell(sprintf(_("Running: %d"), $counts['running']));
label_cell(sprintf(_("Done: %d"), $counts['done']));
label_cell(sprintf(_("Failed: %d"), $counts['failed']));
label_cell($runner_idle ? _("Scheduler: idle") : _("Scheduler: a run is in progress"));
end_row();
end_table(1);

//-----------------------------------------------------------------------------------

$task_types = array();
$result = db_query("SELECT DISTINCT task_type FROM " . TB_PREF . "bg_tasks ORDER BY task_type");
while ($row = db_fetch($result))
	$task_types[$row['task_type']] = $row['task_type'];

$statuses = array(
	'pending' => _('Pending'),
	'running' => _('Running'),
	'done' => _('Done'),
	'failed' => _('Failed'),
);

start_form();
start_table(TABLESTYLE_NOBORDER);
start_row();
echo "<td>" . _("Status:") . "</td><td>";
echo array_selector('show_status', get_post('show_status'), array_merge(array('' => _('-- Any --')), $statuses));
echo "</td>";
echo "<td>" . _("Task type:") . "</td><td>";
echo array_selector('show_task_type', get_post('show_task_type'), array_merge(array('' => _('-- Any --')), $task_types));
echo "</td>";
submit_cells('RefreshInquiry', _("Show"), '', _('Refresh'), 'default');
end_row();
end_table();

$where = array();
if (get_post('show_status') !== '')
	$where[] = "status=" . db_escape(get_post('show_status'));
if (get_post('show_task_type') !== '')
	$where[] = "task_type=" . db_escape(get_post('show_task_type'));

$sql = "SELECT * FROM " . TB_PREF . "bg_tasks";
if ($where)
	$sql .= " WHERE " . implode(' AND ', $where);
$sql .= " ORDER BY id DESC LIMIT 200";
$result = db_query($sql, 'Cannot fetch background tasks');

start_table(TABLESTYLE, "width=98%");
table_header(array(_("ID"), _("Task Type"), _("Status"), _("Attempts"), _("Next Attempt"),
	_("Last Error"), _("Created"), _("Updated"), ''));

$k = 0;
$count = 0;
while ($row = db_fetch($result))
{
	$count++;
	alt_table_row_color($k);
	label_cell($row['id']);
	label_cell(html_specials_encode($row['task_type']));
	label_cell(isset($statuses[$row['status']]) ? $statuses[$row['status']] : html_specials_encode($row['status']));
	label_cell($row['attempts'] . ' / ' . $row['max_attempts']);
	label_cell(html_specials_encode((string)$row['next_attempt_at']));
	label_cell('<span title="' . html_specials_encode((string)$row['last_error']) . '">'
		. html_specials_encode(mb_strimwidth((string)$row['last_error'], 0, 60, '...')) . '</span>');
	label_cell(html_specials_encode((string)$row['created_at']));
	label_cell(html_specials_encode((string)$row['updated_at']));
	if (in_array($row['status'], array('pending', 'failed'), true))
		echo "<td><a href='" . $_SERVER['PHP_SELF'] . "?run_now=" . $row['id'] . "'>" . _("Run now") . "</a></td>";
	else
		label_cell('');
	end_row();
}
end_table(1);

if (!$count)
	display_note(_('No background tasks match these filters.'));

end_form();

display_note(_('A row only appears here once queued by something in the app (e.g. Communications sending a notification). The scheduler is cron/run_scheduled_tasks.php, run periodically outside the web request.'));

end_page();
