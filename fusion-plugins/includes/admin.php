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
}

function fpl_action_form(string $operation, string $label, array $fields = [], string $class = 'button'): void {
    echo '<form class="fpl-inline-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    echo '<input type="hidden" name="action" value="fpl_action"><input type="hidden" name="operation" value="' . esc_attr($operation) . '">';
    foreach ($fields as $name => $value) { echo '<input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '">'; }
    wp_nonce_field('fpl_action');
    echo '<button type="submit" class="' . esc_attr($class) . '">' . esc_html($label) . '</button></form>';
}

function fpl_render_admin(): void {
    if (!fpl_can_manage()) { wp_die('You cannot manage Fusion plugins.'); }
    $tab = sanitize_key(wp_unslash($_GET['tab'] ?? 'installed'));
    if (!in_array($tab, ['installed', 'catalogue', 'settings', 'catalogs'], true)) { $tab = 'installed'; }
    $year = fpl_selected_year();
    $catalog = fpl_catalog($year);
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    $installed = get_plugins();
    $notice = get_transient('fpl_notice_' . get_current_user_id());
    if ($notice) { delete_transient('fpl_notice_' . get_current_user_id()); }
    echo '<div class="wrap fpl-wrap"><h1>Fusion Plugins</h1>';
    if (is_array($notice)) { echo '<div class="notice ' . (!empty($notice['error']) ? 'notice-error' : 'notice-success') . ' is-dismissible"><p>' . esc_html($notice['message'] ?? '') . '</p></div>'; }
    echo '<header class="fpl-header"><span class="fpl-version">v' . esc_html(FPL_VERSION) . '</span><p class="fpl-eyebrow">Fusion ' . esc_html($year) . '</p><h2>One considered choice for every job.</h2><p>Explore the selected catalog and install approved mirrored releases when available.</p></header>';
    $catalog_meta = fpl_catalogs()[$year] ?? [];
    if (!empty($catalog_meta['notice'])) { echo '<div class="notice notice-info fpl-catalog-notice"><p>' . esc_html($catalog_meta['notice']) . '</p></div>'; }
    echo '<nav class="nav-tab-wrapper" aria-label="Fusion Plugins">';
    foreach (['installed' => 'Updates available', 'catalogue' => 'Catalogue', 'settings' => 'Settings', 'catalogs' => 'Catalogs'] as $key => $label) {
        echo '<a class="nav-tab ' . ($key === $tab ? 'nav-tab-active' : '') . '" href="' . esc_url(fpl_admin_url($key)) . '">' . esc_html($label) . '</a>';
    }
    echo '</nav><main class="fpl-main">';
    if ($tab === 'catalogue') { fpl_render_catalogue($catalog, $installed); }
    elseif ($tab === 'settings') { fpl_render_settings(); }
    elseif ($tab === 'catalogs') { fpl_render_catalogs($year); }
    else { fpl_render_installed($catalog, $installed); }
    echo '</main></div>';
}

function fpl_render_release_action(array $entry, string $year, array $installed): void {
    $release = fpl_approved_release($year, $entry['id']);
    $match = fpl_installed_plugin($entry, $installed);
    if ($release && (!$match || version_compare((string) ($match['data']['Version'] ?? '0'), $release['version'], '<'))) {
        fpl_action_form('install_approved', $match ? 'Install approved update' : 'Install approved release', ['year' => $year, 'plugin_id' => $entry['id']], 'button button-primary');
    }
    if (($entry['source'] ?? '') === 'wordpress.org') {
        $url = wp_nonce_url(self_admin_url('update.php?action=install-plugin&plugin=' . rawurlencode($entry['slug'])), 'install-plugin_' . $entry['slug']);
        if (!$match) { echo '<a class="button" href="' . esc_url($url) . '">Install from WordPress.org</a>'; }
        else { echo '<a href="' . esc_url(self_admin_url('plugins.php')) . '">Manage in WordPress</a>'; }
    } elseif (!$match) {
        echo '<a class="button" href="' . esc_url(self_admin_url('plugin-install.php?tab=upload')) . '">Upload licensed ZIP</a>';
    }
}

function fpl_render_catalogue(array $catalog, array $installed): void {
    $year = $catalog['year'] ?? fpl_selected_year();
    echo '<div class="fpl-section-heading"><div><h2>Fusion ' . esc_html($year) . ' catalogue</h2><p>One selected plugin for each function. Installing a plugin does not activate it.</p></div>';
    fpl_action_form('refresh', 'Check Fusion approvals');
    echo '</div><div class="fpl-grid">';
    foreach ($catalog['plugins'] ?? [] as $entry) {
        if (!is_array($entry)) { continue; }
        $match = fpl_installed_plugin($entry, $installed);
        $release = fpl_approved_release($year, $entry['id']);
        echo '<article class="fpl-card"><p class="fpl-role">' . esc_html($entry['role']) . '</p><h3>' . esc_html($entry['name']) . '</h3>';
        echo '<p class="fpl-status">' . ($match ? 'Installed ' . esc_html($match['data']['Version'] ?? '') : 'Not installed') . ' · ' . esc_html(($entry['source'] ?? '') === 'wordpress.org' ? 'WordPress.org' : 'Licensed vendor') . '</p>';
        if ($release) { echo '<p class="fpl-approved">Fusion approved: ' . esc_html($release['version']) . '</p>'; }
        echo '<div class="fpl-actions">';
        fpl_render_release_action($entry, $year, $installed);
        echo '</div></article>';
    }
    echo '</div>';
}

function fpl_render_installed(array $catalog, array $installed): void {
    $year = $catalog['year'] ?? fpl_selected_year();
    $updates = get_site_transient('update_plugins');
    echo '<div class="fpl-section-heading"><div><h2>Updates available · Fusion ' . esc_html($year) . '</h2><p>WordPress updates continue to work normally. Fusion approvals offer an additional manual installation option.</p></div>';
    fpl_action_form('refresh', 'Check Fusion approvals');
    echo '</div><div class="fpl-table-scroll"><table class="widefat striped"><thead><tr><th scope="col">Plugin</th><th scope="col">Installed</th><th scope="col">Fusion approved</th><th scope="col">WordPress update</th><th scope="col">Action</th></tr></thead><tbody>';
    $count = 0;
    foreach ($catalog['plugins'] ?? [] as $entry) {
        if (!is_array($entry)) { continue; }
        $match = fpl_installed_plugin($entry, $installed);
        if (!$match) { continue; }
        $release = fpl_approved_release($year, $entry['id']);
        $native = is_object($updates) ? ($updates->response[$match['file']] ?? null) : null;
        $fusion_update = $release && version_compare((string) ($match['data']['Version'] ?? '0'), $release['version'], '<');
        if (!$fusion_update && !is_object($native)) { continue; }
        $count++;
        echo '<tr><th scope="row">' . esc_html($entry['name']) . '</th><td>' . esc_html($match['data']['Version'] ?? '—') . '</td><td>' . esc_html($release['version'] ?? '—') . '</td><td>' . esc_html(is_object($native) ? 'Available ' . ($native->new_version ?? '') : 'No update shown') . '</td><td>';
        fpl_render_release_action($entry, $year, $installed);
        echo '</td></tr>';
    }
    if (!$count) { echo '<tr><td colspan="5">No updates are available for plugins in this catalog.</td></tr>'; }
    echo '</tbody></table></div>';
}

function fpl_render_settings(): void {
    $checked = is_multisite() ? get_site_option('fpl_checked_at', 0) : get_option('fpl_checked_at', 0);
    echo '<h2>How Fusion works</h2><p>Fusion publishes a curated catalog for each year. The current catalog is selected by default. You can choose another catalog under Catalogs.</p>';
    echo '<p>Fusion mirrors public WordPress.org packages and records approved versions separately. Checking Fusion approvals is manual on this site. WordPress native plugin updates remain unchanged.</p>';
    echo '<p>Licensed vendor packages are listed for catalog guidance. Install them through your licensed vendor account until an authorised mirror source is configured.</p>';
    echo '<p>' . ($checked ? 'Fusion approvals last checked ' . esc_html(wp_date('j M Y, H:i', (int) $checked)) . '.' : 'Fusion approvals have not been checked on this site.') . '</p>';
    fpl_action_form('refresh', 'Check Fusion approvals');
}

function fpl_render_catalogs(string $selected): void {
    echo '<h2>Catalogs</h2><p>Choose the catalog to browse and use on this site. The most current catalog is the default.</p>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="fpl_action"><input type="hidden" name="operation" value="select_catalog">';
    wp_nonce_field('fpl_action');
    echo '<label for="fpl-catalog">Selected catalog</label> <select id="fpl-catalog" name="year">';
    foreach (fpl_catalogs() as $year => $meta) { echo '<option value="' . esc_attr($year) . '"' . selected($selected, $year, false) . '>Fusion ' . esc_html($year) . ' · ' . esc_html(ucwords(str_replace('_', ' ', $meta['status']))) . '</option>'; }
    echo '</select> <button class="button button-primary" type="submit">Select catalog</button></form>';
    echo '<div class="fpl-catalog-list">';
    foreach (fpl_catalogs() as $year => $meta) {
        $catalog = fpl_catalog($year);
        echo '<section><h3>Fusion ' . esc_html($year) . '</h3><p>' . esc_html(ucwords(str_replace('_', ' ', $meta['status']))) . ' · ' . count($catalog['plugins'] ?? []) . ' plugins</p>';
        if (!empty($meta['notice'])) { echo '<p>' . esc_html($meta['notice']) . '</p>'; }
        echo '</section>';
    }
    echo '</div>';
}
