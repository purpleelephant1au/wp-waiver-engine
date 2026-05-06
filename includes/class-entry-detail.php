<?php
namespace WPWE;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the single-entry detail admin page.
 * Instantiated by Admin_Menu::page_entry_detail().
 *
 * Also handles the PDF download action (?dl_pdf=N) for serving generated PDFs.
 */
class Entry_Detail {

    private object $entry;

    public function __construct( object $entry ) {
        $this->entry = $entry;
    }

    public function render(): void {
        $entry     = $this->entry;
        $id        = (int) $entry->id;
        $data      = json_decode( $entry->submission_data, true ) ?: [];
        $pdf_paths = json_decode( $entry->pdf_paths, true ) ?: [];
        $template  = Database::get_template( (int) $entry->template_id );
        $schema    = $template ? ( json_decode( (string) $template->field_schema, true ) ?: [] ) : [];

        // Note: ?dl_pdf downloads are handled early by Admin_Menu::handle_actions()
        // before any HTML output, so we never reach here for download requests.

        $back_url = admin_url( 'admin.php?page=wpwe-entries' );
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">
                <?php printf( esc_html__( 'Entry #%d', 'wp-waiver-engine' ), $id ); ?>
            </h1>
            <a href="<?php echo esc_url( $back_url ); ?>" class="page-title-action">
                &larr; <?php esc_html_e( 'All Entries', 'wp-waiver-engine' ); ?>
            </a>
            &nbsp;
            <a href="<?php echo esc_url( wp_nonce_url(
                admin_url( 'admin.php?page=wpwe-entries&wpwe_action=delete_entry&id=' . $id ),
                'wpwe_delete_entry_' . $id
            ) ); ?>"
               onclick="return confirm('<?php esc_attr_e( 'Permanently delete this entry?', 'wp-waiver-engine' ); ?>')"
               class="page-title-action" style="color:#d63638;border-color:#d63638;">
                <?php esc_html_e( 'Delete Entry', 'wp-waiver-engine' ); ?>
            </a>
            <hr class="wp-header-end">

            <!-- Meta row -->
            <table class="form-table" style="max-width:700px;">
                <tr>
                    <th><?php esc_html_e( 'Template', 'wp-waiver-engine' ); ?></th>
                    <td>
                        <?php if ( $template ) : ?>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=wpwe-edit&id=' . $template->id ) ); ?>">
                            <?php echo esc_html( $template->title ?: '#' . $template->id ); ?>
                        </a>
                        <?php else : ?>
                        <?php echo esc_html( '#' . $entry->template_id . ' (deleted)' ); ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Submitted', 'wp-waiver-engine' ); ?></th>
                    <td><?php echo esc_html( $entry->created_at ); ?></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Submitter IP', 'wp-waiver-engine' ); ?></th>
                    <td><?php echo esc_html( $entry->submitter_ip ?: '—' ); ?></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Email Sent', 'wp-waiver-engine' ); ?></th>
                    <td><?php echo $entry->email_sent
                        ? esc_html__( 'Yes', 'wp-waiver-engine' )
                        : esc_html__( 'No', 'wp-waiver-engine' ); ?></td>
                </tr>
                <?php if ( Settings::is_amelia_enabled() && class_exists( Premium_Bridge::class ) ) : ?>
                <?php
                $bk_id = (int) ( $entry->amelia_booking_id ?? 0 );
                if ( $bk_id ) :
                    $booking = Premium_Bridge::get_booking( $bk_id );
                endif;
                if ( $bk_id && isset( $booking ) && $booking ) : ?>
                <tr>
                    <th><?php esc_html_e( 'Booking', 'wp-waiver-engine' ); ?></th>
                    <td>
                        <strong><?php echo esc_html( $booking->service_name ); ?></strong><br>
                        <?php echo esc_html( wp_date( 'F j, Y g:i a', strtotime( $booking->bookingStart ) ) ); ?><br>
                        <?php echo esc_html( $booking->firstName . ' ' . $booking->lastName ); ?>
                        <small style="color:#6b7a8d;"> &mdash; <?php printf( esc_html__( 'Booking #%d', 'wp-waiver-engine' ), $bk_id ); ?></small>
                    </td>
                </tr>
                <?php elseif ( $bk_id ) : ?>                <tr>
                    <th><?php esc_html_e( 'Booking', 'wp-waiver-engine' ); ?></th>
                    <td><?php printf( esc_html__( 'Booking #%d (details unavailable)', 'wp-waiver-engine' ), $bk_id ); ?></td>
                </tr>
                <?php endif; ?>
                <?php endif; /* Settings::is_amelia_enabled() */ ?>
                <?php if ( $entry->pdf_error ) : ?>
                <tr>
                    <th><?php esc_html_e( 'PDF Error', 'wp-waiver-engine' ); ?></th>
                    <td><code style="color:#d63638;white-space:pre-wrap;"><?php echo esc_html( $entry->pdf_error ); ?></code></td>
                </tr>
                <?php endif; ?>
            </table>

            <!-- PDFs -->
            <?php if ( $pdf_paths ) : ?>
            <h3><?php esc_html_e( 'Generated PDFs', 'wp-waiver-engine' ); ?></h3>
            <ul>
                <?php foreach ( $pdf_paths as $i => $path ) :
                    $nonce_base = wp_create_nonce( 'wpwe_dl_pdf_' . $id );
                    $view_url   = admin_url( 'admin.php?page=wpwe-entry&id=' . $id . '&view_pdf=' . $i . '&_wpnonce=' . $nonce_base );
                    $dl_url     = admin_url( 'admin.php?page=wpwe-entry&id=' . $id . '&dl_pdf='   . $i . '&_wpnonce=' . $nonce_base );
                    $fname      = basename( $path );
                    $exists     = file_exists( $path );
                    $is_combined = strpos( $fname, '-combined-' ) !== false;
                    if ( $is_combined ) {
                        $link_text = __( 'Combined PDF (all players)', 'wp-waiver-engine' );
                    } elseif ( preg_match( '/-row-(\d+)-/', $fname, $m ) ) {
                        /* translators: %d: player number */
                        $link_text = sprintf( __( 'Player %d PDF', 'wp-waiver-engine' ), (int) $m[1] );
                    } else {
                        $link_text = $fname;
                    }
                ?>
                <li<?php echo $is_combined ? ' style="font-weight:600;"' : ''; ?>>
                    <?php echo esc_html( $link_text ); ?>
                    <?php if ( $exists ) : ?>
                    &nbsp;
                    <a href="<?php echo esc_url( $view_url ); ?>" target="_blank" rel="noopener">
                        <?php esc_html_e( 'View', 'wp-waiver-engine' ); ?>
                    </a>
                    &nbsp;&middot;&nbsp;
                    <a href="<?php echo esc_url( $dl_url ); ?>">
                        <?php esc_html_e( 'Download', 'wp-waiver-engine' ); ?>
                    </a>
                    <?php else : ?>
                    &nbsp;<em style="color:#d63638;"><?php esc_html_e( '(file missing)', 'wp-waiver-engine' ); ?></em>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>

            <!-- Submission data -->
            <h3><?php esc_html_e( 'Field Values', 'wp-waiver-engine' ); ?></h3>
            <?php if ( $data ) : ?>
            <?php if ( ! empty( $schema['groups'] ) && is_array( $schema['groups'] ) ) : ?>
            <?php $rendered_groups = []; ?>
            <?php foreach ( $schema['groups'] as $group ) :
                $group_key = sanitize_key( $group['key'] ?? '' );
                if ( '' === $group_key || ! isset( $data[ $group_key ] ) ) {
                    continue;
                }
                $rendered_groups[] = $group_key;
                $group_label = (string) ( $group['label'] ?? $group_key );
                $fields      = isset( $group['fields'] ) && is_array( $group['fields'] ) ? $group['fields'] : [];
                $rows        = $this->normalize_group_rows( $data[ $group_key ] );
                $repeatable  = ! empty( $group['repeatable'] );
            ?>
            <h4 style="margin-top:20px;"><?php echo esc_html( $group_label ); ?></h4>

            <?php if ( $repeatable ) : ?>
            <table class="widefat fixed striped" style="max-width:1000px;">
                <thead>
                    <tr>
                        <th style="width:70px;"><?php esc_html_e( 'Row', 'wp-waiver-engine' ); ?></th>
                        <?php foreach ( $fields as $field ) :
                            $field_label = (string) ( $field['label'] ?? ( $field['key'] ?? '' ) );
                        ?>
                        <th><?php echo esc_html( $field_label ); ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( ! $rows ) : ?>
                    <tr>
                        <td colspan="<?php echo esc_attr( (string) ( count( $fields ) + 1 ) ); ?>">
                            <em><?php esc_html_e( 'No rows submitted.', 'wp-waiver-engine' ); ?></em>
                        </td>
                    </tr>
                    <?php else : ?>
                    <?php foreach ( $rows as $row_index => $row ) : ?>
                    <tr>
                        <td><?php echo esc_html( (string) ( $row_index + 1 ) ); ?></td>
                        <?php foreach ( $fields as $field ) :
                            $field_key = sanitize_key( $field['key'] ?? '' );
                            $value     = $field_key && isset( $row[ $field_key ] ) ? $row[ $field_key ] : '';
                        ?>
                        <td><?php $this->render_cell_value( $value ); ?></td>
                        <?php endforeach; ?>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            <?php else : ?>
            <?php $row = $rows[0] ?? []; ?>
            <table class="widefat fixed striped" style="max-width:900px;">
                <thead>
                    <tr>
                        <th style="width:35%"><?php esc_html_e( 'Field', 'wp-waiver-engine' ); ?></th>
                        <th><?php esc_html_e( 'Value', 'wp-waiver-engine' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $fields as $field ) :
                        $field_key   = sanitize_key( $field['key'] ?? '' );
                        $field_label = (string) ( $field['label'] ?? $field_key );
                        $value       = $field_key && isset( $row[ $field_key ] ) ? $row[ $field_key ] : '';
                    ?>
                    <tr>
                        <td><?php echo esc_html( $field_label ); ?></td>
                        <td><?php $this->render_cell_value( $value ); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
            <?php endforeach; ?>

            <?php
            $unknown = array_diff( array_keys( $data ), $rendered_groups );
            if ( ! empty( $unknown ) ) :
            ?>
            <h4 style="margin-top:20px;"><?php esc_html_e( 'Additional Data', 'wp-waiver-engine' ); ?></h4>
            <table class="widefat fixed striped" style="max-width:900px;">
                <thead>
                    <tr>
                        <th style="width:35%"><?php esc_html_e( 'Field', 'wp-waiver-engine' ); ?></th>
                        <th><?php esc_html_e( 'Value', 'wp-waiver-engine' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $unknown as $unknown_key ) : ?>
                    <tr>
                        <td><code><?php echo esc_html( (string) $unknown_key ); ?></code></td>
                        <td>
                            <?php
                            $raw_value = $data[ $unknown_key ];
                            if ( is_array( $raw_value ) ) {
                                echo esc_html( wp_json_encode( $raw_value ) ?: '' );
                            } else {
                                $this->render_cell_value( $raw_value );
                            }
                            ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
            <?php else : ?>
            <table class="widefat fixed striped" style="max-width:900px;">
                <thead>
                    <tr>
                        <th style="width:35%"><?php esc_html_e( 'Field', 'wp-waiver-engine' ); ?></th>
                        <th><?php esc_html_e( 'Value', 'wp-waiver-engine' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php $this->render_data_rows( $data ); ?>
                </tbody>
            </table>
            <?php endif; ?>
            <?php else : ?>
            <p><?php esc_html_e( 'No submission data found.', 'wp-waiver-engine' ); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function normalize_group_rows( mixed $group_data ): array {
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

    private function render_cell_value( mixed $value ): void {
        if ( is_array( $value ) ) {
            echo esc_html( wp_json_encode( $value ) ?: '' );
            return;
        }

        $display = (string) $value;
        if ( '' === $display ) {
            echo '&mdash;';
            return;
        }

        if ( str_starts_with( $display, 'data:image/' ) ) {
            ?>
            <img src="<?php echo esc_attr( $display ); ?>"
                 alt="<?php esc_attr_e( 'Signature', 'wp-waiver-engine' ); ?>"
                 style="display:block;max-width:100%;width:auto;height:auto;max-height:60px;border:1px solid #ddd;padding:2px;background:#fff;box-sizing:border-box;">
            <?php
            return;
        }

        echo nl2br( esc_html( $display ) );
    }

    private function render_data_rows( array $data, string $prefix = '' ): void {
        foreach ( $data as $key => $value ) {
            $label = $prefix ? $prefix . '.' . $key : (string) $key;
            if ( is_array( $value ) ) {
                if ( isset( $value[0] ) && is_array( $value[0] ) ) {
                    foreach ( $value as $i => $row ) {
                        $this->render_data_rows( $row, $label . '[' . $i . ']' );
                    }
                } else {
                    $this->render_data_rows( $value, $label );
                }
            } else {
                $display = (string) $value;
                $is_sig  = str_starts_with( $display, 'data:image/' );
                ?>
                <tr>
                    <td><code><?php echo esc_html( $label ); ?></code></td>
                    <td>
                        <?php if ( $is_sig ) : ?>
                        <img src="<?php echo esc_attr( $display ); ?>"
                             alt="<?php esc_attr_e( 'Signature', 'wp-waiver-engine' ); ?>"
                             style="max-height:60px;border:1px solid #ddd;padding:2px;background:#fff;">
                        <?php else : ?>
                        <?php echo esc_html( $display ); ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php
            }
        }
    }
}
