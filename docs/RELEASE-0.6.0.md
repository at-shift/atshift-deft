# atshift DEFT 0.6.0

## Reusable Component Styling

- Let an authorized AI reuse or create shared components with bounded colors, spacing, borders, typography, column proportions and link-button styling.
- Generate component-scoped CSS inside DEFT instead of editing theme Additional CSS.
- Target fields by stable keys and preserve saved component revisions across previews and publication.
- Keep AI creation create-only, with the existing reuse checks, owner isolation and catalog limits.

## GitHub Updates

- GitHub packages now use WordPress's normal plugin update mechanism for later official releases from this repository.
- Verify exact release metadata, ZIP size and SHA-256 before installation. Drafts, prereleases, mismatched metadata and other download sources are rejected.
- Preserve WordPress's automatic-update preferences; no auto-update setting is enabled or disabled.
- Include the current translation template, partial Japanese PHP catalog and JavaScript catalog in both distribution channels.

## Upgrading

Back up first. Install `atshift-deft-0.6.0.zip` through **Plugins > Add New > Upload
Plugin**, choosing **Replace current with uploaded** for an existing installation.
This manual upgrade is required once from 0.5.x, which has no GitHub updater.
Download a fresh AI guide after upgrading. Existing drafts, components, roles and
connection grants are retained; the update does not grant new AI permissions.

The GitHub release includes the installable ZIP, its `.sha256` checksum, and
`atshift-deft-update.json` for the updater. GitHub's source archive is not the
installable package. This is an early release; evaluate it on a test site before
production use and review the documented publication limitations.

The separate `wordpress-org/0.6.0` branch prepares a same-version package without
the GitHub updater. **WordPress.org submission is on hold; no submission or
approval is claimed.** No existing website is deployed by this release.

## Verification

- 56 isolated WordPress appearance/compatibility checks.
- 69 GitHub-update checks, including the native WordPress upgrader and preservation of drafts, catalog and update preferences.
- 20 package, translation, connection-guidance and channel-separation tests.
- WordPress.org ZIP: Plugin Check errors 0, warnings 323. The warnings remain visible for review; they are not globally suppressed.
- The prior component-styling verification includes desktop/mobile rendering, Q&A interaction and keyboard focus. External AI-provider conversations were not repeated for this release.

GitHub update HTTP responses were simulated in the isolated upgrade test. Public
asset availability and checksums must also be verified after this release is
published; local tests alone do not establish a live GitHub update.
