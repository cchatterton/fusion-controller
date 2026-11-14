<?php
/**
 * Plugin Name: Fusion Plugins
 * Description: Curated plugin catalogs with convenient updates from the Fusion mirror.
 * Version: 0.3.2
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Update URI: https://github.com/cchatterton/fusion-controller
 * Author: Techn
 * Author URI: https://techn.com.au
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: fusion-plugins
 */

if (!defined('ABSPATH')) { exit; }

define('FPL_VERSION', '0.3.2');
define('FPL_FILE', __FILE__);
define('FPL_ROOT', __DIR__ . '/');
define('FPL_MIRROR_URL', 'https://raw.githubusercontent.com/cchatterton/fusion-controller/main/fusion-index.json');

require_once __DIR__ . '/includes/catalogs.php';
require_once __DIR__ . '/includes/actions.php';
require_once __DIR__ . '/includes/admin.php';

add_action('admin_menu', 'fpl_add_menu');
add_action('network_admin_menu', 'fpl_add_menu');
add_action('admin_post_fpl_action', 'fpl_handle_action');
add_action('wp_ajax_fpl_action', 'fpl_handle_ajax');
add_action('admin_enqueue_scripts', 'fpl_admin_assets');
add_action('plugins_loaded', 'fpl_capture_ajax_output', PHP_INT_MAX);

function fpl_capture_ajax_output(): void {
    if (wp_doing_ajax() && ($_REQUEST['action'] ?? '') === 'fpl_action') {
        ob_start();
        define('FPL_AJAX_BUFFER_LEVEL', ob_get_level());
    }
}
