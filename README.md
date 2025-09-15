# odoo-woo

Seamlessly sync your **WooCommerce** store with **Odoo ERP**. Import modules and products from Odoo into WooCommerce, with bulk or single product import and automatic updates.

---

## Features

- View installed **Odoo modules** in WordPress admin.  
- Import **products from Odoo** to WooCommerce.  
- **Bulk import** all products sequentially with loader and status indicators.  
- **Single import button** per product for manual control.  
- **Update existing products** automatically using `_odoo_id` meta (prevents duplicates).  
- Works even if products have **no SKU**.  
- Fully **AJAX-powered**, no external dependencies.

---

## Installation

1. Upload the plugin folder to `wp-content/plugins/`.  
2. Activate the plugin from the WordPress admin dashboard.  
3. Open `odoo-sync.php` and configure your **Odoo credentials**:  
   ```php
   define('ODOO_URL', 'https://your-odoo.com/jsonrpc');
   define('ODOO_DB', 'your_db');
   define('ODOO_USER', 'user@example.com');
   define('ODOO_KEY', 'your_api_key_here');
