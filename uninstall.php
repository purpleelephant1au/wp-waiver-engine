<?php
/**
 * Uninstall WP Waiver Engine.
 *
 * Runs when the user deletes the plugin from the WordPress admin.
 * Drops all custom database tables and removes all plugin options.
 *
 * @package WP_Waiver_Engine
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// Drop custom tables.
$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}wpwe_entries`" );   // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}wpwe_templates`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// Remove all plugin options.
delete_option( 'wpwe_db_version' );
delete_option( 'wpwe_amelia_enabled' );
delete_option( 'wpwe_admin_email_enabled' );
delete_option( 'wpwe_user_email_enabled' );
