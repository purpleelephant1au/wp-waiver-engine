<?php
/**
 * Plugin Name:       Waiver Engine
 * Plugin URI:        https://github.com/purpleelephant1au/wp-waiver-engine
 * Description:       Template-driven waiver and contract system with PDF overlay generation and optional third-party booking integrations.
 * Version:           1.1.5
 * Requires at least: 6.2
 * Requires PHP:      8.0
 * Author:            Nathaniel Smith
 * Author URI:        https://github.com/nsmithau
 * PeWave_License:           GPL-2.0-or-later
 * PeWave_License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       waiver-engine
 * Domain Path:       /languages
 * @fs_premium_only /includes/integrations/class-integration-amelia.php
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'PEWAVE_VERSION' ) ) {
    define( 'PEWAVE_VERSION', '1.1.5' );
}
if ( ! defined( 'PEWAVE_PLUGIN_DIR' ) ) {
    define( 'PEWAVE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'PEWAVE_PLUGIN_URL' ) ) {
    define( 'PEWAVE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}
if ( ! defined( 'PEWAVE_PLUGIN_BASENAME' ) ) {
    define( 'PEWAVE_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
}

// -----------------------------------------------------------------------
// Composer autoload (Freemius SDK / FPDI / FPDF)
// -----------------------------------------------------------------------
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound,WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound,WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
if ( file_exists( PEWAVE_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
    require_once PEWAVE_PLUGIN_DIR . 'vendor/autoload.php';
}

if ( function_exists( 'pewave_fs' ) ) {
    $pewave_fs_instance = pewave_fs();
    if ( $pewave_fs_instance && method_exists( $pewave_fs_instance, 'set_basename' ) ) {
        $pewave_fs_instance->set_basename( true, __FILE__ );
    }
}

/**
 * DO NOT REMOVE THIS IF, IT IS ESSENTIAL FOR THE
 * `function_exists` CALL ABOVE TO PROPERLY WORK.
 */
if ( ! function_exists( 'pewave_fs' ) ) {
    if ( ! function_exists( 'pewave_freemius_sdk_is_complete' ) ) {
        function pewave_freemius_sdk_is_complete(): bool {
            $required = [
                PEWAVE_PLUGIN_DIR . 'vendor/freemius/wordpress-sdk/start.php',
                PEWAVE_PLUGIN_DIR . 'vendor/freemius/wordpress-sdk/templates/forms/optout.php',
            ];

            foreach ( $required as $file ) {
                if ( ! file_exists( $file ) ) {
                    return false;
                }
            }

            return true;
        }
    }

    if ( ! function_exists( 'pewave_admin_notice_incomplete_sdk' ) ) {
        function pewave_admin_notice_incomplete_sdk(): void {
            if ( ! current_user_can( 'manage_options' ) ) {
                return;
            }

            echo '<div class="notice notice-error"><p>'
                . esc_html__( 'Waiver Engine: Freemius SDK files are incomplete. Licensing and upgrade UI are temporarily disabled. Reinstall the plugin package to restore vendor/freemius files.', 'waiver-engine' )
                . '</p></div>';
        }
    }

    // Create a helper function for easy SDK access.
    function pewave_fs() {
        global $pewave_fs;

        if ( ! isset( $pewave_fs ) ) {
            if ( ! pewave_freemius_sdk_is_complete() ) {
                add_action( 'admin_notices', 'pewave_admin_notice_incomplete_sdk' );
                return null;
            }

            // Include Freemius SDK.
            // SDK is auto-loaded through Composer
            if ( ! function_exists( 'fs_dynamic_init' ) ) {
                return null;
            }

            $pewave_fs = fs_dynamic_init( array(
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
                    'slug'           => 'pewave',
                    'support'        => false,
                ),
            ) );
        }

        return $pewave_fs;
    }

    // Init Freemius.
    pewave_fs();
    // Signal that SDK was initiated.
    do_action( 'pewave_fs_loaded' );
}

/**
 * Suppress Freemius upsell banners in the WordPress.org free package.
 *
 * Guideline 11 allows contextual upsells on the plugin settings page only;
 * site-wide trial/pricing notices are disabled here.
 */
if ( ! function_exists( 'pewave_configure_freemius_org_package' ) ) {
    function pewave_configure_freemius_org_package(): void {
        if ( ! function_exists( 'pewave_fs' ) || ! pewave_fs() ) {
            return;
        }

        $fs = pewave_fs();

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

    add_action( 'pewave_fs_loaded', 'pewave_configure_freemius_org_package' );
}

if ( function_exists( 'pewave_fs' ) && pewave_fs() ) {
    pewave_configure_freemius_org_package();
}
// phpcs:enable

if ( function_exists( 'pewave_fs' ) && pewave_fs() && pewave_fs()->is__premium_only() ) {
    require_once PEWAVE_PLUGIN_DIR . 'includes/class-premium-bridge__premium_only.php';
}

/**
 * Whether this running package is the premium package.
 *
 * Freemius sets this in premium builds so we can safely branch behavior
 * between free and pro ZIPs produced from the same codebase.
 */
if ( ! function_exists( 'pewave_is_premium_package' ) ) {
    function pewave_is_premium_package(): bool {
        $fs = function_exists( 'pewave_fs' ) ? pewave_fs() : null;

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
if ( ! function_exists( 'pewave_can_use_premium_features' ) ) {
    function pewave_can_use_premium_features(): bool {
        if ( ! pewave_is_premium_package() ) {
            return false;
        }

        $fs = function_exists( 'pewave_fs' ) ? pewave_fs() : null;

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
    $prefix = 'Pewave\WaiverEngine\\';
    if ( strncmp( $prefix, $class, strlen( $prefix ) ) !== 0 ) {
        return;
    }
    $relative  = substr( $class, strlen( $prefix ) );
    if ( $relative === 'PeWave_Fpdi' ) {
        $file_name = 'class-pdf-generator.php';
    } else {
        if ( str_starts_with( $relative, 'PeWave_' ) ) {
            $relative = substr( $relative, strlen( 'PeWave_' ) );
        }
        $file_name = 'class-' . strtolower( str_replace( '_', '-', $relative ) ) . '.php';
    }

    // 1. includes/ (core classes and integration manager)
    $file = PEWAVE_PLUGIN_DIR . 'includes' . DIRECTORY_SEPARATOR . $file_name;
    if ( file_exists( $file ) ) {
        require $file;
        return;
    }

    // 2. includes/integrations/ (one file per optional third-party integration)
    $file = PEWAVE_PLUGIN_DIR . 'includes' . DIRECTORY_SEPARATOR . 'integrations' . DIRECTORY_SEPARATOR . $file_name;
    if ( file_exists( $file ) ) {
        require $file;
    }
} );

// -----------------------------------------------------------------------
// Bootstrap
// -----------------------------------------------------------------------
add_action( 'plugins_loaded', [ 'Pewave\WaiverEngine\PeWave_Core', 'init' ] );

// -----------------------------------------------------------------------
// Activation - create tables immediately
// -----------------------------------------------------------------------
register_activation_hook( __FILE__, function (): void {
    \Pewave\WaiverEngine\PeWave_Database::install();
} );

// -----------------------------------------------------------------------
// Deactivation - nothing destructive; tables are preserved
// -----------------------------------------------------------------------
register_deactivation_hook( __FILE__, function (): void {
    // Intentionally empty - do not drop tables on deactivation.
} );

// -----------------------------------------------------------------------
// PeWave_Uninstall - drop tables and remove options (Freemius after_uninstall hook)
// -----------------------------------------------------------------------
if ( function_exists( 'pewave_fs' ) && pewave_fs() ) {
    pewave_fs()->add_action( 'after_uninstall', function (): void {
        require_once PEWAVE_PLUGIN_DIR . 'includes/class-uninstall.php';
        \Pewave\WaiverEngine\PeWave_Uninstall::run();
    } );
}

