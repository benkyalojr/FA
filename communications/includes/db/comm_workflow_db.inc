<?php
// Shared recipient helpers for workflow notifications. Each helper is a
// no-op unless Communications is enabled and the recipient has a contact.
function comm_workflow_supplier($event, $supplier_id, array $placeholders, $related = null)
{
	if (!get_company_pref('use_communications')) return;
	$supplier = db_fetch(db_query('SELECT supplier_id,supp_name FROM '.TB_PREF.'suppliers WHERE supplier_id='.(int)$supplier_id));
	if (!$supplier) return;
	$contacts = get_supplier_contacts((int)$supplier_id);
	$phone = $email = null;
	foreach ($contacts as $contact) {
		if (!$phone && !empty($contact['phone'])) $phone = $contact['phone'];
		if (!$email && !empty($contact['email'])) $email = $contact['email'];
	}
	if (!$phone && !$email) return;
	$placeholders['supplier_name'] = $placeholders['supplier_name'] ?? $supplier['supp_name'];
	comm_send_notification($event, array('type'=>'supplier','id'=>(int)$supplier_id,'name'=>$supplier['supp_name'],'phone'=>$phone,'email'=>$email), $placeholders, $related);
}

function comm_workflow_customer($event, $customer_id, $branch_code, array $placeholders, $related = null)
{
	if (!get_company_pref('use_communications')) return;
	$customer = get_customer((int)$customer_id);
	if (!$customer) return;
	// Fall back to the normal customer contact when an invoice-specific
	// contact is not configured.
	$contacts = get_branch_contacts($branch_code, 'invoice', (int)$customer_id, true);
	$phone = $email = null;
	foreach ($contacts as $contact) {
		if (!$phone && !empty($contact['phone'])) $phone = $contact['phone'];
		if (!$email && !empty($contact['email'])) $email = $contact['email'];
	}
	if (!$phone && !$email) return;
	$placeholders['customer_name'] = $placeholders['customer_name'] ?? $customer['name'];
	comm_send_notification($event, array('type'=>'customer','id'=>(int)$customer_id,'name'=>$customer['name'],'phone'=>$phone,'email'=>$email), $placeholders, $related);
}
