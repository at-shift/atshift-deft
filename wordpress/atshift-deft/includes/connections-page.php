<?php
namespace StructuredFields;
defined( 'ABSPATH' ) || exit;

function connections_page() {
    if ( ! Access::workspace() ) return;
    ?>
    <div class="wrap" id="sf-workspace">
        <h1><?php esc_html_e( 'AI connections', 'atshift-deft' ); ?></h1>
        <p><?php esc_html_e( 'Give the guide to your usual AI first, then follow its connection guidance here. Connections last up to 30 days.', 'atshift-deft' ); ?></p>
        <p><a class="button" href="<?php echo esc_url( Onboarding::url() ); ?>"><span class="dashicons dashicons-download" aria-hidden="true"></span> <?php esc_html_e( 'Download AI guide', 'atshift-deft' ); ?></a></p>
        <section id="sf-mcp-prerequisite" aria-labelledby="sf-mcp-prerequisite-title">
            <h2 id="sf-mcp-prerequisite-title"><?php esc_html_e( 'Before connecting: install MCP Adapter', 'atshift-deft' ); ?></h2>
            <p><?php esc_html_e( 'MCP Adapter 0.7.1 or newer is required for AI connections. Manual JSON import does not require it.', 'atshift-deft' ); ?></p>
            <ol>
                <li><?php esc_html_e( 'Open Plugins > Add New Plugin and search for MCP Adapter by WordPress.org.', 'atshift-deft' ); ?></li>
                <li><?php esc_html_e( 'Install and activate it. If already installed, update it to 0.7.1 or newer if needed, then make sure it is active.', 'atshift-deft' ); ?></li>
                <li><?php esc_html_e( 'Return here and continue with your AI\'s connection settings below.', 'atshift-deft' ); ?></li>
            </ol>
            <p><a href="<?php echo esc_url( 'https://wordpress.org/plugins/mcp-adapter/' ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Official MCP Adapter page', 'atshift-deft' ); ?></a>
            <?php if ( current_user_can( 'install_plugins' ) ) : ?>
                &middot; <a href="<?php echo esc_url( self_admin_url( 'plugin-install.php?tab=search&s=MCP%20Adapter&type=term' ) ); ?>"><?php esc_html_e( 'Find MCP Adapter in WordPress', 'atshift-deft' ); ?></a>
            <?php endif; ?></p>
            <p><?php esc_html_e( 'If you cannot install or activate plugins, ask your site administrator to complete this step.', 'atshift-deft' ); ?></p>
        </section>
        <?php Onboarding::publication_help( true ); ?>
        <p id="sf-connection-status" role="status"></p>
        <p><label for="sf-connection-client"><?php esc_html_e( 'Your AI / client', 'atshift-deft' ); ?></label><br>
        <select id="sf-connection-client"><option value=""><?php esc_html_e( 'Choose a client', 'atshift-deft' ); ?></option></select></p>
        <p id="sf-client-note"></p>
        <p id="sf-client-confirm-row" hidden><label><input id="sf-client-confirm" type="checkbox"> <?php esc_html_e( 'This client supports an Authorization header stored privately for my account, not shared with other staff.', 'atshift-deft' ); ?></label></p>
        <p id="sf-client-readiness" role="status"></p>
        <details id="sf-client-help" hidden><summary><?php esc_html_e( 'Connection instructions', 'atshift-deft' ); ?></summary>
            <ol id="sf-client-steps"></ol>
            <p><a id="sf-client-source" hidden target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Official connection instructions', 'atshift-deft' ); ?></a></p>
        </details>
        <section id="sf-oauth-setup" hidden>
            <h2><?php esc_html_e( 'OAuth connection', 'atshift-deft' ); ?></h2>
            <p id="sf-oauth-status" role="status"></p>
            <p><label for="sf-oauth-client"><?php esc_html_e( 'Registered application', 'atshift-deft' ); ?></label><br><select id="sf-oauth-client"></select></p>
            <p><label><input id="sf-oauth-publishing" type="checkbox"> <?php esc_html_e( 'Use the publishing endpoint', 'atshift-deft' ); ?></label></p>
            <p><label for="sf-oauth-endpoint"><?php esc_html_e( 'MCP endpoint', 'atshift-deft' ); ?></label><br><input id="sf-oauth-endpoint" type="text" class="large-text code" readonly></p>
            <p><label for="sf-oauth-client-id"><?php esc_html_e( 'OAuth client ID', 'atshift-deft' ); ?></label><br><input id="sf-oauth-client-id" type="text" class="large-text code" readonly></p>
            <p><?php esc_html_e( 'In your AI\'s MCP settings, choose OAuth and enter this endpoint and client ID. Leave the client secret empty. Continue to WordPress, sign in with your own account and review the requested access.', 'atshift-deft' ); ?></p>
            <details><summary><?php esc_html_e( 'Protocol details', 'atshift-deft' ); ?></summary><dl id="sf-oauth-details"></dl></details>
            <?php if ( current_user_can( 'manage_options' ) ) : ?>
            <details><summary><?php esc_html_e( 'Register an application (administrator)', 'atshift-deft' ); ?></summary>
                <p><?php esc_html_e( 'Use the exact callback URL shown by the AI service. Registration is shared; each staff member authorizes their own WordPress account. This version supports pre-registered public clients with PKCE S256, not automatic client registration.', 'atshift-deft' ); ?></p>
                <form id="sf-oauth-register">
                    <p><label for="sf-oauth-name"><?php esc_html_e( 'Application name', 'atshift-deft' ); ?></label><br><input id="sf-oauth-name" type="text" required maxlength="80"></p>
                    <p><label for="sf-oauth-provider"><?php esc_html_e( 'Service', 'atshift-deft' ); ?></label><br><select id="sf-oauth-provider"><option value="chatgpt"><?php esc_html_e( 'ChatGPT', 'atshift-deft' ); ?></option><option value="claude"><?php esc_html_e( 'Claude', 'atshift-deft' ); ?></option><option value="gemini"><?php esc_html_e( 'Gemini', 'atshift-deft' ); ?></option><option value="other"><?php esc_html_e( 'Other', 'atshift-deft' ); ?></option></select></p>
                    <p><label for="sf-oauth-redirect"><?php esc_html_e( 'Exact callback URL', 'atshift-deft' ); ?></label><br><input id="sf-oauth-redirect" type="url" required maxlength="2048" class="large-text code"></p>
                    <p><label><input id="sf-oauth-authoring-allowed" type="checkbox"> <?php esc_html_e( 'Permit requesting layouts, files and previews', 'atshift-deft' ); ?></label></p>
                    <p><label><input id="sf-oauth-publishing-allowed" type="checkbox"> <?php esc_html_e( 'Permit requesting publication access', 'atshift-deft' ); ?></label></p>
                    <button class="button" type="submit"><?php esc_html_e( 'Register application', 'atshift-deft' ); ?></button>
                </form>
                <ul id="sf-oauth-applications"></ul>
            </details>
            <?php endif; ?>
        </section>
        <section id="sf-key-setup" hidden>
        <h2><?php esc_html_e( '1. Create a connection', 'atshift-deft' ); ?></h2>
        <form id="sf-connection-form">
            <p><label for="sf-connection-name"><?php esc_html_e( 'Connection name', 'atshift-deft' ); ?></label><br>
            <input id="sf-connection-name" type="text" maxlength="80" required autocomplete="off" placeholder="<?php esc_attr_e( 'My AI app', 'atshift-deft' ); ?>"></p>
            <p><label><input id="sf-connection-authoring" type="checkbox"> <?php esc_html_e( 'Allow reusable layouts, private file transfers and portable previews', 'atshift-deft' ); ?></label></p>
            <?php if ( Access::publisher() ) : ?>
            <p><label><input id="sf-connection-publishing" type="checkbox"> <?php esc_html_e( 'Allow immediate and scheduled public publication of my drafts', 'atshift-deft' ); ?></label></p>
            <?php endif; ?>
            <button id="sf-connection-create" type="submit" class="button button-primary" disabled><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span> <?php esc_html_e( 'Create connection', 'atshift-deft' ); ?></button>
        </form>
        <?php if ( Access::publisher() ) : ?><p><?php esc_html_e( 'Publishing access lets this AI publish without another WordPress approval. Revoking the key cancels pending reservations; key expiry does not. Existing draft-only keys stay draft-only.', 'atshift-deft' ); ?></p><?php endif; ?>
        <h2><?php esc_html_e( '2. Add it to your AI\'s connection settings', 'atshift-deft' ); ?></h2>
        <label for="sf-endpoint"><?php esc_html_e( 'MCP endpoint', 'atshift-deft' ); ?></label>
        <div class="sf-toolbar"><input id="sf-endpoint" type="text" class="large-text code" readonly>
            <button id="sf-endpoint-copy" type="button" class="button" title="<?php esc_attr_e( 'Copy MCP endpoint', 'atshift-deft' ); ?>" aria-label="<?php esc_attr_e( 'Copy MCP endpoint', 'atshift-deft' ); ?>"><span class="dashicons dashicons-admin-page" aria-hidden="true"></span></button></div>
        <p><?php esc_html_e( 'This route uses Streamable HTTP with an Authorization: Bearer header. OAuth clients use the separate OAuth setup.', 'atshift-deft' ); ?></p>
        <section id="sf-client-template-section" hidden>
            <p id="sf-client-environment"></p>
            <details><summary><?php esc_html_e( 'Configuration template (no secret)', 'atshift-deft' ); ?></summary>
                <textarea id="sf-client-template" aria-label="<?php esc_attr_e( 'Configuration template (no secret)', 'atshift-deft' ); ?>" readonly class="large-text code" rows="8" spellcheck="false"></textarea>
            </details>
            <button id="sf-client-template-download" type="button" class="button"><span class="dashicons dashicons-download" aria-hidden="true"></span> <?php esc_html_e( 'Download configuration template', 'atshift-deft' ); ?></button>
        </section>
        <section id="sf-connection-secret" hidden>
            <p><?php esc_html_e( 'Shown once. Put this key in your AI app\'s secure connection settings, not in a chat or the AI guide.', 'atshift-deft' ); ?></p>
            <label for="sf-connection-token"><?php esc_html_e( 'Connection key', 'atshift-deft' ); ?></label>
            <input id="sf-connection-token" type="password" readonly class="large-text code" autocomplete="off">
            <div class="sf-toolbar">
                <button id="sf-connection-copy" type="button" class="button" title="<?php esc_attr_e( 'Copy connection key', 'atshift-deft' ); ?>" aria-label="<?php esc_attr_e( 'Copy connection key', 'atshift-deft' ); ?>"><span class="dashicons dashicons-admin-page" aria-hidden="true"></span></button>
                <button id="sf-connection-hide" type="button" class="button"><?php esc_html_e( 'Done', 'atshift-deft' ); ?></button>
            </div>
        </section>
        </section>
        <h2><?php esc_html_e( 'Return to the same AI conversation', 'atshift-deft' ); ?></h2>
        <p id="sf-connection-check" role="status"><?php esc_html_e( 'Ask your AI to check the connection and continue your article. It will confirm a successful live context response before proceeding. Never paste the key in chat.', 'atshift-deft' ); ?></p>
        <div class="sf-toolbar"><h3><?php esc_html_e( 'Your connections', 'atshift-deft' ); ?></h3><button id="sf-connection-refresh" type="button" class="button" title="<?php esc_attr_e( 'Check connection', 'atshift-deft' ); ?>" aria-label="<?php esc_attr_e( 'Check connection', 'atshift-deft' ); ?>"><span class="dashicons dashicons-update" aria-hidden="true"></span></button></div>
        <ul id="sf-connections"></ul>
    </div>
    <?php
}
