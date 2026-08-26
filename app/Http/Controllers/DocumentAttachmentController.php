<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Document;
use App\Models\DocumentAttachment;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class DocumentAttachmentController extends Controller
{
    /**
     * List attachments for a document.
     */
    public function index(Request $request, $documentId)
    {
        $document = Document::findOrFail($documentId);
        $user = $request->user();

        if (!$user->office_id) {
            return response()->json([
                'message' => 'Your user account is not assigned to an office.',
            ], 403);
        }

        $allowedOfficeIds = array_filter([
            $document->current_office_id,
            $document->origin_office_id,
        ]);

        if (
            !in_array(
                (int) $user->office_id,
                array_map('intval', $allowedOfficeIds),
                true
            )
        ) {
            return response()->json([
                'message' =>
                    'You are not authorized to access attachments for this document.',
            ], 403);
        }

        $attachments = DocumentAttachment::with('uploadedBy')
            ->where('document_id', $document->id)
            ->latest()
            ->get([
                'id',
                'document_id',
                'original_filename',
                'mime_type',
                'file_size',
                'uploaded_by',
                'created_at',
                'updated_at',
            ]);

        return response()->json($attachments);
    }

    /**
     * Upload a new attachment.
     */
    public function store(
        Request $request,
        AuditLogger $auditLogger,
        $documentId
    )
    {
        $document = Document::findOrFail($documentId);
        $user = $request->user();

        if (!$user->office_id) {
            return response()->json([
                'message' => 'Your user account is not assigned to an office.',
            ], 403);
        }

        /*
        |--------------------------------------------------------------------------
        | Only users from the current office may upload attachments.
        |--------------------------------------------------------------------------
        */

        if (
            (int) $user->office_id !==
            (int) $document->current_office_id
        ) {
            return response()->json([
                'message' =>
                    'You cannot upload attachments because this document is not currently assigned to your office.',
            ], 403);
        }

        $validated = $request->validate([
            'file' => [
                'required',
                'file',
                'max:10240',
                'mimes:pdf,doc,docx,xls,xlsx,jpg,jpeg,png',
            ],
        ]);

        $file = $validated['file'];

        /*
        |--------------------------------------------------------------------------
        | Store securely outside the public directory.
        |--------------------------------------------------------------------------
        */

        $extension =
            strtolower(
                $file->getClientOriginalExtension()
            );

        $storedFilename =
            Str::uuid()->toString() .
            ($extension ? '.' . $extension : '');

        $directory =
            'document_attachments/' . $document->id;

        try {
            $path = Storage::disk('local')
                ->putFileAs(
                    $directory,
                    $file,
                    $storedFilename
                );
        } catch (Throwable) {
            return response()->json([
                'message' => 'Attachment could not be stored.',
            ], 500);
        }

        if ($path === false) {
            return response()->json([
                'message' => 'Attachment could not be stored.',
            ], 500);
        }

        try {
            $attachment = DocumentAttachment::create([
                'document_id' =>
                    $document->id,

                'original_filename' =>
                    $file->getClientOriginalName(),

                'stored_filename' =>
                    $storedFilename,

                'file_path' =>
                    $path,

                'mime_type' =>
                    $file->getMimeType(),

                'file_size' =>
                    $file->getSize(),

                'uploaded_by' =>
                    $user->id,
            ]);
        } catch (Throwable) {
            try {
                Storage::disk('local')->delete($path);
            } catch (Throwable) {
                // The primary response must not expose storage details.
            }

            return response()->json([
                'message' => 'Attachment could not be stored.',
            ], 500);
        }

        $attachment->load('uploadedBy');

        $auditLogger->log(
            module: AuditLog::MODULE_ATTACHMENTS,
            action: AuditLog::ACTION_UPLOADED,
            recordId: $attachment->id,
            description: sprintf(
                'Attachment uploaded for document ID %d; MIME: %s; bytes: %d.',
                $document->id,
                $attachment->mime_type ?? 'unknown',
                $attachment->file_size ?? 0
            ),
            userId: $user->id
        );

        return response()->json([
            'message' =>
                'Attachment uploaded successfully.',

            'attachment' =>
                $attachment,
        ], 201);
    }

    /**
     * Download an attachment.
     */
    public function download(Request $request, $attachmentId)
    {
        $attachment =
            DocumentAttachment::with('document')
                ->findOrFail($attachmentId);

        $user = $request->user();

        if (!$user->office_id) {
            return response()->json([
                'message' => 'Your user account is not assigned to an office.',
            ], 403);
        }

        /*
        |--------------------------------------------------------------------------
        | Current sprint access rule:
        | Current office or origin office may access the attachment.
        |--------------------------------------------------------------------------
        */

        $allowedOfficeIds = array_filter([
            $attachment->document->current_office_id,
            $attachment->document->origin_office_id,
        ]);

        if (
            !in_array(
                (int) $user->office_id,
                array_map('intval', $allowedOfficeIds),
                true
            )
        ) {
            return response()->json([
                'message' =>
                    'You are not authorized to access this attachment.',
            ], 403);
        }

        if (
            !Storage::disk('local')
                ->exists($attachment->file_path)
        ) {
            return response()->json([
                'message' =>
                    'Attachment file could not be found.',
            ], 404);
        }

        return Storage::disk('local')
            ->download(
                $attachment->file_path,
                $attachment->original_filename
            );
    }

    /**
     * Delete an attachment.
     */
    public function destroy(
        Request $request,
        AuditLogger $auditLogger,
        $attachmentId
    )
    {
        $attachment =
            DocumentAttachment::with('document')
                ->findOrFail($attachmentId);

        $user = $request->user();

        if (!$user->office_id) {
            return response()->json([
                'message' => 'Your user account is not assigned to an office.',
            ], 403);
        }

        /*
        |--------------------------------------------------------------------------
        | Only current office may delete attachments.
        |--------------------------------------------------------------------------
        */

        if (
            (int) $user->office_id !==
            (int) $attachment->document->current_office_id
        ) {
            return response()->json([
                'message' =>
                    'You cannot delete this attachment because the document is not currently assigned to your office.',
            ], 403);
        }

        try {
            $disk = Storage::disk('local');

            if (
                $disk->exists($attachment->file_path) &&
                !$disk->delete($attachment->file_path)
            ) {
                return response()->json([
                    'message' => 'Attachment could not be deleted.',
                ], 500);
            }
        } catch (Throwable) {
            return response()->json([
                'message' => 'Attachment could not be deleted.',
            ], 500);
        }

        $deletedAttachmentId = $attachment->id;
        $documentId = $attachment->document_id;

        $attachment->delete();

        $auditLogger->log(
            module: AuditLog::MODULE_ATTACHMENTS,
            action: AuditLog::ACTION_DELETED,
            recordId: $deletedAttachmentId,
            description: "Attachment deleted from document ID {$documentId}.",
            userId: $user->id
        );

        return response()->json([
            'message' =>
                'Attachment deleted successfully.',
        ]);
    }
}
