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

### Resolve QR Token

```http
GET {{base_url}}/api/q/{token}
```

Public. No bearer token required.

Possible states include unused, registered, void, or invalid.

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

### Incoming Documents

```http
GET {{base_url}}/api/documents/incoming
```

Permission: `documents.view`

Results are scoped to the authenticated user's office.

### Outgoing Documents

```http
GET {{base_url}}/api/documents/outgoing
```

Permission: `documents.view`

Results are scoped to the authenticated user's office.

### Show One Document

```http
GET {{base_url}}/api/documents/{document}
```

Permission: `documents.view`

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

Only unused QR codes may be voided.

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

Failed-login auditing is deferred to Process 9 so its design can be reviewed
together with authentication rate limiting and audit retention. Process 5C
records successful login/logout events only; invalid credentials and validation
failures must not create misleading successful-login rows.

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
