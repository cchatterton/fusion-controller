<?php
error_reporting(E_ALL);
define('ABSPATH', __DIR__ . '/fixtures/');
function add_action() {}
function is_multisite() { return false; }
function is_network_admin() { return false; }
function current_user_can() { return true; }
function get_option($key, $default = false) { return $default; }
function get_transient() { return false; }
function get_current_user_id() { return 1; }
function get_plugins() { return []; }
function get_site_transient() { return false; }
function admin_url($path = '') { return '/wp-admin/' . $path; }
function self_admin_url($path = '') { return '/wp-admin/' . $path; }
function add_query_arg($key, $value, $url) { return $url . (strpos($url, '?') === false ? '?' : '&') . $key . '=' . $value; }
function wp_unslash($value) { return $value; }
function sanitize_key($value) { return $value; }
function esc_html($value) { return htmlspecialchars((string) $value, ENT_QUOTES); }
function esc_attr($value) { return esc_html($value); }
function esc_url($value) { return esc_attr($value); }
function wp_nonce_field() {}
function wp_nonce_url($url) { return $url; }
function selected($a, $b, $echo = true) { return $a === $b ? ' selected="selected"' : ''; }
require dirname(__DIR__) . '/fusion-plugins/fusion-plugins.php';

$_GET['tab'] = 'catalogue';
ob_start();
fpl_render_admin();
$html = ob_get_clean();
foreach (['Fusion Plugins', 'Updates available', 'Catalogue', 'Settings', 'Catalogs', 'CMS Tree Page View', 'Advanced Custom Fields PRO'] as $text) {
    if (strpos($html, $text) === false) { throw new RuntimeException('Missing admin UI text: ' . $text); }
}
if (substr_count($html, 'class="fpl-card"') !== 16 || strpos($html, 'Techn /') !== false || strpos($html, 'AlphaSys') !== false) {
    throw new RuntimeException('Unexpected catalog or branding output.');
}
echo "Admin smoke test passed.\n";
