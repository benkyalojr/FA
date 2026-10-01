<?php
/**********************************************************************
    Communications - manual messages. "New SMS" / "New Email" write to one
    customer or supplier (or to a number/address typed in) and send right
    away, so the person composing sees the outcome. The attempt is written to
    the delivery log like any other message (event "manual"). Recipients who
    opted out of the channel are respected.
***********************************************************************/
include_once(dirname(__FILE__) . '/db/comm_send_db.inc');

// Customers and suppliers with the first phone / email found on their contacts
// (the customer itself and its branches; the supplier's contacts).
function comm_compose_parties()
{
	$parties = array('customer' => array(), 'supplier' => array());
	$res = db_query("SELECT debtor_no AS id, name FROM " . TB_PREF . "debtors_master WHERE !inactive ORDER BY name", 'customers');
	while ($r = db_fetch($res))
		$parties['customer'][$r['id']] = array('name' => $r['name'], 'phone' => '', 'email' => '');
	$res = db_query("SELECT supplier_id AS id, supp_name AS name FROM " . TB_PREF . "suppliers WHERE !inactive ORDER BY supp_name", 'suppliers');
	while ($r = db_fetch($res))
		$parties['supplier'][$r['id']] = array('name' => $r['name'], 'phone' => '', 'email' => '');

	$res = db_query("SELECT 'customer' AS kind, c.entity_id AS id, p.phone, p.phone2, p.email FROM " . TB_PREF . "crm_contacts c
			JOIN " . TB_PREF . "crm_persons p ON p.id=c.person_id AND !p.inactive WHERE c.type='customer'
		UNION ALL SELECT 'customer', b.debtor_no, p.phone, p.phone2, p.email FROM " . TB_PREF . "crm_contacts c
			JOIN " . TB_PREF . "crm_persons p ON p.id=c.person_id AND !p.inactive
			JOIN " . TB_PREF . "cust_branch b ON b.branch_code=c.entity_id WHERE c.type='cust_branch'
		UNION ALL SELECT 'supplier', c.entity_id, p.phone, p.phone2, p.email FROM " . TB_PREF . "crm_contacts c
			JOIN " . TB_PREF . "crm_persons p ON p.id=c.person_id AND !p.inactive WHERE c.type='supplier'", 'party contacts');
	while ($r = db_fetch($res)) {
		if (!isset($parties[$r['kind']][$r['id']])) continue;
		$p =& $parties[$r['kind']][$r['id']];
		$phone = trim($r['phone']) !== '' ? $r['phone'] : $r['phone2'];
		if ($p['phone'] === '' && trim((string)$phone) !== '') $p['phone'] = trim($phone);
		if ($p['email'] === '' && trim((string)$r['email']) !== '') $p['email'] = trim($r['email']);
		unset($p);
	}
	return $parties;
}

// Validates and sends the posted message. Returns array(ok, message).
function comm_compose_send($channel)
{
	if (!in_array($channel, array('sms', 'email'), true))
		return array(false, _('Unknown channel.'));
	if (!$_SESSION['wa_current_user']->can_access_page('SA_COMMSEND'))
		return array(false, _('You do not have permission to send messages.'));
	if (!get_company_pref('use_communications_' . $channel))
		return array(false, $channel === 'sms' ? _('SMS is switched off. Turn it on under Communications Module Setup.') : _('Email is switched off. Turn it on under Communications Module Setup.'));

	$party = (string)($_POST['comp_party'] ?? '');
	$to = trim((string)($_POST['comp_to'] ?? ''));
	$subject = trim((string)($_POST['comp_subject'] ?? ''));
	$body = trim((string)($_POST['comp_body'] ?? ''));
	$type = $id = $name = null;

	if ($party !== '') {
		list($type, $id) = array_pad(explode(':', $party, 2), 2, null);
		$parties = comm_compose_parties();
		if (!isset($parties[$type][(int)$id]))
			return array(false, _('Choose a customer or supplier from the list.'));
		$id = (int)$id;
		$name = $parties[$type][$id]['name'];
		if ($to === '')
			$to = $channel === 'sms' ? $parties[$type][$id]['phone'] : $parties[$type][$id]['email'];
	}
	if ($to === '')
		return array(false, $party !== '' ? sprintf(_('%s has no %s on file. Type one in the To box.'), $name, $channel === 'sms' ? _('phone number') : _('email address'))
			: _('Choose who to write to, or type a number or address in the To box.'));
	if ($channel === 'sms' && strlen(preg_replace('/\D/', '', $to)) < 9)
		return array(false, _('Enter a valid phone number.'));
	if ($channel === 'email' && !filter_var($to, FILTER_VALIDATE_EMAIL))
		return array(false, _('Enter a valid email address.'));
	if ($channel === 'email' && $subject === '')
		return array(false, _('Enter a subject for the email.'));
	if ($body === '')
		return array(false, _('Write a message first.'));
	if ($type !== null && !comm_recipient_allowed($type, $id, $channel)) {
		comm_log_write('manual', $channel, $type, $id, $to, $channel === 'email' ? $subject : null, $body, 'skipped_optout', null, null, $name);
		return array(false, sprintf(_('%s has opted out of this channel, so nothing was sent.'), $name));
	}

	$send = comm_dispatch($channel, $to, $channel === 'email' ? $subject : null, $body);
	comm_log_write('manual', $channel, $type, $id, $to, $channel === 'email' ? $subject : null, $body, $send['ok'] ? 'sent' : 'failed', $send['response'], null, $name);
	if ($send['ok'])
		return array(true, sprintf(_('Message sent to %s.'), $name !== null ? $name . ' (' . $to . ')' : $to));
	return array(false, sprintf(_('The message could not be sent: %s'), $send['response']));
}

// The compose form fields (rendered inside a modal). Echoes HTML.
function comm_compose_form($channel)
{
	$e = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
	$parties = comm_compose_parties();
	$sel = (string)($_POST['comp_party'] ?? '');
	ensure_select2_assets();
	echo '<input type="hidden" name="compose_channel" value="' . $e($channel) . '">';
	echo '<div class="ma-comp"><label>' . $e(_('Write to')) . '<select name="comp_party" id="comp-party" class="fa-select2" data-select2-local="1">'
		. '<option value="">' . $e(_('Choose a customer or supplier...')) . '</option>';
	foreach (array('customer' => _('Customers'), 'supplier' => _('Suppliers')) as $kind => $label) {
		echo '<optgroup label="' . $e($label) . '">';
		foreach ($parties[$kind] as $id => $p) {
			$key = $kind . ':' . $id;
			$contact = $channel === 'sms' ? $p['phone'] : $p['email'];
			echo '<option value="' . $e($key) . '" data-contact="' . $e($contact) . '"' . ($key === $sel ? ' selected' : '') . '>'
				. $e($p['name'] . ($contact !== '' ? '  (' . $contact . ')' : '  (' . ($channel === 'sms' ? _('no phone') : _('no email')) . ')')) . '</option>';
		}
		echo '</optgroup>';
	}
	echo '</select></label>';
	echo '<label>' . $e($channel === 'sms' ? _('To (phone number)') : _('To (email address)'))
		. '<input type="text" name="comp_to" id="comp-to" value="' . $e($_POST['comp_to'] ?? '') . '" placeholder="' . $e(_('Filled from the contact on file; type to override')) . '"></label>';
	if ($channel === 'email')
		echo '<label>' . $e(_('Subject')) . '<input type="text" name="comp_subject" maxlength="255" value="' . $e($_POST['comp_subject'] ?? '') . '"></label>';
	echo '<label>' . $e(_('Message')) . '<textarea name="comp_body" id="comp-body" rows="' . ($channel === 'sms' ? 4 : 8) . '" maxlength="2000">' . $e($_POST['comp_body'] ?? '') . '</textarea></label>';
	if ($channel === 'sms')
		echo '<p class="ma-cfg-hint" id="comp-count">0 ' . $e(_('characters')) . '</p>';
	echo '<div class="ma-cfg-actions"><button class="ma-btn ma-btn-primary" type="submit" name="send_message" value="1">'
		. $e($channel === 'sms' ? _('Send SMS') : _('Send Email')) . '</button></div></div>';
	echo '<script>(function(){var s=document.getElementById("comp-party"),t=document.getElementById("comp-to"),b=document.getElementById("comp-body"),c=document.getElementById("comp-count");'
		. 'function fill(){var o=s.options[s.selectedIndex];if(o&&o.getAttribute("data-contact")!==null&&!t.dataset.touched){t.value=o.getAttribute("data-contact")||"";}}'
		. 't.addEventListener("input",function(){t.dataset.touched="1"});'
		. 'if(window.jQuery)jQuery(s).on("change",function(){delete t.dataset.touched;fill()});s.addEventListener("change",function(){delete t.dataset.touched;fill()});'
		. 'if(c){var n=function(){var l=b.value.length;c.textContent=l+" ' . addslashes(_('characters')) . ' / "+Math.max(1,Math.ceil(l/160))+" SMS"};b.addEventListener("input",n);n();}})();</script>';
}

// ---------------------------------------------------------------- broadcast
// One message to every customer or every supplier that has a phone number /
// email address on file. Messages are queued and sent by the scheduler (like the
// automatic notifications), so a long list never holds up the page.

function comm_broadcast_audiences()
{
	return array('customer' => _('Customers'), 'supplier' => _('Suppliers'));
}

// Recipients of an audience for a channel: array of array(id, name, address), one per distinct address.
function comm_broadcast_recipients($audience, $channel)
{
	$parties = comm_compose_parties();
	$out = array(); $seen = array();
	foreach ($parties[$audience] ?? array() as $id => $p) {
		$addr = $channel === 'sms' ? $p['phone'] : $p['email'];
		if (trim((string)$addr) === '') continue;
		if ($channel === 'sms' && strlen(preg_replace('/\D/', '', $addr)) < 9) continue;
		if ($channel === 'email' && !filter_var($addr, FILTER_VALIDATE_EMAIL)) continue;
		$key = strtolower(trim($addr));
		if (isset($seen[$key])) continue;
		$seen[$key] = true;
		$out[] = array((int)$id, $p['name'], trim($addr));
	}
	return $out;
}

// Validates and queues the posted broadcast. Returns array(ok, message).
function comm_broadcast_send()
{
	$channel = (string)($_POST['bc_channel'] ?? '');
	$audience = (string)($_POST['bc_audience'] ?? '');
	$subject = trim((string)($_POST['bc_subject'] ?? ''));
	$body = trim((string)($_POST['bc_body'] ?? ''));
	if (!in_array($channel, array('sms', 'email'), true))
		return array(false, _('Choose SMS or Email.'));
	if (!isset(comm_broadcast_audiences()[$audience]))
		return array(false, _('Choose who to send to: customers or suppliers.'));
	if (!$_SESSION['wa_current_user']->can_access_page('SA_COMMSEND'))
		return array(false, _('You do not have permission to send messages.'));
	if (!get_company_pref('use_communications_' . $channel))
		return array(false, $channel === 'sms' ? _('SMS is switched off. Turn it on under Communications Module Setup.') : _('Email is switched off. Turn it on under Communications Module Setup.'));
	if ($channel === 'email' && $subject === '')
		return array(false, _('Enter a subject for the email.'));
	if ($body === '')
		return array(false, _('Write a message first.'));
	if (empty($_POST['bc_confirm']))
		return array(false, _('Tick the box to confirm you want to send this to everyone in the list.'));

	$recipients = comm_broadcast_recipients($audience, $channel);
	if (!$recipients)
		return array(false, $channel === 'sms' ? _('None of them has a phone number on file.') : _('None of them has an email address on file.'));
	if (count($recipients) > 2000)
		return array(false, _('That is more than 2000 recipients. Send to fewer at a time.'));

	$queued = $skipped = 0;
	foreach ($recipients as $r) {
		list($id, $name, $addr) = $r;
		if (!comm_recipient_allowed($audience, $id, $channel)) {
			comm_log_write('broadcast', $channel, $audience, $id, $addr, $channel === 'email' ? $subject : null, $body, 'skipped_optout', null, null, $name);
			$skipped++;
			continue;
		}
		$text = str_replace('{name}', $name, $body);
		$subj = $channel === 'email' ? str_replace('{name}', $name, $subject) : null;
		comm_log_write('broadcast', $channel, $audience, $id, $addr, $subj, $text, 'queued', null, null, $name);
		bg_task_enqueue('comm_send', array('comm_log_id' => db_insert_id(), 'channel' => $channel, 'to' => $addr, 'subject' => $subj, 'body' => $text));
		$queued++;
	}
	return array(true, sprintf(_('%d message(s) queued for %s'), $queued, comm_broadcast_audiences()[$audience])
		. ($skipped ? sprintf(_(' (%d skipped: opted out)'), $skipped) : '') . _('. They are sent in the background; follow the progress in this log.'));
}

function comm_broadcast_form($default_channel)
{
	$e = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
	$channel = in_array($_POST['bc_channel'] ?? $default_channel, array('sms', 'email'), true) ? ($_POST['bc_channel'] ?? $default_channel) : 'sms';
	$audience = (string)($_POST['bc_audience'] ?? 'customer');
	$counts = array();
	foreach (array_keys(comm_broadcast_audiences()) as $a)
		foreach (array('sms', 'email') as $c) $counts[$a][$c] = count(comm_broadcast_recipients($a, $c));
	echo '<input type="hidden" name="compose_channel" value="broadcast"><div class="ma-comp">';
	echo '<label>' . $e(_('Channel')) . '<select name="bc_channel" id="bc-channel"><option value="sms"' . ($channel === 'sms' ? ' selected' : '') . '>' . $e(_('SMS')) . '</option>'
		. '<option value="email"' . ($channel === 'email' ? ' selected' : '') . '>' . $e(_('Email')) . '</option></select></label>';
	echo '<fieldset class="ma-bc-audience"><legend>' . $e(_('Send to')) . '</legend>';
	foreach (comm_broadcast_audiences() as $a => $label)
		echo '<label class="ma-bc-choice"><input type="radio" name="bc_audience" value="' . $e($a) . '"' . ($a === $audience ? ' checked' : '') . '><span><strong>' . $e($label) . '</strong>'
			. '<small class="bc-count" data-audience="' . $e($a) . '" data-sms="' . (int)$counts[$a]['sms'] . '" data-email="' . (int)$counts[$a]['email'] . '"></small></span></label>';
	echo '</fieldset>';
	echo '<label id="bc-subject-row">' . $e(_('Subject')) . '<input type="text" name="bc_subject" maxlength="255" value="' . $e($_POST['bc_subject'] ?? '') . '"></label>';
	echo '<label>' . $e(_('Message')) . '<textarea name="bc_body" id="bc-body" rows="6" maxlength="2000">' . $e($_POST['bc_body'] ?? '') . '</textarea></label>';
	echo '<p class="ma-cfg-hint">' . $e(_('Use {name} to put each person\'s name in the message. Anyone who opted out of the channel is skipped. Messages are queued and sent in the background by the scheduler.')) . '</p>';
	echo '<label class="ma-bc-confirm"><input type="checkbox" name="bc_confirm" value="1"><span id="bc-confirm-text"></span></label>';
	echo '<div class="ma-cfg-actions"><button class="ma-btn ma-btn-primary" type="submit" name="send_message" value="1">' . $e(_('Send broadcast')) . '</button></div></div>';
	echo '<script>(function(){var ch=document.getElementById("bc-channel"),sub=document.getElementById("bc-subject-row"),ct=document.getElementById("bc-confirm-text");'
		. 'function upd(){var c=ch.value,a=document.querySelector("input[name=bc_audience]:checked"),n=0;sub.style.display=c==="email"?"":"none";'
		. 'document.querySelectorAll(".bc-count").forEach(function(s){var k=parseInt(s.getAttribute("data-"+c),10);s.textContent=k+" "+(c==="sms"?"' . addslashes(_('with a phone number')) . '":"' . addslashes(_('with an email address')) . '");if(a&&s.getAttribute("data-audience")===a.value)n=k;});'
		. 'ct.textContent="' . addslashes(_('Yes, send this to')) . ' "+n+" ' . addslashes(_('recipients')) . '";}'
		. 'ch.addEventListener("change",upd);document.querySelectorAll("input[name=bc_audience]").forEach(function(r){r.addEventListener("change",upd)});upd();})();</script>';
}
