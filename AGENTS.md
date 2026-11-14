# Fusion development

Follow the WordPress plugin, GitHub update, branding/UX and general standards in `cchatterton/codex-standards`, with the Fusion-specific requirements in this repository taking precedence.

Keep Fusion's user-facing interface independent of Techn and AlphaSys branding. Preserve the plugin's Techn authorship metadata. Fusion is an optional catalog and mirror client: do not filter, disable, replace, or redirect native WordPress plugin updates. Fusion's own update controls use only verified packages from its GitHub mirror.

The catalog curates plugin choices, one per function, and includes Fusion Plugins itself. `fusion-index.json` records verified releases; the latest mirrored version is the version offered by Fusion. Keep `mirror-index.json` as the public-plugin-only feed for older controllers. Preserve historical release ZIPs. Do not mirror licensed vendor packages without an authorised source and redistribution rights.

For distributable changes, bump the plugin header, version constant, readme stable tag and changelog together; build the root ZIP, tag, publish a GitHub release, and verify its asset. Test install/overwrite actions only on a disposable WordPress site.
