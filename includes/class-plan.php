<?php
namespace WPWE;

defined( 'ABSPATH' ) || exit;

/**
 * Central feature matrix and edition checks.
 *
 * The WordPress.org free package is fully functional for its included
 * features (templates, PDF mapping/generation, entries, security).
 * Premium capabilities live in the separate premium package only.
 */
class Plan {

    /**
     * Whether this running package is the premium distribution.
     */
    public static function is_pro_package(): bool {
        return function_exists( 'wpwe_is_premium_package' ) && wpwe_is_premium_package();
    }

    /**
     * Whether current site is licensed for premium features.
     *
     * Only evaluated in the premium package.
     */
    public static function has_active_license(): bool {
        if ( ! self::is_pro_package() ) {
            return false;
        }

        if ( License::is_available() ) {
            return License::is_premium_active();
        }

        return false;
    }

    /**
     * Whether premium capabilities should be enabled.
     */
    public static function is_pro(): bool {
        $is_pro = self::has_active_license();

        return (bool) apply_filters( 'wpwe_is_pro', $is_pro );
    }

    /**
     * Feature gate resolver for the premium package only.
     *
     * The free WordPress.org package does not ship premium feature code.
     *
     * @param string $feature
     */
    public static function is_feature_enabled( string $feature ): bool {
        if ( ! self::is_pro_package() ) {
            return false;
        }

        $enabled = self::is_pro();

        return (bool) apply_filters( 'wpwe_feature_enabled', $enabled, $feature );
    }

    /**
     * Template limit (0 means unlimited).
     */
    public static function template_limit(): int {
        return (int) apply_filters( 'wpwe_template_limit', 0 );
    }

    /**
     * Whether creating a new template is currently blocked by plan limits.
     */
    public static function is_template_creation_blocked(): bool {
        return false;
    }

    /**
     * Upgrade URL for the premium package admin UI.
     */
    public static function get_upgrade_url(): string {
        if ( ! self::is_pro_package() ) {
            return '#';
        }

        return License::get_upgrade_url();
    }
}
