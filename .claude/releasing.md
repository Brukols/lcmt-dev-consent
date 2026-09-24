# Releasing & updates

Sites update the plugin from Dashboard → Updates, like a wordpress.org plugin: [`src/Updater.php`](../src/Updater.php) (Plugin Update Checker v5.7, vendored in `lib/plugin-update-checker/` — do not edit, copied from `lcmt-dev-mailer`) reads the GitHub releases of `Brukols/lcmt-dev-consent` (**public** repository — PUC cannot read a private one without a token on every site) and installs the `lcmt-dev-consent.zip` attached to the latest one.

The folder, slug, text domain and every internal identifier stay `lcmt-dev-consent`: only the displayed name is "LCMT Consent". Changing the slug would make WordPress treat it as another plugin, and existing sites would stop receiving updates.

## Publishing a version

1. Bump the version in the plugin header **and** `LCMT_DEV_CONSENT_VERSION` (`lcmt-dev-consent.php`), `package.json` and `Stable tag` + changelog in `readme.txt`.
2. Run `php vendor/bin/phpunit` and `yarn build`, and commit `assets/dist/` if it changed.
3. Tag and push: `git tag v1.5.0 && git push && git push --tags`.

`.github/workflows/release.yml` then checks that the tag matches the three versions and that `assets/dist/` is up to date, builds the zip with `git archive` (files marked `export-ignore` in `.gitattributes` are left out) and publishes the release. Sites see the update within 12 hours, or at once with "Check for updates" on the Plugins screen.

## First install on a site

Sites running a version older than 1.5.0 have no updater: install 1.5.0 once by hand (the release zip, or `yarn package` → `build/lcmt-dev-consent-<version>.zip`, which includes `lib/`). Every later version comes through Dashboard → Updates.

## Testing a release before tagging

Test the **zip**, never the working copy: a file present on disk but ignored by git works locally and is missing from the release (1.5.1 shipped without `lib/plugin-update-checker/vendor/`, caught by an unanchored `vendor/` rule in `.gitignore` → fatal "Class Parsedown not found" on every site's update check). Before tagging:

```bash
git status --ignored --short lib src assets/dist languages   # must print nothing
git archive --format=tar HEAD | tar -t | grep plugin-update-checker/vendor   # Parsedown.php, PucReadmeParser.php…
```

The release workflow also refuses a zip missing the updater's runtime files.

## When adding files

A new runtime folder must **not** be listed in `.gitattributes`, and must be added to the `INCLUDE` allowlist of `bin/build.sh` (manual zip). A new development-only file or folder at the root must be added to `.gitattributes` as `export-ignore`.
