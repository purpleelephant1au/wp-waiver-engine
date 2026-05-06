<?php
namespace WPWE;

defined( 'ABSPATH' ) || exit;

/**
 * Registers [waiver_form] shortcode and renders the dynamic form HTML
 * from a waiver_template row in the custom DB table.
 *
 * Usage: [waiver_form id="3"]   (template DB row ID)
 */
class Form_Renderer {

    public function register(): void {
        add_shortcode( 'waiver_form', [ $this, 'shortcode' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'maybe_enqueue' ] );
    }

    // -----------------------------------------------------------------------
    // Enqueue
    // -----------------------------------------------------------------------

    public function maybe_enqueue(): void {
        // Always enqueue â€“ shortcode usage can't always be detected early.
        // Scripts use defer so no cost if shortcode is absent.
        wp_enqueue_script(
            'signature-pad',
            \WPWE_PLUGIN_URL . 'assets/js/signature_pad.umd.min.js',
            [],
            '5.0.2',
            true
        );
        wp_enqueue_script(
            'wpwe-form-engine',
            \WPWE_PLUGIN_URL . 'assets/js/form-engine.js',
            [ 'signature-pad' ],
            \WPWE_VERSION,
            true
        );
        wp_localize_script( 'wpwe-form-engine', 'wpweForm', [
            'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
            'nonce'           => wp_create_nonce( 'wpwe_submit_waiver' ),
            'previewNonce'    => wp_create_nonce( 'wpwe_preview_pdf' ),
            'bookingNonce'    => wp_create_nonce( 'wpwe_search_bookings' ),
            'captchaProvider' => Settings::captcha_provider(),
            'captchaSiteKey'  => Settings::captcha_site_key(),
            'i18n'          => [
                'submitting'          => __( 'Submittingâ€¦', 'waiver-engine' ),
                'success'             => __( 'Thank you! Your waiver has been submitted.', 'waiver-engine' ),
                'error'               => __( 'Submission failed. Please try again.', 'waiver-engine' ),
                'required'            => __( 'This field is required.', 'waiver-engine' ),
                'clearSig'            => __( 'Clear', 'waiver-engine' ),
                'addRow'              => __( '+ Add', 'waiver-engine' ),
                'removeRow'           => __( 'Remove', 'waiver-engine' ),
                'preview'             => __( 'Preview', 'waiver-engine' ),
                'previewHeading'      => __( 'PDF Preview', 'waiver-engine' ),
                'pdfLoading'          => __( 'Generating PDF previewâ€¦', 'waiver-engine' ),
                'editForm'            => __( 'â† Edit', 'waiver-engine' ),
                'confirmSubmit'       => __( 'Confirm & Submit', 'waiver-engine' ),
                'notSigned'           => __( 'Not signed', 'waiver-engine' ),
                'bookingSearchLabel'  => __( 'Find your booking', 'waiver-engine' ),
                'bookingSearchHint'   => __( 'Enter booking ID and booking email to verify.', 'waiver-engine' ),
                'bookingSearching'    => __( 'Verifyingâ€¦', 'waiver-engine' ),
                'bookingNoResults'    => __( 'Booking not found or details do not match.', 'waiver-engine' ),
                'bookingSelected'     => __( 'Booking selected:', 'waiver-engine' ),
                'bookingClear'        => __( 'Change', 'waiver-engine' ),
            ],
        ] );
        wp_enqueue_style(
            'wpwe-form',
            \WPWE_PLUGIN_URL . 'assets/css/waiver-form.css',
            [],
            \WPWE_VERSION
        );

        // Conditionally enqueue CAPTCHA SDKs (only when a provider is configured)
        $captcha_provider = Settings::captcha_provider();
        $captcha_site_key = Settings::captcha_site_key();
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
        $atts        = shortcode_atts( [ 'id' => 0, 'template_id' => 0 ], $atts, 'waiver_form' );
        // Support both `id` and legacy `template_id` attribute names
        $template_id = absint( $atts['id'] ) ?: absint( $atts['template_id'] );

        if ( ! $template_id ) {
            return $this->error( __( 'No waiver template specified.', 'waiver-engine' ) );
        }

        // Load from custom DB table
        $template = Database::get_template( $template_id );
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
        $captcha_active   = Settings::captcha_provider() !== 'none' && ! empty( $template->captcha_enabled );
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
        <div class="wpwe-form-wrap" id="wpwe-form-wrap-<?php echo esc_attr( $uid ); ?>">
            <?php if ( $title ) : ?>
            <h2 class="wpwe-form-title"><?php echo esc_html( $title ); ?></h2>
            <?php endif; ?>

            <?php if ( $has_booking_svc ) : ?>
            <!-- Booking search widget -->
            <div class="wpwe-booking-search" data-amelia-services="<?php echo esc_attr( $services_json ); ?>" data-template-id="<?php echo esc_attr( $uid ); ?>">
                <div class="wpwe-booking-search__header">
                    <span class="wpwe-booking-search__label"><?php esc_html_e( 'Verify your booking', 'waiver-engine' ); ?></span>
                    <span class="wpwe-booking-search__hint"><?php esc_html_e( 'Enter your booking ID and the same email used in the booking.', 'waiver-engine' ); ?></span>
                </div>
                <div class="wpwe-booking-search__input-row">
                    <input type="number"
                           class="wpwe-booking-id-lookup"
                           placeholder="<?php esc_attr_e( 'Booking ID (e.g. 123)', 'waiver-engine' ); ?>"
                           min="1"
                           step="1"
                           inputmode="numeric"
                           aria-label="<?php esc_attr_e( 'Booking ID', 'waiver-engine' ); ?>">
                    <input type="email"
                           class="wpwe-booking-email-lookup"
                           placeholder="<?php esc_attr_e( 'Booking email', 'waiver-engine' ); ?>"
                           autocomplete="email"
                           aria-label="<?php esc_attr_e( 'Booking email', 'waiver-engine' ); ?>">
                    <button type="button" class="wpwe-booking-verify-btn"><?php esc_html_e( 'Verify', 'waiver-engine' ); ?></button>
                    <span class="wpwe-booking-searching" hidden><?php esc_html_e( 'Verifyingâ€¦', 'waiver-engine' ); ?></span>
                </div>
                <div class="wpwe-booking-search-error" hidden></div>
                <div class="wpwe-booking-selected" hidden>
                    <span class="wpwe-booking-selected__badge"></span>
                    <button type="button" class="wpwe-booking-clear-btn"><?php esc_html_e( 'Change', 'waiver-engine' ); ?></button>
                </div>
            </div>
            <?php endif; ?>

            <form class="wpwe-form" id="wpwe-form-<?php echo esc_attr( $uid ); ?>"
                  novalidate
                data-template-id="<?php echo esc_attr( $uid ); ?>"
                  data-output-mode="<?php echo esc_attr( $output_mode ); ?>">

                <?php wp_nonce_field( 'wpwe_submit_waiver', 'wpwe_nonce', false ); ?>
                <input type="hidden" name="wpwe_booking_id" class="wpwe-booking-id-input" value="">

                <?php if ( $captcha_active ) : ?>
                <input type="hidden" name="wpwe_captcha_token" class="wpwe-captcha-token" value="">
                <?php endif; ?>

                <?php
                // Anti-bot: timing token (HMAC of timestamp; rejected server-side if < 3 s old)
                $ts = time();
                echo '<input type="hidden" name="wpwe_ts" value="' . esc_attr( $ts ) . '">';
                echo '<input type="hidden" name="wpwe_ts_sig" value="' . esc_attr( hash_hmac( 'sha256', (string) $ts, wp_salt( 'nonce' ) ) ) . '">';
                ?>

                <?php /* Anti-bot honeypot: hidden with CSS; bots fill it, humans leave it blank */ ?>
                <div class="wpwe-hp-field" aria-hidden="true" style="position:absolute;left:-9999px;height:0;overflow:hidden;" tabindex="-1">
                    <label for="wpwe_hp_<?php echo esc_attr( $uid ); ?>"><?php esc_html_e( 'Leave this field empty', 'waiver-engine' ); ?></label>
                    <input type="text" id="wpwe_hp_<?php echo esc_attr( $uid ); ?>" name="wpwe_hp" value="" autocomplete="off" tabindex="-1" aria-hidden="true">
                </div>

                <?php foreach ( $schema['groups'] as $group ) :
                    $this->render_group( $group );
                endforeach; ?>

                <!-- Email-copy opt-in (not saved, just used to send a copy) -->
                <?php if ( $allow_copy_email ) : ?>
                <div class="wpwe-email-copy-wrap">
                    <label class="wpwe-email-copy-toggle">
                        <input type="checkbox" class="wpwe-email-copy-check" name="wpwe_send_copy" value="1">
                        <?php esc_html_e( 'Email me a copy of my waiver PDF', 'waiver-engine' ); ?>
                    </label>
                    <div class="wpwe-email-copy-input" hidden>
                        <label for="wpwe-copy-email-<?php echo esc_attr( $uid ); ?>" class="wpwe-label">
                            <?php esc_html_e( 'Your email address', 'waiver-engine' ); ?>
                            <span class="wpwe-required" aria-hidden="true">*</span>
                        </label>
                        <input type="email"
                               id="wpwe-copy-email-<?php echo esc_attr( $uid ); ?>"
                               name="wpwe_copy_email"
                               class="wpwe-input wpwe-copy-email-input"
                               autocomplete="email"
                               placeholder="you@example.com">
                        <span class="wpwe-field-error wpwe-copy-email-error"></span>
                    </div>
                </div>
                <?php endif; /* $allow_copy_email */ ?>

                <div class="wpwe-submit-wrap">
                    <button type="button" class="wpwe-preview-btn">
                        <?php esc_html_e( 'Preview', 'waiver-engine' ); ?>
                    </button>
                    <button type="submit" class="wpwe-submit-btn">
                        <?php esc_html_e( 'Submit Waiver', 'waiver-engine' ); ?>
                    </button>
                    <span class="wpwe-spinner" style="display:none;" aria-hidden="true"></span>
                </div>

                <div class="wpwe-messages" role="alert" aria-live="polite"></div>
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
        <fieldset class="wpwe-group wpwe-group--repeatable wpwe-group--table"
                  data-group-key="<?php echo esc_attr( $group_key ); ?>"
                  data-min-rows="<?php echo esc_attr( $min_rows ); ?>"
                  data-max-rows="<?php echo esc_attr( $max_rows ); ?>">
            <legend class="wpwe-group-legend">
                <?php echo esc_html( $group_label ); ?>
            </legend>

            <div class="wpwe-table-wrap">
                <table class="wpwe-table">
                    <thead>
                        <tr>
                            <th class="wpwe-th wpwe-th-rownum">#</th>
                            <?php foreach ( $fields as $field ) : ?>
                            <th class="wpwe-th">
                                <?php echo esc_html( $field['label'] ?? ( $field['key'] ?? '' ) ); ?>
                                <?php if ( ! empty( $field['required'] ) ) : ?>
                                <span class="wpwe-required" aria-hidden="true">*</span>
                                <?php endif; ?>
                            </th>
                            <?php endforeach; ?>
                            <th class="wpwe-th wpwe-th-action"></th>
                        </tr>
                    </thead>
                    <tbody class="wpwe-rows">
                        <?php for ( $i = 0; $i < $min_rows; $i++ ) : ?>
                        <tr class="wpwe-row" data-row-index="<?php echo esc_attr( $i ); ?>">
                            <td class="wpwe-cell wpwe-cell--rownum">
                                <span class="wpwe-row-num"><?php echo esc_html( (string) ( absint( $i ) + 1 ) ); ?></span>
                            </td>
                            <?php foreach ( $fields as $field ) :
                                $this->render_field( $field, $group_key, $i, true );
                            endforeach; ?>
                            <td class="wpwe-cell wpwe-cell--action">
                                <button type="button" class="wpwe-row-preview-btn"
                                    title="<?php esc_attr_e( 'Preview PDF for this row', 'waiver-engine' ); ?>">&#128065;</button>
                                <button type="button" class="wpwe-remove-row" style="display:none;">&times;</button>
                            </td>
                        </tr>
                        <?php endfor; ?>
                    </tbody>
                </table>
            </div>

            <template class="wpwe-row-template">
                <tr class="wpwe-row" data-row-index="__IDX__">
                    <td class="wpwe-cell wpwe-cell--rownum">
                        <span class="wpwe-row-num"></span>
                    </td>
                    <?php foreach ( $fields as $field ) :
                        $this->render_field( $field, $group_key, '__IDX__', true );
                    endforeach; ?>
                    <td class="wpwe-cell wpwe-cell--action">
                        <button type="button" class="wpwe-row-preview-btn"
                            title="<?php esc_attr_e( 'Preview PDF for this row', 'waiver-engine' ); ?>">&#128065;</button>
                        <button type="button" class="wpwe-remove-row">&times;</button>
                    </td>
                </tr>
            </template>

            <button type="button" class="wpwe-add-row button">
                <?php
                /* translators: %s: repeatable group label */
                printf( esc_html__( '+ Add %s', 'waiver-engine' ), esc_html( $group_label ) );
                ?>
            </button>
        </fieldset>
        <?php else : ?>
        <fieldset class="wpwe-group"
                  data-group-key="<?php echo esc_attr( $group_key ); ?>">
            <legend class="wpwe-group-legend"><?php echo esc_html( $group_label ); ?></legend>
            <div class="wpwe-rows">
                <div class="wpwe-row" data-row-index="0">
                    <div class="wpwe-fields">
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
        $name     = "wpwe_data[{$group_key}][{$row_index}][{$key}]";
        $id       = "wpwe_{$group_key}_{$row_index}_{$key}";
        $options  = $field['options'] ?? [];

        if ( $in_table ) : ?>
        <td class="wpwe-cell wpwe-cell--<?php echo esc_attr( $type ); ?>"
            data-field-key="<?php echo esc_attr( $key ); ?>">
        <?php else : ?>
        <div class="wpwe-field wpwe-field--<?php echo esc_attr( $type ); ?>"
             data-field-key="<?php echo esc_attr( $key ); ?>">
            <label for="<?php echo esc_attr( $id ); ?>" class="wpwe-label">
                <?php echo esc_html( $label ); ?>
                <?php if ( $required ) : ?>
                <span class="wpwe-required" aria-hidden="true">*</span>
                <?php endif; ?>
            </label>
        <?php endif;

        switch ( $type ) :
            case 'textarea': ?>
                <textarea id="<?php echo esc_attr( $id ); ?>"
                          name="<?php echo esc_attr( $name ); ?>"
                          class="wpwe-input wpwe-textarea"
                          rows="<?php echo $in_table ? '2' : '4'; ?>"
                          <?php if ( $required ) : ?>required aria-required="true"<?php endif; ?>></textarea>
                <?php break;

            case 'select': ?>
                <select id="<?php echo esc_attr( $id ); ?>"
                        name="<?php echo esc_attr( $name ); ?>"
                        class="wpwe-input wpwe-select"
                    <?php if ( $required ) : ?>required aria-required="true"<?php endif; ?>>
                    <option value=""><?php esc_html_e( 'â€” Select â€”', 'waiver-engine' ); ?></option>
                    <?php foreach ( $options as $opt ) : ?>
                    <option value="<?php echo esc_attr( $opt['value'] ?? $opt ); ?>">
                        <?php echo esc_html( $opt['label'] ?? $opt ); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <?php break;

            case 'checkbox': ?>
                <label class="wpwe-checkbox-label">
                    <input type="checkbox"
                           id="<?php echo esc_attr( $id ); ?>"
                           name="<?php echo esc_attr( $name ); ?>"
                           value="1"
                           class="wpwe-input wpwe-checkbox"
                              <?php if ( $required ) : ?>required aria-required="true"<?php endif; ?>>
                          <?php if ( ! $in_table ) echo esc_html( $label ); ?>
                </label>
                <?php break;

            case 'signature': ?>
                <div class="wpwe-signature-wrap">
                    <canvas class="wpwe-signature-canvas"
                            id="<?php echo esc_attr( $id ); ?>"
                            aria-label="<?php echo esc_attr( $label ); ?>"
                            role="img"></canvas>
                    <input type="hidden"
                           name="<?php echo esc_attr( $name ); ?>"
                           class="wpwe-signature-data"
                              <?php if ( $required ) : ?>data-required="1"<?php endif; ?>>
                    <button type="button" class="wpwe-clear-signature">
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
                       class="wpwe-input wpwe-input--<?php echo esc_attr( $html_type ); ?>"
                      <?php if ( $required ) : ?>required aria-required="true"<?php endif; ?>>
                <?php break;
        endswitch; ?>
        <span class="wpwe-field-error" role="alert"></span>
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
        return '<p class="wpwe-error">' . esc_html( $msg ) . '</p>';
    }

    private function build_form_premium_context( object $template ): array {
        $defaults = [
            'amelia_services'  => [],
            'allow_copy_email' => false,
            'output_mode'      => 'single',
        ];

        if ( class_exists( Premium_Bridge::class ) && method_exists( Premium_Bridge::class, 'build_form_premium_context' ) ) {
            $premium = Premium_Bridge::build_form_premium_context( $template );
            if ( is_array( $premium ) ) {
                return array_merge( $defaults, $premium );
            }
        }

        return $defaults;
    }

    private function is_repeatable_group( array $group ): bool {
        if ( class_exists( Premium_Bridge::class ) && method_exists( Premium_Bridge::class, 'is_repeatable_group' ) ) {
            return Premium_Bridge::is_repeatable_group( $group );
        }

        return false;
    }
}

