<?php
namespace WPWE;

defined( 'ABSPATH' ) || exit;

/**
 * Amelia Booking integration for WP Waiver Engine.
 *
 * Loaded only when Integration_Manager::is_amelia_active() returns true.
 * All Amelia-specific database queries are contained here; the core plugin
 * has no direct awareness of Amelia tables.
 *
 * wp_amelia_* tables are queried directly because Amelia exposes no public
 * PHP API or WP actions for read-only booking lookups at this time.
 *
 * @see Integration_Manager::is_amelia_active()
 */
class Integration_Amelia {

    /**
     * Register any WP hooks this integration needs.
     *
     * Currently all data flow is pull-based (queries initiated by
     * WP Waiver Engine on demand), so no hooks are required.
     * This method exists as a future extension point.
     */
    public function register(): void {
        // Reserved for future Amelia event hooks, e.g. appointment_created.
    }

    // -----------------------------------------------------------------------
    // Services
    // -----------------------------------------------------------------------

    /**
     * Return all visible Amelia services.
     *
     * @return array<int, array{id: int, name: string}>
     */
    public static function get_services(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'amelia_services';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results(
            "SELECT id, name FROM {$table} WHERE status = 'visible' ORDER BY name ASC",
            ARRAY_A
        );
        return is_array( $rows ) ? $rows : [];
    }

    // -----------------------------------------------------------------------
    // Booking lookup
    // -----------------------------------------------------------------------

    /**
     * Fetch a single Amelia appointment with its primary customer.
     *
     * Used for PDF naming and notification email body.
     *
     * @param  int $appointment_id  Amelia appointment ID (wp_amelia_appointments.id).
     * @return object|null
     */
    public static function get_booking( int $appointment_id ): ?object {
        global $wpdb;
        $appt  = $wpdb->prefix . 'amelia_appointments';
        $books = $wpdb->prefix . 'amelia_customer_bookings';
        $svc   = $wpdb->prefix . 'amelia_services';
        $users = $wpdb->prefix . 'amelia_users';

        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT
                    a.id      AS appointment_id,
                    a.bookingStart,
                    s.name    AS service_name,
                    u.firstName,
                    u.lastName
                 FROM {$appt} a
                 JOIN {$books} cb ON cb.appointmentId = a.id
                 JOIN {$svc}   s  ON s.id = a.serviceId
                 JOIN {$users} u  ON u.id = cb.customerId
                 WHERE a.id = %d
                 LIMIT 1",
                $appointment_id
            )
        ) ?: null;
        // phpcs:enable
    }

    // -----------------------------------------------------------------------
    // Booking search (AJAX autocomplete)
    // -----------------------------------------------------------------------

    /**
     * Search upcoming Amelia bookings by customer name fragment or exact date.
     *
     * @param  int[]  $service_ids  Restrict to these Amelia service IDs (empty = all).
     * @param  string $query        Customer name fragment or date string (YYYY-MM-DD).
     * @return object[]
     */
    public static function search_bookings( array $service_ids, string $query ): array {
        global $wpdb;
        $appt  = $wpdb->prefix . 'amelia_appointments';
        $books = $wpdb->prefix . 'amelia_customer_bookings';
        $svc   = $wpdb->prefix . 'amelia_services';
        $users = $wpdb->prefix . 'amelia_users';

        $clean_ids = array_map( 'intval', $service_ids );
        $query     = sanitize_text_field( $query );

        $svc_filter = '';
        if ( ! empty( $clean_ids ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $clean_ids ), '%d' ) );
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $svc_filter = $wpdb->prepare( " AND a.serviceId IN ( $placeholders )", ...$clean_ids );
        }

        $is_date   = (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $query );
        $name_like = '%' . $wpdb->esc_like( $query ) . '%';

        $search_clause = $is_date
            ? $wpdb->prepare( 'AND DATE(a.bookingStart) = %s', $query )
            : $wpdb->prepare( 'AND CONCAT(u.firstName, " ", u.lastName) LIKE %s', $name_like );

        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results(
            "SELECT
                a.id          AS appointment_id,
                a.bookingStart,
                a.status      AS appointment_status,
                s.name        AS service_name,
                u.firstName,
                u.lastName,
                u.email       AS customer_email
             FROM {$appt} a
             JOIN {$books} cb ON cb.appointmentId = a.id
             JOIN {$svc}   s  ON s.id = a.serviceId
             JOIN {$users} u  ON u.id = cb.customerId
             WHERE a.bookingStart >= NOW()
               AND a.status NOT IN ('canceled','rejected')
               {$svc_filter}
               {$search_clause}
             ORDER BY a.bookingStart ASC
             LIMIT 30"
        );
        // phpcs:enable

        return is_array( $rows ) ? $rows : [];
    }

    // -----------------------------------------------------------------------
    // Entries + booking data (admin list table)
    // -----------------------------------------------------------------------

    /**
     * Get entries joined with Amelia booking data for the admin list table.
     *
     * Returns the same structure as Database::get_entries() but augmented
     * with nullable columns: booking_date, booking_status, booking_service,
     * booking_customer.
     *
     * LEFT JOINs ensure entries without bookings still appear when no
     * booking-specific filters are applied.
     *
     * @param int    $per_page          Rows per page.
     * @param int    $paged             Current page (1-based).
     * @param int    $template_id       Filter by template ID (0 = all).
     * @param string $orderby           Column sort key.
     * @param string $order             'ASC' or 'DESC'.
     * @param int    $booking_id        Exact amelia_booking_id match (0 = all).
     * @param string $booking_search    Customer name fragment or YYYY-MM-DD ('' = all).
     * @param int    $amelia_service_id Filter by Amelia service ID (0 = all).
     * @return array{ rows: object[], total: int }
     */
    public static function get_entries_with_booking_data(
        int    $per_page          = 20,
        int    $paged             = 1,
        int    $template_id       = 0,
        string $orderby           = 'id',
        string $order             = 'DESC',
        int    $booking_id        = 0,
        string $booking_search    = '',
        int    $amelia_service_id = 0
    ): array {
        global $wpdb;
        $entries = Database::entries_table();
        $appt    = $wpdb->prefix . 'amelia_appointments';
        $books   = $wpdb->prefix . 'amelia_customer_bookings';
        $svc     = $wpdb->prefix . 'amelia_services';
        $users   = $wpdb->prefix . 'amelia_users';

        $allowed_orderby = [
            'id'              => 'e.id',
            'template_id'     => 'e.template_id',
            'email_sent'      => 'e.email_sent',
            'created_at'      => 'e.created_at',
            'booking_date'    => 'a.bookingStart',
            'booking_service' => 's.name',
        ];
        $orderby_sql = $allowed_orderby[ $orderby ] ?? 'e.id';
        $order_sql   = strtoupper( $order ) === 'ASC' ? 'ASC' : 'DESC';
        $offset      = max( 0, ( $paged - 1 ) * $per_page );

        $wheres = [];
        $args   = [];

        if ( $template_id > 0 ) {
            $wheres[] = 'e.template_id = %d';
            $args[]   = $template_id;
        }
        if ( $booking_id > 0 ) {
            $wheres[] = 'e.amelia_booking_id = %d';
            $args[]   = $booking_id;
        } elseif ( $booking_search !== '' ) {
            $bs      = sanitize_text_field( $booking_search );
            $is_date = (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $bs );
            if ( $is_date ) {
                $wheres[] = 'DATE(a.bookingStart) = %s';
                $args[]   = $bs;
            } else {
                $like     = '%' . $wpdb->esc_like( $bs ) . '%';
                $wheres[] = "EXISTS (
                    SELECT 1 FROM {$books} _cb
                    JOIN {$users} _u ON _u.id = _cb.customerId
                    WHERE _cb.appointmentId = e.amelia_booking_id
                    AND CONCAT(_u.firstName, ' ', _u.lastName) LIKE %s
                )";
                $args[] = $like;
            }
        }
        if ( $amelia_service_id > 0 ) {
            $wheres[] = 'a.serviceId = %d';
            $args[]   = $amelia_service_id;
        }

        $where_sql = $wheres ? 'WHERE ' . implode( ' AND ', $wheres ) : '';

        $join_sql = "LEFT JOIN {$appt} a ON a.id = e.amelia_booking_id AND e.amelia_booking_id > 0
                     LEFT JOIN {$svc}  s ON s.id = a.serviceId";

        // Correlated subquery: avoids duplicate rows for multi-person bookings.
        $customer_sq = "(SELECT CONCAT(_u.firstName, ' ', _u.lastName)
                          FROM {$books} _cb
                          JOIN {$users} _u ON _u.id = _cb.customerId
                          WHERE _cb.appointmentId = a.id
                          ORDER BY _cb.id ASC LIMIT 1)";

        $from_clause = "FROM {$entries} e {$join_sql} {$where_sql}";

        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
        $count_sql = "SELECT COUNT(*) {$from_clause}";
        $total     = $args
            ? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, ...$args ) )
            : (int) $wpdb->get_var( $count_sql );

        $select_sql = "SELECT e.*,
            a.bookingStart  AS booking_date,
            a.status        AS booking_status,
            s.name          AS booking_service,
            {$customer_sq}  AS booking_customer
            {$from_clause}
            ORDER BY {$orderby_sql} {$order_sql}
            LIMIT %d OFFSET %d";

        $limit_args = array_merge( $args, [ $per_page, $offset ] );
        $rows       = $wpdb->get_results( $wpdb->prepare( $select_sql, ...$limit_args ) ) ?: [];
        // phpcs:enable

        return [ 'rows' => $rows, 'total' => $total ];
    }
}
