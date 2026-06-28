<?php
namespace Pewave\WaiverEngine;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin-wide settings page and option helpers.
 *
 * Stored in wp_options:
 *   pewave_amelia_enabled       – '1' (on) or '' (off) – defaults to OFF.
 *   pewave_admin_email_enabled  – '1' (on) or '' (off) – defaults to ON.
 *   pewave_user_email_enabled   – '1' (on) or '' (off) – defaults to ON.
 *   pewave_rate_limit_enabled   – '1' (on) or '' (off) – defaults to ON.
 *   pewave_rate_limit_max       – int, max submissions per window – defaults to 5.
 *   pewave_rate_limit_window    – int, window in minutes – defaults to 15.
 *   pewave_pdf_retention_days   – int, days retained for manual cleanup tool – defaults to 90.
 *   pewave_captcha_provider     – 'none' | 'recaptcha_v3' | 'hcaptcha' – defaults to 'none'.
 *   pewave_captcha_site_key     – string, published site key.
 *   pewave_captcha_secret_key   – string, server-side secret key.
 *
 * Amelia settings are only saved/displayed when Amelia is detected by
 * PeWave_Integration_Manager::is_amelia_active().
 */
class PeWave_Settings {

    const OPTION_AMELIA_ENABLED      = 'pewave_amelia_enabled';
    const OPTION_ADMIN_EMAIL_ENABLED = 'pewave_admin_email_enabled';
    const OPTION_USER_EMAIL_ENABLED  = 'pewave_user_email_enabled';
    const OPTION_RATE_LIMIT_ENABLED  = 'pewave_rate_limit_enabled';
    const OPTION_RATE_LIMIT_MAX      = 'pewave_rate_limit_max';
    const OPTION_RATE_LIMIT_WINDOW   = 'pewave_rate_limit_window';
    const OPTION_PDF_RETENTION_DAYS  = 'pewave_pdf_retention_days';
    const OPTION_CAPTCHA_PROVIDER    = 'pewave_captcha_provider';
    const OPTION_CAPTCHA_SITE_KEY    = 'pewave_captcha_site_key';
    const OPTION_CAPTCHA_SECRET_KEY  = 'pewave_captcha_secret_key';

    /**
     * Returns true when the Amelia booking integration is enabled.
     *
     * Both conditions must be met:
     *   1. Amelia plugin is active (detected by PeWave_Integration_Manager).
     *   2. The user has explicitly enabled the integration in PeWave_Settings.
     */
    public static function is_amelia_enabled(): bool {
        if ( ! \Pewave\WaiverEngine\PeWave_Integration_Manager::is_amelia_active() ) {
            return false;
        }

        return (bool) get_option( self::OPTION_AMELIA_ENABLED, false );
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

    /**
     * CAPTCHA provider: 'none' (default), 'recaptcha_v3', or 'hcaptcha'.
     */
    public static function captcha_provider(): string {
        $val = (string) get_option( self::OPTION_CAPTCHA_PROVIDER, 'none' );
        return in_array( $val, [ 'none', 'recaptcha_v3', 'hcaptcha' ], true ) ? $val : 'none';
    }

    /**
     * CAPTCHA public site key.
     */
    public static function captcha_site_key(): string {
        return (string) get_option( self::OPTION_CAPTCHA_SITE_KEY, '' );
    }

    /**
     * CAPTCHA server-side secret key (never exposed to the browser).
     */
    public static function captcha_secret_key(): string {
        return (string) get_option( self::OPTION_CAPTCHA_SECRET_KEY, '' );
    }

    // -----------------------------------------------------------------------
    // PeWave_Settings page
    // -----------------------------------------------------------------------

    /**
     * Enqueue admin assets for the settings screen.
     */
    public static function enqueue_assets(): void {
        wp_enqueue_script(
            'pewave-admin-settings',
            \PEWAVE_PLUGIN_URL . 'assets/js/admin-settings.js',
            [],
            \PEWAVE_VERSION,
            true
        );

        wp_localize_script(
            'pewave-admin-settings',
            'pewaveSettings',
            [
                'cleanupConfirm' => __( 'This will permanently delete {count} PDF file(s) older than {days} day(s) from disk. This cannot be undone. Continue?', 'waiver-engine' ),
                'cleanupEmpty'   => __( 'No PDF files older than {days} day(s) were found. Nothing will be deleted. Continue?', 'waiver-engine' ),
            ]
        );
    }

    public function render(): void {
        self::enqueue_assets();
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only admin notice query vars.
        $cleanup_done = isset( $_GET['pewave_cleanup_done'] ) ? absint( wp_unslash( $_GET['pewave_cleanup_done'] ) ) : null;
        $import_templates = isset( $_GET['pewave_import_templates'] ) ? absint( wp_unslash( $_GET['pewave_import_templates'] ) ) : 0;
        $import_entries   = isset( $_GET['pewave_import_entries'] ) ? absint( wp_unslash( $_GET['pewave_import_entries'] ) ) : 0;
        $import_options   = isset( $_GET['pewave_import_options'] ) ? absint( wp_unslash( $_GET['pewave_import_options'] ) ) : 0;
        $handoff_deactivated = isset( $_GET['pewave_handoff_deactivated'] ) ? absint( wp_unslash( $_GET['pewave_handoff_deactivated'] ) ) : null;
        // phpcs:enable

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Access denied.', 'waiver-engine' ) );
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Waiver Engine Settings', 'waiver-engine' ); ?></h1>

            <?php if ( class_exists( PeWave_Premium_Bridge::class ) ) : ?>
                <?php PeWave_Premium_Bridge::render_settings_upgrade_notice(); ?>
            <?php endif; ?>

            <?php // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only admin notice query vars. ?>
            <?php if ( isset( $_GET['pewave_settings_saved'] ) ) : ?>
            <div class="notice notice-success is-dismissible">
                <p><?php esc_html_e( 'Settings saved.', 'waiver-engine' ); ?></p>
            </div>
            <?php endif; ?>

            <?php if ( null !== $cleanup_done ) : ?>
            <div class="notice notice-success is-dismissible">
                <p><?php
                    printf(
                        /* translators: number of deleted PDFs */
                        esc_html__( 'Cleanup complete: %d PDF file(s) deleted.', 'waiver-engine' ),
                        absint( $cleanup_done )
                    );
                ?></p>
            </div>
            <?php endif; ?>

            <?php if ( isset( $_GET['pewave_data_imported'] ) ) : ?>
            <div class="notice notice-success is-dismissible">
                <p><?php
                    printf(
                        /* translators: 1: templates count, 2: entries count, 3: options count */
                        esc_html__( 'Data import complete: %1$d template(s), %2$d entry(s), %3$d option(s).', 'waiver-engine' ),
                        absint( $import_templates ),
                        absint( $import_entries ),
                        absint( $import_options )
                    );
                ?></p>
            </div>
            <?php endif; ?>

            <?php if ( isset( $_GET['pewave_data_import_error'] ) ) : ?>
            <div class="notice notice-error is-dismissible">
                <p><?php esc_html_e( 'Data import failed. Please verify you uploaded a valid WPWE data export JSON file and try again.', 'waiver-engine' ); ?></p>
            </div>
            <?php endif; ?>
            <?php // phpcs:enable ?>

            <?php if ( null !== $handoff_deactivated ) : ?>
                <?php if ( 1 === $handoff_deactivated ) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e( 'Free plugin deactivated successfully. Your data remains available in Pro.', 'waiver-engine' ); ?></p>
                </div>
                <?php else : ?>
                <div class="notice notice-error is-dismissible">
                    <p><?php esc_html_e( 'Could not deactivate the Free plugin automatically. You can deactivate it manually from the Plugins screen.', 'waiver-engine' ); ?></p>
                </div>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ( self::is_free_to_pro_handoff_detected() ) : ?>
            <div class="notice notice-info is-dismissible">
                <p>
                    <strong><?php esc_html_e( 'Free-to-Pro handoff detected.', 'waiver-engine' ); ?></strong>
                    <?php esc_html_e( 'This Pro package uses the same templates, entries, and settings schema as Free. Your existing data is already available. You can safely deactivate and remove the Free plugin after confirming your forms and admin pages in Pro.', 'waiver-engine' ); ?>
                </p>
                <?php $target = self::get_handoff_target_plugin(); ?>
                <?php if ( $target !== '' ) : ?>
                <p>
                    <a class="button button-secondary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=pewave-settings&pewave_action=deactivate_free_plugin&plugin=' . rawurlencode( $target ) ), 'pewave_deactivate_free_plugin' ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Deactivate the Free plugin now? Your data will remain in Pro.', 'waiver-engine' ) ); ?>');">
                        <?php esc_html_e( 'Deactivate Free plugin now', 'waiver-engine' ); ?>
                    </a>
                </p>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=pewave-settings' ) ); ?>">
                <?php wp_nonce_field( 'pewave_save_settings' ); ?>
                <input type="hidden" name="pewave_action" value="save_settings">

                <table class="form-table" role="presentation">
                    <?php if ( class_exists( PeWave_Premium_Bridge::class ) ) : ?>
                        <?php PeWave_Premium_Bridge::render_settings_premium_rows(); ?>
                    <?php endif; ?>

                    <!-- -- Submission rate limiting -- -->
                    <tr>
                        <th scope="row">
                            <?php esc_html_e( 'Submission Rate Limiting', 'waiver-engine' ); ?>
                            <span class="wpwe-tooltip dashicons dashicons-editor-help"
                                  title="<?php esc_attr_e( 'Limits how many waivers a single IP address can submit within a rolling time window. Protects against bots flooding PDFs to disk. Uses WordPress transients — no extra database tables needed.', 'waiver-engine' ); ?>"></span>
                        </th>
                        <td>
                            <fieldset>
                                <label>
                                    <input type="checkbox" name="pewave_rate_limit_enabled" value="1"
                                           <?php checked( self::is_rate_limit_enabled() ); ?>>
                                    <?php esc_html_e( 'Enable IP-based rate limiting', 'waiver-engine' ); ?>
                                </label>
                                <p class="description">
                                    <?php esc_html_e( 'Blocks a single IP address from submitting more than the allowed number of waivers within the time window. Helps prevent bots flooding PDFs to disk. Defaults to ON.', 'waiver-engine' ); ?>
                                </p>
                                <br>
                                <label for="pewave_rate_limit_max">
                                    <?php esc_html_e( 'Max submissions:', 'waiver-engine' ); ?>
                                    <input type="number" id="pewave_rate_limit_max" name="pewave_rate_limit_max"
                                           value="<?php echo esc_attr( self::rate_limit_max() ); ?>"
                                           min="1" max="1000" style="width:70px;">
                                </label>
                                &nbsp;
                                <label for="pewave_rate_limit_window">
                                    <?php esc_html_e( 'per', 'waiver-engine' ); ?>
                                    <input type="number" id="pewave_rate_limit_window" name="pewave_rate_limit_window"
                                           value="<?php echo esc_attr( self::rate_limit_window() ); ?>"
                                           min="1" max="1440" style="width:70px;">
                                    <?php esc_html_e( 'minutes', 'waiver-engine' ); ?>
                                </label>
                                <p class="description">
                                    <?php esc_html_e( 'Default: 5 submissions per 15 minutes per IP.', 'waiver-engine' ); ?>
                                </p>
                            </fieldset>
                        </td>
                    </tr>

                    <!-- -- CAPTCHA -- -->
                    <tr>
                        <th scope="row">
                            <?php esc_html_e( 'CAPTCHA', 'waiver-engine' ); ?>
                            <span class="wpwe-tooltip dashicons dashicons-editor-help"
                                  title="<?php esc_attr_e( 'Add an invisible CAPTCHA challenge to waiver forms to block automated bots. Choose a provider, enter your API keys, then enable CAPTCHA per-template in the template editor. Defaults to OFF.', 'waiver-engine' ); ?>"></span>
                        </th>
                        <td>
                            <fieldset>
                                <label for="pewave_captcha_provider"><?php esc_html_e( 'Provider:', 'waiver-engine' ); ?></label>
                                <select id="pewave_captcha_provider" name="pewave_captcha_provider">
                                    <option value="none" <?php selected( self::captcha_provider(), 'none' ); ?>>
                                        <?php esc_html_e( 'None (disabled)', 'waiver-engine' ); ?>
                                    </option>
                                    <option value="recaptcha_v3" <?php selected( self::captcha_provider(), 'recaptcha_v3' ); ?>>
                                        <?php esc_html_e( 'Google reCAPTCHA v3 (invisible)', 'waiver-engine' ); ?>
                                    </option>
                                    <option value="hcaptcha" <?php selected( self::captcha_provider(), 'hcaptcha' ); ?>>
                                        <?php esc_html_e( 'hCaptcha (invisible)', 'waiver-engine' ); ?>
                                    </option>
                                </select>
                                <p class="description">
                                    <?php esc_html_e( 'reCAPTCHA v3: register at google.com/recaptcha. hCaptcha: register at hcaptcha.com. Both are invisible to users; scoring happens server-side.', 'waiver-engine' ); ?>
                                </p>

                                <div id="pewave-captcha-keys" <?php echo self::captcha_provider() === 'none' ? 'style="display:none;"' : ''; ?>>
                                    <br>
                                    <label for="pewave_captcha_site_key">
                                        <?php esc_html_e( 'Site Key (public):', 'waiver-engine' ); ?>
                                        <span class="wpwe-tooltip dashicons dashicons-editor-help"
                                              title="<?php esc_attr_e( 'The public key embedded in the page HTML. Obtained from your CAPTCHA provider\'s dashboard.', 'waiver-engine' ); ?>"></span>
                                    </label><br>
                                    <input type="text" id="pewave_captcha_site_key" name="pewave_captcha_site_key"
                                           value="<?php echo esc_attr( self::captcha_site_key() ); ?>"
                                           class="regular-text" autocomplete="off">
                                    <br><br>
                                    <label for="pewave_captcha_secret_key">
                                        <?php esc_html_e( 'Secret Key (private):', 'waiver-engine' ); ?>
                                        <span class="wpwe-tooltip dashicons dashicons-editor-help"
                                              title="<?php esc_attr_e( 'The private key used server-side to verify tokens. Never exposed to the browser. Keep this confidential.', 'waiver-engine' ); ?>"></span>
                                    </label><br>
                                    <input type="password" id="pewave_captcha_secret_key" name="pewave_captcha_secret_key"
                                           value="<?php echo esc_attr( self::captcha_secret_key() ); ?>"
                                           class="regular-text" autocomplete="off">
                                </div>
                            </fieldset>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" class="button button-primary">
                        <?php echo esc_html( 'Save Settings' ); ?>
                    </button>
                </p>
            </form>

            <!-- -- PDF Cleanup Tool -- -->
            <hr>
            <h2><?php esc_html_e( 'PDF File Cleanup', 'waiver-engine' ); ?></h2>
            <p><?php esc_html_e( 'Permanently delete generated PDF files older than the specified number of days. This only removes the files from disk — entry records in the database are preserved.', 'waiver-engine' ); ?></p>

            <?php
            $retention_days = self::pdf_retention_days();
            $upload_dir     = wp_upload_dir();
            $pdf_dir        = $upload_dir['basedir'] . '/pewave-pdfs';
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

            <form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=pewave-settings' ) ); ?>"
                                    id="pewave-cleanup-form">
                                <?php wp_nonce_field( 'pewave_cleanup_pdfs' ); ?>
                                <input type="hidden" name="pewave_action" value="cleanup_pdfs">

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="pewave_pdf_retention_days">
                                <?php esc_html_e( 'Delete PDFs older than', 'waiver-engine' ); ?>
                            </label>
                        </th>
                        <td>
                            <input type="number" id="pewave_pdf_retention_days" name="pewave_pdf_retention_days"
                                   value="<?php echo esc_attr( $retention_days ); ?>"
                                   min="1" max="3650" style="width:80px;">
                            <?php esc_html_e( 'days', 'waiver-engine' ); ?>
                            <p class="description">
                                <?php
                                printf(
                                    /* translators: number of files that would be deleted */
                                    esc_html__( 'Currently %d PDF file(s) would be deleted with this setting.', 'waiver-engine' ),
                                    absint( $count_old )
                                );
                                ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <p>
                    <button type="button" class="button button-secondary" id="pewave-cleanup-trigger"
                            data-count="<?php echo esc_attr( $count_old ); ?>"
                            data-days="<?php echo esc_attr( $retention_days ); ?>">
                        <?php esc_html_e( 'Delete Old PDFs…', 'waiver-engine' ); ?>
                    </button>
                </p>
            </form>

            <!-- PDF Cleanup Tool -->
            <hr>
            <h2><?php esc_html_e( 'Migration & Data Transfer', 'waiver-engine' ); ?></h2>
            <p><?php esc_html_e( 'Export and import the full Waiver Engine dataset (templates, entries, and plugin settings). Use this for migrations, backups, and Free-to-Pro handoff verification.', 'waiver-engine' ); ?></p>

            <h3><?php esc_html_e( 'Export Full Data Package', 'waiver-engine' ); ?></h3>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=pewave-settings' ) ); ?>">
                <?php wp_nonce_field( 'pewave_export_all_data' ); ?>
                <input type="hidden" name="pewave_action" value="export_all_data">
                <p>
                    <button type="submit" class="button button-secondary">
                        <?php esc_html_e( 'Download Full Data JSON', 'waiver-engine' ); ?>
                    </button>
                </p>
            </form>

            <h3><?php esc_html_e( 'Import Full Data Package', 'waiver-engine' ); ?></h3>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=pewave-settings' ) ); ?>" enctype="multipart/form-data">
                <?php wp_nonce_field( 'pewave_import_all_data' ); ?>
                <input type="hidden" name="pewave_action" value="import_all_data">
                <p>
                    <input type="file" name="pewave_data_import_file" accept="application/json,.json" required>
                </p>
                <p>
                    <label>
                        <input type="checkbox" name="pewave_data_replace_existing" value="1">
                        <?php esc_html_e( 'Replace existing templates and entries before import', 'waiver-engine' ); ?>
                    </label>
                </p>
                <p class="description">
                    <?php esc_html_e( 'Leave unchecked to merge data by ID (existing matching IDs are updated). Check to replace current templates/entries with the imported dataset first.', 'waiver-engine' ); ?>
                </p>
                <p>
                    <button type="submit" class="button button-primary" onclick="return confirm('<?php echo esc_js( __( 'Importing data may overwrite existing records with matching IDs. Continue?', 'waiver-engine' ) ); ?>');">
                        <?php esc_html_e( 'Import Data JSON', 'waiver-engine' ); ?>
                    </button>
                </p>
            </form>

            <?php self::render_org_upgrade_line(); ?>
        </div>
        <?php
    }

    /**
     * Single contextual Pro mention for the WordPress.org free package (settings only).
     */
    public static function render_org_upgrade_line(): void {
        if ( function_exists( 'pewave_is_premium_package' ) && pewave_is_premium_package() ) {
            return;
        }

        $upgrade_url = PeWave_License::get_upgrade_url();
        if ( $upgrade_url === '#' ) {
            return;
        }
        ?>
        <p class="description" style="margin-top:2em;">
            <?php
            printf(
                wp_kses(
                    /* translators: %s: URL to the separate Pro product page */
                    __( 'Need repeating rows, email notifications, or Amelia integration? <a href="%s" target="_blank" rel="noopener noreferrer">Learn about Waiver Engine Pro</a> (separate download).', 'waiver-engine' ),
                    [
                        'a' => [
                            'href'   => [],
                            'target' => [],
                            'rel'    => [],
                        ],
                    ]
                ),
                esc_url( $upgrade_url )
            );
            ?>
        </p>
        <?php
    }

    /**
     * Detect when a premium package is active alongside another WPWE plugin entry.
     */
    public static function is_free_to_pro_handoff_detected(): bool {
        if ( ! function_exists( 'pewave_is_premium_package' ) || ! pewave_is_premium_package() ) {
            return false;
        }

        return self::get_handoff_target_plugin() !== '';
    }

    /**
     * Return active plugin basename for the Free package when detected.
     */
    public static function get_handoff_target_plugin(): string {
        if ( ! function_exists( 'pewave_is_premium_package' ) || ! pewave_is_premium_package() ) {
            return '';
        }

        $current = defined( 'PEWAVE_PLUGIN_BASENAME' ) ? (string) PEWAVE_PLUGIN_BASENAME : '';
        $active  = (array) get_option( 'active_plugins', [] );

        if ( is_multisite() ) {
            $network_active = array_keys( (array) get_site_option( 'active_sitewide_plugins', [] ) );
            $active         = array_merge( $active, $network_active );
        }

        foreach ( $active as $plugin_basename ) {
            $plugin_basename = (string) $plugin_basename;
            if ( $plugin_basename === $current ) {
                continue;
            }
            if ( strpos( $plugin_basename, 'waiver-engine' ) !== false ) {
                return $plugin_basename;
            }
        }

        return '';
    }
}

