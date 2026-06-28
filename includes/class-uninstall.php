<?php
namespace Pewave\WaiverEngine;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin uninstall cleanup (invoked via Freemius after_uninstall hook).
 */
class PeWave_Uninstall {

    public static function run(): void {
        global $wpdb;

        $tables = [
            $wpdb->prefix . 'pewave_entries',
            $wpdb->prefix . 'pewave_templates',
        ];

        foreach ( $tables as $table ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
            $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
        }

        $options = [
            'pewave_db_version',
            'pewave_amelia_enabled',
            'pewave_admin_email_enabled',
            'pewave_user_email_enabled',
            'pewave_rate_limit_enabled',
            'pewave_rate_limit_max',
            'pewave_rate_limit_window',
            'pewave_pdf_retention_days',
            'pewave_captcha_provider',
            'pewave_captcha_site_key',
            'pewave_captcha_secret_key',
        ];

        foreach ( $options as $option ) {
            delete_option( $option );
        }
    }
}
