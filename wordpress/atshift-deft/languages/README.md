# Translation Files

Text domain: `atshift-deft`. Untranslated messages fall back to English.
The bundled Japanese catalog preserves the existing Japanese welcome, consent
and compatibility notices; it is intentionally not a complete UI translation.

## Add a Language

1. Run `npm run i18n:pot` from the repository root with WP-CLI installed.
   Set `WP_CLI=/absolute/path/wp-cli.phar` if it is not on PATH.
2. Create `atshift-deft-LOCALE.po` from `atshift-deft.pot` using a gettext editor
   (for example `atshift-deft-fr_FR.po`). Set Language and the language's
   Plural-Forms header. Preserve placeholders and source references.
3. Run `npm run i18n:compile`. This builds PHP `.mo` files and per-source
   JavaScript JSON catalogs without removing the JavaScript entries from PO.
4. Run `npm run test:i18n`, `npm run pack:wordpress`, and verify the installed
   ZIP with the WordPress user's language set to the new locale.

Keep the generated `.mo` and `atshift-deft-LOCALE-HASH.json` files alongside the
PO file. JavaScript JSON hashes refer to the exact source paths in the POT;
do not rename scripts without regenerating the catalogs. Do not distribute
temporary test locales or editor backup files. Packaging includes only the
translation template, correctly named catalogs and this document.

For updates, merge the latest POT into each PO with your gettext editor or
`msgmerge --update --backup=none FILE.po atshift-deft.pot`, review the changes,
then recompile. Never treat fuzzy entries as reviewed translations.

## Boundaries

Translate interface labels, consent text and human-readable error messages.
Do not translate IDs, keys, API/tool names, scopes, state values, schemas,
configuration examples, saved component definitions or article content.
Their identities and hashes must not change with the interface language.
AI instructions remain English and already direct the AI to use the user's
preferred conversation language, independently of the article language.
The standalone browser prototype under `src/` is not part of this WordPress
translation catalog.

PHP uses WordPress gettext and JavaScript uses `wp.i18n` with
`wp_set_script_translations`. WordPress language packs can also supply
translations from `wp-content/languages/plugins`; plugin-local catalogs are
provided for ZIP installs. No external translation service is used at runtime.

References: [PHP translations](https://developer.wordpress.org/reference/functions/load_plugin_textdomain/),
[JavaScript translations](https://developer.wordpress.org/reference/functions/wp_set_script_translations/),
[POT extraction](https://developer.wordpress.org/cli/commands/i18n/make-pot/).
