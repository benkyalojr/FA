<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Usage: php scripts/migrate_communications.php [company_id]
// Seeds Communications' channel toggles and creates the gateway config,
// template, notification-rule, recipient-preference and delivery-log
// tables. Idempotent - safe to re-run.
$supplier_company_id = isset($argv[1]) ? $argv[1] : 0;
require __DIR__.'/supplier_types_cli.inc';
require $path_to_root.'/sql/communications_module.php';
require $path_to_root.'/sql/communications.php';
migrate_communications_module();
migrate_communications();
echo "Communications toggles + config/template/rule/log tables are ready for company ".$supplier_company_id.".\n";
