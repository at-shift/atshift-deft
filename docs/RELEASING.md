# Release and Update Channels

## 0.7.2 Artifacts

- GitHub: `atshift-deft-0.7.2.zip`, its `.sha256`, and `atshift-deft-update.json`.
- WordPress.org: `atshift-deft-0.7.2-wordpress-org.zip`, without the GitHub updater; accepted as an update to the pending application on 2026-11-01.
- Both report 0.7.2 and include matching core files and translation catalogs, apart from the GitHub-only updater/bootstrap/header.
- Use `RELEASE-0.7.2.md` for release notes, beginning with the required MCP Adapter installation and activation correction.
- Preparing these artifacts does not update the pending WordPress.org application or publish a GitHub Release. Record each actual upload/publication separately after verification and approval.

`WORDPRESS-ORG-REVIEW-0.7.2.md` records the confirmed upload and the explanation
sent to reviewers. The application remains awaiting review; existing sites and
earlier releases have not been changed.

## 0.7.1 Artifacts (Previous)

- GitHub: `atshift-deft-0.7.1.zip`, its `.sha256`, and `atshift-deft-update.json`.
- WordPress.org: `atshift-deft-0.7.1-wordpress-org.zip`, already accepted as an update to the pending application.
- Both install as `atshift-deft/atshift-deft.php`, report 0.7.1 and include matching POT, partial Japanese PO/MO and JavaScript catalogs.
- The GitHub ZIP adds `Update URI`, the updater bootstrap and `includes/class-github-updater.php`; the WordPress.org ZIP does not.
- The GitHub release build refreshes two packaged guides with its 0.7.1 download links. Its runtime and translation files otherwise match the submitted artifact outside the updater differences. The submitted ZIP and its checksum are retained unchanged in `.local/v071-submission/`.

The existing v0.7.0 GitHub Release, tag and assets remain unchanged.
The 0.7.0 submission record is retained; `WORDPRESS-ORG-REVIEW-0.7.1.md` records the
new artifact, checker results and upload confirmation. Existing sites are not
updated by preparing or submitting a ZIP. The application is still awaiting review.

## Submission History

The initial 0.7.0 WordPress.org upload rejected the upstream non-runtime file
`vendor/paragonie/random_compat/build-phar.sh`. The corrected submission package
in `.local/v070-submission/` omits that file and updates its dependency manifest;
all other files match the originally checked submission package. The already
published GitHub 0.7.0 ZIP is immutable and was not replaced. Subsequent builds of
both channels omit this build script. See `WORDPRESS-ORG-REVIEW-0.7.0.md` for the
submitted artifact checksum and the explanation of retained checker findings.

`main` defaults to GitHub packaging. If separately approved, a submission branch
`wordpress-org/<version>` can pin the release source commit plus `distribution.json` set
to `wordpress-org`. Do not update a submission branch or submit without approval.
The optional updater source lives outside the plugin directory and is never part
of the submission ZIP. Keeping it in the repository allows shared tests and
translation extraction without copying feature code between branches.

## Build and Check

1. Bump package/lock, plugin header/constant, readme Stable tag, current guides and PO project version together.
2. Run `npm run i18n:pot` and `npm run i18n:compile`. Review translations; preserve technical IDs and placeholders.
3. Run `npm run build:wordpress`, then `npm run pack:wordpress` and `npm run pack:wordpress-org` from `main`.
4. Run `npm run test:distribution`, branding/i18n tests, PHP lint and the disposable WordPress integration tests. Install each actual ZIP before Plugin Check. Never point test fixtures at a user database.
5. Confirm the submission ZIP has no updater, `Update URI` or GitHub runtime HTTP requests. Compare both archives and every language file byte-for-byte outside the allowed adapter differences.
6. Obtain explicit approval immediately before pushing branches, publishing a release or submitting the plugin ZIP. Submission is not approval; record the actual WordPress.org result separately.

Publish a non-draft, non-prerelease GitHub Release tagged `v0.7.2`, targeting the
verified `main` commit. Upload the three GitHub files above before publishing.
Do not rebuild or replace the ZIP after its manifest/checksum are published.
Download the public assets independently and verify their bytes and SHA-256.
Retain the separately checked submission ZIP and submit that file, not the GitHub
ZIP or GitHub's source-code archive. After approval, WordPress.org updates use SVN
and its normal release process, not this GitHub adapter.

## Update Behavior

The adapter uses WordPress's `update_plugins_github.com` hook and a public,
unauthenticated HTTPS request to this repository's latest release endpoint. It
accepts only exact stable tags, matching versioned ZIP URLs and a bounded release
manifest. Prereleases, drafts, source archives, other repositories and malformed
or mismatched metadata are rejected. SHA-256 and length are checked before the
WordPress upgrader extracts an update; available GitHub asset digests must agree.
This is transport/integrity verification, not a signature independent of GitHub.

Successful metadata is cached for six hours; failures for fifteen minutes. The
adapter sends no site URL, credentials or article data. GitHub sees request IP
and plugin-version User-Agent. WordPress handles update permissions, filesystem
credentials and automatic-update preferences. No user preference is enabled or
disabled by the adapter.

0.5.x has no GitHub updater. Back up, upload the current GitHub ZIP via **Plugins >
Add New > Upload Plugin**, and choose **Replace current with uploaded** once.
Subsequent newer releases appear through normal plugin updates. Inactive plugins
cannot run this adapter; activate DEFT to check GitHub updates.

To switch channels, manually replace the installed ZIP with the desired channel
of the same or newer version; no deletion or data migration is needed. Identical
versions do not generate update notices. A GitHub install does not silently switch
to WordPress.org, and a WordPress.org install never fetches GitHub updates.

## Submission Review Notes

The AI uses an explicitly authorized external MCP client; no hosted AI backend or
provider API key is bundled. MCP Adapter is a separately installed dependency,
not downloaded or installed by DEFT. Private file storage requires configuration
outside public document roots. See the packaged readme for external-service and
publication-permission disclosures.

Plugin Check warnings must be reviewed, not hidden globally. Existing authoritative
database reads/locks and schema installation, compatible `sf_*` hooks, authenticated
REST routes/public media routes, and downloadable Markdown documentation explain
some warnings. Review actual input/nonce findings at their callers before accepting
them. Plugin Check passing alone does not establish production readiness.
