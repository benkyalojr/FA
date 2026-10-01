<?php
/**********************************************************************
    Communications - event catalog. Fixed by which hooks exist in the
    codebase, not user-extensible, so this is a plain PHP array (same
    convention as $systypes_array / $security_areas) rather than a DB
    lookup table. Used by the Message Templates and Notification Rules
    screens for dropdowns/labels, and documents which {placeholder}
    tokens each event's templates can use.

    None of these events are wired to a real trigger yet - that is the
    next slice (see the Communications module plan). Seeding templates
    and rules now lets the config layer be fully set up in advance.
***********************************************************************/

function comm_event_catalog()
{
	return array(
		'sales_invoice' => array(
			'label' => _('Sales Invoice Raised'),
			'module' => _('Sales'),
			'description' => _('Sent to a customer when a sales invoice is posted.'),
			'placeholders' => array('customer_name', 'invoice_no', 'amount', 'date'),
		),
		'direct_delivery' => array('label' => _('Direct Delivery'), 'module' => _('Sales'), 'description' => _('Sent to a customer when a direct delivery is posted.'), 'placeholders' => array('customer_name', 'delivery_no', 'amount', 'date')),
		'customer_payment' => array('label' => _('Customer Payment'), 'module' => _('Sales'), 'description' => _('Sent to a customer when a payment is posted.'), 'placeholders' => array('customer_name', 'payment_no', 'amount', 'date')),
		'user_account_credentials' => array('label' => _('User Account Credentials'), 'module' => _('Administration'), 'description' => _('Sent when an administrator generates a new user password.'), 'placeholders' => array('name', 'username', 'temporary_password')),
		'user_password_reset' => array('label' => _('User Password Reset'), 'module' => _('Administration'), 'description' => _('Sent when a user requests a new password.'), 'placeholders' => array('name', 'username', 'temporary_password')),
	);
}

function comm_channel_catalog()
{
	return array(
		'sms' => _('SMS'),
		'email' => _('Email'),
		'whatsapp' => _('WhatsApp'),
	);
}
