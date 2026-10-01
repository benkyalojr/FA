<?php
/**********************************************************************
    Communications - sales invoice notification hook.
***********************************************************************/

function comm_notify_sales_invoice($invoice_no, $invoice)
{
	if (!get_company_pref('use_communications')) return;

	$customer_id = (int)$invoice->customer_id;
	$branch_code = $invoice->Branch;
	// Include the customer's general contact when no invoice-specific
	// contact exists. Most existing customer records store their phone under
	// the general contact action.
	$contacts = get_branch_contacts($branch_code, 'invoice', $customer_id, true);
	$phone = null;
	$email = null;
	foreach ($contacts as $contact) {
		if (!$phone && !empty($contact['phone'])) $phone = $contact['phone'];
		if (!$email && !empty($contact['email'])) $email = $contact['email'];
		if ($phone && $email) break;
	}
	if (!$phone && !$email) return;

	$customer = get_customer($customer_id);
	if (!$customer) return;

	comm_send_notification('sales_invoice', array(
		'type' => 'customer',
		'id' => $customer_id,
		'name' => $customer['name'],
		'phone' => $phone,
		'email' => $email,
	), array(
		'customer_name' => $customer['name'],
		'invoice_no' => $invoice_no,
		'amount' => number_format((float)$invoice->get_trans_total(), 2),
		'date' => $invoice->document_date,
	), array('trans_type' => ST_SALESINVOICE, 'trans_no' => $invoice_no));
}
