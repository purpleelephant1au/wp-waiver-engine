<?php
namespace Pewave\WaiverEngine;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the paginated entry list admin page.
 * Instantiated by PeWave_Admin_Menu::page_entry_list().
 */
class PeWave_Entry_List_Table {

    public function render(): void {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only list filters.
        $amelia_enabled    = PeWave_Settings::is_amelia_enabled();
        $per_page          = 20;
        $paged             = isset( $_GET['paged'] )       ? max( 1, absint( $_GET['paged'] ) )       : 1;
        $template_id       = isset( $_GET['template_id'] ) ? absint( $_GET['template_id'] )            : 0;
        $orderby_set       = isset( $_GET['orderby'] );
        $orderby           = $orderby_set                    ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'id';
        $order_raw         = isset( $_GET['order'] ) ? sanitize_text_field( wp_unslash( $_GET['order'] ) ) : 'DESC';
        $order             = strtoupper( $order_raw ) === 'ASC' ? 'ASC' : 'DESC';

        // Amelia-specific filters (only collected when integration is enabled)
        $booking_search    = $amelia_enabled && isset( $_GET['booking_search'] )    ? sanitize_text_field( wp_unslash( $_GET['booking_search'] ) ) : '';
        $amelia_service_id = $amelia_enabled && isset( $_GET['amelia_service_id'] ) ? absint( $_GET['amelia_service_id'] )                         : 0;
        // phpcs:enable

        if ( $amelia_enabled && class_exists( PeWave_Premium_Bridge::class ) ) {
            $result = PeWave_Premium_Bridge::get_entries_with_booking_data( $per_page, $paged, $template_id, $orderby, $order, $booking_search, $amelia_service_id );
        } else {
            $result = PeWave_Database::get_entries( $per_page, $paged, $template_id, $orderby, $order );
        }
        $rows        = $result['rows'];
        $total       = $result['total'];
        $total_pages = (int) ceil( $total / $per_page );

        // Pre-fetch all templates for the filter dropdown and column display
        $templates_raw = PeWave_Database::get_templates();
        $templates     = [];
        foreach ( $templates_raw as $t ) {
            $templates[ (int) $t->id ] = $t->title;
        }

        // Amelia services for filter dropdown (only fetched when Amelia is enabled)
        $amelia_services = ( $amelia_enabled && class_exists( PeWave_Premium_Bridge::class ) )
            ? PeWave_Premium_Bridge::get_amelia_services()
            : [];

        // Build base URL carrying all active filters (orderby/order/paged are added per link)
        $active_filters = array_filter( [
            'page'              => 'pewave-entries',
            'template_id'       => $template_id       ?: null,
            'booking_search'    => $booking_search    ?: null,
            'amelia_service_id' => $amelia_service_id ?: null,
        ] );
        $base_url = add_query_arg( $active_filters, admin_url( 'admin.php' ) );

        $has_booking_filter = $amelia_enabled && ( $booking_search !== '' || $amelia_service_id > 0 );
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e( 'Waiver Entries', 'waiver-engine' ); ?></h1>
            <hr class="wp-header-end">

            <?php // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only admin notice query var. ?>
            <?php if ( isset( $_GET['pewave_deleted'] ) ) : ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Entry deleted.', 'waiver-engine' ); ?></p></div>
            <?php endif; ?>
            <?php // phpcs:enable ?>

            <!-- Filters -->
            <form method="get" style="display:flex;flex-wrap:wrap;align-items:center;gap:8px;margin:12px 0;">
                <input type="hidden" name="page" value="pewave-entries">

                <label for="pewave-filter-template"><?php esc_html_e( 'Template:', 'waiver-engine' ); ?></label>
                <select id="pewave-filter-template" name="template_id">
                    <option value="0"><?php esc_html_e( '&mdash; All &mdash;', 'waiver-engine' ); ?></option>
                    <?php foreach ( $templates as $tid => $ttitle ) : ?>
                    <option value="<?php echo esc_attr( $tid ); ?>" <?php selected( $template_id, $tid ); ?>>
                        <?php echo esc_html( $ttitle ?: '#' . $tid ); ?>
                    </option>
                    <?php endforeach; ?>
                </select>

                <?php if ( $amelia_enabled && ! empty( $amelia_services ) ) : ?>
                <label for="pewave-filter-service"><?php esc_html_e( 'Service:', 'waiver-engine' ); ?></label>
                <select id="pewave-filter-service" name="amelia_service_id">
                    <option value="0"><?php esc_html_e( '&mdash; All &mdash;', 'waiver-engine' ); ?></option>
                    <?php foreach ( $amelia_services as $svc ) : ?>
                    <option value="<?php echo esc_attr( $svc['id'] ); ?>" <?php selected( $amelia_service_id, (int) $svc['id'] ); ?>>
                        <?php echo esc_html( $svc['name'] ); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>

                <?php if ( $amelia_enabled ) : ?>
                  <label for="pewave-filter-booking"><?php esc_html_e( 'Booking:', 'waiver-engine' ); ?></label>
                <input type="text"
                      id="pewave-filter-booking"
                       name="booking_search"
                       value="<?php echo esc_attr( $booking_search ); ?>"
                       placeholder="<?php esc_attr_e( 'Customer name or date (YYYY-MM-DD)', 'waiver-engine' ); ?>"
                       style="width:260px;">
                <?php endif; ?>

                <button type="submit" class="button"><?php esc_html_e( 'Filter', 'waiver-engine' ); ?></button>

                <?php if ( $template_id || $has_booking_filter ) : ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=pewave-entries' ) ); ?>" class="button">
                    <?php esc_html_e( 'Clear', 'waiver-engine' ); ?>
                </a>
                <?php endif; ?>
            </form>

            <p><?php
            /* translators: %d: total number of entries */
            printf( esc_html__( '%d entries', 'waiver-engine' ), absint( $total ) );
            ?></p>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <?php $this->sortable_th( '#',        'id',          $orderby, $order, $base_url, $orderby_set ); ?>
                        <?php $this->sortable_th( 'Template', 'template_id', $orderby, $order, $base_url, $orderby_set ); ?>
                        <th><?php esc_html_e( 'Submitter', 'waiver-engine' ); ?></th>
                        <?php if ( $amelia_enabled ) : ?>
                        <?php $this->sortable_th( 'Service',      'booking_service', $orderby, $order, $base_url, $orderby_set ); ?>
                        <?php $this->sortable_th( 'Booking Date', 'booking_date',    $orderby, $order, $base_url, $orderby_set ); ?>
                        <th><?php esc_html_e( 'Customer', 'waiver-engine' ); ?></th>
                        <?php endif; ?>
                        <th><?php esc_html_e( 'PDFs', 'waiver-engine' ); ?></th>
                        <?php $this->sortable_th( 'Email Sent', 'email_sent', $orderby, $order, $base_url, $orderby_set ); ?>
                        <?php $this->sortable_th( 'Submitted',  'created_at', $orderby, $order, $base_url, $orderby_set ); ?>
                        <th style="width:60px"></th>
                    </tr>
                </thead>
                <tbody>
                <?php $col_count = $amelia_enabled ? 10 : 7; ?>
                <?php if ( empty( $rows ) ) : ?>
                    <tr><td colspan="<?php echo esc_attr( $col_count ); ?>"><?php esc_html_e( 'No entries found.', 'waiver-engine' ); ?></td></tr>
                <?php else : ?>
                    <?php foreach ( $rows as $entry ) : ?>
                    <?php
                        $data      = json_decode( $entry->submission_data, true ) ?: [];
                        $name      = $this->find_field( $data, [ 'signer.full_name', 'signer.name', 'full_name' ] );
                        $email     = $this->find_field( $data, [ 'signer.email', 'email' ] );
                        $tname     = $templates[ (int) $entry->template_id ] ?? '&mdash;';
                        $pdf_paths = json_decode( $entry->pdf_paths, true ) ?: [];
                        $entry_id  = (int) $entry->id;

                        // Booking columns (from LEFT JOIN – only populated when Amelia enabled)
                        $bk_service  = isset( $entry->booking_service )  ? $entry->booking_service  : null;
                        $bk_date     = isset( $entry->booking_date )      ? $entry->booking_date     : null;
                        $bk_customer = isset( $entry->booking_customer )  ? $entry->booking_customer : null;
                        $bk_id       = isset( $entry->amelia_booking_id ) ? (int) $entry->amelia_booking_id : 0;
                    ?>
                    <tr>
                        <td><?php echo esc_html( $entry_id ); ?></td>
                        <td><?php echo esc_html( $tname ); ?></td>
                        <td>
                            <?php echo esc_html( $name ?: '&mdash;' ); ?>
                            <?php if ( $email ) : ?>
                            <br><small><?php echo esc_html( $email ); ?></small>
                            <?php endif; ?>
                        </td>
                        <?php if ( $amelia_enabled ) : ?>
                        <td><?php echo $bk_service ? esc_html( $bk_service ) : '<span style="color:#aaa">&mdash;</span>'; ?></td>
                        <td>
                            <?php if ( $bk_date ) :
                                echo esc_html( wp_date( 'd/m/Y g:i a', strtotime( $bk_date ) ) );
                            else : ?>
                                <span style="color:#aaa">&mdash;</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ( $bk_customer ) : ?>
                                <?php echo esc_html( $bk_customer ); ?>
                                <?php if ( $bk_id ) : ?>
                                <br><small style="color:#6b7a8d;">#<?php echo esc_html( $bk_id ); ?></small>
                                <?php endif; ?>
                            <?php else : ?>
                                <span style="color:#aaa">&mdash;</span>
                            <?php endif; ?>
                        </td>
                        <?php endif; /* $amelia_enabled */ ?>
                        <td>
                            <?php if ( $pdf_paths ) : ?>
                                <?php foreach ( $pdf_paths as $i => $path ) :
                                    $dl_url = wp_nonce_url(
                                        admin_url( 'admin.php?page=pewave-entry&id=' . $entry_id . '&dl_pdf=' . $i ),
                                        'pewave_dl_pdf_' . $entry_id
                                    );
                                ?>
                                <a href="<?php echo esc_url( $dl_url ); ?>">PDF <?php echo esc_html( $i + 1 ); ?></a>
                                <?php endforeach; ?>
                            <?php else : ?>
                                &mdash;
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php echo $entry->email_sent
                                ? '<span style="color:green">&#10003;</span>'
                                : '<span style="color:#aaa">&mdash;</span>'; ?>
                        </td>
                        <td><?php echo esc_html( $entry->created_at ); ?></td>
                        <td>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=pewave-entry&id=' . $entry_id ) ); ?>">
                                <?php esc_html_e( 'View', 'waiver-engine' ); ?>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>

            <?php if ( $total_pages > 1 ) : ?>
            <div class="tablenav bottom">
                <div class="tablenav-pages">
                    <?php
                    echo wp_kses_post( paginate_links( [
                        'base'      => add_query_arg( 'paged', '%#%', $base_url ),
                        'format'    => '',
                        'current'   => $paged,
                        'total'     => $total_pages,
                        'prev_text' => '&laquo;',
                        'next_text' => '&raquo;',
                    ] ) );
                    ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function sortable_th( string $label, string $col, string $current_orderby, string $current_order, string $base_url, bool $orderby_explicit = true ): void {
        $new_order = ( $current_orderby === $col && $current_order === 'ASC' ) ? 'DESC' : 'ASC';
        $url       = add_query_arg( [ 'orderby' => $col, 'order' => $new_order ], $base_url );
        echo '<th scope="col"><a href="' . esc_url( $url ) . '">' . esc_html( $label );
        if ( $orderby_explicit && $current_orderby === $col ) {
            echo $current_order === 'ASC' ? ' &#9650;' : ' &#9660;';
        }
        echo '</a></th>';
    }

    private function find_field( array $data, array $paths ): string {
        foreach ( $paths as $path ) {
            $parts = explode( '.', $path );
            if ( count( $parts ) === 2 ) {
                [ $group, $field ] = $parts;
                if ( isset( $data[ $group ][0][ $field ] ) )  return (string) $data[ $group ][0][ $field ];
                if ( isset( $data[ $group ][ $field ] ) )      return (string) $data[ $group ][ $field ];
            }
            if ( isset( $data[ $path ] ) && is_scalar( $data[ $path ] ) ) return (string) $data[ $path ];
        }
        return '';
    }
}

