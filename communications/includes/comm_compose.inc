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
