<?php
namespace WPWE;

defined( 'ABSPATH' ) || exit;

/**
 * Handles the AJAX form submission:
 *  1. Validates nonce + data against schema (from custom DB table)
 *  2. Creates a row in wpwe_entries (no WP posts used)
 *  3. Triggers PDF generation
 *  4. Sends notification email (with PDFs attached)
 *  5. Returns JSON response
 */
class Entry_Handler {

    public function register(): void {
        add_action( 'wp_ajax_wpwe_submit_waiver',        [ $this, 'handle' ] );
        add_action( 'wp_ajax_nopriv_wpwe_submit_waiver', [ $this, 'handle' ] );
        add_action( 'wp_ajax_wpwe_preview_pdf',          [ $this, 'ajax_preview_pdf' ] );
        add_action( 'wp_ajax_nopriv_wpwe_preview_pdf',   [ $this, 'ajax_preview_pdf' ] );
        add_action( 'wp_ajax_wpwe_search_bookings',        [ $this, 'ajax_search_bookings' ] );
        add_action( 'wp_ajax_wpwe_get_booking',            [ $this, 'ajax_get_booking' ] );
        add_action( 'wp_ajax_nopriv_wpwe_get_booking',     [ $this, 'ajax_get_booking' ] );
    }

    // -----------------------------------------------------------------------
    // Main handler
    // -----------------------------------------------------------------------

    public function handle(): void {
        // ----- Security -----
        if ( ! isset( $_POST['wpwe_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wpwe_nonce'] ) ), 'wpwe_submit_waiver' ) ) {
            wp_send_json_error( [ 'message' => __( 'Security check failed.', 'wp-waiver-engine' ) ], 403 );
        }

        // ----- Anti-bot: honeypot -----
        // The field must exist AND be empty. If a bot fills it, reject silently.
        if ( ! isset( $_POST['wpwe_hp'] ) || '' !== $_POST['wpwe_hp'] ) {
            wp_send_json_error( [ 'message' => __( 'Security check failed.', 'wp-waiver-engine' ) ], 403 );
        }

        // ----- Anti-bot: timing check -----
        // Reject if form was submitted in under 3 seconds (bot speed) or token is invalid/expired (>2h).
        $ts    = isset( $_POST['wpwe_ts'] ) ? (int) sanitize_text_field( wp_unslash( $_POST['wpwe_ts'] ) ) : 0;
        $ts_sig = isset( $_POST['wpwe_ts_sig'] ) ? sanitize_text_field( wp_unslash( $_POST['wpwe_ts_sig'] ) ) : '';
        $expected_sig = hash_hmac( 'sha256', (string) $ts, wp_salt( 'nonce' ) );
        $age   = time() - $ts;
        if ( ! hash_equals( $expected_sig, $ts_sig ) || $age < 3 || $age > 7200 ) {
            wp_send_json_error( [ 'message' => __( 'Submission rejected. Please reload the page and try again.', 'wp-waiver-engine' ) ], 429 );
        }

        // ----- Anti-bot: IP rate limiting -----
        if ( Settings::is_rate_limit_enabled() ) {
            $ip  = $this->get_ip();
            $key = 'wpwe_rl_' . md5( $ip );
            $max    = Settings::rate_limit_max();
            $window = Settings::rate_limit_window() * MINUTE_IN_SECONDS;
            $hits   = (int) get_transient( $key );
            if ( $hits >= $max ) {
                wp_send_json_error( [ 'message' => __( 'Too many submissions. Please wait a few minutes and try again.', 'wp-waiver-engine' ) ], 429 );
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
            $captcha_tpl = Database::get_template( $captcha_template_id );
            if ( $captcha_tpl ) {
                $captcha_provider = Settings::captcha_provider();
                if ( $captcha_provider !== 'none' && ! empty( $captcha_tpl->captcha_enabled ) ) {
                    $captcha_token = isset( $_POST['wpwe_captcha_token'] )
                        ? sanitize_text_field( wp_unslash( $_POST['wpwe_captcha_token'] ) )
                        : '';
                    if ( ! $this->verify_captcha( $captcha_token, $captcha_provider ) ) {
                        wp_send_json_error( [ 'message' => __( 'CAPTCHA verification failed. Please reload the page and try again.', 'wp-waiver-engine' ) ], 403 );
                    }
                }
            }
        }

        $template_id = isset( $_POST['template_id'] ) ? absint( $_POST['template_id'] ) : 0;
        if ( ! $template_id ) {
            wp_send_json_error( [ 'message' => __( 'Invalid template.', 'wp-waiver-engine' ) ], 400 );
        }

        // Load template from custom table
        $template = Database::get_template( $template_id );
        if ( ! $template || ! $template->active ) {
            wp_send_json_error( [ 'message' => __( 'Template unavailable.', 'wp-waiver-engine' ) ], 400 );
        }

        // ----- Parse schema from template row -----
        $schema = json_decode( $template->field_schema, true );
        if ( ! is_array( $schema ) || empty( $schema['groups'] ) ) {
            wp_send_json_error( [ 'message' => __( 'Template configuration error.', 'wp-waiver-engine' ) ], 500 );
        }

        // ----- Parse + sanitize submission -----
        $raw_data = isset( $_POST['wpwe_data'] ) && is_array( $_POST['wpwe_data'] ) ? $_POST['wpwe_data'] : [];
        [ $data, $errors ] = $this->process_data( $raw_data, $schema );

        if ( ! empty( $errors ) ) {
            wp_send_json_error( [ 'message' => __( 'Please correct the errors below.', 'wp-waiver-engine' ), 'errors' => $errors ], 422 );
        }

        // ----- Optional Amelia booking linkage (only when integration enabled) -----
        $amelia_booking_id = 0;
        $booking_info      = null;
        if ( Settings::is_amelia_enabled() && Plan::is_feature_enabled( 'amelia_integration' ) ) {
            $amelia_booking_id = isset( $_POST['wpwe_booking_id'] ) ? absint( $_POST['wpwe_booking_id'] ) : 0;
            if ( $amelia_booking_id ) {
                $booking_info = Integration_Amelia::get_booking( $amelia_booking_id );
                if ( ! $booking_info ) {
                    $amelia_booking_id = 0; // booking not found – ignore silently
                }
            }
        }

        // ----- Insert entry into custom table -----
        $entry_id = Database::insert_entry(
            $template_id,
            wp_json_encode( $data ),
            $this->get_ip(),
            $amelia_booking_id
        );

        if ( ! $entry_id ) {
            wp_send_json_error( [ 'message' => __( 'Could not save submission.', 'wp-waiver-engine' ) ], 500 );
        }

        // ----- Generate PDFs -----
        $pdf_paths = [];
        $pdf_error = '';
        try {
            $generator = new PDF_Generator( $template );
            $pdf_paths = $generator->generate( $data, $entry_id, $booking_info );
        } catch ( \Throwable $e ) {
            $pdf_error = '[WPWE ' . gmdate( 'Y-m-d H:i:s' ) . '] PDF error entry=' . $entry_id
                . ' | ' . get_class( $e ) . ': ' . $e->getMessage()
                . ' | ' . $e->getFile() . ':' . $e->getLine();
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log( $pdf_error );
        }

        // ----- Send notification email -----
        $should_notify = Plan::is_feature_enabled( 'email_sending' )
            && Settings::is_admin_email_enabled()
            && ! empty( $template->send_admin_email );
        $email_sent    = $should_notify
            ? $this->send_notification( $template, $entry_id, $data, $pdf_paths, $booking_info )
            : false;

        // ----- Persist PDF paths + email status -----
        Database::update_entry_after_process( $entry_id, $pdf_paths, $email_sent, $pdf_error );

        // ----- Optional copy email to submitter (not saved) -----
           if ( Plan::is_feature_enabled( 'email_sending' )
               && Settings::is_user_email_enabled() && ! empty( $template->send_user_email )
             && ! empty( $_POST['wpwe_send_copy'] ) && ! empty( $_POST['wpwe_copy_email'] ) ) {
            $copy_to = sanitize_email( wp_unslash( $_POST['wpwe_copy_email'] ) );
            if ( is_email( $copy_to ) ) {
                $this->send_copy_email( $template, $copy_to, $pdf_paths, $booking_info );
            }
        }

        /**
         * Fires after a waiver entry has been fully processed.
         *
         * @param int    $entry_id    New entry ID in wpwe_entries.
         * @param object $template    Template row from wpwe_templates.
         * @param array  $data        Sanitised submission data.
         * @param array  $pdf_paths   Filesystem paths of generated PDFs.
         */
        do_action( 'wpwe_entry_created', $entry_id, $template, $data, $pdf_paths );

        wp_send_json_success( [
            'message'  => __( 'Thank you! Your waiver has been submitted.', 'wp-waiver-engine' ),
            'entry_id' => $entry_id,
        ] );
    }

    // -----------------------------------------------------------------------
    // PDF Preview (AJAX – no entry created, no email sent)
    // -----------------------------------------------------------------------

    public function ajax_preview_pdf(): void {
        if ( ! isset( $_POST['nonce'] ) ||
             ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'wpwe_preview_pdf' ) ) {
            wp_send_json_error( [ 'message' => __( 'Security check failed.', 'wp-waiver-engine' ) ], 403 );
        }

        $template_id = isset( $_POST['template_id'] ) ? absint( $_POST['template_id'] ) : 0;
        if ( ! $template_id ) {
            wp_send_json_error( [ 'message' => __( 'Invalid template.', 'wp-waiver-engine' ) ], 400 );
        }

        $template = Database::get_template( $template_id );
        if ( ! $template ) {
            wp_send_json_error( [ 'message' => __( 'Template not found.', 'wp-waiver-engine' ) ], 404 );
        }

        $schema = json_decode( $template->field_schema, true );
        if ( ! is_array( $schema ) || empty( $schema['groups'] ) ) {
            wp_send_json_error( [ 'message' => __( 'Template configuration error.', 'wp-waiver-engine' ) ], 500 );
        }

        // Sanitise form data; ignore validation errors for preview.
        $raw_data  = isset( $_POST['wpwe_data'] ) && is_array( $_POST['wpwe_data'] ) ? $_POST['wpwe_data'] : [];
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
            $generator = new PDF_Generator( $template );
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
            wp_send_json_error( [ 'message' => __( 'Access denied.', 'wp-waiver-engine' ) ], 403 );
        }

        if ( ! Settings::is_amelia_enabled() || ! Plan::is_feature_enabled( 'amelia_integration' ) ) {
            wp_send_json_success( [] ); // Amelia integration is disabled
        }

        if ( ! isset( $_POST['nonce'] ) ||
             ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'wpwe_search_bookings' ) ) {
            wp_send_json_error( [ 'message' => __( 'Security check failed.', 'wp-waiver-engine' ) ], 403 );
        }

        $template_id = isset( $_POST['template_id'] ) ? absint( $_POST['template_id'] ) : 0;
        $query       = isset( $_POST['query'] )       ? sanitize_text_field( wp_unslash( $_POST['query'] ) ) : '';

        if ( ! $template_id || strlen( $query ) < 2 ) {
            wp_send_json_success( [] );
        }

        $template = Database::get_template( $template_id );
        if ( ! $template ) {
            wp_send_json_success( [] );
        }

        $service_ids = json_decode( $template->amelia_service_ids ?? '', true ) ?: [];
        $service_ids = array_map( 'intval', $service_ids );

        $results = Integration_Amelia::search_bookings( $service_ids, $query );

        // Shape results for the frontend.
        $output = array_map( function ( $row ) {
            return [
                'id'          => (int) $row->appointment_id,
                'bookingDate' => wp_date( 'd/m/Y g:i a', strtotime( $row->bookingStart ) ),
                'service'     => $row->service_name,
                'customer'    => $row->firstName . ' ' . $row->lastName,
                'status'      => $row->appointment_status,
            ];
        }, $results );

        wp_send_json_success( $output );
    }

    /**
     * Fetch a single Amelia booking by appointment ID.
     *
     * Used when the waiver form URL contains a `?booking_id=N` parameter so the
     * booking can be pre-selected without the customer having to search for it.
     *
     * Nonce: reuses `wpwe_search_bookings` so no extra localised nonce is needed.
     */
    public function ajax_get_booking(): void {
        if ( ! Settings::is_amelia_enabled() || ! Plan::is_feature_enabled( 'amelia_integration' ) ) {
            wp_send_json_error( [ 'message' => __( 'Amelia integration not enabled.', 'wp-waiver-engine' ) ], 400 );
        }

        if ( ! isset( $_POST['nonce'] ) ||
             ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'wpwe_search_bookings' ) ) {
            wp_send_json_error( [ 'message' => __( 'Security check failed.', 'wp-waiver-engine' ) ], 403 );
        }

        $booking_id  = isset( $_POST['booking_id'] )  ? absint( $_POST['booking_id'] )  : 0;
        $template_id = isset( $_POST['template_id'] ) ? absint( $_POST['template_id'] ) : 0;
        $customer_email = isset( $_POST['customer_email'] )
            ? sanitize_email( wp_unslash( $_POST['customer_email'] ) )
            : '';

        if ( ! $booking_id || ! $template_id || ! is_email( $customer_email ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid request.', 'wp-waiver-engine' ) ], 400 );
        }

        $template = Database::get_template( $template_id );
        if ( ! $template ) {
            wp_send_json_error( [ 'message' => __( 'Template not found.', 'wp-waiver-engine' ) ], 404 );
        }

        $row = Integration_Amelia::get_booking( $booking_id );
        if ( ! $row ) {
            wp_send_json_error( [ 'message' => __( 'Booking not found.', 'wp-waiver-engine' ) ], 404 );
        }

        $service_ids = json_decode( $template->amelia_service_ids ?? '', true ) ?: [];
        $service_ids = array_map( 'intval', $service_ids );

        if ( $service_ids && ! in_array( (int) $row->service_id, $service_ids, true ) ) {
            wp_send_json_error( [ 'message' => __( 'Booking not found.', 'wp-waiver-engine' ) ], 404 );
        }

        $stored_email = isset( $row->customer_email ) ? sanitize_email( (string) $row->customer_email ) : '';
        if ( strtolower( $stored_email ) !== strtolower( $customer_email ) ) {
            wp_send_json_error( [ 'message' => __( 'Booking not found.', 'wp-waiver-engine' ) ], 404 );
        }

        wp_send_json_success( [
            'id'          => (int) $row->appointment_id,
            'bookingDate' => wp_date( 'd/m/Y g:i a', strtotime( $row->bookingStart ) ),
            'service'     => $row->service_name,
            'customer'    => $row->firstName . ' ' . $row->lastName,
        ] );
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
            $repeatable = ! empty( $group['repeatable'] );
            $fields     = $group['fields'] ?? [];
            $min_rows   = max( 1, (int) ( $group['min_rows'] ?? 1 ) );
            $max_rows   = (int) ( $group['max_rows'] ?? 20 );

            $raw_group = isset( $raw[ $gk ] ) && is_array( $raw[ $gk ] ) ? $raw[ $gk ] : [];

            if ( $repeatable ) {
                // Enforce max_rows to prevent abuse
                $raw_group = array_slice( $raw_group, 0, $max_rows );

                if ( count( $raw_group ) < $min_rows ) {
                    $errors[ $gk ] = sprintf(
                        /* translators: 1: group label, 2: min rows */
                        __( '%1$s requires at least %2$d row(s).', 'wp-waiver-engine' ),
                        $group['label'] ?? $gk,
                        $min_rows
                    );
                }

                $data[ $gk ] = [];
                foreach ( $raw_group as $idx => $raw_row ) {
                    $row_idx = (int) $idx;
                    [ $row_data, $row_errors ] = $this->process_row( $raw_row, $fields, $gk, $row_idx );
                    $data[ $gk ][] = $row_data;
                    $errors        = array_merge( $errors, $row_errors );
                }
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
                    __( '%s is required.', 'wp-waiver-engine' ),
                    $field['label'] ?? $fk
                );
            }

            $row[ $fk ] = $value;
        }

        return [ $row, $errors ];
    }

    private function sanitize_field( $value, string $type ): string {
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
        if ( empty( $pdf_paths ) ) {
            return;
        }

        // Per-row: combined PDF is the last entry; single: first (only) entry.
        $attachment_path = ( $template->output_mode === 'per_row' )
            ? end( $pdf_paths )
            : reset( $pdf_paths );

        if ( ! $attachment_path || ! file_exists( $attachment_path ) ) {
            return;
        }

        $subject = sprintf(
            /* translators: template title */
            __( 'Your copy: %s', 'wp-waiver-engine' ),
            $template->title
        );

        $body  = sprintf( __( 'Thank you for completing: %s', 'wp-waiver-engine' ), $template->title ) . "\n\n";

        if ( $booking_info ) {
            $body .= __( 'Booking details:', 'wp-waiver-engine' ) . "\n";
            $body .= sprintf( __( 'Booking ID: %d', 'wp-waiver-engine' ), (int) $booking_info->appointment_id ) . "\n";
            $body .= sprintf( __( 'Service: %s', 'wp-waiver-engine' ), $booking_info->service_name ) . "\n";
            $body .= sprintf( __( 'Booking date: %s', 'wp-waiver-engine' ), wp_date( 'F j, Y g:i a', strtotime( $booking_info->bookingStart ) ) ) . "\n";
            $body .= sprintf( __( 'Customer: %s', 'wp-waiver-engine' ), $booking_info->firstName . ' ' . $booking_info->lastName ) . "\n\n";
        }

        $body .= __( 'Please find your waiver PDF attached to this email.', 'wp-waiver-engine' ) . "\n";

        $headers = [ 'Content-Type: text/plain; charset=UTF-8' ];

        $sent = wp_mail( $to, $subject, $body, $headers, [ $attachment_path ] );
        if ( ! $sent ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log( '[WPWE] send_copy_email failed for: ' . $to . ' | last WP mail error: ' . print_r( $GLOBALS['phpmailer']->ErrorInfo ?? '', true ) );
        }
    }

    private function send_notification( object $template, int $entry_id, array $data, array $pdf_paths, ?object $booking_info = null ): bool {
        $to = $template->notification_email ?: get_option( 'admin_email' );
        if ( ! is_email( $to ) ) {
            return false;
        }

        $template_title = $template->title;
        $subject        = sprintf(
            /* translators: template title */
            __( 'New Waiver Submission: %s', 'wp-waiver-engine' ),
            $template_title
        );

        $entry_url = admin_url( 'admin.php?page=wpwe-entry&id=' . $entry_id );

        $body  = sprintf( __( 'A new waiver was submitted for: %s', 'wp-waiver-engine' ), $template_title ) . "\n\n";
        $body .= sprintf( __( 'Submission time: %s', 'wp-waiver-engine' ), wp_date( 'F j, Y g:i a' ) ) . "\n";
        $body .= sprintf( __( 'View entry: %s', 'wp-waiver-engine' ), $entry_url ) . "\n\n";

        if ( $booking_info ) {
            $body .= __( 'Booking details:', 'wp-waiver-engine' ) . "\n";
            $body .= sprintf( __( 'Booking ID: %d', 'wp-waiver-engine' ), (int) $booking_info->appointment_id ) . "\n";
            $body .= sprintf( __( 'Service: %s', 'wp-waiver-engine' ), $booking_info->service_name ) . "\n";
            $body .= sprintf( __( 'Booking date: %s', 'wp-waiver-engine' ), wp_date( 'F j, Y g:i a', strtotime( $booking_info->bookingStart ) ) ) . "\n";
            $body .= sprintf( __( 'Customer: %s', 'wp-waiver-engine' ), $booking_info->firstName . ' ' . $booking_info->lastName ) . "\n\n";
        }

        $body .= __( 'Submitted data:', 'wp-waiver-engine' ) . "\n";
        $body .= $this->flatten_data_text( $data );

        // Attach generated PDFs directly from filesystem paths
        $attachments = [];
        foreach ( $pdf_paths as $path ) {
            if ( $path && file_exists( $path ) ) {
                $attachments[] = $path;
            }
        }

        $headers = [ 'Content-Type: text/plain; charset=UTF-8' ];

        return wp_mail( $to, $subject, $body, $headers, $attachments );
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

        $secret = Settings::captcha_secret_key();
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
