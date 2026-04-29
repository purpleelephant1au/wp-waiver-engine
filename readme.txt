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

== Development & Building a Release ==

The source repository does not include the `vendor/` directory (Composer
dependencies). After cloning you must install them before the plugin will
run locally.

**Requirements:**

* Git
* PHP 8.0+
* [Composer](https://getcomposer.org/download/)
* PowerShell 5.1+ (Windows) or PowerShell 7+ (cross-platform)

**Install dependencies after cloning:**

    git clone https://github.com/purpleelephant1au/wp-waiver-engine.git
    cd wp-waiver-engine
    composer install

**Build a production-ready release ZIP:**

    .\bin\build-release.ps1

The script will:

1. Verify Composer is available on PATH.
2. Run `composer install --no-dev --optimize-autoloader` to ensure
   `vendor/` is present and contains only production dependencies.
3. Read the version number automatically from the plugin header.
4. Collect all plugin files (excluding `.git`, `bin/`, `composer.*`,
   and any previously generated ZIPs).
5. Bundle them under a top-level `wp-waiver-engine/` folder and output
   `wp-waiver-engine-<version>.zip` at the repository root.

The resulting ZIP is ready to upload via **WordPress Admin → Plugins →
Add New → Upload Plugin**, or to submit to the WordPress.org plugin
directory.

Generated ZIP files are excluded from version control via `.gitignore`.

== Security ==

WP Waiver Engine implements multiple layers of protection against automated
abuse and bot submissions:

**1. WordPress Nonce (CSRF protection)**
Every form submission is validated against a standard WordPress nonce
(`wp_verify_nonce`). Requests without a valid nonce are rejected with HTTP 403.

**2. Honeypot Field**
A hidden text input is rendered off-screen (CSS `position:absolute; left:-9999px`)
and labelled with `aria-hidden="true"`. The field must exist and be completely
empty. Bots that auto-fill all inputs will be silently rejected.

**3. Timing Check**
On page load, the form records a HMAC-SHA256-signed timestamp (using WordPress's
`wp_salt('nonce')` as the key). On submission the signature is verified and the
elapsed time is checked: submissions faster than 3 seconds or older than 2 hours
are rejected with HTTP 429. This prevents replay attacks and trivially fast bot
submissions.

**4. IP Rate Limiting**
A sliding-window rate limiter (implemented using WordPress transients) limits how
many waivers a single IP address can submit within a configurable time window.
Defaults to 5 submissions per 15 minutes. Configurable in **Waivers > Settings**.
Can be disabled independently if another rate-limiting layer exists.

**5. CAPTCHA (optional)**
An invisible CAPTCHA challenge can be added to waiver forms for additional
protection. Supported providers:

* **Google reCAPTCHA v3** – invisible scoring model. A score of ≥ 0.5 is
  required. Register your keys at https://www.google.com/recaptcha/admin.
* **hCaptcha (invisible)** – privacy-respecting alternative. Register at
  https://www.hcaptcha.com/signup-interstitial.

Configuration steps:

1. Go to **Waivers > Settings** and choose a CAPTCHA provider.
2. Enter the **Site Key** (public) and **Secret Key** (private) from your
   provider's dashboard.
3. Open any template in **Waivers > Templates > Edit** and check **Require
   CAPTCHA verification on this form**.

The CAPTCHA toggle defaults to OFF globally. Individual templates also default
to OFF even when a provider is configured, so you can roll out selectively.

Token verification happens entirely server-side via `wp_remote_post()` to the
provider's verify endpoint. The secret key is never exposed in browser output.

**6. PDF File Cleanup**
The **PDF File Cleanup** tool in Settings lets you permanently delete generated
PDFs older than a chosen number of days. PDFs older than the threshold are
removed from disk; database entry records are preserved. This reduces disk
footprint and limits the blast radius of any hypothetical file-disclosure issue.

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

= Can booking confirmation emails link directly to the pre-filled waiver form? =

Yes. Append a `booking_id` query parameter to the waiver page URL and the
customer's Amelia appointment will be automatically pre-selected when the
page loads — no searching required. Example link for inclusion in a booking
confirmation email:

    https://yoursite.com/waiver-page/?booking_id=42

This requires the Amelia integration to be enabled in **Waivers > Settings**
and the template to have at least one linked Amelia service.

== Changelog ==

= 1.0.0 =
* Initial public release.
* Custom DB tables (no CPT dependency).
* PDF overlay generation via FPDI/FPDF.
* Integration manager architecture for optional third-party integrations.
* Amelia Booking integration (auto-detected, opt-in via Settings).
* Admin and submitter email notifications, configurable per template.
* Import / export of template field-to-PDF mappings.
* Security: WordPress nonce validation, honeypot field, HMAC-signed timing
  check, IP rate limiting (configurable via Settings), optional CAPTCHA
  (reCAPTCHA v3 or hCaptcha, configurable globally and per-template),
  and manual PDF cleanup tool.
* Amelia integration: support `?booking_id=N` URL parameter to pre-select
  a booking on the waiver form (for use in booking confirmation email links).

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
