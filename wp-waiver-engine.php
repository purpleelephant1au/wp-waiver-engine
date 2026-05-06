<?php
/**
 * Plugin Name:       Waiver Engine
 * Description:       Template-driven waiver and contract system with PDF overlay generation and optional third-party booking integrations.
 * Version:           1.0.1
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Nathaniel Smith
 * Author URI:        https://github.com/nsmithau
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-waiver-engine
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'WPWE_VERSION' ) ) {
    define( 'WPWE_VERSION', '1.0.1' );
}
if ( ! defined( 'WPWE_PLUGIN_DIR' ) ) {
    define( 'WPWE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'WPWE_PLUGIN_URL' ) ) {
    define( 'WPWE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

// -----------------------------------------------------------------------
// Composer autoload (Freemius SDK / FPDI / FPDF)
// -----------------------------------------------------------------------
if ( file_exists( WPWE_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
    require_once WPWE_PLUGIN_DIR . 'vendor/autoload.php';
}

if ( function_exists( 'wwe_fs' ) ) {
    $wwe_fs_instance = wwe_fs();
    if ( $wwe_fs_instance && method_exists( $wwe_fs_instance, 'set_basename' ) ) {
        $wwe_fs_instance->set_basename( true, __FILE__ );
    }
}

/**
 * DO NOT REMOVE THIS IF, IT IS ESSENTIAL FOR THE
 * `function_exists` CALL ABOVE TO PROPERLY WORK.
 */
if ( ! function_exists( 'wwe_fs' ) ) {
    // Create a helper function for easy SDK access.
    function wwe_fs() {
        global $wwe_fs;

        if ( ! isset( $wwe_fs ) ) {
            // Include Freemius SDK.
            // SDK is auto-loaded through Composer
            if ( ! function_exists( 'fs_dynamic_init' ) ) {
                return null;
            }

            $wwe_fs = fs_dynamic_init( array(
                'id'                  => '28928',
                'slug'                => 'wp-waiver-engine',
                'type'                => 'plugin',
                'public_key'          => 'pk_35474bcd827c65a09ea33fc1513d8',
                'is_premium'          => true,
                'premium_suffix'      => 'Pro',
                'has_premium_version' => true,
                'has_addons'          => false,
                'has_paid_plans'      => true,
                'is_org_compliant'    => true,
                'wp_org_gatekeeper'   => 'OA7#BoRiBNqdf52FvzEf!!074aRLPs8fspif$7K1#4u4Csys1fQlCecVcUTOs2mcpeVHi#C2j9d09fOTvbC0HloPT7fFee5WdS3G',
                'trial'               => array(
                    'days'               => 3,
                    'is_require_payment' => true,
                ),
                'menu'                => array(
                    'slug'           => 'wpwe-settings',
                    'parent'         => array(
                        'slug' => 'wpwe',
                    ),
                    'first-path'     => 'admin.php?page=wpwe-settings',
                    'support'        => false,
                ),
            ) );
        }

        return $wwe_fs;
    }

    // Init Freemius.
    wwe_fs();
    // Signal that SDK was initiated.
    do_action( 'wwe_fs_loaded' );
}

// Backward compatibility for existing internal references.
if ( ! function_exists( 'wpwe_fs' ) ) {
    function wpwe_fs() {
        return function_exists( 'wwe_fs' ) ? wwe_fs() : null;
    }
}

// -----------------------------------------------------------------------
// Autoloader
// -----------------------------------------------------------------------
spl_autoload_register( function ( string $class ): void {
    $prefix = 'WPWE\\';
    if ( strncmp( $prefix, $class, strlen( $prefix ) ) !== 0 ) {
        return;
    }
    $relative  = substr( $class, strlen( $prefix ) );
    $file_name = 'class-' . strtolower( str_replace( '_', '-', $relative ) ) . '.php';

    // 1. includes/ (core classes and integration manager)
    $file = WPWE_PLUGIN_DIR . 'includes' . DIRECTORY_SEPARATOR . $file_name;
    if ( file_exists( $file ) ) {
        require $file;
        return;
    }

    // 2. includes/integrations/ (one file per optional third-party integration)
    $file = WPWE_PLUGIN_DIR . 'includes' . DIRECTORY_SEPARATOR . 'integrations' . DIRECTORY_SEPARATOR . $file_name;
    if ( file_exists( $file ) ) {
        require $file;
    }
} );

// -----------------------------------------------------------------------
// Bootstrap
// -----------------------------------------------------------------------
add_action( 'plugins_loaded', [ 'WPWE\Core', 'init' ] );

// -----------------------------------------------------------------------
// Activation - create tables immediately
// -----------------------------------------------------------------------
register_activation_hook( __FILE__, function (): void {
    \WPWE\Database::install();
} );

// -----------------------------------------------------------------------
// Deactivation - nothing destructive; tables are preserved
// -----------------------------------------------------------------------
register_deactivation_hook( __FILE__, function (): void {
    // Intentionally empty - do not drop tables on deactivation.
} );

// -----------------------------------------------------------------------
// Uninstall - drop tables and remove options (Freemius after_uninstall hook)
// -----------------------------------------------------------------------
if ( function_exists( 'wwe_fs' ) && wwe_fs() ) {
    wwe_fs()->add_action( 'after_uninstall', function (): void {
        global $wpdb;

        // Drop custom tables.
        $wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}wpwe_entries`" );   // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}wpwe_templates`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

        // Remove all plugin options.
        delete_option( 'wpwe_db_version' );
        delete_option( 'wpwe_amelia_enabled' );
        delete_option( 'wpwe_admin_email_enabled' );
        delete_option( 'wpwe_user_email_enabled' );
        delete_option( 'wpwe_rate_limit_enabled' );
        delete_option( 'wpwe_rate_limit_max' );
        delete_option( 'wpwe_rate_limit_window' );
        delete_option( 'wpwe_pdf_retention_days' );
        delete_option( 'wpwe_captcha_provider' );
        delete_option( 'wpwe_captcha_site_key' );
        delete_option( 'wpwe_captcha_secret_key' );
    } );
}
