<?php
namespace WPWE;

defined( 'ABSPATH' ) || exit;

/**
 * Central feature matrix and edition checks.
 *
 * Free defaults:
 * - max 2 templates
 * - no email sending
 * - no repeating rows
 * - no Amelia integration
 *
 * Pro requires premium entitlement resolved through Freemius runtime checks.
 */
class Plan {

    /**
     * Legacy helper retained for compatibility.
     *
     * Freemius-native gating should rely on active entitlement via is_pro().
     */
    public static function is_pro_package(): bool {
        if ( function_exists( 'wwe_fs' ) && wwe_fs() && method_exists( wwe_fs(), 'is__premium_only' ) ) {
            return (bool) wwe_fs()->is__premium_only();
        }

        // Single-zip model: package markers are not used.
        return false;
    }

    /**
     * Whether current site is licensed for premium features.
     */
    public static function has_active_license(): bool {
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
     * Feature gate resolver.
     *
     * @param string $feature
     */
    public static function is_feature_enabled( string $feature ): bool {
        $enabled = false;

        switch ( $feature ) {
            case 'email_sending':
            case 'repeating_rows':
            case 'amelia_integration':
                $enabled = self::is_pro();
                break;
        }

        return (bool) apply_filters( 'wpwe_feature_enabled', $enabled, $feature );
    }

    /**
     * Free template limit (0 means unlimited).
     */
    public static function template_limit(): int {
        $limit = self::is_pro() ? 0 : 2;

        return (int) apply_filters( 'wpwe_template_limit', $limit );
    }

    /**
     * Whether creating a new template is currently blocked by plan limits.
     */
    public static function is_template_creation_blocked(): bool {
        $limit = self::template_limit();

        if ( $limit <= 0 ) {
            return false;
        }

        return Database::get_templates_count() >= $limit;
    }

    /**
     * Best-effort upgrade URL for admin links.
     */
    public static function get_upgrade_url(): string {
        return License::get_upgrade_url();
    }
}
