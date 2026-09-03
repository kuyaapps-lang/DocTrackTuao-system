# DocTrack Tuao API Test Reference

This file is the project-side reference for API testing with Thunder Client.

> Source of truth: `routes/api.php`.
> If routes change, update this file in the same commit.

## Local Test Settings

Base URL:

```text
http://127.0.0.1:8000
```

Recommended Thunder Client environment variables:

```text
base_url = http://127.0.0.1:8000
token =
document_id =
qr_id =
qr_token =
office_id =
user_id =
```

For protected requests, add:

```text
Accept: application/json
Authorization: Bearer {{token}}
```

For JSON requests, also add:

```text
Content-Type: application/json
```

Never commit real passwords or active bearer tokens to Git.

---

## 01 - Authentication

### Login

```http
POST {{base_url}}/api/login
```

JSON body:

```json
{
  "email": "admin@test.com",
  "password": "YOUR_PASSWORD"
}
```

Copy the returned API token into the local Thunder Client `token` variable.

### Logout

```http
POST {{base_url}}/api/logout
```

Protected.

### Current Authenticated User

```http
GET {{base_url}}/api/user
```

Protected.

Returns the authenticated user together with role, department, office, and application permissions.

---

## 02 - Public Tracking / QR Resolution

### Track by Tracking Number

```http
GET {{base_url}}/api/track/{trackingNo}
```

Public. No bearer token required.

Accepted tracking identifiers are 1–50 ASCII letters or digits separated by
single hyphens. This preserves both legacy hyphenated identifiers and the
current `DOC-` plus 17-digit format. Matching remains case-sensitive or
case-insensitive according to the existing database collation; the API does
not normalize or truncate the supplied value.

Malformed and unknown identifiers receive the same generic `404` response:

```json
{
  "message": "Document tracking number not found."
}
```

### Resolve QR Token

```http
GET {{base_url}}/api/q/{token}
```

Public. No bearer token required.

Possible states include unused, registered, void, or invalid.

Accepted QR identifiers are either the current 13-character uppercase
`XXXXX-XXXXXXX` form (using the generator's unambiguous letter/digit alphabet)
or the legacy canonical 36-character UUID form. The API does not normalize or
truncate tokens. Malformed and unknown tokens receive the same generic invalid
`404` response.

Both public endpoints have separate per-client-IP limiter buckets. Each allows
30 requests per 60 seconds by default, counts every request outcome, and returns
the following fixed JSON with normal retry headers after the allowance:

```json
{
  "message": "Too many requests. Please try again later."
}
```

Configure the bounded non-secret policy with
`PUBLIC_TRACKING_MAX_ATTEMPTS`, `PUBLIC_TRACKING_DECAY_SECONDS`,
`PUBLIC_QR_MAX_ATTEMPTS`, and `PUBLIC_QR_DECAY_SECONDS`. Invalid configuration
fails closed with generic JSON before a lookup query. Values must be PHP
integers (not booleans) or canonical unsigned ASCII decimal strings without
leading zeroes. Positive bounds are 1–1,000 attempts and 1–3,600 seconds;
values are never trimmed, coerced, rounded, or clamped.

Application code validates and canonicalizes the resolved client IP (including
equivalent IPv6 spellings), combines it with a server-controlled endpoint
category, and supplies a SHA-256 digest as the named limiter key. An invalid or
unavailable IP uses one fixed sentinel identity. No raw IP, tracking number, or
QR token is supplied as a key. Laravel then applies its own internal MD5
transformation to that already opaque application key; cache storage is not
claimed to contain the SHA-256 text unchanged. Correct `Request::ip()` behavior
depends on trusted proxy configuration when deploying behind a reverse proxy;
proxy trust is a deployment responsibility and is not broadened here.

Public tracking and QR reads create no audit record or business-data mutation.

### Application security boundary

Dynamic HTML and API responses include an enforced Content Security Policy,
`nosniff`, clickjacking protection, a strict referrer policy, a restrictive
permissions policy, and `no-store` caching. Static Vite build assets retain
their server caching behavior. Production CSP permits scripts and connections
only from the application origin. Styles retain `unsafe-inline` because the
current Vue templates use inline and dynamically bound style attributes.
`data:` and `blob:` image sources support QR rendering and local download
previews. Workers remain restricted to the application origin.

Local development reads the active loopback Vite origin from the established
`public/hot` file, or an explicitly configured `VITE_DEV_SERVER_URL`, and adds
its matching WebSocket origin. Development origins are excluded from production
policy. Vite host handling is separate from Laravel request-host validation.

`TRUSTED_HOSTS` is a comma-separated exact allowlist. It defaults to localhost
and IPv4/IPv6 loopback; production or LAN names and addresses must be added
explicitly. Wildcards, URLs, ports, paths, credentials, whitespace, and
malformed entries are rejected. Reverse-proxy trust remains a deployment task;
never trust every proxy.

Unexpected `/api/` server failures return only generic JSON even in debug mode.
Expected authentication, authorization, validation, not-found, conflict,
throttling, and safe-unavailable responses retain their existing contracts.
Existing application-controlled safe `500` responses and the public-lookup
configuration `503` are preserved only by narrow execution-path, status, exact
JSON body, content-type, and safe-header allowlists. Near matches, extra fields,
unsafe headers, duplicate or conflicting transport-header values, and responses
outside those paths become the generic error.
HSTS is disabled by default and is emitted only for verified HTTPS requests
when `SECURITY_HSTS_ENABLED=true`; HTTPS termination and server-added headers
must be verified during deployment. Laravel cannot remove disclosure headers
added later by PHP, Apache, or a reverse proxy.

Bearer tokens remain in browser local storage for the current architecture.
The enforced CSP and shorter token lifetime reduce, but do not eliminate, the
residual XSS token-theft risk.

---

## 03 - Documents

### Document Registration Options

```http
GET {{base_url}}/api/document-form-options
```

Permission: `documents.create`

Returns document types, priorities, confidentiality levels, and offices.

### List Documents

```http
GET {{base_url}}/api/documents
```

Permission: `documents.view`

Returns a deterministic newest-document-first paginated response. Search covers
tracking number, title, document type, document status, priority, and current
office. Each data item contains only:

```text
id
tracking_no
title
type: { id, type_name } or null
status: { id, status_name } or null
priority: { id, priority_name } or null
current_office: { id, office_name } or null
created_at
```

`state` is rejected with `422` on this endpoint.

### Incoming Documents

```http
GET {{base_url}}/api/documents/incoming
```

Permission: `documents.view`

Results are scoped to the authenticated user's office.

A document is incoming when any historical route has `to_office_id` equal to
the authenticated user's `office_id`. Pending and received routes both
qualify. The newest matching route supplies the movement metadata. Users
without an office receive `403`.

Search covers tracking number, title, document type, and the From Office on the
newest matching route. Optional `state` accepts `pending` or `received` and
applies only to that same newest relevant route.

Each item contains only:

```text
id
tracking_no
title
type: { id, type_name } or null
routes: [{
  from_office: { id, office_name } or null,
  received_at
}]
```

### Outgoing Documents

```http
GET {{base_url}}/api/documents/outgoing
```

Permission: `documents.view`

Results are scoped to the authenticated user's office.

A document is outgoing when any historical route has `from_office_id` equal
to the authenticated user's `office_id`. Pending and received routes both
qualify. The newest matching route supplies the movement metadata. Users
without an office receive `403`.

Search covers tracking number, title, document type, and the To Office on the
newest matching route. `state` is rejected with `422` on this endpoint.

Each item contains only:

```text
id
tracking_no
title
type: { id, type_name } or null
routes: [{
  to_office: { id, office_name } or null,
  forwarded_at
}]
```

All three document-list endpoints accept these supported controls:

- `page`: integer, minimum `1` (default `1`);
- `per_page`: exactly `10`, `25`, or `50` (default `25`);
- `search`: trimmed string, maximum `100` characters;
- `state`: Incoming only, exactly `pending` or `received`.

Invalid supported values return `422`. Other arbitrary query keys are ignored
by the current Laravel request handling and are never preserved in pagination
links. Client-controlled sorting is not supported. Results are ordered by
`documents.created_at` descending and then `documents.id` descending.

Each endpoint returns this explicit shape:

```json
{
  "data": [],
  "meta": {
    "current_page": 1,
    "last_page": 1,
    "per_page": 25,
    "total": 0,
    "from": null,
    "to": null
  },
  "links": {
    "first": "http://127.0.0.1:8000/api/documents?page=1&per_page=25",
    "last": "http://127.0.0.1:8000/api/documents?page=1&per_page=25",
    "prev": null,
    "next": null
  }
}
```

`data` uses the safe per-view item fields listed above. Links preserve only
approved list parameters and never contain credentials or bearer tokens.

### Show One Document

```http
GET {{base_url}}/api/documents/{document}
```

Permission: `documents.view`

Attachment metadata is not embedded in this response. Use the separately
authorized `GET /api/documents/{document}/attachments` endpoint.

Direct document reads are additionally office-scoped. Administrator and
Records Officer have system-wide access. Office User, Viewer, and any other
role that passes `documents.view` may read a document only when their valid
assigned office is its origin office, current office, historical route sender,
or historical route destination. Missing, invalid, and unrelated office
assignments receive `403`.

The same rule applies to these sensitive reads:

- `GET /api/documents/{document}`;
- `GET /api/documents/{document}/routing-options`;
- `GET /api/documents/{document}/history`;
- `GET /api/documents/{document}/processing`.

These endpoints return explicit fields used by the document-detail workflow.
Related users contain only `id` and `name`; responses omit email addresses,
credentials, token data, attachment storage names/paths, and unrestricted
relationship trees. The Process 7 document lists and the dedicated attachment
authorization rules are unchanged.

### Register Document

```http
POST {{base_url}}/api/documents
```

Permission: `documents.create`

Example JSON body:

```json
{
  "title": "API Test Document",
  "description": "Created from Thunder Client",
  "document_type_id": 1,
  "priority_id": 1,
  "confidentiality_level_id": 1,
  "origin_office_id": 1,
  "document_date": "2026-08-24",
  "due_date": null
}
```

For QR-based registration, optionally include:

```json
{
  "qr_token": "XXXXX-XXXXXXX"
}
```

### Update Document

```http
PUT {{base_url}}/api/documents/{document}
```

or

```http
PATCH {{base_url}}/api/documents/{document}
```

Permission: `documents.edit`

The user must still belong to the document's current office. Ordinary updates
accept only `title`, `description`, `document_type_id`, `priority_id`,
`confidentiality_level_id`, `document_date`, and `due_date`. The
workflow-controlled `origin_office_id`, `current_office_id`, and `status_id`
fields are prohibited and return `422`. Tracking number, processing action and
note/history, current-action actor/time, creator, and routing records are not
ordinary editable fields and are never applied by this endpoint.

Document forward, receive, and processing transitions re-read the current
document state inside a transaction while holding the document lock. Pending
route checks use the same transaction and lock the relevant route rows after
the document. Replayed forward/receive requests return `409` without creating
another route, history event, state change, or audit. More than one pending
route is treated as an invalid state and is not received.

An identical processing action and normalized note submission returns `200`
with `Current processing action is already up to date.` It is a no-op and does
not create another processing-history or audit row. A different authorized
processing update remains a new history event.

### Delete Document

```http
DELETE {{base_url}}/api/documents/{document}
```

Permission: `documents.delete`

Administrator permission only in the current RBAC map. Use carefully; hard-delete policy may be tightened later.

---

## 04 - Document Processing

### Get Current Processing

```http
GET {{base_url}}/api/documents/{document}/processing
```

Permission: `documents.view`

### Update Current Processing

```http
PUT {{base_url}}/api/documents/{document}/processing
```

Permission: `documents.process`

Example JSON body:

```json
{
  "current_action_id": 3,
  "processing_note": "Reviewed using Thunder Client."
}
```

The user must also be assigned to the document's current office.

System-controlled actions cannot be selected manually:

```text
REGISTERED
AWAITING_RECEIPT
FOR_ACTION
```

`OTHER` requires a processing note.

---

## 05 - Document Routing

### Routing Options

```http
GET {{base_url}}/api/documents/{document}/routing-options
```

Permission: `documents.view`

### Forward Document

```http
POST {{base_url}}/api/documents/{document}/forward
```

Permission: `documents.route`

Example JSON body:

```json
{
  "to_office_id": 2,
  "remarks": "Forwarded for review."
}
```

The authenticated user's office must be the document's current office. A document with a pending unreceived route cannot be forwarded again.

### Receive Document

```http
POST {{base_url}}/api/documents/{document}/receive
```

Permission: `documents.route`

No JSON body is required by the current frontend workflow.

The authenticated user's office must match the pending route destination office.

### Movement / Routing History

```http
GET {{base_url}}/api/documents/{document}/history
```

Permission: `documents.view`

---

## 06 - QR Code Management

### List QR Codes

```http
GET {{base_url}}/api/qr-codes
```

Permission: `qr.view`

This legacy authorized list supports the existing issuance summary. Use the
token-free inventory below for persisted-record administration.

### Persisted QR Inventory

```http
GET {{base_url}}/api/qr-codes/inventory?page=1&per_page=10&status=unused
```

Permission: `qr.manage`

Supported parameters are `page` (positive integer), `per_page` (`10`, `25`,
or `50`), and optional `status` (`unused`, `registered`, or `void`). Unknown or
invalid parameters return `422`. Results are ordered newest-first by issuance
timestamp and then numeric ID.

```json
{
  "data": [
    {
      "id": 42,
      "status": "unused",
      "issued_at": "2026-01-15T08:30:00+00:00",
      "linked": false
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 1,
    "per_page": 10,
    "total": 1,
    "from": 1,
    "to": 1
  }
}
```

The inventory uses explicit safe arrays and never returns QR tokens, encoded
payloads, user details, document content, or raw model relationships. Inventory
reads do not create audit events or mutate application data.

Unauthenticated requests return `401`; authenticated users without
`qr.manage` receive `403`. Invalid or unsupported inventory query parameters
return `422`. Clients must show fixed safe messages and must not render raw
error bodies.

### Generate QR Codes

```http
POST {{base_url}}/api/qr-codes
```

Permission: `qr.manage`

JSON body:

```json
{
  "quantity": 1
}
```

Quantity must be from 1 to 50.

### Show QR Record

```http
GET {{base_url}}/api/qr-codes/{qrCode}
```

Permission: `qr.view`

### Void QR Code

```http
POST {{base_url}}/api/qr-codes/{qrCode}/void
```

Permission: `qr.manage`

Only unused, unlinked QR codes may be voided. The QR row is re-read and locked
inside the void transaction. Registered, linked, already-void, stale, and other
invalid lifecycle states return `409`; replaying a successful void creates no
additional audit. A successful void changes one lifecycle value and creates one
safe audit event.

Clients should treat `409` as a lifecycle conflict, refresh the affected
inventory page, and show a generic fixed message. They should clear established
authentication state on `401`; `403`, validation, network, and unexpected
failures must not expose raw response details.

---

## 07 - Attachments

### List Document Attachments

```http
GET {{base_url}}/api/documents/{document}/attachments
```

Permission: `attachments.view`

### Upload Attachment(s)

```http
POST {{base_url}}/api/documents/{document}/attachments
```

Permission: `attachments.manage`

Use Thunder Client `Form` / `Multipart Form` rather than JSON. Add the file field(s) expected by the current attachment controller.

### Download Attachment

```http
GET {{base_url}}/api/attachments/{attachment}/download
```

Permission: `attachments.view`

### Delete Attachment

```http
DELETE {{base_url}}/api/attachments/{attachment}
```

Permission: `attachments.manage`

Attachment actions are additionally subject to document/office access rules.

---

## 08 - User Management

Administrator-only via `users.manage`.

### User Form Options

```http
GET {{base_url}}/api/users/form-options
```

### List Users

```http
GET {{base_url}}/api/users
```

### Create User

```http
POST {{base_url}}/api/users
```

Example JSON body:

```json
{
  "name": "API User Test",
  "email": "apiuser@test.com",
  "role_id": 3,
  "office_id": 3,
  "password": "CHANGE_ME",
  "password_confirmation": "CHANGE_ME"
}
```

The backend automatically synchronizes `department_id` from the selected office.

### Update User

```http
PUT {{base_url}}/api/users/{user}
```

or

```http
PATCH {{base_url}}/api/users/{user}
```

Example without changing the password:

```json
{
  "name": "API User Test Updated",
  "email": "apiuser@test.com",
  "role_id": 3,
  "office_id": 3,
  "password": null,
  "password_confirmation": null
}
```

The current user cannot change their own role through this endpoint.

---

## 09 - Offices

### List Offices

```http
GET {{base_url}}/api/offices
```

Permission: `master_data.view`

### Create Office

```http
POST {{base_url}}/api/offices
```

Permission: `master_data.manage`

### Show Office

```http
GET {{base_url}}/api/offices/{office}
```

Permission: `master_data.view`

### Update Office

```http
PUT {{base_url}}/api/offices/{office}
```

or

```http
PATCH {{base_url}}/api/offices/{office}
```

Permission: `master_data.manage`

### Delete Office

```http
DELETE {{base_url}}/api/offices/{office}
```

Permission: `master_data.manage`

---

## 10 - Document Types

### List Document Types

```http
GET {{base_url}}/api/document-types
```

Permission: `master_data.view`

### Create Document Type

```http
POST {{base_url}}/api/document-types
```

Permission: `master_data.manage`

### Show Document Type

```http
GET {{base_url}}/api/document-types/{documentType}
```

Permission: `master_data.view`

### Update Document Type

```http
PUT {{base_url}}/api/document-types/{documentType}
```

or

```http
PATCH {{base_url}}/api/document-types/{documentType}
```

Permission: `master_data.manage`

### Delete Document Type

```http
DELETE {{base_url}}/api/document-types/{documentType}
```

Permission: `master_data.manage`

---

## Dashboard Summary

### `GET /api/dashboard/summary`

Requires authentication and the `reports.view` permission.

Supported query parameter:

- `month`: optional strict Gregorian `YYYY-MM` value.

No month means all-time movement/registration data and current document-state
counts. The configured reporting timezone defaults to `Asia/Manila`. Month
boundaries use the inclusive local month start and exclusive next-month start,
converted to UTC for stored timestamp comparisons.

If the configured reporting timezone is invalid, the endpoint returns a
generic `500` JSON response without exposing the configured value or diagnostic
details. It never substitutes a different timezone.

All other query parameters are rejected with `422`. Clients cannot choose an
office, role, scope, sort, column, group, or arbitrary date range.

Scope is determined by the authenticated user:

- Administrator and Records Officer receive system-wide results.
- Office User and Viewer receive office-scoped results.
- The office universe includes surviving documents whose origin office,
  current office, or routing history involves the user's office.
- Office-scoped users without a valid office receive `403`.

Metric meanings:

- `total_documents`: distinct surviving documents in scope.
- `incoming_movements`: historical routes to the scoped office, or all routes
  for system scope. A document may contribute multiple movements.
- `outgoing_movements`: historical routes from the scoped office, or all
  routes for system scope. A document may contribute multiple movements.
- `in_transit_documents`: distinct documents with an unreceived route.
- `received_documents`: documents currently in Received status with no
  unreceived route.
- distributions use each document's current status/current office/origin
  office and include an explicit Unassigned bucket when the relation is null.

When `month` is supplied, document/current-state metrics and recent documents
use `documents.created_at`; movement counts use `forwarded_at`; each routing
activity event uses its own `occurred_at` (`forwarded_at` or `received_at`).

Exact top-level response shape:

```json
{
  "filters": {
    "month": null,
    "timezone": "Asia/Manila"
  },
  "scope": {
    "type": "system",
    "office": null
  },
  "summary": {
    "total_documents": 0,
    "incoming_movements": 0,
    "outgoing_movements": 0,
    "in_transit_documents": 0,
    "received_documents": 0
  },
  "status_distribution": [],
  "current_office_distribution": [],
  "origin_office_distribution": [],
  "recent_documents": [],
  "recent_routing_activity": []
}
```

Distribution items contain only `{ status: { id, name }, count }` or
`{ office: { id, name }, count }`. Recent documents contain only document ID,
tracking number, safe status, and UTC `created_at`. Routing activity contains
only safe document ID/tracking number, event type, safe from/to office objects,
and UTC `occurred_at`.

Recent results are limited to 10. Documents are ordered by `created_at` then
document ID descending. Routing activity is ordered by occurred time, route ID,
and fixed event precedence descending.

The endpoint never returns titles, descriptions, processing notes, route
remarks, users, actor identities, emails, attachments, filenames, paths,
credentials, raw models, or query details. It is read-only and creates no audit
event.

---

## Current RBAC Reference

### Administrator

All currently defined permissions.

### Records Officer

```text
documents.view
documents.create
documents.edit
documents.process
documents.route
attachments.view
attachments.manage
qr.view
qr.manage
master_data.view
reports.view
audit.view
```

### Office User

```text
documents.view
documents.process
documents.route
attachments.view
attachments.manage
master_data.view
reports.view
```

### Viewer

```text
documents.view
attachments.view
master_data.view
reports.view
```

Remember:

```text
ROLE / PERMISSION = what the user may do
OFFICE            = which documents the user may act on
```

Backend authorization remains authoritative even if a frontend button is hidden.

---

## Suggested Thunder Client Folder Layout

```text
DocTrack Tuao API
|
+-- 01 Authentication
|   +-- Login
|   +-- Current User
|   +-- Logout
|
+-- 02 Documents
|   +-- Form Options
|   +-- List Documents
|   +-- Incoming
|   +-- Outgoing
|   +-- Show Document
|   +-- Register Document
|
+-- 03 QR Codes
|   +-- List QR Codes
|   +-- Generate QR Codes
|   +-- Show QR
|   +-- Void QR
|   +-- Resolve QR
|
+-- 04 Processing
|   +-- Get Current Processing
|   +-- Update Current Processing
|
+-- 05 Routing
|   +-- Routing Options
|   +-- Forward Document
|   +-- Receive Document
|   +-- History
|
+-- 06 Attachments
|   +-- List Attachments
|   +-- Upload Attachment
|   +-- Download Attachment
|   +-- Delete Attachment
|
+-- 07 User Management
|   +-- Form Options
|   +-- List Users
|   +-- Create User
|   +-- Update User
|
+-- 08 Master Data
    +-- Offices
    +-- Document Types
```

---

## Git Workflow

This reference file is intended to be tracked by Git.

After creating or updating it:

```powershell
git add api-tests/API-ENDPOINTS.md
git commit -m "Add API testing reference"
git push origin main
```

On the other PC:

```powershell
git pull origin main
```

Thunder Client itself does not need to be synchronized for this workflow. Recreate only the requests needed on each device using this reference.

## Security Rule

Login accepts at most five requests per normalized email-and-client-IP key in
each 60-second window. Every accepted request counts, including malformed,
failed, and successful attempts; the next request receives a generic `429`.
The raw email is not used in the limiter cache key.

Successful login returns the existing Bearer token field, revokes that user's
older tokens, and creates one token that expires after 480 minutes by default.
Email, password, role, or office changes revoke the affected user's tokens;
name-only changes retain them. Expired or replaced tokens receive `401`, after
which the frontend clears local authentication and requires login. Closing the
browser is not server logout. Deployment must schedule Sanctum's expired-token
pruning command; Process 9B does not add the production scheduler.

Failed-login auditing remains deferred so its retention and privacy impact can
be reviewed separately. Successful login/logout events are recorded; invalid,
throttled, and validation-failed requests do not create misleading successful-
login audit rows.

Public QR-scan auditing and the QR void/registration concurrency review are
deferred to Process 9 so rate limiting, audit retention, and lifecycle locking
can be considered together. Process 5D does not audit QR list, show, or public
scan requests.

## Audit Logs

### `GET /api/audit-logs`

Requires an authenticated user with `audit.view`. Administrator and Records
Officer roles currently have system-wide access. Office User and Viewer roles
receive `403`; unauthenticated requests receive `401`.

Results are ordered by descending audit ID and are always paginated. Supported
query parameters are:

- `page`: integer, minimum `1`;
- `per_page`: exactly `10`, `25`, or `50` (default `25`);
- `module`: an exact stable audit module value;
- `action`: an exact stable audit action value.

Each item contains only:

```text
id
actor: { id, name } or null
module
action
record_id
description
ip_address
created_at
```

The response also includes Laravel pagination links and metadata. The endpoint
does not expose raw `user_id`, user agent, updated timestamp, complete user
records, credentials, tokens, request payloads, SQL, exceptions, filenames, or
storage paths. It does not support unbounded results, arbitrary sorting,
free-text search, export, or actor/IP/record/date filtering.

Do not put these in this Git-tracked file:

```text
real passwords
active Sanctum bearer tokens
private keys
production credentials
```

Keep secrets only in local Thunder Client environments or local `.env` files that are excluded from Git.
