<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package Waiver_Engine
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

$tables = [
    $wpdb->prefix . 'wpwe_entries',
    $wpdb->prefix . 'wpwe_templates',
];

foreach ( $tables as $table ) {
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
    $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
}

$options = [
    'wpwe_db_version',
    'wpwe_amelia_enabled',
    'wpwe_admin_email_enabled',
    'wpwe_user_email_enabled',
    'wpwe_rate_limit_enabled',
    'wpwe_rate_limit_max',
    'wpwe_rate_limit_window',
    'wpwe_pdf_retention_days',
    'wpwe_captcha_provider',
    'wpwe_captcha_site_key',
    'wpwe_captcha_secret_key',
];

foreach ( $options as $option ) {
    delete_option( $option );
}
