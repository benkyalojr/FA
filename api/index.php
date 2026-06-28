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

// Sales
$router->post('/sales/invoices', array('SalesController', 'create'));
$router->get('/sales/invoices', array('SalesController', 'index'));
$router->get('/sales/summary', array('SalesController', 'summary'));

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
