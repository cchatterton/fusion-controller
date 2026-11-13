# Fusion Plugins

Fusion is a curated WordPress plugin catalog. **Fusion 26** is the first complete catalog in this repository. It contains one selected plugin for each listed function. The WordPress admin page is **Plugins → Fusion Plugins** (Network Admin on multisite), with Updates available, Catalogue, Settings and Catalogs tabs.

The controller does not replace or filter native WordPress update checks. It offers an additional, explicit way to install an approved mirrored release. New installs remain inactive until an administrator activates them. WordPress.org installation remains available through the native installer. Premium plugins are cataloged but require a licensed vendor package; this repository does not mirror them without an authorised source.

## Catalogs

`fusion-plugins/catalogs/index.json` declares the current year and each catalog's state: `current`, `supported`, `archive_planned`, or `archived`. The optional `notice` text is displayed in the Catalogs tab and on sites with that catalog selected. Add a dated intent-to-archive notice before changing a catalog to `archived`. Archived catalogs remain selectable and readable, but their exclusive plugins stop being refreshed by the mirror. Native WordPress updates are unaffected.

The selected catalog is stored as a site option, or a network option on multisite. The current catalog is used when no selection has been saved. Do not publish an incomplete historical catalog merely to illustrate the format.

## Mirror and approval

The GitHub Action checks the current catalog daily. Each Sunday it also checks previous supported catalogs, with shared plugin slugs checked only once. `workflow_dispatch` accepts an optional catalog year and WordPress.org slug. The sync validates package identity, ZIP paths and integrity, then publishes versioned GitHub release ZIPs and updates `mirror-index.json`. Existing release assets are retained for rollback. Failed checks keep the last known index and fail the workflow.

Mirroring does **not** approve a release. After review, run `python3 scripts/approve-release.py 26 <slug> <version>` and commit `approved.json`. Sites download that file only when an administrator clicks **Check Fusion approvals**. The controller validates IDs, versions, hashes and package URLs, then retains its previous snapshot on failure. Installing an approved release is an explicit administrator action; its ZIP is checked against the approved SHA-256 and expected plugin folder before WordPress installs it. No token is stored on sites.

Premium plugins need an authorised vendor adapter and a verified package identity before they can enter the mirror. Their catalog membership does not grant redistribution rights.

## Release

Run `bash scripts/build-plugin-zip.sh`. The root `fusion-plugins.zip` contains only the installable plugin directory. Plugin header, version constant, readme stable tag, changelog, tag and GitHub release should match. WordPress 6.5+ and PHP 7.4+ are declared minimums; runtime validation on those versions is still required before production deployment.

The plugin is authored by Techn, while the interface and catalog are presented solely as Fusion.
