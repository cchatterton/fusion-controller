<?php
if (!defined('ABSPATH')) { exit; }

function fpl_can_manage(): bool {
    return current_user_can('manage_options') && current_user_can('install_plugins');
}

function fpl_notice(string $message, bool $error = false): void {
    set_transient('fpl_notice_' . get_current_user_id(), ['message' => $message, 'error' => $error], 120);
}

function fpl_validate_approved($candidate) {
    if (!is_array($candidate) || ($candidate['schema'] ?? null) !== 1 || !is_array($candidate['catalogs'] ?? null)) {
        return new WP_Error('fusion_feed', 'The Fusion approval file is invalid.');
    }
    $result = ['schema' => 1, 'catalogs' => []];
    foreach ($candidate['catalogs'] as $year => $entries) {
        $catalog = fpl_catalog((string) $year);
        if (!$catalog || !is_array($entries)) { return new WP_Error('fusion_catalog', 'The approval file refers to an unknown catalog.'); }
        $result['catalogs'][$year] = [];
        foreach ($entries as $id => $release) {
            $plugin = fpl_plugin_by_id($catalog, (string) $id);
            if (!$plugin || !is_array($release) || empty($plugin['slug'])) { return new WP_Error('fusion_plugin', 'The approval file contains an unsupported plugin.'); }
            if (!preg_match('/^\d+\.\d+(?:\.\d+){0,2}$/D', (string) ($release['version'] ?? '')) || !preg_match('/^[a-f0-9]{64}$/D', (string) ($release['sha256'] ?? ''))) {
                return new WP_Error('fusion_release', 'The approval file contains invalid release metadata.');
            }
            $url = (string) ($release['url'] ?? '');
            $prefix = 'https://github.com/cchatterton/fusion-controller/releases/download/';
            $expected = $prefix . 'mirror-' . $plugin['slug'] . '-v' . $release['version'] . '/' . $plugin['slug'] . '-' . $release['version'] . '.zip';
            if ($url !== $expected) {
                return new WP_Error('fusion_url', 'The approval file contains an invalid package URL.');
            }
            $result['catalogs'][$year][$id] = ['version' => $release['version'], 'sha256' => $release['sha256'], 'url' => $url];
        }
    }
    return $result;
}

function fpl_refresh_approved() {
    $url = add_query_arg('check', wp_generate_uuid4(), FPL_APPROVED_URL);
    $response = wp_safe_remote_get($url, ['timeout' => 10, 'redirection' => 0, 'limit_response_size' => 262144, 'headers' => ['Accept' => 'application/json', 'Cache-Control' => 'no-cache']]);
    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) { return new WP_Error('fusion_download', 'Fusion approvals could not be checked. The previous result is retained.'); }
    $validated = fpl_validate_approved(json_decode((string) wp_remote_retrieve_body($response), true));
    if (is_wp_error($validated)) { return $validated; }
    if (is_multisite()) { update_site_option('fpl_approved', $validated); update_site_option('fpl_checked_at', time()); }
    else { update_option('fpl_approved', $validated, false); update_option('fpl_checked_at', time(), false); }
    return true;
}

function fpl_install_approved(string $year, string $id) {
    if (!wp_is_file_mod_allowed('fusion-plugins')) { return new WP_Error('fusion_files', 'Plugin file changes are disabled on this site.'); }
    $catalog = fpl_catalog($year);
    $entry = fpl_plugin_by_id($catalog, $id);
    $release = fpl_approved_release($year, $id);
    if (!$entry || !$release || empty($entry['slug'])) { return new WP_Error('fusion_missing', 'This plugin has no approved mirrored release.'); }
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    if (get_filesystem_method() !== 'direct') { return new WP_Error('fusion_filesystem', 'Use the WordPress plugin installer on sites that require filesystem credentials.'); }
    $installed = fpl_installed_plugin($entry, get_plugins());
    if ($installed && version_compare((string) ($installed['data']['Version'] ?? '0'), $release['version'], '>=')) { return new WP_Error('fusion_current', 'The installed version is already current or newer.'); }
    $download = download_url($release['url'], 120);
    if (is_wp_error($download)) { return $download; }
    try {
        if (filesize($download) > 104857600 || !hash_equals($release['sha256'], hash_file('sha256', $download))) { return new WP_Error('fusion_checksum', 'The downloaded package failed verification.'); }
        if (!class_exists('ZipArchive')) { return new WP_Error('fusion_zip', 'ZIP support is unavailable on this server.'); }
        $zip = new ZipArchive();
        if ($zip->open($download) !== true) { return new WP_Error('fusion_zip', 'The downloaded package is not a valid ZIP.'); }
        $root = $entry['slug'] . '/';
        $valid = $zip->numFiles > 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (strpos($name, $root) !== 0 || strpos($name, '..') !== false || strpos($name, '\\') !== false) { $valid = false; break; }
        }
        $zip->close();
        if (!$valid) { return new WP_Error('fusion_contents', 'The package has an unexpected plugin directory.'); }
        $upgrader = new Plugin_Upgrader(new Automatic_Upgrader_Skin());
        $result = $upgrader->install($download, ['overwrite_package' => (bool) $installed]);
        if (is_wp_error($result) || !$result) { return is_wp_error($result) ? $result : new WP_Error('fusion_install', 'WordPress could not install the package.'); }
        wp_clean_plugins_cache(false);
        $after = fpl_installed_plugin($entry, get_plugins());
        if (!$after || ($after['data']['Version'] ?? '') !== $release['version']) { return new WP_Error('fusion_verify', 'WordPress could not verify the installed version. Check the Plugins screen.'); }
        return true;
    } finally { @unlink($download); }
}

function fpl_handle_action(): void {
    if (!fpl_can_manage()) { wp_die('You cannot manage Fusion plugins.'); }
    check_admin_referer('fpl_action');
    $action = sanitize_key(wp_unslash($_POST['operation'] ?? ''));
    $year = sanitize_text_field(wp_unslash($_POST['year'] ?? fpl_selected_year()));
    if ($action === 'select_catalog' && isset(fpl_catalogs()[$year])) {
        if (is_multisite()) { update_site_option('fpl_selected_catalog', $year); }
        else { update_option('fpl_selected_catalog', $year, false); }
        fpl_notice('Fusion ' . $year . ' selected.');
    } elseif ($action === 'refresh') {
        $result = fpl_refresh_approved();
        fpl_notice(is_wp_error($result) ? $result->get_error_message() : 'Fusion approvals checked.', is_wp_error($result));
    } elseif ($action === 'install_approved' && isset(fpl_catalogs()[$year])) {
        $id = sanitize_key(wp_unslash($_POST['plugin_id'] ?? ''));
        $result = fpl_install_approved($year, $id);
        fpl_notice(is_wp_error($result) ? $result->get_error_message() : 'Approved plugin package installed. New installs remain inactive until you activate them.', is_wp_error($result));
    } else { fpl_notice('Unknown Fusion action.', true); }
    $tab = $action === 'select_catalog' ? 'catalogs' : 'catalogue';
    wp_safe_redirect(fpl_admin_url($tab));
    exit;
}
