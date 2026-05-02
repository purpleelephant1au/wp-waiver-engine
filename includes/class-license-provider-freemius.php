<?php
namespace WPWE;

defined( 'ABSPATH' ) || exit;

class License_Provider_Freemius implements License_Provider_Interface {

    /**
     * Resolve Freemius helper instance with backward compatibility.
     *
     * @return mixed|null
     */
    private function get_fs() {
        if ( function_exists( 'wwe_fs' ) ) {
            return wwe_fs();
        }

        if ( function_exists( 'wpwe_fs' ) ) {
            return wpwe_fs();
        }

        return null;
    }

    public function is_available(): bool {
        return (bool) $this->get_fs();
    }

    public function is_premium_active(): bool {
        if ( ! $this->is_available() ) {
            return false;
        }

        $fs = $this->get_fs();

        return $fs && method_exists( $fs, 'can_use_premium_code' )
            ? (bool) $fs->can_use_premium_code()
            : false;
    }

    public function get_upgrade_url(): string {
        if ( ! $this->is_available() ) {
            return '#';
        }

        $fs = $this->get_fs();

        return $fs && method_exists( $fs, 'get_upgrade_url' )
            ? (string) $fs->get_upgrade_url()
            : '#';
    }

    public function get_account_url(): string {
        if ( ! $this->is_available() ) {
            return '#';
        }

        $fs = $this->get_fs();

        return $fs && method_exists( $fs, 'get_account_url' )
            ? (string) $fs->get_account_url()
            : '#';
    }
}
