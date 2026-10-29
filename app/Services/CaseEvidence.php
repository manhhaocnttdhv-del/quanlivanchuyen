<?php

namespace App\Services;

use App\Models\Attachment;
use App\Models\ComplaintMessage;
use App\Models\IncidentAction;
use App\Models\User;
use App\Support\CaseRules;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Throwable;

class CaseEvidence
{
    public function run(?UploadedFile $file, callable $action): mixed
    {
        Validator::make(['evidence' => $file], ['evidence' => CaseRules::evidence()])->validate();
        $data = null;
        if ($file) {
            $path = $file->store('case-evidence', 'local');
            throw_unless(is_string($path) && $path !== '', new \RuntimeException('Không lưu được bằng chứng.'));
            $data = ['path' => $path, 'original_name' => mb_substr(basename($file->getClientOriginalName()), 0, 180), 'mime' => $file->getMimeType(),
                'size' => $file->getSize(), 'content_sha256' => hash_file('sha256', $file->getRealPath())];
        }
        try {
            $result = $action($data);
        } catch (Throwable $exception) {
            if ($data) {
                Storage::disk('local')->delete($data['path']);
            }
            throw $exception;
        }
        // Sau commit không xóa file đã lưu nếu một truy vấn kiểm tra storage/DB gặp lỗi.
        if ($data && ! Attachment::where('path', $data['path'])->exists()) {
            Storage::disk('local')->delete($data['path']);
        }

        return $result;
    }

    public function attach(User $actor, IncidentAction|ComplaintMessage $parent, ?array $data): void
    {
        if ($data) {
            $parent->attachments()->create($data + ['created_by' => $actor->id]);
        }
    }
}
