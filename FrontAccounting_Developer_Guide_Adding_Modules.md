# Developer Guide: Creating & Integrating Custom Modules in FrontAccounting ERP

This guide provides a comprehensive roadmap for developers wishing to extend the functionality of FrontAccounting ERP by creating and integrating custom modules and extensions. By adhering to FrontAccounting's built-in pluggable architecture, developers can build robust add-ons without modifying core codebase files.

---

## 1. Directory Structure Conventions

Every module should be contained within its own namespace subdirectory under the global `/modules/` directory.

### Standard Subdirectory Layout

```
/modules/my_module_name/
  ├── hooks.php               # Core Connector File (Mandatory)
  ├── my_module.php           # Main GUI entry point 
  ├── schema.sql              # Database creation/alteration script
  ├── includes/               # Business logic, database classes, and data sets
  │     ├── my_db.inc
  │     └── my_ui.inc
  ├── sql/                    # SQL migration updates folder
  │     └── schema.sql
  └── images/                 # Theme-specific icons or image assets
```

*   **`hooks.php`**: This file acts as the registration hub. It is included automatically by the session manager if the module is active.
*   **`my_module.php`**: The initial transaction or interface screen presented to users.

---

## 2. Core Connector (`hooks.php`) Template

The `hooks.php` file must declare a class named `hooks_<package_name>` that inherits from the core `hooks` class.

Here is a fully commented boilerplate template:

```php
<?php
/**********************************************************************
    Copyright (C) Your Company / Your Name
***********************************************************************/

class hooks_my_module_name extends hooks 
{
    // Directory folder name in /modules/
    var $module_name = 'my_module_name';
    var $path = null;

    /**
     * Executes database tables check and updates during module installation/activation.
     * Invoked when module is activated in Setup -> Install/Activate Extensions.
     */
    function install_extension($check_only=true)
    {
        // Format: 'sql_file_name.sql' => array('table_name', 'field_check', 'property_check')
        $updates = array(
            'schema.sql' => array('my_custom_table', 'id', 'int(11)')
        );
        
        return $this->update_databases(-1, $updates, $check_only);
    }

    /**
     * Executed when the module is deactivated by an administrator.
     */
    function uninstall_extension($check_only=true)
    {
        return true;
    }

    /**
     * Injects options and links into existing application tabs.
     * Called automatically during dashboard layout assembly.
     */
    function install_options($app)
    {
        global $path_to_root;
        
        // Target specific application module tabs by ID
        switch ($app->id) {
            case 'orders': // Sales tab dashboard
                // Injects a link into the right column (Section index 0, Transactions)
                $app->add_rapp_function(0, _("My Custom Tool"), 
                    $path_to_root . '/modules/my_module_name/my_module.php?', 
                    'SA_MYCUSTOM_PERM', MENU_TRANSACTION);
                break;
                
            case 'system': // Setup tab dashboard
                // Injects a link into the left column (Section index 2, Maintenance)
                $app->add_lapp_function(2, _("My Extension Preferences"), 
                    $path_to_root . '/modules/my_module_name/setup.php?', 
                    'SA_MYCUSTOM_SETUP', MENU_SETTINGS);
                break;
        }
    }

    /**
     * Declares custom Role-Based Access Control (RBAC) privileges.
     * Dynamic values are integrated during core session startup.
     */
    function install_access()
    {
        // Define Custom Security Section code. Range must be >= 100
        define('SS_MYCUSTOM', 101<<8); 
        
        // Section label displayed in security roles manager
        $security_sections[SS_MYCUSTOM] = _("My Custom Module Actions");
        
        // Mapped Security Areas (Access Tokens)
        $security_areas['SA_MYCUSTOM_PERM'] = array(SS_MYCUSTOM|1, _("Access Custom Module Tool"));
        $security_areas['SA_MYCUSTOM_SETUP'] = array(SS_MYCUSTOM|2, _("Configure Custom Module Settings"));
        
        return array($security_areas, $security_sections);
    }

    /**
     * Business logic hook: Intercepts core database writes before execution.
     * Returning false aborts the database save.
     */
    function db_prewrite(&$cart, $trans_type)
    {
        // Example: block transaction if document date is invalid
        if ($trans_type == ST_SALESORDER && empty($cart->document_date)) {
            return false;
        }
        return true;
    }

    /**
     * Business logic hook: Intercepts core database writes after successful commit.
     */
    function db_postwrite(&$cart, $trans_type)
    {
        // Example: sync completed sales orders to an external CRM
        return true;
    }
}
?>
```

---

## 3. Integrating Custom Access Control (RBAC)

To protect your module's pages and check roles securely, every UI file inside your module must invoke the security manager:

```php
<?php
// 1. Establish absolute path back to application root folder
$path_to_root = "../..";

// 2. Identify the target security area needed to access this page
$page_security = 'SA_MYCUSTOM_PERM'; 

// 3. Include session.inc. This will automatically execute check_page_security()
include_once($path_to_root . "/includes/session.inc");
include_once($path_to_root . "/includes/ui.inc");

// 4. Initialize UI layout
page(_("My Custom Tool Panel"));

// Page content goes here...

end_page();
?>
```

---

## 4. Injecting Navigation Link Items

To link a developer script to an existing dashboard column:

1.  Inside your `install_options($app)` method, evaluate `$app->id` to target modules:
    *   `'orders'`: Sales & Customers tab
    *   `'AP'`: Purchases & Suppliers tab
    *   `'stock'`: Items & Inventory tab
    *   `'manuf'`: Manufacturing tab
    *   `'GL'`: Banking & General Ledger tab
    *   `'system'`: Setup tab
2.  Use `$app->add_lapp_function` or `$app->add_rapp_function` with:
    *   `$module_index`: Column section category group (0 = Transactions, 1 = Inquiries/Reports, 2 = Maintenance).
    *   `$label`: Text string shown on the link.
    *   `$url`: Path to target module script.
    *   `$security_key`: Token required to display/execute (e.g., `'SA_MYCUSTOM_PERM'`).
    *   `$menu_category`: Core constants defining type (`MENU_TRANSACTION`, `MENU_INQUIRY`, `MENU_REPORT`, `MENU_ENTRY`, `MENU_MAINTENANCE`, `MENU_SETTINGS`).

---

## 5. Creating a Brand New Navigation Tab

If your module is extensive and requires its own main tab on the top menu bar, you must define an application class:

```php
<?php
class my_new_app extends application 
{
    function __construct() 
    {
        // Parameters: id (tab name), label, enabled(true/false)
        parent::__construct("my_new_app_tab", _("&My Custom ERP Module"));
    
        // Add modules / categories columns
        $this->add_module(_("Operations"));
        
        // Add left column action links
        $this->add_lapp_function(0, _("Transaction Entry Screen"),
            "modules/my_module_name/transaction.php?", 'SA_MYCUSTOM_PERM', MENU_TRANSACTION);
            
        // Add right column reports links
        $this->add_rapp_function(0, _("Operational Report"),
            "modules/my_module_name/report.php?", 'SA_MYCUSTOM_PERM', MENU_REPORT);
    }
}
?>
```

To register this class in your `hooks.php`:
```php
function install_tabs($app)
{
    include_once($this->path . '/my_new_app.php');
    $app->add_application(new my_new_app());
}
```

---

## 6. Schema Migrations and Updates

Automated database configuration makes custom modules self-contained:

1.  Write a baseline database creation script (e.g. `/modules/my_module_name/sql/schema.sql`).
2.  Use standard, safe InnoDB creation blocks to avoid conflicts:
    ```sql
    CREATE TABLE IF NOT EXISTS `0_my_custom_table` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `description` varchar(60) NOT NULL DEFAULT '',
      PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=latin1;
    ```
3.  Register the sql check parameters in your `install_extension()` method. If FrontAccounting fails to detect the table, field, or property, it will automatically parse the sql file during activation.

---

## 7. Dynamic Form Design and AJAX Integration

FrontAccounting relies on a customized AJAX framework. To create standard interactive forms that update without page reloads:

```php
<?php
$path_to_root = "../..";
$page_security = 'SA_MYCUSTOM_PERM';
include_once($path_to_root . "/includes/session.inc");
include_once($path_to_root . "/includes/ui.inc");

page(_("Interactive Component Panel"));

// 1. Initialize custom Form wrapper
start_form();

start_table(TABLESTYLE2);

// 2. Add an input text box with standard layout
text_row(_("Enter Value:"), 'user_value', null, 30, 60);

// 3. Add an AJAX-enabled dropdown control
// Parameters: label, name, selected_val, table_query, key_col, desc_col, submit_on_change
combo_row(_("Select Customer:"), 'customer_id', null, '0_debtors_master', 'debtor_no', 'name', true);

end_table(1);

// 4. Implement dynamic AJAX target wrappers
div_start('my_dynamic_response');
if (list_updated('customer_id')) {
    display_notification(_("Selected Customer ID: ") . get_post('customer_id'));
}
div_end();

// 5. Form action buttons
submit_center('Process', _("Execute Action"), true, _("Verify inputs and submit"), 'default');

end_form();

end_page();
?>
```

### Essential Form Controls Checklist
*   **`start_form()` / `end_form()`**: Mandatory around interactive inputs.
*   **`start_table(TABLESTYLE)` / `end_table()`**: Aligns form label/value pairs cleanly.
*   **`text_row()`**: Form input wrapper for textual entry.
*   **`submit_center()`**: AJAX-enabled button wrapper. Specifying `'default'` maps Ctrl+Enter to trigger form submission.
*   **`div_start('id')` / `div_end()`**: Crucial for mapping specific HTML blocks to dynamic server-side AJAX responses.
*   **`list_updated('control_name')`**: Evaluates if a dropdown/combobox was altered. Automatically triggers AJAX DOM updates via `JsHttpRequest`.

---

## 8. Extension Packaging & Activation

Once your custom files are structured:

1.  Archive your folder in `.zip` format (or leave it in `/modules/your_module_name/` in your local filesystem).
2.  Navigate to **Setup -> Install/Activate Extensions**.
3.  FrontAccounting will scan the directory and list the inactive module.
4.  Click **Install** next to your module name to register it.
5.  Database structures are automatically compiled and created.
6.  The module will now write configuration entries inside the `/company/X/installed_extensions.php` catalog, creating links on your dashboard immediately.
