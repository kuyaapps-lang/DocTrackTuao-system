from pathlib import Path
from docx import Document
from docx.shared import Inches, Pt, RGBColor
from docx.enum.text import WD_ALIGN_PARAGRAPH, WD_BREAK
from docx.enum.section import WD_SECTION_START
from docx.enum.table import WD_TABLE_ALIGNMENT, WD_CELL_VERTICAL_ALIGNMENT
from docx.oxml import OxmlElement
from docx.oxml.ns import qn
from PIL import Image, ImageDraw, ImageFont, ImageFilter

ROOT = Path(r"C:\xampp\htdocs\DocTrackTuao-system")
OUT = ROOT / "output" / "documentation" / "DocTrackTuao-System-Documentation.docx"
ASSET = ROOT / "output" / "thesis-work" / "figures"
ASSET.mkdir(parents=True, exist_ok=True)
SCREEN_SOURCE = ROOT / "output" / "documentation" / "system-screenshots"
SCREEN = ROOT / "output" / "thesis-work" / "sanitized-screenshots"
SCREEN.mkdir(parents=True, exist_ok=True)

def font(size, bold=False):
    paths = [r"C:\Windows\Fonts\times.ttf", r"C:\Windows\Fonts\timesbd.ttf"]
    return ImageFont.truetype(paths[1 if bold else 0], size)

def redact_image(filename, regions=()):
    image = Image.open(SCREEN_SOURCE / filename).convert('RGB')
    # The signed-in account label appears in the same top-right region on the captured app screens.
    regions = [(1200, 0, image.width, 115), *regions]
    for box in regions:
        box = (
            max(0, box[0]), max(0, box[1]),
            min(image.width, box[2]), min(image.height, box[3]),
        )
        if box[2] <= box[0] or box[3] <= box[1]:
            continue
        crop = image.crop(box).filter(ImageFilter.GaussianBlur(radius=18))
        image.paste(crop, box)
        overlay = ImageDraw.Draw(image)
        overlay.rectangle(box, outline='#d1d5db', width=3)
        overlay.text((box[0] + 12, box[1] + 12), 'REDACTED', fill='#e5e7eb', font=font(18, True))
    output = SCREEN / filename
    image.save(output)
    return output

screens = {
    'login': redact_image('00-login.png', []),
    'dashboard': redact_image('01-dashboard.png', []),
    'documents': redact_image('02-documents.png', []),
    'qr': redact_image('03-qr-codes.png', [(270, 270, 1410, 3000)]),
    'inquiry': redact_image('04-document-inquiry.png', [(270, 270, 1410, 3000)]),
    'users': redact_image('05-users.png', [(270, 270, 1410, 3000)]),
    'offices': redact_image('06-offices-master-data.png', []),
    'audit': redact_image('07-audit-logs.png', [(270, 270, 1410, 3000)]),
    'report': redact_image('08-accomplishment-report.png', [(270, 270, 1410, 3000)]),
}

def diagram(name, title, columns):
    w, h = 1800, 980
    im = Image.new('RGB', (w, h), 'white')
    d = ImageDraw.Draw(im)
    d.text((70, 40), title, fill='#102a43', font=font(44, True))
    n = len(columns); gap = 30; bw = (w - 140 - gap * (n-1)) // n
    for i, (head, lines) in enumerate(columns):
        x = 70 + i * (bw + gap); y = 190
        d.rounded_rectangle((x, y, x+bw, 770), radius=20, fill='#f3f7fb', outline='#1f4e79', width=4)
        d.rounded_rectangle((x, y, x+bw, y+92), radius=20, fill='#1f4e79')
        d.text((x+24, y+25), head, fill='white', font=font(28, True))
        ty = y+130
        for line in lines:
            d.text((x+28, ty), u'• ' + line, fill='#1f2937', font=font(23))
            ty += 62
        if i < n-1:
            ax = x+bw+8; ay = 465
            d.line((ax, ay, ax+gap-18, ay), fill='#1f4e79', width=6)
            d.polygon([(ax+gap-18, ay), (ax+gap-38, ay-13), (ax+gap-38, ay+13)], fill='#1f4e79')
    p = ASSET / f"{name}.png"; im.save(p); return p

arch = diagram('architecture', 'DocTrackTuao Web Application Architecture', [
    ('Vue and Vite Client', ['Role-aware pages', 'QR scanning and inquiry', 'Forms and reports']),
    ('Laravel API', ['Sanctum authentication', 'Permission middleware', 'Validation and workflow rules']),
    ('MySQL and Local Storage', ['Documents and routes', 'Audit and processing logs', 'Protected attachments']),
])
workflow = diagram('workflow', 'Controlled Document Workflow', [
    ('Registration', ['Verify issued QR', 'Save metadata', 'Create tracking record']),
    ('Routing and Custody', ['Forward document', 'Pending route: in transit', 'Destination receives']),
    ('Processing and Accountability', ['Current action / note', 'Attachments when allowed', 'History, audit, complete, archive']),
])
roles = diagram('roles', 'Role Permission and Office Scope Model', [
    ('Role', ['Administrator: system-wide', 'Records Officer: workflow tools', 'Office User: office operations', 'Viewer: read only']),
    ('Permission Gate', ['Route middleware', 'Server-side validation', 'Forbidden requests: 403']),
    ('Office Custody Gate', ['User must have an office', 'Office must hold document', 'No pending route for processing']),
])
erd = diagram('erd', 'Core Entity Relationship View', [
    ('People and Organization', ['users -> roles', 'users -> offices', 'offices -> departments']),
    ('Document Record', ['documents -> type/status', 'origin and current office', 'one document QR record set']),
    ('History and Evidence', ['document_routes', 'processing_logs', 'attachments and audit_logs']),
])
usecase = diagram('usecase', 'Primary Use Cases', [
    ('Administrator', ['Manage users', 'Manage master data', 'Issue/void QR', 'View reports and audit']),
    ('Records Officer', ['Register document', 'Route/receive when authorized', 'QR and audit functions']),
    ('Office User and Viewer', ['Office User: route/process in custody', 'Viewer: read permitted records', 'Both: scoped reports where allowed']),
])
sequence = diagram('sequence', 'Document Routing Sequence', [
    ('Sending Office', ['Holds document', 'Forwards to destination', 'Route becomes pending']),
    ('System', ['Writes route history', 'Marks in transit', 'Prevents processing update']),
    ('Receiving Office', ['Receives pending route', 'Takes current custody', 'May process then re-route']),
])

doc = Document()
sec = doc.sections[0]
sec.page_width, sec.page_height = Inches(8.27), Inches(11.69)
sec.left_margin, sec.right_margin = Inches(1.56), Inches(.71)
sec.top_margin, sec.bottom_margin = Inches(1.25), Inches(1.60)
sec.header_distance, sec.footer_distance = Inches(.30), Inches(.20)

styles = doc.styles
normal = styles['Normal']; normal.font.name = 'Times New Roman'; normal.font.size = Pt(12)
normal._element.rPr.rFonts.set(qn('w:ascii'), 'Times New Roman'); normal._element.rPr.rFonts.set(qn('w:hAnsi'), 'Times New Roman')
normal.paragraph_format.line_spacing = 1.5; normal.paragraph_format.space_after = Pt(6); normal.paragraph_format.first_line_indent = Inches(.30)
for name, size, before, after in [('Heading 1',14,24,12),('Heading 2',13,18,8),('Heading 3',12,12,6)]:
    s=styles[name]; s.font.name='Times New Roman'; s.font.size=Pt(size); s.font.bold=True; s.font.color.rgb=RGBColor(0,0,0)
    s._element.rPr.rFonts.set(qn('w:ascii'),'Times New Roman'); s._element.rPr.rFonts.set(qn('w:hAnsi'),'Times New Roman')
    s.paragraph_format.space_before=Pt(before); s.paragraph_format.space_after=Pt(after); s.paragraph_format.keep_with_next=True
styles['Caption'].font.name='Times New Roman'; styles['Caption'].font.size=Pt(10); styles['Caption'].font.italic=True

def set_cell(cell, value, bold=False, shade=None):
    cell.text=''; cell.vertical_alignment=WD_CELL_VERTICAL_ALIGNMENT.CENTER
    p=cell.paragraphs[0]; p.paragraph_format.space_after=Pt(0); p.paragraph_format.line_spacing=1.0
    r=p.add_run(str(value)); r.font.name='Times New Roman'; r.font.size=Pt(9); r.bold=bold
    if shade:
        tcPr=cell._tc.get_or_add_tcPr(); shd=OxmlElement('w:shd'); shd.set(qn('w:fill'),shade); tcPr.append(shd)
    for m in ('top','start','bottom','end'):
        tcMar=cell._tc.get_or_add_tcPr().first_child_found_in('w:tcMar')
        if tcMar is None: tcMar=OxmlElement('w:tcMar'); cell._tc.get_or_add_tcPr().append(tcMar)
        node=OxmlElement(f'w:{m}'); node.set(qn('w:w'),'100'); node.set(qn('w:type'),'dxa'); tcMar.append(node)

def table(headers, rows, widths=None):
    t=doc.add_table(rows=1, cols=len(headers)); t.alignment=WD_TABLE_ALIGNMENT.CENTER; t.style='Table Grid'
    for i,h in enumerate(headers): set_cell(t.rows[0].cells[i],h,True,'1F4E79')
    for row_idx,row in enumerate(rows):
        cells=t.add_row().cells
        for i,v in enumerate(row): set_cell(cells[i],v,False,'EAF2F8' if row_idx%2 else None)
    if widths:
        for row in t.rows:
            for i,w in enumerate(widths): row.cells[i].width=Inches(w)
    return t

def p(text='', style=None, align=None, boldlead=None):
    par=doc.add_paragraph(style=style)
    if align is not None: par.alignment=align
    if boldlead and text.startswith(boldlead):
        par.add_run(boldlead).bold=True; par.add_run(text[len(boldlead):])
    else: par.add_run(text)
    return par

def heading(text, level=1): return doc.add_paragraph(text, style=f'Heading {level}')
def page(): doc.add_page_break()
def figure(path, caption, width=5.85):
    par=doc.add_paragraph(); par.alignment=WD_ALIGN_PARAGRAPH.CENTER; par.add_run().add_picture(str(path), width=Inches(width))
    cap=doc.add_paragraph(caption, style='Caption'); cap.alignment=WD_ALIGN_PARAGRAPH.CENTER

def field(paragraph, instruction):
    r=paragraph.add_run(); begin=OxmlElement('w:fldChar'); begin.set(qn('w:fldCharType'),'begin'); instr=OxmlElement('w:instrText'); instr.set(qn('xml:space'),'preserve'); instr.text=instruction; separate=OxmlElement('w:fldChar'); separate.set(qn('w:fldCharType'),'separate'); end=OxmlElement('w:fldChar'); end.set(qn('w:fldCharType'),'end'); r._r.extend([begin,instr,separate,end])

def chapter(title): page(); h=heading(title,1); h.alignment=WD_ALIGN_PARAGRAPH.CENTER

# Title and front matter
for line, sz, bold in [
    ('DEVELOPMENT OF A WEB-BASED QR CODE-ENABLED DOCUMENT TRACKING, ROUTING AND MANAGEMENT SYSTEM FOR THE LOCAL GOVERNMENT UNIT OF TUAO',14,True),
    ('A Thesis Documentation',12,False), ('Presented to the Faculty of the Graduate School',12,False), ('Cagayan State University Carig Campus',12,False), ('Tuguegarao City',12,False), ('',12,False), ('In Partial Fulfillment of the Requirements for the Degree',12,False), ('MASTER OF SCIENCE IN INFORMATION TECHNOLOGY',12,True), ('',12,False), ('Prepared by',12,False), ('[RESEARCHER NAME TO BE SUPPLIED]',12,True), ('2026',12,False)]:
    q=p(line,align=WD_ALIGN_PARAGRAPH.CENTER); q.paragraph_format.space_after=Pt(12); q.runs[0].font.size=Pt(sz); q.runs[0].bold=bold
page(); heading('APPROVAL SHEET',1).alignment=WD_ALIGN_PARAGRAPH.CENTER
p('This thesis documentation entitled Development of a Web-Based QR Code-Enabled Document Tracking, Routing and Management System for the Local Government Unit of Tuao is submitted for examination and approval. Names, signatures, institutional identifiers, and defense date must be completed by the researcher and the institution before submission.')
table(['Required approval item','To be supplied before defense'],[['Researcher','Name and signature'],['Adviser','Name, credentials, and signature'],['Panel members','Names, credentials, and signatures'],['Date and venue','Official defense record']], [2.2,4.0])
page(); heading('ACKNOWLEDGEMENT',1).alignment=WD_ALIGN_PARAGRAPH.CENTER
p('The researcher acknowledges the Local Government Unit of Tuao, the adviser, faculty members, participating personnel, family, and colleagues whose guidance and cooperation support the completion of this project documentation.')
page(); heading('DEDICATION',1).alignment=WD_ALIGN_PARAGRAPH.CENTER
p('This work is dedicated to public servants, records personnel, educators, family members, and future researchers who advocate accountable and responsive public service.',align=WD_ALIGN_PARAGRAPH.CENTER)
page(); heading('TABLE OF CONTENTS',1).alignment=WD_ALIGN_PARAGRAPH.CENTER
p('Front Matter',align=WD_ALIGN_PARAGRAPH.LEFT)
for item in ['Abstract', 'CHAPTER I. The Problem and Its Background', 'CHAPTER II. Review of Related Literature and Systems', 'CHAPTER III. Research Methodology', 'CHAPTER IV. System Design and Implementation', 'CHAPTER V. Testing, Findings, Conclusion, and Recommendations', 'References', 'Appendices', 'Curriculum Vitae']:
    entry=p(item,align=WD_ALIGN_PARAGRAPH.LEFT); entry.paragraph_format.left_indent=Inches(.35); entry.paragraph_format.first_line_indent=Inches(0); entry.paragraph_format.space_after=Pt(4)
p('The heading structure is retained so the institution may generate or refresh a page-numbered Word table of contents after final title-page and approval information are supplied.',align=WD_ALIGN_PARAGRAPH.LEFT)
page(); heading('LIST OF TABLES',1).alignment=WD_ALIGN_PARAGRAPH.CENTER
for x in ['Table 1. Functional requirements','Table 2. Non-functional requirements','Table 3. Role and office-scope authorization matrix','Table 4. Core database data dictionary','Table 5. API endpoint summary','Table 6. Verified test-case evidence','Table 7. Information still required before final defense submission']:
    p(x)
page(); heading('LIST OF FIGURES',1).alignment=WD_ALIGN_PARAGRAPH.CENTER
for x in ['Figure 1. System architecture','Figure 2. Controlled document workflow','Figure 3. Role permission and office scope model','Figure 4. Core entity relationship view','Figure 5. Primary use cases','Figure 6. Document routing sequence','Figure 7. Login page','Figure 8. Dashboard','Figure 9. Outgoing documents view','Figure 10. QR code management','Figure 11. Document inquiry view','Figure 12. User management view','Figure 13. Office master data view','Figure 14. Audit-log view','Figure 15. Accomplishment report view']:
    p(x)
page(); heading('ABSTRACT',1).alignment=WD_ALIGN_PARAGRAPH.CENTER
p('DocTrackTuao is a Laravel and Vue web application for internal document registration, QR-supported identification, routing, receipt, processing, controlled attachment handling, history, audit logging, and reporting for the Local Government Unit of Tuao. The implementation maintains server-side authorization and office custody rules: a permitted user may act only on a document held by the user\'s assigned office, and processing changes are blocked while a route remains pending. The application uses Laravel API routes, Sanctum authentication, Eloquent models, Vue pages and components, Vite, MySQL, and protected local file storage. This manuscript documents confirmed implementation behavior and identifies evidence that remains necessary for a formal defense, including institutional approvals, respondent information, completed evaluation instruments, and production deployment evidence. It does not report fabricated survey scores, performance metrics, or user-test results.')
keywords=p('Keywords: document tracking, QR code, routing, Laravel, Vue, role-based access control, local government unit'); keywords.paragraph_format.first_line_indent=Inches(0)

chapter('CHAPTER I. THE PROBLEM AND ITS BACKGROUND')
heading('1.1 Introduction',2)
p('Government offices require reliable records of document custody, processing activity, and routing history. A paper-oriented process can make it difficult to determine which office currently holds a document, whether it has been received, and what action has been recorded. DocTrackTuao addresses this information-management need through an internal web application that links physical documents to system records through issued QR codes while preserving workflow history.')
p('The system is designed as a local-government document workflow tool. Its confirmed functions are document registration, QR issuance and registration verification, incoming and outgoing views, forwarding and receipt, current-action and processing-note updates, attachment controls, audit logs, dashboards, accomplishment reports, and role-aware navigation. The implementation aligns its handling of personal information with the need for appropriate safeguards recognized in the Data Privacy Act of 2012 (Republic Act No. 10173, 2012).')
heading('1.2 Conceptual Framework',2)
p('The study uses an Input-Process-Output framework. Inputs are authorized users, office assignments, document metadata, an issued QR code, and active lookup values. The process applies authentication, authorization, validation, custody and pending-route checks, routing, receipt, processing, audit logging, and reporting. Outputs are a current document state, controlled workflow history, QR-linked identification, and reports available within the assigned access scope.')
figure(workflow,'Figure 1. Controlled document workflow in DocTrackTuao.')
heading('1.3 Objectives of the Study',2); heading('1.3.1 General Objective',3)
p('To document and evaluate the implementation of a web-based QR code-enabled document tracking, routing, and management system for the Local Government Unit of Tuao.')
heading('1.3.2 Specific Objectives',3)
for x in ['Document the implemented registration, QR, routing, receipt, processing, attachment, history, audit, and reporting functions.', 'Document role permission and office-scope controls as implemented by Laravel routes, controllers, and models.', 'Present the confirmed system architecture, data model, API surface, and test evidence.', 'Identify the remaining institutional and evaluation evidence needed for formal defense completion.']:
    p(x,style='List Bullet')
heading('1.4 Scope and Limitations',2)
p('The documented scope is the internal DocTrackTuao application contained in the repository. It includes authenticated account functions, internal document workflow, QR code issuance and resolution, role permissions, office checks, attachment storage, audit logging, reports, and the Vue interface. The system does not by itself prove public internet accessibility, production HTTPS configuration, completed user evaluation, organization-wide deployment, or quantified operational improvement. Those claims require separate evidence.')
heading('1.5 Significance of the Study',2)
p('For administrators, the system provides controlled reports and audit information. For records and office personnel, it provides a structured view of document custody and permitted next actions. For viewers, it provides read-only access within the established permissions. For the LGU, it provides a maintainable implementation record that can guide pilot testing, deployment, and future enhancement. For future researchers, it provides a repository-grounded case of QR-assisted internal document workflow design.')
heading('1.6 Definition of Terms',2)
table(['Term','Operational meaning in this study'],[['Document custody','The office recorded as the document\'s current office.'],['Pending route','A document route that has not been received and has not been cancelled or rejected.'],['Office scope','The server-side restriction requiring a user\'s office to match the document office for relevant mutations.'],['Processing action','An active selected action recorded with an optional processing note.'],['QR record','A system-issued QR code associated with registration/tracking behavior.'],['Terminal status','Completed or Archived; these statuses block further processing and attachment mutations.']],[1.6,4.6])

chapter('CHAPTER II. REVIEW OF RELATED LITERATURE AND SYSTEMS')
heading('2.1 Records Management and Digital Workflow',2)
p('Records-management practice emphasizes reliable management of records throughout their use and retention. ISO 15489-1 frames records management around concepts and principles that support trustworthy information handling (International Organization for Standardization, 2016). In this project, separate document, routing, processing, attachment, and audit records preserve different forms of accountability rather than replacing history with a single current value.')
heading('2.2 QR Code Identification',2)
p('QR identifiers can connect a physical item to a digital record without printing the full record content onto the physical label. In DocTrackTuao, QR records are generated, verified for registration, linked to a document, and resolved through defined routes. QR lifecycle behavior remains subject to backend validation and permissions; a UI control does not replace those server-side checks.')
heading('2.3 Web Application Architecture and Security',2)
p('Laravel separates request routing, authentication, middleware, validation, and Eloquent data access. Its authentication documentation describes guards and providers as core authentication mechanisms (Laravel, 2026). Vue supports component-based interfaces and the Composition API used in the project\'s single-file components (Vue.js, 2026). The system also follows the security principle that authorization must be enforced on the server, not only by hidden controls. The Data Privacy Act of 2012 establishes the Philippine policy context for securing personal information in information and communications systems (Republic Act No. 10173, 2012).')
heading('2.4 Synthesis',2)
p('The reviewed sources support an implementation that distinguishes identity, authorization, custody, workflow state, and retained history. DocTrackTuao applies that synthesis through a Laravel API guarded by Sanctum and permission middleware, a Vue presentation layer, and persistence models for documents, routes, processing logs, QR records, attachments, notifications, and audit logs.')

chapter('CHAPTER III. RESEARCH METHODOLOGY')
heading('3.1 Research Design',2)
p('This manuscript uses a developmental documentation approach. It examines the project repository as the implementation source of truth and documents confirmed routes, controllers, models, migrations, Vue pages, tests, and database relationships. It does not infer results from code inspection alone; test outcomes are separately identified as verified or not yet verified.')
heading('3.2 Development Approach',2)
p('The codebase shows iterative development across workflow, security, reporting, and interface modules. The documented approach consists of requirements interpretation, model and API design, Vue implementation, focused testing, and review. The project guide requires small closure targets, role and office-scope checks, route/history side-effect checks, and review of code changes before advancing.')
heading('3.3 System Architecture',2)
figure(arch,'Figure 2. System architecture confirmed from the Vue, Laravel, MySQL, and attachment-storage implementation.')
heading('3.4 Role and Office-Scope Design',2)
p('The User model maintains a role-to-permission map. The role answers what a user may do, while office assignment answers which currently held documents a user may act on. For processing, the current office must equal the user office and no pending route may exist. Relevant controllers return forbidden or conflict responses when these conditions are not met.')
figure(roles,'Figure 3. Role permission and office scope model.')
table(['Role','Confirmed capabilities','Important boundary'],[['Administrator','All defined permissions, user and master-data administration, reports and audit.','Document actions still follow controller workflow checks.'],['Records Officer','Document registration, workflow, QR request/view/manage, audit and reports as mapped.','Role permission does not remove office-custody checks.'],['Office User','Document view, process, route, attachment management, QR request, reports.','No user management; mutations are office-scoped.'],['Viewer','Document/attachment/master-data/report reading.','No create, route, receive, process, attachment mutation, or user management.']],[1.0,3.2,2.0])
heading('3.5 Requirements',2)
table(['ID','Functional requirement','Repository evidence'],[['FR-01','Register a document after QR verification and save its core metadata.','DocumentController and Documents.vue.'],['FR-02','Forward, receive, reject receipt, and undo pending forwarding subject to custody checks.','DocumentRoutingController and API routes.'],['FR-03','Record an active processing action and optional note only when held and not in transit.','DocumentProcessingController.'],['FR-04','Upload, list, download, and delete allowed attachment types under access rules.','DocumentAttachmentController.'],['FR-05','Show history, audit logs, dashboard, and accomplishment reporting in permitted scope.','Routing, audit, and dashboard controllers.']],[.55,3.1,2.55])
table(['ID','Non-functional requirement','Implementation basis'],[['NFR-01','Security','Sanctum authentication, permissions, validation, 403/409 enforcement, protected local storage.'],['NFR-02','Traceability','Routes, processing logs, QR associations, and audit logs retain workflow events.'],['NFR-03','Usability','Vue pages, QR input/scanner components, role-aware navigation, and confirmation dialogs.'],['NFR-04','Maintainability','Laravel controllers/models/migrations; Vue pages/components; focused tests.'],['NFR-05','Reliability','Database transactions/locks are used in custody-sensitive lifecycle paths.']],[.55,2.2,3.45])
heading('3.6 Data Model',2)
figure(erd,'Figure 4. Core entity relationship view based on confirmed models and migrations.')
table(['Table','Key confirmed purpose','Selected relationships/fields'],[['users','Authenticated accounts with role and office assignment.','role_id, office_id, department_id, must_change_password, deactivated_at'],['documents','Core tracking record and current state.','tracking_no; type/status/priority; origin_office_id; current_office_id; completion/archive fields'],['document_routes','Forwarding, receipt, cancellation, and rejection history.','document_id; from_office_id; to_office_id; forwarded_at; received_at'],['document_qr_codes','Issued/linked QR records and lifecycle data.','document_id and QR lifecycle fields'],['document_processing_logs','Retained processing/action events.','document_id; office_id; user_id; processing_action_id; event_type'],['document_attachments','Protected attachment metadata.','document_id; stored file information; uploaded_by'],['audit_logs','Safe activity logging.','user_id; module; action; record_id; description; IP/user agent']],[1.2,2.2,2.8])
heading('3.7 Use Cases and Routing Sequence',2)
figure(usecase,'Figure 5. Primary actors and use cases.')
figure(sequence,'Figure 6. Document routing sequence.')

chapter('CHAPTER IV. SYSTEM DESIGN AND IMPLEMENTATION')
heading('4.1 Application Modules',2)
p('The Vue application includes dashboard, document registration/list/detail, incoming and outgoing views, document inquiry, QR code management, notifications, offices, user administration, audit logs, accomplishment reporting, profile functions, and report signatories. The Laravel API supplies protected routes for these modules and uses middleware to enforce authentication and permissions.')
heading('4.2 Workflow Controls',2)
p('A forward action creates a route and leaves the document pending or in transit until receipt. The destination office must receive the pending route before it can update processing. The processing controller validates a selected active action, optional note length, office assignment, current custody, terminal state, and lack of a pending route. Completion requires current custody and no pending route. Archiving requires the document to be completed, currently held by the acting office, and free of a pending route.')
heading('4.3 Attachment Handling',2)
p('Attachment uploads are restricted to the current office, blocked for terminal documents, and limited to PDF, Word, Excel, JPEG, and PNG files with a maximum request size of 10,240 KB. Files are placed on the local storage disk rather than the public web directory. Attachment list/download access is restricted to the current or origin office under the current controller rules.')
page()
heading('4.4 API Endpoint Summary',2)
table(['Area','Selected endpoints','Access control'],[['Authentication','POST /api/login; POST /api/logout; GET /api/user','Login public; logout/user authenticated.'],['Documents','GET/POST /api/documents; GET /api/documents/{id}','documents.view/create/edit/delete permissions.'],['Routing','POST forward, receive, reject-receipt, undo-forward; GET history/options','documents.route/view plus controller office checks.'],['Processing','GET/PUT /api/documents/{id}/processing','documents.view/process plus custody and pending-route checks.'],['QR','GET/POST /api/qr-codes; verify-registration; resolve /api/q/{token}','Permission-specific protected functions; public resolution is throttled.'],['Attachments','GET/POST document attachments; download/delete attachment','attachments.view/manage plus office rules.'],['Administration','users, offices, document-types, audit-logs, reports','Permission middleware controls each route.']],[1.15,3.55,1.5])
heading('4.5 Security Design',2)
p('The implementation uses Bearer-token authentication through Sanctum, middleware permission checks, server-side validation, controlled error statuses, database transactions for related workflow changes, and audit logging. Sensitive file paths are not returned by ordinary attachment list responses. The code also contains rate-limited public tracking and QR-resolution routes; before any internet-facing deployment, their data-exposure policy, HTTPS configuration, trusted hosts, and operational monitoring require an explicit security review.')
page(); heading('4.6 User Interface Screens',2)
p('The following screenshots were captured from the local development system. Account labels and record-level areas were blurred where necessary. They demonstrate the implemented interface modules without disclosing passwords, bearer tokens, QR tokens, or document contents.')
figure(screens['login'],'Figure 7. Login page with credential fields empty.', width=4.9)
for number, key, caption in [
    (8, 'dashboard', 'Dashboard with scope, date filter, status distribution, and movement summaries.'),
    (9, 'documents', 'Outgoing documents view with a registration entry point and search field.'),
    (10, 'qr', 'QR code management screen; request and inventory record areas are redacted.'),
    (11, 'inquiry', 'Document inquiry screen; document-record area is redacted.'),
    (12, 'users', 'User-management screen; account record area is redacted.'),
    (13, 'offices', 'Office and master-data screen.'),
    (14, 'audit', 'Audit-log screen; activity record area is redacted.'),
    (15, 'report', 'Accomplishment-report screen; report record area is redacted.'),
]:
    # The audit capture is portrait-oriented; keep its caption with the image.
    page(); figure(screens[key], f'Figure {number}. {caption}', width=4.0 if key == 'audit' else 4.9)

chapter('CHAPTER V. TESTING, FINDINGS, CONCLUSION, AND RECOMMENDATIONS')
heading('5.1 Testing Approach',2)
p('The project guide requires focused automated checks, a positive path, a relevant forbidden or invalid path, inspection of history/audit side effects, and review of the working-tree diff. The following table contains only outcomes actually verified during the documented development session. It is not a substitute for the required end-to-end role and office testing program.')
table(['Test or check','Result','Evidence/status'],[['Dashboard summary feature test','Passed','18 tests and 174 assertions reported in the focused Laravel test run on 10 October 2026.'],['QR registration frontend test','Passed','3 tests passed after Enter-key form-submission handling adjustment.'],['Document lifecycle frontend test','Passed','15 tests passed for complete/archive behavior and related source checks.'],['Production frontend build','Passed','Vite production build completed after the most recent UI changes.'],['Complete end-to-end role matrix','Not yet verified','Do not claim complete until all roles and wrong-office paths are exercised.'],['ISO/IEC 25010 respondent evaluation','Not yet verified','No completed questionnaires or validated dataset supplied.'],['Production/LAN security deployment','Not yet verified','Requires HTTPS, host, backup, and deployment evidence.']],[1.8,1.1,3.3])
heading('5.2 Findings',2)
p('Repository inspection confirms an implemented internal document workflow with QR association, routing history, receipt, controlled processing, protected attachments, audit support, reports, and role/office controls. The strongest implementation constraint is the combination of role permission and office custody. Code inspection also confirms that front-end visibility is not the sole authorization mechanism; protected routes and controller checks govern mutations.')
heading('5.3 Conclusion',2)
p('DocTrackTuao provides a documented technical foundation for controlled internal document tracking in the Local Government Unit of Tuao. Its implementation separates the current document state from retained routing and processing history and applies server-side permission and office-custody rules to workflow mutations. The project is suitable for a defense discussion of its implemented architecture and workflow, provided that evaluation claims remain limited to verified evidence.')
heading('5.4 Recommendations',2)
for x in ['Complete the full register-to-archive end-to-end test across Administrator, Records Officer, Office User, and Viewer accounts, including wrong-office and pending-route denial cases.', 'Collect institutional approval, respondent profile, completed ISO/IEC 25010 instruments, and an auditable analysis dataset before reporting evaluation scores.', 'Prepare a fresh database backup before any real-user pilot, then verify restore procedures and access controls.', 'Before LAN or internet deployment, complete the focused security hardening and deployment checklist, including trusted hosts, HTTPS where applicable, authentication lifecycle, storage protections, and server backup procedures.', 'Capture final screenshots only in a controlled environment with anonymized information.']:
    p(x,style='List Bullet')

chapter('REFERENCES')
refs=[
'International Organization for Standardization. (2016). ISO 15489-1:2016 information and documentation: Records management: Part 1: Concepts and principles.',
'International Organization for Standardization. (2023). ISO/IEC 25010:2023 systems and software engineering: Systems and software quality requirements and evaluation: Product quality model.',
'Laravel. (2026). Authentication. https://laravel.com/docs/12.x/authentication',
'National Institute of Standards and Technology. (2020). Digital identity guidelines: Authentication and lifecycle management (Special Publication 800-63B). https://doi.org/10.6028/NIST.SP.800-63b',
'OWASP Foundation. (n.d.). File upload cheat sheet. https://cheatsheetseries.owasp.org/cheatsheets/File_Upload_Cheat_Sheet.html',
'Republic Act No. 10173. (2012). Data Privacy Act of 2012. Official Gazette of the Republic of the Philippines. https://officialgazette.gov.ph/2012/08/15/republic-act-no-10173/',
'Republic Act No. 11032. (2018). Ease of Doing Business and Efficient Government Service Delivery Act of 2018. Official Gazette of the Republic of the Philippines. https://officialgazette.gov.ph/2018/05/28/republic-act-no-11032/',
'Vue.js. (2026). Introduction and Composition API. https://vuejs.org/guide/introduction.html',
]
for x in refs: p(x)

chapter('APPENDICES')
heading('Appendix A. Repository Evidence Reviewed',2)
table(['Evidence group','Examples used in this documentation'],[['Project guidance','AGENTS.md workflow, role, office-scope, backup, testing, and security rules.'],['Backend','routes/api.php; document, routing, lifecycle, processing, attachment, dashboard, audit, and QR controllers.'],['Models and migrations','Document, DocumentRoute, User, attachment, processing, QR, audit models and migration inventory.'],['Frontend','Vue pages/components for documents, details, tracking, QR, dashboard, reports, navigation, and authentication.'],['Tests and API reference','Focused frontend tests, Laravel tests, and api-tests/API-ENDPOINTS.md.']],[1.5,4.7])
heading('Appendix B. Information Required Before Institutional Submission',2)
table(['Required item','Why it is needed'],[['Researcher, adviser, panel, signatures, defense date','Required front-matter and approval-sheet information.'],['Approved research title and degree wording','Institution-specific title-page compliance.'],['Respondent count, profiles, consent/approval, and completed instruments','Required for any survey/evaluation claim.'],['Validated calculation sheet for ISO/IEC 25010 results','Required before weighted means or verbal interpretations.'],['Pilot/deployment evidence and backup/restore record','Required before claiming operational rollout or production readiness.'],['Anonymized final screenshots','Optional visual evidence for the final defense manuscript.']],[2.4,3.8])
heading('Appendix C. Selected Workflow Rules',2)
p('Processing updates require a valid assigned office, current custody by that office, an active action, and no pending route. Forwarded documents remain pending until received. Completion requires the acting office to hold the document and no pending route. Archiving requires the document to be completed, held by the acting office, and free of a pending route. Completed and archived documents cannot receive processing or attachment mutations.')
heading('Appendix D. ISO/IEC 25010 Evaluation Instrument Status',2)
p('An evaluation instrument may be attached after it is approved by the institution. No completed respondent responses, weighted means, rankings, or compliance scores are included in this manuscript because they were not supplied for verification.')
heading('Appendix E. Source Code Areas',2)
table(['Area','Repository location','Purpose'],[['API routes','routes/api.php','Defines public and protected endpoints plus permission middleware.'],['Role permissions','app/Models/User.php','Defines the application role-to-permission map.'],['Processing enforcement','app/Http/Controllers/DocumentProcessingController.php','Enforces office custody and no-pending-route processing rules.'],['Lifecycle enforcement','app/Http/Controllers/DocumentLifecycleController.php','Enforces completion/archive state transitions.'],['Routing enforcement','app/Http/Controllers/DocumentRoutingController.php','Handles forward, receive, receipt rejection, and undo behavior.'],['Document interface','resources/js/pages/Documents.vue and DocumentDetails.vue','Supports registration and controlled document actions.']],[1.55,2.9,1.75])
heading('CURRICULUM VITAE',1).alignment=WD_ALIGN_PARAGRAPH.CENTER
p('To be completed by the researcher using the institution\'s required curriculum vitae format. Do not include unnecessary personal identifiers in a publicly distributed copy.')

# footer and settings update fields
for s in doc.sections:
    footer=s.footer.paragraphs[0]; footer.alignment=WD_ALIGN_PARAGRAPH.CENTER; footer.add_run('DocTrackTuao System Documentation | Page '); field(footer,'PAGE')
settings=doc.settings.element
upd=OxmlElement('w:updateFields'); upd.set(qn('w:val'),'true'); settings.append(upd)
doc.core_properties.title='DocTrackTuao Defense Ready Thesis Documentation'; doc.core_properties.author='[Researcher Name To Be Supplied]'
doc.save(OUT)
print(OUT)
