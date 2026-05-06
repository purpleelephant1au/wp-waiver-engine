<?php
namespace WPWE\Vendor;

defined( 'ABSPATH' ) || exit;

final class DbProxy {
    public static function prepare( string $query, ...$args ): string {
        global $wpdb;
        return $wpdb->prepare( $query, ...$args );
    }

    public static function get_row( string $query ) {
        global $wpdb;
        return $wpdb->get_row( $query );
    }

    public static function get_results( string $query, $output = OBJECT ) {
        global $wpdb;
        return $wpdb->get_results( $query, $output );
    }

    public static function get_var( string $query ) {
        global $wpdb;
        return $wpdb->get_var( $query );
    }

    public static function query( string $query ) {
        global $wpdb;
        return $wpdb->query( $query );
    }

    public static function insert( string $table, array $data, array $format = null ) {
        global $wpdb;
        return $wpdb->insert( $table, $data, $format );
    }

    public static function update( string $table, array $data, array $where, array $format = null, array $where_format = null ) {
        global $wpdb;
        return $wpdb->update( $table, $data, $where, $format, $where_format );
    }

    public static function delete( string $table, array $where, array $where_format = null ) {
        global $wpdb;
        return $wpdb->delete( $table, $where, $where_format );
    }

    public static function replace( string $table, array $data, array $format = null ) {
        global $wpdb;
        return $wpdb->replace( $table, $data, $format );
    }

    public static function insert_id(): int {
        global $wpdb;
        return (int) $wpdb->insert_id;
    }
}
