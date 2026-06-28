<?php
namespace Pewave\WaiverEngine;

defined( 'ABSPATH' ) || exit;

class PeWave_License {

    /**
     * @var PeWave_License_Provider_Interface|null
     */
    private static ?PeWave_License_Provider_Interface $provider = null;

    public static function provider(): PeWave_License_Provider_Interface {
        if ( self::$provider ) {
            return self::$provider;
        }

        $provider = new PeWave_License_Provider_Null();

        if ( function_exists( 'pewave_fs' ) ) {
            $provider = new PeWave_License_Provider_Freemius();
        }

        $provider = apply_filters( 'pewave_license_provider', $provider );

        if ( $provider instanceof PeWave_License_Provider_Interface ) {
            self::$provider = $provider;
        } else {
            self::$provider = new PeWave_License_Provider_Null();
        }

        return self::$provider;
    }

    public static function is_available(): bool {
        return self::provider()->is_available();
    }

    public static function is_premium_active(): bool {
        return self::provider()->is_premium_active();
    }

    public static function get_upgrade_url(): string {
        return self::provider()->get_upgrade_url();
    }

    public static function get_account_url(): string {
        return self::provider()->get_account_url();
    }
}
