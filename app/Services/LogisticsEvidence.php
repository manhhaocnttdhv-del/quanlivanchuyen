<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Throwable;

class LogisticsEvidence
{
    public function run(?UploadedFile $file, callable $action): mixed
    {
        $path = $file?->store('logistics-evidence', 'local');
        try {
            return $action($path);
        } catch (Throwable $exception) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            throw $exception;
        }
    }
}
