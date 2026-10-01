<?php
// Document modal: one overlay for Sales Orders, Delivery Notes, Invoices, Credit
// Notes and Customer Payments, with summary, actions and detail tabs. Rendered as
// an HTML fragment by sales/view/doc_modal.php and injected by ui/workspace.js.

function ma_doc_types()
{
    return array(ST_SALESORDER, ST_SALESQUOTE, ST_CUSTDELIVERY, ST_SALESINVOICE, ST_CUSTCREDIT, ST_CUSTPAYMENT);
}

function ma_doc_upper($v) { return function_exists('mb_strtoupper') ? mb_strtoupper($v) : strtoupper($v); }
function ma_doc_e($v) { return ma_ui_escape($v); }
function ma_doc_money($v) { return price_format($v); }

// <dl> of label/value pairs; values are pre-escaped HTML.
function ma_doc_facts($pairs)
{
    $h = '<dl class="ma-doc-facts">';
    foreach ($pairs as $label => $value)
        if ($value !== null && $value !== '') $h .= '<dt>'.ma_doc_e($label).'</dt><dd>'.$value.'</dd>';
    return $h.'</dl>';
}

function ma_doc_action($label, $href, $icon = '', $class = '', $attrs = '')
{
    return '<a class="ma-doc-btn'.($class ? ' '.$class : '').'" href="'.ma_doc_e(ma_ui_href($href)).'"'.$attrs.'>'
        .($icon ? ma_ui_icon($icon) : '').ma_doc_e($label).'</a>';
}

function ma_doc_table($heads, $rows, $foot = '', $empty = '')
{
    if (!$rows) return '<div class="ma-doc-empty">'.ma_doc_e($empty).'</div>';
    $h = '<div class="ma-doc-card"><table class="ma-sales-table"><thead><tr>';
    foreach ($heads as $head) $h .= '<th'.(!empty($head[1]) ? ' class="num"' : '').'>'.ma_doc_e($head[0]).'</th>';
    $h .= '</tr></thead><tbody>';
    foreach ($rows as $row) {
        $h .= '<tr>';
        foreach ($row as $i => $cell) $h .= '<td'.(!empty($heads[$i][1]) ? ' class="num"' : '').'>'.$cell.'</td>';
        $h .= '</tr>';
    }
    return $h.$foot.'</tbody></table></div>';
}

function ma_doc_created($type, $no)
{
    $r = db_fetch(db_query('SELECT a.stamp, u.real_name FROM '.TB_PREF.'audit_trail a LEFT JOIN '.TB_PREF.'users u ON u.id=a.user
        WHERE a.type='.db_escape($type).' AND a.trans_no='.db_escape($no).' ORDER BY a.id LIMIT 1', 'audit'));
    if (!$r) return array(null, null);
    return array($r['real_name'], $r['stamp'] ? date('m/d/Y h:i A', strtotime($r['stamp'])) : null);
}

function ma_doc_gl_panel($type, $no)
{
    $res = get_gl_trans($type, $no);
    $rows = array(); $dr = $cr = 0;
    $cur = get_company_pref('curr_default');
    while ($g = db_fetch($res)) {
        $amt = (float)$g['amount'];
        $amt > 0 ? $dr += $amt : $cr += -$amt;
        $who = '';
        if ($g['person_id'] !== null && $g['person_id'] !== '' && $g['person_type_id'] == PT_CUSTOMER)
            $who = ma_doc_e(_('Customer').' '.get_customer_name($g['person_id']));
        $rows[] = array(ma_doc_e($g['account'].' - '.$g['account_name']), $who, $amt > 0 ? ma_doc_e($cur.' '.ma_doc_money($amt)) : '',
            $amt < 0 ? ma_doc_e($cur.' '.ma_doc_money(-$amt)) : '', ma_doc_e($g['memo_']));
    }
    $foot = '<tr class="ma-doc-total"><td colspan="2">'.ma_doc_e(_('Total')).'</td><td class="num">'.ma_doc_e($cur.' '.ma_doc_money($dr)).'</td><td class="num">'
        .ma_doc_e($cur.' '.ma_doc_money($cr)).'</td><td></td></tr>';
    $h = ma_doc_table(array(array(_('Account')), array(_('Counterparty')), array(_('Debit'), 1), array(_('Credit'), 1), array(_('Memo'))),
        $rows, $foot, _('No general ledger postings for this document.'));
    if ($rows) $h .= '<span class="ma-doc-balance'.(floatcmp($dr, $cr) ? ' off' : '').'">'
        .ma_doc_e(floatcmp($dr, $cr) ? _('Debits and credits do not balance') : ''._('Debits and credits balance')).'</span>';
    return $h;
}

// Payments allocated to a document (invoice, credit, order) or what a payment settles.
function ma_doc_alloc_panel($type, $no, $debtor_no, $is_payment)
{
    global $systypes_array;
    $res = $is_payment ? get_allocatable_to_cust_transactions($debtor_no, $no, $type) : get_allocatable_from_cust_transactions($debtor_no, $no, $type);
    $rows = array(); $sum = 0;
    while ($res && ($a = db_fetch($res))) {
        $sum += (float)$a['amt'];
        $rows[] = array(ma_doc_e($systypes_array[$a['type']] ?? $a['type']), get_trans_view_str($a['type'], $a['trans_no'], $a['reference']),
            ma_doc_e(sql2date($a['tran_date'])), ma_doc_money($a['Total']), '<b>'.ma_doc_money($a['amt']).'</b>');
    }
    $foot = '<tr class="ma-doc-total"><td colspan="4">'.ma_doc_e(_('Total allocated')).'</td><td class="num">'.ma_doc_money($sum).'</td></tr>';
    return array(ma_doc_table(array(array(_('Type')), array(_('Reference')), array(_('Date')), array(_('Total Amount'), 1), array(_('This Allocation'), 1)),
        $rows, $foot, $is_payment ? _('This payment has not been allocated to any document.') : _('No payments are allocated to this document.')), $sum);
}

function ma_doc_trans_list($type, $where, $empty)
{
    $res = db_query('SELECT t.* FROM '.TB_PREF.'debtor_trans t LEFT JOIN '.TB_PREF.'voided v ON v.type=t.type AND v.id=t.trans_no
        WHERE t.type='.(int)$type.' AND v.id IS NULL AND '.$where.' ORDER BY t.trans_no', 'related documents');
    $rows = array();
    while ($r = db_fetch($res))
        $rows[] = array(get_trans_view_str($r['type'], $r['trans_no']), ma_doc_e($r['reference']), ma_doc_e(sql2date($r['tran_date'])),
            ma_doc_money($r['ov_amount'] + $r['ov_gst'] + $r['ov_freight'] + $r['ov_freight_tax']));
    return ma_doc_table(array(array(_('#')), array(_('Reference')), array(_('Date')), array(_('Total'), 1)), $rows, '', $empty);
}

// Line table for debtor_trans documents. Returns array(html, subtotal, tax, qty).
function ma_doc_trans_lines($type, $no, $tax_included)
{
    $res = get_customer_trans_details($type, $no);
    $rows = array(); $sub = $tax = $qty = 0;
    while ($l = db_fetch($res)) {
        if ((float)$l['quantity'] == 0) continue;
        $price = $l['unit_price']; // already the full price when tax is included
        $value = round2((1 - $l['discount_percent']) * $price * $l['quantity'], user_price_dec());
        $sub += $value; $tax += $l['unit_tax'] * $l['quantity']; $qty += $l['quantity'];
        $rows[] = array('<span class="ma-doc-code">'.ma_doc_e($l['stock_id']).'</span>', ma_doc_e($l['StockDescription']),
            ma_doc_e(number_format((float)$l['quantity'], get_qty_dec($l['stock_id'])).' '.$l['units']), ma_doc_money($price),
            ma_doc_money($l['unit_tax'] * $l['quantity']), $l['discount_percent'] ? ma_doc_e(number_format($l['discount_percent'] * 100, 1).'%') : '-',
            '<b>'.ma_doc_money($value).'</b>');
    }
    $foot = '<tr class="ma-doc-total"><td colspan="2">'.ma_doc_e(_('Total Qty')).'</td><td class="num">'.ma_doc_e(number_format($qty, 2)).'</td><td colspan="4"></td></tr>';
    return array(ma_doc_table(array(array(_('Item Code')), array(_('Item Description')), array(_('Quantity'), 1), array(_('Price'), 1), array(_('Tax'), 1),
        array(_('Discount'), 1), array(_('Total'), 1)), $rows, $foot, _('There are no line items on this document.')), $sub, $tax, $qty);
}

function ma_doc_totals($cur, $rows, $note)
{
    $h = '<div class="ma-doc-totals"><div class="ma-doc-note">'.ma_doc_e($note).'</div><div class="ma-doc-totalbox">';
    foreach ($rows as $i => $r)
        $h .= '<div class="ma-doc-trow'.($i === count($rows) - 1 ? ' grand' : '').'"><span>'.ma_doc_e($r[0]).'</span><strong>'.ma_doc_e($cur.' '.ma_doc_money($r[1])).'</strong></div>';
    return $h.'</div></div>';
}

// Print button: opens the A4 printout (reporting/print_doc.php) and starts printing.
function ma_doc_print($type, $no, $label)
{
    return '<a class="ma-doc-btn" target="_blank" rel="noopener" href="'.ma_doc_e(ma_ui_href('reporting/print_doc.php').'?trans_type='.(int)$type.'&trans_no='.(int)$no.'&autoprint=1').'">'
        .ma_ui_icon('file').ma_doc_e($label).'</a>';
}

function ma_doc_viewer($type)
{
    $map = array(ST_SALESINVOICE => 'view_invoice', ST_CUSTCREDIT => 'view_credit', ST_CUSTPAYMENT => 'view_receipt',
        ST_CUSTDELIVERY => 'view_dispatch', ST_SALESORDER => 'view_sales_order', ST_SALESQUOTE => 'view_sales_order');
    return 'sales/view/'.$map[$type].'.php';
}

function ma_doc_can($area) { return $_SESSION['wa_current_user']->can_access_page($area); }

// Returns array('title'=>, 'html'=>) for a document, or array('error'=>).
function ma_doc_render($type, $no)
{
    global $systypes_array;
    $type = (int)$type; $no = (int)$no;
    if (!in_array($type, ma_doc_types(), true) || $no <= 0) return array('error' => _('Unknown document.'));
    $cur = get_company_pref('curr_default');
    $void = get_voided_entry($type, $no);
    $actions = array(); $tabs = array(); $facts = array(); $status = array('', 'open'); $panels = '';
    $label = $systypes_array[$type] ?? '';
    $parties = ''; $details = ''; $ref = ''; $ribbon = null;

    if ($type === ST_SALESORDER || $type === ST_SALESQUOTE) {
        $o = get_sales_order_header($no, $type);
        if (!$o) return array('error' => _('Document not found.'));
        $ref = $o['reference'];
        $label = $type === ST_SALESQUOTE ? _('Sales Quotation') : _('Sales Order');
        list($by, $on) = ma_doc_created($type, $no);
        $res = get_sales_order_details($no, $type);
        $rows = array(); $sub = 0; $qty = 0; $left = 0;
        while ($l = db_fetch($res)) {
            $value = round2((1 - $l['discount_percent']) * $l['unit_price'] * $l['quantity'], user_price_dec());
            $sub += $value; $qty += $l['quantity']; $left += max(0, $l['quantity'] - $l['qty_done']);
            $rows[] = array('<span class="ma-doc-code">'.ma_doc_e($l['stk_code']).'</span>', ma_doc_e($l['description']),
                ma_doc_e(number_format((float)$l['quantity'], get_qty_dec($l['stk_code'])).' '.$l['units']),
                ma_doc_e(number_format((float)$l['qty_done'], get_qty_dec($l['stk_code']))), ma_doc_money($l['unit_price']),
                $l['discount_percent'] ? ma_doc_e(number_format($l['discount_percent'] * 100, 1).'%') : '-', '<b>'.ma_doc_money($value).'</b>');
        }
        $freight = (float)$o['freight_cost'];
        $total = $sub + $freight;
        $status = $void ? array(_('Voided'), 'late') : ($type === ST_SALESQUOTE ? array(_('Quotation'), 'open')
            : ($left <= 0 && $rows ? array(_('Delivered'), 'paid') : array(_('Open'), 'pending')));
        $facts = array($label => ma_doc_e($no), _('Reference') => ma_doc_e($ref), _('Order Date') => ma_doc_e(sql2date($o['ord_date'])),
            _('Requested Delivery') => ma_doc_e(sql2date($o['delivery_date'])), _('Created By') => ma_doc_e($by), _('Created On') => ma_doc_e($on));
        $details = array(_('Sales Type') => ma_doc_e($o['sales_type']), _('Customer Order Ref.') => ma_doc_e($o['customer_ref']),
            _('Deliver From') => ma_doc_e($o['location_name']), _('Shipping Company') => ma_doc_e($o['shipper_name']),
            _('Customer currency') => ma_doc_e($o['curr_code']), _('Phone') => ma_doc_e($o['contact_phone']));
        $parties = '<section><h2>'.ma_doc_e(_('Customer')).'</h2><p>'.ma_doc_e($o['name']).'</p><p>'.nl2br(ma_doc_e($o['address'])).'</p></section><section><h2>'
            .ma_doc_e(_('Delivered To')).'</h2><p>'.ma_doc_e($o['deliver_to']).'</p><p>'.nl2br(ma_doc_e($o['delivery_address'])).'</p></section>';
        $foot = '<tr class="ma-doc-total"><td colspan="2">'.ma_doc_e(_('Total Qty')).'</td><td class="num">'.ma_doc_e(number_format($qty, 2)).'</td><td colspan="4"></td></tr>';
        $tabs[] = array(_('Line Details'), ma_doc_table(array(array(_('Item Code')), array(_('Item Description')), array(_('Quantity'), 1), array(_('Delivered'), 1),
            array(_('Price'), 1), array(_('Discount'), 1), array(_('Total'), 1)), $rows, $foot, _('There are no line items on this order.'))
            .ma_doc_totals($cur, array_merge(array(array(_('Subtotal'), $sub)), $freight ? array(array(_('Shipping'), $freight)) : array(), array(array(_('Total'), $total))),
                $o['tax_included'] ? _('Tax is included in the displayed price.') : ''));
        if ($type === ST_SALESORDER) {
            $tabs[] = array(_('Delivery Notes'), ma_doc_trans_list(ST_CUSTDELIVERY, 't.order_='.$no, _('No delivery notes have been raised for this order.')));
            $tabs[] = array(_('Sales Invoice'), ma_doc_trans_list(ST_SALESINVOICE, 't.order_='.$no, _('No invoices have been raised for this order.')));
            list($alloc_html) = ma_doc_alloc_panel($type, $no, $o['debtor_no'], false);
            $tabs[] = array(_('Pre-Payments'), $alloc_html);
        }
        if (!$void) {
            if ($type === ST_SALESORDER && $left > 0 && ma_doc_can('SA_SALESDELIVERY'))
                $actions[] = ma_doc_action(_('Dispatch'), 'sales/customer_delivery.php?OrderNumber='.$no, 'truck', 'primary');
            if ($type === ST_SALESORDER && ma_doc_can('SA_SALESINVOICE'))
                $actions[] = ma_doc_action(_('Invoice'), 'sales/sales_order_entry.php?NewInvoice='.$no, 'receipt', $left > 0 ? '' : 'primary');
            if ($type === ST_SALESQUOTE && ma_doc_can('SA_SALESORDER'))
                $actions[] = ma_doc_action(_('Make Sales Order'), 'sales/sales_order_entry.php?NewQuoteToSalesOrder='.$no, 'cart', 'primary');
            $actions[] = ma_doc_print($type, $no, sprintf(_('Print %s'), $label));
            if (ma_doc_can($type === ST_SALESQUOTE ? 'SA_SALESQUOTE' : 'SA_SALESORDER'))
                $actions[] = ma_doc_action(_('Edit'), 'sales/sales_order_entry.php?'.($type === ST_SALESQUOTE ? 'ModifyQuotationNumber=' : 'ModifyOrderNumber=').$no, 'file');
        }
        $debtor = $o['debtor_no'];
    } else {
        $t = get_customer_trans($no, $type);
        if (!$t) return array('error' => _('Document not found.'));
        $ref = $t['reference'];
        $debtor = $t['debtor_no'];
        $total = $t['Total'];
        list($by, $on) = ma_doc_created($type, $no);
        $order = $t['order_'] ? get_sales_order_header($t['order_'], ST_SALESORDER) : null;

        if ($type === ST_CUSTPAYMENT) {
            $label = _('Customer Payment');
            list($alloc_html, $sum) = ma_doc_alloc_panel($type, $no, $debtor, true);
            $left = round2($total - $sum, user_price_dec());
            $status = $void ? array(_('Voided'), 'late') : ($left <= 0 ? array(_('Allocated'), 'paid') : ($sum > 0 ? array(_('Partly allocated'), 'pending') : array(_('Unallocated'), 'pending')));
            global $bank_transfer_types;
            $facts = array($label => ma_doc_e($no), _('Reference') => ma_doc_e($ref), _('Date of Deposit') => ma_doc_e(sql2date($t['tran_date'])),
                _('Amount') => ma_doc_e($t['curr_code'].' '.ma_doc_money($total - $t['ov_discount'])), _('Discount') => $t['ov_discount'] ? ma_doc_e(ma_doc_money($t['ov_discount'])) : '',
                _('Left to allocate') => ma_doc_e(ma_doc_money($left)), _('Created By') => ma_doc_e($by), _('Created On') => ma_doc_e($on));
            $details = array(_('Into Bank Account') => ma_doc_e($t['bank_account_name'].' ['.$t['bank_curr_code'].']'), _('Bank Amount') => ma_doc_e(ma_doc_money($t['bank_amount'])),
                _('Payment Type') => ma_doc_e($bank_transfer_types[$t['BankTransType']] ?? ''), _('Customer currency') => ma_doc_e($t['curr_code']),
                _('Memo') => ma_doc_e($t['memo_']));
            $parties = '<section><h2>'.ma_doc_e(_('Received From')).'</h2><p>'.ma_doc_e($t['DebtorName']).'</p><p>'.nl2br(ma_doc_e($t['address'])).'</p></section>';
            $tabs[] = array(_('Allocations'), $alloc_html);
            $tabs[] = array(_('General Ledger'), ma_doc_gl_panel($type, $no));
            if (!$void) {
                if ($left > 0 && ma_doc_can('SA_SALESALLOC'))
                    $actions[] = ma_doc_action(_('Allocate'), 'sales/allocations/customer_allocate.php?trans_no='.$no.'&trans_type='.$type.'&debtor_no='.$debtor, 'swap', 'primary');
                if (ma_doc_can('SA_SALESPAYMNT'))
                    $actions[] = ma_doc_action(_('Edit'), 'sales/customer_payments.php?trans_no='.$no, 'file');
                $actions[] = ma_doc_print($type, $no, _('Print Receipt'));
            }
        } else {
            $taxinc = !empty($t['tax_included']);
            $paid = (float)$t['alloc'];
            $tot = $t['ov_amount'] + $t['ov_gst'] + $t['ov_freight'] + $t['ov_freight_tax'];
            if ($type === ST_SALESINVOICE) {
                $label = _('Sales Invoice');
                $status = $void ? array(_('Voided'), 'late') : (floatcmp($paid, $tot) >= 0 ? array(_('Paid'), 'paid') : ($paid > 0 ? array(_('Partly paid'), 'pending') : array(_('Unpaid'), 'late')));
                $ribbon = array($status[0], $void ? 'void' : (floatcmp($paid, $tot) >= 0 ? 'paid' : ($paid > 0 ? 'part' : 'unpaid')));
            } elseif ($type === ST_CUSTCREDIT) {
                $label = _('Credit Note');
                $status = $void ? array(_('Voided'), 'late') : array(_('Credit'), 'open');
            } else {
                $label = _('Delivery Note');
                $inv = get_sales_child_numbers(ST_CUSTDELIVERY, $no);
                $status = $void ? array(_('Voided'), 'late') : ($inv ? array(_('Invoiced'), 'paid') : array(_('Not invoiced'), 'pending'));
            }
            $branch = get_branch($t['branch_code']);
            $terms = $type === ST_SALESINVOICE ? get_payment_terms($t['payment_terms']) : null;
            $facts = array($label => ma_doc_e($no), _('Reference') => ma_doc_e($ref), _('Date') => ma_doc_e(sql2date($t['tran_date'])),
                _('Due Date') => $type === ST_CUSTDELIVERY ? '' : ma_doc_e(sql2date($t['due_date'])), _('Created By') => ma_doc_e($by), _('Created On') => ma_doc_e($on),
                _('Branch') => ma_doc_e($branch['br_name'] ?? ''));
            $details = array(_('Sales Type') => ma_doc_e($t['sales_type']), _('Tax') => $taxinc ? _('Included') : _('Excluded'),
                _('Our Order') => $order ? get_trans_view_str(ST_SALESORDER, $t['order_']) : '', _('Customer Order Ref.') => ma_doc_e($order['customer_ref'] ?? ''),
                _('Delivery from') => ma_doc_e($order['location_name'] ?? ''), _('Payment Terms') => ma_doc_e($terms['terms'] ?? ''),
                _('Shipping Company') => ma_doc_e($t['shipper_name']), _('Customer currency') => ma_doc_e($t['curr_code']), _('Phone') => ma_doc_e($order['contact_phone'] ?? ''));
            $parties = '<section><h2>'.ma_doc_e(_('Billed To')).'</h2><p>'.ma_doc_e($t['DebtorName']).'</p><p>'.nl2br(ma_doc_e($t['address'])).'</p></section><section><h2>'
                .ma_doc_e(_('Delivered To')).'</h2><p>'.ma_doc_e($order['deliver_to'] ?? $t['DebtorName']).'</p><p>'.nl2br(ma_doc_e($order['delivery_address'] ?? ($branch['br_address'] ?? ''))).'</p></section>';
            list($lines_html, $sub, $tax) = ma_doc_trans_lines($type, $no, $taxinc);
            $trows = array(array(_('Subtotal'), $taxinc ? $sub : $t['ov_amount']));
            if ($t['ov_freight']) $trows[] = array(_('Shipping'), $t['ov_freight']);
            $trows[] = array($taxinc ? _('Included tax') : _('Tax'), $taxinc ? $tax : $t['ov_gst'] + $t['ov_freight_tax']);
            $trows[] = array(_('Total'), $tot);
            $tabs[] = array(_('Line Details'), $lines_html.ma_doc_totals($cur, $trows, $taxinc ? _('Tax is included in the displayed price.') : ''));
            if ($type === ST_SALESINVOICE) {
                $tabs[] = array(_('Delivery Notes'), ma_doc_trans_list(ST_CUSTDELIVERY, 't.trans_no IN ('.implode(',', array_map('intval', get_sales_parent_numbers(ST_SALESINVOICE, $no)) ?: array(0)).')',
                    _('This invoice was not raised from a delivery note.')));
            } elseif ($type === ST_CUSTDELIVERY) {
                $tabs[] = array(_('Invoices'), ma_doc_trans_list(ST_SALESINVOICE, 't.trans_no IN ('.implode(',', array_map('intval', $inv) ?: array(0)).')', _('This delivery has not been invoiced.')));
            }
            $tabs[] = array(_('General Ledger'), ma_doc_gl_panel($type, $no));
            if ($type !== ST_CUSTDELIVERY) {
                list($alloc_html) = ma_doc_alloc_panel($type, $no, $debtor, false);
                $tabs[] = array(_('Allocations'), $alloc_html);
            }
            if (!$void) {
                if ($type === ST_SALESINVOICE) {
                    if (floatcmp($paid, $tot) < 0 && ma_doc_can('SA_SALESPAYMNT'))
                        $actions[] = ma_doc_action(_('Payment'), 'sales/customer_payments.php?SInvoice='.$no.'&Type='.$type.'&customer_id='.$debtor, 'wallet', 'primary');
                    if (ma_doc_can('SA_SALESINVOICE'))
                        $actions[] = ma_doc_action(_('Edit'), 'sales/customer_invoice.php?ModifyInvoice='.$no, 'file');
                    if (ma_doc_can('SA_SALESCREDITINV'))
                        $actions[] = ma_doc_action(_('Credit This'), 'sales/customer_credit_invoice.php?InvoiceNumber='.$no, 'undo');
                    if ($t['order_'] && ma_doc_can('SA_SALESORDER'))
                        $actions[] = ma_doc_action(_('Repeat'), 'sales/sales_order_entry.php?NewInvoice='.$t['order_'], 'swap');
                } elseif ($type === ST_CUSTDELIVERY) {
                    if (!$inv && ma_doc_can('SA_SALESINVOICE'))
                        $actions[] = ma_doc_action(_('Invoice'), 'sales/customer_invoice.php?DeliveryNumber='.$no, 'receipt', 'primary');
                    if (ma_doc_can('SA_SALESDELIVERY'))
                        $actions[] = ma_doc_action(_('Edit'), 'sales/customer_delivery.php?ModifyDelivery='.$no, 'file');
                } elseif ($t['order_'] == 0 && ma_doc_can('SA_SALESCREDIT')) {
                    $actions[] = ma_doc_action(_('Edit'), 'sales/credit_note_entry.php?ModifyCredit='.$no, 'file');
                }
                $actions[] = ma_doc_print($type, $no, sprintf(_('Print %s'), $label));
            }
        }
    }
    if (!$void && ma_doc_can('SA_VOIDTRANSACTION') && !is_closed_trans($type, $no))
        $actions[] = ma_doc_action(_('Void'), 'admin/void_transaction.php?filterType='.$type.'&trans_no='.$no, '', 'danger');
    if ($type === ST_SALESINVOICE && !$void)
        $actions[] = '<button type="button" class="ma-doc-btn" data-doc-share>'.ma_ui_icon('message').ma_doc_e(_('Share Link')).'</button>';
    else
        $actions[] = '<button type="button" class="ma-doc-btn" data-doc-copy="'.ma_doc_e(ma_ui_href(ma_doc_viewer($type)).'?trans_no='.$no.'&trans_type='.$type).'">'.ma_ui_icon('file').ma_doc_e(_('Copy Link')).'</button>';

    $h = '<article class="ma-doc" data-doc-type="'.$type.'" data-doc-no="'.$no.'" data-doc-token="'.ma_doc_e($_SESSION['csrf_token'] ?? '').'">'
        .($ribbon ? '<div class="ma-doc-ribbon '.$ribbon[1].'" role="status">'.ma_doc_e(ma_doc_upper($ribbon[0])).'</div>' : '').'<div class="ma-doc-head"><div><span class="ma-doc-eyebrow">'.ma_doc_e(ma_doc_upper($label)).'</span><h1>'
        .ma_doc_e($label.' '.($ref !== '' ? $ref : '#'.$no)).'</h1><span class="ma-pill '.$status[1].'">'.ma_doc_e(ma_doc_upper($status[0])).'</span></div></div>';
    if ($void) $h .= '<div class="ma-doc-void">'.ma_doc_e(sprintf(_('Voided on %s'), sql2date($void['date_']))).($void['memo_'] !== '' ? ' - '.ma_doc_e($void['memo_']) : '').'</div>';
    $h .= '<div class="ma-doc-summary">'.ma_doc_facts($facts).'<div class="ma-doc-actions">'.implode('', $actions).'</div></div><div class="ma-doc-share" hidden></div>';
    $h .= '<div class="ma-doc-details"><section><h2>'.ma_doc_e(_('Document Details')).'</h2>'.ma_doc_facts($details).'</section>'.$parties.'</div>';
    $h .= '<nav class="ma-doc-tabs" role="tablist">';
    foreach ($tabs as $i => $tab)
        $h .= '<button type="button" role="tab" class="ma-doc-tab'.($i ? '' : ' active').'" data-doc-tab="'.$i.'" aria-selected="'.($i ? 'false' : 'true').'">'.ma_doc_e($tab[0]).'</button>';
    $h .= '</nav>';
    foreach ($tabs as $i => $tab) $h .= '<section class="ma-doc-panel" data-doc-panel="'.$i.'"'.($i ? ' hidden' : '').'>'.$tab[1].'</section>';
    return array('title' => $label.' # '.($ref !== '' ? $ref : $no), 'html' => $h.'</article>');
}
