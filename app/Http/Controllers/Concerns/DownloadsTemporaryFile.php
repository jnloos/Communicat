<?php

namespace App\Http\Controllers\Concerns;

use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * Builds a file in the temp directory and sends it as a download that is
 * deleted after sending; a failed build leaves no file behind.
 */
trait DownloadsTemporaryFile
{
    /** @param  callable(string): void  $write  writes the file to the given path */
    private function downloadTemporary(callable $write, string $filename, string $contentType = 'application/zip'): BinaryFileResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'download') ?: throw new RuntimeException('Could not create a temporary file for the download.');

        try {
            $write($path);
        } catch (Throwable $exception) {
            @unlink($path);

            throw $exception;
        }

        return response()->download($path, $filename, ['Content-Type' => $contentType])->deleteFileAfterSend();
    }
}
