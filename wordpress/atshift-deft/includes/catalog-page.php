<?php
namespace Atshift\Deft;
defined( 'ABSPATH' ) || exit;

function catalog_page() {
    if ( ! Access::workspace() ) return;
    ?>
    <div class="wrap" id="sf-workspace">
        <h1><?php esc_html_e( 'Components', 'atshift-deft' ); ?> <small><?php echo esc_html( VERSION ); ?></small></h1>
        <div class="sf-toolbar">
            <button class="button" id="sf-catalog-structure"><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span> <?php esc_html_e( 'New structure', 'atshift-deft' ); ?></button>
            <button class="button" id="sf-catalog-personal"><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span> <?php esc_html_e( 'New personal component', 'atshift-deft' ); ?></button>
            <button class="button" id="sf-catalog-import"><span class="dashicons dashicons-upload" aria-hidden="true"></span> <?php esc_html_e( 'Import component', 'atshift-deft' ); ?></button>
            <input id="sf-catalog-file" type="file" accept="application/json,.json" hidden>
            <?php if ( Catalog::can_register() ) : ?><button class="button" id="sf-catalog-example"><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span> <?php esc_html_e( 'New example', 'atshift-deft' ); ?></button><?php endif; ?>
            <button class="button" id="sf-refresh" title="<?php esc_attr_e( 'Refresh components', 'atshift-deft' ); ?>" aria-label="<?php esc_attr_e( 'Refresh components', 'atshift-deft' ); ?>"><span class="dashicons dashicons-update" aria-hidden="true"></span></button>
            <button class="button" id="sf-catalog-schema" title="<?php esc_attr_e( 'Download API schemas', 'atshift-deft' ); ?>" aria-label="<?php esc_attr_e( 'Download API schemas', 'atshift-deft' ); ?>"><span class="dashicons dashicons-download" aria-hidden="true"></span></button>
            <button class="button" id="sf-catalog-handoff"><span class="dashicons dashicons-download" aria-hidden="true"></span> <?php esc_html_e( 'AI handoff', 'atshift-deft' ); ?></button>
        </div>
        <div class="sf-toolbar">
            <div class="sf-catalog-control"><label for="sf-catalog-scope"><?php esc_html_e( 'Scope', 'atshift-deft' ); ?></label><select id="sf-catalog-scope"><option value="all"><?php esc_html_e( 'All available', 'atshift-deft' ); ?></option><option value="used"><?php esc_html_e( 'Used by me', 'atshift-deft' ); ?></option><option value="shared"><?php esc_html_e( 'Shared catalog', 'atshift-deft' ); ?></option><option value="personal"><?php esc_html_e( 'My private components', 'atshift-deft' ); ?></option></select></div>
            <div class="sf-catalog-control"><label for="sf-catalog-query"><?php esc_html_e( 'Find existing', 'atshift-deft' ); ?></label><input id="sf-catalog-query" type="search" maxlength="200">
            <button class="button" id="sf-catalog-lookup" title="<?php esc_attr_e( 'Check existing components', 'atshift-deft' ); ?>" aria-label="<?php esc_attr_e( 'Check existing components', 'atshift-deft' ); ?>"><span class="dashicons dashicons-search" aria-hidden="true"></span></button></div>
            <div class="sf-catalog-control"><label for="sf-reply-language"><?php esc_html_e( 'Reply language', 'atshift-deft' ); ?></label><input id="sf-reply-language" type="text" maxlength="35" value="<?php echo esc_attr( str_replace( '_', '-', get_user_locale() ) ); ?>"></div>
        </div>
        <p id="sf-status" role="status"><?php esc_html_e( 'Component catalog', 'atshift-deft' ); ?></p>
        <details id="sf-behavior-registry"><summary><?php esc_html_e( 'Behaviors', 'atshift-deft' ); ?></summary><div id="sf-behavior-list"></div></details>
        <div class="sf-layout"><nav id="sf-articles" aria-label="<?php esc_attr_e( 'Components', 'atshift-deft' ); ?>"></nav><main>
            <h2 id="sf-title"><?php esc_html_e( 'No component selected', 'atshift-deft' ); ?></h2><p id="sf-catalog-state"></p>
            <p id="sf-catalog-purpose"></p><dl id="sf-catalog-fields"></dl>
            <div class="sf-toolbar">
                <button class="button button-primary" id="sf-catalog-save" disabled><span class="dashicons dashicons-saved" aria-hidden="true"></span> <?php esc_html_e( 'Register version', 'atshift-deft' ); ?></button>
                <label><input type="checkbox" id="sf-catalog-enabled" disabled> <?php esc_html_e( 'Available for new articles', 'atshift-deft' ); ?></label>
                <?php if ( Catalog::can_register() ) : ?><button class="button" id="sf-catalog-share" disabled><span class="dashicons dashicons-share" aria-hidden="true"></span> <?php esc_html_e( 'Register shared copy', 'atshift-deft' ); ?></button><?php endif; ?>
                <button class="button" id="sf-catalog-export" disabled title="<?php esc_attr_e( 'Export component', 'atshift-deft' ); ?>" aria-label="<?php esc_attr_e( 'Export component', 'atshift-deft' ); ?>"><span class="dashicons dashicons-download" aria-hidden="true"></span></button>
            </div>
            <section id="sf-catalog-reuse" hidden>
                <label for="sf-catalog-considered"><?php esc_html_e( 'Components considered', 'atshift-deft' ); ?></label><select id="sf-catalog-considered" multiple size="4"></select>
                <label for="sf-catalog-reason"><?php esc_html_e( 'Missing capability', 'atshift-deft' ); ?></label><textarea id="sf-catalog-reason" rows="3" maxlength="1000"></textarea>
            </section>
            <label for="sf-catalog-json"><?php esc_html_e( 'Component JSON', 'atshift-deft' ); ?></label>
            <textarea id="sf-catalog-json" class="code" rows="18" spellcheck="false" readonly></textarea>
        </main></div>
    </div>
    <?php
}
