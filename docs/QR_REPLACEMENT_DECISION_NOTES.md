# QR replacement decision notes

## Purpose

Capture the agreed safeguards and scenario questions before implementing any
registered-QR replacement feature.

## Current system behavior

- A QR becomes `registered` when linked to a document.
- Registered QR codes cannot currently be voided. Only unused, unlinked QR
  codes can be voided.
- A document rejection/return does not void or replace its QR. The existing
  physical QR remains linked to the same document and continues to resolve to
  its updated record.

## Agreed rule

Keep the existing QR when it is attached to the correct physical document and
only document details, approval, routing, or other discrepancies need
correction.

Do not reuse, unlink, delete, or reassign a QR that has been printed and
attached to a physical document.

## Proposed future workflow: QR replacement

Use this only when the physical QR is damaged, unreadable, or attached to the
wrong physical document.

1. An authorized QR administrator selects the existing document.
2. The administrator selects or scans a new unused QR.
3. A replacement reason is required.
4. The new QR is linked to the same document.
5. The prior QR remains permanently tied to that document, but is marked
   `replaced` rather than deleted or reassigned.
6. A scan of the old QR returns a safe replacement message and does not expose
   document details.
7. A scan of the new QR resolves normally.
8. Document History and Audit Logs record the old QR, new QR, actor, time, and
   replacement reason. The public response must not disclose the reason.

## Scenario questions to decide

- Which roles may replace a registered QR?
- Is a second approval required for replacement?
- Can a replacement be requested by an office user but approved by a QR
  administrator?
- What message should a public scan of a replaced QR show?
- Should a replacement label include a version or replacement date?
- How should a missing physical document be handled differently from a
  damaged QR label?

## Implementation status

The receipt-rejection/return workflow has been added locally. Registered-QR
replacement has not been implemented yet; these notes are the design baseline
for that future work.
