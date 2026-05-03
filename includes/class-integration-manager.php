<?php
namespace WPWE;

defined( 'ABSPATH' ) || exit;

/**
 * Detects available third-party plugin integrations and acts as a registry.
 *
 * Each integration lives in includes/integrations/class-integration-*.php and
 * is only instantiated when its host plugin is detected on the current
 * WordPress install. This pattern is used by WooCommerce add-ons, WPForms,
 * RankMath, and ACF: hooks/plugin APIs are preferred; direct DB queries are
 * used only as a contextual fallback within the integration class itself.
 *
 * No integration code runs when its host plugin is absent, so there is zero
 * runtime overhead and no fatal errors if a supported plugin is deactivated.
 */
class Integration_Manager {

    /**
     * Cached Amelia detection result. Null until first call.
     *
     * @var bool|null
     */
    private static ?bool $amelia_active = null;

    // -----------------------------------------------------------------------
    // Bootstrap
    // -----------------------------------------------------------------------

    /**
     * Called once at plugins_loaded. Instantiates and registers every
     * integration whose host plugin is currently active.
     */
    public static function register(): void {
        if ( \WPWE\Plan::is_feature_enabled( 'amelia_integration' ) && self::is_amelia_active() ) {
            ( new Integration_Amelia() )->register();
        }
    }

    // -----------------------------------------------------------------------
    // Detection helpers
    // -----------------------------------------------------------------------

    /**
     * Returns true when the Amelia Booking plugin is active on this site.
     *
     * Detection order (requires no DB query):
     *   1. AMELIA_VERSION constant – defined in the Amelia main plugin file.
     *   2. Core application class  – covers renamed / white-label builds.
     *
     * The result is cached in a static property so repeated calls are free.
     */
    public static function is_amelia_active(): bool {
        if ( self::$amelia_active !== null ) {
            return self::$amelia_active;
        }

        if ( defined( 'AMELIA_VERSION' ) ) {
            return self::$amelia_active = true;
        }

        // Broader guard for custom / white-label Amelia builds.
        if ( class_exists(
            'AmeliaBooking\Application\Services\Reservation\AppointmentReservationService',
            false
        ) ) {
            return self::$amelia_active = true;
        }

        return self::$amelia_active = false;
    }
}
