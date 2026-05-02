<?php
namespace WPWE;

defined( 'ABSPATH' ) || exit;

class License_Provider_Null implements License_Provider_Interface {

    public function is_available(): bool {
        return false;
    }

    public function is_premium_active(): bool {
        return false;
    }

    public function get_upgrade_url(): string {
        return '#';
    }

    public function get_account_url(): string {
        return '#';
    }
}
