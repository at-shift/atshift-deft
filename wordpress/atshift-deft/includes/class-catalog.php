<?php
namespace Atshift\Deft;

defined( 'ABSPATH' ) || exit;

final class Catalog {
    const MAX_BYTES = 524288;
    public static function table() { global $wpdb; return $wpdb->prefix . 'atshde_catalog'; }
    public static function versions() { global $wpdb; return $wpdb->prefix . 'atshde_components'; }
    public static function permission() {
        return self::can_register() || self::can_manage() ? true : Access::denied();
    }
    public static function can_register() { return Access::workspace() && ( current_user_can( 'manage_options' ) || current_user_can( 'atshde_register_components' ) ); }
    public static function can_manage() { return Access::workspace() && ( current_user_can( 'manage_options' ) || current_user_can( 'atshde_manage_components' ) ); }
    private static function visible( $row ) { return $row && ( 0 === (int) $row['owner_id'] || get_current_user_id() === (int) $row['owner_id'] ); }
    private static function editable( $row ) { return self::visible( $row ) && ( $row['owner_id'] ? Access::workspace() : self::can_manage() ); }

    public static function shared_limit( $actor ) {
        $limit = apply_filters( 'atshde_shared_component_limit_per_user', apply_filters( 'sf_shared_component_limit_per_user', 20, $actor ), $actor );
        return is_int( $limit ) && $limit >= 0 ? min( 100, $limit ) : 20;
    }

    public static function shared_usage( $actor ) {
        global $wpdb; $table = self::table(); $versions = self::versions();
        // Legacy timestamps can tie: charge each earliest writer, never the latest editor.
        $count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i c WHERE c.owner_id=0 AND (
            c.creator_id=%d OR (c.creator_id IS NULL AND (
                NOT EXISTS (SELECT 1 FROM %i missing WHERE missing.component_id=c.component_id) OR
                EXISTS (SELECT 1 FROM %i v WHERE v.component_id=c.component_id AND v.created_by=%d AND
                    NOT EXISTS (SELECT 1 FROM %i earlier WHERE earlier.component_id=v.component_id AND earlier.created_at<v.created_at))
            )))', $table, $actor, $versions, $versions, $actor, $versions ) );
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; REST JSON-encodes messages and HTML callers escape at output.
        if ( null === $count ) throw new \RuntimeException( __( 'Shared component usage is unavailable.', 'atshift-deft' ) );
        return (int) $count;
    }

    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table(); $versions = self::versions();
        dbDelta( "CREATE TABLE $table (
            component_id varchar(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            owner_id bigint(20) unsigned NOT NULL DEFAULT 0,
            creator_id bigint(20) unsigned DEFAULT NULL,
            definition_kind varchar(16) NOT NULL DEFAULT 'design',
            machine_key varchar(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            guidance longtext NOT NULL,
            recipe longtext NOT NULL,
            reuse_note longtext NOT NULL,
            revision char(64) NOT NULL,
            version bigint(20) unsigned NOT NULL,
            enabled tinyint(1) NOT NULL DEFAULT 1,
            updated_by bigint(20) unsigned NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (component_id),
            UNIQUE KEY owner_key (owner_id,machine_key),
            KEY creator_owner (creator_id,owner_id)
        ) ENGINE=InnoDB {$wpdb->get_charset_collate()};" );
        // Migrate the pre-owner global uniqueness constraint; dbDelta does not drop indexes.
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name=%s', $table, 'machine_key' ) ) ) $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP INDEX machine_key', $table ) );
        dbDelta( "CREATE TABLE $versions (
            component_id varchar(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            revision char(64) NOT NULL,
            payload longtext NOT NULL,
            created_by bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (component_id,revision)
        ) ENGINE=InnoDB {$wpdb->get_charset_collate()};" );
        if ( 'creator_id' !== $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, $wpdb->esc_like( 'creator_id' ) ) ) ) return;
        ComponentUsage::install();
        try {
            // Shipped examples are trusted defaults; never reactivate or replace an existing entry.
            foreach ( array( 'sample.json', 'media-sample.json' ) as $file ) {
                foreach ( json_decode( file_get_contents( dirname( __DIR__ ) . '/' . $file ) )->components as $c ) {
                    if ( self::read( $c->id ) ) continue;
                    $result = self::write( $c->id, Snapshot::component( $c ), 0, true, 0 );
                    if ( is_wp_error( $result ) ) return;
                }
            }
            update_option( 'atshde_catalog_schema_version', 4, false );
        } catch ( \Throwable $error ) { return; }
    }

    public static function read( $id, $revision = null ) {
        global $wpdb; $table = self::table(); $versions = self::versions();
        if ( ! is_string( $id ) || ! preg_match( '/^[a-zA-Z0-9][a-zA-Z0-9_-]{0,79}$/D', $id ) ) return null;
        if ( null === $revision ) {
            return $wpdb->get_row( $wpdb->prepare( 'SELECT c.*,v.revision AS selected_revision,v.payload,v.created_by,v.created_at FROM %i c INNER JOIN %i v ON c.component_id=v.component_id AND v.revision=c.revision WHERE c.component_id=%s', $table, $versions, $id ), ARRAY_A );
        }
        return $wpdb->get_row( $wpdb->prepare( 'SELECT c.*,v.revision AS selected_revision,v.payload,v.created_by,v.created_at FROM %i c INNER JOIN %i v ON c.component_id=v.component_id AND v.revision=%s WHERE c.component_id=%s', $table, $versions, $revision, $id ), ARRAY_A );
    }

    public static function available( $id, $revision ) {
        $row = self::read( $id );
        return self::visible( $row ) && $row['enabled'] && hash_equals( $row['revision'], $revision );
    }

    public static function response( $row, $full = true ) {
        $c = json_decode( $row['payload'] );
        $out = array( 'id' => $row['component_id'], 'revision' => $row['selected_revision'], 'version' => (int) $row['version'],
            'enabled' => (bool) $row['enabled'], 'availableForNewArticles' => (bool) $row['enabled'] && $row['revision'] === $row['selected_revision'],
            'name' => $c->name, 'key' => $c->key, 'purpose' => $c->purpose, 'fieldCount' => count( $c->fields ),
            'variantCount' => count( $c->variants ), 'updatedAt' => $row['updated_at'] );
        $out['scope'] = $row['owner_id'] ? 'personal' : 'shared';
        $out['definitionKind'] = $row['definition_kind'];
        $out['usedRevisions'] = array_values( array_column( array_filter( ComponentUsage::references(), static function( $ref ) use ( $row ) { return $ref['id'] === $row['component_id']; } ), 'revision' ) );
        $out['usedByMe'] = (bool) $out['usedRevisions'];
        $out['canEdit'] = self::editable( $row );
        $out['guidance'] = json_decode( $row['guidance'] ) ?: (object) array( 'contentKind' => 'general', 'suitableFor' => array(), 'avoidFor' => array() );
        $recipe = json_decode( $row['recipe'] );
        $out['layout'] = $recipe->layout ?? 'custom';
        $current_recipe = $row['revision'] === $row['selected_revision'];
        $out['appearance'] = $current_recipe ? ( $recipe->appearance ?? null ) : null;
        // Only content-free shared recipes are discoverable through the AI context.
        $out['structureRecipe'] = $current_recipe && 'structure' === $row['definition_kind'] ? $recipe : null;
        $out['presentations'] = array_map( static function( $v ) use ( $recipe ) { return array( 'id' => $v->id, 'name' => $v->name,
            'disclosure' => isset( $recipe->layout ) && 'qa' === $recipe->layout ? ( 'accordion' === $v->id ? 'expandable' : 'visible' ) : 'unspecified' ); }, $c->variants );
        $out['fields'] = array_map( static function( $f ) { return array( 'key' => $f->key, 'type' => $f->type, 'label' => $f->label, 'required' => $f->required ); }, $c->fields );
        $out['behaviors'] = $c->behaviors ?? array();
        $out['behaviorWarnings'] = Behaviors::warnings( (object) array( 'components' => array( $c ) ) );
        if ( $full ) { $out['component'] = $c; $out['recipe'] = $recipe; }
        return $out;
    }

    public static function visible_rows( $inactive = false ) {
        global $wpdb; $table = self::table(); $versions = self::versions();
        return $wpdb->get_results( $wpdb->prepare( 'SELECT c.*,v.revision AS selected_revision,v.payload FROM %i c INNER JOIN %i v ON c.component_id=v.component_id AND c.revision=v.revision WHERE (c.owner_id=0 OR c.owner_id=%d) AND (%d=1 OR c.enabled=1) ORDER BY c.component_id', $table, $versions, get_current_user_id(), (int) (bool) $inactive ), ARRAY_A );
    }

    public static function listing( $request ) {
        $page = $request->get_param( 'page' ) ?? '1';
        $all = $request->get_param( 'includeDisabled' ) ?? '0';
        $scope = $request->get_param( 'scope' ) ?? 'all';
        if ( ! is_scalar( $page ) || ! preg_match( '/^[1-9][0-9]{0,3}$/D', (string) $page ) || ! in_array( $all, array( '0', '1' ), true ) ) return new \WP_Error( 'sf_query', __( 'Invalid catalog query.', 'atshift-deft' ), array( 'status' => 400 ) );
        if ( ! in_array( $scope, array( 'all', 'personal', 'shared', 'used' ), true ) ) return new \WP_Error( 'sf_query', __( 'Invalid catalog scope.', 'atshift-deft' ), array( 'status' => 400 ) );
        if ( '1' === $all && 'personal' !== $scope && true !== self::permission() ) return Access::denied();
        $used = array_column( ComponentUsage::references(), 'id' );
        $rows = array_values( array_filter( self::visible_rows( '1' === $all ), static function( $row ) use ( $scope, $used ) {
            if ( 'used' === $scope ) return in_array( $row['component_id'], $used, true );
            return 'all' === $scope || ( 'personal' === $scope ? (bool) $row['owner_id'] : ! $row['owner_id'] );
        } ) );
        $count = count( $rows ); $rows = array_slice( $rows, ( (int) $page - 1 ) * 20, 20 );
        return array( 'items' => array_map( static function( $row ) { return self::response( $row, false ); }, $rows ), 'page' => (int) $page, 'totalPages' => (int) ceil( $count / 20 ) );
    }

    public static function get( $request ) {
        $revision = $request->get_param( 'revision' );
        if ( null !== $revision && ( ! is_string( $revision ) || ! preg_match( '/^[a-f0-9]{64}$/D', $revision ) ) ) return new \WP_Error( 'sf_revision', __( 'Invalid revision.', 'atshift-deft' ), array( 'status' => 400 ) );
        $row = self::read( $request['id'], $revision );
        return self::visible( $row ) ? self::response( $row ) : new \WP_Error( 'sf_missing', __( 'Component not found.', 'atshift-deft' ), array( 'status' => 404 ) );
    }

    public static function schemas() {
        $snapshot = Snapshot::schema( 3 );
        $component = $snapshot['properties']['components']['items'];
        $component['required'] = array_values( array_diff( $component['required'], array( 'revision' ) ) );
        $component['properties']['id']['pattern'] = '^(?!(?:schema|context|personal|structures)$)[a-zA-Z0-9][a-zA-Z0-9_-]{0,79}$';
        $expected = array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 9007199254740991 );
        return array( 'schemaVersion' => 1, 'register' => array( 'type' => 'object', 'additionalProperties' => false,
            'required' => array( 'component', 'expectedVersion' ), 'properties' => array( 'component' => $component, 'expectedVersion' => $expected, 'guidance' => ComponentBuilder::guidance_schema() ) ),
            'personal' => array( 'type' => 'object', 'additionalProperties' => false, 'required' => array( 'recipe', 'expectedVersion', 'reuse' ),
                'properties' => array( 'recipe' => ComponentBuilder::schema(), 'expectedVersion' => $expected, 'reuse' => CatalogContext::reuse_schema() ) ),
            'structure' => array( 'type' => 'object', 'additionalProperties' => false, 'required' => array( 'recipe', 'expectedVersion', 'reuse' ),
                'properties' => array( 'recipe' => ComponentBuilder::structure_schema(), 'expectedVersion' => $expected, 'reuse' => CatalogContext::reuse_schema() ) ),
            'compose' => array( 'type' => 'object', 'additionalProperties' => false, 'required' => array( 'article', 'expectedVersion' ),
                'properties' => array( 'article' => $snapshot['properties']['article'], 'expectedVersion' => $expected ) ) );
    }

    private static function body( $request, $limit ) {
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; REST JSON-encodes messages and HTML callers escape at output.
        if ( ! $request->is_json_content_type() || strlen( $request->get_body() ) > $limit ) throw new \InvalidArgumentException( __( 'Use JSON within the request size limit.', 'atshift-deft' ) );
        return json_decode( $request->get_body(), false, 128, JSON_THROW_ON_ERROR );
    }

    public static function register( $request ) {
        if ( true !== self::permission() ) return Access::denied();
        try {
            $body = self::body( $request, self::MAX_BYTES );
            Snapshot::shape( $body, self::schemas()['register'] );
            $c = Snapshot::component( $body->component );
            Behaviors::assert_available( $c );
            $current = self::read( $c->id );
            if ( $current ? ( $current['owner_id'] || ! self::can_manage() ) : ! self::can_register() ) return Access::denied();
            if ( $current && 'structure' === $current['definition_kind'] ) return new \WP_Error( 'sf_structure_required', __( 'Use the content-free structure recipe to update this definition.', 'atshift-deft' ), array( 'status' => 400 ) );
            return self::write( $c->id, $c, $body->expectedVersion, true, get_current_user_id(), 0, $body->guidance ?? null );
        } catch ( \Throwable $error ) { return new \WP_Error( 'sf_component', $error->getMessage(), array( 'status' => 400 ) ); }
    }

    public static function availability( $request ) {
        $current = self::read( $request['id'] );
        if ( ! self::editable( $current ) ) return Access::denied();
        try {
            $body = self::body( $request, 1024 );
            Snapshot::shape( $body, array( 'type' => 'object', 'additionalProperties' => false, 'required' => array( 'expectedVersion', 'enabled' ),
                'properties' => array( 'expectedVersion' => self::schemas()['register']['properties']['expectedVersion'], 'enabled' => array( 'type' => 'boolean' ) ) ) );
            return self::write( $request['id'], null, $body->expectedVersion, $body->enabled, get_current_user_id(), (int) $current['owner_id'] );
        } catch ( \Throwable $error ) { return new \WP_Error( 'sf_component', $error->getMessage(), array( 'status' => 400 ) ); }
    }

    private static function write( $id, $component, $expected, $enabled, $actor, $owner = 0, $guidance = null, $recipe = null, $reuse = null, $sharing = false, $kind = null ) {
        global $wpdb; $table = self::table(); $versions = self::versions();
        $lock = 'sf_catalog_' . substr( hash( 'sha256', DB_NAME . ':' . $table ), 0, 50 );
        if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,3)', $lock ) ) ) return new \WP_Error( 'sf_busy', __( 'Catalog is busy. Retry later.', 'atshift-deft' ), array( 'status' => 409 ) );
        try {
            $current = self::read( $id ); $version = $current ? (int) $current['version'] : 0;
            if ( $sharing ) {
                $source = self::read( $sharing['id'] );
                if ( ! $source || (int) $source['owner_id'] !== $actor || (int) $source['version'] !== $sharing['version'] ) return new \WP_Error( 'sf_conflict', __( 'Personal component changed before sharing.', 'atshift-deft' ), array( 'status' => 409 ) );
            }
            if ( $current && (int) $current['owner_id'] !== $owner ) return Access::denied();
            if ( $expected !== $version ) return new \WP_Error( 'sf_conflict', __( 'Catalog changed. Reload before editing.', 'atshift-deft' ), array( 'status' => 409 ) );
            if ( ! $current && ! $component ) return new \WP_Error( 'sf_missing', __( 'Component not found.', 'atshift-deft' ), array( 'status' => 404 ) );
            if ( $component && $current && $current['machine_key'] !== $component->key ) return new \WP_Error( 'sf_key', __( 'Keep the registered machine key when updating a component.', 'atshift-deft' ), array( 'status' => 409 ) );
            if ( $component && $wpdb->get_var( $wpdb->prepare( 'SELECT component_id FROM %i WHERE owner_id=%d AND machine_key=%s AND component_id<>%s', $table, $owner, $component->key, $id ) ) ) return new \WP_Error( 'sf_key', __( 'This machine key is already registered in this scope.', 'atshift-deft' ), array( 'status' => 409 ) );
            $revision = $component ? $component->revision : $current['revision'];
            $guidance_json = null !== $guidance ? Snapshot::json( $guidance ) : ( $current['guidance'] ?? '' );
            if ( $current && $revision === $current['revision'] && $guidance_json === $current['guidance'] && $enabled === (bool) $current['enabled'] ) return self::response( $current );
            if ( 'structure' === $kind && $current && ! self::can_manage() ) return Access::denied();
            if ( 'structure' === $kind && $current && 'structure' !== $current['definition_kind'] ) return new \WP_Error( 'sf_structure_required', __( 'Existing definitions are not converted automatically.', 'atshift-deft' ), array( 'status' => 409 ) );
            if ( $recipe ) {
                $fingerprint = ComponentBuilder::fingerprint( $component );
                // Resolve identical global structures under the lock, including simultaneous creates.
                if ( 'structure' === $kind && ! $current ) {
                    $inactive = null;
                    foreach ( self::visible_rows( true ) as $other ) {
                        if ( $other['owner_id'] || ! hash_equals( $fingerprint, ComponentBuilder::fingerprint( json_decode( $other['payload'] ) ) ) ) continue;
                        if ( ! $other['enabled'] ) { $inactive = $other; continue; }
                        return array_merge( self::response( $other ), array( 'reused' => true ) );
                    }
                    if ( $inactive ) return new \WP_Error( 'sf_component_inactive', __( 'An equivalent shared component is inactive. Ask a component manager to review it.', 'atshift-deft' ), array( 'status' => 409, 'componentId' => $inactive['component_id'] ) );
                }
                $check = CatalogContext::check_reuse( $reuse );
                if ( is_wp_error( $check ) && ! $sharing ) return $check;
                foreach ( self::visible_rows( true ) as $other ) {
                    if ( $other['component_id'] === $id || ( ( $sharing || 'structure' === $kind ) && $other['owner_id'] ) ) continue;
                    if ( hash_equals( $fingerprint, ComponentBuilder::fingerprint( json_decode( $other['payload'] ) ) ) ) return new \WP_Error( 'sf_reuse_component', __( 'Reuse the existing definition instead of creating a copy.', 'atshift-deft' ), array( 'status' => 409, 'componentId' => $other['component_id'], 'revision' => $other['revision'], 'enabled' => (bool) $other['enabled'] ) );
                }
                if ( ! $current && $owner && (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE owner_id=%d', $table, $owner ) ) >= 20 ) return new \WP_Error( 'sf_component_quota', __( 'Personal component limit reached (20). Reuse or revise existing definitions.', 'atshift-deft' ), array( 'status' => 409 ) );
            }
            if ( ! $current && 0 === $owner && $actor > 0 ) {
                $limit = self::shared_limit( $actor ); $used = self::shared_usage( $actor );
                if ( $used >= $limit ) return new \WP_Error( 'sf_shared_component_quota', __( 'Your shared component creation limit is reached. Reuse existing definitions or ask the site administrator to adjust the limit.', 'atshift-deft' ), array( 'status' => 409, 'limit' => $limit, 'used' => $used ) );
            }
            $payload = $component ? Snapshot::json( $component ) : $current['payload'];
            if ( strlen( $payload ) > self::MAX_BYTES ) throw new \RuntimeException( __( 'Component exceeds 512 KiB.', 'atshift-deft' ) );
            $existing = self::read( $id, $revision );
            if ( ! $existing && ( ( ! $current && (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) ) >= 100 ) ||
                (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE component_id=%s', $versions, $id ) ) >= 30 ||
                (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(OCTET_LENGTH(payload)),0) FROM %i', $versions ) ) + strlen( $payload ) > 32 * 1024 * 1024 ) ) throw new \RuntimeException( __( 'Catalog storage limit reached.', 'atshift-deft' ) );
            foreach ( array( $table, $versions ) as $name ) {
                if ( 'InnoDB' !== $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $name ) ) ) throw new \RuntimeException( __( 'InnoDB tables are required.', 'atshift-deft' ) );
            }
            if ( false === $wpdb->query( 'START TRANSACTION' ) ) throw new \RuntimeException( __( 'Could not start transaction.', 'atshift-deft' ) );
            $now = current_time( 'mysql', true );
            if ( ! $existing && false === $wpdb->insert( $versions, array( 'component_id' => $id, 'revision' => $revision, 'payload' => $payload, 'created_by' => $actor, 'created_at' => $now ) ) ) throw new \RuntimeException( __( 'Could not save component.', 'atshift-deft' ) );
            $record = array( 'owner_id' => $owner, 'definition_kind' => $kind ?? ( $current['definition_kind'] ?? 'design' ), 'guidance' => $guidance_json, 'recipe' => $recipe ? Snapshot::json( $recipe ) : ( $component && $revision !== ( $current['revision'] ?? '' ) ? '' : ( $current['recipe'] ?? '' ) ), 'reuse_note' => $reuse ? Snapshot::json( $reuse ) : ( $current['reuse_note'] ?? '' ),
                'machine_key' => $component ? $component->key : $current['machine_key'], 'revision' => $revision, 'version' => $version + 1, 'enabled' => $enabled ? 1 : 0, 'updated_by' => $actor, 'updated_at' => $now );
            $ok = $current ? $wpdb->update( $table, $record, array( 'component_id' => $id ) ) : $wpdb->insert( $table, array_merge( array( 'component_id' => $id, 'creator_id' => $actor ), $record ) );
            if ( false === $ok || false === $wpdb->query( 'COMMIT' ) ) throw new \RuntimeException( __( 'Could not save catalog.', 'atshift-deft' ) );
            return self::response( self::read( $id ) );
        } catch ( \Throwable $error ) {
            $wpdb->query( 'ROLLBACK' );
            return new \WP_Error( 'sf_catalog_storage', $error->getMessage(), array( 'status' => 503 ) );
        } finally { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); }
    }

    public static function personal( $request ) {
        if ( ! Access::workspace() ) return Access::denied();
        try {
            $body = self::body( $request, self::MAX_BYTES ); Snapshot::shape( $body, self::schemas()['personal'] );
            $c = ComponentBuilder::build( $body->recipe );
            Behaviors::assert_available( $c );
            return self::write( $c->id, $c, $body->expectedVersion, true, get_current_user_id(), get_current_user_id(), $body->recipe->guidance, $body->recipe, $body->reuse );
        } catch ( \Throwable $error ) { return new \WP_Error( 'sf_personal_component', $error->getMessage(), array( 'status' => 400 ) ); }
    }

    public static function structure( $request ) {
        if ( ! Access::workspace() ) return Access::denied();
        try {
            $body = self::body( $request, self::MAX_BYTES ); Snapshot::shape( $body, self::schemas()['structure'] );
            $c = ComponentBuilder::structure( $body->recipe );
            Behaviors::assert_available( $c );
            return self::write( $c->id, $c, $body->expectedVersion, true, get_current_user_id(), 0, $body->recipe->guidance, $body->recipe, $body->reuse, false, 'structure' );
        } catch ( \Throwable $error ) { return new \WP_Error( 'sf_structure', $error->getMessage(), array( 'status' => 400 ) ); }
    }

    public static function share( $request ) {
        if ( ! self::can_register() ) return Access::denied();
        try {
            // The JSON id names the destination; the route id identifies the private source.
            $source_id = $request->get_url_params()['id'];
            $row = self::read( $source_id );
            if ( ! $row || (int) $row['owner_id'] !== get_current_user_id() ) return Access::denied();
            $body = self::body( $request, 1024 ); $props = self::schemas()['register']['properties'];
            Snapshot::shape( $body, array( 'type' => 'object', 'additionalProperties' => false, 'required' => array( 'id', 'key', 'expectedVersion' ),
                'properties' => array( 'id' => $props['component']['properties']['id'], 'key' => $props['component']['properties']['key'], 'expectedVersion' => $props['expectedVersion'] ) ) );
            if ( (int) $row['version'] !== $body->expectedVersion ) return new \WP_Error( 'sf_conflict', __( 'Reload the personal component before sharing.', 'atshift-deft' ), array( 'status' => 409 ) );
            $recipe = json_decode( $row['recipe'] ); $recipe->id = $body->id; $recipe->key = $body->key;
            $component = ComponentBuilder::build( $recipe ); Behaviors::assert_available( $component );
            return self::write( $recipe->id, $component, 0, true, get_current_user_id(), 0, $recipe->guidance, $recipe, null, array( 'id' => $source_id, 'version' => $body->expectedVersion ) );
        } catch ( \Throwable $error ) { return new \WP_Error( 'sf_share_component', $error->getMessage(), array( 'status' => 400 ) ); }
    }

    public static function compose( $request ) {
        if ( ! Access::workspace() ) return Access::denied();
        try {
            $body = self::body( $request, 4 * 1024 * 1024 );
            Snapshot::shape( $body, self::schemas()['compose'] );
            $current = Store::read( $body->article->id );
            if ( $current && ! Access::article( $body->article->id ) ) return Access::denied();
            $pinned = $components = array();
            if ( $current ) foreach ( json_decode( $current['payload'] )->components as $c ) $pinned[$c->id . '@' . $c->revision] = $c;
            foreach ( $body->article->blocks as $block ) {
                $key = $block->componentId . '@' . $block->componentRevision;
                if ( isset( $components[$key] ) ) continue;
                if ( isset( $pinned[$key] ) ) $components[$key] = $pinned[$key];
                elseif ( self::available( $block->componentId, $block->componentRevision ) ) $components[$key] = json_decode( self::read( $block->componentId )['payload'] );
                else return new \WP_Error( 'sf_component_unavailable', __( 'Choose an active catalog revision or a component pinned in your saved article.', 'atshift-deft' ), array( 'status' => 409 ) );
            }
            $version = 2;
            foreach ( $components as $c ) if ( property_exists( $c, 'behaviors' ) ) $version = 3;
            foreach ( $body->article->blocks as $b ) if ( property_exists( $b, 'behaviorSettings' ) ) $version = 3;
            if ( $current && 3 === ( json_decode( $current['payload'] )->schemaVersion ?? 1 ) ) $version = 3;
            $snapshot = array( 'schemaVersion' => $version, 'article' => $body->article, 'components' => array_values( $components ) );
            return Store::save( Snapshot::validate( Snapshot::json( $snapshot ) ), $body->expectedVersion );
        } catch ( \Throwable $error ) { return new \WP_Error( 'sf_compose', $error->getMessage(), array( 'status' => 400 ) ); }
    }
}
