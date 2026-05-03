# Standard Waiver Use Case

This walkthrough shows how to create a single template named Standard Waiver.

It uses the demo-standard-waiver.pdf file as the base PDF and focuses on:

1. Creating the template
2. Opening the Visual Field Mapper
3. Dragging mapping boxes onto the actual PDF lines
4. Configuring the mapped fields
5. Using page 2 for the signature field

## 1) Create the template

1. Open Waiver Templates.
2. Click Add New.
3. Enter a title for this waiver.
4. Select your PDF waiver from your media library.
5. Save the template.

![Templates list](screenshots/FINAL-01-templates-list.png)
![Standard Waiver title](screenshots/FINAL-02-standard-waiver-title.png)
![Demo PDF selected](screenshots/FINAL-03-demo-pdf-selected.png)
![Demo PDF linked](screenshots/FINAL-04-demo-pdf-linked.png)
![Template saved](screenshots/FINAL-05-template-saved.png)

## 2) Open the Visual Mapper

1. Scroll to Fields & PDF Mapping.
2. Click Open Visual Mapper.
3. Confirm the mapper shows Pg 1/2, which means the PDF has multiple pages.

![Mapper page 1](screenshots/FINAL-06-mapper-page1.png)

## 3) Map fields to the actual lines on page 1

This sample PDF has three printed entry lines on page 1:

1. Participant Full Name
2. Participant Email
3. Date of Birth

Click and drag directly over each printed line so the blue mapping box sits on top of the real field area.

![Full name drag start](screenshots/FINAL-07-full-name-drag-start.png)
![Full name drag end](screenshots/FINAL-08-full-name-drag-end.png)
![Three aligned page 1 fields](screenshots/FINAL-09-page1-three-aligned-fields.png)

## 4) Field type guidance

After each box is created, configure the TYPE column for the field you are mapping.

Recommended setup for this simple example:

1. Participant Full Name: text
2. Participant Email: email
3. Date of Birth: date
4. Participant Signature: signature

The TYPE column controls whether each mapped row behaves as a text, email, date, or signature field.

The screenshot below shows the corrected aligned page 1 fields with the Group, Key, Label, and Type values filled in for the mapped rows.

![Mapped rows on page 1](screenshots/FINAL-10-page1-fields-filled.png)

## 5) Use page 2 for the signature example

Page 2 is shown separately using the Pg 1/2 navigation in the mapper header.

For this sample PDF, the signature field is mapped to the printed Signature line at the top of page 2.

![Page 2 signature area](screenshots/FINAL-11-page2-signature-area.png)
![Signature drag start](screenshots/FINAL-12-signature-drag-start.png)
![Signature drag end](screenshots/FINAL-13-signature-drag-end.png)
![Signature mapped on page 2](screenshots/FINAL-14-page2-signature-mapped.png)
