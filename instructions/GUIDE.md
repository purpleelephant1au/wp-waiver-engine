# WP Waiver Engine — User Guide

This guide walks through configuring and using the WP Waiver Engine plugin, from creating PDF templates and mapping fields through to embedding forms on pages and reviewing submitted entries.

Screenshots are in the `guide-screenshots/` folder alongside this file.

---

## Table of Contents

1. [Overview](#1-overview)
2. [Creating a Standard (Single-Participant) Template](#2-creating-a-standard-single-participant-template)
3. [Mapping Fields with the Visual Mapper](#3-mapping-fields-with-the-visual-mapper)
4. [Creating a Family / Group (Repeatable-Row) Template](#4-creating-a-family--group-repeatable-row-template)
5. [Mapping Repeatable Fields](#5-mapping-repeatable-fields)
6. [Templates List — Finding Your Shortcodes](#6-templates-list--finding-your-shortcodes)
7. [Embedding a Waiver Form on a Page](#7-embedding-a-waiver-form-on-a-page)
8. [Filling In and Submitting the Standard Form](#8-filling-in-and-submitting-the-standard-form)
9. [Filling In and Submitting the Group / Family Form](#9-filling-in-and-submitting-the-group--family-form)
10. [Viewing Waiver Entries in the Backend](#10-viewing-waiver-entries-in-the-backend)

---

## 1. Overview

WP Waiver Engine lets you:

- Upload any PDF as a waiver base document.
- Visually map form fields onto the PDF so submitted data is stamped directly into the PDF.
- Optionally generate **one PDF per repeating row** (e.g. one waiver per participant in a group booking).
- Embed the resulting form anywhere on your site using a shortcode.
- Store and view all submissions from the WordPress admin.

---

## 2. Creating a Standard (Single-Participant) Template

Navigate to **WaiverEngine → Templates** in the WordPress admin.

![Empty templates list](guide-screenshots/01-templates-list-empty.png)
*The templates list is empty on a fresh install.*

Click **Add New**.

![Add New template form](guide-screenshots/02-add-new-template.png)

Fill in a **Title** (e.g. "Standard Participant Waiver"), then click **Choose PDF** to open the Media Library picker.

![PDF picker modal](guide-screenshots/04-pdf-picker-modal.png)
*Both uploaded demo PDFs are shown in the picker.*

Select your PDF and click **Use this PDF**.

![Standard PDF selected](guide-screenshots/05-pdf-picker-selected.png)

The template settings page shows the PDF thumbnail and basic options:

![Template settings — top](guide-screenshots/06-template-pdf-selected.png)

![Template settings — output mode](guide-screenshots/07-template-settings-bottom.png)

For a single-participant form leave **Output Mode** set to its default (*Single PDF per submission*). Click **Save Template** to save before opening the mapper.

![Template saved confirmation](guide-screenshots/10-template-saved.png)

---

## 3. Mapping Fields with the Visual Mapper

After saving, the **Open Visual Mapper** button becomes active.

![Open Visual Mapper button](guide-screenshots/09-open-visual-mapper-btn.png)

Click it to launch the mapper overlay. The PDF is rendered in the left panel; the field table is on the right.

![Visual Mapper open — empty](guide-screenshots/12-visual-mapper-open.png)

### Drawing a field on the PDF

Scroll the PDF canvas to the form area you want to map.

![PDF scrolled to show fillable area](guide-screenshots/15-mapper-showing-fields.png)

**Click and drag** on the PDF to draw a rectangle over the target field. A new row instantly appears in the field table.

![Blue rectangle drawn over Phone Number field](guide-screenshots/17-field-row-drawn.png)
*The blue rectangle marks the exact area that will be filled with the submitted value.*

Fill in the row details:

| Column | Value |
|--------|-------|
| **Group** | e.g. `personal` — a logical namespace |
| **Key** | e.g. `phone` — the data key |
| **Label** | e.g. `Phone Number` — shown on the form |
| **Type** | e.g. `tel`, `email`, `text`, `number`, `date`, `signature` |

![Field row filled in](guide-screenshots/18-field-row-filled.png)

> **Resize / move a rectangle** — hover over any drawn rectangle on the PDF and drag the handles to resize, or drag the centre to reposition it.

### Adding a date field with a custom format

Click **+ Add Field** (below the table) to add a row without drawing a rectangle — useful for fields that don't need to overlay the PDF, or that you'll position later.

For `type = date`, a **Format** column appears. Enter a PHP `date()` format string such as `d/m/Y` to control how the date is stamped into the PDF.

![Date field with d/m/Y format](guide-screenshots/22-date-format-field.png)

### Full field list for the standard template

After adding all eight fields the mapper table looks like this:

![All 8 fields in the mapper](guide-screenshots/24-mapper-full-field-list.png)

| Group | Key | Label | Type | Notes |
|-------|-----|-------|------|-------|
| personal | phone | Phone Number | tel | drawn on PDF |
| personal | email | Email Address | email | drawn on PDF |
| personal | dob | Date of Birth | date | format: `d/m/Y` |
| personal | first_name | First Name | text | |
| personal | last_name | Last Name | text | |
| personal | postcode | Postcode | number | |
| consent | signature | Participant Signature | signature | signature pad |
| consent | sign_date | Date Signed | date | format: `d/m/Y` |

Click **Done** to close the mapper. The template page shows the field count.

![Mapper closed — 8 fields mapped](guide-screenshots/26-8-fields-ready-to-save.png)

Click **Save Template** (or **Update Template** on subsequent saves).

---

## 4. Creating a Family / Group (Repeatable-Row) Template

From the Templates list click **Add New** again.

Set a title (e.g. "Family / Group Waiver") and select the group/family PDF.

![Family PDF selected](guide-screenshots/29-family-pdf-selected.png)

Scroll to the **Output Mode** section and choose **One PDF per row (repeatable group)**.

![Output Mode drop-down](guide-screenshots/30-family-template-output-mode.png)

A new field appears: **Repeatable Group Key**. Enter the key name that will hold the array of participants — e.g. `participants`.

![One PDF per row + group key set](guide-screenshots/31-output-mode-per-row.png)

> With this mode WP Waiver Engine generates **one filled PDF per participant row** plus a **combined PDF** containing all pages. The family/group entry in the backend will list all generated PDFs.

Save the template.

![Family template saved](guide-screenshots/32-family-template-saved.png)

---

## 5. Mapping Repeatable Fields

Open the Visual Mapper for the family template.

![Family mapper open](guide-screenshots/33-family-mapper-open.png)

For fields that belong to the repeating **participants** group, check the **RPT** (repeatable) checkbox in the field row. This tells the engine to iterate over all participant rows when generating PDFs.

![Repeatable rows with RPT checkmarks](guide-screenshots/35-family-repeatable-rows.png)

Fields **without** RPT (e.g. `declaration / signature`) are stamped once per submission, not once per participant.

Example field layout:

| Group | Key | Label | Type | RPT |
|-------|-----|-------|------|-----|
| participants | first_name | First Name | text | ✅ |
| participants | last_name | Last Name | text | ✅ |
| participants | dob | Date of Birth | date | ✅ |
| participants | email | Email | email | ✅ |
| declaration | signature | Lead/Guardian Signature | signature | ☐ |
| declaration | sign_date | Date Signed | date | ☐ |

Close the mapper and click **Update Template**.

---

## 6. Templates List — Finding Your Shortcodes

Return to **WaiverEngine → Templates**. Each saved template has a **Shortcode** column with the code to embed the form.

![Templates list with both shortcodes](guide-screenshots/36-templates-both.png)

| Template | Shortcode |
|----------|-----------|
| Standard Participant Waiver | `[waiver_form id="1"]` |
| Family / Group Waiver | `[waiver_form id="2"]` |

---

## 7. Embedding a Waiver Form on a Page

Open any WordPress page in the block editor. Add a **Shortcode** block (use the block inserter and search "shortcode"), then paste the shortcode.

![Shortcode block — Standard form page](guide-screenshots/39-shortcode-block-standard.png)

![Shortcode block — Group form page](guide-screenshots/40-shortcode-block-group.png)

The page status should already show **Published**. Click **Save** if any changes are pending.

---

## 8. Filling In and Submitting the Standard Form

Visit the page on the frontend. The form renders with labelled groups matching the mapper configuration.

![Standard form — blank](guide-screenshots/41-frontend-standard-form-empty.png)

Fill in all fields. The **Date of Birth** and **Date Signed** fields show as calendar pickers and are formatted as `dd/mm/yyyy` in the UI (and stamped as configured in the mapper).

The **Participant Signature** field is a canvas-based signature pad — click and drag to draw.

![Standard form — filled with signature](guide-screenshots/42-frontend-standard-form-filled.png)

Click **Submit Waiver**. A success message appears below the form.

![Standard form — submission confirmed](guide-screenshots/43-standard-form-submitted.png)

---

## 9. Filling In and Submitting the Group / Family Form

Visit the group form page on the frontend.

![Group form — blank](guide-screenshots/44-frontend-group-form-empty.png)

The form has two sections:

- **Contact** — the lead contact's details (non-repeating).
- **Participants** — a repeating table. Each row collects one participant's First Name, Last Name, Date of Birth, and Email.

Click **+ Add Participants** to add extra rows.

The **Mode** dropdown (Repeating / Single) lets the user switch if they only need a single participant.

![Group form — filled with 2 participants](guide-screenshots/45-frontend-group-form-filled.png)

The **Declaration** section contains the lead contact's signature and date signed (applied to all generated PDFs).

Click **Submit Waiver**. Confirmation shows when complete.

![Group form — submitted](guide-screenshots/46-group-form-submitted.png)

---

## 10. Viewing Waiver Entries in the Backend

Navigate to **WaiverEngine → Entries**.

![Waiver Entries list](guide-screenshots/47-entries-list.png)

The list shows:

| Column | Description |
|--------|-------------|
| **#** | Entry ID |
| **Template** | Which waiver template was used |
| **PDFs** | Links to view or download each generated PDF |
| **Email Sent** | Whether a copy was emailed to the submitter |
| **Submitted** | Date and time of submission |

> Notice that Entry #2 (Family / Group Waiver) has **3 PDFs** — one per participant plus the combined PDF.

Click **View** on any row to see the full entry detail.

### Standard entry detail

![Entry #1 — top](guide-screenshots/48-entry-detail-standard.png)
![Entry #1 — field values](guide-screenshots/48b-entry-detail-scrolled.png)

The detail page shows:

- **Template** link, submission timestamp, submitter IP, email send status.
- **Generated PDFs** — view or download each file.
- **Field Values** table — every submitted value including a preview of the signature image.

### Group entry detail

![Entry #2 — group entry with 3 PDFs and all field values](guide-screenshots/49-entry-detail-group.png)

For group entries the Field Values table lists each participant row separately (`participants[0].first_name`, `participants[1].first_name`, etc.) alongside the non-repeating declaration fields.

---

## Summary

| Step | What to do |
|------|------------|
| 1 | Upload your PDF to the WordPress Media Library |
| 2 | Create a template under **WaiverEngine → Templates** and attach the PDF |
| 3 | Open the Visual Mapper and draw rectangles to place fields, or use **+ Add Field** for fields not overlaid on the PDF |
| 4 | For group bookings set **Output Mode → One PDF per row** and tick **RPT** on participant fields |
| 5 | Copy the shortcode from the Templates list |
| 6 | Paste the shortcode into a page using the WordPress Shortcode block |
| 7 | Visitors fill in and submit the form on the frontend |
| 8 | Review submitted entries under **WaiverEngine → Entries** |
