<?php
/**
 * Plugin Name:       Waiver Engine
 * Plugin URI:        https://github.com/purpleelephant1au/wp-waiver-engine
 * Description:       Template-driven waiver and contract system with PDF overlay generation and optional third-party booking integrations.
 * Version:           1.1.3
 * Requires at least: 6.2
 * Requires PHP:      8.0
 * Author:            Nathaniel Smith
 * Author URI:        https://github.com/nsmithau
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       waiver-engine
 * Domain Path:       /languages
 * @fs_premium_only /includes/integrations/class-integration-amelia.php
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'WPWE_VERSION' ) ) {
    define( 'WPWE_VERSION', '1.1.3' );
}
if ( ! defined( 'WPWE_PLUGIN_DIR' ) ) {
    define( 'WPWE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'WPWE_PLUGIN_URL' ) ) {
    define( 'WPWE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}
if ( ! defined( 'WPWE_PLUGIN_BASENAME' ) ) {
    define( 'WPWE_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
}

// -----------------------------------------------------------------------
// Composer autoload (Freemius SDK / FPDI / FPDF)
// -----------------------------------------------------------------------
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound,WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound,WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
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
                'slug'                => 'waiver-engine',
                'premium_slug'        => 'waiver-engine-premium',
                'type'                => 'plugin',
                'public_key'          => 'pk_35474bcd827c65a09ea33fc1513d8',
                'is_premium'          => true,
                'premium_suffix'      => 'Pro',
                'has_premium_version' => true,
                'has_addons'          => false,
                'has_paid_plans'      => true,
                'is_org_compliant'    => true,
                'wp_org_gatekeeper'   => 'OA7#BoRiBNqdf52FvzEf!!074aRLPs8fspif$7K1#4u4Csys1fQlCecVcUTOs2mcpeVHi#C2j9d09fOTvbC0HloPT7fFee5WdS3G',
                'menu'                => array(
                    'slug'           => 'wpwe',
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

/**
 * Suppress Freemius upsell banners in the WordPress.org free package.
 *
 * Guideline 11 allows contextual upsells on the plugin settings page only;
 * site-wide trial/pricing notices are disabled here.
 */
if ( ! function_exists( 'wpwe_configure_freemius_org_package' ) ) {
    function wpwe_configure_freemius_org_package(): void {
        if ( ! function_exists( 'wwe_fs' ) || ! wwe_fs() ) {
            return;
        }

        $fs = wwe_fs();

        if ( method_exists( $fs, 'is__premium_only' ) && $fs->is__premium_only() ) {
            return;
        }

        $fs->add_filter( 'show_trial', '__return_false' );
        $fs->add_filter( 'is_pricing_page_visible', '__return_false' );

        $fs->add_filter(
            'is_submenu_visible',
            static function ( $is_visible, $id ) {
                if ( in_array( $id, [ 'pricing', 'account' ], true ) ) {
                    return false;
                }

                return $is_visible;
            },
            10,
            2
        );

        $fs->add_filter(
            'show_admin_notice',
            static function ( $show, $msg ) {
                if ( ! is_array( $msg ) || empty( $msg['id'] ) ) {
                    return $show;
                }

                $blocked_ids = [
                    'trial_promotion',
                    'affiliate_program',
                    'plan_upgraded',
                    'trial_started',
                    'premium_version_upgrade_selection',
                    'activation_complete',
                ];

                if ( in_array( $msg['id'], $blocked_ids, true ) ) {
                    return false;
                }

                if ( isset( $msg['type'] ) && 'promotion' === $msg['type'] ) {
                    return false;
                }

                return $show;
            },
            10,
            2
        );
    }

    add_action( 'wwe_fs_loaded', 'wpwe_configure_freemius_org_package' );
}

if ( function_exists( 'wwe_fs' ) && wwe_fs() ) {
    wpwe_configure_freemius_org_package();
}
// phpcs:enable

// Backward compatibility for existing internal references.
if ( ! function_exists( 'wpwe_fs' ) ) {
    function wpwe_fs() {
        return function_exists( 'wwe_fs' ) ? wwe_fs() : null;
    }
}

if ( function_exists( 'wwe_fs' ) && wwe_fs() && wwe_fs()->is__premium_only() ) {
    require_once WPWE_PLUGIN_DIR . 'includes/class-premium-bridge__premium_only.php';
}

/**
 * Whether this running package is the premium package.
 *
 * Freemius sets this in premium builds so we can safely branch behavior
 * between free and pro ZIPs produced from the same codebase.
 */
if ( ! function_exists( 'wpwe_is_premium_package' ) ) {
    function wpwe_is_premium_package(): bool {
        $fs = function_exists( 'wwe_fs' ) ? wwe_fs() : null;

        return $fs && method_exists( $fs, 'is__premium_only' )
            ? (bool) $fs->is__premium_only()
            : false;
    }
}

/**
 * Whether premium functionality should be available at runtime.
 *
 * Primary source is Freemius entitlement (`can_use_premium_code`).
 * Premium package fallback keeps behavior deterministic in environments where
 * entitlement checks are temporarily unavailable.
 */
if ( ! function_exists( 'wpwe_can_use_premium_features' ) ) {
    function wpwe_can_use_premium_features(): bool {
        if ( ! wpwe_is_premium_package() ) {
            return false;
        }

        $fs = function_exists( 'wwe_fs' ) ? wwe_fs() : null;

        if ( $fs && method_exists( $fs, 'can_use_premium_code' ) ) {
            return (bool) $fs->can_use_premium_code();
        }

        return false;
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
// Uninstall cleanup is handled in uninstall.php when the plugin is deleted.
// -----------------------------------------------------------------------

