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
function get_site_transient($key) { return $GLOBALS['stub_site_transient'][$key] ?? false; }
function admin_url($path = '') { return '/wp-admin/' . $path; }
function self_admin_url($path = '') { return '/wp-admin/' . $path; }
function add_query_arg($key, $value, $url = null) {
    if (is_array($key)) { $url = $value; $query = http_build_query($key); }
    else { $query = urlencode($key) . '=' . urlencode($value); }
    return $url . (strpos($url, '?') === false ? '?' : '&') . $query;
}
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

$submenu['plugins.php'] = [
    ['Installed Plugins', 'activate_plugins', 'plugins.php'],
    ['Add Plugin', 'install_plugins', 'plugin-install.php'],
    ['AlphaSys Plugins', 'update_plugins', 'asuc'],
    ['Plugin File Editor', 'edit_plugins', 'plugin-editor.php'],
    ['Fusion Plugins', 'install_plugins', 'fusion-plugins'],
    ['Techn Plugins', 'update_plugins', 'tnuc'],
];
fpl_order_plugin_menu();
if (array_column($submenu['plugins.php'], 2) !== ['tnuc', 'asuc', 'fusion-plugins', 'plugins.php', 'plugin-install.php', 'plugin-editor.php'] || $submenu['plugins.php'][3][0] !== 'Other Plugins') {
    throw new RuntimeException('The Plugins submenu order is incorrect.');
}

$mirror = json_decode(file_get_contents(dirname(__DIR__) . '/fusion-index-v3.json'), true);
$validated = fpl_validate_mirror($mirror);
if (is_wp_error($validated) || count($validated['packages']) < 14) { throw new RuntimeException('The published mirror index did not validate.'); }
$GLOBALS['stub_options']['fpl_mirror'] = $validated;

$_GET['tab'] = 'catalogue';
ob_start();
fpl_render_admin();
$html = ob_get_clean();
foreach (['Fusion Plugins', 'Updates available', 'Catalogue', 'Check for updates', 'CMS Tree Page View', 'Advanced Custom Fields PRO', 'Font Awesome', 'Gravity Forms Stripe Add-On', 'Query Monitor', 'Search Regex', 'fpl-catalog'] as $text) {
    if (strpos($html, $text) === false) { throw new RuntimeException('Missing admin UI text: ' . $text); }
}
if (strpos($html, 'id="fpl-view"') === false || strpos($html, '<dialog id="fpl-dialog"') === false || strpos($html, 'aria-labelledby="fpl-dialog-title"') === false || strpos($html, 'id="fpl-feedback"') !== false) {
    throw new RuntimeException('The Fusion progress modal is missing or inline feedback remains.');
}
$view = fpl_dispatch('view', ['tab'=>'catalogue']);
if (is_wp_error($view) || strpos($view['html'], 'id="fpl-view"') === false) { throw new RuntimeException('The catalog view could not refresh inside the modal flow.'); }
if (substr_count($html, 'class="fpl-card"') !== 25 || substr_count($html, 'class="nav-tab ') !== 2 || strpos($html, '>Settings</a>') !== false || strpos($html, '>Catalogs</a>') !== false || strpos($html, 'Techn /') !== false || strpos($html, 'AlphaSys') !== false) {
    throw new RuntimeException('Unexpected catalog or branding output.');
}
$_GET['tab'] = 'installed';
ob_start();
fpl_render_admin();
$html = ob_get_clean();
if (strpos($html, 'No updates are available for plugins in this catalog.') === false) {
    throw new RuntimeException('The updates tab did not show its empty state.');
}
$GLOBALS['stub_plugins'] = ['cms-tree-page-view/cms-tree-page-view.php'=>['Name'=>'CMS Tree Page View','Version'=>'2.6.0']];
ob_start();
fpl_render_admin();
$html = ob_get_clean();
if (strpos($html, 'name="fpl-selected"') === false || strpos($html, '<th scope="col">Available</th>') === false || strpos($html, '<th scope="col">Actions</th>') !== false || strpos($html, '<th scope="col">Mirror</th>') !== false || strpos($html, 'Update selected plugins') === false) {
    throw new RuntimeException('The mirrored update was not selectable.');
}
$GLOBALS['stub_plugins'] = ['fusion-plugins/fusion-plugins.php'=>['Name'=>'Fusion Plugins','Version'=>'0.2.2']];
ob_start();
fpl_render_admin();
$html = ob_get_clean();
$self_version = $validated['packages']['fusion-plugins']['latest'];
if (strpos($html, 'name="fpl-selected" value="fusion-plugins"') === false || strpos($html, '/releases/tag/v' . $self_version) === false) {
    throw new RuntimeException('The Fusion controller update was not shown.');
}
$GLOBALS['stub_plugins'] = ['gravityforms/gravityforms.php'=>['Name'=>'Gravity Forms','Version'=>'2.0.0']];
$GLOBALS['stub_site_transient']['update_plugins'] = (object) ['response'=>[
    'gravityforms/gravityforms.php'=>(object) ['new_version'=>'2.1.0']
]];
ob_start();
fpl_render_admin();
$html = ob_get_clean();
if (strpos($html, '<th scope="col">Actions</th>') === false || strpos($html, '<td>2.1.0</td><td><a href="/wp-admin/plugins.php">Update in WordPress Plugins</a>') === false || strpos($html, 'WordPress update available · Not in Fusion mirror yet') !== false || strpos($html, 'name="fpl-selected" value="gravityforms"') !== false) {
    throw new RuntimeException('The unmirrored vendor update was not shown as a WordPress update.');
}
$GLOBALS['stub_plugins']['cms-tree-page-view/cms-tree-page-view.php'] = ['Name'=>'CMS Tree Page View','Version'=>'2.6.0'];
ob_start();
fpl_render_admin();
$mixed = ob_get_clean();
if (strpos($mixed, 'name="fpl-selected" value="cms-tree-page-view"') === false || substr_count($mixed, 'Update in WordPress Plugins') !== 1 || substr_count($mixed, '<th scope="col">Actions</th>') !== 1) {
    throw new RuntimeException('The mixed mirror and WordPress update table is incorrect.');
}
unset($GLOBALS['stub_plugins']['cms-tree-page-view/cms-tree-page-view.php']);
$_GET['tab'] = 'catalogue';
ob_start();
fpl_render_admin();
$html = ob_get_clean();
if (strpos($html, 'WordPress update 2.1.0') === false || strpos($html, 'Not in Fusion mirror yet') !== false) { throw new RuntimeException('The catalog card did not show the native update concisely.'); }
$vendor_feed = ['schema'=>1,'packages'=>['gravityforms'=>['latest'=>'2.1.0','versions'=>['2.1.0'=>['sha256'=>str_repeat('a',64),'url'=>'https://github.com/cchatterton/fusion-controller/releases/download/mirror-gravityforms-v2.1.0/gravityforms-2.1.0.zip','root'=>'gravityforms']]]]];
$vendor_validated = fpl_validate_mirror($vendor_feed);
if (is_wp_error($vendor_validated)) { throw new RuntimeException('The vendor mirror package was rejected.'); }
$vendor_feed['packages']['gravityforms']['versions']['2.1.0']['root'] = '../other';
if (!is_wp_error(fpl_validate_mirror($vendor_feed))) { throw new RuntimeException('An unsafe vendor package directory was accepted.'); }
$GLOBALS['stub_options']['fpl_mirror']['packages']['gravityforms'] = $vendor_validated['packages']['gravityforms'];
$_GET['tab'] = 'installed';
ob_start();
fpl_render_admin();
$html = ob_get_clean();
if (strpos($html, 'name="fpl-selected" value="gravityforms"') === false || strpos($html, '<th scope="col">Actions</th>') !== false) {
    throw new RuntimeException('The mirrored vendor update did not take precedence.');
}
echo "Admin smoke test passed.\n";
