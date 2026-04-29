<?php
namespace WPWE;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the Template create/edit admin page and enqueues its assets.
 * Instantiated by Admin_Menu::page_template_edit().
 *
 * @param object|null $tpl  stdClass template row from Database::get_template(), or null for new.
 */
class Template_Editor {

    private ?object $tpl;

    public function __construct( ?object $tpl ) {
        $this->tpl = $tpl;
    }

    // -----------------------------------------------------------------------
    // Asset enqueue
    // -----------------------------------------------------------------------

    /**
     * Called within render() so assets are only enqueued on this page.
     */
    private function enqueue(): void {
        wp_enqueue_media();

        wp_enqueue_script(
            'pdfjs',
            WPWE_PLUGIN_URL . 'assets/js/pdfjs/pdf.min.js',
            [],
            '3.11.174',
            true
        );
        wp_enqueue_script(
            'wpwe-admin-editor',
            WPWE_PLUGIN_URL . 'assets/js/admin-editor.js',
            [ 'jquery', 'pdfjs' ],
            WPWE_VERSION,
            true
        );
        wp_enqueue_style(
            'wpwe-admin',
            WPWE_PLUGIN_URL . 'assets/css/admin.css',
            [],
            WPWE_VERSION
        );

        // Resolve PDF preview URL
        $pdf_id  = $this->tpl ? (int) $this->tpl->pdf_attachment_id : 0;
        $pdf_url = $pdf_id ? wp_get_attachment_url( $pdf_id ) : '';

        if ( ! $pdf_url && $this->tpl ) {
            $mapping = json_decode( $this->tpl->pdf_mapping, true );
            $pdf_file = $mapping['pdf_file'] ?? '';
            if ( $pdf_file ) {
                $pdf_url = home_url( '/forms/' . basename( $pdf_file ) );
            }
        }

        wp_localize_script( 'wpwe-admin-editor', 'wpweAdmin', [
            'mediaTitle'    => __( 'Select Base PDF', 'wp-waiver-engine' ),
            'mediaButton'   => __( 'Use this PDF', 'wp-waiver-engine' ),
            'workerSrc'     => WPWE_PLUGIN_URL . 'assets/js/pdfjs/pdf.worker.min.js',
            'currentPdfUrl' => $pdf_url,
            'builtinForms'  => [
                [ 'label' => 'Archery Tag',    'value' => 'archerytag.pdf',   'url' => home_url( '/forms/archerytag.pdf' ) ],
                [ 'label' => 'Laser Skirmish', 'value' => 'laserskirmish.pdf','url' => home_url( '/forms/laserskirmish.pdf' ) ],
                [ 'label' => 'Paintball',      'value' => 'paintballNew.pdf', 'url' => home_url( '/forms/paintballNew.pdf' ) ],
            ],
            'i18n' => [
                'drawHint'  => __( 'Draw a rectangle on the PDF to map a field', 'wp-waiver-engine' ),
                'page'      => __( 'Page', 'wp-waiver-engine' ),
                'prev'      => __( '‹ Prev', 'wp-waiver-engine' ),
                'next'      => __( 'Next ›', 'wp-waiver-engine' ),
                'deleteRow' => __( 'Delete', 'wp-waiver-engine' ),
                'addRow'    => __( '+ Add Row Manually', 'wp-waiver-engine' ),
                'noSchema'  => __( 'Save the field schema first to populate the field dropdown.', 'wp-waiver-engine' ),
                'noPdf'     => __( 'Select or choose a PDF above to enable the visual mapper.', 'wp-waiver-engine' ),
            ],
        ] );
    }

    // -----------------------------------------------------------------------
    // Main render
    // -----------------------------------------------------------------------

    public function render(): void {
        $this->enqueue();

        $tpl         = $this->tpl;
        $id          = $tpl ? (int) $tpl->id : 0;
        $title       = $tpl ? $tpl->title : '';
        $description = $tpl ? $tpl->description : '';
        $pdf_id      = $tpl ? (int) $tpl->pdf_attachment_id : 0;
        $pdf_url     = $pdf_id ? wp_get_attachment_url( $pdf_id ) : '';
        $output_mode = $tpl ? $tpl->output_mode : 'single';
        $group_key   = $tpl ? $tpl->output_group_key : '';
        $notify           = $tpl ? $tpl->notification_email : '';
        $send_admin_email = $tpl ? (bool) ( $tpl->send_admin_email ?? 1 ) : true;
        $send_user_email  = $tpl ? (bool) ( $tpl->send_user_email  ?? 1 ) : true;
        $captcha_enabled  = $tpl ? (bool) ( $tpl->captcha_enabled  ?? 0 ) : false;
        $active           = $tpl ? (bool) $tpl->active : true;

        $mapping_raw = $tpl ? $tpl->pdf_mapping : '';
        $mapping     = $mapping_raw ? ( json_decode( $mapping_raw, true ) ?: [] ) : [];
        $rows        = $mapping['fields']    ?? [];
        $groups_meta = $mapping['groups']    ?? [];
        $pdf_file    = $mapping['pdf_file']  ?? '';
        $page_count  = (int) ( $mapping['page_count'] ?? 1 );

        // Build a form-type lookup from field_schema so the mapper dropdown shows
        // the real type (date, signature, number…) even for older templates whose
        // pdf_mapping only stored the collapsed 'text'/'image' pdf render type.
        $schema_raw         = $tpl ? $tpl->field_schema : '';
        $schema_data        = $schema_raw ? ( json_decode( $schema_raw, true ) ?: [] ) : [];
        $schema_type_lookup = [];
        foreach ( $schema_data['groups'] ?? [] as $sg ) {
            $sgk = $sg['key'] ?? '';
            foreach ( $sg['fields'] ?? [] as $sf ) {
                $sfk = $sf['key'] ?? '';
                if ( $sgk && $sfk ) {
                    $schema_type_lookup[ $sgk . '.' . $sfk ] = $sf['type'] ?? 'text';
                }
            }
        }

        $heading = $id
            ? sprintf( __( 'Edit Template #%d', 'wp-waiver-engine' ), $id )
            : __( 'Add New Template', 'wp-waiver-engine' );

        $back_url = admin_url( 'admin.php?page=wpwe' );
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php echo esc_html( $heading ); ?></h1>
            <a href="<?php echo esc_url( $back_url ); ?>" class="page-title-action">
                &larr; <?php esc_html_e( 'All Templates', 'wp-waiver-engine' ); ?>
            </a>
            <hr class="wp-header-end">

            <?php if ( isset( $_GET['wpwe_saved'] ) ) : ?>
            <div class="notice notice-success is-dismissible">
                <p><?php esc_html_e( 'Template saved.', 'wp-waiver-engine' ); ?></p>
            </div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=' . ( $id ? 'wpwe-edit' : 'wpwe-new' ) ) ); ?>">
                <?php wp_nonce_field( 'wpwe_save_template' ); ?>
                <input type="hidden" name="wpwe_action"      value="save_template">
                <input type="hidden" name="wpwe_template_id" value="<?php echo esc_attr( $id ); ?>">

                <!-- ── Core Settings ─────────────────────────────────────── -->
                <div id="poststuff">
                <div id="post-body" class="metabox-holder">
                <div id="post-body-content">

                    <div class="postbox">
                        <div class="postbox-header">
                            <h2><?php esc_html_e( 'Template Settings', 'wp-waiver-engine' ); ?></h2>
                        </div>
                        <div class="inside">
                            <table class="form-table wpwe-settings-table">
                                <tr>
                                    <th><label for="wpwe_title"><?php esc_html_e( 'Title', 'wp-waiver-engine' ); ?></label></th>
                                    <td>
                                        <input type="text" id="wpwe_title" name="wpwe_title"
                                               value="<?php echo esc_attr( $title ); ?>"
                                               class="large-text" required>
                                    </td>
                                </tr>
                                <tr>
                                    <th><label for="wpwe_pdf_attachment_id"><?php esc_html_e( 'Base PDF', 'wp-waiver-engine' ); ?></label></th>
                                    <td>
                                        <input type="hidden" id="wpwe_pdf_attachment_id" name="wpwe_pdf_attachment_id"
                                               value="<?php echo esc_attr( $pdf_id ); ?>">
                                        <button type="button" class="button" id="wpwe_select_pdf">
                                            <?php esc_html_e( 'Select PDF from Media Library', 'wp-waiver-engine' ); ?>
                                        </button>
                                        <span id="wpwe_pdf_name" style="margin-left:10px;">
                                            <?php echo $pdf_url
                                                ? '<a href="' . esc_url( $pdf_url ) . '" target="_blank">' . esc_html( basename( $pdf_url ) ) . '</a>'
                                                : esc_html__( 'No PDF selected', 'wp-waiver-engine' ); ?>
                                        </span>
                                    </td>
                                </tr>
                                <tr>
                                    <th><label for="wpwe_description"><?php esc_html_e( 'Description', 'wp-waiver-engine' ); ?></label></th>
                                    <td>
                                        <textarea id="wpwe_description" name="wpwe_description"
                                                  rows="3" class="large-text"><?php echo esc_textarea( $description ); ?></textarea>
                                    </td>
                                </tr>
                                <tr>
                                    <th><label for="wpwe_output_mode"><?php esc_html_e( 'Output Mode', 'wp-waiver-engine' ); ?></label></th>
                                    <td>
                                        <select id="wpwe_output_mode" name="wpwe_output_mode">
                                            <option value="single"  <?php selected( $output_mode, 'single' ); ?>>
                                                <?php esc_html_e( 'Single PDF per submission', 'wp-waiver-engine' ); ?>
                                            </option>
                                            <option value="per_row" <?php selected( $output_mode, 'per_row' ); ?>>
                                                <?php esc_html_e( 'One PDF per row (repeatable group)', 'wp-waiver-engine' ); ?>
                                            </option>
                                        </select>
                                        <div id="wpwe_group_key_wrap" style="margin-top:8px;<?php echo $output_mode !== 'per_row' ? 'display:none;' : ''; ?>">
                                            <label for="wpwe_output_group_key"><?php esc_html_e( 'Repeatable Group Key:', 'wp-waiver-engine' ); ?></label>
                                            <input type="text" id="wpwe_output_group_key" name="wpwe_output_group_key"
                                                   value="<?php echo esc_attr( $group_key ); ?>" class="regular-text"
                                                   placeholder="e.g. participants">
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <th><label for="wpwe_notification_email"><?php esc_html_e( 'Notification Email', 'wp-waiver-engine' ); ?></label></th>
                                    <td>
                                        <input type="email" id="wpwe_notification_email" name="wpwe_notification_email"
                                               value="<?php echo esc_attr( $notify ); ?>" class="regular-text"
                                               placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>">
                                        <p class="description"><?php esc_html_e( 'Leave blank to use the site admin email.', 'wp-waiver-engine' ); ?></p>
                                    </td>
                                </tr>
                                <?php if ( Settings::is_admin_email_enabled() ) : ?>
                                <tr>
                                    <th><label for="wpwe_send_admin_email"><?php esc_html_e( 'Admin Notification', 'wp-waiver-engine' ); ?></label></th>
                                    <td>
                                        <input type="checkbox" id="wpwe_send_admin_email" name="wpwe_send_admin_email" value="1"
                                               <?php checked( $send_admin_email ); ?>>
                                        <label for="wpwe_send_admin_email">
                                            <?php esc_html_e( 'Send admin notification email on submission', 'wp-waiver-engine' ); ?>
                                        </label>
                                        <p class="description"><?php esc_html_e( 'If unchecked, no email is sent to the notification address when this template is submitted.', 'wp-waiver-engine' ); ?></p>
                                    </td>
                                </tr>
                                <?php endif; /* Settings::is_admin_email_enabled() */ ?>
                                <?php if ( Settings::is_user_email_enabled() ) : ?>
                                <tr>
                                    <th><label for="wpwe_send_user_email"><?php esc_html_e( 'Submitter Copy', 'wp-waiver-engine' ); ?></label></th>
                                    <td>
                                        <input type="checkbox" id="wpwe_send_user_email" name="wpwe_send_user_email" value="1"
                                               <?php checked( $send_user_email ); ?>>
                                        <label for="wpwe_send_user_email">
                                            <?php esc_html_e( 'Allow submitters to request an email copy of their PDF', 'wp-waiver-engine' ); ?>
                                        </label>
                                        <p class="description"><?php esc_html_e( 'If unchecked, the email copy opt-in is hidden for this template.', 'wp-waiver-engine' ); ?></p>
                                    </td>
                                </tr>
                                <?php endif; /* Settings::is_user_email_enabled() */ ?>
                                <?php if ( Settings::captcha_provider() !== 'none' ) : ?>
                                <tr>
                                    <th><label for="wpwe_captcha_enabled"><?php esc_html_e( 'CAPTCHA', 'wp-waiver-engine' ); ?></label></th>
                                    <td>
                                        <input type="checkbox" id="wpwe_captcha_enabled" name="wpwe_captcha_enabled" value="1"
                                               <?php checked( $captcha_enabled ); ?>>
                                        <label for="wpwe_captcha_enabled">
                                            <?php esc_html_e( 'Require CAPTCHA verification on this form', 'wp-waiver-engine' ); ?>
                                        </label>
                                        <p class="description">
                                            <?php
                                            $provider_label = Settings::captcha_provider() === 'recaptcha_v3'
                                                ? __( 'Google reCAPTCHA v3', 'wp-waiver-engine' )
                                                : __( 'hCaptcha', 'wp-waiver-engine' );
                                            printf(
                                                /* translators: CAPTCHA provider name */
                                                esc_html__( 'When checked, %s will silently verify each submission. Global provider is configured in Settings.', 'wp-waiver-engine' ),
                                                esc_html( $provider_label )
                                            );
                                            ?>
                                        </p>
                                    </td>
                                </tr>
                                <?php endif; /* captcha_provider !== 'none' */ ?>
                                <tr>
                                    <th><label for="wpwe_active"><?php esc_html_e( 'Active', 'wp-waiver-engine' ); ?></label></th>
                                    <td>
                                        <input type="checkbox" id="wpwe_active" name="wpwe_active" value="1"
                                               <?php checked( $active, true ); ?>>
                                        <label for="wpwe_active">
                                            <?php esc_html_e( 'Make this template available on the frontend', 'wp-waiver-engine' ); ?>
                                        </label>
                                    </td>
                                </tr>
                                <?php if ( Settings::is_amelia_enabled() ) : ?>
                                <tr>
                                    <th><?php esc_html_e( 'Linked Amelia Services', 'wp-waiver-engine' ); ?></th>
                                    <td>
                                        <?php
                                        $saved_amelia_ids = json_decode( (string) ( $tpl->amelia_service_ids ?? '' ), true ) ?: [];
                                        $saved_amelia_ids = array_map( 'intval', $saved_amelia_ids );
                                        $amelia_svc       = Integration_Amelia::get_services();
                                        if ( empty( $amelia_svc ) ) : ?>
                                            <p class="description"><?php esc_html_e( 'No Amelia services found. Activate the Amelia plugin and create services first.', 'wp-waiver-engine' ); ?></p>
                                        <?php else : ?>
                                            <fieldset>
                                                <?php foreach ( $amelia_svc as $svc ) :
                                                    $svc_id  = (int) $svc['id'];
                                                    $checked = in_array( $svc_id, $saved_amelia_ids, true );
                                                    ?>
                                                    <label style="display:block;margin-bottom:4px;">
                                                        <input type="checkbox"
                                                               name="wpwe_amelia_service_ids[]"
                                                               value="<?php echo esc_attr( $svc_id ); ?>"
                                                               <?php checked( $checked ); ?>>
                                                        <?php echo esc_html( $svc['name'] ); ?>
                                                    </label>
                                                <?php endforeach; ?>
                                            </fieldset>
                                            <p class="description"><?php esc_html_e( 'When services are selected, a booking-search widget appears on the frontend form so submitters can link their waiver to an existing booking.', 'wp-waiver-engine' ); ?></p>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endif; /* Settings::is_amelia_enabled() */ ?>
                            </table>
                        </div><!-- /.inside -->
                    </div><!-- /.postbox -->

                    <!-- ── Fields & PDF Mapping ───────────────────────────── -->
                    <div class="postbox">
                        <div class="postbox-header">
                            <h2><?php esc_html_e( 'Fields & PDF Mapping', 'wp-waiver-engine' ); ?></h2>
                        </div>
                        <div class="inside">
                            <!-- Hidden JSON store – JS updates this before form submit -->
                            <input type="hidden" id="wpwe_pdf_mapping" name="wpwe_pdf_mapping"
                                   value="<?php echo esc_attr( $mapping_raw ); ?>">

                            <!-- Summary + Open button -->
                            <div class="wpwe-mapper-summary">
                                <span id="wpwe-mapper-summary-text" class="wpwe-mapper-summary-text">
                                    <?php
                                    $field_count = count( $rows );
                                    if ( $pdf_file && $field_count ) {
                                        echo esc_html( sprintf(
                                            _n( '%1$s — %2$d field', '%1$s — %2$d fields', $field_count, 'wp-waiver-engine' ),
                                            $pdf_file, $field_count
                                        ) );
                                    } elseif ( $field_count ) {
                                        echo esc_html( sprintf(
                                            _n( '%d field mapped', '%d fields mapped', $field_count, 'wp-waiver-engine' ),
                                            $field_count
                                        ) );
                                    } else {
                                        esc_html_e( 'No fields mapped yet', 'wp-waiver-engine' );
                                    }
                                    ?>
                                </span>
                                <button type="button" class="button button-primary" id="wpwe-open-mapper">
                                    <span class="dashicons dashicons-editor-expand" style="vertical-align:text-bottom;margin-right:4px;"></span>
                                    <?php esc_html_e( 'Open Visual Mapper', 'wp-waiver-engine' ); ?>
                                </button>
                            </div>

                            <!-- Row template (cloned by JS) -->
                            <template id="wpwe-mapping-row-tpl">
                                <?php $this->render_mapping_row( '', [], [], [] ); ?>
                            </template>
                        </div><!-- /.inside -->
                    </div><!-- /.postbox -->

                    <p class="submit">
                        <button type="submit" class="button button-primary button-large">
                            <?php echo $id ? esc_html__( 'Update Template', 'wp-waiver-engine' ) : esc_html__( 'Save Template', 'wp-waiver-engine' ); ?>
                        </button>
                    </p>

                </div><!-- /#post-body-content -->
                </div><!-- /#post-body -->
                </div><!-- /#poststuff -->

        <!-- ══════════════════════════════════════════════════════════════
             Full-screen mapper modal — kept INSIDE the form so that
             all wpwe_map_*[] inputs are submitted with the form.
             ══════════════════════════════════════════════════════════════ -->
        <div id="wpwe-mapper-modal" class="wpwe-mapper-modal" role="dialog" aria-modal="true" style="display:none;">

            <div class="wpwe-modal-header">
                <span class="wpwe-modal-title">
                    <span class="dashicons dashicons-location-alt" style="vertical-align:middle;margin-right:6px;"></span>
                    <?php esc_html_e( 'Visual Field Mapper', 'wp-waiver-engine' ); ?>
                </span>
                <div class="wpwe-modal-toolbar">
                    <label for="wpwe_builtin_pdf"><?php esc_html_e( 'PDF:', 'wp-waiver-engine' ); ?></label>
                    <select id="wpwe_builtin_pdf" name="wpwe_builtin_pdf">
                        <option value=""><?php esc_html_e( '— built-in forms —', 'wp-waiver-engine' ); ?></option>
                        <option value="archerytag.pdf"    <?php selected( $pdf_file, 'archerytag.pdf' ); ?>>Archery Tag</option>
                        <option value="laserskirmish.pdf" <?php selected( $pdf_file, 'laserskirmish.pdf' ); ?>>Laser Skirmish</option>
                        <option value="paintballNew.pdf"  <?php selected( $pdf_file, 'paintballNew.pdf' ); ?>>Paintball</option>
                    </select>
                    <input type="number" id="wpwe_map_page_count" name="wpwe_map_page_count" min="1" max="20"
                           value="<?php echo esc_attr( $page_count ?: 1 ); ?>"
                           title="<?php esc_attr_e( 'Total pages', 'wp-waiver-engine' ); ?>"
                           style="width:46px;">
                    <button type="button" class="button" id="wpwe-prev-page">&#8249;</button>
                    <span><?php esc_html_e( 'Pg', 'wp-waiver-engine' ); ?> <strong id="wpwe-page-num">1</strong>/<span id="wpwe-page-total">1</span></span>
                    <button type="button" class="button" id="wpwe-next-page">&#8250;</button>
                    <span class="wpwe-draw-hint"><?php esc_html_e( 'Draw on PDF to add a field', 'wp-waiver-engine' ); ?></span>
                </div>
                <button type="button" class="wpwe-modal-close" id="wpwe-close-mapper"
                        title="<?php esc_attr_e( 'Close mapper (Esc)', 'wp-waiver-engine' ); ?>">
                    <span class="dashicons dashicons-no-alt"></span>
                </button>
            </div>

            <div class="wpwe-modal-body">
                <div class="wpwe-modal-pdf-pane">
                    <div class="wpwe-mapper-hint" id="wpwe-mapper-hint">
                        <?php esc_html_e( 'Select a PDF above, or use the Base PDF picker in Template Settings.', 'wp-waiver-engine' ); ?>
                    </div>
                    <div class="wpwe-pdf-viewer" id="wpwe-pdf-viewer" style="display:none;">
                        <div class="wpwe-canvas-wrap" id="wpwe-canvas-wrap">
                            <canvas id="wpwe-pdf-canvas"></canvas>
                            <canvas id="wpwe-draw-canvas"></canvas>
                            <div id="wpwe-overlays"></div>
                        </div>
                    </div>
                </div>

                <div class="wpwe-modal-table-pane">
                    <p class="description" style="margin:0 0 8px;font-size:12px;">
                        <?php esc_html_e( 'Draw on the PDF to add rows. Fill in Group, Field Key, Label, Type.', 'wp-waiver-engine' ); ?>
                    </p>
                    <div class="wpwe-mapping-table-wrap">
                        <table class="widefat wpwe-mapping-table" id="wpwe-mapping-table">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e( 'Group', 'wp-waiver-engine' ); ?></th>
                                    <th><?php esc_html_e( 'Key', 'wp-waiver-engine' ); ?></th>
                                    <th><?php esc_html_e( 'Label', 'wp-waiver-engine' ); ?></th>
                                    <th><?php esc_html_e( 'Type', 'wp-waiver-engine' ); ?></th>
                                    <th style="width:34px" title="<?php esc_attr_e( 'Required', 'wp-waiver-engine' ); ?>">Req</th>
                                    <th style="width:34px" title="<?php esc_attr_e( 'Repeatable group', 'wp-waiver-engine' ); ?>">Rpt</th>
                                    <th style="width:36px"><?php esc_html_e( 'Pg', 'wp-waiver-engine' ); ?></th>
                                    <th><?php esc_html_e( 'Location', 'wp-waiver-engine' ); ?></th>
                                    <th style="width:42px"><?php esc_html_e( 'Font', 'wp-waiver-engine' ); ?></th>
                                    <th style="width:44px" title="<?php esc_attr_e( 'Character spacing in pt \u2014 use to spread text across letter-boxes', 'wp-waiver-engine' ); ?>"><?php esc_html_e( 'Spc', 'wp-waiver-engine' ); ?></th>
                                    <th style="width:64px" title="<?php esc_attr_e( 'Date output format (PHP date format, e.g. d/m/Y). Only used when Type = date.', 'wp-waiver-engine' ); ?>"><?php esc_html_e( 'Date Fmt', 'wp-waiver-engine' ); ?></th>
                                    <th style="width:26px"></th>
                                </tr>
                            </thead>
                            <tbody id="wpwe-mapping-tbody">
                                <?php foreach ( $rows as $path => $cfg ) :
                                    $this->render_mapping_row( $path, $cfg, $groups_meta, $schema_type_lookup );
                                endforeach; ?>
                            </tbody>
                        </table>
                        <button type="button" class="button" id="wpwe-add-mapping-row" style="margin-top:6px;">
                            <?php esc_html_e( '+ Add Field', 'wp-waiver-engine' ); ?>
                        </button>
                    </div>
                </div>
            </div>

            <div class="wpwe-modal-footer">
                <button type="button" class="button button-primary button-hero" id="wpwe-close-mapper-done">
                    <?php esc_html_e( 'Done', 'wp-waiver-engine' ); ?>
                </button>
                <span class="wpwe-modal-footer-hint">
                    <?php esc_html_e( 'Click Done, then Save/Update Template to persist changes.', 'wp-waiver-engine' ); ?>
                </span>
            </div>
        </div><!-- /#wpwe-mapper-modal -->

            </form>

        <?php if ( $id ) : ?>
        <!-- ══════════════════════════════════════════════════════════════
             Export / Import Field Mappings (outside the main form)
             ══════════════════════════════════════════════════════════════ -->
        <div id="poststuff" style="padding-top:0;">
        <div id="post-body" class="metabox-holder">
        <div id="post-body-content">
            <div class="postbox">
                <div class="postbox-header">
                    <h2><?php esc_html_e( 'Export / Import Field Mappings', 'wp-waiver-engine' ); ?></h2>
                </div>
                <div class="inside">
                    <p><?php esc_html_e( 'Export the field schema and PDF coordinate mappings to migrate this template\'s configuration to another site. The Base PDF itself is not included — upload it separately on the destination site before importing.', 'wp-waiver-engine' ); ?></p>

                    <h3 style="margin-top:0;"><?php esc_html_e( 'Export', 'wp-waiver-engine' ); ?></h3>
                    <a href="<?php echo esc_url( wp_nonce_url(
                        admin_url( 'admin.php?page=wpwe-edit&id=' . $id . '&wpwe_action=export_mappings' ),
                        'wpwe_export_mappings_' . $id
                    ) ); ?>" class="button">
                        <span class="dashicons dashicons-download" style="vertical-align:text-bottom;margin-right:4px;"></span>
                        <?php esc_html_e( 'Download Mapping JSON', 'wp-waiver-engine' ); ?>
                    </a>

                    <h3><?php esc_html_e( 'Import', 'wp-waiver-engine' ); ?></h3>

                    <?php if ( isset( $_GET['wpwe_imported'] ) ) : ?>
                    <div class="notice notice-success inline" style="margin-bottom:12px;">
                        <p><?php esc_html_e( 'Mappings imported successfully. Review the fields and save to confirm.', 'wp-waiver-engine' ); ?></p>
                    </div>
                    <?php endif; ?>

                    <?php if ( isset( $_GET['wpwe_import_error'] ) ) : ?>
                    <div class="notice notice-error inline" style="margin-bottom:12px;">
                        <p><?php esc_html_e( 'Import failed. Please ensure the file is a valid waiver mapping export (.json).', 'wp-waiver-engine' ); ?></p>
                    </div>
                    <?php endif; ?>

                    <form method="post"
                          action="<?php echo esc_url( admin_url( 'admin.php?page=wpwe-edit&id=' . $id ) ); ?>"
                          enctype="multipart/form-data">
                        <?php wp_nonce_field( 'wpwe_import_mappings' ); ?>
                        <input type="hidden" name="wpwe_action"      value="import_mappings">
                        <input type="hidden" name="wpwe_template_id" value="<?php echo esc_attr( $id ); ?>">
                        <input type="file" name="wpwe_import_file" accept=".json,application/json" required style="margin-right:8px;">
                        <button type="submit" class="button"
                                onclick="return confirm('<?php esc_attr_e( 'This will overwrite the current field schema and PDF mapping. Continue?', 'wp-waiver-engine' ); ?>')">
                            <span class="dashicons dashicons-upload" style="vertical-align:text-bottom;margin-right:4px;"></span>
                            <?php esc_html_e( 'Import', 'wp-waiver-engine' ); ?>
                        </button>
                    </form>
                    <p class="description" style="margin-top:8px;">
                        <?php esc_html_e( 'Importing replaces field definitions and PDF coordinate mappings only. Template title, notification email, and other settings are preserved.', 'wp-waiver-engine' ); ?>
                    </p>
                </div>
            </div>
        </div><!-- /#post-body-content -->
        </div><!-- /#post-body -->
        </div><!-- /#poststuff -->
        <?php endif; ?>

        </div><!-- /.wrap -->
        <?php
    }

    // -----------------------------------------------------------------------
    // Mapping row renderer (kept identical to previous Template_Meta_Box)
    // -----------------------------------------------------------------------

    private function render_mapping_row( string $path, array $cfg, array $groups_meta, array $schema_type_lookup = [] ): void {
        // Strip __N duplicate suffix (e.g. "signer.age__2" -> "signer.age") for display.
        $path_display = preg_replace( '/__\d+$/', '', $path );
        $parts      = $path_display ? explode( '.', $path_display, 2 ) : [ '', '' ];
        $group_key  = $parts[0] ?? '';
        $field_key  = $parts[1] ?? '';
        $gm         = $groups_meta[ $group_key ] ?? [];
        $label      = $cfg['label']      ?? '';
        $required   = ! empty( $cfg['required'] );
        $repeatable = ! empty( $gm['repeatable'] );
        // Prefer the form type from field_schema (reliable), then from pdf_mapping
        // (correct for post-fix saves), then fall back to 'text'.
        // Old pdf_mappings stored 'text'/'image'; new ones store the real form type.
        $raw_type   = $cfg['type'] ?? 'text';
        $type       = $schema_type_lookup[ $path_display ] ?? $raw_type;
        $page       = (int) ( $cfg['page'] ?? 1 );
        $x          = $cfg['x']         ?? '';
        $y          = $cfg['y']         ?? '';
        $width      = $cfg['width']     ?? '';
        $height     = $cfg['height']    ?? '';
        $font_size    = $cfg['font_size']    ?? '';
        $char_spacing = $cfg['char_spacing'] ?? '';
        $date_format  = $cfg['date_format']  ?? '';

        $loc = '';
        if ( $x !== '' && $y !== '' ) {
            $loc = 'pg.' . $page . ' · ' . round( (float) $x, 1 ) . ', ' . round( (float) $y, 1 );
            if ( $width !== '' && $height !== '' ) {
                $loc .= ' · ' . round( (float) $width, 1 ) . '×' . round( (float) $height, 1 );
            }
        }
        ?>
        <tr class="wpwe-mapping-row">
            <td><input type="text" class="wpwe-map-group-key small-text" name="wpwe_map_group_keys[]"
                       value="<?php echo esc_attr( $group_key ); ?>" placeholder="signer"></td>
            <td><input type="text" class="wpwe-map-field-key small-text" name="wpwe_map_field_keys[]"
                       value="<?php echo esc_attr( $field_key ); ?>" placeholder="full_name"></td>
            <td><input type="text" class="wpwe-map-label" name="wpwe_map_labels[]"
                       value="<?php echo esc_attr( $label ); ?>" placeholder="e.g. Full Name"></td>
            <td>
                <select class="wpwe-map-type" name="wpwe_map_types[]">
                    <option value="text"      <?php selected( $type, 'text' );      ?>>text</option>
                    <option value="email"     <?php selected( $type, 'email' );     ?>>email</option>
                    <option value="number"    <?php selected( $type, 'number' );    ?>>number</option>
                    <option value="date"      <?php selected( $type, 'date' );      ?>>date</option>
                    <option value="tel"       <?php selected( $type, 'tel' );       ?>>tel</option>
                    <option value="textarea"  <?php selected( $type, 'textarea' );  ?>>textarea</option>
                    <option value="select"    <?php selected( $type, 'select' );    ?>>select</option>
                    <option value="checkbox"  <?php selected( $type, 'checkbox' );  ?>>checkbox</option>
                    <option value="signature" <?php selected( $type, 'signature' ); ?>>signature</option>
                    <option value="image"     <?php selected( $type, 'image' );     ?>>image (overlay)</option>
                </select>
            </td>
            <td style="text-align:center">
                <input type="hidden" class="wpwe-req-hidden" name="wpwe_map_required[]"
                       value="<?php echo $required ? '1' : '0'; ?>">
                <input type="checkbox" class="wpwe-map-required" <?php checked( $required ); ?>>
            </td>
            <td style="text-align:center">
                <input type="hidden" class="wpwe-rep-hidden" name="wpwe_map_repeatable[]"
                       value="<?php echo $repeatable ? '1' : '0'; ?>">
                <input type="checkbox" class="wpwe-map-repeatable" <?php checked( $repeatable ); ?>>
            </td>
            <td><input type="number" class="wpwe-map-page small-text" name="wpwe_map_pages[]"
                       value="<?php echo esc_attr( $page ); ?>" min="1" max="99"></td>
            <input type="hidden" class="wpwe-map-x" name="wpwe_map_x[]" value="<?php echo esc_attr( $x ); ?>">
            <input type="hidden" class="wpwe-map-y" name="wpwe_map_y[]" value="<?php echo esc_attr( $y ); ?>">
            <input type="hidden" class="wpwe-map-w" name="wpwe_map_w[]" value="<?php echo esc_attr( $width ); ?>">
            <input type="hidden" class="wpwe-map-h" name="wpwe_map_h[]" value="<?php echo esc_attr( $height ); ?>">
            <td class="wpwe-map-location"><?php echo $loc ? esc_html( $loc ) : '<em>draw on PDF</em>'; ?></td>
            <td><input type="number" class="wpwe-map-font small-text" name="wpwe_map_fonts[]"
                       value="<?php echo esc_attr( $font_size ); ?>" step="0.5" placeholder="11"></td>
            <td><input type="number" class="wpwe-map-char-spacing small-text" name="wpwe_map_char_spacing[]"
                       value="<?php echo esc_attr( $char_spacing ); ?>" step="0.1" min="0" placeholder="0"
                       title="<?php esc_attr_e( 'Extra character spacing in pt (e.g. 6 for date boxes)', 'wp-waiver-engine' ); ?>"></td>
            <td><input type="text" class="wpwe-map-date-format small-text" name="wpwe_map_date_format[]"
                       value="<?php echo esc_attr( $date_format ); ?>" placeholder="d/m/Y" maxlength="20"
                       title="<?php esc_attr_e( 'PHP date() format string, e.g. d/m/Y or m-d-Y. Leave blank for default d/m/Y.', 'wp-waiver-engine' ); ?>"></td>
            <td><button type="button" class="button-link wpwe-delete-map-row"
                        title="<?php esc_attr_e( 'Delete', 'wp-waiver-engine' ); ?>">
                <span class="dashicons dashicons-trash" style="color:#d63638;vertical-align:middle;"></span>
            </button></td>
        </tr>
        <?php
    }

    // -----------------------------------------------------------------------
    // Static: rebuild mapping + schema from POST (shared with Admin_Menu)
    // -----------------------------------------------------------------------

    /**
     * Rebuild both pdf_mapping and field_schema from the unified table POST data.
     *
     * @return array{0: array, 1: array}  [ $mapping, $schema ]
     */
    public static function build_from_post(): array {
        $pdf_file   = isset( $_POST['wpwe_builtin_pdf'] )
            ? basename( sanitize_text_field( wp_unslash( $_POST['wpwe_builtin_pdf'] ) ) ) : '';
        $page_count = isset( $_POST['wpwe_map_page_count'] )
            ? max( 1, absint( $_POST['wpwe_map_page_count'] ) ) : 1;

        $gk_arr    = self::post_arr( 'wpwe_map_group_keys' );
        $fk_arr    = self::post_arr( 'wpwe_map_field_keys' );
        $label_arr = self::post_arr( 'wpwe_map_labels' );
        $type_arr  = self::post_arr( 'wpwe_map_types' );
        $pages_arr = self::post_arr( 'wpwe_map_pages' );
        $x_arr     = self::post_arr( 'wpwe_map_x' );
        $y_arr     = self::post_arr( 'wpwe_map_y' );
        $w_arr     = self::post_arr( 'wpwe_map_w' );
        $h_arr     = self::post_arr( 'wpwe_map_h' );
        $fonts_arr        = self::post_arr( 'wpwe_map_fonts' );
        $char_spacing_arr = self::post_arr( 'wpwe_map_char_spacing' );
        $date_format_arr  = self::post_arr( 'wpwe_map_date_format' );
        $req_map   = isset( $_POST['wpwe_map_required'] )   && is_array( $_POST['wpwe_map_required'] )
            ? array_map( 'absint', $_POST['wpwe_map_required'] )   : [];
        $rep_map   = isset( $_POST['wpwe_map_repeatable'] ) && is_array( $_POST['wpwe_map_repeatable'] )
            ? array_map( 'absint', $_POST['wpwe_map_repeatable'] ) : [];

        $allowed_types = [ 'text', 'email', 'number', 'date', 'tel', 'textarea', 'select', 'checkbox', 'signature', 'image' ];

        $mapping_fields = [];
        $groups_meta    = [];
        $schema_groups  = [];

        $count = count( $gk_arr );
        for ( $i = 0; $i < $count; $i++ ) {
            $gk = sanitize_key( wp_unslash( $gk_arr[ $i ] ?? '' ) );
            $fk = sanitize_key( wp_unslash( $fk_arr[ $i ] ?? '' ) );
            if ( ! $gk || ! $fk ) continue;

            $path = $gk . '.' . $fk;
            // Allow multiple PDF locations for the same form field:
            // append __2, __3, … suffix when the path already exists.
            $final_path = $path;
            $dup_count  = 2;
            while ( isset( $mapping_fields[ $final_path ] ) ) {
                $final_path = $path . '__' . $dup_count;
                $dup_count++;
            }
            $label      = sanitize_text_field( wp_unslash( $label_arr[ $i ] ?? '' ) );
            $type       = in_array( $type_arr[ $i ] ?? '', $allowed_types, true ) ? $type_arr[ $i ] : 'text';
            $required   = (bool) ( $req_map[ $i ] ?? 0 );
            $repeatable = (bool) ( $rep_map[ $i ] ?? 0 );
            $page       = max( 1, absint( $pages_arr[ $i ] ?? 1 ) );
            $x          = is_numeric( $x_arr[ $i ] ?? '' ) ? (float) $x_arr[ $i ] : 0.0;
            $y          = is_numeric( $y_arr[ $i ] ?? '' ) ? (float) $y_arr[ $i ] : 0.0;
            $width      = is_numeric( $w_arr[ $i ] ?? '' ) ? (float) $w_arr[ $i ] : 0.0;
            $height     = is_numeric( $h_arr[ $i ] ?? '' ) ? (float) $h_arr[ $i ] : 0.0;
            $font_size    = is_numeric( $fonts_arr[ $i ] ?? '' )        ? (float) $fonts_arr[ $i ]        : 11.0;
            $char_spacing = is_numeric( $char_spacing_arr[ $i ] ?? '' ) ? (float) $char_spacing_arr[ $i ] : 0.0;
            $date_format  = sanitize_text_field( wp_unslash( $date_format_arr[ $i ] ?? '' ) );

            // Store the actual form type in pdf_mapping so it round-trips correctly
            // through render_mapping_row() and field_schema on re-save.
            $entry = [
                'page'     => $page,
                'x'        => $x,
                'y'        => $y,
                'type'     => $type,
                'label'    => $label,
                'required' => $required,
            ];
            if ( $width  > 0 ) $entry['width']  = $width;
            if ( $height > 0 ) $entry['height'] = $height;
            if ( ! in_array( $type, [ 'signature', 'image' ], true ) ) $entry['font_size'] = $font_size;
            if ( $char_spacing > 0 ) $entry['char_spacing'] = $char_spacing;
            if ( 'date' === $type && '' !== $date_format ) $entry['date_format'] = $date_format;
            $mapping_fields[ $final_path ] = $entry;

            if ( ! isset( $groups_meta[ $gk ] ) ) {
                $groups_meta[ $gk ] = [ 'repeatable' => $repeatable ];
            } elseif ( $repeatable ) {
                $groups_meta[ $gk ]['repeatable'] = true;
            }

            if ( ! isset( $schema_groups[ $gk ] ) ) {
                $schema_groups[ $gk ] = [
                    'key'        => $gk,
                    'label'      => ucwords( str_replace( '_', ' ', $gk ) ),
                    'repeatable' => $repeatable,
                    'fields'     => [],
                ];
            }
            if ( $repeatable ) {
                $schema_groups[ $gk ]['repeatable'] = true;
                if ( ! isset( $schema_groups[ $gk ]['min_rows'] ) ) {
                    $schema_groups[ $gk ]['min_rows'] = 1;
                    $schema_groups[ $gk ]['max_rows'] = 20;
                }
            }
            // Skip if this field key is already in the schema (duplicate PDF placement
            // for the same form field — two boxes on the PDF, one input on the form).
            $already_in_schema = false;
            foreach ( $schema_groups[ $gk ]['fields'] as $existing ) {
                if ( ( $existing['key'] ?? '' ) === $fk ) { $already_in_schema = true; break; }
            }
            if ( ! $already_in_schema ) {
                $schema_groups[ $gk ]['fields'][] = [
                    'key'      => $fk,
                    'label'    => $label ?: ucwords( str_replace( '_', ' ', $fk ) ),
                    'type'     => $type,
                    'required' => $required,
                ];
            }
        }

        $mapping = [
            'page_count' => $page_count,
            'groups'     => $groups_meta,
            'fields'     => $mapping_fields,
        ];
        if ( $pdf_file ) $mapping['pdf_file'] = $pdf_file;

        $schema = [ 'groups' => array_values( $schema_groups ) ];

        return [ $mapping, $schema ];
    }

    private static function post_arr( string $key ): array {
        if ( ! isset( $_POST[ $key ] ) || ! is_array( $_POST[ $key ] ) ) {
            return [];
        }
        return array_values( $_POST[ $key ] );
    }
}
