<?php
error_reporting(E_ALL);
define('ABSPATH', __DIR__ . '/fixtures/');
function add_action() {}
function is_multisite() { return false; }
function is_network_admin() { return false; }
function current_user_can() { return true; }
function get_option($key, $default = false) { return $GLOBALS['stub_options'][$key] ?? $default; }
function get_transient() { return false; }
function get_current_user_id() { return 1; }
function get_plugins() { return $GLOBALS['stub_plugins'] ?? []; }
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
function wp_is_file_mod_allowed() { return true; }
function is_plugin_active() { return false; }
function is_plugin_active_for_network() { return false; }
function selected($a, $b, $echo = true) { return $a === $b ? ' selected="selected"' : ''; }
class WP_Error { public $code; public $message; public function __construct(string $code, string $message) { $this->code = $code; $this->message = $message; } }
function is_wp_error($value) { return $value instanceof WP_Error; }
require dirname(__DIR__) . '/fusion-plugins/fusion-plugins.php';

$mirror = json_decode(file_get_contents(dirname(__DIR__) . '/mirror-index.json'), true);
$validated = fpl_validate_mirror($mirror);
if (is_wp_error($validated) || count($validated['packages']) !== 10) { throw new RuntimeException('The published mirror index did not validate.'); }
$GLOBALS['stub_options']['fpl_mirror'] = $validated;

$_GET['tab'] = 'catalogue';
ob_start();
fpl_render_admin();
$html = ob_get_clean();
foreach (['Fusion Plugins', 'Updates available', 'Catalogue', 'Check for updates', 'CMS Tree Page View', 'Advanced Custom Fields PRO', 'fpl-catalog'] as $text) {
    if (strpos($html, $text) === false) { throw new RuntimeException('Missing admin UI text: ' . $text); }
}
if (strpos($html, 'id="fpl-view"') === false || strpos($html, '<dialog id="fpl-dialog"') === false || strpos($html, 'aria-labelledby="fpl-dialog-title"') === false || strpos($html, 'id="fpl-feedback"') !== false) {
    throw new RuntimeException('The Fusion progress modal is missing or inline feedback remains.');
}
$view = fpl_dispatch('view', ['tab'=>'catalogue']);
if (is_wp_error($view) || strpos($view['html'], 'id="fpl-view"') === false) { throw new RuntimeException('The catalog view could not refresh inside the modal flow.'); }
if (substr_count($html, 'class="fpl-card"') !== 17 || substr_count($html, 'class="nav-tab ') !== 2 || strpos($html, '>Settings</a>') !== false || strpos($html, '>Catalogs</a>') !== false || strpos($html, 'Techn /') !== false || strpos($html, 'AlphaSys') !== false) {
    throw new RuntimeException('Unexpected catalog or branding output.');
}
$_GET['tab'] = 'installed';
ob_start();
fpl_render_admin();
$html = ob_get_clean();
if (strpos($html, 'No mirrored updates are available for plugins in this catalog.') === false) {
    throw new RuntimeException('The updates tab did not show its empty state.');
}
$GLOBALS['stub_plugins'] = ['cms-tree-page-view/cms-tree-page-view.php'=>['Name'=>'CMS Tree Page View','Version'=>'2.6.0']];
ob_start();
fpl_render_admin();
$html = ob_get_clean();
if (strpos($html, 'name="fpl-selected"') === false || strpos($html, '<th scope="col">Available</th>') === false || strpos($html, '<th scope="col">Mirror</th>') !== false || strpos($html, 'Update selected plugins') === false) {
    throw new RuntimeException('The mirrored update was not selectable.');
}
echo "Admin smoke test passed.\n";
