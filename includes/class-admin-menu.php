<?php
namespace WPWE;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the plugin's top-level admin menu and sub-pages.
 *
 * Menu structure:
 *   Waivers                     (top-level, slug: wpwe)
 *   ├─ Templates                (sub: wpwe)          → template list
 *   └─ Entries                  (sub: wpwe-entries)   → entry list
 *
 * Edit-template page is accessed via `?page=wpwe-edit&id=N` (hidden from menu).
 * View-entry page is accessed via `?page=wpwe-entry&id=N` (hidden from menu).
 */
class Admin_Menu {

    public function register(): void {
        add_action( 'admin_menu', [ $this, 'add_menu' ] );
        // Redirect the bare top-level slug to the template list
        add_action( 'admin_init', [ $this, 'handle_actions' ] );
    }

    // -----------------------------------------------------------------------
    // Menu registration
    // -----------------------------------------------------------------------

    public function add_menu(): void {
        add_menu_page(
            __( 'Waivers', 'wp-waiver-engine' ),
            __( 'Waivers', 'wp-waiver-engine' ),
            'manage_options',
            'wpwe',
            [ $this, 'page_template_list' ],
            'dashicons-media-document',
            30
        );

        add_submenu_page(
            'wpwe',
            __( 'Templates', 'wp-waiver-engine' ),
            __( 'Templates', 'wp-waiver-engine' ),
            'manage_options',
            'wpwe',
            [ $this, 'page_template_list' ]
        );

        // Add New — hidden from sidebar; accessed via the button in the template list
        add_submenu_page(
            null,
            __( 'Add New Template', 'wp-waiver-engine' ),
            __( 'Add New', 'wp-waiver-engine' ),
            'manage_options',
            'wpwe-new',
            [ $this, 'page_template_edit' ]
        );

        // Edit page — hidden (no parent), accessed via ?page=wpwe-edit&id=N
        add_submenu_page(
            null,
            __( 'Edit Template', 'wp-waiver-engine' ),
            __( 'Edit Template', 'wp-waiver-engine' ),
            'manage_options',
            'wpwe-edit',
            [ $this, 'page_template_edit' ]
        );

        add_submenu_page(
            'wpwe',
            __( 'Entries', 'wp-waiver-engine' ),
            __( 'Entries', 'wp-waiver-engine' ),
            'manage_options',
            'wpwe-entries',
            [ $this, 'page_entry_list' ]
        );

        // Entry detail — hidden
        add_submenu_page(
            null,
            __( 'View Entry', 'wp-waiver-engine' ),
            __( 'View Entry', 'wp-waiver-engine' ),
            'manage_options',
            'wpwe-entry',
            [ $this, 'page_entry_detail' ]
        );

        add_submenu_page(
            'wpwe',
            __( 'Settings', 'wp-waiver-engine' ),
            __( 'Settings', 'wp-waiver-engine' ),
            'manage_options',
            'wpwe-settings',
            [ $this, 'page_settings' ]
        );
    }

    // -----------------------------------------------------------------------
    // POST action handling (save, delete, etc.) – runs before output
    // -----------------------------------------------------------------------

    public function handle_actions(): void {
        $page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
        if ( ! in_array( $page, [ 'wpwe', 'wpwe-new', 'wpwe-edit', 'wpwe-entries', 'wpwe-entry', 'wpwe-settings' ], true ) ) {
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $action = isset( $_REQUEST['wpwe_action'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['wpwe_action'] ) ) : '';

        // PDF serve (download or inline view) must be handled here (before any HTML output)
        if ( $page === 'wpwe-entry' && isset( $_GET['dl_pdf'] ) ) {
            $this->action_serve_pdf( false );
        }
        if ( $page === 'wpwe-entry' && isset( $_GET['view_pdf'] ) ) {
            $this->action_serve_pdf( true );
        }

        switch ( $action ) {
            case 'save_template':
                $this->action_save_template();
                break;
            case 'delete_template':
                $this->action_delete_template();
                break;
            case 'delete_entry':
                $this->action_delete_entry();
                break;
            case 'save_settings':
                $this->action_save_settings();
                break;
            case 'cleanup_pdfs':
                $this->action_cleanup_pdfs();
                break;
            case 'export_mappings':
                $this->action_export_mappings();
                break;
            case 'import_mappings':
                $this->action_import_mappings();
                break;
        }
    }

    // -----------------------------------------------------------------------
    // Action: serve PDF (inline view or forced download)
    // -----------------------------------------------------------------------

    private function action_serve_pdf( bool $inline ): void {
        $entry_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
        if ( ! $entry_id ) {
            wp_die( esc_html__( 'Invalid entry.', 'wp-waiver-engine' ) );
        }

        // Both view_pdf and dl_pdf use the same nonce key so links can be shared
        $idx = $inline ? absint( $_GET['view_pdf'] ) : absint( $_GET['dl_pdf'] );
        check_admin_referer( 'wpwe_dl_pdf_' . $entry_id );

        $entry = Database::get_entry( $entry_id );
        if ( ! $entry ) {
            wp_die( esc_html__( 'Entry not found.', 'wp-waiver-engine' ) );
        }

        $pdf_paths = json_decode( $entry->pdf_paths, true ) ?: [];
        $path      = $pdf_paths[ $idx ] ?? '';

        if ( $path && file_exists( $path ) ) {
            if ( ob_get_level() ) {
                ob_end_clean();
            }
            $fname       = sanitize_file_name( basename( $path ) );
            $disposition = $inline ? 'inline' : 'attachment';
            header( 'Content-Type: application/pdf' );
            header( 'Content-Disposition: ' . $disposition . '; filename="' . $fname . '"' );
            header( 'Content-Length: ' . filesize( $path ) );
            header( 'Cache-Control: private, no-cache' );
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
            readfile( $path );
            exit;
        }

        wp_die( esc_html__( 'PDF file not found on disk.', 'wp-waiver-engine' ) );
    }

    // -----------------------------------------------------------------------
    // Action: save template
    // -----------------------------------------------------------------------

    private function action_save_template(): void {
        check_admin_referer( 'wpwe_save_template' );

        $id = isset( $_POST['wpwe_template_id'] ) ? absint( $_POST['wpwe_template_id'] ) : 0;

        // Build both pdf_mapping and field_schema from the unified table POST data
        [ $mapping, $schema ] = Template_Editor::build_from_post();

        $raw_amelia_ids = isset( $_POST['wpwe_amelia_service_ids'] ) && is_array( $_POST['wpwe_amelia_service_ids'] )
            ? array_map( 'absint', $_POST['wpwe_amelia_service_ids'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
            : [];

        $data = [
            'title'              => isset( $_POST['wpwe_title'] ) ? sanitize_text_field( wp_unslash( $_POST['wpwe_title'] ) ) : '',
            'description'        => isset( $_POST['wpwe_description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['wpwe_description'] ) ) : '',
            'pdf_attachment_id'  => isset( $_POST['wpwe_pdf_attachment_id'] ) ? absint( $_POST['wpwe_pdf_attachment_id'] ) : 0,
            'output_mode'        => isset( $_POST['wpwe_output_mode'] ) ? sanitize_text_field( wp_unslash( $_POST['wpwe_output_mode'] ) ) : 'single',
            'output_group_key'   => isset( $_POST['wpwe_output_group_key'] ) ? sanitize_key( wp_unslash( $_POST['wpwe_output_group_key'] ) ) : '',
            'notification_email' => isset( $_POST['wpwe_notification_email'] ) ? sanitize_email( wp_unslash( $_POST['wpwe_notification_email'] ) ) : '',
            'send_admin_email'   => ! empty( $_POST['wpwe_send_admin_email'] ) ? 1 : 0,
            'send_user_email'    => ! empty( $_POST['wpwe_send_user_email'] )  ? 1 : 0,
            'amelia_service_ids' => array_values( $raw_amelia_ids ),
            'active'             => ! empty( $_POST['wpwe_active'] ) ? 1 : 0,
            'field_schema'       => wp_json_encode( $schema ),
            'pdf_mapping'        => wp_json_encode( $mapping ),
        ];

        if ( $id > 0 ) {
            Database::update_template( $id, $data );
            wp_safe_redirect( admin_url( 'admin.php?page=wpwe-edit&id=' . $id . '&wpwe_saved=1' ) );
        } else {
            $new_id = Database::insert_template( $data );
            wp_safe_redirect( admin_url( 'admin.php?page=wpwe-edit&id=' . (int) $new_id . '&wpwe_saved=1' ) );
        }
        exit;
    }

    // -----------------------------------------------------------------------
    // Action: delete template
    // -----------------------------------------------------------------------

    private function action_delete_template(): void {
        $id = isset( $_REQUEST['id'] ) ? absint( $_REQUEST['id'] ) : 0;
        if ( ! $id ) {
            return;
        }
        check_admin_referer( 'wpwe_delete_template_' . $id );
        Database::delete_template( $id, /* cascade entries */ false );
        wp_safe_redirect( admin_url( 'admin.php?page=wpwe&wpwe_deleted=1' ) );
        exit;
    }

    // -----------------------------------------------------------------------
    // Action: delete entry
    // -----------------------------------------------------------------------

    private function action_delete_entry(): void {
        $id = isset( $_REQUEST['id'] ) ? absint( $_REQUEST['id'] ) : 0;
        if ( ! $id ) {
            return;
        }
        check_admin_referer( 'wpwe_delete_entry_' . $id );
        Database::delete_entry( $id );
        wp_safe_redirect( admin_url( 'admin.php?page=wpwe-entries&wpwe_deleted=1' ) );
        exit;
    }

    // -----------------------------------------------------------------------
    // Page: Template List
    // -----------------------------------------------------------------------

    public function page_template_list(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Access denied.', 'wp-waiver-engine' ) );
        }
        $templates = Database::get_templates();
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e( 'Waiver Templates', 'wp-waiver-engine' ); ?></h1>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=wpwe-new' ) ); ?>" class="page-title-action">
                <?php esc_html_e( 'Add New', 'wp-waiver-engine' ); ?>
            </a>
            <hr class="wp-header-end">

            <?php if ( isset( $_GET['wpwe_saved'] ) ) : ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Template saved.', 'wp-waiver-engine' ); ?></p></div>
            <?php endif; ?>
            <?php if ( isset( $_GET['wpwe_deleted'] ) ) : ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Template deleted.', 'wp-waiver-engine' ); ?></p></div>
            <?php endif; ?>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th scope="col" style="width:60px"><?php esc_html_e( 'ID', 'wp-waiver-engine' ); ?></th>
                        <th scope="col"><?php esc_html_e( 'Title', 'wp-waiver-engine' ); ?></th>
                        <th scope="col" style="width:80px"><?php esc_html_e( 'Active', 'wp-waiver-engine' ); ?></th>
                        <th scope="col" style="width:120px"><?php esc_html_e( 'Shortcode', 'wp-waiver-engine' ); ?></th>
                        <th scope="col" style="width:160px"><?php esc_html_e( 'Last Updated', 'wp-waiver-engine' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( empty( $templates ) ) : ?>
                    <tr><td colspan="5"><?php esc_html_e( 'No templates found.', 'wp-waiver-engine' ); ?></td></tr>
                <?php else : ?>
                    <?php foreach ( $templates as $tpl ) : ?>
                    <tr>
                        <td><?php echo esc_html( $tpl->id ); ?></td>
                        <td>
                            <strong>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wpwe-edit&id=' . $tpl->id ) ); ?>">
                                    <?php echo esc_html( $tpl->title ?: __( '(no title)', 'wp-waiver-engine' ) ); ?>
                                </a>
                            </strong>
                            <div class="row-actions">
                                <span class="edit">
                                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=wpwe-edit&id=' . $tpl->id ) ); ?>">
                                        <?php esc_html_e( 'Edit', 'wp-waiver-engine' ); ?>
                                    </a>
                                </span>
                                &nbsp;|&nbsp;
                                <span class="trash">
                                    <a href="<?php echo esc_url( wp_nonce_url(
                                        admin_url( 'admin.php?page=wpwe&wpwe_action=delete_template&id=' . $tpl->id ),
                                        'wpwe_delete_template_' . $tpl->id
                                    ) ); ?>"
                                    onclick="return confirm('<?php esc_attr_e( 'Delete this template?', 'wp-waiver-engine' ); ?>')"
                                    style="color:#d63638;">
                                        <?php esc_html_e( 'Delete', 'wp-waiver-engine' ); ?>
                                    </a>
                                </span>
                            </div>
                        </td>
                        <td>
                            <?php echo $tpl->active
                                ? '<span style="color:green">&#10003;</span>'
                                : '<span style="color:#aaa">—</span>'; ?>
                        </td>
                        <td>
                            <code>[waiver_form id="<?php echo esc_attr( $tpl->id ); ?>"]</code>
                        </td>
                        <td><?php echo esc_html( $tpl->updated_at ?: $tpl->created_at ); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    // -----------------------------------------------------------------------
    // Page: Template Edit / New
    // -----------------------------------------------------------------------

    public function page_template_edit(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Access denied.', 'wp-waiver-engine' ) );
        }
        $id  = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
        $tpl = $id ? Database::get_template( $id ) : null;

        ( new Template_Editor( $tpl ) )->render();
    }

    // -----------------------------------------------------------------------
    // Page: Entry List
    // -----------------------------------------------------------------------

    public function page_entry_list(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Access denied.', 'wp-waiver-engine' ) );
        }
        ( new Entry_List_Table() )->render();
    }

    // -----------------------------------------------------------------------
    // Page: Entry Detail
    // -----------------------------------------------------------------------

    public function page_entry_detail(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Access denied.', 'wp-waiver-engine' ) );
        }
        $id    = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
        $entry = $id ? Database::get_entry( $id ) : null;
        if ( ! $entry ) {
            wp_die( esc_html__( 'Entry not found.', 'wp-waiver-engine' ) );
        }
        ( new Entry_Detail( $entry ) )->render();
    }

    // -----------------------------------------------------------------------
    // Page: Settings
    // -----------------------------------------------------------------------

    public function page_settings(): void {
        ( new Settings() )->render();
    }

    // -----------------------------------------------------------------------
    // Action: save settings
    // -----------------------------------------------------------------------

    private function action_save_settings(): void {
        check_admin_referer( 'wpwe_save_settings' );
        update_option( 'wpwe_amelia_enabled',      ! empty( $_POST['wpwe_amelia_enabled'] )      ? '1' : '' );
        update_option( 'wpwe_admin_email_enabled', ! empty( $_POST['wpwe_admin_email_enabled'] ) ? '1' : '' );
        update_option( 'wpwe_user_email_enabled',  ! empty( $_POST['wpwe_user_email_enabled'] )  ? '1' : '' );
        update_option( 'wpwe_rate_limit_enabled',  ! empty( $_POST['wpwe_rate_limit_enabled'] )  ? '1' : '' );
        update_option( 'wpwe_rate_limit_max',    max( 1, (int) ( $_POST['wpwe_rate_limit_max']    ?? 5  ) ) );
        update_option( 'wpwe_rate_limit_window', max( 1, (int) ( $_POST['wpwe_rate_limit_window'] ?? 15 ) ) );
        update_option( 'wpwe_pdf_retention_days', max( 1, (int) ( $_POST['wpwe_pdf_retention_days'] ?? 90 ) ) );
        wp_safe_redirect( admin_url( 'admin.php?page=wpwe-settings&wpwe_settings_saved=1' ) );
        exit;
    }

    // -----------------------------------------------------------------------
    // Action: cleanup old PDF files
    // -----------------------------------------------------------------------

    private function action_cleanup_pdfs(): void {
        check_admin_referer( 'wpwe_cleanup_pdfs' );

        // Save chosen retention days before running cleanup.
        $retention_days = max( 1, (int) ( $_POST['wpwe_pdf_retention_days'] ?? 90 ) );
        update_option( 'wpwe_pdf_retention_days', $retention_days );

        $upload_dir = wp_upload_dir();
        $pdf_dir    = $upload_dir['basedir'] . '/wpwe-pdfs';
        $cutoff     = time() - ( $retention_days * DAY_IN_SECONDS );
        $deleted    = 0;

        if ( is_dir( $pdf_dir ) ) {
            foreach ( new \DirectoryIterator( $pdf_dir ) as $file ) {
                if ( $file->isFile() && $file->getMTime() < $cutoff ) {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
                    if ( @unlink( $file->getPathname() ) ) {
                        $deleted++;
                    }
                }
            }
        }

        wp_safe_redirect( admin_url( 'admin.php?page=wpwe-settings&wpwe_cleanup_done=' . $deleted ) );
        exit;
    }

    // -----------------------------------------------------------------------
    // Action: export template field mappings as JSON
    // -----------------------------------------------------------------------

    private function action_export_mappings(): void {
        $id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
        if ( ! $id ) {
            wp_die( esc_html__( 'Invalid template.', 'wp-waiver-engine' ) );
        }
        check_admin_referer( 'wpwe_export_mappings_' . $id );

        $tpl = Database::get_template( $id );
        if ( ! $tpl ) {
            wp_die( esc_html__( 'Template not found.', 'wp-waiver-engine' ) );
        }

        $payload = [
            'export_version' => 1,
            'template_title' => $tpl->title,
            'field_schema'   => json_decode( (string) $tpl->field_schema, true ) ?: (object) [],
            'pdf_mapping'    => json_decode( (string) $tpl->pdf_mapping,  true ) ?: (object) [],
        ];

        if ( ob_get_level() ) {
            ob_end_clean();
        }
        $filename = 'waiver-mappings-' . sanitize_title( $tpl->title ) . '-' . gmdate( 'Y-m-d' ) . '.json';
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Cache-Control: no-cache, no-store, must-revalidate' );
        header( 'Pragma: no-cache' );
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        exit;
    }

    // -----------------------------------------------------------------------
    // Action: import template field mappings from JSON upload
    // -----------------------------------------------------------------------

    private function action_import_mappings(): void {
        check_admin_referer( 'wpwe_import_mappings' );

        $id = isset( $_POST['wpwe_template_id'] ) ? absint( $_POST['wpwe_template_id'] ) : 0;
        if ( ! $id ) {
            wp_die( esc_html__( 'Invalid template.', 'wp-waiver-engine' ) );
        }

        // Validate file upload.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        if ( ! isset( $_FILES['wpwe_import_file'] ) || (int) $_FILES['wpwe_import_file']['error'] !== UPLOAD_ERR_OK ) {
            wp_safe_redirect( admin_url( 'admin.php?page=wpwe-edit&id=' . $id . '&wpwe_import_error=1' ) );
            exit;
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $tmp_path = (string) $_FILES['wpwe_import_file']['tmp_name'];
        if ( ! is_uploaded_file( $tmp_path ) ) {
            wp_safe_redirect( admin_url( 'admin.php?page=wpwe-edit&id=' . $id . '&wpwe_import_error=1' ) );
            exit;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $json_str = file_get_contents( $tmp_path );
        if ( $json_str === false ) {
            wp_safe_redirect( admin_url( 'admin.php?page=wpwe-edit&id=' . $id . '&wpwe_import_error=2' ) );
            exit;
        }

        $decoded = json_decode( $json_str, true );
        if ( ! is_array( $decoded )
             || ! array_key_exists( 'field_schema', $decoded )
             || ! array_key_exists( 'pdf_mapping', $decoded ) ) {
            wp_safe_redirect( admin_url( 'admin.php?page=wpwe-edit&id=' . $id . '&wpwe_import_error=3' ) );
            exit;
        }

        // Fetch existing template to preserve all other fields (title, email, etc.).
        $tpl = Database::get_template( $id );
        if ( ! $tpl ) {
            wp_die( esc_html__( 'Template not found.', 'wp-waiver-engine' ) );
        }

        $data                 = (array) $tpl;
        $data['field_schema'] = wp_json_encode( $decoded['field_schema'] );
        $data['pdf_mapping']  = wp_json_encode( $decoded['pdf_mapping'] );

        Database::update_template( $id, $data );

        wp_safe_redirect( admin_url( 'admin.php?page=wpwe-edit&id=' . $id . '&wpwe_imported=1' ) );
        exit;
    }
}
