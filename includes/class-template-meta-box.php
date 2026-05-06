<?php
namespace WPWE;

defined( 'ABSPATH' ) || exit;

/**
 * Admin meta boxes for the waiver_template post type.
 */
class Template_Meta_Box {

    public function register(): void {
        add_action( 'add_meta_boxes', [ $this, 'add_boxes' ] );
        add_action( 'save_post_waiver_template', [ $this, 'save' ], 10, 2 );
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
        if ( ! $post || $post->post_type !== 'waiver_template' ) {
            return;
        }
        wp_enqueue_media();

        // PDF.js (UMD – exposes window.pdfjsLib)
        wp_enqueue_script(
            'pdfjs',
            \WPWE_PLUGIN_URL . 'assets/js/pdfjs/pdf.min.js',
            [],
            '3.11.174',
            true
        );

        wp_enqueue_script(
            'wpwe-admin-editor',
            \WPWE_PLUGIN_URL . 'assets/js/admin-editor.js',
            [ 'jquery', 'pdfjs' ],
            \WPWE_VERSION,
            true
        );
        wp_enqueue_style(
            'wpwe-admin',
            \WPWE_PLUGIN_URL . 'assets/css/admin.css',
            [],
            \WPWE_VERSION
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

        wp_localize_script( 'wpwe-admin-editor', 'wpweAdmin', [
            'mediaTitle'   => __( 'Select Base PDF', 'wp-waiver-engine' ),
            'mediaButton'  => __( 'Use this PDF', 'wp-waiver-engine' ),
            'workerSrc'    => \WPWE_PLUGIN_URL . 'assets/js/pdfjs/pdf.worker.min.js',
            'currentPdfUrl'=> $pdf_url,
            'i18n'         => [
                'drawHint'    => __( 'Draw a rectangle on the PDF to map a field', 'wp-waiver-engine' ),
                'page'        => __( 'Page', 'wp-waiver-engine' ),
                'prev'        => __( '‹ Prev', 'wp-waiver-engine' ),
                'next'        => __( 'Next ›', 'wp-waiver-engine' ),
                'deleteRow'   => __( 'Delete', 'wp-waiver-engine' ),
                'addRow'      => __( '+ Add Row Manually', 'wp-waiver-engine' ),
                'noSchema'    => __( 'Save the field schema first to populate the field dropdown.', 'wp-waiver-engine' ),
                'noPdf'       => __( 'Select a Base PDF in Template Settings to enable the visual mapper.', 'wp-waiver-engine' ),
            ],
        ] );
    }

    // -----------------------------------------------------------------------
    // Register meta boxes
    // -----------------------------------------------------------------------

    public function add_boxes(): void {
        add_meta_box(
            'wpwe_template_settings',
            'Template Settings',
            [ $this, 'render_settings_box' ],
            'waiver_template',
            'normal',
            'high'
        );
        add_meta_box(
            'wpwe_pdf_mapping',
            'Fields & PDF Mapping',
            [ $this, 'render_mapping_box' ],
            'waiver_template',
            'normal',
            'default'
        );
    }

    // -----------------------------------------------------------------------
    // Render: Settings
    // -----------------------------------------------------------------------

    public function render_settings_box( \WP_Post $post ): void {
        wp_nonce_field( 'wpwe_save_template_' . $post->ID, 'wpwe_template_nonce' );
        $pdf_id       = (int) get_post_meta( $post->ID, 'pdf_attachment_id', true );
        $description  = (string) get_post_meta( $post->ID, 'description', true );
        $output_mode  = (string) get_post_meta( $post->ID, 'output_mode', true ) ?: 'single';
        $group_key    = (string) get_post_meta( $post->ID, 'output_group_key', true );
        $active       = (bool)   get_post_meta( $post->ID, 'active', true );
        $notify_email = (string) get_post_meta( $post->ID, 'notification_email', true );
        $pdf_url      = $pdf_id ? wp_get_attachment_url( $pdf_id ) : '';
        $has_premium_editor = function_exists( 'wwe_fs' ) && wwe_fs() && wwe_fs()->is__premium_only();
        ?>
        <table class="form-table wpwe-settings-table">
            <tr>
                <th><label for="wpwe_pdf_attachment_id"><?php esc_html_e( 'Base PDF', 'wp-waiver-engine' ); ?></label></th>
                <td>
                    <input type="hidden" id="wpwe_pdf_attachment_id" name="wpwe_pdf_attachment_id"
                           value="<?php echo esc_attr( $pdf_id ); ?>">
                    <button type="button" class="button" id="wpwe_select_pdf">
                        <?php esc_html_e( 'Select PDF from Media Library', 'wp-waiver-engine' ); ?>
                    </button>
                    <span id="wpwe_pdf_name" style="margin-left:10px;">
                        <?php echo $pdf_url ? '<a href="' . esc_url( $pdf_url ) . '" target="_blank">' . esc_html( basename( $pdf_url ) ) . '</a>' : esc_html__( 'No PDF selected', 'wp-waiver-engine' ); ?>
                    </span>
                    <p class="description"><?php esc_html_e( 'Or reference a built-in form by filename (see PDF Mapping notes).', 'wp-waiver-engine' ); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="wpwe_description"><?php esc_html_e( 'Description', 'wp-waiver-engine' ); ?></label></th>
                <td>
                    <textarea id="wpwe_description" name="wpwe_description" rows="3" class="large-text"><?php echo esc_textarea( $description ); ?></textarea>
                </td>
            </tr>
            <tr>
                <th><label for="wpwe_output_mode"><?php esc_html_e( 'Output Mode', 'wp-waiver-engine' ); ?></label></th>
                <td>
                    <select id="wpwe_output_mode" name="wpwe_output_mode">
                        <option value="single" <?php selected( $output_mode, 'single' ); ?>>
                            <?php esc_html_e( 'Single PDF per submission', 'wp-waiver-engine' ); ?>
                        </option>
                        <?php if ( $has_premium_editor ) : ?>
                        <option value="per_row" <?php selected( $output_mode, 'per_row' ); ?>>
                            <?php esc_html_e( 'One PDF per row (repeatable group)', 'wp-waiver-engine' ); ?>
                        </option>
                        <?php endif; ?>
                    </select>
                    <?php if ( $has_premium_editor ) : ?>
                    <div id="wpwe_group_key_wrap" style="margin-top:8px; <?php echo $output_mode !== 'per_row' ? 'display:none;' : ''; ?>">
                        <label for="wpwe_output_group_key"><?php esc_html_e( 'Repeatable Group Key:', 'wp-waiver-engine' ); ?></label>
                        <input type="text" id="wpwe_output_group_key" name="wpwe_output_group_key"
                               value="<?php echo esc_attr( $group_key ); ?>" class="regular-text"
                               placeholder="e.g. participants">
                    </div>
                    <?php endif; ?>
                </td>
            </tr>
            <?php if ( $has_premium_editor ) : ?>
            <tr>
                <th><label for="wpwe_notification_email"><?php esc_html_e( 'Notification Email', 'wp-waiver-engine' ); ?></label></th>
                <td>
                    <input type="email" id="wpwe_notification_email" name="wpwe_notification_email"
                           value="<?php echo esc_attr( $notify_email ); ?>" class="regular-text"
                           placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>">
                    <p class="description"><?php esc_html_e( 'Leave blank to use the site admin email. PDF(s) will be attached to the notification.', 'wp-waiver-engine' ); ?></p>
                </td>
            </tr>
            <?php endif; ?>
            <tr>
                <th><label for="wpwe_active"><?php esc_html_e( 'Active', 'wp-waiver-engine' ); ?></label></th>
                <td>
                    <input type="checkbox" id="wpwe_active" name="wpwe_active" value="1"
                        <?php checked( $active, true ); ?>>
                    <label for="wpwe_active"><?php esc_html_e( 'Make this template available on the frontend', 'wp-waiver-engine' ); ?></label>
                </td>
            </tr>
            <?php if ( $has_premium_editor ) : ?>
            <tr>
                <th><?php esc_html_e( 'Linked Amelia Services', 'wp-waiver-engine' ); ?></th>
                <td>
                    <?php
                    $saved_ids     = json_decode( (string) get_post_meta( $post->ID, 'amelia_service_ids', true ), true ) ?: [];
                    $saved_ids     = array_map( 'intval', $saved_ids );
                    $amelia_svc    = Integration_Amelia::get_services();
                    if ( empty( $amelia_svc ) ) : ?>
                        <p class="description"><?php esc_html_e( 'No Amelia services found. Activate the Amelia plugin and create services first.', 'wp-waiver-engine' ); ?></p>
                    <?php else : ?>
                        <fieldset>
                            <?php foreach ( $amelia_svc as $svc ) :
                                $svc_id = (int) $svc['id'];
                                $checked = in_array( $svc_id, $saved_ids, true );
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
            <?php endif; ?>
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
        $has_premium_editor = function_exists( 'wwe_fs' ) && wwe_fs() && wwe_fs()->is__premium_only();

        // Load group metadata stored alongside fields
        $groups_meta = $mapping['groups'] ?? [];
        ?>

        <!-- Hidden JSON store – updated by JS before form submit -->
        <input type="hidden" id="wpwe_pdf_mapping" name="wpwe_pdf_mapping" value="<?php echo esc_attr( $raw ); ?>">

        <!-- ── Meta box summary: field count + open button ─────────────── -->
        <div class="wpwe-mapper-summary">
            <span id="wpwe-mapper-summary-text" class="wpwe-mapper-summary-text">
                <?php
                $field_count = count( $rows );
                if ( $pdf_file && $field_count ) {
                    echo esc_html( sprintf(
                        /* translators: 1: PDF filename, 2: number of fields */
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

        <!-- Row template (cloned by JS, lives outside modal) -->
        <template id="wpwe-mapping-row-tpl">
            <?php $this->render_mapping_row( '', [], [] ); ?>
        </template>

        <!-- ══════════════════════════════════════════════════════════════
             Full-screen mapper modal
             ══════════════════════════════════════════════════════════════ -->
        <div id="wpwe-mapper-modal" class="wpwe-mapper-modal" role="dialog" aria-modal="true" style="display:none;">

            <!-- Modal header / toolbar -->
            <div class="wpwe-modal-header">
                <span class="wpwe-modal-title">
                    <span class="dashicons dashicons-location-alt" style="vertical-align:middle;margin-right:6px;"></span>
                    <?php esc_html_e( 'Visual Field Mapper', 'wp-waiver-engine' ); ?>
                </span>
                <div class="wpwe-modal-toolbar">
                      <input type="hidden" id="wpwe_map_page_count" name="wpwe_map_page_count"
                          value="<?php echo esc_attr( $page_count ?: 1 ); ?>">
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

            <!-- Two-panel body -->
            <div class="wpwe-modal-body">

                <!-- Left: PDF canvas -->
                <div class="wpwe-modal-pdf-pane">
                    <div class="wpwe-mapper-hint" id="wpwe-mapper-hint">
                        <?php esc_html_e( 'Use the Base PDF picker in Template Settings to enable the visual mapper.', 'wp-waiver-engine' ); ?>
                    </div>
                    <div class="wpwe-pdf-viewer" id="wpwe-pdf-viewer" style="display:none;">
                        <div class="wpwe-canvas-wrap" id="wpwe-canvas-wrap">
                            <canvas id="wpwe-pdf-canvas"></canvas>
                            <canvas id="wpwe-draw-canvas"></canvas>
                            <div id="wpwe-overlays"></div>
                        </div>
                    </div>
                </div>

                <!-- Right: Field table -->
                <div class="wpwe-modal-table-pane">
                    <p class="description" style="margin:0 0 8px;font-size:12px;">
                        <?php esc_html_e( 'Draw on the PDF to add rows. Fill in Group, Field Key, Label, Type — both the frontend form and PDF overlay are generated from this table.', 'wp-waiver-engine' ); ?>
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
                                    <?php if ( $has_premium_editor ) : ?>
                                    <th style="width:34px" title="<?php esc_attr_e( 'Repeatable group', 'wp-waiver-engine' ); ?>">Rpt</th>
                                    <?php endif; ?>
                                    <th style="width:36px"><?php esc_html_e( 'Pg', 'wp-waiver-engine' ); ?></th>
                                    <th><?php esc_html_e( 'Location', 'wp-waiver-engine' ); ?></th>
                                    <th style="width:42px"><?php esc_html_e( 'Font', 'wp-waiver-engine' ); ?></th>
                                    <th style="width:26px"></th>
                                </tr>
                            </thead>
                            <tbody id="wpwe-mapping-tbody">
                            <?php foreach ( $rows as $path => $cfg ) :
                                $this->render_mapping_row( $path, $cfg, $groups_meta );
                            endforeach; ?>
                            </tbody>
                        </table>
                        <button type="button" class="button" id="wpwe-add-mapping-row" style="margin-top:6px;">
                            <?php esc_html_e( '+ Add Field', 'wp-waiver-engine' ); ?>
                        </button>
                    </div>
                </div>

            </div><!-- /.wpwe-modal-body -->

            <div class="wpwe-modal-footer">
                <button type="button" class="button button-primary button-hero" id="wpwe-close-mapper-done">
                    <?php esc_html_e( 'Done', 'wp-waiver-engine' ); ?>
                </button>
                <span class="wpwe-modal-footer-hint">
                    <?php esc_html_e( 'Changes are held in memory — click Save/Update in the post editor to persist.', 'wp-waiver-engine' ); ?>
                </span>
            </div>

        </div><!-- /#wpwe-mapper-modal -->
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
        $has_premium_editor = function_exists( 'wwe_fs' ) && wwe_fs() && wwe_fs()->is__premium_only();

        $loc = '';
        if ( $x !== '' && $y !== '' ) {
            $loc = 'pg.' . $page . ' · ' . round( (float) $x, 1 ) . ', ' . round( (float) $y, 1 );
            if ( $width !== '' && $height !== '' ) {
                $loc .= ' · ' . round( (float) $width, 1 ) . '×' . round( (float) $height, 1 );
            }
        }
        ?>
        <tr class="wpwe-mapping-row">
            <!-- Group Key -->
            <td><input type="text" class="wpwe-map-group-key small-text" name="wpwe_map_group_keys[]"
                       value="<?php echo esc_attr( $group_key ); ?>" placeholder="signer"></td>
            <!-- Field Key -->
            <td><input type="text" class="wpwe-map-field-key small-text" name="wpwe_map_field_keys[]"
                       value="<?php echo esc_attr( $field_key ); ?>" placeholder="full_name"></td>
            <!-- Label -->
            <td><input type="text" class="wpwe-map-label" name="wpwe_map_labels[]"
                       value="<?php echo esc_attr( $label ); ?>" placeholder="e.g. Full Name"></td>
            <!-- Type -->
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
            <!-- Required -->
            <td style="text-align:center">
                <input type="hidden" class="wpwe-req-hidden" name="wpwe_map_required[]" value="<?php echo $required ? '1' : '0'; ?>">
                <input type="checkbox" class="wpwe-map-required" <?php checked( $required ); ?>>
            </td>
            <?php if ( $has_premium_editor ) : ?>
            <!-- Repeatable group -->
            <td style="text-align:center">
                <input type="hidden" class="wpwe-rep-hidden" name="wpwe_map_repeatable[]" value="<?php echo $repeatable ? '1' : '0'; ?>">
                <input type="checkbox" class="wpwe-map-repeatable" <?php checked( $repeatable ); ?>>
            </td>
            <?php endif; ?>
            <!-- Page -->
            <td><input type="number" class="wpwe-map-page small-text" name="wpwe_map_pages[]"
                       value="<?php echo esc_attr( $page ); ?>" min="1" max="99"></td>
            <!-- X/Y/W/H – hidden, set by canvas draw -->
            <input type="hidden" class="wpwe-map-x" name="wpwe_map_x[]" value="<?php echo esc_attr( $x ); ?>">
            <input type="hidden" class="wpwe-map-y" name="wpwe_map_y[]" value="<?php echo esc_attr( $y ); ?>">
            <input type="hidden" class="wpwe-map-w" name="wpwe_map_w[]" value="<?php echo esc_attr( $width ); ?>">
            <input type="hidden" class="wpwe-map-h" name="wpwe_map_h[]" value="<?php echo esc_attr( $height ); ?>">
            <!-- Location badge -->
            <td class="wpwe-map-location"><?php echo $loc ? esc_html( $loc ) : '<em>draw on PDF</em>'; ?></td>
            <!-- Font size -->
            <td><input type="number" class="wpwe-map-font small-text" name="wpwe_map_fonts[]"
                       value="<?php echo esc_attr( $font_size ); ?>" step="0.5" placeholder="11"></td>
            <!-- Delete -->
            <td><button type="button" class="button-link wpwe-delete-map-row" title="<?php esc_attr_e( 'Delete', 'wp-waiver-engine' ); ?>">
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
        if ( ! isset( $_POST['wpwe_template_nonce'] ) ) {
            return;
        }
        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wpwe_template_nonce'] ) ), 'wpwe_save_template_' . $post_id ) ) {
            return;
        }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // pdf_attachment_id
        $pdf_id = isset( $_POST['wpwe_pdf_attachment_id'] ) ? absint( $_POST['wpwe_pdf_attachment_id'] ) : 0;
        update_post_meta( $post_id, 'pdf_attachment_id', $pdf_id );

        // description
        $desc = isset( $_POST['wpwe_description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['wpwe_description'] ) ) : '';
        update_post_meta( $post_id, 'description', $desc );

        // output_mode
        $allowed_modes = [ 'single', 'per_row' ];
        $output_mode   = isset( $_POST['wpwe_output_mode'] ) && in_array( $_POST['wpwe_output_mode'], $allowed_modes, true )
            ? sanitize_text_field( wp_unslash( $_POST['wpwe_output_mode'] ) )
            : 'single';
        if ( ! \WPWE\Plan::is_feature_enabled( 'repeating_rows' ) ) {
            $output_mode = 'single';
        }
        update_post_meta( $post_id, 'output_mode', $output_mode );

        // output_group_key
        $group_key = isset( $_POST['wpwe_output_group_key'] ) ? sanitize_text_field( wp_unslash( $_POST['wpwe_output_group_key'] ) ) : '';
        update_post_meta( $post_id, 'output_group_key', $group_key );

        // notification_email
        $email = isset( $_POST['wpwe_notification_email'] ) ? sanitize_email( wp_unslash( $_POST['wpwe_notification_email'] ) ) : '';
        update_post_meta( $post_id, 'notification_email', $email );

        // active
        $active = isset( $_POST['wpwe_active'] ) ? true : false;
        update_post_meta( $post_id, 'active', $active );

        // amelia_service_ids
        $raw_svc_ids = isset( $_POST['wpwe_amelia_service_ids'] ) && is_array( $_POST['wpwe_amelia_service_ids'] )
            ? array_map( 'absint', $_POST['wpwe_amelia_service_ids'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
            : [];
        if ( ! \WPWE\Plan::is_feature_enabled( 'amelia_integration' ) ) {
            $raw_svc_ids = [];
        }
        update_post_meta( $post_id, 'amelia_service_ids', wp_json_encode( array_values( $raw_svc_ids ) ) );

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
     *   wpwe_map_group_keys[], wpwe_map_field_keys[], wpwe_map_labels[],
     *   wpwe_map_types[], wpwe_map_required[], wpwe_map_repeatable[],
     *   wpwe_map_pages[], wpwe_map_x[], wpwe_map_y[], wpwe_map_w[], wpwe_map_h[],
     *   wpwe_map_fonts[]
     *
     * wpwe_map_required[] and wpwe_map_repeatable[] only POST for checked rows,
     * so we use separate index tracking via a bitmask array.
     *
     * @return array{0: array, 1: array}  [ $mapping, $schema ]
     */
    private function build_from_unified_post(): array {
        $page_count = isset( $_POST['wpwe_map_page_count'] ) ? max( 1, absint( $_POST['wpwe_map_page_count'] ) ) : 1;

        $gk_arr     = $this->post_arr( 'wpwe_map_group_keys' );
        $fk_arr     = $this->post_arr( 'wpwe_map_field_keys' );
        $label_arr  = $this->post_arr( 'wpwe_map_labels' );
        $type_arr   = $this->post_arr( 'wpwe_map_types' );
        $pages_arr  = $this->post_arr( 'wpwe_map_pages' );
        $x_arr      = $this->post_arr( 'wpwe_map_x' );
        $y_arr      = $this->post_arr( 'wpwe_map_y' );
        $w_arr      = $this->post_arr( 'wpwe_map_w' );
        $h_arr      = $this->post_arr( 'wpwe_map_h' );
        $fonts_arr  = $this->post_arr( 'wpwe_map_fonts' );
        // Checkboxes: posted as associative array with row index as key
        $req_map    = isset( $_POST['wpwe_map_required'] )   && is_array( $_POST['wpwe_map_required'] )   ? array_map( 'absint', $_POST['wpwe_map_required'] )   : [];
        $rep_map    = [];
        if ( function_exists( 'wwe_fs' ) && wwe_fs() && wwe_fs()->can_use_premium_code__premium_only() ) {
            $rep_map = isset( $_POST['wpwe_map_repeatable'] ) && is_array( $_POST['wpwe_map_repeatable'] ) ? array_map( 'absint', $_POST['wpwe_map_repeatable'] ) : [];
        }

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

        return [ $mapping, $schema ];
    }

    /** Safe helper: returns a numerically-indexed array from a POST key, sanitized. */
    private function post_arr( string $key ): array {
        if ( ! isset( $_POST[ $key ] ) || ! is_array( $_POST[ $key ] ) ) {
            return [];
        }
        return array_values( $_POST[ $key ] );
    }
}
