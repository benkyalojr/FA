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
    Response::json(array(
        'name'    => "AVO'Gs API",
        'status'  => 'ok',
        'version' => '2.5.0',
        'scope'   => 'retail',
        'docs'    => '/api/docs/',
        'guide'   => '/api/docs/MOBILE_APP_GUIDE.md',
        'checkin' => '/api/docs/CHECKIN.md',
        'transactions' => array(
            'sales_invoice'      => '/sales/invoices',
            'customer_payment'   => '/sales/payments',
            'supplier_invoice'   => '/purchasing/invoices',
            'inventory_adjustment' => '/inventory/adjustments',
        ),
    ));
});

// Auth
$router->post('/auth/login', array('AuthController', 'login'));
$router->post('/auth/logout', array('AuthController', 'logout'));

// Reference data (read-only master data for mobile pickers)
$router->get('/stores', array('ReferenceController', 'stores'));
$router->get('/sales-types', array('ReferenceController', 'salesTypes'));
$router->get('/customers', array('ReferenceController', 'customers'));
$router->get('/customers/{id}/prices', array('CatalogController', 'customerPrices'));
$router->get('/customers/{id}', array('ReferenceController', 'customerShow'));
$router->get('/suppliers', array('ReferenceController', 'suppliers'));
$router->get('/suppliers/{id}/prices', array('CatalogController', 'supplierPrices'));
$router->get('/suppliers/{id}', array('ReferenceController', 'supplierShow'));
$router->get('/items', array('CatalogController', 'items'));
$router->get('/items/{stock_id}/context', array('CatalogController', 'itemContext'));
$router->get('/items/{stock_id}', array('CatalogController', 'itemShow'));
$router->get('/prices', array('CatalogController', 'prices'));
$router->get('/purchasing-data', array('CatalogController', 'purchasingData'));
$router->get('/catalog', array('ReferenceController', 'catalog'));
$router->get('/payment-methods', array('ReferenceController', 'paymentMethods'));
$router->get('/payment-terms', array('ReferenceController', 'paymentTerms'));
$router->get('/shifts/definitions', array('ReferenceController', 'shifts'));

// Inventory
$router->get('/inventory', array('InventoryController', 'index'));

// Shifts & checklists
$router->get('/checklists/{mode}', array('ShiftController', 'checklist'));
$router->get('/shifts/current', array('ShiftController', 'current'));
$router->get('/shifts/checkin/prefill', array('ShiftController', 'checkinPrefill'));
$router->post('/shifts/checkin', array('ShiftController', 'checkin'));
$router->get('/shifts/checkout/prefill', array('ShiftController', 'checkoutPrefill'));
$router->post('/shifts/checkout', array('ShiftController', 'checkout'));
$router->get('/shifts/{id}/checkin', array('ShiftController', 'checkinShow'));
$router->get('/shifts/{id}/checkout', array('ShiftController', 'checkoutShow'));
$router->post('/shifts/open', array('ShiftController', 'open'));
$router->post('/shifts/{id}/close', array('ShiftController', 'close'));

// Sales (legacy AVO'Gs shadow-table routes)
$router->get('/sales/invoices', array('SalesController', 'index'));
$router->get('/sales/summary', array('SalesController', 'summary'));

// ── Retail FA transaction APIs ───────────────────────────────────────────────

// Direct sales invoice (cash-and-carry or on credit)
$router->get('/sales/invoices/prefill', array('SalesInvoiceController', 'prefill'));
$router->get('/sales/invoices/pending', array('PaymentController', 'pendingInvoices'));
$router->post('/sales/invoices', array('SalesInvoiceController', 'create'));
$router->get('/sales/invoices/{id}', array('SalesInvoiceController', 'show'));

// Customer payments (credit / tab settlement)
$router->get('/sales/payments/prefill', array('PaymentController', 'prefill'));
$router->post('/sales/payments', array('PaymentController', 'create'));
$router->get('/sales/payments/{id}', array('PaymentController', 'show'));

// Direct supplier invoice (stock in + AP)
$router->get('/purchasing/invoices/prefill', array('SupplierInvoiceController', 'prefill'));
$router->post('/purchasing/invoices', array('SupplierInvoiceController', 'create'));
$router->get('/purchasing/invoices/{id}', array('SupplierInvoiceController', 'show'));

// Inventory adjustments (shrinkage, stocktake)
$router->get('/inventory/adjustments/prefill', array('AdjustmentController', 'prefill'));
$router->post('/inventory/adjustments', array('AdjustmentController', 'create'));
$router->get('/inventory/adjustments/{id}', array('AdjustmentController', 'show'));

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

// Media — prefer POST /media on nginx (a physical api/uploads/ folder breaks POST /uploads).
$router->post('/uploads', array('UploadController', 'create'));
$router->post('/media', array('UploadController', 'create'));
$router->get('/storage/{filename}', array('UploadController', 'serve'));

// Reports & dashboard
$router->get('/dashboard', array('DashboardController', 'index'));
$router->get('/dashboard/summary', array('DashboardController', 'summary'));
$router->get('/dashboard/trends', array('DashboardController', 'trends'));
$router->get('/reports/sales-trend', array('ReportController', 'salesTrend'));

$router->dispatch($req);
