<?php

namespace App\Services;

use App\Models\FinanceAttachment;
use App\Models\FinanceDocument;
use App\Models\User;
use App\Support\FinanceRules;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Throwable;

class FinanceEvidence
{
    public function run(?UploadedFile $file, bool $required, callable $action): mixed
    {
        Validator::make(['evidence' => $file], ['evidence' => FinanceRules::evidence($required)])->validate();
        $proof = null;
        if ($file) {
            $path = $file->store('finance-evidence', 'local');
            throw_unless(is_string($path) && $path !== '', new \RuntimeException('Không lưu được bằng chứng.'));
            $proof = ['path' => $path, 'original_name' => mb_substr(basename($file->getClientOriginalName()), 0, 180), 'mime' => $file->getMimeType(), 'size' => $file->getSize(), 'content_sha256' => hash_file('sha256', $file->getRealPath())];
        }
        try {
            $result = $action($proof);
        } catch (Throwable $e) {
            if ($proof) {
                Storage::disk('local')->delete($proof['path']);
            } throw $e;
        }
        if ($proof && ! FinanceAttachment::where('path', $proof['path'])->exists()) {
            Storage::disk('local')->delete($proof['path']);
        }

        return $result;
    }

    public function attach(User $actor, FinanceDocument $document, ?array $proof): void
    {
        if ($proof) {
            $document->attachments()->create($proof + ['created_by' => $actor->id]);
        }
    }
}
