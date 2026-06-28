<?php
namespace Pewave\WaiverEngine;

defined( 'ABSPATH' ) || exit;

use setasign\Fpdi\Fpdi;

class PeWave_Premium_Bridge {

    public static function is_premium_editor_enabled(): bool {
        return function_exists( 'pewave_fs' ) && pewave_fs() && pewave_fs()->is__premium_only();
    }

    public static function can_use_repeatable_mapping(): bool {
        return function_exists( 'pewave_fs' ) && pewave_fs() && pewave_fs()->can_use_premium_code__premium_only();
    }

    public static function sanitize_template_output_mode( string $requested_mode ): string {
        if ( ! in_array( $requested_mode, [ 'single', 'per_row' ], true ) ) {
            return 'single';
        }

        if ( ! PeWave_Plan::is_feature_enabled( 'repeating_rows' ) ) {
            return 'single';
        }

        return $requested_mode;
    }

    public static function build_repeatable_map_from_post(): array {
        if ( ! self::can_use_repeatable_mapping() ) {
            return [];
        }

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce checked by the template save handlers before this helper is called.
        return isset( $_POST['pewave_map_repeatable'] ) && is_array( $_POST['pewave_map_repeatable'] )
            ? array_map( 'absint', wp_unslash( $_POST['pewave_map_repeatable'] ) )
            : [];
        // phpcs:enable
    }

    public static function build_template_premium_data_from_post(): array {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce checked by the template save handlers before this helper is called.
        $output_mode = isset( $_POST['pewave_output_mode'] )
            ? sanitize_text_field( wp_unslash( $_POST['pewave_output_mode'] ) )
            : 'single';
        $output_mode = self::sanitize_template_output_mode( $output_mode );

        $raw_amelia_ids = isset( $_POST['pewave_amelia_service_ids'] ) && is_array( $_POST['pewave_amelia_service_ids'] )
            ? array_map( 'absint', $_POST['pewave_amelia_service_ids'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
            : [];
        if ( ! PeWave_Plan::is_feature_enabled( 'amelia_integration' ) ) {
            $raw_amelia_ids = [];
        }

        $send_admin_email = ! empty( $_POST['pewave_send_admin_email'] ) ? 1 : 0;
        $send_user_email = ! empty( $_POST['pewave_send_user_email'] ) ? 1 : 0;
        if ( ! PeWave_Plan::is_feature_enabled( 'email_sending' ) ) {
            $send_admin_email = 0;
            $send_user_email = 0;
        }

        return [
            'output_mode'        => $output_mode,
            'output_group_key'   => isset( $_POST['pewave_output_group_key'] ) ? sanitize_key( wp_unslash( $_POST['pewave_output_group_key'] ) ) : '',
            'notification_email' => isset( $_POST['pewave_notification_email'] ) ? sanitize_email( wp_unslash( $_POST['pewave_notification_email'] ) ) : '',
            'send_admin_email'   => $send_admin_email,
            'send_user_email'    => $send_user_email,
            'amelia_service_ids' => array_values( $raw_amelia_ids ),
        ];
        // phpcs:enable
    }

    public static function build_form_premium_context( object $template ): array {
        $amelia_services = [];
        if ( PeWave_Settings::is_amelia_enabled() && PeWave_Plan::is_feature_enabled( 'amelia_integration' ) ) {
            $amelia_services = json_decode( $template->amelia_service_ids ?? '', true ) ?: [];
            $amelia_services = array_map( 'intval', $amelia_services );
        }

        return [
            'amelia_services'  => $amelia_services,
            'allow_copy_email' => self::should_offer_submitter_copy( $template ),
            'output_mode'      => self::sanitize_template_output_mode( (string) ( $template->output_mode ?? 'single' ) ),
        ];
    }

    public static function render_per_row_output_mode_option( string $output_mode ): void {
        ?>
        <option value="per_row" <?php selected( $output_mode, 'per_row' ); ?>>
            <?php esc_html_e( 'One PDF per row (repeatable group)', 'waiver-engine' ); ?>
        </option>
        <?php
    }

    public static function render_output_group_key_control( string $output_mode, string $group_key ): void {
        ?>
        <div id="pewave_group_key_wrap" style="margin-top:8px;<?php echo $output_mode !== 'per_row' ? 'display:none;' : ''; ?>">
            <label for="pewave_output_group_key"><?php esc_html_e( 'Repeatable Group Key:', 'waiver-engine' ); ?></label>
            <input type="text" id="pewave_output_group_key" name="pewave_output_group_key"
                   value="<?php echo esc_attr( $group_key ); ?>" class="regular-text"
                   placeholder="e.g. participants">
        </div>
        <?php
    }

    public static function render_template_editor_premium_rows( ?object $tpl, string $notify, bool $send_admin_email, bool $send_user_email ): void {
        ?>
        <tr>
            <th><label for="pewave_notification_email"><?php esc_html_e( 'Notification Email', 'waiver-engine' ); ?></label></th>
            <td>
                <input type="email" id="pewave_notification_email" name="pewave_notification_email"
                       value="<?php echo esc_attr( $notify ); ?>" class="regular-text"
                       placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>">
                <p class="description"><?php esc_html_e( 'Leave blank to use the site admin email.', 'waiver-engine' ); ?></p>
            </td>
        </tr>
        <?php if ( PeWave_Settings::is_admin_email_enabled() ) : ?>
        <tr>
            <th><label for="pewave_send_admin_email"><?php esc_html_e( 'Admin Notification', 'waiver-engine' ); ?></label></th>
            <td>
                <input type="checkbox" id="pewave_send_admin_email" name="pewave_send_admin_email" value="1"
                       <?php checked( $send_admin_email ); ?>>
                <label for="pewave_send_admin_email">
                    <?php esc_html_e( 'Send admin notification email on submission', 'waiver-engine' ); ?>
                </label>
                <p class="description"><?php esc_html_e( 'If unchecked, no email is sent to the notification address when this template is submitted.', 'waiver-engine' ); ?></p>
            </td>
        </tr>
        <?php endif; ?>
        <?php if ( PeWave_Settings::is_user_email_enabled() ) : ?>
        <tr>
            <th><label for="pewave_send_user_email"><?php esc_html_e( 'Submitter Copy', 'waiver-engine' ); ?></label></th>
            <td>
                <input type="checkbox" id="pewave_send_user_email" name="pewave_send_user_email" value="1"
                       <?php checked( $send_user_email ); ?>>
                <label for="pewave_send_user_email">
                    <?php esc_html_e( 'Allow submitters to request an email copy of their PDF', 'waiver-engine' ); ?>
                </label>
                <p class="description"><?php esc_html_e( 'If unchecked, the email copy opt-in is hidden for this template.', 'waiver-engine' ); ?></p>
            </td>
        </tr>
        <?php endif; ?>
        <?php if ( PeWave_Settings::is_amelia_enabled() ) : ?>
        <tr>
            <th><?php esc_html_e( 'Linked Amelia Services', 'waiver-engine' ); ?></th>
            <td>
                <?php
                $saved_amelia_ids = json_decode( (string) ( $tpl->amelia_service_ids ?? '' ), true ) ?: [];
                $saved_amelia_ids = array_map( 'intval', $saved_amelia_ids );
                $amelia_svc = PeWave_Integration_Amelia::get_services();
                if ( empty( $amelia_svc ) ) : ?>
                    <p class="description"><?php esc_html_e( 'No Amelia services found. Activate the Amelia plugin and create services first.', 'waiver-engine' ); ?></p>
                <?php else : ?>
                    <fieldset>
                        <?php foreach ( $amelia_svc as $svc ) :
                            $svc_id = (int) $svc['id'];
                            $checked = in_array( $svc_id, $saved_amelia_ids, true );
                            ?>
                            <label style="display:block;margin-bottom:4px;">
                                <input type="checkbox"
                                       name="pewave_amelia_service_ids[]"
                                       value="<?php echo esc_attr( $svc_id ); ?>"
                                       <?php checked( $checked ); ?>>
                                <?php echo esc_html( $svc['name'] ); ?>
                            </label>
                        <?php endforeach; ?>
                    </fieldset>
                    <p class="description"><?php esc_html_e( 'When services are selected, a booking-search widget appears on the frontend form so submitters can link their waiver to an existing booking.', 'waiver-engine' ); ?></p>
                <?php endif; ?>
            </td>
        </tr>
        <?php endif;
    }

    public static function render_template_meta_box_premium_rows( \WP_Post $post, string $notify_email ): void {
        $send_admin_raw   = get_post_meta( $post->ID, 'send_admin_email', true );
        $send_user_raw    = get_post_meta( $post->ID, 'send_user_email', true );
        $send_admin_email = ( $send_admin_raw === '' ) ? true : (bool) $send_admin_raw;
        $send_user_email  = ( $send_user_raw === '' ) ? true : (bool) $send_user_raw;

        ?>
        <tr>
            <th><label for="pewave_notification_email"><?php esc_html_e( 'Notification Email', 'waiver-engine' ); ?></label></th>
            <td>
                <input type="email" id="pewave_notification_email" name="pewave_notification_email"
                       value="<?php echo esc_attr( $notify_email ); ?>" class="regular-text"
                       placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>">
                <p class="description"><?php esc_html_e( 'Leave blank to use the site admin email. PDF(s) will be attached to the notification.', 'waiver-engine' ); ?></p>
            </td>
        </tr>
        <tr>
            <th><?php esc_html_e( 'Linked Amelia Services', 'waiver-engine' ); ?></th>
            <td>
                <?php
                $saved_ids = json_decode( (string) get_post_meta( $post->ID, 'amelia_service_ids', true ), true ) ?: [];
                $saved_ids = array_map( 'intval', $saved_ids );
                $amelia_svc = PeWave_Integration_Amelia::get_services();
                if ( empty( $amelia_svc ) ) : ?>
                    <p class="description"><?php esc_html_e( 'No Amelia services found. Activate the Amelia plugin and create services first.', 'waiver-engine' ); ?></p>
                <?php else : ?>
                    <fieldset>
                        <?php foreach ( $amelia_svc as $svc ) :
                            $svc_id = (int) $svc['id'];
                            $checked = in_array( $svc_id, $saved_ids, true );
                            ?>
                            <label style="display:block;margin-bottom:4px;">
                                <input type="checkbox"
                                       name="pewave_amelia_service_ids[]"
                                       value="<?php echo esc_attr( $svc_id ); ?>"
                                       <?php checked( $checked ); ?>>
                                <?php echo esc_html( $svc['name'] ); ?>
                            </label>
                        <?php endforeach; ?>
                    </fieldset>
                    <p class="description"><?php esc_html_e( 'When services are selected, a booking-search widget appears on the frontend form so submitters can link their waiver to an existing booking.', 'waiver-engine' ); ?></p>
                <?php endif; ?>
            </td>
        </tr>
        <?php if ( PeWave_Settings::is_admin_email_enabled() ) : ?>
        <tr>
            <th><label for="pewave_send_admin_email"><?php esc_html_e( 'Admin Notification', 'waiver-engine' ); ?></label></th>
            <td>
                <input type="checkbox" id="pewave_send_admin_email" name="pewave_send_admin_email" value="1"
                       <?php checked( $send_admin_email ); ?>>
                <label for="pewave_send_admin_email">
                    <?php esc_html_e( 'Send completed waiver PDF to admin email', 'waiver-engine' ); ?>
                </label>
            </td>
        </tr>
        <?php endif; ?>

        <?php if ( PeWave_Settings::is_user_email_enabled() ) : ?>
        <tr>
            <th><label for="pewave_send_user_email"><?php esc_html_e( 'Submitter Copy', 'waiver-engine' ); ?></label></th>
            <td>
                <input type="checkbox" id="pewave_send_user_email" name="pewave_send_user_email" value="1"
                       <?php checked( $send_user_email ); ?>>
                <label for="pewave_send_user_email">
                    <?php esc_html_e( 'Allow submitter to request an emailed copy', 'waiver-engine' ); ?>
                </label>
            </td>
        </tr>
        <?php endif; ?>
        <?php
    }

    public static function render_repeatable_header_cell(): void {
        ?>
        <th style="width:34px" title="<?php esc_attr_e( 'Repeatable group', 'waiver-engine' ); ?>">Rpt</th>
        <?php
    }

    public static function render_repeatable_row_cell( bool $repeatable ): void {
        ?>
        <td style="text-align:center">
            <input type="hidden" class="pewave-rep-hidden" name="pewave_map_repeatable[]" value="<?php echo $repeatable ? '1' : '0'; ?>">
            <input type="checkbox" class="pewave-map-repeatable" <?php checked( $repeatable ); ?>>
        </td>
        <?php
    }

    public static function resolve_booking_submission_from_request(): array {
        if ( ! PeWave_Settings::is_amelia_enabled() || ! PeWave_Plan::is_feature_enabled( 'amelia_integration' ) ) {
            return [ 0, null ];
        }

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce is verified in the calling AJAX handler before this helper runs.
        $amelia_booking_id = isset( $_POST['pewave_booking_id'] ) ? absint( $_POST['pewave_booking_id'] ) : 0;
        $booking_info = null;

        if ( $amelia_booking_id ) {
            $booking_info = PeWave_Integration_Amelia::get_booking( $amelia_booking_id );
            if ( ! $booking_info ) {
                $amelia_booking_id = 0;
            }
        }

        // phpcs:enable
        return [ $amelia_booking_id, $booking_info ];
    }

    public static function should_send_admin_notification( object $template ): bool {
        return PeWave_Plan::is_feature_enabled( 'email_sending' )
            && PeWave_Settings::is_admin_email_enabled()
            && ! empty( $template->send_admin_email );
    }

    public static function should_offer_submitter_copy( object $template ): bool {
        $template_allows_copy = isset( $template->send_user_email )
            ? ! empty( $template->send_user_email )
            : true;

        return PeWave_Plan::is_feature_enabled( 'email_sending' )
            && PeWave_Settings::is_user_email_enabled()
            && $template_allows_copy;
    }

    public static function ajax_search_bookings(): void {
        if ( ! PeWave_Settings::is_amelia_enabled() || ! PeWave_Plan::is_feature_enabled( 'amelia_integration' ) ) {
            wp_send_json_success( [] );
        }

        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'pewave_search_bookings' ) ) {
            wp_send_json_error( [ 'message' => __( 'Security check failed.', 'waiver-engine' ) ], 403 );
        }

        $template_id = isset( $_POST['template_id'] ) ? absint( $_POST['template_id'] ) : 0;
        $query = isset( $_POST['query'] ) ? sanitize_text_field( wp_unslash( $_POST['query'] ) ) : '';

        if ( ! $template_id || strlen( $query ) < 2 ) {
            wp_send_json_success( [] );
        }

        $template = PeWave_Database::get_template( $template_id );
        if ( ! $template ) {
            wp_send_json_success( [] );
        }

        $service_ids = json_decode( $template->amelia_service_ids ?? '', true ) ?: [];
        $service_ids = array_map( 'intval', $service_ids );
        $results = PeWave_Integration_Amelia::search_bookings( $service_ids, $query );

        wp_send_json_success( array_map( static function ( $row ) {
            return [
                'id'          => (int) $row->appointment_id,
                'bookingDate' => wp_date( 'd/m/Y g:i a', strtotime( $row->bookingStart ) ),
                'service'     => $row->service_name,
                'customer'    => $row->firstName . ' ' . $row->lastName,
                'status'      => $row->appointment_status,
            ];
        }, $results ) );
    }

    public static function ajax_get_booking(): void {
        if ( ! PeWave_Settings::is_amelia_enabled() || ! PeWave_Plan::is_feature_enabled( 'amelia_integration' ) ) {
            wp_send_json_error( [ 'message' => __( 'Amelia integration not enabled.', 'waiver-engine' ) ], 400 );
        }

        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'pewave_search_bookings' ) ) {
            wp_send_json_error( [ 'message' => __( 'Security check failed.', 'waiver-engine' ) ], 403 );
        }

        $booking_id = isset( $_POST['booking_id'] ) ? absint( $_POST['booking_id'] ) : 0;
        $template_id = isset( $_POST['template_id'] ) ? absint( $_POST['template_id'] ) : 0;
        $customer_email = isset( $_POST['customer_email'] ) ? sanitize_email( wp_unslash( $_POST['customer_email'] ) ) : '';

        if ( ! $booking_id || ! $template_id || ! is_email( $customer_email ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid request.', 'waiver-engine' ) ], 400 );
        }

        $template = PeWave_Database::get_template( $template_id );
        if ( ! $template ) {
            wp_send_json_error( [ 'message' => __( 'Template not found.', 'waiver-engine' ) ], 404 );
        }

        $row = PeWave_Integration_Amelia::get_booking( $booking_id );
        if ( ! $row ) {
            wp_send_json_error( [ 'message' => __( 'Booking not found.', 'waiver-engine' ) ], 404 );
        }

        $service_ids = json_decode( $template->amelia_service_ids ?? '', true ) ?: [];
        $service_ids = array_map( 'intval', $service_ids );
        if ( $service_ids && ! in_array( (int) $row->service_id, $service_ids, true ) ) {
            wp_send_json_error( [ 'message' => __( 'Booking not found.', 'waiver-engine' ) ], 404 );
        }

        $stored_email = isset( $row->customer_email ) ? sanitize_email( (string) $row->customer_email ) : '';
        if ( strtolower( $stored_email ) !== strtolower( $customer_email ) ) {
            wp_send_json_error( [ 'message' => __( 'Booking not found.', 'waiver-engine' ) ], 404 );
        }

        wp_send_json_success( [
            'id'          => (int) $row->appointment_id,
            'bookingDate' => wp_date( 'd/m/Y g:i a', strtotime( $row->bookingStart ) ),
            'service'     => $row->service_name,
            'customer'    => $row->firstName . ' ' . $row->lastName,
        ] );
    }

    public static function is_repeatable_group( array $group ): bool {
        return ! empty( $group['repeatable'] );
    }

    public static function process_repeatable_group( array $raw_group, array $group, array $fields, string $group_key, callable $process_row ): array {
        $errors = [];
        $min_rows = max( 1, (int) ( $group['min_rows'] ?? 1 ) );
        $max_rows = (int) ( $group['max_rows'] ?? 20 );
        $raw_group = array_slice( $raw_group, 0, $max_rows );

        if ( count( $raw_group ) < $min_rows ) {
            $errors[ $group_key ] = sprintf(
                /* translators: 1: group label, 2: minimum required row count */
                __( '%1$s requires at least %2$d row(s).', 'waiver-engine' ),
                $group['label'] ?? $group_key,
                $min_rows
            );
        }

        $data = [];
        foreach ( $raw_group as $idx => $raw_row ) {
            [ $row_data, $row_errors ] = $process_row( $raw_row, $fields, $group_key, (int) $idx );
            $data[] = $row_data;
            $errors = array_merge( $errors, $row_errors );
        }

        return [ $data, $errors ];
    }

    public static function send_copy_email( object $template, string $to, array $pdf_paths, ?object $booking_info = null ): void {
        if ( empty( $pdf_paths ) ) {
            return;
        }

        $attachment_path = ( $template->output_mode === 'per_row' ) ? end( $pdf_paths ) : reset( $pdf_paths );
        if ( ! $attachment_path || ! file_exists( $attachment_path ) ) {
            return;
        }

        /* translators: %s: template title */
        $subject = sprintf( __( 'Your copy: %s', 'waiver-engine' ), $template->title );
        /* translators: %s: template title */
        $body  = sprintf( __( 'Thank you for completing: %s', 'waiver-engine' ), $template->title ) . "\n\n";

        if ( $booking_info ) {
            $body .= __( 'Booking details:', 'waiver-engine' ) . "\n";
            /* translators: %d: booking ID */
            $body .= sprintf( __( 'Booking ID: %d', 'waiver-engine' ), (int) $booking_info->appointment_id ) . "\n";
            /* translators: %s: service name */
            $body .= sprintf( __( 'Service: %s', 'waiver-engine' ), $booking_info->service_name ) . "\n";
            /* translators: %s: booking date */
            $body .= sprintf( __( 'Booking date: %s', 'waiver-engine' ), wp_date( 'F j, Y g:i a', strtotime( $booking_info->bookingStart ) ) ) . "\n";
            /* translators: %s: customer full name */
            $body .= sprintf( __( 'Customer: %s', 'waiver-engine' ), $booking_info->firstName . ' ' . $booking_info->lastName ) . "\n\n";
        }

        $body .= __( 'Please find your waiver PDF attached to this email.', 'waiver-engine' ) . "\n";

        $sent = wp_mail( $to, $subject, $body, [ 'Content-Type: text/plain; charset=UTF-8' ], [ $attachment_path ] );
        if ( ! $sent ) {
            $last_mail_error = isset( $GLOBALS['phpmailer']->ErrorInfo ) ? (string) $GLOBALS['phpmailer']->ErrorInfo : '';
            error_log( '[WPWE] send_copy_email failed for: ' . $to . ' | last WP mail error: ' . $last_mail_error ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        }
    }

    public static function send_notification( object $template, int $entry_id, array $data, array $pdf_paths, ?object $booking_info = null ): bool {
        $to = $template->notification_email ?: get_option( 'admin_email' );
        if ( ! is_email( $to ) ) {
            return false;
        }

        /* translators: %s: template title */
        $subject = sprintf( __( 'New Waiver Submission: %s', 'waiver-engine' ), $template->title );
        $entry_url = admin_url( 'admin.php?page=pewave-entry&id=' . $entry_id );

        $body  = '<!doctype html><html><body style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.45;color:#1d2327;">';
        /* translators: %s: template title */
        $body .= '<p>' . esc_html( sprintf( __( 'A new waiver was submitted for: %s', 'waiver-engine' ), $template->title ) ) . '</p>';
        /* translators: %s: submission timestamp */
        $body .= '<p>' . esc_html( sprintf( __( 'Submission time: %s', 'waiver-engine' ), wp_date( 'F j, Y g:i a' ) ) ) . '<br>';
        $body .= esc_html( __( 'View entry:', 'waiver-engine' ) ) . ' <a href="' . esc_url( $entry_url ) . '">' . esc_html( $entry_url ) . '</a></p>';

        if ( $booking_info ) {
            $body .= '<h3 style="margin:20px 0 8px 0;">' . esc_html__( 'Booking details', 'waiver-engine' ) . '</h3>';
            $body .= '<table cellpadding="6" cellspacing="0" border="1" style="border-collapse:collapse;border-color:#dcdcde;">';
            $body .= '<tr><th align="left">' . esc_html__( 'Field', 'waiver-engine' ) . '</th><th align="left">' . esc_html__( 'Value', 'waiver-engine' ) . '</th></tr>';
            $body .= '<tr><td>' . esc_html__( 'Booking ID', 'waiver-engine' ) . '</td><td>' . esc_html( (string) (int) $booking_info->appointment_id ) . '</td></tr>';
            $body .= '<tr><td>' . esc_html__( 'Service', 'waiver-engine' ) . '</td><td>' . esc_html( (string) $booking_info->service_name ) . '</td></tr>';
            $body .= '<tr><td>' . esc_html__( 'Booking date', 'waiver-engine' ) . '</td><td>' . esc_html( wp_date( 'F j, Y g:i a', strtotime( $booking_info->bookingStart ) ) ) . '</td></tr>';
            $body .= '<tr><td>' . esc_html__( 'Customer', 'waiver-engine' ) . '</td><td>' . esc_html( (string) ( $booking_info->firstName . ' ' . $booking_info->lastName ) ) . '</td></tr>';
            $body .= '</table>';
        }

        $body .= '<h3 style="margin:20px 0 8px 0;">' . esc_html__( 'Submitted data', 'waiver-engine' ) . '</h3>';
        $body .= self::build_submission_html_tables( $template, $data );
        $body .= '</body></html>';

        $attachments = [];
        foreach ( $pdf_paths as $path ) {
            if ( $path && file_exists( $path ) ) {
                $attachments[] = $path;
            }
        }

        return wp_mail( $to, $subject, $body, [ 'Content-Type: text/html; charset=UTF-8' ], $attachments );
    }

    public static function generate_per_row_pdfs( PeWave_PDF_Generator $generator, array $data, int $entry_id, ?object $booking = null ): array {
        $group_key = $generator->get_output_group_key();
        $rows = $data[ $group_key ] ?? [];
        $paths = [];
        $ts = time();
        $booking_slug = $booking ? $generator->booking_slug_for_premium( $booking ) : '';
        $prefix = $booking_slug ?: ( 'waiver-' . $entry_id );

        foreach ( $rows as $i => $row ) {
            $row_data = $data;
            $row_data[ $group_key ] = [ $row ];
            $filename = sanitize_file_name( $prefix . '-row-' . ( $i + 1 ) . '-' . $ts . '.pdf' );
            $paths[] = $generator->save_pdf_for_premium( $generator->build_pdf_for_premium( $row_data ), $filename );
        }

        $combined_filename = sanitize_file_name( $prefix . '-combined-' . $ts . '.pdf' );
        $paths[] = $generator->save_pdf_for_premium( self::build_combined_pdf_from_rows( $generator, $data ), $combined_filename );

        return $paths;
    }

    public static function preview_per_row_pdf( PeWave_PDF_Generator $generator, array $data ): string {
        return self::build_combined_pdf_from_rows( $generator, $data )->Output( 'S' );
    }

    private static function build_combined_pdf_from_rows( PeWave_PDF_Generator $generator, array $data ): Fpdi {
        $group_key = $generator->get_output_group_key();
        $rows = $data[ $group_key ] ?? [];

        if ( empty( $rows ) ) {
            return $generator->build_pdf_for_premium( $data );
        }

        $pdf = $generator->new_pdf_document_for_premium();
        $mapping = $generator->get_mapping_for_premium();
        $pdf_file_path = $generator->get_pdf_file_path_for_premium();
        $page_count = $pdf->setSourceFile( $pdf_file_path ) ?: (int) ( $mapping['page_count'] ?? 1 );

        foreach ( $rows as $row ) {
            $row_data = $data;
            $row_data[ $group_key ] = [ $row ];

            for ( $page = 1; $page <= $page_count; $page++ ) {
                $tpl_id = $pdf->importPage( $page );
                $size = $pdf->getTemplateSize( $tpl_id );
                $pdf->AddPage( $size['orientation'], [ $size['width'], $size['height'] ] );
                $pdf->useTemplate( $tpl_id, 0, 0, $size['width'], $size['height'] );

                foreach ( $mapping['fields'] ?? [] as $path => $config ) {
                    if ( (int) ( $config['page'] ?? 1 ) !== $page ) {
                        continue;
                    }

                    $lookup_path = preg_replace( '/__\d+$/', '', $path );
                    $value = $generator->resolve_path_for_premium( $row_data, $lookup_path );
                    if ( $value === '' ) {
                        continue;
                    }

                    $field_type = $config['type'] ?? 'text';
                    if ( $field_type === 'image' || $field_type === 'signature' ) {
                        $generator->place_image_for_premium( $pdf, $value, $config );
                    } else {
                        $generator->place_text_for_premium( $pdf, $value, $config );
                    }
                }
            }
        }

        return $pdf;
    }

    public static function build_submission_html_tables( object $template, array $data ): string {
        $schema = json_decode( (string) ( $template->field_schema ?? '' ), true );
        if ( ! is_array( $schema ) || empty( $schema['groups'] ) || ! is_array( $schema['groups'] ) ) {
            return '<pre style="white-space:pre-wrap;">' . esc_html( self::flatten_data_text( $data ) ) . '</pre>';
        }

        $html = '';
        $rendered_groups = [];

        foreach ( $schema['groups'] as $group ) {
            $group_key = sanitize_key( $group['key'] ?? '' );
            if ( '' === $group_key || ! isset( $data[ $group_key ] ) ) {
                continue;
            }

            $rendered_groups[] = $group_key;
            $group_label = (string) ( $group['label'] ?? $group_key );
            $fields = isset( $group['fields'] ) && is_array( $group['fields'] ) ? $group['fields'] : [];
            $rows = self::normalize_group_rows_for_output( $data[ $group_key ] );
            $repeatable = self::is_repeatable_group( $group );

            $html .= '<h4 style="margin:14px 0 6px 0;">' . esc_html( $group_label ) . '</h4>';
            $html .= '<table cellpadding="6" cellspacing="0" border="1" style="border-collapse:collapse;border-color:#dcdcde;width:100%;max-width:1000px;">';

            if ( $repeatable ) {
                $html .= '<tr><th align="left" style="width:70px;">' . esc_html__( 'Row', 'waiver-engine' ) . '</th>';
                foreach ( $fields as $field ) {
                    $field_label = (string) ( $field['label'] ?? ( $field['key'] ?? '' ) );
                    $html .= '<th align="left">' . esc_html( $field_label ) . '</th>';
                }
                $html .= '</tr>';

                if ( ! $rows ) {
                    $html .= '<tr><td colspan="' . esc_attr( (string) max( 2, count( $fields ) + 1 ) ) . '"><em>' . esc_html__( 'No rows submitted.', 'waiver-engine' ) . '</em></td></tr>';
                } else {
                    foreach ( $rows as $row_index => $row ) {
                        $html .= '<tr><td>' . esc_html( (string) ( $row_index + 1 ) ) . '</td>';
                        foreach ( $fields as $field ) {
                            $field_key = sanitize_key( $field['key'] ?? '' );
                            $value = $field_key && isset( $row[ $field_key ] ) ? $row[ $field_key ] : '';
                            $html .= '<td>' . self::format_email_cell_value( $value ) . '</td>';
                        }
                        $html .= '</tr>';
                    }
                }
            } else {
                $html .= '<tr><th align="left" style="width:35%;">' . esc_html__( 'Field', 'waiver-engine' ) . '</th><th align="left">' . esc_html__( 'Value', 'waiver-engine' ) . '</th></tr>';
                $row = $rows[0] ?? [];
                foreach ( $fields as $field ) {
                    $field_key = sanitize_key( $field['key'] ?? '' );
                    $field_label = (string) ( $field['label'] ?? $field_key );
                    $value = $field_key && isset( $row[ $field_key ] ) ? $row[ $field_key ] : '';
                    $html .= '<tr><td>' . esc_html( $field_label ) . '</td><td>' . self::format_email_cell_value( $value ) . '</td></tr>';
                }
            }

            $html .= '</table>';
        }

        $unknown = array_diff( array_keys( $data ), $rendered_groups );
        if ( ! empty( $unknown ) ) {
            $html .= '<h4 style="margin:14px 0 6px 0;">' . esc_html__( 'Additional Data', 'waiver-engine' ) . '</h4>';
            $html .= '<table cellpadding="6" cellspacing="0" border="1" style="border-collapse:collapse;border-color:#dcdcde;width:100%;max-width:900px;">';
            $html .= '<tr><th align="left" style="width:35%;">' . esc_html__( 'Field', 'waiver-engine' ) . '</th><th align="left">' . esc_html__( 'Value', 'waiver-engine' ) . '</th></tr>';
            foreach ( $unknown as $unknown_key ) {
                $raw_value = $data[ $unknown_key ];
                $value = is_array( $raw_value ) ? ( wp_json_encode( $raw_value ) ?: '' ) : (string) $raw_value;
                $html .= '<tr><td><code>' . esc_html( (string) $unknown_key ) . '</code></td><td>' . self::format_email_cell_value( $value ) . '</td></tr>';
            }
            $html .= '</table>';
        }

        return $html !== '' ? $html : '<pre style="white-space:pre-wrap;">' . esc_html( self::flatten_data_text( $data ) ) . '</pre>';
    }

    public static function normalize_group_rows_for_output( mixed $group_data ): array {
        if ( ! is_array( $group_data ) || [] === $group_data ) {
            return [];
        }

        $first = reset( $group_data );
        if ( is_array( $first ) ) {
            return $group_data;
        }

        return [ $group_data ];
    }

    public static function format_email_cell_value( mixed $value ): string {
        if ( is_array( $value ) ) {
            return esc_html( wp_json_encode( $value ) ?: '' );
        }

        $display = (string) $value;
        if ( '' === $display ) {
            return '&mdash;';
        }

        if ( str_starts_with( $display, 'data:image/' ) ) {
            return '<img src="' . esc_attr( $display ) . '" alt="' . esc_attr__( 'Signature', 'waiver-engine' ) . '" style="display:block;max-width:100%;width:auto;height:auto;max-height:60px;border:1px solid #ddd;padding:2px;background:#fff;box-sizing:border-box;">';
        }

        return nl2br( esc_html( $display ) );
    }

    public static function flatten_data_text( array $data, string $prefix = '' ): string {
        $lines = '';
        foreach ( $data as $key => $value ) {
            $label = '' === $prefix ? (string) $key : $prefix . '.' . $key;
            if ( is_array( $value ) ) {
                if ( array_is_list( $value ) ) {
                    foreach ( $value as $i => $row ) {
                        $lines .= self::flatten_data_text( $row, $label . '[' . $i . ']' );
                    }
                } else {
                    $lines .= self::flatten_data_text( $value, $label );
                }
            } else {
                $lines .= $label . ': ' . (string) $value . "\n";
            }
        }

        return $lines;
    }

    public static function get_entries_with_booking_data( int $per_page, int $paged, int $template_id, string $orderby, string $order, string $booking_search, int $amelia_service_id ): array {
        return PeWave_Integration_Amelia::get_entries_with_booking_data( $per_page, $paged, $template_id, $orderby, $order, 0, $booking_search, $amelia_service_id );
    }

    public static function get_amelia_services(): array {
        return PeWave_Integration_Amelia::get_services();
    }

    public static function get_booking( int $booking_id ): ?object {
        return PeWave_Integration_Amelia::get_booking( $booking_id );
    }

    /**
     * Pro upgrade notice on the settings screen (unlicensed premium package only).
     */
    public static function render_settings_upgrade_notice(): void {
        if ( PeWave_Plan::is_pro() ) {
            return;
        }

        $account_url = PeWave_Plan::get_account_url();
        $upgrade_url = PeWave_Plan::get_upgrade_url();
        ?>
        <div class="notice notice-info">
            <p>
                <?php esc_html_e( 'Pro package is installed but not activated yet. Enter your license key to unlock Pro features.', 'waiver-engine' ); ?>
                <?php if ( $account_url !== '#' ) : ?>
                    <a class="button button-primary" href="<?php echo esc_url( $account_url ); ?>" target="_blank" rel="noopener noreferrer" style="margin-left:8px;">
                        <?php esc_html_e( 'Activate License', 'waiver-engine' ); ?>
                    </a>
                <?php endif; ?>
                <?php if ( $upgrade_url !== '#' ) : ?>
                    <a class="button button-secondary" href="<?php echo esc_url( $upgrade_url ); ?>" target="_blank" rel="noopener noreferrer" style="margin-left:8px;">
                        <?php esc_html_e( 'Upgrade to Pro', 'waiver-engine' ); ?>
                    </a>
                <?php endif; ?>
            </p>
        </div>
        <?php
    }

    /**
     * Amelia and email settings rows on the settings screen (premium package only).
     */
    public static function render_settings_premium_rows(): void {
        ?>
        <tr>
            <th scope="row">
                <?php esc_html_e( 'Amelia Integration', 'waiver-engine' ); ?>
                <span class="wpwe-tooltip dashicons dashicons-editor-help"
                      title="<?php esc_attr_e( 'Connect Waiver Engine with the Amelia Booking plugin. When active, a booking-search widget appears on waiver forms so customers can link their submission to an existing appointment.', 'waiver-engine' ); ?>"></span>
            </th>
            <td>
            <?php if ( ! PeWave_Plan::is_feature_enabled( 'amelia_integration' ) ) : ?>
                <p class="description">
                    <?php esc_html_e( 'Available on Pro plans.', 'waiver-engine' ); ?>
                </p>
            <?php elseif ( PeWave_Integration_Manager::is_amelia_active() ) : ?>
                <fieldset>
                    <label>
                        <input type="checkbox" name="pewave_amelia_enabled" value="1"
                               <?php checked( (bool) get_option( PeWave_Settings::OPTION_AMELIA_ENABLED, false ) ); ?>>
                        <?php esc_html_e( 'Enable Amelia booking integration', 'waiver-engine' ); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e( 'When enabled: a booking-search widget appears on frontend forms, booking details are included in notification emails, Service / Customer / Date columns appear in the Entries list, and "Linked Amelia Services" is shown in template editors. Defaults to OFF.', 'waiver-engine' ); ?>
                    </p>
                </fieldset>
            <?php else : ?>
                <p class="description">
                    <?php esc_html_e( 'Amelia Booking plugin not detected. Install and activate Amelia to enable booking integration settings.', 'waiver-engine' ); ?>
                </p>
            <?php endif; ?>
            </td>
        </tr>
        <tr>
            <th scope="row">
                <?php esc_html_e( 'Admin Notification Emails', 'waiver-engine' ); ?>
                <span class="wpwe-tooltip dashicons dashicons-editor-help"
                      title="<?php esc_attr_e( 'When enabled, the notification address receives an email with the PDF attached each time a waiver is submitted. Can also be toggled per-template in the template editor.', 'waiver-engine' ); ?>"></span>
            </th>
            <td>
                <?php if ( PeWave_Plan::is_feature_enabled( 'email_sending' ) ) : ?>
                <fieldset>
                    <label>
                        <input type="checkbox" name="pewave_admin_email_enabled" value="1"
                               <?php checked( PeWave_Settings::is_admin_email_enabled() ); ?>>
                        <?php esc_html_e( 'Enable admin notification emails', 'waiver-engine' ); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e( 'When enabled, an email is sent to the notification address each time a new waiver is submitted. Per-template control appears on each template\'s settings page. Defaults to ON.', 'waiver-engine' ); ?>
                    </p>
                </fieldset>
                <?php else : ?>
                <p class="description"><?php esc_html_e( 'Available on Pro plans.', 'waiver-engine' ); ?></p>
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <th scope="row">
                <?php esc_html_e( 'Submitter Copy Emails', 'waiver-engine' ); ?>
                <span class="wpwe-tooltip dashicons dashicons-editor-help"
                      title="<?php esc_attr_e( 'When enabled, an opt-in checkbox appears on waiver forms so submitters can request a PDF copy by email. Can also be toggled per-template.', 'waiver-engine' ); ?>"></span>
            </th>
            <td>
                <?php if ( PeWave_Plan::is_feature_enabled( 'email_sending' ) ) : ?>
                <fieldset>
                    <label>
                        <input type="checkbox" name="pewave_user_email_enabled" value="1"
                               <?php checked( PeWave_Settings::is_user_email_enabled() ); ?>>
                        <?php esc_html_e( 'Enable submitter copy emails', 'waiver-engine' ); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e( 'When enabled, submitters can opt in to receive a PDF copy by email. The opt-in checkbox appears on the frontend form. Per-template control appears on each template\'s settings page. Defaults to ON.', 'waiver-engine' ); ?>
                    </p>
                </fieldset>
                <?php else : ?>
                <p class="description"><?php esc_html_e( 'Available on Pro plans.', 'waiver-engine' ); ?></p>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }
}

