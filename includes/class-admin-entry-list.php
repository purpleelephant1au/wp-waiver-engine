<?php
namespace WPWE;

defined( 'ABSPATH' ) || exit;

/**
 * Customises the admin list table for waiver_entry and adds a meta box
 * showing submission details and download links.
 */
class Admin_Entry_List {

    public function register(): void {
        add_filter( 'manage_waiver_entry_posts_columns',       [ $this, 'columns' ] );
        add_action( 'manage_waiver_entry_posts_custom_column', [ $this, 'column_content' ], 10, 2 );
        add_filter( 'manage_edit-waiver_entry_sortable_columns', [ $this, 'sortable_columns' ] );
        add_action( 'add_meta_boxes', [ $this, 'add_entry_meta_box' ] );
        // Prevent creating new entries from the admin edit screen
        add_action( 'admin_head-post-new.php', [ $this, 'block_new_entry' ] );
    }

    // -----------------------------------------------------------------------
    // Columns
    // -----------------------------------------------------------------------

    public function columns( array $columns ): array {
        return [
            'cb'           => $columns['cb'],
            'title'        => __( 'Entry', 'waiver-engine' ),
            'template'     => __( 'Template', 'waiver-engine' ),
            'submitter'    => __( 'Submitter', 'waiver-engine' ),
            'pdfs'         => __( 'PDFs', 'waiver-engine' ),
            'email_sent'   => __( 'Email Sent', 'waiver-engine' ),
            'date'         => __( 'Submitted', 'waiver-engine' ),
        ];
    }

    public function sortable_columns( array $cols ): array {
        $cols['date'] = 'date';
        return $cols;
    }

    public function column_content( string $column, int $post_id ): void {
        switch ( $column ) {
            case 'template':
                $tid = (int) get_post_meta( $post_id, 'template_id', true );
                echo $tid ? esc_html( get_the_title( $tid ) ) : '—';
                break;

            case 'submitter':
                $data = $this->get_submission_data( $post_id );
                // Try common signer fields
                $name  = $this->find_field( $data, [ 'signer.full_name', 'signer.name', 'full_name' ] );
                $email = $this->find_field( $data, [ 'signer.email', 'email' ] );
                echo esc_html( $name ?: '—' );
                if ( $email ) {
                    echo '<br><small>' . esc_html( $email ) . '</small>';
                }
                break;

            case 'pdfs':
                $pdf_ids = json_decode( (string) get_post_meta( $post_id, 'pdf_ids', true ), true );
                if ( is_array( $pdf_ids ) && count( $pdf_ids ) ) {
                    foreach ( $pdf_ids as $i => $att_id ) {
                        $url = wp_get_attachment_url( $att_id );
                        if ( $url ) {
                            echo '<a href="' . esc_url( $url ) . '" target="_blank">';
                            /* translators: %d: PDF index number */
                            printf( esc_html__( 'PDF %d', 'waiver-engine' ), absint( $i ) + 1 );
                            echo '</a> ';
                        }
                    }
                } else {
                    echo '—';
                }
                break;

            case 'email_sent':
                $sent = (bool) get_post_meta( $post_id, 'email_sent', true );
                echo $sent ? '<span style="color:green">&#10003;</span>' : '—';
                break;
        }
    }

    // -----------------------------------------------------------------------
    // Entry Detail Meta Box
    // -----------------------------------------------------------------------

    public function add_entry_meta_box(): void {
        add_meta_box(
            'wpwe_entry_detail',
            __( 'Submission Data', 'waiver-engine' ),
            [ $this, 'render_entry_meta_box' ],
            'waiver_entry',
            'normal',
            'high'
        );
    }

    public function render_entry_meta_box( \WP_Post $post ): void {
        $template_id = (int) get_post_meta( $post->ID, 'template_id', true );
        $data        = $this->get_submission_data( $post->ID );
        $pdf_ids     = json_decode( (string) get_post_meta( $post->ID, 'pdf_ids', true ), true );
        $ip          = (string) get_post_meta( $post->ID, 'submitter_ip', true );
        $email_sent  = (bool) get_post_meta( $post->ID, 'email_sent', true );
        ?>
        <p>
            <strong><?php esc_html_e( 'Template:', 'waiver-engine' ); ?></strong>
            <?php echo $template_id ? esc_html( get_the_title( $template_id ) ) : '—'; ?>
            &nbsp;|&nbsp;
            <strong><?php esc_html_e( 'Submitter IP:', 'waiver-engine' ); ?></strong>
            <?php echo esc_html( $ip ?: '—' ); ?>
            &nbsp;|&nbsp;
            <strong><?php esc_html_e( 'Email Sent:', 'waiver-engine' ); ?></strong>
            <?php echo $email_sent ? esc_html__( 'Yes', 'waiver-engine' ) : esc_html__( 'No', 'waiver-engine' ); ?>
        </p>

        <?php if ( is_array( $pdf_ids ) && count( $pdf_ids ) ) : ?>
        <p>
            <strong><?php esc_html_e( 'Generated PDFs:', 'waiver-engine' ); ?></strong>
        </p>
        <ul>
            <?php foreach ( $pdf_ids as $i => $att_id ) :
                $url  = wp_get_attachment_url( $att_id );
                $name = get_the_title( $att_id );
            ?>
            <li>
                <?php if ( $url ) : ?>
                    <a href="<?php echo esc_url( $url ); ?>" target="_blank">
                        <?php echo esc_html( $name ?: 'PDF ' . ( $i + 1 ) ); ?>
                    </a>
                <?php else : ?>
                    <?php esc_html_e( '(attachment deleted)', 'waiver-engine' ); ?>
                <?php endif; ?>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>

        <hr>
        <h4><?php esc_html_e( 'Field Values', 'waiver-engine' ); ?></h4>
        <?php if ( $data ) : ?>
        <table class="widefat fixed striped">
            <thead><tr>
                <th style="width:30%"><?php esc_html_e( 'Field', 'waiver-engine' ); ?></th>
                <th><?php esc_html_e( 'Value', 'waiver-engine' ); ?></th>
            </tr></thead>
            <tbody>
            <?php $this->render_data_rows( $data ); ?>
            </tbody>
        </table>
        <?php else : ?>
        <p><?php esc_html_e( 'No submission data found.', 'waiver-engine' ); ?></p>
        <?php endif; ?>
        <?php
    }

    // -----------------------------------------------------------------------
    // Block new entry creation from admin
    // -----------------------------------------------------------------------

    public function block_new_entry(): void {
        global $post_type;
        if ( $post_type === 'waiver_entry' ) {
            wp_die( esc_html__( 'Waiver entries are created by form submissions only.', 'waiver-engine' ) );
        }
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function get_submission_data( int $post_id ): array {
        $raw = (string) get_post_meta( $post_id, 'submission_data', true );
        if ( ! $raw ) {
            return [];
        }
        $decoded = json_decode( $raw, true );
        return is_array( $decoded ) ? $decoded : [];
    }

    private function find_field( array $data, array $paths ): string {
        foreach ( $paths as $path ) {
            $parts = explode( '.', $path );
            if ( count( $parts ) === 2 ) {
                $group = $parts[0];
                $field = $parts[1];
                if ( isset( $data[ $group ][0][ $field ] ) ) {
                    return (string) $data[ $group ][0][ $field ];
                }
                if ( isset( $data[ $group ][ $field ] ) ) {
                    return (string) $data[ $group ][ $field ];
                }
            }
            if ( isset( $data[ $path ] ) && is_string( $data[ $path ] ) ) {
                return $data[ $path ];
            }
        }
        return '';
    }

    private function render_data_rows( array $data, string $prefix = '' ): void {
        foreach ( $data as $key => $value ) {
            $label = $prefix ? $prefix . '.' . $key : (string) $key;
            if ( is_array( $value ) ) {
                // Repeatable row array: [ [field => val, ...], ... ]
                if ( isset( $value[0] ) && is_array( $value[0] ) ) {
                    foreach ( $value as $i => $row ) {
                        $this->render_data_rows( $row, $label . '[' . $i . ']' );
                    }
                } else {
                    $this->render_data_rows( $value, $label );
                }
            } else {
                $display = (string) $value;
                // Hide raw base64 signature – show placeholder
                if ( str_starts_with( $display, 'data:image/' ) ) {
                    $display = '[signature image]';
                }
                ?>
                <tr>
                    <td><code><?php echo esc_html( $label ); ?></code></td>
                    <td><?php echo esc_html( $display ); ?></td>
                </tr>
                <?php
            }
        }
    }
}

