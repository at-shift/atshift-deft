<?php
namespace StructuredFields;

defined( 'ABSPATH' ) || exit;

final class ComponentBuilder {
    public static function guidance_schema() {
        $list = array( 'type' => 'array', 'maxItems' => 5, 'items' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 300 ) );
        return array( 'type' => 'object', 'additionalProperties' => false, 'required' => array( 'contentKind', 'suitableFor', 'avoidFor' ),
            'properties' => array( 'contentKind' => array( 'type' => 'string', 'pattern' => '^[a-z][a-z0-9_]{0,79}$' ), 'suitableFor' => $list, 'avoidFor' => $list ) );
    }

    public static function schema() {
        $c = Snapshot::schema()['properties']['components']['items']['properties'];
        $properties = array_intersect_key( $c, array_flip( array( 'id', 'key', 'name', 'purpose', 'fields' ) ) );
        $properties['id']['pattern'] = '^(?!(?:schema|context|personal|structures)$)[a-zA-Z0-9][a-zA-Z0-9_-]{0,79}$';
        $properties['fields']['minItems'] = 1; $properties['fields']['maxItems'] = 12;
        $properties['layout'] = array( 'type' => 'string', 'enum' => array( 'stack', 'columns', 'qa' ) );
        $properties['guidance'] = self::guidance_schema();
        $required = array_keys( $properties );
        $properties['behaviors'] = Behaviors::bindings_schema();
        return array( 'type' => 'object', 'additionalProperties' => false, 'required' => $required, 'properties' => $properties );
    }

    public static function build( $recipe ) {
        Snapshot::shape( $recipe, self::schema() );
        $qa = 'qa' === $recipe->layout;
        if ( $qa && ( 2 !== count( $recipe->fields ) || 'question' !== $recipe->fields[0]->key || 'text' !== $recipe->fields[0]->type ||
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; REST JSON-encodes messages and HTML callers escape at output.
            'answer' !== $recipe->fields[1]->key || 'textarea' !== $recipe->fields[1]->type ) ) throw new \InvalidArgumentException( __( 'Q&A requires question (text) and answer (textarea), in that order.', 'atshift-deft' ) );
        $html = '<section class="sf-built sf-built-' . $recipe->layout . '"' . ( $qa ? ' data-sf-layout="qa"' : '' ) . '>';
        foreach ( $recipe->fields as $index => $f ) {
            $key = esc_attr( $f->key ); $value = esc_html( $f->value );
            $tag = 'text' === $f->type && 0 === $index ? 'h3' : 'p';
            if ( 'image' === $f->type ) $field = '<img data-field="' . $key . '" src="' . esc_attr( $f->value ) . '" alt="' . esc_attr( $f->alt ) . '">';
            elseif ( 'link' === $f->type ) $field = '<a data-field="' . $key . '" href="' . esc_attr( $f->value ) . '">' . esc_html( $f->alt ) . '</a>';
            else $field = '<' . $tag . ' data-field="' . $key . '">' . $value . '</' . $tag . '>';
            $html .= $qa ? $field : '<div class="sf-built-field">' . $field . '</div>';
        }
        $html .= '</section>';
        $c = (object) array( 'id' => $recipe->id, 'key' => $recipe->key, 'name' => $recipe->name, 'purpose' => $recipe->purpose,
            'html' => $html, 'css' => '.sf-built{margin:1.5em 0}.sf-built img{max-width:100%;height:auto}.sf-built img[hidden]{display:none}.sf-built p{white-space:pre-line}.sf-built-columns{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:1.5em}.sf-built-field{min-width:0;overflow-wrap:anywhere}.sf-built-qa{border-bottom:1px solid #ccc;padding:1em 0}.sf-built-qa summary{cursor:pointer;font-weight:600}.sf-built-qa summary:focus-visible{outline:2px solid currentColor;outline-offset:4px}@media(max-width:600px){.sf-built-columns{grid-template-columns:1fr}}',
            'fields' => $recipe->fields, 'variants' => $qa ? array(
                (object) array( 'id' => 'list', 'name' => 'Visible answer', 'classes' => '' ),
                (object) array( 'id' => 'accordion', 'name' => 'Expandable answer', 'classes' => '' ),
            ) : array( (object) array( 'id' => 'default', 'name' => 'Default', 'classes' => '' ) ) );
        if ( property_exists( $recipe, 'behaviors' ) ) $c->behaviors = $recipe->behaviors;
        return Snapshot::component( $c );
    }

    public static function structure_schema() {
        $schema = self::schema();
        $field = &$schema['properties']['fields']['items'];
        unset( $field['properties']['value'], $field['properties']['alt'] );
        $field['required'] = array_values( array_diff( $field['required'], array( 'value', 'alt' ) ) );
        return $schema;
    }

    public static function structure( $recipe ) {
        Snapshot::shape( $recipe, self::structure_schema() );
        $empty = json_decode( Snapshot::json( $recipe ) );
        foreach ( $empty->fields as $field ) { $field->value = ''; $field->alt = ''; }
        return self::build( $empty );
    }

    public static function structure_example() {
        $recipe = self::example();
        foreach ( $recipe->fields as $field ) { unset( $field->value, $field->alt ); }
        return $recipe;
    }

    public static function fingerprint( $c ) {
        $dom = new \DOMDocument(); $previous = libxml_use_internal_errors( true );
        $dom->loadHTML( '<?xml encoding="UTF-8"><html><body>' . $c->html . '</body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
        libxml_clear_errors(); libxml_use_internal_errors( $previous );
        foreach ( $dom->getElementsByTagName( '*' ) as $el ) {
            if ( ! $el->hasAttribute( 'data-field' ) ) continue;
            while ( $el->firstChild ) $el->removeChild( $el->firstChild );
            foreach ( array( 'src', 'href', 'alt' ) as $attr ) $el->removeAttribute( $attr );
        }
        $fields = array_map( static function( $f ) { return array( $f->key, $f->type, $f->required ); }, $c->fields );
        $variants = array_map( static function( $v ) { return array( $v->id, $v->classes ); }, $c->variants );
        $parts = array( $dom->saveHTML( $dom->getElementsByTagName( 'body' )->item( 0 ) ), trim( $c->css ), $fields, $variants );
        if ( ! empty( $c->behaviors ) ) {
            // Local binding names/order do not make otherwise identical structures different.
            $behaviors = array_map( static function( $ref ) {
                $settings = get_object_vars( $ref->settings ); ksort( $settings, SORT_STRING );
                return array( $ref->id, $ref->revision, $ref->target, $settings );
            }, $c->behaviors );
            usort( $behaviors, static function( $a, $b ) { return strcmp( Snapshot::json( $a ), Snapshot::json( $b ) ); } );
            $parts[] = $behaviors;
        }
        return hash( 'sha256', Snapshot::json( $parts ) );
    }

    public static function example() {
        return (object) array( 'id' => 'component-' . wp_generate_uuid4(), 'key' => 'qa_' . str_replace( '-', '_', wp_generate_uuid4() ),
            'name' => 'Questions and answers', 'purpose' => 'Answer a reader question without changing its meaning between presentations.', 'layout' => 'qa',
            'guidance' => (object) array( 'contentKind' => 'qa', 'suitableFor' => array( 'Short visible answers or selectively read longer answers.' ), 'avoidFor' => array( 'Do not hide essential warnings in collapsed answers.' ) ),
            'fields' => array(
                (object) array( 'id' => 'question', 'key' => 'question', 'label' => 'Question', 'type' => 'text', 'required' => true, 'value' => 'Do I need to book?', 'alt' => '' ),
                (object) array( 'id' => 'answer', 'key' => 'answer', 'label' => 'Answer', 'type' => 'textarea', 'required' => true, 'value' => 'Please book before your visit.', 'alt' => '' ),
            ) );
    }
}
