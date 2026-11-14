<?php
if (!defined('ABSPATH')) { exit; }

function fpl_can_manage(): bool { return current_user_can('manage_options') && current_user_can('install_plugins'); }
function fpl_notice(string $message, bool $error = false): void { set_transient('fpl_notice_' . get_current_user_id(), ['message'=>$message, 'error'=>$error], 120); }
function fpl_valid_version(string $version): bool { return (bool) preg_match('/^\d+\.\d+(?:\.\d+){0,3}$/D', $version); }

function fpl_validate_mirror($candidate) {
    if (!is_array($candidate) || ($candidate['schema'] ?? null) !== 1 || !is_array($candidate['packages'] ?? null) || count($candidate['packages']) > 200) { return new WP_Error('fusion_feed', 'The Fusion mirror index is invalid.'); }
    $known = [];
    foreach (fpl_catalogs() as $year => $meta) {
        foreach (fpl_catalog($year)['plugins'] ?? [] as $entry) { if (($entry['source'] ?? '') === 'wordpress.org' && !empty($entry['slug'])) { $known[$entry['slug']] = true; } }
    }
    $result = ['schema'=>1, 'packages'=>[]];
    foreach ($candidate['packages'] as $slug => $package) {
        if (!isset($known[$slug]) || !is_array($package) || !is_array($package['versions'] ?? null) || count($package['versions']) > 100 || !fpl_valid_version((string) ($package['latest'] ?? ''))) { return new WP_Error('fusion_identity', 'The mirror index contains an unknown or invalid plugin.'); }
        $latest = $package['latest'];
        if (!isset($package['versions'][$latest])) { return new WP_Error('fusion_latest', 'The mirror index has no package for its latest version.'); }
        $versions = [];
        foreach ($package['versions'] as $version => $release) {
            if (!fpl_valid_version((string) $version) || !is_array($release) || !preg_match('/^[a-f0-9]{64}$/D', (string) ($release['sha256'] ?? ''))) { return new WP_Error('fusion_release', 'The mirror index contains invalid release metadata.'); }
            $url = 'https://github.com/cchatterton/fusion-controller/releases/download/mirror-' . $slug . '-v' . $version . '/' . $slug . '-' . $version . '.zip';
            if (($release['url'] ?? '') !== $url) { return new WP_Error('fusion_url', 'The mirror index contains an invalid package URL.'); }
            $versions[$version] = ['sha256'=>$release['sha256'], 'url'=>$url];
            foreach (['requires','requires_php'] as $key) {
                if (isset($release[$key]) && $release[$key] !== '') {
                    if (!preg_match('/^\d+(?:\.\d+){0,3}$/D', (string) $release[$key])) { return new WP_Error('fusion_requirements', 'The mirror index contains invalid requirements.'); }
                    $versions[$version][$key] = $release[$key];
                }
            }
        }
        $result['packages'][$slug] = ['latest'=>$latest, 'versions'=>$versions];
    }
    return $result;
}

function fpl_refresh_mirror() {
    $url = add_query_arg('check', wp_generate_uuid4(), FPL_MIRROR_URL);
    $response = wp_safe_remote_get($url, ['timeout'=>10, 'redirection'=>0, 'limit_response_size'=>1048576, 'headers'=>['Accept'=>'application/json','Cache-Control'=>'no-cache']]);
    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) { return new WP_Error('fusion_download', 'The Fusion mirror could not be checked. Previous results are retained.'); }
    $validated = fpl_validate_mirror(json_decode((string) wp_remote_retrieve_body($response), true));
    if (is_wp_error($validated)) { return $validated; }
    if (is_multisite()) { update_site_option('fpl_mirror', $validated); update_site_option('fpl_checked_at', time()); }
    else { update_option('fpl_mirror', $validated, false); update_option('fpl_checked_at', time(), false); }
    return ['message'=>'Fusion mirror checked. Available plugins and updates have been refreshed.'];
}

function fpl_compatibility(array $entry, array $release): string {
    global $wp_version;
    $php = (string) ($release['requires_php'] ?? $entry['requires_php'] ?? '');
    $wp = (string) ($release['requires'] ?? $entry['requires'] ?? '');
    if ($php !== '' && version_compare(PHP_VERSION, $php, '<')) { return 'Requires PHP ' . $php . '+'; }
    if ($wp !== '' && version_compare((string) $wp_version, $wp, '<')) { return 'Requires WordPress ' . $wp . '+'; }
    return '';
}

function fpl_install_mirrored(array $entry, array $expected) {
    if (!wp_is_file_mod_allowed('fusion-plugins')) { return new WP_Error('fusion_files', 'Plugin file changes are disabled on this site.'); }
    $release = fpl_latest_release($entry);
    if (!$release || $release['version'] !== ($expected['version'] ?? '') || $release['sha256'] !== ($expected['sha256'] ?? '')) { return new WP_Error('fusion_changed', 'The mirror changed. Check again before installing this plugin.'); }
    $issue = fpl_compatibility($entry, $release);
    if ($issue) { return new WP_Error('fusion_compatibility', $issue); }
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    if (get_filesystem_method() !== 'direct') { return new WP_Error('fusion_filesystem', 'This site requires filesystem credentials. Use the WordPress plugin uploader.'); }
    $installed = fpl_installed_plugin($entry, get_plugins());
    if ($installed && version_compare((string) ($installed['data']['Version'] ?? '0'), $release['version'], '>=')) { return new WP_Error('fusion_current', 'The installed version is already current or newer.'); }
    $download = download_url($release['url'], 120);
    if (is_wp_error($download)) { return $download; }
    try {
        $size = filesize($download);
        if (!$size || $size > 104857600 || !hash_equals($release['sha256'], (string) hash_file('sha256', $download))) { return new WP_Error('fusion_checksum', 'The mirrored package failed verification.'); }
        if (!class_exists('ZipArchive')) { return new WP_Error('fusion_zip', 'ZIP support is unavailable on this server.'); }
        $zip = new ZipArchive();
        if ($zip->open($download) !== true) { return new WP_Error('fusion_zip', 'The mirrored package is not a valid ZIP.'); }
        $root = $entry['slug'] . '/'; $valid = $zip->numFiles > 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (strpos($name, $root) !== 0 || strpos($name, '..') !== false || strpos($name, '\\') !== false) { $valid = false; break; }
        }
        $zip->close();
        if (!$valid) { return new WP_Error('fusion_contents', 'The mirrored package has an unexpected plugin directory.'); }
        $result = (new Plugin_Upgrader(new Automatic_Upgrader_Skin()))->install($download, ['overwrite_package'=>(bool) $installed]);
        if (is_wp_error($result) || !$result) { return is_wp_error($result) ? $result : new WP_Error('fusion_install', 'WordPress could not install the package.'); }
        wp_clean_plugins_cache(false);
        $after = fpl_installed_plugin($entry, get_plugins());
        if (!$after || ($after['data']['Version'] ?? '') !== $release['version']) { return new WP_Error('fusion_verify', 'The installed version could not be verified. Check the Plugins screen.'); }
        return true;
    } finally { @unlink($download); }
}

function fpl_batch(): array { $job = is_multisite() ? get_site_option('fpl_batch', []) : get_option('fpl_batch', []); return is_array($job) ? $job : []; }
function fpl_store_batch(array $job): void { if (is_multisite()) { update_site_option('fpl_batch', $job); } else { update_option('fpl_batch', $job, false); } }

function fpl_start_batch(array $ids, string $kind, string $year) {
    if (!in_array($kind, ['install','update'], true) || !isset(fpl_catalogs()[$year])) { return new WP_Error('fusion_batch', 'Invalid catalog or operation.'); }
    if (!current_user_can($kind === 'install' ? 'install_plugins' : 'update_plugins') || !wp_is_file_mod_allowed('fusion-plugins')) { return new WP_Error('fusion_permission', 'You cannot change plugins on this site.'); }
    $previous = fpl_batch();
    if (($previous['status'] ?? '') === 'running' && ($previous['updated_at'] ?? 0) > time() - HOUR_IN_SECONDS) { return new WP_Error('fusion_running', 'Finish or resume the existing batch first.'); }
    $ids = array_values(array_unique(array_map('sanitize_key', $ids)));
    if (!$ids || count($ids) > 50) { return new WP_Error('fusion_selection', 'Select at least one eligible plugin.'); }
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    $installed = get_plugins(); $catalog = fpl_catalog($year); $items = [];
    foreach ($ids as $id) {
        $entry = fpl_plugin_by_id($catalog, $id); $release = fpl_latest_release($entry);
        if (!$entry || !$release || empty($entry['slug'])) { return new WP_Error('fusion_release', 'A selected plugin has no mirrored release.'); }
        $issue = fpl_compatibility($entry, $release);
        if ($issue) { return new WP_Error('fusion_compatibility', $entry['name'] . ': ' . $issue); }
        $match = fpl_installed_plugin($entry, $installed);
        if ($kind === 'install' && $match) { return new WP_Error('fusion_installed', $entry['name'] . ' is already installed.'); }
        if ($kind === 'update' && (!$match || !version_compare($release['version'], (string) ($match['data']['Version'] ?? '0'), '>'))) { return new WP_Error('fusion_current', $entry['name'] . ' has no mirrored update.'); }
        $items[] = ['id'=>$id, 'version'=>$release['version'], 'sha256'=>$release['sha256'], 'status'=>'pending'];
    }
    $job = ['id'=>wp_generate_uuid4(), 'owner'=>get_current_user_id(), 'kind'=>$kind, 'year'=>$year, 'items'=>$items, 'status'=>'running', 'updated_at'=>time()];
    fpl_store_batch($job);
    return $job;
}

function fpl_step_batch(string $id) {
    $job = fpl_batch();
    if (($job['id'] ?? '') !== $id || ($job['owner'] ?? 0) !== get_current_user_id() || ($job['status'] ?? '') !== 'running') { return new WP_Error('fusion_batch', 'This update batch is unavailable.'); }
    if (!current_user_can($job['kind'] === 'install' ? 'install_plugins' : 'update_plugins')) { return new WP_Error('fusion_permission', 'You cannot change plugins on this site.'); }
    foreach ($job['items'] as $index => $item) {
        if (!in_array($item['status'], ['pending','working'], true)) { continue; }
        $entry = fpl_plugin_by_id(fpl_catalog($job['year']), $item['id']);
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $match = $entry ? fpl_installed_plugin($entry, get_plugins()) : [];
        if ($item['status'] === 'working' && $match && version_compare((string) ($match['data']['Version'] ?? '0'), $item['version'], '>=')) {
            $result = true;
        } else {
            $job['items'][$index]['status'] = 'working'; $job['updated_at'] = time(); fpl_store_batch($job);
            try { $result = $entry ? fpl_install_mirrored($entry, $item) : new WP_Error('fusion_catalog', 'The selected catalog changed.'); }
            catch (Throwable $error) { $result = new WP_Error('fusion_install', $error->getMessage()); }
        }
        $job['items'][$index]['status'] = is_wp_error($result) ? 'failed' : 'success';
        $job['items'][$index]['message'] = is_wp_error($result) ? $result->get_error_message() : 'Installed ' . $item['version'] . '.';
        break;
    }
    $pending = array_filter($job['items'], static function ($item) { return in_array($item['status'], ['pending','working'], true); });
    $failed = array_filter($job['items'], static function ($item) { return $item['status'] === 'failed'; });
    $job['status'] = $pending ? 'running' : ($failed ? 'partial' : 'complete'); $job['updated_at'] = time(); fpl_store_batch($job);
    return $job;
}

function fpl_dispatch(string $operation, array $input) {
    if (!fpl_can_manage()) { return new WP_Error('fusion_permission', 'You cannot manage Fusion plugins.'); }
    if ($operation === 'view') {
        $previous = $_GET['tab'] ?? null;
        $_GET['tab'] = sanitize_key($input['tab'] ?? 'installed');
        ob_start();
        try { fpl_render_admin(); $html = ob_get_contents(); }
        finally { ob_end_clean(); if ($previous === null) { unset($_GET['tab']); } else { $_GET['tab'] = $previous; } }
        return ['html'=>$html];
    }
    if ($operation === 'check') { return fpl_refresh_mirror(); }
    if ($operation === 'start') { return fpl_start_batch((array) ($input['ids'] ?? []), sanitize_key($input['kind'] ?? ''), sanitize_text_field($input['year'] ?? fpl_selected_year())); }
    if ($operation === 'step') { return fpl_step_batch(sanitize_text_field($input['job'] ?? '')); }
    if ($operation === 'status') { return ['batch'=>fpl_batch()]; }
    return new WP_Error('fusion_action', 'Unknown Fusion action.');
}
function fpl_clean_ajax_output(): void {
    if (!defined('FPL_AJAX_BUFFER_LEVEL')) { return; }
    while (ob_get_level() >= FPL_AJAX_BUFFER_LEVEL) { ob_end_clean(); }
}
function fpl_handle_ajax(): void {
    if (!check_ajax_referer('fpl_action', 'nonce', false)) { fpl_clean_ajax_output(); wp_send_json_error(['message'=>'Your session expired. Reload this page and try again.'], 403); }
    $input = wp_unslash($_POST); $result = fpl_dispatch(sanitize_key($input['operation'] ?? ''), $input);
    fpl_clean_ajax_output();
    if (is_wp_error($result)) { wp_send_json_error(['message'=>$result->get_error_message()], 400); }
    wp_send_json_success($result);
}
function fpl_handle_action(): void {
    if (!fpl_can_manage()) { wp_die('You cannot manage Fusion plugins.'); }
    check_admin_referer('fpl_action');
    $operation = sanitize_key(wp_unslash($_POST['operation'] ?? ''));
    $year = sanitize_text_field(wp_unslash($_POST['year'] ?? fpl_selected_year()));
    if ($operation === 'select_catalog' && isset(fpl_catalogs()[$year])) {
        if (is_multisite()) { update_site_option('fpl_selected_catalog', $year); } else { update_option('fpl_selected_catalog', $year, false); }
        fpl_notice('Fusion ' . $year . ' selected.');
    } elseif ($operation === 'check') {
        $result = fpl_refresh_mirror(); fpl_notice(is_wp_error($result) ? $result->get_error_message() : $result['message'], is_wp_error($result));
    } else { fpl_notice('Unknown Fusion action.', true); }
    wp_safe_redirect(fpl_admin_url($operation === 'select_catalog' ? 'catalogue' : 'installed')); exit;
}
