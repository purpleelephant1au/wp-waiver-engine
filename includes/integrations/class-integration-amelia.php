<?php
namespace WPWE;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,PluginCheck.Security.DirectDB.UnescapedDBParameter

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
        $table = esc_sql( $wpdb->prefix . 'amelia_services' );
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
        $appt  = esc_sql( $wpdb->prefix . 'amelia_appointments' );
        $books = esc_sql( $wpdb->prefix . 'amelia_customer_bookings' );
        $svc   = esc_sql( $wpdb->prefix . 'amelia_services' );
        $users = esc_sql( $wpdb->prefix . 'amelia_users' );

        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT
                    a.id      AS appointment_id,
                    a.serviceId AS service_id,
                    a.bookingStart,
                    s.name    AS service_name,
                    u.firstName,
                    u.lastName,
                    u.email   AS customer_email
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

        $is_date   = (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $query );
        $name_like = '%' . $wpdb->esc_like( $query ) . '%';

        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if ( $is_date ) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT
                        a.id AS appointment_id,
                        a.serviceId AS service_id,
                        a.bookingStart,
                        a.status AS appointment_status,
                        s.name AS service_name,
                        u.firstName,
                        u.lastName,
                        u.email AS customer_email
                    FROM %i a
                    JOIN %i cb ON cb.appointmentId = a.id
                    JOIN %i s ON s.id = a.serviceId
                    JOIN %i u ON u.id = cb.customerId
                    WHERE a.bookingStart >= NOW()
                      AND a.status NOT IN (\'canceled\',\'rejected\')
                      AND DATE(a.bookingStart) = %s
                    ORDER BY a.bookingStart ASC
                    LIMIT 30',
                    $appt,
                    $books,
                    $svc,
                    $users,
                    $query
                )
            );
        } else {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT
                        a.id AS appointment_id,
                        a.serviceId AS service_id,
                        a.bookingStart,
                        a.status AS appointment_status,
                        s.name AS service_name,
                        u.firstName,
                        u.lastName,
                        u.email AS customer_email
                    FROM %i a
                    JOIN %i cb ON cb.appointmentId = a.id
                    JOIN %i s ON s.id = a.serviceId
                    JOIN %i u ON u.id = cb.customerId
                    WHERE a.bookingStart >= NOW()
                      AND a.status NOT IN (\'canceled\',\'rejected\')
                      AND CONCAT(u.firstName, " ", u.lastName) LIKE %s
                    ORDER BY a.bookingStart ASC
                    LIMIT 30',
                    $appt,
                    $books,
                    $svc,
                    $users,
                    $name_like
                )
            );
        }
        // phpcs:enable

        if ( ! empty( $clean_ids ) ) {
            $rows = array_values(
                array_filter(
                    is_array( $rows ) ? $rows : [],
                    static function ( $row ) use ( $clean_ids ) {
                        return in_array( (int) ( $row->service_id ?? 0 ), $clean_ids, true );
                    }
                )
            );
        }

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
        $entries = \WPWE\Database::entries_table();
        $appt    = $wpdb->prefix . 'amelia_appointments';
        $svc     = $wpdb->prefix . 'amelia_services';

        $is_asc      = strtoupper( $order ) === 'ASC';
        $offset      = max( 0, ( $paged - 1 ) * $per_page );

        $search_date = '';
        if ( '' !== $booking_search ) {
            $candidate = sanitize_text_field( $booking_search );
            if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $candidate ) ) {
                $search_date = $candidate;
            }
        }

        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $total = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*)
                FROM %i e
                LEFT JOIN %i a ON a.id = e.amelia_booking_id AND e.amelia_booking_id > 0
                LEFT JOIN %i s ON s.id = a.serviceId
                WHERE (%d = 0 OR e.template_id = %d)
                  AND (%d = 0 OR e.amelia_booking_id = %d)
                  AND (%d = 0 OR a.serviceId = %d)
                  AND (%s = "" OR DATE(a.bookingStart) = %s)',
                $entries,
                $appt,
                $svc,
                $template_id,
                $template_id,
                $booking_id,
                $booking_id,
                $amelia_service_id,
                $amelia_service_id,
                $search_date,
                $search_date
            )
        );

        if ( $is_asc ) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT e.*,
                        a.bookingStart AS booking_date,
                        a.status AS booking_status,
                        s.name AS booking_service,
                        "" AS booking_customer
                    FROM %i e
                    LEFT JOIN %i a ON a.id = e.amelia_booking_id AND e.amelia_booking_id > 0
                    LEFT JOIN %i s ON s.id = a.serviceId
                    WHERE (%d = 0 OR e.template_id = %d)
                      AND (%d = 0 OR e.amelia_booking_id = %d)
                      AND (%d = 0 OR a.serviceId = %d)
                      AND (%s = "" OR DATE(a.bookingStart) = %s)
                    ORDER BY e.id ASC
                    LIMIT %d OFFSET %d',
                    $entries,
                    $appt,
                    $svc,
                    $template_id,
                    $template_id,
                    $booking_id,
                    $booking_id,
                    $amelia_service_id,
                    $amelia_service_id,
                    $search_date,
                    $search_date,
                    $per_page,
                    $offset
                )
            ) ?: [];
        } else {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT e.*,
                        a.bookingStart AS booking_date,
                        a.status AS booking_status,
                        s.name AS booking_service,
                        "" AS booking_customer
                    FROM %i e
                    LEFT JOIN %i a ON a.id = e.amelia_booking_id AND e.amelia_booking_id > 0
                    LEFT JOIN %i s ON s.id = a.serviceId
                    WHERE (%d = 0 OR e.template_id = %d)
                      AND (%d = 0 OR e.amelia_booking_id = %d)
                      AND (%d = 0 OR a.serviceId = %d)
                      AND (%s = "" OR DATE(a.bookingStart) = %s)
                    ORDER BY e.id DESC
                    LIMIT %d OFFSET %d',
                    $entries,
                    $appt,
                    $svc,
                    $template_id,
                    $template_id,
                    $booking_id,
                    $booking_id,
                    $amelia_service_id,
                    $amelia_service_id,
                    $search_date,
                    $search_date,
                    $per_page,
                    $offset
                )
            ) ?: [];
        }
        // phpcs:enable

        return [ 'rows' => $rows, 'total' => $total ];
    }
}
