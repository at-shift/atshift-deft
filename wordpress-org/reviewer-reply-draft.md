# Reviewer Reply Draft

An adapted version was posted in the existing application's Additional Information
with the verified English-only 0.7.3 WordPress.org ZIP on 2026-11-03 (Japan time).
The email reply has not been sent. The full implementation checklist,
hashes and tests are linked from `docs/WORDPRESS-ORG-REVIEW-0.7.3.md`. The review email asks
for a short reply with context, not a change list; keep the detailed report separate.

---

Hello Plugin Review Team,

Thank you for reviewing atshift DeFt (`atshift-deft`). We have prepared the revised
0.7.3 ZIP for the existing application, not a new submission or slug change. A few clarifications for your review:

**Name:** `atshift` is our product-family name. Could you identify the specific
mark/project behind the concern about `DEFT`, and whether this is a directory
naming issue or a trademark conflict? The relevant owner and jurisdiction would
help us choose an appropriate remedy. We are not treating the capitalization
`DeFt` as resolving that concern, and have kept the slug unchanged pending clarification.

**Remote scope:** this plugin provides article/content operations, not remote
plugin/theme administration, user management/login, critical settings or arbitrary
code execution. Its bearer/OAuth credentials cannot authorize unrelated core REST
routes or nested dispatch. Connection setup takes place in the authenticated
WordPress session. Please confirm that the infrastructure-audit requirement does
not apply to this content-only scope. We do not claim an independent security audit.

**Compatibility and storage:** `Atshift\Deft` and `atshde_` are the new code/data
prefixes. Exact legacy action, cron and quota-hook aliases, protocol identifiers
and stored markup remain to preserve existing users' data and connections. New
private/frozen files use hash-verified database storage; old private directories
are read-only compatibility sources. This is access control, not encryption at rest.

**Filter and checker context:** replacement titles owned by the plugin are escaped;
unrelated incoming title-filter values remain unchanged to avoid altering other
plugins' output. HTTP nonce boundaries explicitly validate canonical input before
core verification; other callers retain the shared verifier and ownership checks.
Plugin Check reports 0 errors and 404 warnings, including helper
analysis limitations, direct database operations, explicit namespace/legacy aliases
and downloadable guides. File/line-specific explanations and the separate nonzero
PHPCS results are retained; no blanket suppression or clean-audit claim is made.

The candidate ZIP was installed and tested, including legacy-data upgrades,
relocated paths, two-site isolation and actual browser workflows. It contains no
GitHub updater or bundled translations; it is English-only while retaining WordPress language-pack support. Source, exact ZIP hashes and verification details:
https://github.com/at-shift/atshift-deft/blob/v0.7.3/docs/WORDPRESS-ORG-REVIEW-0.7.3.md

Please let us know if any retained compatibility boundary needs
a different approach. Thank you.
