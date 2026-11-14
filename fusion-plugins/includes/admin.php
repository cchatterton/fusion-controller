<?php
if (!defined('ABSPATH')) { exit; }

function fpl_admin_url(string $tab = 'installed'): string {
    $base = is_multisite() ? network_admin_url('plugins.php?page=fusion-plugins') : admin_url('plugins.php?page=fusion-plugins');
    return add_query_arg('tab', $tab, $base);
}
function fpl_add_menu(): void {
    if (is_multisite() && !is_network_admin()) { return; }
    add_submenu_page('plugins.php', 'Fusion Plugins', 'Fusion Plugins', 'install_plugins', 'fusion-plugins', 'fpl_render_admin');
}
function fpl_admin_assets(string $hook): void {
    if ($hook !== 'plugins_page_fusion-plugins') { return; }
    wp_enqueue_style('fpl-admin', plugins_url('assets/admin.css', FPL_FILE), [], FPL_VERSION);
    wp_enqueue_script('fpl-admin', plugins_url('assets/admin.js', FPL_FILE), [], FPL_VERSION, true);
    $names = [];
    foreach (fpl_catalog(fpl_selected_year())['plugins'] ?? [] as $entry) { $names[$entry['id']] = $entry['name']; }
    wp_localize_script('fpl-admin', 'fplAdmin', ['url'=>admin_url('admin-ajax.php'), 'nonce'=>wp_create_nonce('fpl_action'), 'year'=>fpl_selected_year(), 'names'=>$names]);
}
function fpl_action_form(string $operation, string $label, array $fields = []): void {
    echo '<form class="fpl-inline-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    echo '<input type="hidden" name="action" value="fpl_action"><input type="hidden" name="operation" value="' . esc_attr($operation) . '">';
    foreach ($fields as $name=>$value) { echo '<input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '">'; }
    wp_nonce_field('fpl_action');
    echo '<button type="submit" class="button">' . esc_html($label) . '</button></form>';
}
function fpl_last_check(): string {
    $checked = is_multisite() ? get_site_option('fpl_checked_at', 0) : get_option('fpl_checked_at', 0);
    return $checked ? 'Last checked ' . wp_date('j M Y, H:i', (int) $checked) : 'Never checked. Check for updates to load the mirror.';
}
function fpl_is_active(string $file): bool { return is_multisite() ? is_plugin_active_for_network($file) : is_plugin_active($file); }

function fpl_render_admin(): void {
    if (!fpl_can_manage()) { wp_die('You cannot manage Fusion plugins.'); }
    $tab = sanitize_key(wp_unslash($_GET['tab'] ?? 'installed'));
    if (!in_array($tab, ['installed','catalogue'], true)) { $tab = 'installed'; }
    $year = fpl_selected_year(); $catalog = fpl_catalog($year);
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    $installed = get_plugins(); $updates = 0; $installed_count = 0;
    foreach ($catalog['plugins'] ?? [] as $entry) {
        $match = fpl_installed_plugin($entry, $installed); $release = fpl_latest_release($entry);
        if ($match) { $installed_count++; }
        if ($match && $release && version_compare($release['version'], (string) ($match['data']['Version'] ?? '0'), '>')) { $updates++; }
    }
    $notice = get_transient('fpl_notice_' . get_current_user_id());
    if ($notice) { delete_transient('fpl_notice_' . get_current_user_id()); }
    echo '<div class="wrap fpl-wrap"><h1>Fusion Plugins</h1><div id="fpl-view">';
    if (is_array($notice)) { echo '<div class="notice ' . (!empty($notice['error']) ? 'notice-error' : 'notice-success') . ' is-dismissible"><p>' . esc_html($notice['message'] ?? '') . '</p></div>'; }
    echo '<header class="fpl-header"><span class="fpl-version">v' . esc_html(FPL_VERSION) . '</span><p class="fpl-eyebrow">Fusion ' . esc_html($year) . '</p><h2>One considered choice for every job.</h2><p>Curated plugins, ready from the Fusion mirror.</p><div class="fpl-header-bottom"><span>' . esc_html($installed_count) . ' installed · ' . esc_html($updates) . ' updates available</span><button type="button" class="button fpl-primary" data-fpl-check>Check for updates</button></div></header>';
    echo '<div class="fpl-status"><span>' . esc_html(fpl_last_check()) . '</span><span>Manual checks only</span></div>';
    echo '<nav class="nav-tab-wrapper" aria-label="Fusion Plugins">';
    foreach (['installed'=>'Updates available','catalogue'=>'Catalogue'] as $key=>$label) { echo '<a class="nav-tab ' . ($key === $tab ? 'nav-tab-active' : '') . '" href="' . esc_url(fpl_admin_url($key)) . '">' . esc_html($label) . '</a>'; }
    echo '</nav><main class="fpl-main">';
    $batch = fpl_batch();
    if (($batch['status'] ?? '') === 'running') { echo '<p><button type="button" class="button" data-fpl-resume="' . esc_attr($batch['id']) . '">Resume ' . esc_html($batch['kind']) . 's</button></p>'; }
    if ($tab === 'catalogue') { fpl_render_catalogue($catalog, $installed); }
    else { fpl_render_installed($catalog, $installed); }
    echo '<noscript><p>JavaScript is required for installing and updating from the Fusion mirror.</p>'; fpl_action_form('check', 'Check for updates'); echo '</noscript></main></div>';
    fpl_render_dialog();
    echo '</div>';
}

function fpl_render_dialog(): void {
    echo '<dialog id="fpl-dialog" class="fpl-dialog" aria-labelledby="fpl-dialog-title" aria-describedby="fpl-dialog-message"><h2 id="fpl-dialog-title" tabindex="-1">Checking for updates</h2><div class="fpl-activity"><span class="spinner is-active" aria-hidden="true"></span><p id="fpl-dialog-message" role="status" aria-live="polite"></p></div><div id="fpl-progress-area" hidden><progress id="fpl-progress" max="1" value="0" aria-label="Plugins processed"></progress><p id="fpl-current"></p></div><details id="fpl-failures" hidden><summary>Failed plugins</summary><ul></ul></details><div class="fpl-dialog-actions"><button type="button" class="button" id="fpl-retry" hidden>Retry</button><button type="button" class="button button-primary" id="fpl-close" disabled>Close</button></div></dialog>';
}

function fpl_release_url(array $entry, array $release): string {
    return 'https://github.com/cchatterton/fusion-controller/releases/tag/mirror-' . $entry['slug'] . '-v' . $release['version'];
}
function fpl_card_actions(array $entry, array $installed): void {
    $match = fpl_installed_plugin($entry, $installed); $release = fpl_latest_release($entry);
    $issue = $release ? fpl_compatibility($entry, $release) : '';
    if ($release && (!$match || version_compare($release['version'], (string) ($match['data']['Version'] ?? '0'), '>'))) {
        $kind = $match ? 'update' : 'install';
        echo '<button type="button" class="button button-primary" data-fpl-package="' . esc_attr($entry['id']) . '" data-fpl-kind="' . esc_attr($kind) . '"' . ($issue || !wp_is_file_mod_allowed('fusion-plugins') ? ' disabled' : '') . '>' . ($match ? 'Update' : 'Install') . '</button>';
    }
    if ($match) {
        $file = $match['file']; $active = fpl_is_active($file);
        $action = $active ? 'deactivate' : 'activate';
        $url = wp_nonce_url(add_query_arg(['action'=>$action,'plugin'=>$file,'networkwide'=>is_multisite() ? 1 : 0], self_admin_url('plugins.php')), $action . '-plugin_' . $file);
        echo '<a class="button" href="' . esc_url($url) . '">' . esc_html((is_multisite() ? 'Network ' : '') . ($active ? 'Deactivate' : 'Activate')) . '</a>';
    } elseif (($entry['source'] ?? '') === 'vendor') {
        echo '<a class="button" href="' . esc_url(self_admin_url('plugin-install.php?tab=upload')) . '">Upload licensed ZIP</a>';
    }
    if ($release) { echo '<a href="' . esc_url(fpl_release_url($entry, $release)) . '" target="_blank" rel="noopener noreferrer">Release notes<span class="screen-reader-text"> (opens in a new tab)</span></a>'; }
}
function fpl_render_catalogue(array $catalog, array $installed): void {
    $year = $catalog['year'] ?? fpl_selected_year();
    echo '<div class="fpl-catalog-toolbar"><div><h2>Fusion ' . esc_html($year) . ' catalogue</h2><p>One selected plugin for each function. New installs remain inactive.</p></div>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="fpl_action"><input type="hidden" name="operation" value="select_catalog">';
    wp_nonce_field('fpl_action');
    echo '<label for="fpl-catalog">Catalog</label> <select id="fpl-catalog" name="year">';
    foreach (fpl_catalogs() as $choice=>$meta) { echo '<option value="' . esc_attr($choice) . '"' . selected($year, $choice, false) . '>Fusion ' . esc_html($choice) . ' · ' . esc_html(ucwords(str_replace('_',' ',$meta['status']))) . '</option>'; }
    echo '</select> <button class="button" type="submit">Select</button></form></div>';
    $catalog_meta = fpl_catalogs()[$year] ?? [];
    if (!empty($catalog_meta['notice'])) { echo '<div class="notice notice-info"><p>' . esc_html($catalog_meta['notice']) . '</p></div>'; }
    $groups = ['active'=>[], 'installed'=>[], 'available'=>[], 'licensed'=>[]];
    foreach ($catalog['plugins'] ?? [] as $entry) {
        $match = fpl_installed_plugin($entry, $installed);
        $group = $match ? (fpl_is_active($match['file']) ? 'active' : 'installed') : (($entry['source'] ?? '') === 'vendor' ? 'licensed' : 'available');
        $groups[$group][] = $entry;
    }
    foreach (['active'=>'Active','installed'=>'Installed','available'=>'Available','licensed'=>'Licensed'] as $key=>$label) {
        if (!$groups[$key]) { continue; }
        echo '<section class="fpl-catalog-group"><h2>' . esc_html($label) . '</h2><div class="fpl-grid">';
        foreach ($groups[$key] as $entry) {
            $match = fpl_installed_plugin($entry, $installed); $release = fpl_latest_release($entry);
            $requirements = [];
            $wp = $release['requires'] ?? $entry['requires'] ?? ''; $php = $release['requires_php'] ?? $entry['requires_php'] ?? '';
            if ($wp) { $requirements[] = 'WordPress ' . $wp . '+'; }
            if ($php) { $requirements[] = 'PHP ' . $php . '+'; }
            echo '<article class="fpl-card"><div class="fpl-card-content"><p class="fpl-role">' . esc_html($entry['role']) . '</p><h3>' . esc_html($entry['name']) . '</h3><p class="fpl-description">' . esc_html($entry['description'] ?? '') . '</p>';
            $availability = ($entry['source'] ?? '') === 'vendor' ? 'Licensed vendor package' : ($release ? 'Latest ' . $release['version'] : 'Mirror not checked');
            echo '<p class="fpl-detail">' . ($match ? 'Installed ' . esc_html($match['data']['Version'] ?? '') : 'Not installed') . ' · ' . esc_html($availability) . '</p>';
            if ($requirements) { echo '<p class="fpl-detail">' . esc_html(implode(' · ', $requirements)) . '</p>'; }
            if (($entry['source'] ?? '') === 'vendor') { echo '<p class="fpl-detail">Licensed vendor package · Mirror source not configured</p>'; }
            if ($release && ($issue = fpl_compatibility($entry, $release))) { echo '<p class="fpl-warning">' . esc_html($issue) . '</p>'; }
            echo '<div class="fpl-actions">'; fpl_card_actions($entry, $installed); echo '</div></div></article>';
        }
        echo '</div></section>';
    }
}
function fpl_render_installed(array $catalog, array $installed): void {
    echo '<div class="fpl-toolbar"><h2>Updates available</h2><button type="button" class="button button-primary" id="fpl-update-selected" disabled>Update selected plugins</button></div>';
    echo '<div class="fpl-table-scroll"><table class="widefat striped"><thead><tr><td class="check-column"><input type="checkbox" id="fpl-select-all" aria-label="Select all eligible updates"></td><th scope="col">Plugin</th><th scope="col">Installed</th><th scope="col">Available</th></tr></thead><tbody>';
    $count = 0;
    foreach ($catalog['plugins'] ?? [] as $entry) {
        $match = fpl_installed_plugin($entry, $installed); $release = fpl_latest_release($entry);
        if (!$match || !$release || !version_compare($release['version'], (string) ($match['data']['Version'] ?? '0'), '>')) { continue; }
        $count++; $issue = fpl_compatibility($entry, $release); $disabled = $issue || !wp_is_file_mod_allowed('fusion-plugins');
        echo '<tr><th class="check-column"><input type="checkbox" name="fpl-selected" value="' . esc_attr($entry['id']) . '" aria-label="Update ' . esc_attr($entry['name']) . '"' . ($disabled ? ' disabled' : '') . '></th><td><strong>' . esc_html($entry['name']) . '</strong><br><span class="description">' . (fpl_is_active($match['file']) ? 'Active' : 'Inactive') . '</span></td><td>' . esc_html($match['data']['Version'] ?? '') . '</td><td><a href="' . esc_url(fpl_release_url($entry, $release)) . '" target="_blank" rel="noopener noreferrer">' . esc_html($release['version']) . '<span class="screen-reader-text"> release notes (opens in a new tab)</span></a>';
        if ($issue) { echo '<p class="fpl-warning">' . esc_html($issue) . '</p>'; }
        echo '</td></tr>';
    }
    if (!$count) { echo '<tr><td colspan="4">No mirrored updates are available for plugins in this catalog.</td></tr>'; }
    echo '</tbody></table></div>';
}
