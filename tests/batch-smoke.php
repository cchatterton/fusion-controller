<?php
error_reporting(E_ALL);
define('ABSPATH', __DIR__ . '/fixtures/');
define('HOUR_IN_SECONDS', 3600);
$wp_version = '7.0';
function add_action() {}
function is_multisite() { return false; }
function current_user_can() { return true; }
function wp_is_file_mod_allowed() { return true; }
function get_current_user_id() { return 7; }
function wp_generate_uuid4() { return 'test-batch-id'; }
function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
function update_option($key, $value) { $GLOBALS['options'][$key] = $value; return true; }
function get_plugins() { return $GLOBALS['plugins']; }
function sanitize_key($value) { return $value; }
function get_filesystem_method() { return 'direct'; }
function wp_clean_plugins_cache() {}
function download_url() { $target = tempnam(sys_get_temp_dir(), 'fpl-download-'); copy($GLOBALS['package'], $target); return $target; }
class WP_Error { public $message; public function __construct($code, $message) { $this->message = $message; } public function get_error_message() { return $this->message; } }
function is_wp_error($value) { return $value instanceof WP_Error; }
class Automatic_Upgrader_Skin {}
class Plugin_Upgrader {
    public function __construct($skin) {}
    public function install($package, $args) {
        if (empty($args['overwrite_package'])) { throw new RuntimeException('Expected an overwrite update.'); }
        $GLOBALS['plugins']['cms-tree-page-view/cms-tree-page-view.php']['Version'] = '2.7.0';
        return true;
    }
}
require dirname(__DIR__) . '/fusion-plugins/fusion-plugins.php';

$package = tempnam(sys_get_temp_dir(), 'fpl-package-');
$zip = new ZipArchive();
if ($zip->open($package, ZipArchive::OVERWRITE) !== true) { throw new RuntimeException('Could not create test package.'); }
$zip->addFromString('cms-tree-page-view/cms-tree-page-view.php', '<?php /* test package */');
$zip->close();
$GLOBALS['package'] = $package;
$GLOBALS['plugins'] = ['cms-tree-page-view/cms-tree-page-view.php'=>['Name'=>'CMS Tree Page View', 'Version'=>'2.6.0']];
$GLOBALS['options']['fpl_mirror'] = ['packages'=>['cms-tree-page-view'=>['latest'=>'2.7.0','versions'=>['2.7.0'=>['sha256'=>hash_file('sha256', $package),'url'=>'https://github.com/cchatterton/fusion-controller/releases/download/mirror-cms-tree-page-view-v2.7.0/cms-tree-page-view-2.7.0.zip']]]]];
try {
    $job = fpl_start_batch(['cms-tree-page-view'], 'update', '26');
    if (is_wp_error($job) || count($job['items']) !== 1) { throw new RuntimeException('Could not start selected update.'); }
    $job = fpl_step_batch($job['id']);
    if (is_wp_error($job) || $job['status'] !== 'complete' || $job['items'][0]['status'] !== 'success' || $GLOBALS['plugins']['cms-tree-page-view/cms-tree-page-view.php']['Version'] !== '2.7.0') {
        throw new RuntimeException('Mirrored update did not complete.');
    }
    $GLOBALS['plugins']['cms-tree-page-view/cms-tree-page-view.php']['Version'] = '2.6.0';
    $entry = fpl_plugin_by_id(fpl_catalog('26'), 'cms-tree-page-view');
    $GLOBALS['options']['fpl_mirror']['packages']['cms-tree-page-view']['versions']['2.7.0']['sha256'] = str_repeat('0', 64);
    $rejected = fpl_install_mirrored($entry, ['version'=>'2.7.0', 'sha256'=>str_repeat('0', 64)]);
    if (!is_wp_error($rejected) || $GLOBALS['plugins']['cms-tree-page-view/cms-tree-page-view.php']['Version'] !== '2.6.0') {
        throw new RuntimeException('A mismatched package checksum was not rejected.');
    }
    echo "Batch smoke test passed.\n";
} finally { @unlink($package); }
