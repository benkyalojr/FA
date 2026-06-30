<?php
/** AVO'Gs REST API front controller. */
require __DIR__ . '/bootstrap.php';

$req = new Request();
$router = new Router();

if (!empty($AVOGS_CFG['log_requests'])) {
    Logger::info('Request', array('method' => $req->method, 'path' => $req->path));
}

// Health / root
$router->get('/', function ($req) {
    Response::json(array('name' => "AVO'Gs API", 'status' => 'ok', 'version' => 1));
});

// Auth
$router->post('/auth/login', array('AuthController', 'login'));
$router->post('/auth/logout', array('AuthController', 'logout'));

// Reference data
$router->get('/stores', array('ReferenceController', 'stores'));
$router->get('/customers', array('ReferenceController', 'customers'));
$router->get('/catalog', array('ReferenceController', 'catalog'));
$router->get('/payment-methods', array('ReferenceController', 'paymentMethods'));
$router->get('/shifts/definitions', array('ReferenceController', 'shifts'));

// Inventory
$router->get('/inventory', array('InventoryController', 'index'));

// Shifts & checklists
$router->get('/checklists/{mode}', array('ShiftController', 'checklist'));
$router->get('/shifts/current', array('ShiftController', 'current'));
$router->post('/shifts/open', array('ShiftController', 'open'));
$router->post('/shifts/{id}/close', array('ShiftController', 'close'));

// Sales (legacy AVO'Gs shadow-table routes)
$router->get('/sales/invoices', array('SalesController', 'index'));
$router->get('/sales/summary', array('SalesController', 'summary'));

// ── Phase 2: FA transaction APIs ─────────────────────────────────────────────

// Sales orders
$router->get('/sales/orders/prefill', array('SalesOrderController', 'prefill'));
$router->post('/sales/orders', array('SalesOrderController', 'create'));
$router->get('/sales/orders/{id}', array('SalesOrderController', 'show'));

// Fulfilment: deliver from an existing sales order
$router->post('/sales/orders/{id}/deliveries', array('SalesDeliveryController', 'createFromOrder'));

// Sales invoices (Phase 2 FA write replaces 0_avogs_sales shadow table)
$router->get('/sales/invoices/prefill', array('SalesInvoiceController', 'prefill'));
$router->post('/sales/invoices', array('SalesInvoiceController', 'create'));
$router->get('/sales/invoices/{id}', array('SalesInvoiceController', 'show'));

// Sales deliveries
$router->get('/sales/deliveries/prefill', array('SalesDeliveryController', 'prefill'));
$router->post('/sales/deliveries', array('SalesDeliveryController', 'create'));
$router->get('/sales/deliveries/{id}', array('SalesDeliveryController', 'show'));

// Customer payments
$router->get('/sales/payments/prefill', array('PaymentController', 'prefill'));
$router->post('/sales/payments', array('PaymentController', 'create'));
$router->get('/sales/payments/{id}', array('PaymentController', 'show'));

// Purchase orders
$router->get('/purchasing/orders/prefill', array('PurchaseOrderController', 'prefill'));
$router->post('/purchasing/orders', array('PurchaseOrderController', 'create'));
$router->get('/purchasing/orders/{id}', array('PurchaseOrderController', 'show'));

// Inventory transfers
$router->get('/inventory/transfers/prefill', array('TransferController', 'prefill'));
$router->post('/inventory/transfers', array('TransferController', 'create'));
$router->get('/inventory/transfers/{id}', array('TransferController', 'show'));

// Inventory adjustments
$router->get('/inventory/adjustments/prefill', array('AdjustmentController', 'prefill'));
$router->post('/inventory/adjustments', array('AdjustmentController', 'create'));
$router->get('/inventory/adjustments/{id}', array('AdjustmentController', 'show'));

// Item context — price + QOH in one round-trip (for dynamic line additions)
$router->get('/items/{stock_id}/context', function (Request $req, $params) {
    Auth::requireUser($req);
    FaTransaction::include_sales();
    FaTransaction::include_inventory();

    $stock_id    = $params['stock_id'] ?? '';
    $customer_id = (int) $req->q('customer_id', 0);
    $supplier_id = (int) $req->q('supplier_id', 0);
    $location    = $req->q('location', '');
    $date        = avogs_date($req);

    $sm = db_fetch(db_query(
        "SELECT stock_id, description, units, mb_flag, material_cost
         FROM " . TB_PREF . "stock_master WHERE stock_id=" . db_escape($stock_id)
    ));
    if (!$sm) Response::error('Unknown stock_id.', 404);

    $result = array(
        'stock_id'      => $sm['stock_id'],
        'description'   => $sm['description'],
        'units'         => $sm['units'],
        'material_cost' => (float) $sm['material_cost'],
        'qoh'           => $location ? FaTransaction::qoh($stock_id, $location, $date) : null,
    );

    if ($customer_id) {
        $cust = get_customer_to_order($customer_id);
        if ($cust) {
            $result['unit_price'] = (float) FaTransaction::sales_price(
                $stock_id, $cust['curr_code'], $cust['salestype'], $cust['factor'], $date
            );
        }
    }

    if ($supplier_id) {
        FaTransaction::include_purchasing();
        $result['supplier_price'] = (float) get_purchase_price($supplier_id, $stock_id);
    }

    Response::json($result);
});

// Operations
$router->get('/deliveries', array('OperationsController', 'listDeliveries'));
$router->post('/deliveries', array('OperationsController', 'createDelivery'));
$router->delete('/deliveries/{id}', array('OperationsController', 'deleteDelivery'));
$router->get('/supplies', array('OperationsController', 'listSupplies'));
$router->post('/supplies', array('OperationsController', 'createSupply'));
$router->delete('/supplies/{id}', array('OperationsController', 'deleteSupply'));

// Finance & wastage
$router->get('/expenses', array('FinanceController', 'listExpenses'));
$router->post('/expenses', array('FinanceController', 'createExpense'));
$router->delete('/expenses/{id}', array('FinanceController', 'deleteExpense'));
$router->get('/wastage', array('FinanceController', 'listWastage'));
$router->post('/wastage', array('FinanceController', 'createWastage'));
$router->delete('/wastage/{id}', array('FinanceController', 'deleteWastage'));

// Media
$router->post('/uploads', array('UploadController', 'create'));

// Reports
$router->get('/reports/sales-trend', array('ReportController', 'salesTrend'));

$router->dispatch($req);
