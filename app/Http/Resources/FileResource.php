<?php

namespace App\Http\Resources;

use App\Models\CourseFile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CourseFile */
class FileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'courseId' => (string) $this->offering_id,
            'classId' => $this->class_id ? (string) $this->class_id : null,
            'classCode' => $this->courseClass?->code,
            'sessionId' => $this->session_id ? (string) $this->session_id : null,
            'title' => $this->title,
            'fileName' => $this->original_name,
            'kind' => $this->kind->value,
            'sizeBytes' => $this->size_bytes,
            'sizeKb' => (int) max(1, round($this->size_bytes / 1024)),
            'uploadedAt' => $this->created_at->toIso8601String(),
            'visible' => $this->visible,
            'downloads' => $this->download_count,
            'downloadUrl' => url("/api/v1/files/{$this->id}/download"),
            // Only the uploader may hide, rename or delete a file.
            'canEdit' => $this->uploaded_by === $request->user()?->id,
        ];
    }
}
