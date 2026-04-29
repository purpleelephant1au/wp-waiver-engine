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
 *   wpwe_rate_limit_enabled   – '1' (on) or '' (off) – defaults to ON.
 *   wpwe_rate_limit_max       – int, max submissions per window – defaults to 5.
 *   wpwe_rate_limit_window    – int, window in minutes – defaults to 15.
 *   wpwe_pdf_retention_days   – int, days retained for manual cleanup tool – defaults to 90.
 *
 * Amelia settings are only saved/displayed when Amelia is detected by
 * Integration_Manager::is_amelia_active().
 */
class Settings {

    const OPTION_AMELIA_ENABLED      = 'wpwe_amelia_enabled';
    const OPTION_ADMIN_EMAIL_ENABLED = 'wpwe_admin_email_enabled';
    const OPTION_USER_EMAIL_ENABLED  = 'wpwe_user_email_enabled';
    const OPTION_RATE_LIMIT_ENABLED  = 'wpwe_rate_limit_enabled';
    const OPTION_RATE_LIMIT_MAX      = 'wpwe_rate_limit_max';
    const OPTION_RATE_LIMIT_WINDOW   = 'wpwe_rate_limit_window';
    const OPTION_PDF_RETENTION_DAYS  = 'wpwe_pdf_retention_days';

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

    /**
     * Returns true when IP-based rate limiting is enabled (default ON).
     */
    public static function is_rate_limit_enabled(): bool {
        return get_option( self::OPTION_RATE_LIMIT_ENABLED, '1' ) !== '';
    }

    /**
     * Max submissions allowed per IP within the rate-limit window (default 5).
     */
    public static function rate_limit_max(): int {
        return max( 1, (int) get_option( self::OPTION_RATE_LIMIT_MAX, 5 ) );
    }

    /**
     * Rate-limit sliding window in minutes (default 15).
     */
    public static function rate_limit_window(): int {
        return max( 1, (int) get_option( self::OPTION_RATE_LIMIT_WINDOW, 15 ) );
    }

    /**
     * PDF retention days used by the manual cleanup tool (default 90).
     */
    public static function pdf_retention_days(): int {
        return max( 1, (int) get_option( self::OPTION_PDF_RETENTION_DAYS, 90 ) );
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

            <?php if ( isset( $_GET['wpwe_cleanup_done'] ) ) : ?>
            <div class="notice notice-success is-dismissible">
                <p><?php
                    printf(
                        /* translators: number of deleted PDFs */
                        esc_html__( 'Cleanup complete: %d PDF file(s) deleted.', 'wp-waiver-engine' ),
                        (int) $_GET['wpwe_cleanup_done']
                    );
                ?></p>
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

                    <!-- ── Submission rate limiting ── -->
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Submission Rate Limiting', 'wp-waiver-engine' ); ?></th>
                        <td>
                            <fieldset>
                                <label>
                                    <input type="checkbox" name="wpwe_rate_limit_enabled" value="1"
                                           <?php checked( self::is_rate_limit_enabled() ); ?>>
                                    <?php esc_html_e( 'Enable IP-based rate limiting', 'wp-waiver-engine' ); ?>
                                </label>
                                <p class="description">
                                    <?php esc_html_e( 'Blocks a single IP address from submitting more than the allowed number of waivers within the time window. Helps prevent bots flooding PDFs to disk. Defaults to ON.', 'wp-waiver-engine' ); ?>
                                </p>
                                <br>
                                <label for="wpwe_rate_limit_max">
                                    <?php esc_html_e( 'Max submissions:', 'wp-waiver-engine' ); ?>
                                    <input type="number" id="wpwe_rate_limit_max" name="wpwe_rate_limit_max"
                                           value="<?php echo esc_attr( self::rate_limit_max() ); ?>"
                                           min="1" max="1000" style="width:70px;">
                                </label>
                                &nbsp;
                                <label for="wpwe_rate_limit_window">
                                    <?php esc_html_e( 'per', 'wp-waiver-engine' ); ?>
                                    <input type="number" id="wpwe_rate_limit_window" name="wpwe_rate_limit_window"
                                           value="<?php echo esc_attr( self::rate_limit_window() ); ?>"
                                           min="1" max="1440" style="width:70px;">
                                    <?php esc_html_e( 'minutes', 'wp-waiver-engine' ); ?>
                                </label>
                                <p class="description">
                                    <?php esc_html_e( 'Default: 5 submissions per 15 minutes per IP.', 'wp-waiver-engine' ); ?>
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

            <!-- ── PDF Cleanup Tool ── -->
            <hr>
            <h2><?php esc_html_e( 'PDF File Cleanup', 'wp-waiver-engine' ); ?></h2>
            <p><?php esc_html_e( 'Permanently delete generated PDF files older than the specified number of days. This only removes the files from disk — entry records in the database are preserved.', 'wp-waiver-engine' ); ?></p>

            <?php
            $retention_days = self::pdf_retention_days();
            $upload_dir     = wp_upload_dir();
            $pdf_dir        = $upload_dir['basedir'] . '/wpwe-pdfs';
            $cutoff         = time() - ( $retention_days * DAY_IN_SECONDS );
            $count_old      = 0;
            if ( is_dir( $pdf_dir ) ) {
                foreach ( new \DirectoryIterator( $pdf_dir ) as $file ) {
                    if ( $file->isFile() && $file->getMTime() < $cutoff ) {
                        $count_old++;
                    }
                }
            }
            ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=wpwe-settings' ) ); ?>"
                  id="wpwe-cleanup-form">
                <?php wp_nonce_field( 'wpwe_cleanup_pdfs' ); ?>
                <input type="hidden" name="wpwe_action" value="cleanup_pdfs">

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="wpwe_pdf_retention_days">
                                <?php esc_html_e( 'Delete PDFs older than', 'wp-waiver-engine' ); ?>
                            </label>
                        </th>
                        <td>
                            <input type="number" id="wpwe_pdf_retention_days" name="wpwe_pdf_retention_days"
                                   value="<?php echo esc_attr( $retention_days ); ?>"
                                   min="1" max="3650" style="width:80px;">
                            <?php esc_html_e( 'days', 'wp-waiver-engine' ); ?>
                            <p class="description">
                                <?php
                                printf(
                                    /* translators: number of files that would be deleted */
                                    esc_html__( 'Currently %d PDF file(s) would be deleted with this setting.', 'wp-waiver-engine' ),
                                    $count_old
                                );
                                ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <p>
                    <button type="button" class="button button-secondary" id="wpwe-cleanup-trigger"
                            data-count="<?php echo esc_attr( $count_old ); ?>"
                            data-days="<?php echo esc_attr( $retention_days ); ?>">
                        <?php esc_html_e( 'Delete Old PDFs…', 'wp-waiver-engine' ); ?>
                    </button>
                </p>
            </form>

            <script>
            (function() {
                var btn = document.getElementById('wpwe-cleanup-trigger');
                if (!btn) return;

                // Re-read live values when the button is clicked
                btn.addEventListener('click', function() {
                    var daysInput = document.getElementById('wpwe_pdf_retention_days');
                    var days  = daysInput ? parseInt(daysInput.value, 10) : parseInt(btn.dataset.days, 10);

                    // Re-count is done server-side on next load; show the count from page render
                    var count = parseInt(btn.dataset.count, 10);
                    var msg   = count > 0
                        ? '<?php echo esc_js( __( 'This will permanently delete {count} PDF file(s) older than {days} day(s) from disk. This cannot be undone. Continue?', 'wp-waiver-engine' ) ); ?>'
                        : '<?php echo esc_js( __( 'No PDF files older than {days} day(s) were found. Nothing will be deleted. Continue?', 'wp-waiver-engine' ) ); ?>';

                    msg = msg.replace('{count}', count).replace('{days}', days);

                    if ( window.confirm(msg) ) {
                        document.getElementById('wpwe-cleanup-form').submit();
                    }
                });
            })();
            </script>
        </div>
        <?php
    }
}
