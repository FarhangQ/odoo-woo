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

if (!defined('ABSPATH')) exit; // No direct access

/**
 * Get Odoo config from options
 */
function odoo_config($key)
{
    return get_option($key);
}

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
                odoo_config('odoo_db'),
                odoo_uid(),
                odoo_config('odoo_key'),
                $model,
                $method,
                $args,
                $kwargs
            ]
        ],
        "id" => uniqid()
    ];

    $response = wp_remote_post(
        esc_url(odoo_config('odoo_url')),
        [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => wp_json_encode($payload),
            'timeout' => 30,
        ]
    );

    if (is_wp_error($response)) return false;

    $body = wp_remote_retrieve_body($response);
    $decoded = json_decode($body, true);

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
            "args" => [
                odoo_config('odoo_db'),
                odoo_config('odoo_user'),
                odoo_config('odoo_key'),
                []
            ]
        ],
        "id" => uniqid()
    ];

    $response = wp_remote_post(
        esc_url(odoo_config('odoo_url')),
        [
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode($payload),
            'timeout' => 30,
        ]
    );

    if (is_wp_error($response)) return 0;

    $body = wp_remote_retrieve_body($response);
    $decoded = json_decode($body, true);
    $uid = $decoded['result'] ?? 0;

    return $uid;
}

/**
 * Admin Menu
 */
add_action('admin_menu', function () {
    add_menu_page(
        esc_html__('Odoo Sync', 'odoo-woo'),
        esc_html__('Odoo Sync', 'odoo-woo'),
        'manage_options',
        'odoo-sync',
        'odoo_admin_page',
        'dashicons-database',
        56
    );
});

/**
 * Admin Page with Tabs
 */
function odoo_admin_page()
{
    $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'products';
    echo '<div class="wrap"><h1>' . esc_html__('Odoo Sync', 'odoo-woo') . '</h1>';
    echo '<h2 class="nav-tab-wrapper">';
    echo '<a href="?page=odoo-sync&tab=products" class="nav-tab ' . ($tab == 'products' ? 'nav-tab-active' : '') . '">' . esc_html__('Import Products', 'odoo-woo') . '</a>';
    echo '<a href="?page=odoo-sync&tab=modules" class="nav-tab ' . ($tab == 'modules' ? 'nav-tab-active' : '') . '">' . esc_html__('Modules', 'odoo-woo') . '</a>';
    echo '<a href="?page=odoo-sync&tab=settings" class="nav-tab ' . ($tab == 'settings' ? 'nav-tab-active' : '') . '">' . esc_html__('Settings', 'odoo-woo') . '</a>';
    echo '</h2>';

    if ($tab === 'products') odoo_products_tab();
    elseif ($tab === 'modules') odoo_modules_tab();
    elseif ($tab === 'settings') odoo_settings_tab();

    // Inline JS for bulk and single import
?>
    <script type="text/javascript">
        jQuery(document).ready(function($) {
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
                        _wpnonce: '<?php echo esc_js(wp_create_nonce("odoo-sync-nonce")); ?>'
                    }, function(response) {
                        loader.html(response.success ? '✅' : '❌');
                        button.prop('disabled', false);
                        index++;
                        importNext();
                    });
                }
                importNext();
            });

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
                    _wpnonce: '<?php echo esc_js(wp_create_nonce("odoo-sync-nonce")); ?>'
                }, function(response) {
                    loader.html(response.success ? '✅' : '❌');
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
        echo '<p style="color:red;">❌ ' . esc_html__('Could not authenticate to Odoo.', 'odoo-woo') . '</p>';
        return;
    }
    $modules = odoo_rpc("ir.module.module", "search_read", [[["state", "=", "installed"]]], ["fields" => ["name", "shortdesc", "author", "state"], "limit" => 50]);
    if (empty($modules)) {
        echo '<p>' . esc_html__('No modules found.', 'odoo-woo') . '</p>';
        return;
    }

    echo '<table class="widefat striped"><thead><tr>';
    echo '<th>' . esc_html__('Name', 'odoo-woo') . '</th>';
    echo '<th>' . esc_html__('Description', 'odoo-woo') . '</th>';
    echo '<th>' . esc_html__('Author', 'odoo-woo') . '</th>';
    echo '<th>' . esc_html__('Status', 'odoo-woo') . '</th>';
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
        echo '<p style="color:red;">❌ ' . esc_html__('Could not authenticate to Odoo.', 'odoo-woo') . '</p>';
        return;
    }
    $products = odoo_rpc("product.template", "search_read", [[]], ["fields" => ["id", "name", "list_price", "default_code"], "limit" => 20]);
    if (empty($products)) {
        echo '<p>' . esc_html__('No products found in Odoo.', 'odoo-woo') . '</p>';
        return;
    }

    echo '<button type="button" id="odoo-bulk-import" class="button button-primary" style="margin-bottom:10px;">' . esc_html__('Import All Listed Products', 'odoo-woo') . '</button>';
    echo '<table class="widefat striped" id="odoo-products-table"><thead><tr>';
    echo '<th>' . esc_html__('ID', 'odoo-woo') . '</th>';
    echo '<th>' . esc_html__('Name', 'odoo-woo') . '</th>';
    echo '<th>' . esc_html__('Price', 'odoo-woo') . '</th>';
    echo '<th>' . esc_html__('SKU', 'odoo-woo') . '</th>';
    echo '<th>' . esc_html__('Loader', 'odoo-woo') . '</th>';
    echo '<th>' . esc_html__('Action', 'odoo-woo') . '</th>';
    echo '</tr></thead><tbody>';
    foreach ($products as $p) {
        echo '<tr data-odoo-id="' . esc_attr($p['id']) . '">';
        echo '<td>' . esc_html($p['id']) . '</td>';
        echo '<td>' . esc_html($p['name']) . '</td>';
        echo '<td>' . esc_html($p['list_price']) . '</td>';
        echo '<td>' . esc_html($p['default_code'] ?? '') . '</td>';
        echo '<td><span class="odoo-loader"></span></td>';
        echo '<td><button type="button" class="button odoo-import-single">' . esc_html__('Import', 'odoo-woo') . '</button></td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
}

/**
 * Settings Tab (processed via admin_post hook)
 */
function odoo_settings_tab()
{
    $url  = esc_attr(get_option('odoo_url', ''));
    $db   = esc_attr(get_option('odoo_db', ''));
    $user = esc_attr(get_option('odoo_user', ''));
    $key  = esc_attr(get_option('odoo_key', ''));
?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="odoo_save_settings">
        <?php wp_nonce_field('odoo_save_settings', 'odoo_settings_nonce'); ?>
        <table class="form-table">
            <tr>
                <th><?php echo esc_html__('Odoo URL', 'odoo-woo'); ?></th>
                <td><input type="url" name="odoo_url" value="<?php echo esc_attr(get_option('odoo_url', '')); ?>" class="regular-text" required></td>
            </tr>
            <tr>
                <th><?php echo esc_html__('Database', 'odoo-woo'); ?></th>
                <td><input type="text" name="odoo_db" value="<?php echo esc_attr(get_option('odoo_db', '')); ?>" class="regular-text" required></td>
            </tr>
            <tr>
                <th><?php echo esc_html__('User Email', 'odoo-woo'); ?></th>
                <td><input type="email" name="odoo_user" value="<?php echo esc_attr(get_option('odoo_user', '')); ?>" class="regular-text" required></td>
            </tr>
            <tr>
                <th><?php echo esc_html__('API Key', 'odoo-woo'); ?></th>
                <td><input type="password" name="odoo_key" value="<?php echo esc_attr(get_option('odoo_key', '')); ?>" class="regular-text" required></td>
            </tr>
        </table>
        <p class="submit">
            <input type="submit" class="button button-primary" value="<?php echo esc_attr__('Save Settings', 'odoo-woo'); ?>">
        </p>
    </form>

<?php
}

/**
 * Save Settings Handler
 */
// Hook to save settings securely
add_action('admin_post_odoo_save_settings', function () {

    // Unsplash (sanitize) nonce first
    $nonce = isset($_POST['odoo_settings_nonce']) ? wp_unslash($_POST['odoo_settings_nonce']) : '';

    // Verify nonce
    if (!wp_verify_nonce($nonce, 'odoo_save_settings')) {
        wp_die(esc_html__('Nonce verification failed', 'odoo-woo'));
    }

    // Sanitize other fields
    $odoo_url  = isset($_POST['odoo_url']) ? sanitize_text_field(wp_unslash($_POST['odoo_url'])) : '';
    $odoo_db   = isset($_POST['odoo_db']) ? sanitize_text_field(wp_unslash($_POST['odoo_db'])) : '';
    $odoo_user = isset($_POST['odoo_user']) ? sanitize_email(wp_unslash($_POST['odoo_user'])) : '';
    $odoo_key  = isset($_POST['odoo_key']) ? sanitize_text_field(wp_unslash($_POST['odoo_key'])) : '';

    update_option('odoo_url', $odoo_url);
    update_option('odoo_db', $odoo_db);
    update_option('odoo_user', $odoo_user);
    update_option('odoo_key', $odoo_key);

    // Redirect back to settings page
    wp_redirect(admin_url('admin.php?page=odoo-sync&tab=settings&saved=1'));
    exit;
});


/**
 * AJAX Handler
 */
add_action('wp_ajax_odoo_bulk_import', function () {
    check_ajax_referer('odoo-sync-nonce');
    $odoo_id = intval($_POST['odoo_id'] ?? 0);
    if (!$odoo_id) wp_send_json_error(['message' => esc_html__('Invalid Odoo ID', 'odoo-woo')]);

    $wc_id = odoo_import_product_to_wc($odoo_id);
    if ($wc_id) wp_send_json_success(['message' => esc_html('Imported/Updated product ID ' . $wc_id)]);
    else wp_send_json_error(['message' => esc_html__('Failed', 'odoo-woo')]);
});

/**
 * Import/update product
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
