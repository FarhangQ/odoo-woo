<?php

/**
 * Plugin Name: Odoo Woo
 * Plugin URI: https://github.com/FarhangQ/odoo-woo
 * Description: Sync WooCommerce products and modules with Odoo via API. Supports bulk and single product import with updates.
 * Version: 1.0.0
 * Author: Farhang Qahwe
 * Author URI: https://tirdesign.ir
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 * Text Domain: odoo-woo
 */

if (! defined('ABSPATH')) exit; // No direct access

// --- CONFIG (change to your setup) ---
define('ODOO_URL', 'https://your-odoo.com/jsonrpc');     // Odoo JSON-RPC endpoint
define('ODOO_DB', 'your_db');    // Odoo DB name
define('ODOO_USER', 'user@example.com');    // Odoo user that owns API key
define('ODOO_KEY', 'your_api_key_here');     // Odoo API key


/**
 * JSON-RPC call
 */
function odoo_rpc($model, $method, $args = [], $kwargs = [])
{
    $payload = [
        "jsonrpc" => "2.0",
        "method" => "call",
        "params" => [
            "service" => "object",
            "method" => "execute_kw",
            "args" => [
                ODOO_DB,
                odoo_uid(),
                ODOO_KEY,
                $model,
                $method,
                $args,
                $kwargs
            ]
        ],
        "id" => uniqid()
    ];
    $ch = curl_init(ODOO_URL);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    $resp = curl_exec($ch);
    curl_close($ch);
    $decoded = json_decode($resp, true);
    return $decoded['result'] ?? false;
}

/**
 * Authenticate to Odoo once
 */
function odoo_uid()
{
    static $uid = null;
    if ($uid !== null) return $uid;

    $payload = [
        "jsonrpc" => "2.0",
        "method" => "call",
        "params" => [
            "service" => "common",
            "method" => "authenticate",
            "args" => [ODOO_DB, ODOO_USER, ODOO_KEY, []]
        ],
        "id" => uniqid()
    ];
    $ch = curl_init(ODOO_URL);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    $resp = curl_exec($ch);
    curl_close($ch);
    $decoded = json_decode($resp, true);
    $uid = $decoded['result'] ?? 0;
    return $uid;
}

/**
 * Admin Menu
 */
add_action('admin_menu', function () {
    add_menu_page(
        'Odoo Sync',
        'Odoo Sync',
        'manage_options',
        'odoo-sync',
        'odoo_admin_page',
        'dashicons-database',
        56
    );
});

/**
 * Admin Page with Tabs + Inline JS
 */
function odoo_admin_page()
{
    $tab = $_GET['tab'] ?? 'modules';
    echo '<div class="wrap"><h1>Odoo Sync</h1>';
    echo '<h2 class="nav-tab-wrapper">';
    echo '<a href="?page=odoo-sync&tab=products" class="nav-tab ' . ($tab == 'products' ? 'nav-tab-active' : '') . '">Import Products</a>';
    echo '<a href="?page=odoo-sync&tab=modules" class="nav-tab ' . ($tab == 'modules' ? 'nav-tab-active' : '') . '">Modules</a>';
    echo '</h2>';

    if ($tab == 'products') odoo_products_tab();
    else if ($tab == 'modules') odoo_modules_tab();

    // Inline JS for sequential bulk and single import
?>
    <script type="text/javascript">
        jQuery(document).ready(function($) {
            // Bulk import
            $('#odoo-bulk-import').on('click', function() {
                var rows = $('#odoo-products-table tbody tr');
                var index = 0;

                function importNext() {
                    if (index >= rows.length) return;
                    var row = $(rows[index]);
                    var odooId = row.data('odoo-id');
                    var loader = row.find('.odoo-loader');
                    var button = row.find('.odoo-import-single');

                    loader.html('⏳');
                    button.prop('disabled', true);

                    $.post(ajaxurl, {
                        action: 'odoo_bulk_import',
                        odoo_id: odooId,
                        _wpnonce: '<?php echo wp_create_nonce("odoo-sync-nonce"); ?>'
                    }, function(response) {
                        if (response.success) {
                            loader.html('✅');
                        } else {
                            loader.html('❌');
                        }
                        button.prop('disabled', false);
                        index++;
                        importNext();
                    });
                }
                importNext();
            });

            // Single product import
            $('.odoo-import-single').on('click', function() {
                var row = $(this).closest('tr');
                var odooId = row.data('odoo-id');
                var loader = row.find('.odoo-loader');
                var button = $(this);

                loader.html('⏳');
                button.prop('disabled', true);

                $.post(ajaxurl, {
                    action: 'odoo_bulk_import',
                    odoo_id: odooId,
                    _wpnonce: '<?php echo wp_create_nonce("odoo-sync-nonce"); ?>'
                }, function(response) {
                    if (response.success) {
                        loader.html('✅');
                    } else {
                        loader.html('❌');
                    }
                    button.prop('disabled', false);
                });
            });
        });
    </script>
<?php
    echo '</div>';
}

/**
 * Modules Tab
 */
function odoo_modules_tab()
{
    $uid = odoo_uid();
    if (!$uid) {
        echo '<p style="color:red;">❌ Could not authenticate to Odoo.</p>';
        return;
    }

    $modules = odoo_rpc("ir.module.module", "search_read", [[["state", "=", "installed"]]], ["fields" => ["name", "shortdesc", "author", "state"], "limit" => 50]);
    if (empty($modules)) {
        echo '<p>No modules found.</p>';
        return;
    }

    echo '<table class="widefat striped"><thead><tr>';
    echo '<th>Name</th><th>Description</th><th>Author</th><th>Status</th>';
    echo '</tr></thead><tbody>';
    foreach ($modules as $m) {
        echo '<tr>';
        echo '<td>' . esc_html($m['name']) . '</td>';
        echo '<td>' . esc_html($m['shortdesc'] ?? '') . '</td>';
        echo '<td>' . esc_html($m['author'] ?? '') . '</td>';
        echo '<td>' . esc_html($m['state']) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
}

/**
 * Products Tab
 */
function odoo_products_tab()
{
    $uid = odoo_uid();
    if (!$uid) {
        echo '<p style="color:red;">❌ Could not authenticate to Odoo.</p>';
        return;
    }

    $products = odoo_rpc("product.template", "search_read", [[]], ["fields" => ["id", "name", "list_price", "default_code"], "limit" => 20]);
    if (empty($products)) {
        echo '<p>No products found in Odoo.</p>';
        return;
    }

    echo '<button type="button" id="odoo-bulk-import" class="button button-primary" style="margin-bottom:10px;">Import All Listed Products</button>';

    echo '<table class="widefat striped" id="odoo-products-table"><thead><tr>';
    echo '<th>ID</th><th>Name</th><th>Price</th><th>SKU</th><th>Loader</th><th>Action</th>';
    echo '</tr></thead><tbody>';
    foreach ($products as $p) {
        echo '<tr data-odoo-id="' . esc_attr($p['id']) . '">';
        echo '<td>' . esc_html($p['id']) . '</td>';
        echo '<td>' . esc_html($p['name']) . '</td>';
        echo '<td>' . esc_html($p['list_price']) . '</td>';
        echo '<td>' . esc_html($p['default_code'] ?? '') . '</td>';
        echo '<td><span class="odoo-loader"></span></td>';
        echo '<td><button type="button" class="button odoo-import-single">Import</button></td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
}

/**
 * AJAX Handler for sequential import (single/bulk)
 */
add_action('wp_ajax_odoo_bulk_import', function () {
    check_ajax_referer('odoo-sync-nonce');
    $odoo_id = intval($_POST['odoo_id'] ?? 0);
    if (!$odoo_id) wp_send_json_error(['message' => 'Invalid Odoo ID']);

    $wc_id = odoo_import_product_to_wc($odoo_id);
    if ($wc_id) wp_send_json_success(['message' => 'Imported/Updated product ID ' . $wc_id]);
    else wp_send_json_error(['message' => 'Failed']);
});

/**
 * Import/update single product by Odoo ID
 */
function odoo_import_product_to_wc($odoo_id)
{
    $product_data = odoo_rpc("product.template", "read", [[$odoo_id]], ["fields" => ["name", "list_price", "description_sale"]]);
    if (empty($product_data[0])) return false;
    $p = $product_data[0];

    $existing = get_posts([
        'post_type' => 'product',
        'meta_key' => '_odoo_id',
        'meta_value' => $odoo_id,
        'posts_per_page' => 1,
        'post_status' => 'any'
    ]);

    if (!empty($existing)) {
        $wc_product = wc_get_product($existing[0]->ID);
    } else {
        $wc_product = new WC_Product_Simple();
    }

    $wc_product->set_name($p['name']);
    $wc_product->set_regular_price($p['list_price']);
    if (!empty($p['description_sale'])) $wc_product->set_description($p['description_sale']);
    $wc_product->set_status('publish');
    $wc_product->set_catalog_visibility('visible');
    $wc_product->set_stock_status('instock');
    $wc_product->save();

    if (empty($existing)) update_post_meta($wc_product->get_id(), '_odoo_id', $odoo_id);

    return $wc_product->get_id();
}
