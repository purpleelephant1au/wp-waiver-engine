=== Waiver Engine ===
Contributors: purpleelephant1au
Donate link: https://github.com/sponsors/purpleelephant1au
Tags: pdf, forms, contracts, signature, booking
Requires at least: 6.2
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 1.1.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Template-driven waiver and contract system with PDF overlay generation.

== Description ==

Waiver Engine lets you build waiver and contract forms in the WordPress
admin, map form fields to coordinate positions on an uploaded PDF template,
and automatically generate filled PDFs when visitors submit the form.

**Key features:**

* Build multi-section waiver/contract forms
* Map form fields to exact coordinate positions on a PDF
* Auto-generate filled PDFs on each submission
* Admin entry list with pagination, filtering, sorting, and PDF download
* Import / export template field-to-PDF mappings for easy site migration
* Security protections (nonce, honeypot, timing checks, rate limiting, optional CAPTCHA)

**WordPress.org (free) package:**

The version distributed on WordPress.org is fully functional and includes:

* Unlimited waiver templates
* PDF field mapping and PDF generation
* Waiver submission capture and admin entry management
* Import/export of template field mappings
* Security settings (rate limiting, optional CAPTCHA, PDF cleanup)

**Pro add-on (separate download):**

A premium package is available separately (not from WordPress.org) with
additional features:

* Repeating rows in form sections (per-row PDF output mode)
* Admin notification emails with PDF attachments
* Submitter email copies with PDF attachments
* Amelia Booking integration and booking-linked waiver flows

**Integration architecture:**

The plugin follows an integration-manager pattern (the same approach used
by WooCommerce add-ons, WPForms, RankMath, and ACF). Each third-party
integration lives in `includes/integrations/` and is loaded only when its
host plugin is active. The core plugin has zero knowledge of Amelia tables
when Amelia is not installed.

== Installation ==

1. Upload the `waiver-engine` folder to `/wp-content/plugins/`.
2. Activate through the **Plugins** screen in WordPress.
3. Navigate to **Waivers > Templates** to create your first waiver template.
4. Upload a PDF template file, map form fields to PDF coordinates, and
   copy the `[waiver_form id="N"]` shortcode into any page or post.

== External services ==

This plugin can connect to third-party services when you enable optional
features. No data is sent unless you configure the feature.

**Google reCAPTCHA v3 (optional CAPTCHA)**

Used only when you select Google reCAPTCHA v3 under **Waivers > Settings**
and enable CAPTCHA on a template.

* What it is: Google's invisible bot-detection service.
* What is sent: When a visitor submits a waiver form with CAPTCHA enabled,
  the browser loads Google's reCAPTCHA script and obtains a token. On
  submission, the plugin sends that token, your secret key, and the
  visitor's IP address to Google's verification endpoint to validate the
  request.
* When: Only during form submission when CAPTCHA is enabled for that template.
* Terms of service: https://policies.google.com/terms
* Privacy policy: https://policies.google.com/privacy

**hCaptcha (optional CAPTCHA)**

Used only when you select hCaptcha under **Waivers > Settings** and enable
CAPTCHA on a template.

* What it is: hCaptcha's invisible bot-detection service.
* What is sent: When a visitor submits a waiver form with CAPTCHA enabled,
  the browser loads hCaptcha's script and obtains a token. On submission,
  the plugin sends that token, your secret key, and the visitor's IP
  address to hCaptcha's verification endpoint to validate the request.
* When: Only during form submission when CAPTCHA is enabled for that template.
* Terms of service: https://hcaptcha.com/terms
* Privacy policy: https://hcaptcha.com/privacy

== Development & Building a Release ==

The source repository does not include the `vendor/` directory (Composer
dependencies). After cloning you must install them before the plugin will
run locally.

**Requirements:**

* Git
* PHP 8.0+ with the GD extension
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
4. Collect all plugin files (excluding `.git`, `bin/`, `composer.lock`,
   and any previously generated ZIPs). `composer.json` is included.
5. Bundle them under a top-level `waiver-engine/` folder and output
   `waiver-engine-<version>.zip` at the repository root.

The resulting ZIP is ready to upload via **WordPress Admin -> Plugins ->
Add New -> Upload Plugin**, or to submit to the WordPress.org plugin
directory.

Generated ZIP files are excluded from version control via `.gitignore`.

== Security ==

Waiver Engine implements multiple layers of protection against automated
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

* **Google reCAPTCHA v3** - invisible scoring model. A score of >= 0.5 is
  required. Register your keys at https://www.google.com/recaptcha/admin.
* **hCaptcha (invisible)** - privacy-respecting alternative. Register at
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

== Screenshots ==

1. Template editor with section builder, PDF upload, and field mapping tools.
2. Frontend waiver form rendered from a published shortcode.
3. PDF preview modal before final confirmation and submission.
4. Waiver Entries admin table with filtering, sorting, and PDF access.
5. Entry detail screen showing captured field data and generated PDF actions.
6. Settings screen for security options, CAPTCHA, cleanup, and integrations.

== Frequently Asked Questions ==

= Does this plugin require Amelia Booking? =

No. Amelia integration is optional and is only available in the separate
Pro package. The WordPress.org version works fully for standalone waivers.

= Where are submitted PDFs stored? =

Generated PDFs are stored inside the `wp-content/uploads/wpwe-pdfs/`
directory on the server. They are served for download through an
authenticated admin URL, not directly from disk.

= Can I migrate template mappings between sites? =

Yes. On the template edit screen, scroll past the Save button to find the
Export / Import section. Export downloads a JSON file containing your field
schema and PDF coordinate mappings. Import merges those into an existing
template on another site while preserving its title and other settings.

= Can booking confirmation emails link directly to the pre-filled waiver form? =

Yes, in the Pro package with Amelia integration enabled. Append a `booking_id`
query parameter to the waiver page URL and ask the customer to confirm using
the same booking email they used in Amelia. Optionally include `booking_email`
as a convenience parameter.

== Changelog ==

= 1.1.2 =
* Suppress Freemius site-wide trial and pricing upsell notices in the WordPress.org free package.
* Add a single contextual Pro mention at the bottom of the Settings screen only.

= 1.1.1 =
* WordPress.org compliance: removed feature gating and upgrade prompts from the free package.
* Moved settings page inline JavaScript to enqueued admin assets.
* Documented external CAPTCHA services in readme with terms and privacy links.
* Updated FPDF (1.9) and PDF.js (5.7.284) dependencies.
* Included composer.json in release packages; corrected repository URL.

= 1.1.0 =
* Added Free-to-Pro handoff detection and in-dashboard migration guidance.
* Added full data migration tooling (templates, entries, and key settings export/import).
* Added one-click Free plugin deactivation action from Pro handoff notice with nonce/capability safeguards.
* Refined Freemius premium package/runtime helpers for single-codebase dual ZIP packaging.

= 1.0.1 =
* Improvements to form/template handling and admin workflows.
* User guide updates with reorganized flow-based screenshots.
* Release tooling and packaging updates for consistent ZIP builds.

= 1.0.0 =
* Initial public release.
* Custom DB tables (no CPT dependency).
* PDF overlay generation via FPDI/FPDF.
* Integration manager architecture for optional third-party integrations.
* Security: WordPress nonce validation, honeypot field, HMAC-signed timing
  check, IP rate limiting (configurable via Settings), optional CAPTCHA
  (reCAPTCHA v3 or hCaptcha, configurable globally and per-template),
  and manual PDF cleanup tool.
* Import / export of template field-to-PDF mappings.

== Upgrade Notice ==

= 1.1.2 =
Suppresses dashboard upsell banners in the free package; adds one settings-only Pro link.

= 1.1.1 =
WordPress.org compliance and documentation updates. No database migrations required.

= 1.1.0 =
Adds migration and handoff tooling for Free-to-Pro transitions and data transfer.

= 1.0.1 =
Maintenance release with fixes and documentation updates.

= 1.0.0 =
First release. No upgrade steps required for fresh installs.

== License ==

Waiver Engine is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by the
Free Software Foundation, either version 2 of the License, or any later
version.

Waiver Engine is distributed in the hope that it will be useful, but
WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY
or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License
for more details.
