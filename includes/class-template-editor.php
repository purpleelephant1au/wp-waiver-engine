<?php
namespace Pewave\WaiverEngine;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the Template create/edit admin page and enqueues its assets.
 * Instantiated by PeWave_Admin_Menu::page_template_edit().
 *
 * @param object|null $tpl  stdClass template row from PeWave_Database::get_template(), or null for new.
 */
class PeWave_Template_Editor {

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
            \PEWAVE_PLUGIN_URL . 'assets/js/pdfjs/pdf.min.js',
            [],
            '5.7.284',
            true
        );
        wp_enqueue_script(
            'wpwe-admin-editor',
            \PEWAVE_PLUGIN_URL . 'assets/js/admin-editor.js',
            [ 'jquery', 'pdfjs' ],
            \PEWAVE_VERSION,
            true
        );
        wp_enqueue_style(
            'wpwe-admin',
            \PEWAVE_PLUGIN_URL . 'assets/css/admin.css',
            [],
            \PEWAVE_VERSION
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

        wp_localize_script( 'wpwe-admin-editor', 'pewaveAdmin', [
            'mediaTitle'    => __( 'Select Base PDF', 'waiver-engine' ),
            'mediaButton'   => __( 'Use this PDF', 'waiver-engine' ),
            'workerSrc'     => \PEWAVE_PLUGIN_URL . 'assets/js/pdfjs/pdf.worker.min.js',
            'currentPdfUrl' => $pdf_url,
            'i18n' => [
                'drawHint'  => __( 'Draw a rectangle on the PDF to map a field', 'waiver-engine' ),
                'page'      => __( 'Page', 'waiver-engine' ),
                'prev'      => __( '‹ Prev', 'waiver-engine' ),
                'next'      => __( 'Next ›', 'waiver-engine' ),
                'deleteRow' => __( 'Delete', 'waiver-engine' ),
                'addRow'    => __( '+ Add Row Manually', 'waiver-engine' ),
                'noSchema'  => __( 'Save the field schema first to populate the field dropdown.', 'waiver-engine' ),
                'noPdf'     => __( 'Select a Base PDF in Template PeWave_Settings to enable the visual mapper.', 'waiver-engine' ),
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
            ? sprintf(
                /* translators: %d: template ID */
                __( 'Edit Template #%d', 'waiver-engine' ),
                $id
            )
            : __( 'Add New Template', 'waiver-engine' );

        $back_url = admin_url( 'admin.php?page=pewave' );
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php echo esc_html( $heading ); ?></h1>
            <a href="<?php echo esc_url( $back_url ); ?>" class="page-title-action">
                &larr; <?php esc_html_e( 'All Templates', 'waiver-engine' ); ?>
            </a>
            <hr class="wp-header-end">

            <?php // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only admin notice query vars. ?>
            <?php if ( isset( $_GET['pewave_saved'] ) ) : ?>
            <div class="notice notice-success is-dismissible">
                <p><?php esc_html_e( 'Template saved.', 'waiver-engine' ); ?></p>
            </div>
            <?php endif; ?>
            <?php // phpcs:enable ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=' . ( $id ? 'pewave-edit' : 'pewave-new' ) ) ); ?>">
                <?php wp_nonce_field( 'pewave_save_template' ); ?>
                <input type="hidden" name="pewave_action"      value="save_template">
                <input type="hidden" name="pewave_template_id" value="<?php echo esc_attr( $id ); ?>">

                <!-- -- PeWave_Core PeWave_Settings --------------------------------------- -->
                <div id="poststuff">
                <div id="post-body" class="metabox-holder">
                <div id="post-body-content">

                    <div class="postbox">
                        <div class="postbox-header">
                            <h2><?php esc_html_e( 'Template PeWave_Settings', 'waiver-engine' ); ?></h2>
                        </div>
                        <div class="inside">
                            <table class="form-table pewave-settings-table">
                                <tr>
                                    <th><label for="pewave_title"><?php esc_html_e( 'Title', 'waiver-engine' ); ?></label></th>
                                    <td>
                                        <input type="text" id="pewave_title" name="pewave_title"
                                               value="<?php echo esc_attr( $title ); ?>"
                                               class="large-text" required>
                                    </td>
                                </tr>
                                <tr>
                                    <th><label for="pewave_pdf_attachment_id"><?php esc_html_e( 'Base PDF', 'waiver-engine' ); ?></label></th>
                                    <td>
                                        <input type="hidden" id="pewave_pdf_attachment_id" name="pewave_pdf_attachment_id"
                                               value="<?php echo esc_attr( $pdf_id ); ?>">
                                        <button type="button" class="button" id="pewave_select_pdf">
                                            <?php esc_html_e( 'Select PDF from Media Library', 'waiver-engine' ); ?>
                                        </button>
                                        <span id="pewave_pdf_name" style="margin-left:10px;">
                                            <?php echo $pdf_url
                                                ? '<a href="' . esc_url( $pdf_url ) . '" target="_blank">' . esc_html( basename( $pdf_url ) ) . '</a>'
                                                : esc_html__( 'No PDF selected', 'waiver-engine' ); ?>
                                        </span>
                                    </td>
                                </tr>
                                <tr>
                                    <th><label for="pewave_description"><?php esc_html_e( 'Description', 'waiver-engine' ); ?></label></th>
                                    <td>
                                        <textarea id="pewave_description" name="pewave_description"
                                                  rows="3" class="large-text"><?php echo esc_textarea( $description ); ?></textarea>
                                    </td>
                                </tr>
                                <tr>
                                    <th><label for="pewave_output_mode"><?php esc_html_e( 'Output Mode', 'waiver-engine' ); ?></label></th>
                                    <td>
                                        <select id="pewave_output_mode" name="pewave_output_mode">
                                            <option value="single"  <?php selected( $output_mode, 'single' ); ?>>
                                                <?php esc_html_e( 'Single PDF per submission', 'waiver-engine' ); ?>
                                            </option>
                                            <?php $this->render_per_row_output_mode_option( $output_mode ); ?>
                                        </select>
                                        <?php $this->render_output_group_key_control( $output_mode, $group_key ); ?>
                                    </td>
                                </tr>
                                <?php $this->render_premium_settings_rows( $tpl, (string) $notify, (bool) $send_admin_email, (bool) $send_user_email ); ?>
                                <?php if ( PeWave_Settings::captcha_provider() !== 'none' ) : ?>
                                <tr>
                                    <th><label for="pewave_captcha_enabled"><?php esc_html_e( 'CAPTCHA', 'waiver-engine' ); ?></label></th>
                                    <td>
                                        <input type="checkbox" id="pewave_captcha_enabled" name="pewave_captcha_enabled" value="1"
                                               <?php checked( $captcha_enabled ); ?>>
                                        <label for="pewave_captcha_enabled">
                                            <?php esc_html_e( 'Require CAPTCHA verification on this form', 'waiver-engine' ); ?>
                                        </label>
                                        <p class="description">
                                            <?php
                                            $provider_label = PeWave_Settings::captcha_provider() === 'recaptcha_v3'
                                                ? __( 'Google reCAPTCHA v3', 'waiver-engine' )
                                                : __( 'hCaptcha', 'waiver-engine' );
                                            printf(
                                                /* translators: CAPTCHA provider name */
                                                esc_html__( 'When checked, %s will silently verify each submission. Global provider is configured in PeWave_Settings.', 'waiver-engine' ),
                                                esc_html( $provider_label )
                                            );
                                            ?>
                                        </p>
                                    </td>
                                </tr>
                                <?php endif; /* captcha_provider !== 'none' */ ?>
                                <tr>
                                    <th><label for="pewave_active"><?php esc_html_e( 'Active', 'waiver-engine' ); ?></label></th>
                                    <td>
                                        <input type="checkbox" id="pewave_active" name="pewave_active" value="1"
                                               <?php checked( $active, true ); ?>>
                                        <label for="pewave_active">
                                            <?php esc_html_e( 'Make this template available on the frontend', 'waiver-engine' ); ?>
                                        </label>
                                    </td>
                                </tr>

                            </table>
                        </div><!-- /.inside -->
                    </div><!-- /.postbox -->

                    <!-- -- Fields & PDF Mapping ----------------------------- -->
                    <div class="postbox">
                        <div class="postbox-header">
                            <h2><?php esc_html_e( 'Fields & PDF Mapping', 'waiver-engine' ); ?></h2>
                        </div>
                        <div class="inside">
                            <!-- Hidden JSON store – JS updates this before form submit -->
                            <input type="hidden" id="pewave_pdf_mapping" name="pewave_pdf_mapping"
                                   value="<?php echo esc_attr( $mapping_raw ); ?>">

                            <!-- Summary + Open button -->
                            <div class="pewave-mapper-summary">
                                <span id="pewave-mapper-summary-text" class="pewave-mapper-summary-text">
                                    <?php
                                    $field_count = count( $rows );
                                    if ( $pdf_file && $field_count ) {
                                        echo esc_html( sprintf(
                                            /* translators: 1: PDF filename, 2: number of mapped fields */
                                            _n( '%1$s — %2$d field', '%1$s — %2$d fields', $field_count, 'waiver-engine' ),
                                            $pdf_file, $field_count
                                        ) );
                                    } elseif ( $field_count ) {
                                        echo esc_html( sprintf(
                                            /* translators: %d: number of mapped fields */
                                            _n( '%d field mapped', '%d fields mapped', $field_count, 'waiver-engine' ),
                                            $field_count
                                        ) );
                                    } else {
                                        esc_html_e( 'No fields mapped yet', 'waiver-engine' );
                                    }
                                    ?>
                                </span>
                                <button type="button" class="button button-primary" id="pewave-open-mapper">
                                    <span class="dashicons dashicons-editor-expand" style="vertical-align:text-bottom;margin-right:4px;"></span>
                                    <?php esc_html_e( 'Open Visual Mapper', 'waiver-engine' ); ?>
                                </button>
                            </div>

                            <!-- Row template (cloned by JS) -->
                            <template id="pewave-mapping-row-tpl">
                                <?php $this->render_mapping_row( '', [], [], [] ); ?>
                            </template>
                        </div><!-- /.inside -->
                    </div><!-- /.postbox -->

                    <p class="submit">
                        <button type="submit" class="button button-primary button-large">
                            <?php echo $id ? esc_html__( 'Update Template', 'waiver-engine' ) : esc_html__( 'Save Template', 'waiver-engine' ); ?>
                        </button>
                    </p>

                </div><!-- /#post-body-content -->
                </div><!-- /#post-body -->
                </div><!-- /#poststuff -->

           <!-- -----------------------------------------------------------------
               Full-screen mapper modal kept inside the form so that
               all pewave_map_*[] inputs are submitted with the form.
               ----------------------------------------------------------------- -->
        <div id="pewave-mapper-modal" class="pewave-mapper-modal" role="dialog" aria-modal="true" style="display:none;">

            <div class="pewave-modal-header">
                <span class="pewave-modal-title">
                    <span class="dashicons dashicons-location-alt" style="vertical-align:middle;margin-right:6px;"></span>
                    <?php esc_html_e( 'Visual Field Mapper', 'waiver-engine' ); ?>
                </span>
                <div class="pewave-modal-toolbar">
                      <input type="hidden" id="pewave_map_page_count" name="pewave_map_page_count"
                          value="<?php echo esc_attr( $page_count ?: 1 ); ?>">
                    <button type="button" class="button" id="pewave-prev-page">&#8249;</button>
                    <span><?php esc_html_e( 'Pg', 'waiver-engine' ); ?> <strong id="pewave-page-num">1</strong>/<span id="pewave-page-total">1</span></span>
                    <button type="button" class="button" id="pewave-next-page">&#8250;</button>
                    <span class="pewave-draw-hint"><?php esc_html_e( 'Draw on PDF to add a field', 'waiver-engine' ); ?></span>
                </div>
                <button type="button" class="pewave-modal-close" id="pewave-close-mapper"
                        title="<?php esc_attr_e( 'Close mapper (Esc)', 'waiver-engine' ); ?>">
                    <span class="dashicons dashicons-no-alt"></span>
                </button>
            </div>

            <div class="pewave-modal-body">
                <div class="pewave-modal-pdf-pane">
                    <div class="pewave-mapper-hint" id="pewave-mapper-hint">
                        <?php esc_html_e( 'Use the Base PDF picker in Template PeWave_Settings to enable the visual mapper.', 'waiver-engine' ); ?>
                    </div>
                    <div class="pewave-pdf-viewer" id="pewave-pdf-viewer" style="display:none;">
                        <div class="pewave-canvas-wrap" id="pewave-canvas-wrap">
                            <canvas id="pewave-pdf-canvas"></canvas>
                            <canvas id="pewave-draw-canvas"></canvas>
                            <div id="pewave-overlays"></div>
                        </div>
                    </div>
                </div>

                <div class="pewave-modal-table-pane">
                    <p class="description" style="margin:0 0 8px;font-size:12px;">
                        <?php esc_html_e( 'Draw on the PDF to add rows. Fill in Group, Field Key, Label, Type.', 'waiver-engine' ); ?>
                    </p>
                    <div class="pewave-mapping-table-wrap">
                        <table class="widefat pewave-mapping-table" id="pewave-mapping-table">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e( 'Group', 'waiver-engine' ); ?></th>
                                    <th><?php esc_html_e( 'Key', 'waiver-engine' ); ?></th>
                                    <th><?php esc_html_e( 'Label', 'waiver-engine' ); ?></th>
                                    <th><?php esc_html_e( 'Type', 'waiver-engine' ); ?></th>
                                    <th style="width:34px" title="<?php esc_attr_e( 'Required', 'waiver-engine' ); ?>">Req</th>
                                    <?php $this->render_repeatable_header_cell(); ?>
                                    <th style="width:36px"><?php esc_html_e( 'Pg', 'waiver-engine' ); ?></th>
                                    <th><?php esc_html_e( 'Location', 'waiver-engine' ); ?></th>
                                    <th style="width:42px"><?php esc_html_e( 'Font', 'waiver-engine' ); ?></th>
                                    <th style="width:44px" title="<?php esc_attr_e( 'Character spacing in pt \u2014 use to spread text across letter-boxes', 'waiver-engine' ); ?>"><?php esc_html_e( 'Spc', 'waiver-engine' ); ?></th>
                                    <th style="width:64px" title="<?php esc_attr_e( 'Date output format (PHP date format, e.g. d/m/Y). Only used when Type = date.', 'waiver-engine' ); ?>"><?php esc_html_e( 'Date Fmt', 'waiver-engine' ); ?></th>
                                    <th style="width:26px"></th>
                                </tr>
                            </thead>
                            <tbody id="pewave-mapping-tbody">
                                <?php foreach ( $rows as $path => $cfg ) :
                                    $this->render_mapping_row( $path, $cfg, $groups_meta, $schema_type_lookup );
                                endforeach; ?>
                            </tbody>
                        </table>
                        <button type="button" class="button" id="pewave-add-mapping-row" style="margin-top:6px;">
                            <?php esc_html_e( '+ Add Field', 'waiver-engine' ); ?>
                        </button>
                    </div>
                </div>
            </div>

            <div class="pewave-modal-footer">
                <button type="button" class="button button-primary button-hero" id="pewave-close-mapper-done">
                    <?php esc_html_e( 'Done', 'waiver-engine' ); ?>
                </button>
                <span class="pewave-modal-footer-hint">
                    <?php esc_html_e( 'Click Done, then Save/Update Template to persist changes.', 'waiver-engine' ); ?>
                </span>
            </div>
        </div><!-- /#wpwe-mapper-modal -->

            </form>

        <?php if ( $id ) : ?>
           <!-- -----------------------------------------------------------------
               Export / Import field mappings (outside the main form)
               ----------------------------------------------------------------- -->
        <div id="poststuff" style="padding-top:0;">
        <div id="post-body" class="metabox-holder">
        <div id="post-body-content">
            <div class="postbox">
                <div class="postbox-header">
                    <h2><?php esc_html_e( 'Export / Import Field Mappings', 'waiver-engine' ); ?></h2>
                </div>
                <div class="inside">
                    <p><?php esc_html_e( 'Export the field schema and PDF coordinate mappings to migrate this template\'s configuration to another site. The Base PDF itself is not included — upload it separately on the destination site before importing.', 'waiver-engine' ); ?></p>

                    <h3 style="margin-top:0;"><?php esc_html_e( 'Export', 'waiver-engine' ); ?></h3>
                    <a href="<?php echo esc_url( wp_nonce_url(
                        admin_url( 'admin.php?page=pewave-edit&id=' . $id . '&pewave_action=export_mappings' ),
                        'pewave_export_mappings_' . $id
                    ) ); ?>" class="button">
                        <span class="dashicons dashicons-download" style="vertical-align:text-bottom;margin-right:4px;"></span>
                        <?php esc_html_e( 'Download Mapping JSON', 'waiver-engine' ); ?>
                    </a>

                    <h3><?php esc_html_e( 'Import', 'waiver-engine' ); ?></h3>

                    <?php // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only admin notice query vars. ?>
                    <?php if ( isset( $_GET['pewave_imported'] ) ) : ?>
                    <div class="notice notice-success inline" style="margin-bottom:12px;">
                        <p><?php esc_html_e( 'Mappings imported successfully. Review the fields and save to confirm.', 'waiver-engine' ); ?></p>
                    </div>
                    <?php endif; ?>

                    <?php if ( isset( $_GET['pewave_import_error'] ) ) : ?>
                    <div class="notice notice-error inline" style="margin-bottom:12px;">
                        <p><?php esc_html_e( 'Import failed. Please ensure the file is a valid waiver mapping export (.json).', 'waiver-engine' ); ?></p>
                    </div>
                    <?php endif; ?>
                    <?php // phpcs:enable ?>

                    <form method="post"
                                                    action="<?php echo esc_url( admin_url( 'admin.php?page=pewave-edit&id=' . $id ) ); ?>"
                          enctype="multipart/form-data">
                                                <?php wp_nonce_field( 'pewave_import_mappings' ); ?>
                                                <input type="hidden" name="pewave_action"      value="import_mappings">
                                                <input type="hidden" name="pewave_template_id" value="<?php echo esc_attr( $id ); ?>">
                                                <input type="file" name="pewave_import_file" accept=".json,application/json" required style="margin-right:8px;">
                        <button type="submit" class="button"
                                onclick="return confirm('<?php esc_attr_e( 'This will overwrite the current field schema and PDF mapping. Continue?', 'waiver-engine' ); ?>')">
                            <span class="dashicons dashicons-upload" style="vertical-align:text-bottom;margin-right:4px;"></span>
                            <?php esc_html_e( 'Import', 'waiver-engine' ); ?>
                        </button>
                    </form>
                    <p class="description" style="margin-top:8px;">
                        <?php esc_html_e( 'Importing replaces field definitions and PDF coordinate mappings only. Template title, notification email, and other settings are preserved.', 'waiver-engine' ); ?>
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
    // Mapping row renderer (kept identical to previous PeWave_Template_Meta_Box)
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
        <tr class="pewave-mapping-row">
            <td><input type="text" class="pewave-map-group-key small-text" name="pewave_map_group_keys[]"
                       value="<?php echo esc_attr( $group_key ); ?>" placeholder="signer"></td>
            <td><input type="text" class="pewave-map-field-key small-text" name="pewave_map_field_keys[]"
                       value="<?php echo esc_attr( $field_key ); ?>" placeholder="full_name"></td>
            <td><input type="text" class="pewave-map-label" name="pewave_map_labels[]"
                       value="<?php echo esc_attr( $label ); ?>" placeholder="e.g. Full Name"></td>
            <td>
                <select class="pewave-map-type" name="pewave_map_types[]">
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
                <input type="hidden" class="pewave-req-hidden" name="pewave_map_required[]"
                       value="<?php echo $required ? '1' : '0'; ?>">
                <input type="checkbox" class="pewave-map-required" <?php checked( $required ); ?>>
            </td>
            <?php $this->render_repeatable_row_cell( $repeatable ); ?>
            <td><input type="number" class="pewave-map-page small-text" name="pewave_map_pages[]"
                       value="<?php echo esc_attr( $page ); ?>" min="1" max="99"></td>
            <input type="hidden" class="pewave-map-x" name="pewave_map_x[]" value="<?php echo esc_attr( $x ); ?>">
            <input type="hidden" class="pewave-map-y" name="pewave_map_y[]" value="<?php echo esc_attr( $y ); ?>">
            <input type="hidden" class="pewave-map-w" name="pewave_map_w[]" value="<?php echo esc_attr( $width ); ?>">
            <input type="hidden" class="pewave-map-h" name="pewave_map_h[]" value="<?php echo esc_attr( $height ); ?>">
            <td class="pewave-map-location"><?php echo $loc ? esc_html( $loc ) : '<em>draw on PDF</em>'; ?></td>
            <td><input type="number" class="pewave-map-font small-text" name="pewave_map_fonts[]"
                       value="<?php echo esc_attr( $font_size ); ?>" step="0.5" placeholder="11"></td>
            <td><input type="number" class="pewave-map-char-spacing small-text" name="pewave_map_char_spacing[]"
                       value="<?php echo esc_attr( $char_spacing ); ?>" step="0.1" min="0" placeholder="0"
                       title="<?php esc_attr_e( 'Extra character spacing in pt (e.g. 6 for date boxes)', 'waiver-engine' ); ?>"></td>
            <td><input type="text" class="pewave-map-date-format small-text" name="pewave_map_date_format[]"
                       value="<?php echo esc_attr( $date_format ); ?>" placeholder="d/m/Y" maxlength="20"
                       title="<?php esc_attr_e( 'PHP date() format string, e.g. d/m/Y or m-d-Y. Leave blank for default d/m/Y.', 'waiver-engine' ); ?>"></td>
            <td><button type="button" class="button-link pewave-delete-map-row"
                        title="<?php esc_attr_e( 'Delete', 'waiver-engine' ); ?>">
                <span class="dashicons dashicons-trash" style="color:#d63638;vertical-align:middle;"></span>
            </button></td>
        </tr>
        <?php
    }

    // -----------------------------------------------------------------------
    // Static: rebuild mapping + schema from POST (shared with PeWave_Admin_Menu)
    // -----------------------------------------------------------------------

    /**
     * Rebuild both pdf_mapping and field_schema from the unified table POST data.
     *
     * @return array{0: array, 1: array}  [ $mapping, $schema ]
     */
    public static function build_from_post(): array {
        // phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $page_count = isset( $_POST['pewave_map_page_count'] )
            ? max( 1, absint( $_POST['pewave_map_page_count'] ) ) : 1;

        $gk_arr    = self::post_arr( 'pewave_map_group_keys' );
        $fk_arr    = self::post_arr( 'pewave_map_field_keys' );
        $label_arr = self::post_arr( 'pewave_map_labels' );
        $type_arr  = self::post_arr( 'pewave_map_types' );
        $pages_arr = self::post_arr( 'pewave_map_pages' );
        $x_arr     = self::post_arr( 'pewave_map_x' );
        $y_arr     = self::post_arr( 'pewave_map_y' );
        $w_arr     = self::post_arr( 'pewave_map_w' );
        $h_arr     = self::post_arr( 'pewave_map_h' );
        $fonts_arr        = self::post_arr( 'pewave_map_fonts' );
        $char_spacing_arr = self::post_arr( 'pewave_map_char_spacing' );
        $date_format_arr  = self::post_arr( 'pewave_map_date_format' );
        $req_map   = isset( $_POST['pewave_map_required'] )   && is_array( $_POST['pewave_map_required'] )
            ? array_map( 'absint', $_POST['pewave_map_required'] )   : [];
        $rep_map   = self::repeatable_map_from_premium();

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

        $schema = [ 'groups' => array_values( $schema_groups ) ];

        // phpcs:enable
        return [ $mapping, $schema ];
    }

    private static function post_arr( string $key ): array {
        // phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated by caller before build_from_post().
        if ( ! isset( $_POST[ $key ] ) || ! is_array( $_POST[ $key ] ) ) {
            return [];
        }
        return array_values( wp_unslash( $_POST[ $key ] ) );
        // phpcs:enable
    }

    private static function can_use_repeatable_mapping(): bool {
        return class_exists( PeWave_Premium_Bridge::class )
            && method_exists( PeWave_Premium_Bridge::class, 'can_use_repeatable_mapping' )
            && PeWave_Premium_Bridge::can_use_repeatable_mapping();
    }

    private static function repeatable_map_from_premium(): array {
        if ( class_exists( PeWave_Premium_Bridge::class ) && method_exists( PeWave_Premium_Bridge::class, 'build_repeatable_map_from_post' ) ) {
            $map = PeWave_Premium_Bridge::build_repeatable_map_from_post();
            return is_array( $map ) ? $map : [];
        }

        return [];
    }

    private function render_per_row_output_mode_option( string $output_mode ): void {
        if ( class_exists( PeWave_Premium_Bridge::class ) && method_exists( PeWave_Premium_Bridge::class, 'render_per_row_output_mode_option' ) ) {
            PeWave_Premium_Bridge::render_per_row_output_mode_option( $output_mode );
        }
    }

    private function render_output_group_key_control( string $output_mode, string $group_key ): void {
        if ( class_exists( PeWave_Premium_Bridge::class ) && method_exists( PeWave_Premium_Bridge::class, 'render_output_group_key_control' ) ) {
            PeWave_Premium_Bridge::render_output_group_key_control( $output_mode, $group_key );
        }
    }

    private function render_premium_settings_rows( ?object $tpl, string $notify, bool $send_admin_email, bool $send_user_email ): void {
        if ( class_exists( PeWave_Premium_Bridge::class ) && method_exists( PeWave_Premium_Bridge::class, 'render_template_editor_premium_rows' ) ) {
            PeWave_Premium_Bridge::render_template_editor_premium_rows( $tpl, $notify, $send_admin_email, $send_user_email );
        }
    }

    private function render_repeatable_header_cell(): void {
        if ( class_exists( PeWave_Premium_Bridge::class ) && method_exists( PeWave_Premium_Bridge::class, 'render_repeatable_header_cell' ) ) {
            PeWave_Premium_Bridge::render_repeatable_header_cell();
        }
    }

    private function render_repeatable_row_cell( bool $repeatable ): void {
        if ( class_exists( PeWave_Premium_Bridge::class ) && method_exists( PeWave_Premium_Bridge::class, 'render_repeatable_row_cell' ) ) {
            PeWave_Premium_Bridge::render_repeatable_row_cell( $repeatable );
        }
    }
}

