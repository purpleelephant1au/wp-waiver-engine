<?php
namespace Pewave\WaiverEngine;

defined( 'ABSPATH' ) || exit;

/**
 * Handles the AJAX form submission:
 *  1. Validates nonce + data against schema (from custom DB table)
 *  2. Creates a row in pewave_entries (no WP posts used)
 *  3. Triggers PDF generation
 *  4. Sends notification email (with PDFs attached)
 *  5. Returns JSON response
 */
class PeWave_Entry_Handler {

    public function register(): void {
        add_action( 'wp_ajax_pewave_submit_waiver',        [ $this, 'handle' ] );
        add_action( 'wp_ajax_nopriv_pewave_submit_waiver', [ $this, 'handle' ] );
        add_action( 'wp_ajax_pewave_preview_pdf',          [ $this, 'ajax_preview_pdf' ] );
        add_action( 'wp_ajax_nopriv_pewave_preview_pdf',   [ $this, 'ajax_preview_pdf' ] );
        add_action( 'wp_ajax_pewave_search_bookings',        [ $this, 'ajax_search_bookings' ] );
        add_action( 'wp_ajax_pewave_get_booking',            [ $this, 'ajax_get_booking' ] );
        add_action( 'wp_ajax_nopriv_pewave_get_booking',     [ $this, 'ajax_get_booking' ] );
    }

    // -----------------------------------------------------------------------
    // Main handler
    // -----------------------------------------------------------------------

    public function handle(): void {
        // ----- Security -----
        if ( ! isset( $_POST['pewave_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['pewave_nonce'] ) ), 'pewave_submit_waiver' ) ) {
            wp_send_json_error( [ 'message' => __( 'Security check failed.', 'waiver-engine' ) ], 403 );
        }

        // ----- Anti-bot: honeypot -----
        // The field must exist AND be empty. If a bot fills it, reject silently.
        if ( ! isset( $_POST['pewave_hp'] ) || '' !== $_POST['pewave_hp'] ) {
            wp_send_json_error( [ 'message' => __( 'Security check failed.', 'waiver-engine' ) ], 403 );
        }

        // ----- Anti-bot: timing check -----
        // Reject if form was submitted in under 3 seconds (bot speed) or token is invalid/expired (>2h).
        $ts    = isset( $_POST['pewave_ts'] ) ? (int) sanitize_text_field( wp_unslash( $_POST['pewave_ts'] ) ) : 0;
        $ts_sig = isset( $_POST['pewave_ts_sig'] ) ? sanitize_text_field( wp_unslash( $_POST['pewave_ts_sig'] ) ) : '';
        $expected_sig = hash_hmac( 'sha256', (string) $ts, wp_salt( 'nonce' ) );
        $age   = time() - $ts;
        if ( ! hash_equals( $expected_sig, $ts_sig ) || $age < 3 || $age > 7200 ) {
            wp_send_json_error( [ 'message' => __( 'Submission rejected. Please reload the page and try again.', 'waiver-engine' ) ], 429 );
        }

        // ----- Anti-bot: IP rate limiting -----
        if ( PeWave_Settings::is_rate_limit_enabled() ) {
            $ip  = $this->get_ip();
            $key = 'pewave_rl_' . md5( $ip );
            $max    = PeWave_Settings::rate_limit_max();
                $window = PeWave_Settings::rate_limit_window() * \MINUTE_IN_SECONDS;
            $hits   = (int) get_transient( $key );
            if ( $hits >= $max ) {
                wp_send_json_error( [ 'message' => __( 'Too many submissions. Please wait a few minutes and try again.', 'waiver-engine' ) ], 429 );
            }
            // Increment counter; set expiry only on first hit so the window is sliding.
            if ( $hits === 0 ) {
                set_transient( $key, 1, $window );
            } else {
                // get_transient doesn't return the remaining TTL, so we just update the value.
                // The expiry was set on first hit and will naturally expire after the window.
                set_transient( $key, $hits + 1, $window );
            }
        }

        // ----- Anti-bot: CAPTCHA verification -----
        // Must load template first so we can check per-template captcha_enabled flag.
        // We do a lightweight load here and re-use it below.
        $captcha_template_id = isset( $_POST['template_id'] ) ? absint( $_POST['template_id'] ) : 0;
        if ( $captcha_template_id ) {
            $captcha_tpl = PeWave_Database::get_template( $captcha_template_id );
            if ( $captcha_tpl ) {
                $captcha_provider = PeWave_Settings::captcha_provider();
                if ( $captcha_provider !== 'none' && ! empty( $captcha_tpl->captcha_enabled ) ) {
                    $captcha_token = isset( $_POST['pewave_captcha_token'] )
                        ? sanitize_text_field( wp_unslash( $_POST['pewave_captcha_token'] ) )
                        : '';
                    if ( ! $this->verify_captcha( $captcha_token, $captcha_provider ) ) {
                        wp_send_json_error( [ 'message' => __( 'CAPTCHA verification failed. Please reload the page and try again.', 'waiver-engine' ) ], 403 );
                    }
                }
            }
        }

        $template_id = isset( $_POST['template_id'] ) ? absint( $_POST['template_id'] ) : 0;
        if ( ! $template_id ) {
            wp_send_json_error( [ 'message' => __( 'Invalid template.', 'waiver-engine' ) ], 400 );
        }

        // Load template from custom table
        $template = PeWave_Database::get_template( $template_id );
        if ( ! $template || ! $template->active ) {
            wp_send_json_error( [ 'message' => __( 'Template unavailable.', 'waiver-engine' ) ], 400 );
        }

        // ----- Parse schema from template row -----
        $schema = json_decode( $template->field_schema, true );
        if ( ! is_array( $schema ) || empty( $schema['groups'] ) ) {
            wp_send_json_error( [ 'message' => __( 'Template configuration error.', 'waiver-engine' ) ], 500 );
        }

        // ----- Parse + sanitize submission -----
        // phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- pewave_data is unslashed then validated per-field in process_data().
        $raw_data = isset( $_POST['pewave_data'] ) && is_array( $_POST['pewave_data'] )
            ? wp_unslash( $_POST['pewave_data'] )
            : [];
        // phpcs:enable
        [ $data, $errors ] = $this->process_data( $raw_data, $schema );

        if ( ! empty( $errors ) ) {
            wp_send_json_error( [ 'message' => __( 'Please correct the errors below.', 'waiver-engine' ), 'errors' => $errors ], 422 );
        }

        // ----- Optional Amelia booking linkage (only when integration enabled) -----
        $amelia_booking_id = 0;
        $booking_info      = null;
        if ( class_exists( PeWave_Premium_Bridge::class ) ) {
            [ $amelia_booking_id, $booking_info ] = PeWave_Premium_Bridge::resolve_booking_submission_from_request();
        }

        // ----- Insert entry into custom table -----
        $entry_id = PeWave_Database::insert_entry(
            $template_id,
            wp_json_encode( $data ),
            $this->get_ip(),
            $amelia_booking_id
        );

        if ( ! $entry_id ) {
            wp_send_json_error( [ 'message' => __( 'Could not save submission.', 'waiver-engine' ) ], 500 );
        }

        // ----- Generate PDFs -----
        $pdf_paths = [];
        $pdf_error = '';
        try {
            $generator = new PeWave_PDF_Generator( $template );
            $pdf_paths = $generator->generate( $data, $entry_id, $booking_info );
        } catch ( \Throwable $e ) {
            $pdf_error = '[PEWAVE ' . gmdate( 'Y-m-d H:i:s' ) . '] PDF error entry=' . $entry_id
                . ' | ' . get_class( $e ) . ': ' . $e->getMessage()
                . ' | ' . $e->getFile() . ':' . $e->getLine();
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log( $pdf_error );
        }

        // ----- Send notification email -----
        $should_notify = class_exists( PeWave_Premium_Bridge::class )
            && PeWave_Premium_Bridge::should_send_admin_notification( $template );
        $email_sent    = $should_notify
            ? $this->send_notification( $template, $entry_id, $data, $pdf_paths, $booking_info )
            : false;

        // ----- Persist PDF paths + email status -----
        PeWave_Database::update_entry_after_process( $entry_id, $pdf_paths, $email_sent, $pdf_error );

        // ----- Optional copy email to submitter (not saved) -----
        if ( class_exists( PeWave_Premium_Bridge::class ) && PeWave_Premium_Bridge::should_offer_submitter_copy( $template ) ) {
            $copy_to = ! empty( $_POST['pewave_copy_email'] ) ? sanitize_email( wp_unslash( $_POST['pewave_copy_email'] ) ) : '';
            if ( ! empty( $_POST['pewave_send_copy'] ) && is_email( $copy_to ) ) {
                $this->send_copy_email( $template, $copy_to, $pdf_paths, $booking_info );
            }
        }

        /**
         * Fires after a waiver entry has been fully processed.
         *
         * @param int    $entry_id    New entry ID in pewave_entries.
         * @param object $template    Template row from pewave_templates.
         * @param array  $data        Sanitised submission data.
         * @param array  $pdf_paths   Filesystem paths of generated PDFs.
         */
        do_action( 'pewave_entry_created', $entry_id, $template, $data, $pdf_paths );

        wp_send_json_success( [
            'message'  => __( 'Thank you! Your waiver has been submitted.', 'waiver-engine' ),
            'entry_id' => $entry_id,
        ] );
    }

    // -----------------------------------------------------------------------
    // PDF Preview (AJAX – no entry created, no email sent)
    // -----------------------------------------------------------------------

    public function ajax_preview_pdf(): void {
           if ( ! isset( $_POST['nonce'] ) ||
               ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'pewave_preview_pdf' ) ) {
            wp_send_json_error( [ 'message' => __( 'Security check failed.', 'waiver-engine' ) ], 403 );
        }

        $template_id = isset( $_POST['template_id'] ) ? absint( $_POST['template_id'] ) : 0;
        if ( ! $template_id ) {
            wp_send_json_error( [ 'message' => __( 'Invalid template.', 'waiver-engine' ) ], 400 );
        }

        $template = PeWave_Database::get_template( $template_id );
        if ( ! $template ) {
            wp_send_json_error( [ 'message' => __( 'Template not found.', 'waiver-engine' ) ], 404 );
        }

        $schema = json_decode( $template->field_schema, true );
        if ( ! is_array( $schema ) || empty( $schema['groups'] ) ) {
            wp_send_json_error( [ 'message' => __( 'Template configuration error.', 'waiver-engine' ) ], 500 );
        }

        // Sanitise form data; ignore validation errors for preview.
        // phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- pewave_data is unslashed then validated per-field in process_data().
        $raw_data  = isset( $_POST['pewave_data'] ) && is_array( $_POST['pewave_data'] )
            ? wp_unslash( $_POST['pewave_data'] )
            : [];
        // phpcs:enable
        $processed = $this->process_data( $raw_data, $schema );
        $data      = $processed[0];

        // Per-row preview: isolate only the requested row.
        $preview_mode = isset( $_POST['preview_mode'] ) ? sanitize_key( $_POST['preview_mode'] ) : 'full';
        if ( $preview_mode === 'row' ) {
            $group_key = isset( $_POST['group_key'] ) ? sanitize_key( $_POST['group_key'] ) : '';
            $row_index = isset( $_POST['row_index'] ) ? absint( $_POST['row_index'] ) : 0;
            if ( $group_key && isset( $data[ $group_key ] ) ) {
                $rows = $data[ $group_key ];
                $row  = $rows[ $row_index ] ?? ( $rows[0] ?? [] );
                $data[ $group_key ] = [ $row ];
            }
        }

        try {
            $generator = new PeWave_PDF_Generator( $template );
            $bytes     = $generator->preview( $data );
            wp_send_json_success( [ 'pdf' => base64_encode( $bytes ) ] );
        } catch ( \Throwable $e ) {
            wp_send_json_error( [ 'message' => $e->getMessage() ] );
        }
    }

    // -----------------------------------------------------------------------
    // AJAX: booking search
    // -----------------------------------------------------------------------

    public function ajax_search_bookings(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Access denied.', 'waiver-engine' ) ], 403 );
        }

        if ( class_exists( PeWave_Premium_Bridge::class ) ) {
            PeWave_Premium_Bridge::ajax_search_bookings();
            return;
        }

        wp_send_json_success( [] );
    }

    /**
     * Fetch a single Amelia booking by appointment ID.
     *
     * Used when the waiver form URL contains a `?booking_id=N` parameter so the
     * booking can be pre-selected without the customer having to search for it.
     *
    * Nonce: reuses `pewave_search_bookings` so no extra localised nonce is needed.
     */
    public function ajax_get_booking(): void {
        if ( class_exists( PeWave_Premium_Bridge::class ) ) {
            PeWave_Premium_Bridge::ajax_get_booking();
            return;
        }

        wp_send_json_error( [ 'message' => __( 'Booking verification is unavailable.', 'waiver-engine' ) ], 400 );
    }

    // -----------------------------------------------------------------------
    // Data processing and validation
    // -----------------------------------------------------------------------

    /**
     * Recursively sanitises the raw POST data against the schema.
     * Returns [ sanitised_data_array, errors_array ].
     */
    private function process_data( array $raw, array $schema ): array {
        $data   = [];
        $errors = [];

        foreach ( $schema['groups'] as $group ) {
            $gk         = sanitize_key( $group['key'] ?? '' );
            $fields     = $group['fields'] ?? [];
            $repeatable = class_exists( PeWave_Premium_Bridge::class ) && PeWave_Premium_Bridge::is_repeatable_group( $group );
            $raw_group  = isset( $raw[ $gk ] ) && is_array( $raw[ $gk ] ) ? $raw[ $gk ] : [];

            if ( $repeatable ) {
                [ $data[ $gk ], $group_errors ] = PeWave_Premium_Bridge::process_repeatable_group(
                    $raw_group,
                    $group,
                    $fields,
                    $gk,
                    [ $this, 'process_row_for_premium' ]
                );
                $errors = array_merge( $errors, $group_errors );
            } else {
                // Non-repeatable: treat [0] as the single row
                $raw_row = isset( $raw_group[0] ) && is_array( $raw_group[0] ) ? $raw_group[0] : $raw_group;
                [ $row_data, $row_errors ] = $this->process_row( $raw_row, $fields, $gk, 0 );
                $data[ $gk ]  = [ $row_data ]; // stored as array for consistent structure
                $errors       = array_merge( $errors, $row_errors );
            }
        }

        return [ $data, $errors ];
    }

    /**
     * Sanitise and validate a single row of field values.
     */
    private function process_row( array $raw_row, array $fields, string $gk, int $idx ): array {
        $row    = [];
        $errors = [];

        foreach ( $fields as $field ) {
            $fk       = sanitize_key( $field['key'] ?? '' );
            $type     = sanitize_key( $field['type'] ?? 'text' );
            $required = ! empty( $field['required'] );
            $raw_val  = $raw_row[ $fk ] ?? '';

            $value = $this->sanitize_field( $raw_val, $type );

            if ( $required && $value === '' ) {
                $errors[ "{$gk}[{$idx}][{$fk}]" ] = sprintf(
                    /* translators: field label */
                    __( '%s is required.', 'waiver-engine' ),
                    $field['label'] ?? $fk
                );
            }

            $row[ $fk ] = $value;
        }

        return [ $row, $errors ];
    }

    public function process_row_for_premium( array $raw_row, array $fields, string $gk, int $idx ): array {
        return $this->process_row( $raw_row, $fields, $gk, $idx );
    }

    private function sanitize_field( mixed $value, string $type ): string {
        if ( ! is_scalar( $value ) ) {
            return '';
        }
        $value = (string) $value;

        return match ( $type ) {
            'email'     => sanitize_email( $value ),
            'number'    => is_numeric( $value ) ? $value : '',
            'date'      => $this->sanitize_date( $value ),
            'checkbox'  => $value === '1' ? '1' : '',
            'signature' => $this->sanitize_signature( $value ),
            'textarea'  => sanitize_textarea_field( wp_unslash( $value ) ),
            default     => sanitize_text_field( wp_unslash( $value ) ),
        };
    }

    private function sanitize_date( string $value ): string {
        // Accept YYYY-MM-DD only
        if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
            return $value;
        }
        return '';
    }

    private function sanitize_signature( string $value ): string {
        // Must be a valid data URI PNG/JPEG
        if ( preg_match( '/^data:image\/(png|jpeg|jpg);base64,[A-Za-z0-9+\/=]+$/', $value ) ) {
            return $value;
        }
        return '';
    }

    // -----------------------------------------------------------------------
    // Email notification
    // -----------------------------------------------------------------------

    /**
     * Send the PDF copy to the submitter's requested address.
     * For per_row mode the combined PDF (last path) is used;
     * for single mode the only PDF is used.
     */
    private function send_copy_email( object $template, string $to, array $pdf_paths, ?object $booking_info = null ): void {
        if ( class_exists( PeWave_Premium_Bridge::class ) ) {
            PeWave_Premium_Bridge::send_copy_email( $template, $to, $pdf_paths, $booking_info );
        }
    }

    private function send_notification( object $template, int $entry_id, array $data, array $pdf_paths, ?object $booking_info = null ): bool {
        return class_exists( PeWave_Premium_Bridge::class )
            ? PeWave_Premium_Bridge::send_notification( $template, $entry_id, $data, $pdf_paths, $booking_info )
            : false;
    }

    private function build_submission_html_tables( object $template, array $data ): string {
        if ( class_exists( PeWave_Premium_Bridge::class ) ) {
            return PeWave_Premium_Bridge::build_submission_html_tables( $template, $data );
        }

        return '<pre style="white-space:pre-wrap;">' . esc_html( $this->flatten_data_text( $data ) ) . '</pre>';
    }

    private function normalize_group_rows_for_output( mixed $group_data ): array {
        if ( ! is_array( $group_data ) ) {
            return [];
        }

        if ( [] === $group_data ) {
            return [];
        }

        $first = reset( $group_data );
        if ( is_array( $first ) ) {
            return $group_data;
        }

        return [ $group_data ];
    }

    private function format_email_cell_value( mixed $value ): string {
        if ( is_array( $value ) ) {
            $value = wp_json_encode( $value ) ?: '';
        }

        $display = (string) $value;
        if ( '' === $display ) {
            return '&mdash;';
        }

        if ( str_starts_with( $display, 'data:image/' ) ) {
            return '<em>' . esc_html__( 'Signature captured (see PDF attachment)', 'waiver-engine' ) . '</em>';
        }

        return nl2br( esc_html( $display ) );
    }

    private function flatten_data_text( array $data, string $prefix = '' ): string {
        $lines = '';
        foreach ( $data as $key => $value ) {
            $label = $prefix ? $prefix . '.' . $key : (string) $key;
            if ( is_array( $value ) ) {
                if ( isset( $value[0] ) && is_array( $value[0] ) ) {
                    foreach ( $value as $i => $row ) {
                        $lines .= $this->flatten_data_text( $row, $label . '[' . $i . ']' );
                    }
                } else {
                    $lines .= $this->flatten_data_text( $value, $label );
                }
            } else {
                $display = (string) $value;
                if ( str_starts_with( $display, 'data:image/' ) ) {
                    $display = '[signature image – see PDF]';
                }
                $lines .= $label . ': ' . $display . "\n";
            }
        }
        return $lines;
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function get_ip(): string {
        // Basic IP retrieval; deliberately not trusting X-Forwarded-For without config
        return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
    }

    /**
     * Verify a CAPTCHA token server-side.
     *
     * Returns true on success or when the network request fails (fail-open to avoid
     * false positives on connectivity issues). Returns false only on a confirmed
     * invalid/missing token.
     *
     * @param string $token    Token from the frontend.
     * @param string $provider 'recaptcha_v3' or 'hcaptcha'.
     */
    private function verify_captcha( string $token, string $provider ): bool {
        if ( '' === $token ) {
            return false;
        }

        $secret = PeWave_Settings::captcha_secret_key();
        if ( '' === $secret ) {
            // No secret key configured – can't verify, fail open
            return true;
        }

        if ( $provider === 'recaptcha_v3' ) {
            $response = wp_remote_post(
                'https://www.google.com/recaptcha/api/siteverify',
                [
                    'timeout' => 10,
                    'body'    => [
                        'secret'   => $secret,
                        'response' => $token,
                        'remoteip' => $this->get_ip(),
                    ],
                ]
            );
        } else { // hcaptcha
            $response = wp_remote_post(
                'https://api.hcaptcha.com/siteverify',
                [
                    'timeout' => 10,
                    'body'    => [
                        'secret'   => $secret,
                        'response' => $token,
                        'remoteip' => $this->get_ip(),
                    ],
                ]
            );
        }

        // On network failure, fail open to avoid blocking legitimate users
        if ( is_wp_error( $response ) ) {
            return true;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $body ) || empty( $body['success'] ) ) {
            return false;
        }

        // reCAPTCHA v3 also returns a score; require >= 0.5
        if ( $provider === 'recaptcha_v3' && isset( $body['score'] ) ) {
            return ( (float) $body['score'] ) >= 0.5;
        }

        return true;
    }
}

