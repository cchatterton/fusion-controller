<?php
/**
 * Plugin Name: Fusion Plugins
 * Description: Curated plugin catalogs with optional, approved mirror installs.
 * Version: 0.1.1
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

define('FPL_VERSION', '0.1.1');
define('FPL_FILE', __FILE__);
define('FPL_ROOT', __DIR__ . '/');
define('FPL_APPROVED_URL', 'https://raw.githubusercontent.com/cchatterton/fusion-controller/main/approved.json');

require_once __DIR__ . '/includes/catalogs.php';
require_once __DIR__ . '/includes/actions.php';
require_once __DIR__ . '/includes/admin.php';

add_action('admin_menu', 'fpl_add_menu');
add_action('network_admin_menu', 'fpl_add_menu');
add_action('admin_post_fpl_action', 'fpl_handle_action');
add_action('admin_enqueue_scripts', 'fpl_admin_assets');
