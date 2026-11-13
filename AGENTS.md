# Fusion development

Follow the WordPress plugin, GitHub update, branding/UX and general standards in `cchatterton/codex-standards`, with the Fusion-specific requirements in this repository taking precedence.

Keep Fusion's user-facing interface independent of Techn and AlphaSys branding. Preserve the plugin's Techn authorship metadata. Fusion is an optional catalog and mirror client: do not filter, disable, replace, or redirect native WordPress plugin updates.

Do not auto-approve a mirrored release. `mirror-index.json` records verified packages; `approved.json` records explicit catalog approvals. Preserve historical release ZIPs. Do not mirror licensed vendor packages without an authorised source and redistribution rights.

For distributable changes, bump the plugin header, version constant, readme stable tag and changelog together; build the root ZIP, tag, publish a GitHub release, and verify its asset. Test install/overwrite actions only on a disposable WordPress site.
