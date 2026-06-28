<?php
namespace Pewave\WaiverEngine;

defined( 'ABSPATH' ) || exit;

/**
 * Admin meta boxes for the pewave_template post type.
 */
class PeWave_Template_Meta_Box {

    public function register(): void {
        add_action( 'add_meta_boxes', [ $this, 'add_boxes' ] );
        add_action( 'save_post_pewave_template', [ $this, 'save' ], 10, 2 );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
    }

    // -----------------------------------------------------------------------
    // Enqueue admin assets
    // -----------------------------------------------------------------------

    public function enqueue( string $hook ): void {
        global $post;
        if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
            return;
        }
        if ( ! $post || $post->post_type !== 'pewave_template' ) {
            return;
        }
        wp_enqueue_media();

        // PDF.js (UMD – exposes window.pdfjsLib)
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

        // Resolve the current PDF URL to pass to JS for live preview
        $pdf_id  = (int) get_post_meta( $post->ID, 'pdf_attachment_id', true );
        $pdf_url = $pdf_id ? wp_get_attachment_url( $pdf_id ) : '';

        // fallback: try pdf_file from mapping
        if ( ! $pdf_url ) {
            $mapping_raw = (string) get_post_meta( $post->ID, 'pdf_mapping', true );
            $mapping     = json_decode( $mapping_raw, true );
            $pdf_file    = $mapping['pdf_file'] ?? '';
            if ( $pdf_file ) {
                $pdf_url = home_url( '/forms/' . basename( $pdf_file ) );
            }
        }

        wp_localize_script( 'wpwe-admin-editor', 'pewaveAdmin', [
            'mediaTitle'   => __( 'Select Base PDF', 'waiver-engine' ),
            'mediaButton'  => __( 'Use this PDF', 'waiver-engine' ),
            'workerSrc'    => \PEWAVE_PLUGIN_URL . 'assets/js/pdfjs/pdf.worker.min.js',
            'currentPdfUrl'=> $pdf_url,
            'i18n'         => [
                'drawHint'    => __( 'Draw a rectangle on the PDF to map a field', 'waiver-engine' ),
                'page'        => __( 'Page', 'waiver-engine' ),
                'prev'        => __( '‹ Prev', 'waiver-engine' ),
                'next'        => __( 'Next ›', 'waiver-engine' ),
                'deleteRow'   => __( 'Delete', 'waiver-engine' ),
                'addRow'      => __( '+ Add Row Manually', 'waiver-engine' ),
                'noSchema'    => __( 'Save the field schema first to populate the field dropdown.', 'waiver-engine' ),
                'noPdf'       => __( 'Select a Base PDF in Template PeWave_Settings to enable the visual mapper.', 'waiver-engine' ),
            ],
        ] );
    }

    // -----------------------------------------------------------------------
    // Register meta boxes
    // -----------------------------------------------------------------------

    public function add_boxes(): void {
        add_meta_box(
            'pewave_template_settings',
            'Template PeWave_Settings',
            [ $this, 'render_settings_box' ],
            'pewave_template',
            'normal',
            'high'
        );
        add_meta_box(
            'pewave_pdf_mapping',
            'Fields & PDF Mapping',
            [ $this, 'render_mapping_box' ],
            'pewave_template',
            'normal',
            'default'
        );
    }

    // -----------------------------------------------------------------------
    // Render: PeWave_Settings
    // -----------------------------------------------------------------------

    public function render_settings_box( \WP_Post $post ): void {
        wp_nonce_field( 'pewave_save_template_' . $post->ID, 'pewave_template_nonce' );
        $pdf_id       = (int) get_post_meta( $post->ID, 'pdf_attachment_id', true );
        $description  = (string) get_post_meta( $post->ID, 'description', true );
        $output_mode  = (string) get_post_meta( $post->ID, 'output_mode', true ) ?: 'single';
        $group_key    = (string) get_post_meta( $post->ID, 'output_group_key', true );
        $active       = (bool)   get_post_meta( $post->ID, 'active', true );
        $notify_email = (string) get_post_meta( $post->ID, 'notification_email', true );
        $pdf_url      = $pdf_id ? wp_get_attachment_url( $pdf_id ) : '';
        ?>
        <table class="form-table wpwe-settings-table">
            <tr>
                <th><label for="pewave_pdf_attachment_id"><?php esc_html_e( 'Base PDF', 'waiver-engine' ); ?></label></th>
                <td>
                    <input type="hidden" id="pewave_pdf_attachment_id" name="pewave_pdf_attachment_id"
                           value="<?php echo esc_attr( $pdf_id ); ?>">
                    <button type="button" class="button" id="pewave_select_pdf">
                        <?php esc_html_e( 'Select PDF from Media Library', 'waiver-engine' ); ?>
                    </button>
                    <span id="pewave_pdf_name" style="margin-left:10px;">
                        <?php echo $pdf_url ? '<a href="' . esc_url( $pdf_url ) . '" target="_blank">' . esc_html( basename( $pdf_url ) ) . '</a>' : esc_html__( 'No PDF selected', 'waiver-engine' ); ?>
                    </span>
                    <p class="description"><?php esc_html_e( 'Or reference a built-in form by filename (see PDF Mapping notes).', 'waiver-engine' ); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="pewave_description"><?php esc_html_e( 'Description', 'waiver-engine' ); ?></label></th>
                <td>
                    <textarea id="pewave_description" name="pewave_description" rows="3" class="large-text"><?php echo esc_textarea( $description ); ?></textarea>
                </td>
            </tr>
            <tr>
                <th><label for="pewave_output_mode"><?php esc_html_e( 'Output Mode', 'waiver-engine' ); ?></label></th>
                <td>
                    <select id="pewave_output_mode" name="pewave_output_mode">
                        <option value="single" <?php selected( $output_mode, 'single' ); ?>>
                            <?php esc_html_e( 'Single PDF per submission', 'waiver-engine' ); ?>
                        </option>
                        <?php $this->render_per_row_output_mode_option( $output_mode ); ?>
                    </select>
                    <?php $this->render_output_group_key_control( $output_mode, $group_key ); ?>
                </td>
            </tr>
            <?php $this->render_premium_settings_rows( $post, $notify_email ); ?>
            <tr>
                <th><label for="pewave_active"><?php esc_html_e( 'Active', 'waiver-engine' ); ?></label></th>
                <td>
                    <input type="checkbox" id="pewave_active" name="pewave_active" value="1"
                        <?php checked( $active, true ); ?>>
                    <label for="pewave_active"><?php esc_html_e( 'Make this template available on the frontend', 'waiver-engine' ); ?></label>
                </td>
            </tr>

        </table>
        <?php
    }

    // -----------------------------------------------------------------------
    // Render: Fields & PDF Mapping  (unified visual drag-to-map + table)
    // -----------------------------------------------------------------------

    public function render_mapping_box( \WP_Post $post ): void {
        $raw     = (string) get_post_meta( $post->ID, 'pdf_mapping', true );
        $mapping = $raw ? ( json_decode( $raw, true ) ?: [] ) : [];
        $rows    = ( isset( $mapping['fields'] ) && is_array( $mapping['fields'] ) )
                   ? $mapping['fields'] : [];
        $pdf_file   = $mapping['pdf_file']   ?? '';
        $page_count = (int) ( $mapping['page_count'] ?? 1 );
        // Load group metadata stored alongside fields
        $groups_meta = $mapping['groups'] ?? [];
        ?>

        <!-- Hidden JSON store – updated by JS before form submit -->
        <input type="hidden" id="pewave_pdf_mapping" name="pewave_pdf_mapping" value="<?php echo esc_attr( $raw ); ?>">

        <!-- -- Meta box summary: field count + open button --------------- -->
        <div class="pewave-mapper-summary">
            <span id="pewave-mapper-summary-text" class="pewave-mapper-summary-text">
                <?php
                $field_count = count( $rows );
                if ( $pdf_file && $field_count ) {
                    echo esc_html( sprintf(
                        /* translators: 1: PDF filename, 2: number of fields */
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

        <!-- Row template (cloned by JS, lives outside modal) -->
        <template id="pewave-mapping-row-tpl">
            <?php $this->render_mapping_row( '', [], [] ); ?>
        </template>

           <!-- -----------------------------------------------------------------
               Full-screen mapper modal
               ----------------------------------------------------------------- -->
        <div id="pewave-mapper-modal" class="pewave-mapper-modal" role="dialog" aria-modal="true" style="display:none;">

            <!-- Modal header / toolbar -->
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

            <!-- Two-panel body -->
            <div class="pewave-modal-body">

                <!-- Left: PDF canvas -->
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

                <!-- Right: Field table -->
                <div class="pewave-modal-table-pane">
                    <p class="description" style="margin:0 0 8px;font-size:12px;">
                        <?php esc_html_e( 'Draw on the PDF to add rows. Fill in Group, Field Key, Label, Type — both the frontend form and PDF overlay are generated from this table.', 'waiver-engine' ); ?>
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
                                    <th style="width:26px"></th>
                                </tr>
                            </thead>
                            <tbody id="pewave-mapping-tbody">
                            <?php foreach ( $rows as $path => $cfg ) :
                                $this->render_mapping_row( $path, $cfg, $groups_meta );
                            endforeach; ?>
                            </tbody>
                        </table>
                        <button type="button" class="button" id="pewave-add-mapping-row" style="margin-top:6px;">
                            <?php esc_html_e( '+ Add Field', 'waiver-engine' ); ?>
                        </button>
                    </div>
                </div>

            </div><!-- /.wpwe-modal-body -->

            <div class="pewave-modal-footer">
                <button type="button" class="button button-primary button-hero" id="pewave-close-mapper-done">
                    <?php esc_html_e( 'Done', 'waiver-engine' ); ?>
                </button>
                <span class="pewave-modal-footer-hint">
                    <?php esc_html_e( 'Changes are held in memory — click Save/Update in the post editor to persist.', 'waiver-engine' ); ?>
                </span>
            </div>

        </div><!-- /#pewave-mapper-modal -->
        <?php
    }

    /**
     * Render a single unified field+mapping table row.
     *
     * @param string $path        Dot-notation path e.g. "signer.full_name" (empty for template row)
     * @param array  $cfg         Mapping config array from pdf_mapping.fields[ $path ]
     * @param array  $groups_meta groups metadata stored in pdf_mapping.groups
     */
    private function render_mapping_row( string $path, array $cfg, array $groups_meta ): void {
        // Parse path into group/field keys
        $parts     = $path ? explode( '.', $path, 2 ) : [ '', '' ];
        $group_key = $parts[0] ?? '';
        $field_key = $parts[1] ?? '';

        // Schema-level attributes stored in mapping.groups[ group_key ]
        $gm        = $groups_meta[ $group_key ] ?? [];
        $label     = $cfg['label']      ?? '';
        $required  = ! empty( $cfg['required'] );
        $repeatable= ! empty( $gm['repeatable'] );

        // Mapping/PDF attributes
        $type      = $cfg['type']      ?? 'text';
        $page      = (int) ( $cfg['page']      ?? 1 );
        $x         = $cfg['x']         ?? '';
        $y         = $cfg['y']         ?? '';
        $width     = $cfg['width']     ?? '';
        $height    = $cfg['height']    ?? '';
        $font_size = $cfg['font_size'] ?? '';

        $loc = '';
        if ( $x !== '' && $y !== '' ) {
            $loc = 'pg.' . $page . ' · ' . round( (float) $x, 1 ) . ', ' . round( (float) $y, 1 );
            if ( $width !== '' && $height !== '' ) {
                $loc .= ' · ' . round( (float) $width, 1 ) . '×' . round( (float) $height, 1 );
            }
        }
        ?>
        <tr class="pewave-mapping-row">
            <!-- Group Key -->
            <td><input type="text" class="pewave-map-group-key small-text" name="pewave_map_group_keys[]"
                       value="<?php echo esc_attr( $group_key ); ?>" placeholder="signer"></td>
            <!-- Field Key -->
            <td><input type="text" class="pewave-map-field-key small-text" name="pewave_map_field_keys[]"
                       value="<?php echo esc_attr( $field_key ); ?>" placeholder="full_name"></td>
            <!-- Label -->
            <td><input type="text" class="pewave-map-label" name="pewave_map_labels[]"
                       value="<?php echo esc_attr( $label ); ?>" placeholder="e.g. Full Name"></td>
            <!-- Type -->
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
            <!-- Required -->
            <td style="text-align:center">
                <input type="hidden" class="pewave-req-hidden" name="pewave_map_required[]" value="<?php echo $required ? '1' : '0'; ?>">
                <input type="checkbox" class="pewave-map-required" <?php checked( $required ); ?>>
            </td>
            <?php $this->render_repeatable_row_cell( $repeatable ); ?>
            <!-- Page -->
            <td><input type="number" class="pewave-map-page small-text" name="pewave_map_pages[]"
                       value="<?php echo esc_attr( $page ); ?>" min="1" max="99"></td>
            <!-- X/Y/W/H – hidden, set by canvas draw -->
            <input type="hidden" class="pewave-map-x" name="pewave_map_x[]" value="<?php echo esc_attr( $x ); ?>">
            <input type="hidden" class="pewave-map-y" name="pewave_map_y[]" value="<?php echo esc_attr( $y ); ?>">
            <input type="hidden" class="pewave-map-w" name="pewave_map_w[]" value="<?php echo esc_attr( $width ); ?>">
            <input type="hidden" class="pewave-map-h" name="pewave_map_h[]" value="<?php echo esc_attr( $height ); ?>">
            <!-- Location badge -->
            <td class="pewave-map-location"><?php echo $loc ? esc_html( $loc ) : '<em>draw on PDF</em>'; ?></td>
            <!-- Font size -->
            <td><input type="number" class="pewave-map-font small-text" name="pewave_map_fonts[]"
                       value="<?php echo esc_attr( $font_size ); ?>" step="0.5" placeholder="11"></td>
            <!-- Delete -->
            <td><button type="button" class="button-link pewave-delete-map-row" title="<?php esc_attr_e( 'Delete', 'waiver-engine' ); ?>">
                <span class="dashicons dashicons-trash" style="color:#d63638;vertical-align:middle;"></span>
            </button></td>
        </tr>
        <?php
    }

    // -----------------------------------------------------------------------
    // Save
    // -----------------------------------------------------------------------

    public function save( int $post_id, \WP_Post $post ): void {
        // Security checks
        if ( ! isset( $_POST['pewave_template_nonce'] ) ) {
            return;
        }
        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['pewave_template_nonce'] ) ), 'pewave_save_template_' . $post_id ) ) {
            return;
        }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // pdf_attachment_id
        $pdf_id = isset( $_POST['pewave_pdf_attachment_id'] ) ? absint( $_POST['pewave_pdf_attachment_id'] ) : 0;
        update_post_meta( $post_id, 'pdf_attachment_id', $pdf_id );

        // description
        $desc = isset( $_POST['pewave_description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['pewave_description'] ) ) : '';
        update_post_meta( $post_id, 'description', $desc );

        $premium_data = $this->build_template_premium_data_from_post();

        update_post_meta( $post_id, 'output_mode', (string) ( $premium_data['output_mode'] ?? 'single' ) );
        update_post_meta( $post_id, 'output_group_key', (string) ( $premium_data['output_group_key'] ?? '' ) );
        update_post_meta( $post_id, 'notification_email', (string) ( $premium_data['notification_email'] ?? '' ) );
        update_post_meta( $post_id, 'send_admin_email', (int) ( $premium_data['send_admin_email'] ?? 0 ) );
        update_post_meta( $post_id, 'send_user_email', (int) ( $premium_data['send_user_email'] ?? 0 ) );

        // active
        $active = isset( $_POST['pewave_active'] ) ? true : false;
        update_post_meta( $post_id, 'active', $active );

        update_post_meta( $post_id, 'amelia_service_ids', wp_json_encode( array_values( (array) ( $premium_data['amelia_service_ids'] ?? [] ) ) ) );

        // Build both pdf_mapping and field_schema from the unified table POST data
        [ $mapping, $schema ] = $this->build_from_unified_post();
        update_post_meta( $post_id, 'pdf_mapping',   wp_json_encode( $mapping ) );
        update_post_meta( $post_id, 'field_schema',  wp_json_encode( $schema ) );
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Rebuild both pdf_mapping and field_schema from the unified table POST data.
     *
     * POST arrays (indexed arrays, one element per row):
    *   pewave_map_group_keys[], pewave_map_field_keys[], pewave_map_labels[],
    *   pewave_map_types[], pewave_map_required[], pewave_map_repeatable[],
    *   pewave_map_pages[], pewave_map_x[], pewave_map_y[], pewave_map_w[], pewave_map_h[],
    *   pewave_map_fonts[]
     *
    * pewave_map_required[] and pewave_map_repeatable[] only POST for checked rows,
     * so we use separate index tracking via a bitmask array.
     *
     * @return array{0: array, 1: array}  [ $mapping, $schema ]
     */
    private function build_from_unified_post(): array {
        // phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $page_count = isset( $_POST['pewave_map_page_count'] ) ? max( 1, absint( $_POST['pewave_map_page_count'] ) ) : 1;

        $gk_arr     = $this->post_arr( 'pewave_map_group_keys' );
        $fk_arr     = $this->post_arr( 'pewave_map_field_keys' );
        $label_arr  = $this->post_arr( 'pewave_map_labels' );
        $type_arr   = $this->post_arr( 'pewave_map_types' );
        $pages_arr  = $this->post_arr( 'pewave_map_pages' );
        $x_arr      = $this->post_arr( 'pewave_map_x' );
        $y_arr      = $this->post_arr( 'pewave_map_y' );
        $w_arr      = $this->post_arr( 'pewave_map_w' );
        $h_arr      = $this->post_arr( 'pewave_map_h' );
        $fonts_arr  = $this->post_arr( 'pewave_map_fonts' );
        // Checkboxes: posted as associative array with row index as key
        $req_map    = isset( $_POST['pewave_map_required'] )   && is_array( $_POST['pewave_map_required'] )   ? array_map( 'absint', $_POST['pewave_map_required'] )   : [];
        $rep_map    = self::repeatable_map_from_premium();

        $allowed_types = [ 'text', 'email', 'number', 'date', 'tel', 'textarea', 'select', 'checkbox', 'signature', 'image' ];

        // Accumulators
        $mapping_fields = [];
        $groups_meta    = []; // keyed by group_key: { repeatable, min_rows }
        // For schema: group_key => [ label_for_group, fields[] ]
        $schema_groups  = [];

        $count = count( $gk_arr );
        for ( $i = 0; $i < $count; $i++ ) {
            $gk = sanitize_key( wp_unslash( $gk_arr[ $i ] ?? '' ) );
            $fk = sanitize_key( wp_unslash( $fk_arr[ $i ] ?? '' ) );
            if ( ! $gk || ! $fk ) continue; // skip incomplete rows

            $path      = $gk . '.' . $fk;
            $label     = sanitize_text_field( wp_unslash( $label_arr[ $i ] ?? '' ) );
            $type      = in_array( $type_arr[ $i ] ?? '', $allowed_types, true ) ? $type_arr[ $i ] : 'text';
            $required   = (bool) ( $req_map[ $i ] ?? 0 );
            $repeatable = (bool) ( $rep_map[ $i ] ?? 0 );
            $page      = max( 1, absint( $pages_arr[ $i ] ?? 1 ) );
            $x         = is_numeric( $x_arr[ $i ] ?? '' ) ? (float) $x_arr[ $i ] : 0.0;
            $y         = is_numeric( $y_arr[ $i ] ?? '' ) ? (float) $y_arr[ $i ] : 0.0;
            $width     = is_numeric( $w_arr[ $i ] ?? '' ) ? (float) $w_arr[ $i ] : 0.0;
            $height    = is_numeric( $h_arr[ $i ] ?? '' ) ? (float) $h_arr[ $i ] : 0.0;
            $font_size = is_numeric( $fonts_arr[ $i ] ?? '' ) ? (float) $fonts_arr[ $i ] : 11.0;

            // --- pdf_mapping entry ---
            // For form-type fields (not raw image overlay) store as text in PDF
            $pdf_type = ( $type === 'signature' ) ? 'image' : ( ( $type === 'image' ) ? 'image' : 'text' );
            $entry = [
                'page'      => $page,
                'x'         => $x,
                'y'         => $y,
                'type'      => $pdf_type,
                'label'     => $label,
                'required'  => $required,
            ];
            if ( $width  > 0 ) $entry['width']   = $width;
            if ( $height > 0 ) $entry['height']  = $height;
            if ( $pdf_type !== 'image' ) $entry['font_size'] = $font_size;
            $mapping_fields[ $path ] = $entry;

            // --- groups_meta (repeatable flag, stored in mapping for round-trip) ---
            if ( ! isset( $groups_meta[ $gk ] ) ) {
                $groups_meta[ $gk ] = [ 'repeatable' => $repeatable ];
            } elseif ( $repeatable ) {
                $groups_meta[ $gk ]['repeatable'] = true;
            }

            // --- field_schema accumulation ---
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

    /** Safe helper: returns a numerically-indexed array from a POST key, sanitized. */
    private function post_arr( string $key ): array {
        // phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated by save() before helper usage.
        if ( ! isset( $_POST[ $key ] ) || ! is_array( $_POST[ $key ] ) ) {
            return [];
        }
        return array_values( wp_unslash( $_POST[ $key ] ) );
        // phpcs:enable
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

        if ( class_exists( PeWave_Premium_Bridge::class ) && method_exists( PeWave_Premium_Bridge::class, 'build_template_premium_data_from_post' ) ) {
            $premium = PeWave_Premium_Bridge::build_template_premium_data_from_post();
            if ( is_array( $premium ) ) {
                return array_merge( $defaults, $premium );
            }
        }

        return $defaults;
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

    private static function sanitize_template_output_mode( string $requested_mode ): string {
        if ( class_exists( PeWave_Premium_Bridge::class ) && method_exists( PeWave_Premium_Bridge::class, 'sanitize_template_output_mode' ) ) {
            return PeWave_Premium_Bridge::sanitize_template_output_mode( $requested_mode );
        }

        if ( ! in_array( $requested_mode, [ 'single', 'per_row' ], true ) ) {
            return 'single';
        }

        return $requested_mode;
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

    private function render_premium_settings_rows( \WP_Post $post, string $notify_email ): void {
        if ( class_exists( PeWave_Premium_Bridge::class ) && method_exists( PeWave_Premium_Bridge::class, 'render_template_meta_box_premium_rows' ) ) {
            PeWave_Premium_Bridge::render_template_meta_box_premium_rows( $post, $notify_email );
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

