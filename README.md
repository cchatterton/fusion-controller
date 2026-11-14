# Fusion Plugins

Fusion is a curated WordPress plugin catalog. **Fusion 26** is the first complete catalog in this repository, with one selected plugin for each listed function. Open **Plugins → Fusion Plugins** (Network Admin on multisite). The two tabs are **Updates available** and **Catalogue**. Choose the catalog from the pick list at the top of Catalogue.

**Check for updates** downloads one public `mirror-index.json` file. The controller compares installed versions with the latest verified mirrored versions. Select eligible updates and choose **Update selected plugins**, or install a mirrored release from its catalog card. Each operation runs one plugin at a time and verifies the ZIP's SHA-256 and folder before WordPress installs it. New installs remain inactive until activated. An interrupted batch can be resumed.

Checks and package operations show their progress and results in a modal. A successful check refreshes the update list and closes the modal; errors stay visible with a retry action.

Fusion does not filter, disable or redirect WordPress native update checks. Its own install and update controls use the Fusion GitHub mirror. Premium plugins are cataloged but require a licensed vendor package until an authorised mirror source is configured.

## Catalogs

`fusion-plugins/catalogs/index.json` declares the current year and each catalog's state: `current`, `supported`, `archive_planned`, or `archived`. The optional `notice` text is shown with a selected catalog. Add a dated intent-to-archive notice before changing a catalog to `archived`. Archived catalogs remain selectable and readable, but their exclusive plugins stop being refreshed by the mirror. Native WordPress updates are unaffected.

The selected catalog is stored as a site option, or a network option on multisite. The current catalog is used when no selection has been saved. Do not publish an incomplete historical catalog merely to illustrate the format.

## Mirror

The GitHub Action checks the current catalog daily. Each Sunday it also checks previous supported catalogs, with shared plugin slugs checked only once. `workflow_dispatch` accepts an optional catalog year and WordPress.org slug. The sync validates package identity, ZIP paths and integrity, then publishes versioned GitHub release ZIPs and updates `mirror-index.json`. Historical release assets are retained. Failed checks retain the last known index and fail the workflow.

The curated decision is which plugin fills each function. The latest verified mirrored release is available to the controller without a separate version approval step. Sites fetch the index only when an administrator clicks **Check for updates**; ordinary page loads read the cached result. No GitHub token is stored on sites.

`approved.json` remains empty solely so older 0.1.x clients can finish their existing checks during migration. Fusion 0.2.0 does not read it.

Premium plugins need an authorised vendor adapter and a verified package identity before they can enter the mirror. Catalog membership does not grant redistribution rights.

## Release

Run `bash scripts/build-plugin-zip.sh`. The root `fusion-plugins.zip` contains only the installable plugin directory. Plugin header, version constant, readme stable tag, changelog, tag and GitHub release should match. WordPress 6.5+ and PHP 7.4+ are declared minimums; runtime validation on those versions is required before production deployment.

The plugin is authored by Techn, while the interface and catalog are presented solely as Fusion.
