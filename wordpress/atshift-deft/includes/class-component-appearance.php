<?php
namespace Atshift\Deft;

defined( 'ABSPATH' ) || exit;

final class ComponentAppearance {
    private static function object_schema( $properties ) {
        return array( 'type' => 'object', 'additionalProperties' => false, 'properties' => $properties );
    }

    public static function schema() {
        $color = array( 'type' => 'string', 'pattern' => '^(#[a-fA-F0-9]{6}|inherit|currentColor)$' );
        $integer = static function( $max ) { return array( 'type' => 'integer', 'minimum' => 0, 'maximum' => $max ); };
        $enum = static function( $values ) { return array( 'type' => 'string', 'enum' => $values ); };
        $box = array( 'backgroundColor' => $color, 'borderColor' => $color, 'borderWidth' => $integer( 8 ),
            'borderRadius' => $integer( 24 ), 'padding' => $integer( 48 ), 'paddingBlock' => $integer( 48 ),
            'paddingInline' => $integer( 48 ), 'shadow' => $enum( array( 'none', 'subtle' ) ) );
        $text = array( 'color' => $color, 'fontSize' => array( 'type' => 'integer', 'minimum' => 12, 'maximum' => 48 ),
            'fontWeight' => $enum( array( 'normal', 'medium', 'bold' ) ), 'textAlign' => $enum( array( 'start', 'center', 'end' ) ),
            'lineHeight' => $enum( array( 'compact', 'normal', 'relaxed' ) ) );
        $field = self::object_schema( array( 'key' => array( 'type' => 'string', 'pattern' => '^[a-z][a-z0-9_]{0,79}$' ),
            'box' => self::object_schema( $box ), 'text' => self::object_schema( $text ),
            'span' => $enum( array( 'auto', 'full' ) ), 'linkStyle' => $enum( array( 'text', 'button' ) ) ) );
        $field['required'] = array( 'key' );
        $schema = self::object_schema( array( 'container' => self::object_schema( $box + $text + array( 'gap' => $integer( 48 ) ) ),
            'columns' => $enum( array( 'equal', 'label-value', 'wide-left', 'wide-right' ) ),
            'fields' => array( 'type' => 'array', 'maxItems' => 12, 'items' => $field ) ) );
        $schema['minProperties'] = 1;
        return $schema;
    }

    private static function declarations( $style ) {
        $out = '';
        // Only these typed values reach CSS. No client selectors, units, URLs or functions.
        $map = array( 'backgroundColor' => 'background-color', 'borderColor' => 'border-color', 'borderWidth' => 'border-width',
            'borderRadius' => 'border-radius', 'padding' => 'padding', 'paddingBlock' => 'padding-block', 'paddingInline' => 'padding-inline',
            'color' => 'color', 'fontSize' => 'font-size', 'fontWeight' => 'font-weight', 'textAlign' => 'text-align', 'lineHeight' => 'line-height', 'gap' => 'gap', 'shadow' => 'box-shadow' );
        $enums = array( 'fontWeight' => array( 'normal' => '400', 'medium' => '600', 'bold' => '700' ),
            'lineHeight' => array( 'compact' => '1.4', 'normal' => '1.7', 'relaxed' => '2' ),
            'shadow' => array( 'none' => 'none', 'subtle' => '0 2px 8px #00000014' ) );
        foreach ( $map as $key => $property ) {
            if ( ! property_exists( $style, $key ) ) continue;
            $value = $style->$key;
            $value = $enums[$key][$value] ?? ( is_int( $value ) ? $value . 'px' : ( str_starts_with( $value, '#' ) ? strtolower( $value ) : $value ) );
            $out .= $property . ':' . $value . ';';
        }
        if ( isset( $style->borderWidth ) || isset( $style->borderColor ) ) {
            $out .= 'border-style:solid;';
            if ( ! isset( $style->borderWidth ) ) $out .= 'border-width:1px;';
        }
        return $out;
    }

    public static function compile( $appearance, $recipe ) {
        Snapshot::shape( $appearance, self::schema() );
        $fields = array(); foreach ( $recipe->fields as $field ) $fields[$field->key] = $field;
        $rules = array();
        $root = isset( $appearance->container ) ? self::declarations( $appearance->container ) : '';
        if ( isset( $appearance->container->gap ) && 'stack' === $recipe->layout ) $root .= 'display:grid;';
        if ( isset( $appearance->container->gap ) && 'qa' === $recipe->layout ) self::invalid();
        if ( isset( $appearance->columns ) ) {
            if ( 'columns' !== $recipe->layout ) self::invalid();
            $columns = array( 'equal' => 'repeat(2,minmax(0,1fr))', 'label-value' => 'minmax(0,1fr) minmax(0,2fr)',
                'wide-left' => 'minmax(0,2fr) minmax(0,1fr)', 'wide-right' => 'minmax(0,1fr) minmax(0,2fr)' );
            $root .= 'grid-template-columns:' . $columns[$appearance->columns] . ';';
        }
        if ( $root ) $rules[''] = 'box-sizing:border-box;min-width:0;max-width:100%;' . $root;
        $seen = array();
        foreach ( $appearance->fields ?? array() as $field ) {
            $key = $field->key;
            if ( ! isset( $fields[$key] ) || isset( $seen[$key] ) ) self::invalid();
            $seen[$key] = true;
            if ( isset( $field->span ) && 'columns' !== $recipe->layout ) self::invalid();
            if ( isset( $field->linkStyle ) && 'link' !== $fields[$key]->type ) self::invalid();
            if ( isset( $field->text ) && 'image' === $fields[$key]->type ) self::invalid();
            $box = isset( $field->box ) ? self::declarations( $field->box ) : '';
            if ( isset( $field->span ) ) $box .= 'grid-column:' . ( 'full' === $field->span ? '1/-1' : 'auto' ) . ';';
            if ( $box ) $rules['box:' . $key] = 'box-sizing:border-box;min-width:0;max-width:100%;' . $box;
            $text = isset( $field->text ) ? self::declarations( $field->text ) : '';
            if ( 'button' === ( $field->linkStyle ?? '' ) ) {
                $text = 'display:inline-flex;align-items:center;justify-content:center;box-sizing:border-box;min-height:44px;max-width:100%;padding:10px 16px;border:1px solid currentColor;border-radius:4px;background-color:inherit;color:inherit;text-decoration:none;white-space:normal;overflow-wrap:anywhere;' . $text;
                $rules['focus:' . $key] = 'outline:2px solid currentColor;outline-offset:3px;';
                $rules['hover:' . $key] = 'text-decoration:underline;';
            }
            if ( $text ) $rules['text:' . $key] = $text;
        }
        ksort( $rules, SORT_STRING );
        if ( ! $rules ) return array( 'class' => '', 'css' => '' );
        // Content-derived scope keeps equivalent designs deduplicable across names/owners.
        $class = 'sf-appearance-' . substr( hash( 'sha256', Snapshot::json( array( 1, $recipe->layout, $rules ) ) ), 0, 24 );
        $scope = '.sf-article > .sf-block > .' . $class;
        $css = '';
        foreach ( $rules as $target => $declarations ) {
            $selector = $scope;
            if ( '' !== $target ) {
                list( $kind, $key ) = explode( ':', $target, 2 );
                $selector .= ' > .sf-appearance-field-' . $key;
                if ( 'qa' === $recipe->layout && 'question' === $key ) $selector = $scope . ' > :is(.sf-appearance-field-question,summary)';
                if ( 'box' !== $kind && 'qa' !== $recipe->layout ) $selector .= ' > .sf-appearance-value-' . $key;
                if ( 'focus' === $kind ) $selector .= ':focus-visible';
                if ( 'hover' === $kind ) $selector .= ':hover';
            }
            $css .= $selector . '{' . $declarations . '}';
        }
        if ( 'columns' === $recipe->layout ) $css .= '@media(max-width:600px){' . $scope . '{grid-template-columns:1fr}}';
        return array( 'class' => $class, 'css' => $css );
    }

    private static function invalid() {
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; REST JSON-encodes messages and HTML callers escape at output.
        throw new \InvalidArgumentException( __( 'Appearance must reference distinct existing field keys and options supported by their types and layout.', 'atshift-deft' ) );
    }
}
