# Fusion Plugins

Fusion is a curated WordPress plugin catalog. **Fusion 26** is the first complete catalog in this repository, with one selected plugin for each listed function. Open **Plugins → Fusion Plugins** (Network Admin on multisite). When present, the Plugins submenu lists Techn Plugins, AlphaSys Plugins, Fusion Plugins, then WordPress's installed plugin page as Other Plugins; Add Plugin and Plugin File Editor follow. The two Fusion tabs are **Updates available** and **Catalogue**. Choose the catalog from the pick list at the top of Catalogue.

**Check for updates** downloads one public `fusion-index-v3.json` file. The controller compares installed versions with the latest verified mirrored versions. Select eligible updates and choose **Update selected plugins**, or install a mirrored release from its catalog card. Each operation runs one plugin at a time and verifies the ZIP's SHA-256 and folder before WordPress installs it. New installs remain inactive until activated. An interrupted batch can be resumed.

Checks and package operations show their progress and results in a modal. A successful check refreshes the update list and closes the modal; errors stay visible with a retry action.

Fusion does not filter, disable or redirect WordPress native update checks. Its own install and update controls use the Fusion GitHub mirror. When a catalogued plugin has no mirrored update, Fusion reads WordPress's cached plugin update notice and links to the Plugins screen. That update is not selectable for a Fusion batch until its ZIP is mirrored.

## Catalogs

`fusion-plugins/catalogs/index.json` declares the current year and each catalog's state: `current`, `supported`, `archive_planned`, or `archived`. The optional `notice` text is shown with a selected catalog. Add a dated intent-to-archive notice before changing a catalog to `archived`. Archived catalogs remain selectable and readable, but their exclusive plugins stop being refreshed by the mirror. Native WordPress updates are unaffected.

The selected catalog is stored as a site option, or a network option on multisite. The current catalog is used when no selection has been saved. Do not publish an incomplete historical catalog merely to illustrate the format.

## Mirror

The GitHub Action checks the current catalog daily. Each Sunday it also checks previous supported catalogs, with shared plugin slugs checked only once. `workflow_dispatch` accepts an optional catalog year and plugin slug. The sync validates package identity, ZIP paths and integrity, then publishes versioned GitHub release ZIPs and updates `fusion-index-v3.json`. Fusion Plugins itself is indexed from its verified controller release ZIP. The older `fusion-index-v2.json`, `fusion-index.json` and public-plugin-only `mirror-index.json` remain available for older controllers; their package sets stay fixed so those controllers can validate updates. Historical release assets are retained. Failed checks retain the last known index and fail the workflow.

The curated decision is which plugin fills each function. The latest verified mirrored release is available to the controller without a separate version approval step. Sites fetch the index only when an administrator clicks **Check for updates**; ordinary page loads read the cached result. No GitHub token is stored on sites.

`approved.json` remains empty solely so older 0.1.x clients can finish their existing checks during migration. Current Fusion versions do not read it.

Premium plugins can enter the mirror through a verified, manually supplied vendor ZIP. Catalog membership does not grant redistribution rights.

All premium entries follow the same path, including Gravity Forms and its Advanced Post Creation, Salesforce, Stripe, Survey and Webhooks add-ons, Independent Analytics Pro, and WP Migrate. When WordPress reports a new version, obtain its vendor ZIP through your licensed account. Run `python3 scripts/add-vendor-package.py --id <catalog-id> --zip <path> --version <version>` to inspect it. After checking distribution rights, rerun with `--publish`, commit `fusion-index-v3.json`, and have client sites use **Check for updates**. The importer verifies the plugin name, version, directory, ZIP contents and checksum before publishing a versioned GitHub release asset. If a package should not be publicly downloadable, use private distribution instead of `--publish`.

## Release

Run `bash scripts/build-plugin-zip.sh`. The root `fusion-plugins.zip` contains only the installable plugin directory. Plugin header, version constant, readme stable tag, changelog, tag and GitHub release should match. WordPress 6.5+ and PHP 7.4+ are declared minimums; runtime validation on those versions is required before production deployment.

The plugin is authored by Techn, while the interface and catalog are presented solely as Fusion.
