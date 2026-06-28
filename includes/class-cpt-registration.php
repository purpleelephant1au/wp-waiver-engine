<?php
namespace Pewave\WaiverEngine;

defined( 'ABSPATH' ) || exit;

/**
 * Registers all custom post types and their meta fields.
 */
class PeWave_CPT_Registration {

    public function register(): void {
        add_action( 'init', [ $this, 'register_cpts' ] );
        add_action( 'init', [ $this, 'register_meta' ] );
    }

    // -----------------------------------------------------------------------
    // Custom Post Types
    // -----------------------------------------------------------------------

    public function register_cpts(): void {
        // pewave_template -------------------------------------------------
        register_post_type( 'pewave_template', [
            'label'               => 'Waiver Templates',
            'labels'              => [
                'name'               => 'Waiver Templates',
                'singular_name'      => 'Waiver Template',
                'add_new_item'       => 'Add New Waiver Template',
                'edit_item'          => 'Edit Waiver Template',
                'new_item'           => 'New Waiver Template',
                'view_item'          => 'View Waiver Template',
                'search_items'       => 'Search Waiver Templates',
                'not_found'          => 'No waiver templates found',
                'not_found_in_trash' => 'No waiver templates in trash',
            ],
            'public'              => false,
            'publicly_queryable'  => false,
            'show_ui'             => true,
            'show_in_menu'        => true,
            'menu_icon'           => 'dashicons-media-document',
            'menu_position'       => 30,
            'supports'            => [ 'title' ],
            'capability_type'     => 'post',
            'capabilities'        => [ 'create_posts' => 'manage_options' ],
            'map_meta_cap'        => true,
            'has_archive'         => false,
            'rewrite'             => false,
            'show_in_rest'        => false,
        ] );

        // pewave_entry ----------------------------------------------------
        register_post_type( 'pewave_entry', [
            'label'               => 'Waiver Entries',
            'labels'              => [
                'name'               => 'Waiver Entries',
                'singular_name'      => 'Waiver Entry',
                'edit_item'          => 'View Waiver Entry',
                'search_items'       => 'Search Waiver Entries',
                'not_found'          => 'No waiver entries found',
                'not_found_in_trash' => 'No waiver entries in trash',
            ],
            'public'              => false,
            'publicly_queryable'  => false,
            'show_ui'             => true,
            'show_in_menu'        => 'edit.php?post_type=pewave_template',
            'supports'            => [ 'title' ],
            'capability_type'     => 'post',
            'capabilities'        => [
                'create_posts' => 'do_not_allow',
                'edit_posts'   => 'manage_options',
            ],
            'map_meta_cap'        => true,
            'has_archive'         => false,
            'rewrite'             => false,
            'show_in_rest'        => false,
        ] );
    }

    // -----------------------------------------------------------------------
    // Meta fields
    // -----------------------------------------------------------------------

    public function register_meta(): void {
        // pewave_template meta
        $template_meta = [
            'pdf_attachment_id'  => [ 'type' => 'integer', 'single' => true, 'default' => 0 ],
            'field_schema'       => [ 'type' => 'string',  'single' => true, 'default' => '' ],
            'pdf_mapping'        => [ 'type' => 'string',  'single' => true, 'default' => '' ],
            'output_mode'        => [ 'type' => 'string',  'single' => true, 'default' => 'single' ],
            'output_group_key'   => [ 'type' => 'string',  'single' => true, 'default' => '' ],
            'description'        => [ 'type' => 'string',  'single' => true, 'default' => '' ],
            'active'             => [ 'type' => 'boolean', 'single' => true, 'default' => true ],
            'notification_email' => [ 'type' => 'string',  'single' => true, 'default' => '' ],
        ];

        foreach ( $template_meta as $key => $args ) {
            register_post_meta( 'pewave_template', $key, array_merge( $args, [
                'show_in_rest'      => false,
                'sanitize_callback' => $this->get_sanitizer( $args['type'] ),
            ] ) );
        }

        // pewave_entry meta
        $entry_meta = [
            'template_id'    => [ 'type' => 'integer', 'single' => true, 'default' => 0 ],
            'submission_data'=> [ 'type' => 'string',  'single' => true, 'default' => '' ], // JSON
            'pdf_ids'        => [ 'type' => 'string',  'single' => true, 'default' => '' ], // JSON array of attachment IDs
            'submitter_ip'   => [ 'type' => 'string',  'single' => true, 'default' => '' ],
            'email_sent'     => [ 'type' => 'boolean', 'single' => true, 'default' => false ],
        ];

        foreach ( $entry_meta as $key => $args ) {
            register_post_meta( 'pewave_entry', $key, array_merge( $args, [
                'show_in_rest'      => false,
                'sanitize_callback' => $this->get_sanitizer( $args['type'] ),
            ] ) );
        }
    }

    private function get_sanitizer( string $type ): callable {
        return match ( $type ) {
            'integer' => 'absint',
            'boolean' => 'rest_sanitize_boolean',
            default   => 'sanitize_text_field',
        };
    }
}
