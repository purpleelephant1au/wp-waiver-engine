<?php
namespace WPWE;

defined( 'ABSPATH' ) || exit;

/**
 * Manages the two custom database tables:
 *
 *   {prefix}wpwe_templates  – template definitions (replaces waiver_template CPT)
 *   {prefix}wpwe_entries    – submission entries   (replaces waiver_entry CPT)
 *
 * Schema version is stored in option `wpwe_db_version`.
 * Call Database::install() on activation and on plugins_loaded (for upgrades).
 */
class Database {

    const DB_VERSION = '1.3';

    // -----------------------------------------------------------------------
    // Table name helpers
    // -----------------------------------------------------------------------

    public static function templates_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'wpwe_templates';
    }

    public static function entries_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'wpwe_entries';
    }

    // -----------------------------------------------------------------------
    // Install / Upgrade
    // -----------------------------------------------------------------------

    public static function install(): void {
        $current_version = (string) get_option( 'wpwe_db_version', '0.0' );
        if ( $current_version === self::DB_VERSION ) {
            return; // Already current – skip expensive dbDelta.
        }

        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $templates = self::templates_table();
        $entries   = self::entries_table();

        // Require dbDelta helper
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // ── Templates ───────────────────────────────────────────────────────
        // `amelia_service_ids` – JSON array of Amelia service IDs linked to this template
        dbDelta( "
CREATE TABLE $templates (
  id                  BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  title               VARCHAR(255)        NOT NULL DEFAULT '',
  description         TEXT                NOT NULL,
  field_schema        LONGTEXT            NOT NULL,
  pdf_mapping         LONGTEXT            NOT NULL,
  pdf_attachment_id   BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
  output_mode         VARCHAR(20)         NOT NULL DEFAULT 'single',
  output_group_key    VARCHAR(100)        NOT NULL DEFAULT '',
  notification_email  VARCHAR(255)        NOT NULL DEFAULT '',
  amelia_service_ids  TEXT,
  send_admin_email    TINYINT(1)          NOT NULL DEFAULT 1,
  send_user_email     TINYINT(1)          NOT NULL DEFAULT 1,
  captcha_enabled     TINYINT(1)          NOT NULL DEFAULT 0,
  active              TINYINT(1)          NOT NULL DEFAULT 1,
  created_at          DATETIME            NOT NULL DEFAULT '0000-00-00 00:00:00',
  updated_at          DATETIME            NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY (id)
) $charset_collate;
" );

        // ── Entries ─────────────────────────────────────────────────────────
        // `amelia_booking_id` – Amelia appointment ID linked to this submission (0 = none)
        dbDelta( "
CREATE TABLE $entries (
  id                 BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  template_id        BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
  amelia_booking_id  BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
  submission_data    LONGTEXT            NOT NULL,
  pdf_paths          TEXT                NOT NULL DEFAULT '',
  submitter_ip       VARCHAR(100)        NOT NULL DEFAULT '',
  email_sent         TINYINT(1)          NOT NULL DEFAULT 0,
  pdf_error          TEXT                NOT NULL DEFAULT '',
  created_at         DATETIME            NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY (id),
  KEY template_id (template_id),
  KEY created_at  (created_at)
) $charset_collate;
" );

        // ── v1.1 live-site column migrations ────────────────────────────────
        // dbDelta adds columns to new installs; existing sites need ALTER TABLE.
        if ( version_compare( $current_version, '1.1', '<' ) ) {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
            $has_services = $wpdb->get_results( "SHOW COLUMNS FROM $templates LIKE 'amelia_service_ids'" );
            if ( empty( $has_services ) ) {
                $wpdb->query( "ALTER TABLE $templates ADD COLUMN amelia_service_ids TEXT AFTER notification_email" );
            }
            $has_booking = $wpdb->get_results( "SHOW COLUMNS FROM $entries LIKE 'amelia_booking_id'" );
            if ( empty( $has_booking ) ) {
                $wpdb->query( "ALTER TABLE $entries ADD COLUMN amelia_booking_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0 AFTER template_id" );
            }
            // phpcs:enable
        }

        // ── v1.2 live-site column migrations ────────────────────────────────
        if ( version_compare( $current_version, '1.2', '<' ) ) {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
            $has_admin_email = $wpdb->get_results( "SHOW COLUMNS FROM $templates LIKE 'send_admin_email'" );
            if ( empty( $has_admin_email ) ) {
                $wpdb->query( "ALTER TABLE $templates ADD COLUMN send_admin_email TINYINT(1) NOT NULL DEFAULT 1 AFTER amelia_service_ids" );
            }
            $has_user_email = $wpdb->get_results( "SHOW COLUMNS FROM $templates LIKE 'send_user_email'" );
            if ( empty( $has_user_email ) ) {
                $wpdb->query( "ALTER TABLE $templates ADD COLUMN send_user_email TINYINT(1) NOT NULL DEFAULT 1 AFTER send_admin_email" );
            }
            // phpcs:enable
        }

        // ── v1.3 live-site column migrations ────────────────────────────────
        if ( version_compare( $current_version, '1.3', '<' ) ) {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
            $has_captcha = $wpdb->get_results( "SHOW COLUMNS FROM $templates LIKE 'captcha_enabled'" );
            if ( empty( $has_captcha ) ) {
                $wpdb->query( "ALTER TABLE $templates ADD COLUMN captcha_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER send_user_email" );
            }
            // phpcs:enable
        }

        update_option( 'wpwe_db_version', self::DB_VERSION );
    }

    // -----------------------------------------------------------------------
    // Template CRUD
    // -----------------------------------------------------------------------

    /**
     * Insert a new template row.
     *
     * @param  array $data Associative: title, description, field_schema, pdf_mapping,
     *                     pdf_attachment_id, output_mode, output_group_key,
     *                     notification_email, active
     * @return int|false  New template ID, or false on failure.
     */
    public static function insert_template( array $data ) {
        global $wpdb;
        $now = current_time( 'mysql', true );
        $result = $wpdb->insert(
            self::templates_table(),
            array_merge( self::sanitize_template_data( $data ), [
                'created_at' => $now,
                'updated_at' => $now,
            ] ),
            self::template_formats()
        );
        return $result ? (int) $wpdb->insert_id : false;
    }

    /**
     * Update an existing template row.
     *
     * @param  int   $id   Template ID.
     * @param  array $data Associative (same keys as insert_template).
     * @return bool
     */
    public static function update_template( int $id, array $data ): bool {
        global $wpdb;
        $result = $wpdb->update(
            self::templates_table(),
            array_merge( self::sanitize_template_data( $data ), [
                'updated_at' => current_time( 'mysql', true ),
            ] ),
            [ 'id' => $id ],
            self::template_formats(),
            [ '%d' ]
        );
        return $result !== false;
    }

    /**
     * Get a single template by ID.
     *
     * @param  int        $id
     * @return object|null  stdClass row or null.
     */
    public static function get_template( int $id ): ?object {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
        return $wpdb->get_row( $wpdb->prepare(
            'SELECT * FROM ' . self::templates_table() . ' WHERE id = %d', // phpcs:ignore
            $id
        ) ) ?: null;
    }

    /**
     * Get all templates, optionally filtered.
     *
     * @param  bool $active_only  When true, only `active = 1` rows.
     * @return object[]
     */
    public static function get_templates( bool $active_only = false ): array {
        global $wpdb;
        $where = $active_only ? ' WHERE active = 1' : '';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
        return $wpdb->get_results(
            'SELECT * FROM ' . self::templates_table() . $where . ' ORDER BY id ASC' // phpcs:ignore
        ) ?: [];
    }

    /**
     * Get total template count.
     */
    public static function get_templates_count( bool $active_only = false ): int {
        global $wpdb;
        $where = $active_only ? ' WHERE active = 1' : '';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
        return (int) $wpdb->get_var(
            'SELECT COUNT(*) FROM ' . self::templates_table() . $where // phpcs:ignore
        );
    }

    /**
     * Delete a template (and optionally its entries).
     *
     * @param int  $id
     * @param bool $cascade  When true, also removes all entries for this template.
     */
    public static function delete_template( int $id, bool $cascade = false ): void {
        global $wpdb;
        if ( $cascade ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->delete( self::entries_table(), [ 'template_id' => $id ], [ '%d' ] );
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->delete( self::templates_table(), [ 'id' => $id ], [ '%d' ] );
    }

    // -----------------------------------------------------------------------
    // Entry CRUD
    // -----------------------------------------------------------------------

    /**
     * Insert a new submission entry.
     *
     * @param  int    $template_id
     * @param  string $submission_data  JSON-encoded data.
     * @param  string $submitter_ip
     * @param  int    $amelia_booking_id  Optional Amelia appointment ID (0 = none).
     * @return int|false  New entry ID, or false on failure.
     */
    public static function insert_entry( int $template_id, string $submission_data, string $submitter_ip, int $amelia_booking_id = 0 ) {
        global $wpdb;
        $result = $wpdb->insert(
            self::entries_table(),
            [
                'template_id'       => $template_id,
                'amelia_booking_id' => $amelia_booking_id,
                'submission_data'   => $submission_data,
                'pdf_paths'         => '',
                'submitter_ip'      => $submitter_ip,
                'email_sent'        => 0,
                'pdf_error'         => '',
                'created_at'        => current_time( 'mysql', true ),
            ],
            [ '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s' ]
        );
        return $result ? (int) $wpdb->insert_id : false;
    }

    /**
     * Update entry after PDF generation and email send.
     */
    public static function update_entry_after_process( int $id, array $pdf_paths, bool $email_sent, string $pdf_error = '' ): void {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->update(
            self::entries_table(),
            [
                'pdf_paths'  => wp_json_encode( $pdf_paths ),
                'email_sent' => $email_sent ? 1 : 0,
                'pdf_error'  => $pdf_error,
            ],
            [ 'id' => $id ],
            [ '%s', '%d', '%s' ],
            [ '%d' ]
        );
    }

    /**
     * Get a single entry.
     */
    public static function get_entry( int $id ): ?object {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
        return $wpdb->get_row( $wpdb->prepare(
            'SELECT * FROM ' . self::entries_table() . ' WHERE id = %d', // phpcs:ignore
            $id
        ) ) ?: null;
    }

    /**
     * Get entries for the list table, with pagination and optional template filter.
     *
     * @return array{ rows: object[], total: int }
     */
    public static function get_entries( int $per_page = 20, int $paged = 1, int $template_id = 0, string $orderby = 'id', string $order = 'DESC' ): array {
        global $wpdb;

        $allowed_orderby = [ 'id', 'template_id', 'email_sent', 'created_at' ];
        $orderby = in_array( $orderby, $allowed_orderby, true ) ? $orderby : 'id';
        $order   = strtoupper( $order ) === 'ASC' ? 'ASC' : 'DESC';
        $offset  = max( 0, ( $paged - 1 ) * $per_page );

        $table = self::entries_table();
        $where = '';
        $args  = [];

        if ( $template_id > 0 ) {
            $where  = ' WHERE template_id = %d';
            $args[] = $template_id;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
        $total = (int) $wpdb->get_var( $wpdb->prepare(
            'SELECT COUNT(*) FROM ' . $table . $where, // phpcs:ignore
            ...$args
        ) );

        $args[] = $per_page;
        $args[] = $offset;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare(
            'SELECT * FROM ' . $table . $where . ' ORDER BY ' . $orderby . ' ' . $order . ' LIMIT %d OFFSET %d', // phpcs:ignore
            ...$args
        ) ) ?: [];

        return [ 'rows' => $rows, 'total' => $total ];
    }

    /**
     * Delete an entry.
     */
    public static function delete_entry( int $id ): void {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->delete( self::entries_table(), [ 'id' => $id ], [ '%d' ] );
    }

    // -----------------------------------------------------------------------
    // Full data export / import (migration tooling)
    // -----------------------------------------------------------------------

    /**
     * Export templates, entries, and key plugin options into a portable package.
     */
    public static function export_data_package(): array {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
        $templates = $wpdb->get_results( 'SELECT * FROM ' . self::templates_table() . ' ORDER BY id ASC', ARRAY_A ) ?: [];
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
        $entries   = $wpdb->get_results( 'SELECT * FROM ' . self::entries_table() . ' ORDER BY id ASC', ARRAY_A ) ?: [];

        $option_keys = [
            'wpwe_db_version',
            'wpwe_amelia_enabled',
            'wpwe_admin_email_enabled',
            'wpwe_user_email_enabled',
            'wpwe_rate_limit_enabled',
            'wpwe_rate_limit_max',
            'wpwe_rate_limit_window',
            'wpwe_pdf_retention_days',
            'wpwe_captcha_provider',
            'wpwe_captcha_site_key',
            'wpwe_captcha_secret_key',
        ];

        $options = [];
        foreach ( $option_keys as $key ) {
            $options[ $key ] = get_option( $key, null );
        }

        return [
            'export_version' => 1,
            'generated_at'   => gmdate( 'c' ),
            'plugin'         => [
                'slug'    => 'wp-waiver-engine',
                'version' => defined( 'WPWE_VERSION' ) ? WPWE_VERSION : '',
            ],
            'tables'         => [
                'templates' => $templates,
                'entries'   => $entries,
            ],
            'options'        => $options,
        ];
    }

    /**
     * Import a package created by export_data_package().
     *
     * @return array{templates:int,entries:int,options:int}
     */
    public static function import_data_package( array $package, bool $replace_existing = false ): array {
        global $wpdb;

        $templates = isset( $package['tables']['templates'] ) && is_array( $package['tables']['templates'] )
            ? $package['tables']['templates']
            : [];
        $entries = isset( $package['tables']['entries'] ) && is_array( $package['tables']['entries'] )
            ? $package['tables']['entries']
            : [];
        $options = isset( $package['options'] ) && is_array( $package['options'] )
            ? $package['options']
            : [];

        if ( $replace_existing ) {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
            $wpdb->query( 'DELETE FROM ' . self::entries_table() );
            $wpdb->query( 'DELETE FROM ' . self::templates_table() );
            // phpcs:enable
        }

        $templates_imported = 0;
        foreach ( $templates as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }

            $sanitized = self::sanitize_template_data( $row );
            $payload   = [
                'id'                 => absint( $row['id'] ?? 0 ),
                'title'              => $sanitized['title'],
                'description'        => $sanitized['description'],
                'field_schema'       => (string) $sanitized['field_schema'],
                'pdf_mapping'        => (string) $sanitized['pdf_mapping'],
                'pdf_attachment_id'  => (int) $sanitized['pdf_attachment_id'],
                'output_mode'        => (string) $sanitized['output_mode'],
                'output_group_key'   => (string) $sanitized['output_group_key'],
                'notification_email' => (string) $sanitized['notification_email'],
                'amelia_service_ids' => (string) $sanitized['amelia_service_ids'],
                'send_admin_email'   => (int) $sanitized['send_admin_email'],
                'send_user_email'    => (int) $sanitized['send_user_email'],
                'captcha_enabled'    => (int) $sanitized['captcha_enabled'],
                'active'             => (int) $sanitized['active'],
                'created_at'         => sanitize_text_field( (string) ( $row['created_at'] ?? current_time( 'mysql', true ) ) ),
                'updated_at'         => sanitize_text_field( (string) ( $row['updated_at'] ?? current_time( 'mysql', true ) ) ),
            ];

            if ( $payload['id'] <= 0 ) {
                unset( $payload['id'] );
                $ok = $wpdb->insert( self::templates_table(), $payload ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            } else {
                $ok = $wpdb->replace( self::templates_table(), $payload ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            }

            if ( false !== $ok ) {
                $templates_imported++;
            }
        }

        $entries_imported = 0;
        foreach ( $entries as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }

            $payload = [
                'id'                => absint( $row['id'] ?? 0 ),
                'template_id'       => absint( $row['template_id'] ?? 0 ),
                'amelia_booking_id' => absint( $row['amelia_booking_id'] ?? 0 ),
                'submission_data'   => (string) ( $row['submission_data'] ?? '' ),
                'pdf_paths'         => (string) ( $row['pdf_paths'] ?? '' ),
                'submitter_ip'      => sanitize_text_field( (string) ( $row['submitter_ip'] ?? '' ) ),
                'email_sent'        => ! empty( $row['email_sent'] ) ? 1 : 0,
                'pdf_error'         => sanitize_textarea_field( (string) ( $row['pdf_error'] ?? '' ) ),
                'created_at'        => sanitize_text_field( (string) ( $row['created_at'] ?? current_time( 'mysql', true ) ) ),
            ];

            if ( $payload['template_id'] <= 0 ) {
                continue;
            }

            if ( $payload['id'] <= 0 ) {
                unset( $payload['id'] );
                $ok = $wpdb->insert( self::entries_table(), $payload ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            } else {
                $ok = $wpdb->replace( self::entries_table(), $payload ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            }

            if ( false !== $ok ) {
                $entries_imported++;
            }
        }

        $options_imported = 0;
        $allowed_options  = [
            'wpwe_amelia_enabled',
            'wpwe_admin_email_enabled',
            'wpwe_user_email_enabled',
            'wpwe_rate_limit_enabled',
            'wpwe_rate_limit_max',
            'wpwe_rate_limit_window',
            'wpwe_pdf_retention_days',
            'wpwe_captcha_provider',
            'wpwe_captcha_site_key',
            'wpwe_captcha_secret_key',
        ];
        foreach ( $allowed_options as $key ) {
            if ( array_key_exists( $key, $options ) ) {
                update_option( $key, $options[ $key ] );
                $options_imported++;
            }
        }

        return [
            'templates' => $templates_imported,
            'entries'   => $entries_imported,
            'options'   => $options_imported,
        ];
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    private static function sanitize_template_data( array $data ): array {
        // amelia_service_ids: array of ints, stored as JSON.
        $raw_ids = $data['amelia_service_ids'] ?? [];
        if ( is_string( $raw_ids ) ) {
            $raw_ids = json_decode( $raw_ids, true ) ?: [];
        }
        $service_ids = array_values( array_map( 'absint', (array) $raw_ids ) );

        return [
            'title'              => sanitize_text_field( $data['title']              ?? '' ),
            'description'        => sanitize_textarea_field( $data['description']    ?? '' ),
            'field_schema'       => $data['field_schema']                            ?? '',
            'pdf_mapping'        => $data['pdf_mapping']                             ?? '',
            'pdf_attachment_id'  => absint( $data['pdf_attachment_id']               ?? 0 ),
            'output_mode'        => in_array( $data['output_mode'] ?? '', [ 'single', 'per_row' ], true ) ? $data['output_mode'] : 'single',
            'output_group_key'   => sanitize_key( $data['output_group_key']          ?? '' ),
            'notification_email' => sanitize_email( $data['notification_email']      ?? '' ),
            'amelia_service_ids' => wp_json_encode( $service_ids ),
            'send_admin_email'   => array_key_exists( 'send_admin_email', $data ) ? ( $data['send_admin_email'] ? 1 : 0 ) : 1,
            'send_user_email'    => array_key_exists( 'send_user_email', $data )  ? ( $data['send_user_email']  ? 1 : 0 ) : 1,
            'captcha_enabled'    => array_key_exists( 'captcha_enabled', $data )  ? ( $data['captcha_enabled']  ? 1 : 0 ) : 0,
            'active'             => empty( $data['active'] ) ? 0 : 1,
        ];
    }

    private static function template_formats(): array {
        return [ '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s' ];
    }
}
