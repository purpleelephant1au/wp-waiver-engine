# Demo Waiver PDFs

This folder contains example PDF templates for use when demonstrating or
testing WP Waiver Engine. They have no real business branding; all text is
generic.

> **Note:** This folder is tracked in Git but is **excluded from release ZIPs**
> produced by `bin/build-release.ps1`.

---

## Files

### `demo-standard-waiver.pdf` — Single-Person, Multi-Page Waiver

A two-page waiver for individual participants.

| Page | Sections |
|------|----------|
| 1 | Personal Details, Emergency Contact, Medical Information |
| 2 | Declaration / Terms, Participant Signature + Date, Guardian Consent (under-18) |

**Suggested field schema (for the template editor):**

| Group key    | Field key           | Label                        | Type        | Required |
|-------------|---------------------|------------------------------|-------------|----------|
| `personal`  | `first_name`        | First Name                   | text        | yes      |
| `personal`  | `last_name`         | Last Name                    | text        | yes      |
| `personal`  | `dob`               | Date of Birth                | date        | yes      |
| `personal`  | `gender`            | Gender                       | text        | no       |
| `personal`  | `address`           | Street Address               | text        | yes      |
| `personal`  | `suburb`            | Suburb                       | text        | yes      |
| `personal`  | `state`             | State                        | text        | yes      |
| `personal`  | `postcode`          | Postcode                     | number      | yes      |
| `personal`  | `phone`             | Phone Number                 | tel         | yes      |
| `personal`  | `email`             | Email Address                | email       | yes      |
| `emergency` | `ec_name`           | Emergency Contact Name       | text        | yes      |
| `emergency` | `ec_relationship`   | Relationship                 | text        | yes      |
| `emergency` | `ec_phone`          | Emergency Contact Phone      | tel         | yes      |
| `medical`   | `conditions`        | Medical Conditions / Allergies | textarea  | no       |
| `consent`   | `signature`         | Participant Signature        | signature   | yes      |
| `consent`   | `sign_date`         | Date Signed                  | date        | yes      |
| `consent`   | `print_name`        | Print Full Name              | text        | yes      |
| `guardian`  | `grd_signature`     | Guardian Signature           | signature   | no       |
| `guardian`  | `grd_date`          | Guardian Date Signed         | date        | no       |
| `guardian`  | `grd_name`          | Guardian Full Name           | text        | no       |
| `guardian`  | `grd_relationship`  | Guardian Relationship        | text        | no       |

---

### `demo-family-waiver.pdf` — Group / Family Waiver with Repeatable Participants

A two-page waiver where a lead contact signs once on behalf of a group, with
a repeatable table for up to 12 participants.

| Page | Sections |
|------|----------|
| 1 | Lead Contact Details, Emergency Contact, Booking Details |
| 2 | Participant table (repeatable rows: first name, last name, DOB, email), Declaration, Signature + Date |

**Suggested field schema (for the template editor):**

| Group key      | Repeatable | Field key        | Label               | Type    | Required |
|----------------|-----------|-----------------|---------------------|---------|----------|
| `contact`      | no        | `first_name`    | First Name          | text    | yes      |
| `contact`      | no        | `last_name`     | Last Name           | text    | yes      |
| `contact`      | no        | `address`       | Street Address      | text    | yes      |
| `contact`      | no        | `suburb`        | Suburb              | text    | yes      |
| `contact`      | no        | `state`         | State               | text    | yes      |
| `contact`      | no        | `postcode`      | Postcode            | number  | yes      |
| `contact`      | no        | `phone`         | Phone Number        | tel     | yes      |
| `contact`      | no        | `email`         | Email Address       | email   | yes      |
| `emergency`    | no        | `ec_name`       | Emergency Contact   | text    | yes      |
| `emergency`    | no        | `ec_rel`        | Relationship        | text    | yes      |
| `emergency`    | no        | `ec_phone`      | Emergency Phone     | tel     | yes      |
| `booking`      | no        | `activity`      | Activity Name       | text    | no       |
| `booking`      | no        | `session_date`  | Session Date        | date    | no       |
| `booking`      | no        | `ref`           | Booking Reference   | text    | no       |
| `participants` | **yes**   | `first_name`    | First Name          | text    | yes      |
| `participants` | **yes**   | `last_name`     | Last Name           | text    | yes      |
| `participants` | **yes**   | `dob`           | Date of Birth       | date    | yes      |
| `participants` | **yes**   | `email`         | Email               | email   | no       |
| `declaration`  | no        | `signature`     | Lead Contact Signature | signature | yes  |
| `declaration`  | no        | `sign_date`     | Date Signed         | date    | yes      |
| `declaration`  | no        | `print_name`    | Print Full Name     | text    | yes      |

**Template settings for `participants` group:**

- Repeatable: ✅
- Min rows: 1
- Max rows: 12

---

## Regenerating the PDFs

If you need to modify the PDF layouts, edit and re-run the generator:

```powershell
cd <plugin-root>
php bin/generate-demo-pdfs.php
```

Requires `composer install` to have been run first (the generator uses FPDF
from the `vendor/` directory).
