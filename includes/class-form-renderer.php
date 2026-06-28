<?php
namespace Pewave\WaiverEngine;

defined( 'ABSPATH' ) || exit;

/**
 * Registers [pewave_form] shortcode and renders the dynamic form HTML
 * from a pewave_template row in the custom DB table.
 *
 * Usage: [pewave_form id="3"]   (template DB row ID)
 */
class PeWave_Form_Renderer {

    public function register(): void {
        add_shortcode( 'pewave_form', [ $this, 'shortcode' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'maybe_enqueue' ] );
    }

    // -----------------------------------------------------------------------
    // Enqueue
    // -----------------------------------------------------------------------

    public function maybe_enqueue(): void {
        // Always enqueue – shortcode usage can't always be detected early.
        // Scripts use defer so no cost if shortcode is absent.
        wp_enqueue_script(
            'signature-pad',
            \PEWAVE_PLUGIN_URL . 'assets/js/signature_pad.umd.min.js',
            [],
            '5.0.2',
            true
        );
        wp_enqueue_script(
            'pewave-form-engine',
            \PEWAVE_PLUGIN_URL . 'assets/js/form-engine.js',
            [ 'signature-pad' ],
            \PEWAVE_VERSION,
            true
        );
        wp_localize_script( 'pewave-form-engine', 'pewaveForm', [
            'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
            'nonce'           => wp_create_nonce( 'pewave_submit_waiver' ),
            'previewNonce'    => wp_create_nonce( 'pewave_preview_pdf' ),
            'bookingNonce'    => wp_create_nonce( 'pewave_search_bookings' ),
            'captchaProvider' => PeWave_Settings::captcha_provider(),
            'captchaSiteKey'  => PeWave_Settings::captcha_site_key(),
            'i18n'          => [
                'submitting'          => __( 'Submitting…', 'waiver-engine' ),
                'success'             => __( 'Thank you! Your waiver has been submitted.', 'waiver-engine' ),
                'error'               => __( 'Submission failed. Please try again.', 'waiver-engine' ),
                'required'            => __( 'This field is required.', 'waiver-engine' ),
                'clearSig'            => __( 'Clear', 'waiver-engine' ),
                'addRow'              => __( '+ Add', 'waiver-engine' ),
                'removeRow'           => __( 'Remove', 'waiver-engine' ),
                'preview'             => __( 'Preview', 'waiver-engine' ),
                'previewHeading'      => __( 'PDF Preview', 'waiver-engine' ),
                'pdfLoading'          => __( 'Generating PDF preview…', 'waiver-engine' ),
                'editForm'            => __( '← Edit', 'waiver-engine' ),
                'confirmSubmit'       => __( 'Confirm & Submit', 'waiver-engine' ),
                'notSigned'           => __( 'Not signed', 'waiver-engine' ),
                'bookingSearchLabel'  => __( 'Find your booking', 'waiver-engine' ),
                'bookingSearchHint'   => __( 'Enter booking ID and booking email to verify.', 'waiver-engine' ),
                'bookingSearching'    => __( 'Verifying…', 'waiver-engine' ),
                'bookingNoResults'    => __( 'Booking not found or details do not match.', 'waiver-engine' ),
                'bookingSelected'     => __( 'Booking selected:', 'waiver-engine' ),
                'bookingClear'        => __( 'Change', 'waiver-engine' ),
            ],
        ] );
        wp_enqueue_style(
            'pewave-form',
            \PEWAVE_PLUGIN_URL . 'assets/css/waiver-form.css',
            [],
            \PEWAVE_VERSION
        );

        // Conditionally enqueue CAPTCHA SDKs (only when a provider is configured)
        $captcha_provider = PeWave_Settings::captcha_provider();
        $captcha_site_key = PeWave_Settings::captcha_site_key();
        if ( $captcha_provider === 'recaptcha_v3' && $captcha_site_key ) {
            wp_enqueue_script(
                'google-recaptcha',
                'https://www.google.com/recaptcha/api.js?render=' . rawurlencode( $captcha_site_key ),
                [],
                null, // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
                true
            );
        } elseif ( $captcha_provider === 'hcaptcha' && $captcha_site_key ) {
            wp_enqueue_script(
                'hcaptcha',
                'https://js.hcaptcha.com/1/api.js',
                [],
                null, // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
                true
            );
        }
    }

    // -----------------------------------------------------------------------
    // Shortcode
    // -----------------------------------------------------------------------

    public function shortcode( array $atts ): string {
        $atts        = shortcode_atts( [ 'id' => 0, 'template_id' => 0 ], $atts, 'pewave_form' );
        // Support both `id` and legacy `template_id` attribute names
        $template_id = absint( $atts['id'] ) ?: absint( $atts['template_id'] );

        if ( ! $template_id ) {
            return $this->error( __( 'No waiver template specified.', 'waiver-engine' ) );
        }

        // Load from custom DB table
        $template = PeWave_Database::get_template( $template_id );
        if ( ! $template ) {
            return $this->error( __( 'Waiver template not found.', 'waiver-engine' ) );
        }
        if ( ! $template->active ) {
            return $this->error( __( 'This waiver form is currently unavailable.', 'waiver-engine' ) );
        }

        $schema = json_decode( $template->field_schema, true );
        if ( ! is_array( $schema ) || empty( $schema['groups'] ) ) {
            return $this->error( __( 'Waiver template has no valid field schema.', 'waiver-engine' ) );
        }

        ob_start();
        $premium_context  = $this->build_form_premium_context( $template );
        $amelia_services  = array_values( (array) ( $premium_context['amelia_services'] ?? [] ) );
        $allow_copy_email = ! empty( $premium_context['allow_copy_email'] );
        $captcha_active   = PeWave_Settings::captcha_provider() !== 'none' && ! empty( $template->captcha_enabled );
        $output_mode      = (string) ( $premium_context['output_mode'] ?? 'single' );
        $this->render_form( $template_id, $template->title, $schema, $output_mode, $amelia_services, $allow_copy_email, $captcha_active );
        return ob_get_clean();
    }

    // -----------------------------------------------------------------------
    // Render Form
    // -----------------------------------------------------------------------

    private function render_form( int $template_id, string $title, array $schema, string $output_mode = 'single', array $amelia_services = [], bool $allow_copy_email = true, bool $captcha_active = false ): void {
        $uid             = $template_id;
        $services_json   = wp_json_encode( $amelia_services );
        $has_booking_svc = ! empty( $amelia_services );
        ?>
        <div class="pewave-form-wrap" id="pewave-form-wrap-<?php echo esc_attr( $uid ); ?>">
            <?php if ( $title ) : ?>
            <h2 class="pewave-form-title"><?php echo esc_html( $title ); ?></h2>
            <?php endif; ?>

            <?php if ( $has_booking_svc ) : ?>
            <!-- Booking search widget -->
            <div class="pewave-booking-search" data-amelia-services="<?php echo esc_attr( $services_json ); ?>" data-template-id="<?php echo esc_attr( $uid ); ?>">
                <div class="pewave-booking-search__header">
                    <span class="pewave-booking-search__label"><?php esc_html_e( 'Verify your booking', 'waiver-engine' ); ?></span>
                    <span class="pewave-booking-search__hint"><?php esc_html_e( 'Enter your booking ID and the same email used in the booking.', 'waiver-engine' ); ?></span>
                </div>
                <div class="pewave-booking-search__input-row">
                    <input type="number"
                           class="pewave-booking-id-lookup"
                           placeholder="<?php esc_attr_e( 'Booking ID (e.g. 123)', 'waiver-engine' ); ?>"
                           min="1"
                           step="1"
                           inputmode="numeric"
                           aria-label="<?php esc_attr_e( 'Booking ID', 'waiver-engine' ); ?>">
                    <input type="email"
                           class="pewave-booking-email-lookup"
                           placeholder="<?php esc_attr_e( 'Booking email', 'waiver-engine' ); ?>"
                           autocomplete="email"
                           aria-label="<?php esc_attr_e( 'Booking email', 'waiver-engine' ); ?>">
                    <button type="button" class="pewave-booking-verify-btn"><?php esc_html_e( 'Verify', 'waiver-engine' ); ?></button>
                    <span class="pewave-booking-searching" hidden><?php esc_html_e( 'Verifying…', 'waiver-engine' ); ?></span>
                </div>
                <div class="pewave-booking-search-error" hidden></div>
                <div class="pewave-booking-selected" hidden>
                    <span class="pewave-booking-selected__badge"></span>
                    <button type="button" class="pewave-booking-clear-btn"><?php esc_html_e( 'Change', 'waiver-engine' ); ?></button>
                </div>
            </div>
            <?php endif; ?>

            <form class="pewave-form" id="pewave-form-<?php echo esc_attr( $uid ); ?>"
                  novalidate
                data-template-id="<?php echo esc_attr( $uid ); ?>"
                  data-output-mode="<?php echo esc_attr( $output_mode ); ?>">

                <?php wp_nonce_field( 'pewave_submit_waiver', 'pewave_nonce', false ); ?>
                <input type="hidden" name="pewave_booking_id" class="pewave-booking-id-input" value="">

                <?php if ( $captcha_active ) : ?>
                <input type="hidden" name="pewave_captcha_token" class="pewave-captcha-token" value="">
                <?php endif; ?>

                <?php
                // Anti-bot: timing token (HMAC of timestamp; rejected server-side if < 3 s old)
                $ts = time();
                echo '<input type="hidden" name="pewave_ts" value="' . esc_attr( $ts ) . '">';
                echo '<input type="hidden" name="pewave_ts_sig" value="' . esc_attr( hash_hmac( 'sha256', (string) $ts, wp_salt( 'nonce' ) ) ) . '">';
                ?>

                <?php /* Anti-bot honeypot: hidden with CSS; bots fill it, humans leave it blank */ ?>
                <div class="pewave-hp-field" aria-hidden="true" style="position:absolute;left:-9999px;height:0;overflow:hidden;" tabindex="-1">
                    <label for="pewave_hp_<?php echo esc_attr( $uid ); ?>"><?php esc_html_e( 'Leave this field empty', 'waiver-engine' ); ?></label>
                    <input type="text" id="pewave_hp_<?php echo esc_attr( $uid ); ?>" name="pewave_hp" value="" autocomplete="off" tabindex="-1" aria-hidden="true">
                </div>

                <?php foreach ( $schema['groups'] as $group ) :
                    $this->render_group( $group );
                endforeach; ?>

                <!-- Email-copy opt-in (not saved, just used to send a copy) -->
                <?php if ( $allow_copy_email ) : ?>
                <div class="pewave-email-copy-wrap">
                    <label class="pewave-email-copy-toggle">
                        <input type="checkbox" class="pewave-email-copy-check" name="pewave_send_copy" value="1">
                        <?php esc_html_e( 'Email me a copy of my waiver PDF', 'waiver-engine' ); ?>
                    </label>
                    <div class="pewave-email-copy-input" hidden>
                        <label for="pewave-copy-email-<?php echo esc_attr( $uid ); ?>" class="pewave-label">
                            <?php esc_html_e( 'Your email address', 'waiver-engine' ); ?>
                            <span class="pewave-required" aria-hidden="true">*</span>
                        </label>
                        <input type="email"
                               id="pewave-copy-email-<?php echo esc_attr( $uid ); ?>"
                               name="pewave_copy_email"
                               class="pewave-input pewave-copy-email-input"
                               autocomplete="email"
                               placeholder="you@example.com">
                        <span class="pewave-field-error pewave-copy-email-error"></span>
                    </div>
                </div>
                <?php endif; /* $allow_copy_email */ ?>

                <div class="pewave-submit-wrap">
                    <button type="button" class="pewave-preview-btn">
                        <?php esc_html_e( 'Preview', 'waiver-engine' ); ?>
                    </button>
                    <button type="submit" class="pewave-submit-btn">
                        <?php esc_html_e( 'Submit Waiver', 'waiver-engine' ); ?>
                    </button>
                    <span class="pewave-spinner" style="display:none;" aria-hidden="true"></span>
                </div>

                <div class="pewave-messages" role="alert" aria-live="polite"></div>
            </form>
        </div>
        <?php
    }

    // -----------------------------------------------------------------------
    // Render Group
    // -----------------------------------------------------------------------

    private function render_group( array $group ): void {
        $repeatable  = $this->is_repeatable_group( $group );
        $min_rows    = max( 1, (int) ( $group['min_rows'] ?? 1 ) );
        $max_rows    = (int) ( $group['max_rows'] ?? 20 );
        $group_key   = sanitize_key( $group['key'] ?? '' );
        $group_label = (string) ( $group['label'] ?? $group_key );
        $fields      = $group['fields'] ?? [];

        if ( $repeatable ) : ?>
        <fieldset class="pewave-group pewave-group--repeatable pewave-group--table"
                  data-group-key="<?php echo esc_attr( $group_key ); ?>"
                  data-min-rows="<?php echo esc_attr( $min_rows ); ?>"
                  data-max-rows="<?php echo esc_attr( $max_rows ); ?>">
            <legend class="pewave-group-legend">
                <?php echo esc_html( $group_label ); ?>
            </legend>

            <div class="pewave-table-wrap">
                <table class="pewave-table">
                    <thead>
                        <tr>
                            <th class="pewave-th pewave-th-rownum">#</th>
                            <?php foreach ( $fields as $field ) : ?>
                            <th class="pewave-th">
                                <?php echo esc_html( $field['label'] ?? ( $field['key'] ?? '' ) ); ?>
                                <?php if ( ! empty( $field['required'] ) ) : ?>
                                <span class="pewave-required" aria-hidden="true">*</span>
                                <?php endif; ?>
                            </th>
                            <?php endforeach; ?>
                            <th class="pewave-th pewave-th-action"></th>
                        </tr>
                    </thead>
                    <tbody class="pewave-rows">
                        <?php for ( $i = 0; $i < $min_rows; $i++ ) : ?>
                        <tr class="pewave-row" data-row-index="<?php echo esc_attr( $i ); ?>">
                            <td class="pewave-cell pewave-cell--rownum">
                                <span class="pewave-row-num"><?php echo esc_html( (string) ( absint( $i ) + 1 ) ); ?></span>
                            </td>
                            <?php foreach ( $fields as $field ) :
                                $this->render_field( $field, $group_key, $i, true );
                            endforeach; ?>
                            <td class="pewave-cell pewave-cell--action">
                                <button type="button" class="pewave-row-preview-btn"
                                    title="<?php esc_attr_e( 'Preview PDF for this row', 'waiver-engine' ); ?>">&#128065;</button>
                                <button type="button" class="pewave-remove-row" style="display:none;">&times;</button>
                            </td>
                        </tr>
                        <?php endfor; ?>
                    </tbody>
                </table>
            </div>

            <template class="pewave-row-template">
                <tr class="pewave-row" data-row-index="__IDX__">
                    <td class="pewave-cell pewave-cell--rownum">
                        <span class="pewave-row-num"></span>
                    </td>
                    <?php foreach ( $fields as $field ) :
                        $this->render_field( $field, $group_key, '__IDX__', true );
                    endforeach; ?>
                    <td class="pewave-cell pewave-cell--action">
                        <button type="button" class="pewave-row-preview-btn"
                            title="<?php esc_attr_e( 'Preview PDF for this row', 'waiver-engine' ); ?>">&#128065;</button>
                        <button type="button" class="pewave-remove-row">&times;</button>
                    </td>
                </tr>
            </template>

            <button type="button" class="pewave-add-row button">
                <?php
                /* translators: %s: repeatable group label */
                printf( esc_html__( '+ Add %s', 'waiver-engine' ), esc_html( $group_label ) );
                ?>
            </button>
        </fieldset>
        <?php else : ?>
        <fieldset class="pewave-group"
                  data-group-key="<?php echo esc_attr( $group_key ); ?>">
            <legend class="pewave-group-legend"><?php echo esc_html( $group_label ); ?></legend>
            <div class="pewave-rows">
                <div class="pewave-row" data-row-index="0">
                    <div class="pewave-fields">
                        <?php foreach ( $fields as $field ) :
                            $this->render_field( $field, $group_key, 0 );
                        endforeach; ?>
                    </div>
                </div>
            </div>
        </fieldset>
        <?php endif;
    }

    // -----------------------------------------------------------------------
    // Render individual field
    // -----------------------------------------------------------------------

    private function render_field( array $field, string $group_key, int|string $row_index, bool $in_table = false ): void {
        $key      = sanitize_key( $field['key'] ?? '' );
        $label    = (string) ( $field['label'] ?? $key );
        $type     = sanitize_key( $field['type'] ?? 'text' );
        $required = ! empty( $field['required'] );
            $name     = "pewave_data[{$group_key}][{$row_index}][{$key}]";
        $id       = "pewave_{$group_key}_{$row_index}_{$key}";
        $options  = $field['options'] ?? [];

        if ( $in_table ) : ?>
        <td class="pewave-cell pewave-cell--<?php echo esc_attr( $type ); ?>"
            data-field-key="<?php echo esc_attr( $key ); ?>">
        <?php else : ?>
        <div class="pewave-field pewave-field--<?php echo esc_attr( $type ); ?>"
             data-field-key="<?php echo esc_attr( $key ); ?>">
            <label for="<?php echo esc_attr( $id ); ?>" class="pewave-label">
                <?php echo esc_html( $label ); ?>
                <?php if ( $required ) : ?>
                <span class="pewave-required" aria-hidden="true">*</span>
                <?php endif; ?>
            </label>
        <?php endif;

        switch ( $type ) :
            case 'textarea': ?>
                <textarea id="<?php echo esc_attr( $id ); ?>"
                          name="<?php echo esc_attr( $name ); ?>"
                          class="pewave-input pewave-textarea"
                          rows="<?php echo $in_table ? '2' : '4'; ?>"
                          <?php if ( $required ) : ?>required aria-required="true"<?php endif; ?>></textarea>
                <?php break;

            case 'select': ?>
                <select id="<?php echo esc_attr( $id ); ?>"
                        name="<?php echo esc_attr( $name ); ?>"
                        class="pewave-input pewave-select"
                    <?php if ( $required ) : ?>required aria-required="true"<?php endif; ?>>
                    <option value=""><?php esc_html_e( '— Select —', 'waiver-engine' ); ?></option>
                    <?php foreach ( $options as $opt ) : ?>
                    <option value="<?php echo esc_attr( $opt['value'] ?? $opt ); ?>">
                        <?php echo esc_html( $opt['label'] ?? $opt ); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <?php break;

            case 'checkbox': ?>
                <label class="pewave-checkbox-label">
                    <input type="checkbox"
                           id="<?php echo esc_attr( $id ); ?>"
                           name="<?php echo esc_attr( $name ); ?>"
                           value="1"
                           class="pewave-input pewave-checkbox"
                              <?php if ( $required ) : ?>required aria-required="true"<?php endif; ?>>
                          <?php if ( ! $in_table ) echo esc_html( $label ); ?>
                </label>
                <?php break;

            case 'signature': ?>
                <div class="pewave-signature-wrap">
                    <canvas class="pewave-signature-canvas"
                            id="<?php echo esc_attr( $id ); ?>"
                            aria-label="<?php echo esc_attr( $label ); ?>"
                            role="img"></canvas>
                    <input type="hidden"
                           name="<?php echo esc_attr( $name ); ?>"
                           class="pewave-signature-data"
                              <?php if ( $required ) : ?>data-required="1"<?php endif; ?>>
                    <button type="button" class="pewave-clear-signature">
                        <?php esc_html_e( 'Clear', 'waiver-engine' ); ?>
                    </button>
                </div>
                <?php break;

            default: // text, email, number, date, tel, url
                $html_type = in_array( $type, [ 'text', 'email', 'number', 'date', 'tel', 'url' ], true ) ? $type : 'text';
                ?>
                <input type="<?php echo esc_attr( $html_type ); ?>"
                       id="<?php echo esc_attr( $id ); ?>"
                       name="<?php echo esc_attr( $name ); ?>"
                       class="pewave-input pewave-input--<?php echo esc_attr( $html_type ); ?>"
                      <?php if ( $required ) : ?>required aria-required="true"<?php endif; ?>>
                <?php break;
        endswitch; ?>
        <span class="pewave-field-error" role="alert"></span>
        <?php if ( $in_table ) : ?>
        </td>
        <?php else : ?>
        </div>
        <?php endif;
    }

    // -----------------------------------------------------------------------
    // Error helper
    // -----------------------------------------------------------------------

    private function error( string $msg ): string {
        return '<p class="pewave-error">' . esc_html( $msg ) . '</p>';
    }

    private function build_form_premium_context( object $template ): array {
        $defaults = [
            'amelia_services'  => [],
            'allow_copy_email' => false,
            'output_mode'      => 'single',
        ];

        if ( class_exists( PeWave_Premium_Bridge::class ) && method_exists( PeWave_Premium_Bridge::class, 'build_form_premium_context' ) ) {
            $premium = PeWave_Premium_Bridge::build_form_premium_context( $template );
            if ( is_array( $premium ) ) {
                return array_merge( $defaults, $premium );
            }
        }

        return $defaults;
    }

    private function is_repeatable_group( array $group ): bool {
        if ( class_exists( PeWave_Premium_Bridge::class ) && method_exists( PeWave_Premium_Bridge::class, 'is_repeatable_group' ) ) {
            return PeWave_Premium_Bridge::is_repeatable_group( $group );
        }

        return false;
    }
}

