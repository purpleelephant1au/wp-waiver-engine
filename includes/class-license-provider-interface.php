<?php
namespace Pewave\WaiverEngine;

defined( 'ABSPATH' ) || exit;

interface PeWave_License_Provider_Interface {
    public function is_available(): bool;
    public function is_premium_active(): bool;
    public function get_upgrade_url(): string;
    public function get_account_url(): string;
}
