<?php
/**
 * Plugin Name:       WP Waiver Engine
 * Description:       Template-driven waiver and contract system with PDF overlay generation and optional third-party booking integrations.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Nathaniel Smith
 * Author URI:        https://github.com/nsmithau
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-waiver-engine
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'WPWE_VERSION',   '1.0.0' );
define( 'WPWE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPWE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// ---------------------------------------------------------------------------
// Autoloader
// ---------------------------------------------------------------------------
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

// ---------------------------------------------------------------------------
// Composer autoload (FPDI / FPDF)
// ---------------------------------------------------------------------------
if ( file_exists( WPWE_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
    require_once WPWE_PLUGIN_DIR . 'vendor/autoload.php';
}

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------
add_action( 'plugins_loaded', [ 'WPWE\Core', 'init' ] );

// ---------------------------------------------------------------------------
// Activation – create tables immediately
// ---------------------------------------------------------------------------
register_activation_hook( __FILE__, function (): void {
    WPWE\Database::install();
} );

// ---------------------------------------------------------------------------
// Deactivation – nothing destructive; tables are preserved
// ---------------------------------------------------------------------------
register_deactivation_hook( __FILE__, function (): void {
    // Intentionally empty – do not drop tables on deactivation.
    // Tables are only removed on uninstall (see uninstall.php if added).
} );
