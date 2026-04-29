<?php
namespace WPWE;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin-wide settings page and option helpers.
 *
 * Stored in wp_options:
 *   wpwe_amelia_enabled       – '1' (on) or '' (off) – defaults to OFF.
 *   wpwe_admin_email_enabled  – '1' (on) or '' (off) – defaults to ON.
 *   wpwe_user_email_enabled   – '1' (on) or '' (off) – defaults to ON.
 *
 * Amelia settings are only saved/displayed when Amelia is detected by
 * Integration_Manager::is_amelia_active().
 */
class Settings {

    const OPTION_AMELIA_ENABLED      = 'wpwe_amelia_enabled';
    const OPTION_ADMIN_EMAIL_ENABLED = 'wpwe_admin_email_enabled';
    const OPTION_USER_EMAIL_ENABLED  = 'wpwe_user_email_enabled';

    /**
     * Returns true when the Amelia booking integration is enabled.
     *
     * Both conditions must be met:
     *   1. Amelia plugin is active (detected by Integration_Manager).
     *   2. The user has explicitly enabled the integration in Settings.
     */
    public static function is_amelia_enabled(): bool {
        return Integration_Manager::is_amelia_active()
            && (bool) get_option( self::OPTION_AMELIA_ENABLED, false );
    }

    /**
     * Returns true when admin notification emails are globally enabled (default ON).
     */
    public static function is_admin_email_enabled(): bool {
        return get_option( self::OPTION_ADMIN_EMAIL_ENABLED, '1' ) !== '';
    }

    /**
     * Returns true when submitter copy emails are globally enabled (default ON).
     */
    public static function is_user_email_enabled(): bool {
        return get_option( self::OPTION_USER_EMAIL_ENABLED, '1' ) !== '';
    }

    // -----------------------------------------------------------------------
    // Settings page
    // -----------------------------------------------------------------------

    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Access denied.', 'wp-waiver-engine' ) );
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Waiver Engine Settings', 'wp-waiver-engine' ); ?></h1>

            <?php if ( isset( $_GET['wpwe_settings_saved'] ) ) : ?>
            <div class="notice notice-success is-dismissible">
                <p><?php esc_html_e( 'Settings saved.', 'wp-waiver-engine' ); ?></p>
            </div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=wpwe-settings' ) ); ?>">
                <?php wp_nonce_field( 'wpwe_save_settings' ); ?>
                <input type="hidden" name="wpwe_action" value="save_settings">

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Amelia Integration', 'wp-waiver-engine' ); ?></th>
                        <td>
                        <?php if ( Integration_Manager::is_amelia_active() ) : ?>
                            <fieldset>
                                <label>
                                    <input type="checkbox" name="wpwe_amelia_enabled" value="1"
                                           <?php checked( (bool) get_option( self::OPTION_AMELIA_ENABLED, false ) ); ?>>
                                    <?php esc_html_e( 'Enable Amelia booking integration', 'wp-waiver-engine' ); ?>
                                </label>
                                <p class="description">
                                    <?php esc_html_e( 'When enabled: a booking-search widget appears on frontend forms, booking details are included in notification emails, Service / Customer / Date columns appear in the Entries list, and "Linked Amelia Services" is shown in template editors. Defaults to OFF.', 'wp-waiver-engine' ); ?>
                                </p>
                            </fieldset>
                        <?php else : ?>
                            <p class="description">
                                <?php esc_html_e( 'Amelia Booking plugin not detected. Install and activate Amelia to enable booking integration settings.', 'wp-waiver-engine' ); ?>
                            </p>
                        <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Admin Notification Emails', 'wp-waiver-engine' ); ?></th>
                        <td>
                            <fieldset>
                                <label>
                                    <input type="checkbox" name="wpwe_admin_email_enabled" value="1"
                                           <?php checked( self::is_admin_email_enabled() ); ?>>
                                    <?php esc_html_e( 'Enable admin notification emails', 'wp-waiver-engine' ); ?>
                                </label>
                                <p class="description">
                                    <?php esc_html_e( 'When enabled, an email is sent to the notification address each time a new waiver is submitted. Per-template control appears on each template\'s settings page. Defaults to ON.', 'wp-waiver-engine' ); ?>
                                </p>
                            </fieldset>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Submitter Copy Emails', 'wp-waiver-engine' ); ?></th>
                        <td>
                            <fieldset>
                                <label>
                                    <input type="checkbox" name="wpwe_user_email_enabled" value="1"
                                           <?php checked( self::is_user_email_enabled() ); ?>>
                                    <?php esc_html_e( 'Enable submitter copy emails', 'wp-waiver-engine' ); ?>
                                </label>
                                <p class="description">
                                    <?php esc_html_e( 'When enabled, submitters can opt in to receive a PDF copy by email. The opt-in checkbox appears on the frontend form. Per-template control appears on each template\'s settings page. Defaults to ON.', 'wp-waiver-engine' ); ?>
                                </p>
                            </fieldset>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" class="button button-primary">
                        <?php esc_html_e( 'Save Settings', 'wp-waiver-engine' ); ?>
                    </button>
                </p>
            </form>
        </div>
        <?php
    }
}
