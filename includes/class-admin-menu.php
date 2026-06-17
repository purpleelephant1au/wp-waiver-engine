<?php
namespace WPWE;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the plugin's top-level admin menu and sub-pages.
 *
 * Menu structure:
 *   Waivers                     (top-level, slug: wpwe)
 *   |- Templates                (sub: wpwe)          → template list
 *   `- Entries                  (sub: wpwe-entries)   → entry list
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
            __( 'Waivers', 'waiver-engine' ),
            __( 'Waivers', 'waiver-engine' ),
            'manage_options',
            'wpwe',
            [ $this, 'page_template_list' ],
            'dashicons-media-document',
            30
        );

        add_submenu_page(
            'wpwe',
            __( 'Templates', 'waiver-engine' ),
            __( 'Templates', 'waiver-engine' ),
            'manage_options',
            'wpwe',
            [ $this, 'page_template_list' ]
        );

        // Add New — hidden from sidebar; accessed via the button in the template list
        add_submenu_page(
            null,
            __( 'Add New Template', 'waiver-engine' ),
            __( 'Add New', 'waiver-engine' ),
            'manage_options',
            'wpwe-new',
            [ $this, 'page_template_edit' ]
        );

        // Edit page — hidden (no parent), accessed via ?page=wpwe-edit&id=N
        add_submenu_page(
            null,
            __( 'Edit Template', 'waiver-engine' ),
            __( 'Edit Template', 'waiver-engine' ),
            'manage_options',
            'wpwe-edit',
            [ $this, 'page_template_edit' ]
        );

        add_submenu_page(
            'wpwe',
            __( 'Entries', 'waiver-engine' ),
            __( 'Entries', 'waiver-engine' ),
            'manage_options',
            'wpwe-entries',
            [ $this, 'page_entry_list' ]
        );

        // Entry detail — hidden
        add_submenu_page(
            null,
            __( 'View Entry', 'waiver-engine' ),
            __( 'View Entry', 'waiver-engine' ),
            'manage_options',
            'wpwe-entry',
            [ $this, 'page_entry_detail' ]
        );

        add_submenu_page(
            'wpwe',
            __( 'Settings', 'waiver-engine' ),
            __( 'Settings', 'waiver-engine' ),
            'manage_options',
            'wpwe-settings',
            [ $this, 'page_settings' ]
        );
    }

    // -----------------------------------------------------------------------
    // POST action handling (save, delete, etc.) – runs before output
    // -----------------------------------------------------------------------

    public function handle_actions(): void {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only routing values.
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
        // phpcs:enable

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
            case 'export_all_data':
                $this->action_export_all_data();
                break;
            case 'import_all_data':
                $this->action_import_all_data();
                break;
            case 'deactivate_free_plugin':
                $this->action_deactivate_free_plugin();
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
            wp_die( esc_html__( 'Invalid entry.', 'waiver-engine' ) );
        }

        // Both view_pdf and dl_pdf use the same nonce key so links can be shared
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- checked immediately below via check_admin_referer().
        $idx = $inline
            ? ( isset( $_GET['view_pdf'] ) ? absint( $_GET['view_pdf'] ) : 0 )
            : ( isset( $_GET['dl_pdf'] ) ? absint( $_GET['dl_pdf'] ) : 0 );
        // phpcs:enable
        check_admin_referer( 'wpwe_dl_pdf_' . $entry_id );

        $entry = Database::get_entry( $entry_id );
        if ( ! $entry ) {
            wp_die( esc_html__( 'Entry not found.', 'waiver-engine' ) );
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

        wp_die( esc_html__( 'PDF file not found on disk.', 'waiver-engine' ) );
    }

    // -----------------------------------------------------------------------
    // Action: save template
    // -----------------------------------------------------------------------

    private function action_save_template(): void {
        check_admin_referer( 'wpwe_save_template' );

        $id = isset( $_POST['wpwe_template_id'] ) ? absint( $_POST['wpwe_template_id'] ) : 0;

        // Build both pdf_mapping and field_schema from the unified table POST data
        [ $mapping, $schema ] = \WPWE\Template_Editor::build_from_post();

        $premium_data = $this->build_template_premium_data_from_post();

        $data = [
            'title'              => isset( $_POST['wpwe_title'] ) ? sanitize_text_field( wp_unslash( $_POST['wpwe_title'] ) ) : '',
            'description'        => isset( $_POST['wpwe_description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['wpwe_description'] ) ) : '',
            'pdf_attachment_id'  => isset( $_POST['wpwe_pdf_attachment_id'] ) ? absint( $_POST['wpwe_pdf_attachment_id'] ) : 0,
            'output_mode'        => (string) ( $premium_data['output_mode'] ?? 'single' ),
            'output_group_key'   => (string) ( $premium_data['output_group_key'] ?? '' ),
            'notification_email' => (string) ( $premium_data['notification_email'] ?? '' ),
            'send_admin_email'   => (int) ( $premium_data['send_admin_email'] ?? 0 ),
            'send_user_email'    => (int) ( $premium_data['send_user_email'] ?? 0 ),
            'captcha_enabled'    => ! empty( $_POST['wpwe_captcha_enabled'] )  ? 1 : 0,
            'amelia_service_ids' => array_values( (array) ( $premium_data['amelia_service_ids'] ?? [] ) ),
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

    private function build_template_premium_data_from_post(): array {
        $defaults = [
            'output_mode'        => 'single',
            'output_group_key'   => '',
            'notification_email' => '',
            'send_admin_email'   => 0,
            'send_user_email'    => 0,
            'amelia_service_ids' => [],
        ];

        if ( class_exists( Premium_Bridge::class ) && method_exists( Premium_Bridge::class, 'build_template_premium_data_from_post' ) ) {
            $premium = Premium_Bridge::build_template_premium_data_from_post();
            if ( is_array( $premium ) ) {
                return array_merge( $defaults, $premium );
            }
        }

        return $defaults;
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
            wp_die( esc_html__( 'Access denied.', 'waiver-engine' ) );
        }
        $templates = Database::get_templates();
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e( 'Waiver Templates', 'waiver-engine' ); ?></h1>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=wpwe-new' ) ); ?>" class="page-title-action">
                <?php esc_html_e( 'Add New', 'waiver-engine' ); ?>
            </a>
            <hr class="wp-header-end">

            <?php // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only admin notice query vars. ?>
            <?php if ( isset( $_GET['wpwe_saved'] ) ) : ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Template saved.', 'waiver-engine' ); ?></p></div>
            <?php endif; ?>
            <?php if ( isset( $_GET['wpwe_deleted'] ) ) : ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Template deleted.', 'waiver-engine' ); ?></p></div>
            <?php endif; ?>
            <?php // phpcs:enable ?>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th scope="col" style="width:60px"><?php esc_html_e( 'ID', 'waiver-engine' ); ?></th>
                        <th scope="col"><?php esc_html_e( 'Title', 'waiver-engine' ); ?></th>
                        <th scope="col" style="width:80px"><?php esc_html_e( 'Active', 'waiver-engine' ); ?></th>
                        <th scope="col" style="width:120px"><?php esc_html_e( 'Shortcode', 'waiver-engine' ); ?></th>
                        <th scope="col" style="width:160px"><?php esc_html_e( 'Last Updated', 'waiver-engine' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( empty( $templates ) ) : ?>
                    <tr><td colspan="5"><?php esc_html_e( 'No templates found.', 'waiver-engine' ); ?></td></tr>
                <?php else : ?>
                    <?php foreach ( $templates as $tpl ) : ?>
                    <tr>
                        <td><?php echo esc_html( $tpl->id ); ?></td>
                        <td>
                            <strong>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wpwe-edit&id=' . $tpl->id ) ); ?>">
                                    <?php echo esc_html( $tpl->title ?: __( '(no title)', 'waiver-engine' ) ); ?>
                                </a>
                            </strong>
                            <div class="row-actions">
                                <span class="edit">
                                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=wpwe-edit&id=' . $tpl->id ) ); ?>">
                                        <?php esc_html_e( 'Edit', 'waiver-engine' ); ?>
                                    </a>
                                </span>
                                &nbsp;|&nbsp;
                                <span class="trash">
                                    <a href="<?php echo esc_url( wp_nonce_url(
                                        admin_url( 'admin.php?page=wpwe&wpwe_action=delete_template&id=' . $tpl->id ),
                                        'wpwe_delete_template_' . $tpl->id
                                    ) ); ?>"
                                    onclick="return confirm('<?php esc_attr_e( 'Delete this template?', 'waiver-engine' ); ?>')"
                                    style="color:#d63638;">
                                        <?php esc_html_e( 'Delete', 'waiver-engine' ); ?>
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
            wp_die( esc_html__( 'Access denied.', 'waiver-engine' ) );
        }
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only admin routing parameter.
        $id  = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
        // phpcs:enable
        $tpl = $id ? Database::get_template( $id ) : null;

        ( new \WPWE\Template_Editor( $tpl ) )->render();
    }

    // -----------------------------------------------------------------------
    // Page: Entry List
    // -----------------------------------------------------------------------

    public function page_entry_list(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Access denied.', 'waiver-engine' ) );
        }
        ( new Entry_List_Table() )->render();
    }

    // -----------------------------------------------------------------------
    // Page: Entry Detail
    // -----------------------------------------------------------------------

    public function page_entry_detail(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Access denied.', 'waiver-engine' ) );
        }
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only admin routing parameter.
        $id    = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
        // phpcs:enable
        $entry = $id ? Database::get_entry( $id ) : null;
        if ( ! $entry ) {
            wp_die( esc_html__( 'Entry not found.', 'waiver-engine' ) );
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

        $allow_premium_settings = function_exists( 'wpwe_is_premium_package' ) && wpwe_is_premium_package();

        update_option( 'wpwe_amelia_enabled', $allow_premium_settings && ! empty( $_POST['wpwe_amelia_enabled'] ) ? '1' : '' );
        update_option( 'wpwe_admin_email_enabled', $allow_premium_settings && ! empty( $_POST['wpwe_admin_email_enabled'] ) ? '1' : '' );
        update_option( 'wpwe_user_email_enabled', $allow_premium_settings && ! empty( $_POST['wpwe_user_email_enabled'] ) ? '1' : '' );
        update_option( 'wpwe_rate_limit_enabled',  ! empty( $_POST['wpwe_rate_limit_enabled'] )  ? '1' : '' );
        $rate_limit_max = isset( $_POST['wpwe_rate_limit_max'] )
            ? max( 1, absint( wp_unslash( $_POST['wpwe_rate_limit_max'] ) ) )
            : 5;
        $rate_limit_window = isset( $_POST['wpwe_rate_limit_window'] )
            ? max( 1, absint( wp_unslash( $_POST['wpwe_rate_limit_window'] ) ) )
            : 15;
        $retention_days = isset( $_POST['wpwe_pdf_retention_days'] )
            ? max( 1, absint( wp_unslash( $_POST['wpwe_pdf_retention_days'] ) ) )
            : 90;
        update_option( 'wpwe_rate_limit_max', $rate_limit_max );
        update_option( 'wpwe_rate_limit_window', $rate_limit_window );
        update_option( 'wpwe_pdf_retention_days', $retention_days );
        $captcha_provider = isset( $_POST['wpwe_captcha_provider'] ) ? sanitize_text_field( wp_unslash( $_POST['wpwe_captcha_provider'] ) ) : 'none';
        if ( ! in_array( $captcha_provider, [ 'none', 'recaptcha_v3', 'hcaptcha' ], true ) ) {
            $captcha_provider = 'none';
        }
        update_option( 'wpwe_captcha_provider',   $captcha_provider );
        update_option( 'wpwe_captcha_site_key',   isset( $_POST['wpwe_captcha_site_key'] )   ? sanitize_text_field( wp_unslash( $_POST['wpwe_captcha_site_key'] ) )   : '' );
        update_option( 'wpwe_captcha_secret_key', isset( $_POST['wpwe_captcha_secret_key'] ) ? sanitize_text_field( wp_unslash( $_POST['wpwe_captcha_secret_key'] ) ) : '' );
        wp_safe_redirect( admin_url( 'admin.php?page=wpwe-settings&wpwe_settings_saved=1' ) );
        exit;
    }

    // -----------------------------------------------------------------------
    // Action: cleanup old PDF files
    // -----------------------------------------------------------------------

    private function action_cleanup_pdfs(): void {
        check_admin_referer( 'wpwe_cleanup_pdfs' );

        // Save chosen retention days before running cleanup.
        $retention_days = isset( $_POST['wpwe_pdf_retention_days'] )
            ? max( 1, absint( wp_unslash( $_POST['wpwe_pdf_retention_days'] ) ) )
            : 90;
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
    // Action: export all plugin data (templates, entries, options)
    // -----------------------------------------------------------------------

    private function action_export_all_data(): void {
        check_admin_referer( 'wpwe_export_all_data' );

        $payload = Database::export_data_package();

        if ( ob_get_level() ) {
            ob_end_clean();
        }

        $filename = 'wpwe-data-export-' . gmdate( 'Y-m-d-His' ) . '.json';
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Cache-Control: no-cache, no-store, must-revalidate' );
        header( 'Pragma: no-cache' );

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        exit;
    }

    // -----------------------------------------------------------------------
    // Action: import all plugin data (templates, entries, options)
    // -----------------------------------------------------------------------

    private function action_import_all_data(): void {
        check_admin_referer( 'wpwe_import_all_data' );

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        if ( ! isset( $_FILES['wpwe_data_import_file'] ) || (int) $_FILES['wpwe_data_import_file']['error'] !== UPLOAD_ERR_OK ) {
            wp_safe_redirect( admin_url( 'admin.php?page=wpwe-settings&wpwe_data_import_error=1' ) );
            exit;
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $tmp_path = (string) $_FILES['wpwe_data_import_file']['tmp_name'];
        if ( ! is_uploaded_file( $tmp_path ) ) {
            wp_safe_redirect( admin_url( 'admin.php?page=wpwe-settings&wpwe_data_import_error=1' ) );
            exit;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $json = file_get_contents( $tmp_path );
        if ( false === $json ) {
            wp_safe_redirect( admin_url( 'admin.php?page=wpwe-settings&wpwe_data_import_error=2' ) );
            exit;
        }

        $decoded = json_decode( $json, true );
        if ( ! is_array( $decoded ) || ! isset( $decoded['tables'] ) || ! is_array( $decoded['tables'] ) ) {
            wp_safe_redirect( admin_url( 'admin.php?page=wpwe-settings&wpwe_data_import_error=3' ) );
            exit;
        }

        $replace = ! empty( $_POST['wpwe_data_replace_existing'] );
        $result  = Database::import_data_package( $decoded, $replace );

        $query = add_query_arg(
            [
                'page'                 => 'wpwe-settings',
                'wpwe_data_imported'   => 1,
                'wpwe_import_templates' => (int) $result['templates'],
                'wpwe_import_entries'   => (int) $result['entries'],
                'wpwe_import_options'   => (int) $result['options'],
            ],
            admin_url( 'admin.php' )
        );

        wp_safe_redirect( $query );
        exit;
    }

    // -----------------------------------------------------------------------
    // Action: one-click deactivate Free plugin during Pro handoff
    // -----------------------------------------------------------------------

    private function action_deactivate_free_plugin(): void {
        check_admin_referer( 'wpwe_deactivate_free_plugin' );

        if ( ! current_user_can( 'activate_plugins' ) ) {
            wp_die( esc_html__( 'You do not have permission to deactivate plugins.', 'waiver-engine' ) );
        }

        $target = isset( $_GET['plugin'] ) ? sanitize_text_field( wp_unslash( $_GET['plugin'] ) ) : '';
        if ( $target === '' ) {
            wp_safe_redirect( admin_url( 'admin.php?page=wpwe-settings&wpwe_handoff_deactivated=0' ) );
            exit;
        }

        $current = defined( 'WPWE_PLUGIN_BASENAME' ) ? (string) WPWE_PLUGIN_BASENAME : '';
        if ( $target === $current || strpos( $target, 'waiver-engine' ) === false ) {
            wp_safe_redirect( admin_url( 'admin.php?page=wpwe-settings&wpwe_handoff_deactivated=0' ) );
            exit;
        }

        if ( ! function_exists( 'deactivate_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $is_network_active = is_multisite() && is_plugin_active_for_network( $target );
        deactivate_plugins( $target, false, $is_network_active );

        $active = (array) get_option( 'active_plugins', [] );
        $still_active = in_array( $target, $active, true );
        if ( is_multisite() ) {
            $network_active = array_keys( (array) get_site_option( 'active_sitewide_plugins', [] ) );
            $still_active   = $still_active || in_array( $target, $network_active, true );
        }

        wp_safe_redirect( admin_url( 'admin.php?page=wpwe-settings&wpwe_handoff_deactivated=' . ( $still_active ? '0' : '1' ) ) );
        exit;
    }

    // -----------------------------------------------------------------------
    // Action: export template field mappings as JSON
    // -----------------------------------------------------------------------

    private function action_export_mappings(): void {
        $id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
        if ( ! $id ) {
            wp_die( esc_html__( 'Invalid template.', 'waiver-engine' ) );
        }
        check_admin_referer( 'wpwe_export_mappings_' . $id );

        $tpl = Database::get_template( $id );
        if ( ! $tpl ) {
            wp_die( esc_html__( 'Template not found.', 'waiver-engine' ) );
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
            wp_die( esc_html__( 'Invalid template.', 'waiver-engine' ) );
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
            wp_die( esc_html__( 'Template not found.', 'waiver-engine' ) );
        }

        $data                 = (array) $tpl;
        $data['field_schema'] = wp_json_encode( $decoded['field_schema'] );
        $data['pdf_mapping']  = wp_json_encode( $decoded['pdf_mapping'] );

        Database::update_template( $id, $data );

        wp_safe_redirect( admin_url( 'admin.php?page=wpwe-edit&id=' . $id . '&wpwe_imported=1' ) );
        exit;
    }
}

