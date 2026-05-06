<?php
namespace WPWE;

defined( 'ABSPATH' ) || exit;

use setasign\Fpdi\Fpdi;

/**
 * Minimal FPDI subclass that exposes the PDF character-spacing operator (Tc).
 * This allows individual fields to have configurable letter spacing.
 */
class Wpwe_Fpdi extends Fpdi {
    // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
    public function SetCharSpacing( float $cs ): void {
        $this->_out( sprintf( '%.4F Tc', $cs ) );
    }
}

/**
 * Generates PDF files by overlaying submission data onto the base PDF
 * using FPDI + FPDF.
 *
 * Accepts a template stdClass row from Database::get_template().
 * Generated PDFs are stored in the uploads/wpwe-pdfs/ directory and
 * their filesystem paths returned (no WP attachment posts created).
 *
 * Supported mapping field configs:
 *   - type: "text"  (default) – renders a text string
 *   - type: "image" – renders a base64 PNG (used for signatures)
 *
 * Coordinate system: x/y are from the TOP-LEFT corner in points (pt).
 * 72 pt = 1 inch.
 */
class PDF_Generator {

    private int    $template_id;
    private array  $mapping;
    private string $output_mode;
    private string $output_group_key;
    private ?int   $pdf_attachment_id;
    private string $pdf_file_path;

    public function __construct( object $template ) {
        $this->template_id       = (int) $template->id;
        $this->output_mode       = $template->output_mode       ?: 'single';
        $this->output_group_key  = $template->output_group_key  ?: '';
        $this->pdf_attachment_id = $template->pdf_attachment_id ? (int) $template->pdf_attachment_id : null;

        $this->mapping      = json_decode( $template->pdf_mapping, true ) ?: [];
        $this->pdf_file_path = $this->resolve_pdf_path();
    }

    // -----------------------------------------------------------------------
    // Public API
    // -----------------------------------------------------------------------

    /**
     * Generate PDF(s) for the given submission data.
     *
     * @param  array       $data      Sanitised submission data [group => [row => [field => value]]].
     * @param  int         $entry_id  The entry ID (used for naming the output file).
     * @param  object|null $booking   Optional Amelia booking object for named files.
     * @return string[]               Array of absolute filesystem paths for generated PDFs.
     */
    public function generate( array $data, int $entry_id, ?object $booking = null ): array {
        if ( ! file_exists( $this->pdf_file_path ) ) {
            throw new \RuntimeException( 'Base PDF not found: ' . $this->pdf_file_path );
        }

        if ( function_exists( 'wwe_fs' ) && wwe_fs() && wwe_fs()->can_use_premium_code__premium_only() ) {
            if ( $this->output_mode === 'per_row' && $this->output_group_key ) {
                return $this->generate_per_row( $data, $entry_id, $booking );
            }
        }

        return [ $this->generate_single( $data, $entry_id, 0, $booking ) ];
    }

    /**
     * Generate a preview PDF and return it as a binary string (not saved to disk).
     * For per_row output mode, all rows are combined as consecutive pages.
     *
     * @param  array  $data  Sanitised submission data.
     * @return string Binary PDF string.
     * @throws \RuntimeException If the base PDF cannot be found.
     */
    public function preview( array $data ): string {
        if ( ! file_exists( $this->pdf_file_path ) ) {
            throw new \RuntimeException( 'Base PDF not found: ' . $this->pdf_file_path );
        }

        if ( function_exists( 'wwe_fs' ) && wwe_fs() && wwe_fs()->can_use_premium_code__premium_only() ) {
            if ( $this->output_mode === 'per_row' && $this->output_group_key ) {
                return $this->build_combined_pdf( $data )->Output( 'S' );
            }
        }

        return $this->build_pdf( $data )->Output( 'S' );
    }

    // -----------------------------------------------------------------------
    // Generation modes
    // -----------------------------------------------------------------------

    /**
     * Generate a single PDF, save to disk, and return its path.
     */
    private function generate_single( array $data, int $entry_id, int $index, ?object $booking = null ): string {
        $pdf      = $this->build_pdf( $data );
        $filename = $this->make_filename( $entry_id, $index, $booking );
        return $this->save_pdf( $pdf, $filename );
    }

    private function generate_per_row( array $data, int $entry_id, ?object $booking = null ): array {
        $group_key = $this->output_group_key;
        $rows      = $data[ $group_key ] ?? [];
        $paths     = [];
        $ts        = time();
        $booking_slug = $booking ? $this->booking_slug( $booking ) : '';
        $prefix   = $booking_slug ?: ( 'waiver-' . $entry_id );

        // One PDF per row.
        foreach ( $rows as $i => $row ) {
            $row_data               = $data;
            $row_data[ $group_key ] = [ $row ];
            $filename = sanitize_file_name( $prefix . '-row-' . ( $i + 1 ) . '-' . $ts . '.pdf' );
            $paths[]  = $this->save_pdf( $this->build_pdf( $row_data ), $filename );
        }

        // Combined PDF – all rows together in one file.
        $combined_filename = sanitize_file_name( $prefix . '-combined-' . $ts . '.pdf' );
        $paths[] = $this->save_pdf( $this->build_combined_pdf( $data ), $combined_filename );

        return $paths;
    }

    // -----------------------------------------------------------------------
    // Core FPDI/FPDF logic
    // -----------------------------------------------------------------------

    private function build_pdf( array $data ): Fpdi {
        // Use 'pt' unit so that stored x/y/width/height (in PDF points from the
        // admin mapper) are passed directly to SetXY/Image without conversion.
        $pdf = new Wpwe_Fpdi( 'P', 'pt' );
        $pdf->SetAutoPageBreak( false );
        $pdf->SetMargins( 0, 0, 0 );

        // Use the real page count from the PDF file itself; fall back to
        // the stored mapping value only if setSourceFile returns 0.
        $actual_pages = $pdf->setSourceFile( $this->pdf_file_path );
        $page_count   = $actual_pages ?: (int) ( $this->mapping['page_count'] ?? 1 );

        for ( $page = 1; $page <= $page_count; $page++ ) {
            $tpl_id = $pdf->importPage( $page );
            $size   = $pdf->getTemplateSize( $tpl_id );
            $pdf->AddPage( $size['orientation'], [ $size['width'], $size['height'] ] );
            $pdf->useTemplate( $tpl_id, 0, 0, $size['width'], $size['height'] );

            // Place fields mapped to this page
            foreach ( $this->mapping['fields'] ?? [] as $path => $config ) {
                if ( (int) ( $config['page'] ?? 1 ) !== $page ) {
                    continue;
                }
                // Strip __N duplicate suffix (multi-field mapping) before resolving
                $lookup_path = preg_replace( '/__\d+$/', '', $path );
                $value = $this->resolve_path( $data, $lookup_path );
                if ( $value === '' ) {
                    continue;
                }
                $field_type = $config['type'] ?? 'text';
                // 'image' = image overlay (old+new mapping), 'signature' = signature (new mapping)
                if ( $field_type === 'image' || $field_type === 'signature' ) {
                    $this->place_image( $pdf, $value, $config );
                } else {
                    $this->place_text( $pdf, $value, $config );
                }
            }
        }

        return $pdf;
    }

    /**
     * Build a single combined PDF where each repeating-group row occupies its
     * own copy of the base PDF pages. Used for per_row preview.
     */
    private function build_combined_pdf( array $data ): Fpdi {
        $group_key = $this->output_group_key;
        $rows      = $data[ $group_key ] ?? [];

        if ( empty( $rows ) ) {
            // Nothing to combine – fall back to normal single render.
            return $this->build_pdf( $data );
        }

        $pdf = new Wpwe_Fpdi( 'P', 'pt' );
        $pdf->SetAutoPageBreak( false );
        $pdf->SetMargins( 0, 0, 0 );

        // Open the source file once — all rows use the same base PDF.
        $page_count = $pdf->setSourceFile( $this->pdf_file_path ) ?: (int) ( $this->mapping['page_count'] ?? 1 );

        foreach ( $rows as $row ) {
            $row_data               = $data;
            $row_data[ $group_key ] = [ $row ];

            for ( $page = 1; $page <= $page_count; $page++ ) {
                $tpl_id = $pdf->importPage( $page );
                $size   = $pdf->getTemplateSize( $tpl_id );
                $pdf->AddPage( $size['orientation'], [ $size['width'], $size['height'] ] );
                $pdf->useTemplate( $tpl_id, 0, 0, $size['width'], $size['height'] );

                foreach ( $this->mapping['fields'] ?? [] as $path => $config ) {
                    if ( (int) ( $config['page'] ?? 1 ) !== $page ) {
                        continue;
                    }
                    $lookup_path = preg_replace( '/__\d+$/', '', $path );
                    $value = $this->resolve_path( $row_data, $lookup_path );
                    if ( $value === '' ) {
                        continue;
                    }
                    $field_type = $config['type'] ?? 'text';
                    if ( $field_type === 'image' || $field_type === 'signature' ) {
                        $this->place_image( $pdf, $value, $config );
                    } else {
                        $this->place_text( $pdf, $value, $config );
                    }
                }
            }
        }

        return $pdf;
    }

    // -----------------------------------------------------------------------
    // Field placement
    // -----------------------------------------------------------------------

    private function place_text( Fpdi $pdf, string $text, array $cfg ): void {
        // Auto-format date values stored as YYYY-MM-DD using the per-field date_format.
        if ( ( $cfg['type'] ?? '' ) === 'date' && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $text ) ) {
            $fmt = ! empty( $cfg['date_format'] ) ? $cfg['date_format'] : 'd/m/Y';
            $dt  = \DateTime::createFromFormat( 'Y-m-d', $text );
            $text = $dt ? $dt->format( $fmt ) : $text;
        }

        $x_pos        = (float) ( $cfg['x']            ?? 10 );
        $y_pos        = (float) ( $cfg['y']            ?? 10 );
        $font_size    = (float) ( $cfg['font_size']    ?? 11 );
        $font         = $cfg['font']         ?? 'Helvetica';
        $color        = $cfg['color']        ?? [ 0, 0, 0 ];
        $char_spacing = (float) ( $cfg['char_spacing'] ?? 0 );

        $pdf->SetFont( $font, '', $font_size );
        $pdf->SetTextColor( ...$color );
        $pdf->SetXY( $x_pos, $y_pos );
        if ( $char_spacing > 0 && $pdf instanceof Wpwe_Fpdi ) {
            $pdf->SetCharSpacing( $char_spacing );
        }
        // Line height must be >= font size (both in pt since we use the 'pt' unit).
        $pdf->Write( $font_size * 1.2, $text );
        if ( $char_spacing > 0 && $pdf instanceof Wpwe_Fpdi ) {
            $pdf->SetCharSpacing( 0.0 ); // reset so adjacent fields are unaffected
        }
    }

    private function place_image( Fpdi $pdf, string $data_uri, array $cfg ): void {
        $x      = (float) ( $cfg['x']      ?? 10 );
        $y      = (float) ( $cfg['y']      ?? 10 );
        $width  = (float) ( $cfg['width']  ?? 60 );
        $height = (float) ( $cfg['height'] ?? 20 );

        // Decode data URI to a temp file
        if ( ! preg_match( '/^data:image\/(png|jpeg|jpg);base64,(.+)$/s', $data_uri, $matches ) ) {
            return;
        }
        $ext      = $matches[1] === 'png' ? 'png' : 'jpg';
        $raw      = base64_decode( $matches[2], true );
        if ( ! $raw ) {
            return;
        }
        $tmp_path = $this->temp_dir() . '/wpwe_sig_' . wp_generate_password( 12, false ) . '.' . $ext;
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        file_put_contents( $tmp_path, $raw );

        try {
            $pdf->Image( $tmp_path, $x, $y, $width, $height, strtoupper( $ext ) );
        } finally {
            @unlink( $tmp_path );
        }
    }

    // -----------------------------------------------------------------------
    // Save generated PDF as WP attachment
    // -----------------------------------------------------------------------

    /**
     * Write the PDF to the wpwe-pdfs upload directory and return the path.
     */
    private function save_pdf( Fpdi $pdf, string $filename ): string {
        $upload_dir = wp_upload_dir();
        $wpwe_dir   = $upload_dir['basedir'] . '/wpwe-pdfs';

        if ( ! file_exists( $wpwe_dir ) ) {
            wp_mkdir_p( $wpwe_dir );
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            file_put_contents( $wpwe_dir . '/.htaccess', "Options -Indexes\nDeny from all\n" );
        }

        $filepath = $wpwe_dir . '/' . $filename;
        $pdf->Output( 'F', $filepath );

        if ( ! file_exists( $filepath ) ) {
            throw new \RuntimeException( 'PDF was not written to disk: ' . $filepath );
        }

        return $filepath;
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Resolve dot-notation path like "signer.full_name" or "participants[0].name"
     * against the nested data array.
     */
    private function resolve_path( array $data, string $path ): string {
        // Handle paths like "signer.full_name" -> data[signer][0][full_name]
        // Or            like "participants[0].name" -> data[participants][0][name]
        $segments = preg_split( '/[\.\[\]]+/', $path, -1, PREG_SPLIT_NO_EMPTY );
        $node     = $data;

        foreach ( $segments as $seg ) {
            if ( is_array( $node ) ) {
                if ( isset( $node[ $seg ] ) ) {
                    $node = $node[ $seg ];
                } elseif ( is_numeric( $seg ) && isset( $node[ (int) $seg ] ) ) {
                    $node = $node[ (int) $seg ];
                } else {
                    // Try index 0 implicitly for non-repeatable groups
                    if ( isset( $node[0] ) && is_array( $node[0] ) && isset( $node[0][ $seg ] ) ) {
                        $node = $node[0][ $seg ];
                    } else {
                        return '';
                    }
                }
            } else {
                return '';
            }
        }
        return is_scalar( $node ) ? (string) $node : '';
    }

    /**
     * Determine the full filesystem path to the base PDF.
     * Priority: media library attachment > forms/ directory (pdf_file key in mapping).
     */
    private function resolve_pdf_path(): string {
        if ( $this->pdf_attachment_id ) {
            $path = get_attached_file( $this->pdf_attachment_id );
            if ( $path && file_exists( $path ) ) {
                return $path;
            }
        }
        // Fallback: pdf_file key in mapping (relative to the legacy wpwe-forms upload dir).
        $pdf_file = $this->mapping['pdf_file'] ?? '';
        if ( $pdf_file ) {
            // Sanitise to prevent path traversal.
            $pdf_file   = basename( $pdf_file );
            $upload_dir = wp_upload_dir();
            $path       = $upload_dir['basedir'] . '/wpwe-forms/' . $pdf_file;
            if ( file_exists( $path ) ) {
                return $path;
            }
        }
        return '';
    }

    private function make_filename( int $entry_id, int $index, ?object $booking = null ): string {
        $suffix = $index > 0 ? '-' . $index : '';
        if ( $booking ) {
            return sanitize_file_name( $this->booking_slug( $booking ) . $suffix . '-' . time() . '.pdf' );
        }
        return sanitize_file_name( 'waiver-' . $entry_id . $suffix . '-' . time() . '.pdf' );
    }

    /**
     * Build a filesystem-safe slug from Amelia booking data.
     * Format: <appointmentId>-<service>-<firstName>-<lastName>-<date>
     */
    private function booking_slug( object $booking ): string {
        $slugify = fn( string $s ) => strtolower( trim( preg_replace( '/[^a-z0-9]+/i', '-', $s ), '-' ) );
        $date    = date( 'Y-m-d', strtotime( $booking->bookingStart ) );
        return implode( '-', array_filter( [
            (string) $booking->appointment_id,
            $slugify( $booking->service_name ),
            $slugify( $booking->firstName ),
            $slugify( $booking->lastName ),
            $date,
        ] ) );
    }

    private function temp_dir(): string {
        return sys_get_temp_dir();
    }
}
