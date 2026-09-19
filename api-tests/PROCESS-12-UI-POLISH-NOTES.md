# Process 12: future UI polish notes

These notes capture future interface inspiration from a reference DMS screen set.
They are not committed scope for adviser preview or near-deployment hardening.
Do not start implementation until the current priority items are closed:
Forgot Password visible behavior, dashboard default filter, clean migration
replay, and final injection/security smoke.

## Reference ideas to consider later

- Group the sidebar into clearer sections such as Dashboard, Transactions, and
  Reports.
- Make Incoming Documents and Outgoing Documents first-class navigation entries
  if they improve daily office workflows.
- Consider a Document Status or Inquiry area that supports QR scanning and manual
  tracking-number search.
- Consider a QR Requests area if future QR issuance/replacement workflows need a
  dedicated home.
- Consider report pages for Documents Received and Documents Released.
- Add a notification or activity panel if it helps users notice pending work,
  recent routing events, or documents needing action.
- Add compact quick actions for common office tasks such as receive, release, and
  forward, while keeping detail pages available for confirmation and context.

## Guardrails

- Do not copy the external design exactly; use it only as workflow inspiration.
- Preserve DocTrack's existing role-based access control, office scope, and
  server-side authorization. Hiding a button is not authorization.
- Keep workflow state rules intact: custody, pending routes, receipt, forwarding,
  processing history, QR status, attachments, and audits must remain protected by
  backend validation.
- Prefer improving navigation clarity and task flow before visual decoration.
- Keep future UI changes small enough to test by role and office before moving on.
