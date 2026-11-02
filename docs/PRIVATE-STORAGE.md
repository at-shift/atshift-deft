# Private Storage

New private uploads and frozen publication copies are stored in the site's
`atshde_blob_chunks` database table. They do not create a file in the plugin,
WordPress core, content root, public uploads, or an externally configured folder.
The normal PHP upload temporary file is read within its upload request only.

Each file is split into 64 KiB chunks. SQL uses bounded hexadecimal binary
transport, not charset-sensitive text storage. Reads require the exact chunk
sequence, length and SHA-256. Missing, extra or modified chunks fail closed.
Writes are serialized and verified; an unchanged retry reuses existing bytes.
Caught write failures remove their partial chunks. A process termination may
leave an orphan; it is not served without valid metadata and counts toward the
bounded capacity. Automatic orphan deletion is not implemented.

## Access and Limits

- Staged files require the owning workspace account, upload permission, the
  owning draft and an asset/hash-bound nonce for browser downloads.
- Frozen bytes are public only while their publication record matches an actual
  public, non-password-protected WordPress post. GET and HEAD recheck this state.
- Database storage has no directly addressable web file, independent of Apache
  `.htaccess` or Nginx access rules. Database and backup security remain the host's
  responsibility. This is access-controlled storage, not encryption at rest.
- Existing type, 10 MiB per-file, article and per-user checks remain. Staged
  metadata counts both legacy and database files toward the 500 MiB site quota.
  Frozen copies have a separate 500 MiB quota; readable retained legacy copies
  are included in that calculation. These are not one combined 500 MiB limit.
- Trash/deactivation retain data and do not reclaim quota. There is no automatic
  permanent deletion or retention policy.

## Upgrading Existing Sites

Back up the database, WordPress configuration and old private files before an
upgrade. The prefix migration renames only known plugin tables/options/roles and
preserves binary identities, owners, hashes, history and signing material.
New table names do not change the old directory identity: legacy reads retain
the original database name plus original `sf_assets` table-name hash.

Previously written files stay where they are and are read only. Keep the old
`SF_PRIVATE_MEDIA_DIR` and `SF_PUBLIC_DOCUMENT_ROOT` settings while using those
files. No new directory or file is created there, and originals are not deleted
or silently copied to public uploads. Invalid legacy paths, symlinks, permissions
or bytes still fail closed. Removing the old configuration makes those files
unavailable; database-backed files remain usable.

Do not downgrade the plugin alone after its data-prefix migration. Restoring a
pre-migration version requires the matching database/configuration/file backup.
Conflicting old/new names stop plugin initialization and show an administrator
notice. They are never merged automatically. Resolve the conflict from a backup
or with administrator assistance before retrying.

## WordPress Paths and Multisite

Existing Media Library attachments still use WordPress's uploads APIs and retain
their original access behavior. Reusing them does not make their public URL
private. The database table is scoped by WordPress's current site table prefix;
one site's private bytes are not searched in another site's table. New private
storage does not depend on `ABSPATH`, `WP_CONTENT_DIR`, `DOCUMENT_ROOT`, Unix file
permissions or a hard-coded uploads path. Legacy file reads retain stricter
historical path checks solely for backward compatibility.

Regression evidence and any environment limitations are recorded in
[WordPress.org Remediation](WORDPRESS-ORG-REMEDIATION.md).
