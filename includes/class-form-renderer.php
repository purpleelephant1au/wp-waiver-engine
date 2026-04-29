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
        // Always enqueue – shortcode usage can't always be detected early.
        // Scripts use defer so no cost if shortcode is absent.
        wp_enqueue_script(
            'signature-pad',
            WPWE_PLUGIN_URL . 'assets/js/signature_pad.umd.min.js',
            [],
            '5.0.2',
            true
        );
        wp_enqueue_script(
            'wpwe-form-engine',
            WPWE_PLUGIN_URL . 'assets/js/form-engine.js',
            [ 'signature-pad' ],
            WPWE_VERSION,
            true
        );
        wp_localize_script( 'wpwe-form-engine', 'wpweForm', [
            'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
            'nonce'         => wp_create_nonce( 'wpwe_submit_waiver' ),
            'previewNonce'  => wp_create_nonce( 'wpwe_preview_pdf' ),
            'bookingNonce'  => wp_create_nonce( 'wpwe_search_bookings' ),
            'i18n'          => [
                'submitting'          => __( 'Submitting…', 'wp-waiver-engine' ),
                'success'             => __( 'Thank you! Your waiver has been submitted.', 'wp-waiver-engine' ),
                'error'               => __( 'Submission failed. Please try again.', 'wp-waiver-engine' ),
                'required'            => __( 'This field is required.', 'wp-waiver-engine' ),
                'clearSig'            => __( 'Clear', 'wp-waiver-engine' ),
                'addRow'              => __( '+ Add', 'wp-waiver-engine' ),
                'removeRow'           => __( 'Remove', 'wp-waiver-engine' ),
                'preview'             => __( 'Preview', 'wp-waiver-engine' ),
                'previewHeading'      => __( 'PDF Preview', 'wp-waiver-engine' ),
                'pdfLoading'          => __( 'Generating PDF preview…', 'wp-waiver-engine' ),
                'editForm'            => __( '← Edit', 'wp-waiver-engine' ),
                'confirmSubmit'       => __( 'Confirm & Submit', 'wp-waiver-engine' ),
                'notSigned'           => __( 'Not signed', 'wp-waiver-engine' ),
                'bookingSearchLabel'  => __( 'Find your booking', 'wp-waiver-engine' ),
                'bookingSearchHint'   => __( 'Search by customer name or date (YYYY-MM-DD)', 'wp-waiver-engine' ),
                'bookingSearching'    => __( 'Searching…', 'wp-waiver-engine' ),
                'bookingNoResults'    => __( 'No upcoming bookings found.', 'wp-waiver-engine' ),
                'bookingSelected'     => __( 'Booking selected:', 'wp-waiver-engine' ),
                'bookingClear'        => __( 'Change', 'wp-waiver-engine' ),
            ],
        ] );
        wp_enqueue_style(
            'wpwe-form',
            WPWE_PLUGIN_URL . 'assets/css/waiver-form.css',
            [],
            WPWE_VERSION
        );
    }

    // -----------------------------------------------------------------------
    // Shortcode
    // -----------------------------------------------------------------------

    public function shortcode( array $atts ): string {
        $atts        = shortcode_atts( [ 'id' => 0, 'template_id' => 0 ], $atts, 'waiver_form' );
        // Support both `id` and legacy `template_id` attribute names
        $template_id = absint( $atts['id'] ) ?: absint( $atts['template_id'] );

        if ( ! $template_id ) {
            return $this->error( __( 'No waiver template specified.', 'wp-waiver-engine' ) );
        }

        // Load from custom DB table
        $template = Database::get_template( $template_id );
        if ( ! $template ) {
            return $this->error( __( 'Waiver template not found.', 'wp-waiver-engine' ) );
        }
        if ( ! $template->active ) {
            return $this->error( __( 'This waiver form is currently unavailable.', 'wp-waiver-engine' ) );
        }

        $schema = json_decode( $template->field_schema, true );
        if ( ! is_array( $schema ) || empty( $schema['groups'] ) ) {
            return $this->error( __( 'Waiver template has no valid field schema.', 'wp-waiver-engine' ) );
        }

        $amelia_services = [];
        if ( Settings::is_amelia_enabled() ) {
            $amelia_services = json_decode( $template->amelia_service_ids ?? '', true ) ?: [];
            $amelia_services = array_map( 'intval', $amelia_services );
        }

        ob_start();
        $allow_copy_email = Settings::is_user_email_enabled() && ! empty( $template->send_user_email );
        $this->render_form( $template_id, $template->title, $schema, $template->output_mode ?? 'single', $amelia_services, $allow_copy_email );
        return ob_get_clean();
    }

    // -----------------------------------------------------------------------
    // Render Form
    // -----------------------------------------------------------------------

    private function render_form( int $template_id, string $title, array $schema, string $output_mode = 'single', array $amelia_services = [], bool $allow_copy_email = true ): void {
        $uid             = esc_attr( $template_id );
        $services_json   = esc_attr( wp_json_encode( $amelia_services ) );
        $has_booking_svc = ! empty( $amelia_services );
        ?>
        <div class="wpwe-form-wrap" id="wpwe-form-wrap-<?php echo $uid; ?>">
            <?php if ( $title ) : ?>
            <h2 class="wpwe-form-title"><?php echo esc_html( $title ); ?></h2>
            <?php endif; ?>

            <?php if ( $has_booking_svc ) : ?>
            <!-- Booking search widget -->
            <div class="wpwe-booking-search" data-amelia-services="<?php echo $services_json; ?>" data-template-id="<?php echo $uid; ?>">
                <div class="wpwe-booking-search__header">
                    <span class="wpwe-booking-search__label"><?php esc_html_e( 'Find your booking', 'wp-waiver-engine' ); ?></span>
                    <span class="wpwe-booking-search__hint"><?php esc_html_e( 'Search by customer name or pick a date', 'wp-waiver-engine' ); ?></span>
                </div>
                <div class="wpwe-booking-search__input-row">
                    <input type="text"
                           class="wpwe-booking-search-input"
                           placeholder="<?php esc_attr_e( 'e.g. Jane Smith', 'wp-waiver-engine' ); ?>"
                           autocomplete="off"
                           aria-label="<?php esc_attr_e( 'Search bookings by name', 'wp-waiver-engine' ); ?>">
                    <span class="wpwe-booking-search__or"><?php esc_html_e( 'or pick a date:', 'wp-waiver-engine' ); ?></span>
                    <input type="date"
                           class="wpwe-booking-date-picker"
                           title="<?php esc_attr_e( 'Pick a booking date', 'wp-waiver-engine' ); ?>"
                           aria-label="<?php esc_attr_e( 'Pick a booking date', 'wp-waiver-engine' ); ?>">
                    <span class="wpwe-booking-searching" hidden><?php esc_html_e( 'Searching…', 'wp-waiver-engine' ); ?></span>
                </div>
                <ul class="wpwe-booking-results" hidden role="listbox" aria-label="<?php esc_attr_e( 'Booking results', 'wp-waiver-engine' ); ?>"></ul>
                <div class="wpwe-booking-selected" hidden>
                    <span class="wpwe-booking-selected__badge"></span>
                    <button type="button" class="wpwe-booking-clear-btn"><?php esc_html_e( 'Change', 'wp-waiver-engine' ); ?></button>
                </div>
            </div>
            <?php endif; ?>

            <form class="wpwe-form" id="wpwe-form-<?php echo $uid; ?>"
                  novalidate
                  data-template-id="<?php echo $uid; ?>"
                  data-output-mode="<?php echo esc_attr( $output_mode ); ?>">

                <?php wp_nonce_field( 'wpwe_submit_waiver', 'wpwe_nonce', false ); ?>
                <input type="hidden" name="wpwe_booking_id" class="wpwe-booking-id-input" value="">

                <?php
                // Anti-bot: timing token (HMAC of timestamp; rejected server-side if < 3 s old)
                $ts = time();
                echo '<input type="hidden" name="wpwe_ts" value="' . esc_attr( $ts ) . '">';
                echo '<input type="hidden" name="wpwe_ts_sig" value="' . esc_attr( hash_hmac( 'sha256', (string) $ts, wp_salt( 'nonce' ) ) ) . '">';
                ?>

                <?php /* Anti-bot honeypot: hidden with CSS; bots fill it, humans leave it blank */ ?>
                <div class="wpwe-hp-field" aria-hidden="true" style="position:absolute;left:-9999px;height:0;overflow:hidden;" tabindex="-1">
                    <label for="wpwe_hp_<?php echo $uid; ?>"><?php esc_html_e( 'Leave this field empty', 'wp-waiver-engine' ); ?></label>
                    <input type="text" id="wpwe_hp_<?php echo $uid; ?>" name="wpwe_hp" value="" autocomplete="off" tabindex="-1" aria-hidden="true">
                </div>

                <?php foreach ( $schema['groups'] as $group ) :
                    $this->render_group( $group );
                endforeach; ?>

                <!-- Email-copy opt-in (not saved, just used to send a copy) -->
                <?php if ( $allow_copy_email ) : ?>
                <div class="wpwe-email-copy-wrap">
                    <label class="wpwe-email-copy-toggle">
                        <input type="checkbox" class="wpwe-email-copy-check" name="wpwe_send_copy" value="1">
                        <?php esc_html_e( 'Email me a copy of my waiver PDF', 'wp-waiver-engine' ); ?>
                    </label>
                    <div class="wpwe-email-copy-input" hidden>
                        <label for="wpwe-copy-email-<?php echo $uid; ?>" class="wpwe-label">
                            <?php esc_html_e( 'Your email address', 'wp-waiver-engine' ); ?>
                            <span class="wpwe-required" aria-hidden="true">*</span>
                        </label>
                        <input type="email"
                               id="wpwe-copy-email-<?php echo $uid; ?>"
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
                        <?php esc_html_e( 'Preview', 'wp-waiver-engine' ); ?>
                    </button>
                    <button type="submit" class="wpwe-submit-btn">
                        <?php esc_html_e( 'Submit Waiver', 'wp-waiver-engine' ); ?>
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
        $repeatable  = ! empty( $group['repeatable'] );
        $min_rows    = max( 1, (int) ( $group['min_rows'] ?? 1 ) );
        $max_rows    = (int) ( $group['max_rows'] ?? 20 );
        $group_key   = sanitize_key( $group['key'] ?? '' );
        $group_label = esc_html( $group['label'] ?? $group_key );
        $fields      = $group['fields'] ?? [];

        if ( $repeatable ) : ?>
        <fieldset class="wpwe-group wpwe-group--repeatable wpwe-group--table"
                  data-group-key="<?php echo esc_attr( $group_key ); ?>"
                  data-min-rows="<?php echo esc_attr( $min_rows ); ?>"
                  data-max-rows="<?php echo esc_attr( $max_rows ); ?>"
                  data-mode="repeating">
            <legend class="wpwe-group-legend">
                <?php echo $group_label; ?>
                <span class="wpwe-mode-wrap">
                    <span class="wpwe-mode-label"><?php esc_html_e( 'Mode:', 'wp-waiver-engine' ); ?></span>
                    <select class="wpwe-mode-select" id="wpwe-mode-<?php echo esc_attr( $group_key ); ?>">
                        <option value="single"><?php esc_html_e( 'Single', 'wp-waiver-engine' ); ?></option>
                        <option value="repeating" selected><?php esc_html_e( 'Repeating', 'wp-waiver-engine' ); ?></option>
                    </select>
                </span>
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
                                <span class="wpwe-row-num"><?php echo $i + 1; ?></span>
                            </td>
                            <?php foreach ( $fields as $field ) :
                                $this->render_field( $field, $group_key, $i, true );
                            endforeach; ?>
                            <td class="wpwe-cell wpwe-cell--action">
                                <button type="button" class="wpwe-row-preview-btn"
                                    title="<?php esc_attr_e( 'Preview PDF for this row', 'wp-waiver-engine' ); ?>">&#128065;</button>
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
                            title="<?php esc_attr_e( 'Preview PDF for this row', 'wp-waiver-engine' ); ?>">&#128065;</button>
                        <button type="button" class="wpwe-remove-row">&times;</button>
                    </td>
                </tr>
            </template>

            <button type="button" class="wpwe-add-row button">
                <?php printf( esc_html__( '+ Add %s', 'wp-waiver-engine' ), $group_label ); ?>
            </button>
        </fieldset>
        <?php else : ?>
        <fieldset class="wpwe-group"
                  data-group-key="<?php echo esc_attr( $group_key ); ?>">
            <legend class="wpwe-group-legend"><?php echo $group_label; ?></legend>
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

    private function render_field( array $field, string $group_key, $row_index, bool $in_table = false ): void {
        $key      = sanitize_key( $field['key'] ?? '' );
        $label    = esc_html( $field['label'] ?? $key );
        $type     = sanitize_key( $field['type'] ?? 'text' );
        $required = ! empty( $field['required'] );
        $name     = "wpwe_data[{$group_key}][{$row_index}][{$key}]";
        $id       = "wpwe_{$group_key}_{$row_index}_{$key}";
        $req_attr = $required ? ' required aria-required="true"' : '';
        $options  = $field['options'] ?? [];

        if ( $in_table ) : ?>
        <td class="wpwe-cell wpwe-cell--<?php echo esc_attr( $type ); ?>"
            data-field-key="<?php echo esc_attr( $key ); ?>">
        <?php else : ?>
        <div class="wpwe-field wpwe-field--<?php echo esc_attr( $type ); ?>"
             data-field-key="<?php echo esc_attr( $key ); ?>">
            <label for="<?php echo esc_attr( $id ); ?>" class="wpwe-label">
                <?php echo $label; ?>
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
                          <?php echo $req_attr; ?>></textarea>
                <?php break;

            case 'select': ?>
                <select id="<?php echo esc_attr( $id ); ?>"
                        name="<?php echo esc_attr( $name ); ?>"
                        class="wpwe-input wpwe-select"
                        <?php echo $req_attr; ?>>
                    <option value=""><?php esc_html_e( '— Select —', 'wp-waiver-engine' ); ?></option>
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
                           <?php echo $req_attr; ?>>
                    <?php if ( ! $in_table ) echo $label; ?>
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
                           <?php echo $required ? 'data-required="1"' : ''; ?>>
                    <button type="button" class="wpwe-clear-signature">
                        <?php esc_html_e( 'Clear', 'wp-waiver-engine' ); ?>
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
                       <?php echo $req_attr; ?>>
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
}
