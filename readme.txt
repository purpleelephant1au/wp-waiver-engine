=== WP Waiver Engine ===
Contributors: purpleelephant1au
Tags: waiver, contract, pdf, form, booking
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Template-driven waiver and contract system with PDF overlay generation.

== Description ==

WP Waiver Engine lets you build waiver and contract forms in the WordPress
admin, map form fields to coordinate positions on an uploaded PDF template,
and automatically generate filled PDFs when visitors submit the form.

**Key features:**

* Build multi-section, repeatable waiver/contract forms
* Map form fields to exact coordinate positions on a PDF
* Auto-generate filled PDFs on each submission
* Admin and submitter notification emails with PDF attachments
* Email notifications configurable globally (Settings) and per template
* Admin entry list with pagination, filtering, sorting, and PDF download
* Import / export template field-to-PDF mappings for easy site migration
* Optional Amelia Booking integration – automatically enabled when Amelia
  is detected, allowing forms to link to Amelia appointments

**Integration architecture:**

The plugin follows an integration-manager pattern (the same approach used
by WooCommerce add-ons, WPForms, RankMath, and ACF). Each third-party
integration lives in `includes/integrations/` and is loaded only when its
host plugin is active. The core plugin has zero knowledge of Amelia tables
when Amelia is not installed.

== Installation ==

1. Upload the `wp-waiver-engine` folder to `/wp-content/plugins/`.
2. Activate through the **Plugins** screen in WordPress.
3. Navigate to **Waivers > Templates** to create your first waiver template.
4. Upload a PDF template file, map form fields to PDF coordinates, and
   copy the `[waiver_form id="N"]` shortcode into any page or post.

== Frequently Asked Questions ==

= Does this plugin require Amelia Booking? =

No. Amelia integration is entirely optional. If the Amelia Booking plugin
is active, an "Amelia Integration" toggle appears under **Waivers > Settings**.
Without Amelia, the plugin works identically — booking fields, columns, and
search inputs are simply not shown.

= Where are submitted PDFs stored? =

Generated PDFs are stored inside the `wp-content/uploads/wpwe-pdfs/`
directory on the server. They are served for download through an
authenticated admin URL, not directly from disk.

= Can I migrate template mappings between sites? =

Yes. On the template edit screen, scroll past the Save button to find the
Export / Import section. Export downloads a JSON file containing your field
schema and PDF coordinate mappings. Import merges those into an existing
template on another site while preserving its title, notification email,
and other settings.

== Changelog ==

= 1.0.0 =
* Initial public release.
* Custom DB tables (no CPT dependency).
* PDF overlay generation via FPDI/FPDF.
* Integration manager architecture for optional third-party integrations.
* Amelia Booking integration (auto-detected, opt-in via Settings).
* Admin and submitter email notifications, configurable per template.
* Import / export of template field-to-PDF mappings.

== Upgrade Notice ==

= 1.0.0 =
First release. No upgrade steps required for fresh installs.

== License ==

WP Waiver Engine is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by the
Free Software Foundation, either version 2 of the License, or any later
version.

WP Waiver Engine is distributed in the hope that it will be useful, but
WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY
or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License
for more details.
