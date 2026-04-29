<?php
/**
 * Demo PDF Generator for WP Waiver Engine
 *
 * Generates two sample waiver PDF templates that can be uploaded to
 * WordPress and used as the "Base PDF" in the template editor:
 *
 *   instructions/demo-standard-waiver.pdf
 *     Single-person, multi-page waiver. Fields on two pages:
 *       Page 1 – personal details (name, address, postcode, phone, email, DOB)
 *       Page 2 – declaration text + signature + date
 *
 *   instructions/demo-family-waiver.pdf
 *     Family / group waiver with repeatable participant rows. Fields:
 *       Page 1 – lead contact details (name, address, postcode, phone, email)
 *                + header for participant table
 *       Page 2 – participant rows (first name, last name, DOB)
 *                + guardian signature + date
 *
 * Usage (from the plugin root):
 *   php bin/generate-demo-pdfs.php
 *
 * Requires Composer dependencies to be installed (vendor/ must exist).
 */

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------
$plugin_root = dirname( __DIR__ );
$autoload    = $plugin_root . '/vendor/autoload.php';

if ( ! file_exists( $autoload ) ) {
    echo "ERROR: vendor/autoload.php not found.\n";
    echo "Run:  composer install  (from the plugin root) then try again.\n";
    exit( 1 );
}

require $autoload;

$out_dir = $plugin_root . '/instructions';
if ( ! is_dir( $out_dir ) ) {
    mkdir( $out_dir, 0755, true );
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Draw a labelled underline field.
 *
 * @param FPDF   $pdf
 * @param float  $x       Left edge of label (mm)
 * @param float  $y       Baseline Y (mm)
 * @param string $label   Small label text above the line
 * @param float  $width   Width of the underline (mm)
 */
function field( \FPDF $pdf, float $x, float $y, string $label, float $width ): void {
    // Label in small grey text
    $pdf->SetFont( 'Helvetica', '', 7 );
    $pdf->SetTextColor( 100, 100, 100 );
    $pdf->SetXY( $x, $y - 4.5 );
    $pdf->Cell( $width, 4, $label );

    // Underline
    $pdf->SetDrawColor( 80, 80, 80 );
    $pdf->SetLineWidth( 0.3 );
    $pdf->Line( $x, $y, $x + $width, $y );
}

/**
 * Draw a section heading.
 */
function section_heading( \FPDF $pdf, float $x, float $y, string $text, float $page_width ): void {
    $pdf->SetFillColor( 230, 230, 230 );
    $pdf->SetFont( 'Helvetica', 'B', 9 );
    $pdf->SetTextColor( 40, 40, 40 );
    $pdf->SetXY( $x, $y );
    $pdf->Cell( $page_width, 6, strtoupper( $text ), 0, 0, 'L', true );
}

/**
 * Draw body text (wrapped, returns new Y).
 */
function body_text( \FPDF $pdf, float $x, float $y, string $text, float $width ): float {
    $pdf->SetFont( 'Helvetica', '', 8.5 );
    $pdf->SetTextColor( 40, 40, 40 );
    $pdf->SetXY( $x, $y );
    $pdf->MultiCell( $width, 4.5, $text, 0, 'L' );
    return $pdf->GetY();
}

// Shared page dimensions (A4)
$margin = 20; // mm left/right
$pw     = 210 - ( $margin * 2 ); // printable width

// ---------------------------------------------------------------------------
// PDF 1 – Standard single-person multi-page waiver
// ---------------------------------------------------------------------------

$pdf1 = new \FPDF( 'P', 'mm', 'A4' );
$pdf1->SetAutoPageBreak( false );
$pdf1->SetMargins( $margin, $margin );

// ── Page 1: Personal Details ──────────────────────────────────────────────
$pdf1->AddPage();

// Logo placeholder + title
$pdf1->SetFont( 'Helvetica', 'B', 16 );
$pdf1->SetTextColor( 30, 30, 30 );
$pdf1->SetXY( $margin, 20 );
$pdf1->Cell( $pw, 8, 'ACTIVITY CENTRE WAIVER', 0, 1, 'C' );

$pdf1->SetFont( 'Helvetica', '', 9 );
$pdf1->SetTextColor( 100, 100, 100 );
$pdf1->SetX( $margin );
$pdf1->Cell( $pw, 5, 'Please complete all fields clearly. Keep a copy for your records.', 0, 1, 'C' );

$pdf1->Ln( 4 );

// Section: Personal Details
section_heading( $pdf1, $margin, $pdf1->GetY(), 'Section 1 – Personal Details', $pw );
$pdf1->Ln( 10 );

$y = $pdf1->GetY();

// Row 1: First name | Last name
field( $pdf1, $margin,            $y, 'First Name',  85 );
field( $pdf1, $margin + 90,       $y, 'Last Name',   $pw - 90 );
$y += 10;

// Row 2: Date of Birth | Gender
field( $pdf1, $margin,            $y, 'Date of Birth (DD/MM/YYYY)', 55 );
field( $pdf1, $margin + 60,       $y, 'Gender',      40 );
$y += 10;

// Row 3: Street Address (full width)
field( $pdf1, $margin,            $y, 'Street Address', $pw );
$y += 10;

// Row 4: Suburb | State | Postcode
field( $pdf1, $margin,            $y, 'Suburb',      75 );
field( $pdf1, $margin + 80,       $y, 'State',       30 );
field( $pdf1, $margin + 115,      $y, 'Postcode',    $pw - 115 );
$y += 10;

// Row 5: Phone | Email
field( $pdf1, $margin,            $y, 'Phone Number', 75 );
field( $pdf1, $margin + 80,       $y, 'Email Address', $pw - 80 );
$y += 12;

// Section: Emergency Contact
section_heading( $pdf1, $margin, $y, 'Section 2 – Emergency Contact', $pw );
$y += 10;

field( $pdf1, $margin,            $y, 'Contact Name', 80 );
field( $pdf1, $margin + 85,       $y, 'Relationship', $pw - 85 );
$y += 10;

field( $pdf1, $margin,            $y, 'Contact Phone', 75 );
field( $pdf1, $margin + 80,       $y, 'Contact Email', $pw - 80 );
$y += 12;

// Section: Medical
section_heading( $pdf1, $margin, $y, 'Section 3 – Medical Information', $pw );
$y += 10;

$pdf1->SetFont( 'Helvetica', '', 8.5 );
$pdf1->SetTextColor( 40, 40, 40 );
$pdf1->SetXY( $margin, $y );
$pdf1->Cell( $pw, 5, 'Do you have any medical conditions, injuries or allergies we should know about?', 0, 1 );
$y += 6;

field( $pdf1, $margin, $y,       'Please describe (or write N/A)', $pw );
$y += 10;
field( $pdf1, $margin, $y,       '', $pw );
$y += 12;

// Page number
$pdf1->SetFont( 'Helvetica', 'I', 7 );
$pdf1->SetTextColor( 150, 150, 150 );
$pdf1->SetXY( $margin, 287 );
$pdf1->Cell( $pw, 4, 'Page 1 of 2', 0, 0, 'C' );

// ── Page 2: Declaration & Signature ──────────────────────────────────────
$pdf1->AddPage();

$pdf1->SetFont( 'Helvetica', 'B', 13 );
$pdf1->SetTextColor( 30, 30, 30 );
$pdf1->SetXY( $margin, 20 );
$pdf1->Cell( $pw, 7, 'ACTIVITY CENTRE WAIVER', 0, 1, 'C' );

$pdf1->Ln( 2 );
section_heading( $pdf1, $margin, $pdf1->GetY(), 'Section 4 – Declaration & Consent', $pw );
$pdf1->Ln( 8 );

$declaration = "I, the undersigned, acknowledge and voluntarily accept the risks associated with "
    . "participating in activities at this centre. I confirm that I have read and understood "
    . "the safety rules and briefing provided.\n\n"
    . "I consent to receive first aid, medical treatment or emergency services as deemed "
    . "necessary by the operator in the event of injury or illness.\n\n"
    . "I agree to indemnify and hold harmless the operator, its employees, agents and "
    . "contractors from any claim, loss or liability arising from my participation, except "
    . "where caused by the gross negligence of the operator.\n\n"
    . "I certify that all information provided on this form is accurate and complete. "
    . "I understand that falsifying information may result in removal from the activity "
    . "without refund.";

$ny = body_text( $pdf1, $margin, $pdf1->GetY(), $declaration, $pw );
$ny += 6;

// Section: Signature
section_heading( $pdf1, $margin, $ny, 'Section 5 – Signature', $pw );
$ny += 10;

// Signature box (large, for signature pad overlay)
$pdf1->SetDrawColor( 100, 100, 100 );
$pdf1->SetLineWidth( 0.3 );
$pdf1->Rect( $margin, $ny, 100, 30 );
$pdf1->SetFont( 'Helvetica', 'I', 7 );
$pdf1->SetTextColor( 180, 180, 180 );
$pdf1->SetXY( $margin + 1, $ny + 12 );
$pdf1->Cell( 98, 5, 'Participant Signature', 0, 0, 'C' );
$ny += 2;

// Date field to the right of the signature box
field( $pdf1, $margin + 110, $ny + 14, 'Date (DD/MM/YYYY)', 60 );

$ny += 36;

// Printed name field
field( $pdf1, $margin, $ny, 'Print Full Name', 100 );

$ny += 12;

// Under-18 section
section_heading( $pdf1, $margin, $ny, 'Section 6 – Guardian Consent (if participant is under 18)', $pw );
$ny += 10;

$pdf1->SetFont( 'Helvetica', '', 8.5 );
$pdf1->SetTextColor( 40, 40, 40 );
$pdf1->SetXY( $margin, $ny );
$pdf1->Cell( $pw, 5, 'I am the parent or legal guardian of the participant named above and consent on their behalf.', 0, 1 );
$ny += 7;

$pdf1->Rect( $margin, $ny, 100, 25 );
$pdf1->SetFont( 'Helvetica', 'I', 7 );
$pdf1->SetTextColor( 180, 180, 180 );
$pdf1->SetXY( $margin + 1, $ny + 9 );
$pdf1->Cell( 98, 5, 'Guardian Signature', 0, 0, 'C' );

field( $pdf1, $margin + 110, $ny + 10, 'Date (DD/MM/YYYY)', 60 );
$ny += 30;

field( $pdf1, $margin, $ny, 'Guardian Full Name', 80 );
field( $pdf1, $margin + 85, $ny, 'Relationship to Participant', $pw - 85 );

// Page number
$pdf1->SetFont( 'Helvetica', 'I', 7 );
$pdf1->SetTextColor( 150, 150, 150 );
$pdf1->SetXY( $margin, 287 );
$pdf1->Cell( $pw, 4, 'Page 2 of 2', 0, 0, 'C' );

$pdf1->Output( 'F', $out_dir . '/demo-standard-waiver.pdf' );
echo "Created: instructions/demo-standard-waiver.pdf\n";

// ---------------------------------------------------------------------------
// PDF 2 – Family / Group waiver with repeatable participant rows
// ---------------------------------------------------------------------------

$pdf2 = new \FPDF( 'P', 'mm', 'A4' );
$pdf2->SetAutoPageBreak( false );
$pdf2->SetMargins( $margin, $margin );

// ── Page 1: Lead Contact ──────────────────────────────────────────────────
$pdf2->AddPage();

$pdf2->SetFont( 'Helvetica', 'B', 16 );
$pdf2->SetTextColor( 30, 30, 30 );
$pdf2->SetXY( $margin, 20 );
$pdf2->Cell( $pw, 8, 'GROUP ACTIVITY WAIVER', 0, 1, 'C' );

$pdf2->SetFont( 'Helvetica', '', 9 );
$pdf2->SetTextColor( 100, 100, 100 );
$pdf2->SetX( $margin );
$pdf2->Cell( $pw, 5, 'Complete once per group. List each participant in the table on page 2.', 0, 1, 'C' );
$pdf2->Ln( 4 );

section_heading( $pdf2, $margin, $pdf2->GetY(), 'Section 1 – Lead Contact Details', $pw );
$pdf2->Ln( 10 );
$y = $pdf2->GetY();

field( $pdf2, $margin,        $y, 'First Name',   80 );
field( $pdf2, $margin + 85,   $y, 'Last Name',    $pw - 85 );
$y += 10;

field( $pdf2, $margin,        $y, 'Street Address', $pw );
$y += 10;

field( $pdf2, $margin,        $y, 'Suburb',       70 );
field( $pdf2, $margin + 75,   $y, 'State',        28 );
field( $pdf2, $margin + 108,  $y, 'Postcode',     $pw - 108 );
$y += 10;

field( $pdf2, $margin,        $y, 'Phone Number', 75 );
field( $pdf2, $margin + 80,   $y, 'Email Address', $pw - 80 );
$y += 12;

section_heading( $pdf2, $margin, $y, 'Section 2 – Emergency Contact', $pw );
$y += 10;

field( $pdf2, $margin,        $y, 'Emergency Contact Name', 80 );
field( $pdf2, $margin + 85,   $y, 'Relationship',           $pw - 85 );
$y += 10;

field( $pdf2, $margin,        $y, 'Emergency Phone', 75 );
$y += 12;

section_heading( $pdf2, $margin, $y, 'Section 3 – Booking Details', $pw );
$y += 10;

field( $pdf2, $margin,        $y, 'Activity / Experience Name', 100 );
field( $pdf2, $margin + 105,  $y, 'Session Date (DD/MM/YYYY)', $pw - 105 );
$y += 10;

field( $pdf2, $margin,        $y, 'Booking Reference / Order Number', 80 );
field( $pdf2, $margin + 85,   $y, 'Number of Participants', 40 );
$y += 12;

// Note about page 2
$pdf2->SetFont( 'Helvetica', 'I', 8 );
$pdf2->SetTextColor( 80, 80, 80 );
$pdf2->SetXY( $margin, $y );
$pdf2->Cell( $pw, 5, 'Please list all participants on page 2, including the lead contact if they are participating.', 0, 0 );

// Page number
$pdf2->SetFont( 'Helvetica', 'I', 7 );
$pdf2->SetTextColor( 150, 150, 150 );
$pdf2->SetXY( $margin, 287 );
$pdf2->Cell( $pw, 4, 'Page 1 of 2', 0, 0, 'C' );

// ── Page 2: Participant Rows + Guardian Signature ─────────────────────────
$pdf2->AddPage();

$pdf2->SetFont( 'Helvetica', 'B', 13 );
$pdf2->SetTextColor( 30, 30, 30 );
$pdf2->SetXY( $margin, 20 );
$pdf2->Cell( $pw, 7, 'GROUP ACTIVITY WAIVER', 0, 1, 'C' );
$pdf2->Ln( 2 );

section_heading( $pdf2, $margin, $pdf2->GetY(), 'Section 4 – Participant List', $pw );
$pdf2->Ln( 4 );

// Table header
$pdf2->SetFont( 'Helvetica', 'B', 7.5 );
$pdf2->SetTextColor( 40, 40, 40 );
$pdf2->SetFillColor( 210, 210, 210 );
$pdf2->SetDrawColor( 120, 120, 120 );
$pdf2->SetLineWidth( 0.25 );

$col_w = [ 6, 45, 45, 30, 44 ]; // #, First Name, Last Name, DOB, Email
$col_h = 6;
$y = $pdf2->GetY();

$pdf2->SetXY( $margin, $y );
$pdf2->Cell( $col_w[0], $col_h, '#',           1, 0, 'C', true );
$pdf2->Cell( $col_w[1], $col_h, 'First Name',  1, 0, 'L', true );
$pdf2->Cell( $col_w[2], $col_h, 'Last Name',   1, 0, 'L', true );
$pdf2->Cell( $col_w[3], $col_h, 'DOB',         1, 0, 'C', true );
$pdf2->Cell( $col_w[4], $col_h, 'Email',       1, 1, 'L', true );

$pdf2->SetFont( 'Helvetica', '', 7.5 );
$pdf2->SetFillColor( 250, 250, 250 );
$row_h = 8;

// 12 blank participant rows
for ( $i = 1; $i <= 12; $i++ ) {
    $fill = ( $i % 2 === 0 );
    $pdf2->SetFillColor( $fill ? 245 : 255, $fill ? 245 : 255, $fill ? 245 : 255 );
    $y = $pdf2->GetY();
    $pdf2->Cell( $col_w[0], $row_h, (string) $i,  1, 0, 'C', true );
    $pdf2->Cell( $col_w[1], $row_h, '',            1, 0, 'L', true );
    $pdf2->Cell( $col_w[2], $row_h, '',            1, 0, 'L', true );
    $pdf2->Cell( $col_w[3], $row_h, '',            1, 0, 'C', true );
    $pdf2->Cell( $col_w[4], $row_h, '',            1, 1, 'L', true );
}

$y = $pdf2->GetY() + 8;

// Section 5: Declaration
section_heading( $pdf2, $margin, $y, 'Section 5 – Declaration', $pw );
$y += 8;

$group_decl = "On behalf of myself and all participants listed above, I confirm that every person "
    . "listed has been informed of the safety rules and agrees to comply with all instructions "
    . "given by staff. I accept the inherent risks of the activity and agree to the terms of "
    . "participation on behalf of any participant under 18 years of age for whom I am the "
    . "parent or legal guardian.";

$pdf2->SetFont( 'Helvetica', '', 8.5 );
$pdf2->SetTextColor( 40, 40, 40 );
$pdf2->SetXY( $margin, $y );
$pdf2->MultiCell( $pw, 4.5, $group_decl, 0, 'L' );
$y = $pdf2->GetY() + 6;

// Signature + Date on same line
$pdf2->SetDrawColor( 100, 100, 100 );
$pdf2->SetLineWidth( 0.3 );
$pdf2->Rect( $margin, $y, 100, 26 );
$pdf2->SetFont( 'Helvetica', 'I', 7 );
$pdf2->SetTextColor( 180, 180, 180 );
$pdf2->SetXY( $margin + 1, $y + 10 );
$pdf2->Cell( 98, 5, 'Lead Contact / Guardian Signature', 0, 0, 'C' );

field( $pdf2, $margin + 110, $y + 12, 'Date (DD/MM/YYYY)', 60 );
$y += 31;

field( $pdf2, $margin, $y, 'Print Full Name', 100 );

// Page number
$pdf2->SetFont( 'Helvetica', 'I', 7 );
$pdf2->SetTextColor( 150, 150, 150 );
$pdf2->SetXY( $margin, 287 );
$pdf2->Cell( $pw, 4, 'Page 2 of 2', 0, 0, 'C' );

$pdf2->Output( 'F', $out_dir . '/demo-family-waiver.pdf' );
echo "Created: instructions/demo-family-waiver.pdf\n";

echo "\nDone. Upload both PDFs to WordPress Media Library, then create waiver templates\n";
echo "using these files as the Base PDF.\n";
