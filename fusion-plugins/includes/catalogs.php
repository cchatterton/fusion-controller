<?php
if (!defined('ABSPATH')) { exit; }

function fpl_read_json(string $path): array {
    if (!is_file($path)) { return []; }
    $decoded = json_decode((string) file_get_contents($path), true);
    return is_array($decoded) ? $decoded : [];
}

function fpl_catalog_index(): array {
    $index = fpl_read_json(FPL_ROOT . 'catalogs/index.json');
    return ($index['schema'] ?? null) === 1 ? $index : [];
}

function fpl_catalogs(): array {
    $catalogs = [];
    foreach (fpl_catalog_index()['catalogs'] ?? [] as $catalog) {
        if (!is_array($catalog) || !preg_match('/^\d{2}$/D', (string) ($catalog['year'] ?? ''))) { continue; }
        $year = $catalog['year'];
        if (!in_array($catalog['status'] ?? '', ['current', 'supported', 'archive_planned', 'archived'], true)) { continue; }
        $catalogs[$year] = $catalog;
    }
    return $catalogs;
}

function fpl_catalog(string $year): array {
    if (!isset(fpl_catalogs()[$year])) { return []; }
    $catalog = fpl_read_json(FPL_ROOT . 'catalogs/' . $year . '.json');
    if (($catalog['schema'] ?? null) !== 1 || ($catalog['year'] ?? '') !== $year || !is_array($catalog['plugins'] ?? null)) { return []; }
    return $catalog;
}

function fpl_selected_year(): string {
    $current = (string) (fpl_catalog_index()['current'] ?? '');
    $saved = (string) (is_multisite() ? get_site_option('fpl_selected_catalog', '') : get_option('fpl_selected_catalog', ''));
    return isset(fpl_catalogs()[$saved]) ? $saved : $current;
}

function fpl_mirror(): array {
    $stored = is_multisite() ? get_site_option('fpl_mirror', []) : get_option('fpl_mirror', []);
    return is_array($stored) ? $stored : [];
}

function fpl_latest_release(array $entry): array {
    $slug = (string) ($entry['slug'] ?? '');
    $package = fpl_mirror()['packages'][$slug] ?? [];
    $version = (string) ($package['latest'] ?? '');
    $release = $package['versions'][$version] ?? [];
    return is_array($release) && $version !== '' ? $release + ['version' => $version] : [];
}

function fpl_plugin_by_id(array $catalog, string $id): array {
    foreach ($catalog['plugins'] ?? [] as $plugin) {
        if (is_array($plugin) && ($plugin['id'] ?? '') === $id) { return $plugin; }
    }
    return [];
}

function fpl_installed_plugin(array $entry, array $installed): array {
    $slug = (string) ($entry['slug'] ?? '');
    foreach ($installed as $file => $plugin) {
        if ($slug !== '' && dirname($file) === $slug) { return ['file' => $file, 'data' => $plugin]; }
        if ($slug === '' && strcasecmp((string) ($plugin['Name'] ?? ''), (string) ($entry['name'] ?? '')) === 0) { return ['file' => $file, 'data' => $plugin]; }
    }
    return [];
}
