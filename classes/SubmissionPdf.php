<?php
namespace APP\plugins\generic\pdfMetadata\classes;

use APP\facades\Repo;
use PKP\config\Config;
use PKP\core\Core;
use PKP\submissionFile\SubmissionFile;

/** Accept only original submission PDFs, never review reports or client paths. */
class SubmissionPdf
{
    public static function tempRoot(): string
    {
        $configured = (string) Config::getVar('pdf_metadata', 'temp_dir', '');
        // realpath('') resolves to cwd in PHP: reject it before resolution.
        if ($configured === '' || $configured[0] !== '/') { throw new Failure('configuration', 503); }
        $root = realpath($configured);
        if (!$root || !is_dir($root) || !is_writable($root) || (fileperms($root) & 0077) !== 0) {
            throw new Failure('configuration', 503);
        }
        foreach ([Core::getBaseDir(), $_SERVER['DOCUMENT_ROOT'] ?? ''] as $public) {
            if ($public !== '' && ($public = realpath($public))) {
                if ($root === $public || str_starts_with($root, rtrim($public, '/') . '/')) { throw new Failure('configuration', 503); }
            }
        }
        return $root;
    }

    public function list(int $submissionId): array
    {
        $files = Repo::submissionFile()->getCollector()->filterBySubmissionIds([$submissionId])
            ->filterByFileStages([SubmissionFile::SUBMISSION_FILE_SUBMISSION])->getMany();
        $result = [];
        foreach ($files as $file) {
            $stored = app('file')->get($file->getData('fileId'));
            if ($stored && $stored->mimetype === 'application/pdf') {
                $result[] = ['id' => $file->getId(), 'name' => $file->getLocalizedData('name') ?: 'PDF #' . $file->getId()];
            }
        }
        return $result;
    }

    /** Stream via OJS storage abstraction; copy is removed in finally, including errors. */
    public function withCopy(int $submissionId, int $id, callable $callback): mixed
    {
        $file = Repo::submissionFile()->get($id);
        if (!$file || (int) $file->getData('submissionId') !== $submissionId || (int) $file->getData('fileStage') !== SubmissionFile::SUBMISSION_FILE_SUBMISSION) {
            throw new Failure('forbidden', 403);
        }
        $service = app('file');
        $stored = $service->get($file->getData('fileId'));
        if (!$stored || $stored->mimetype !== 'application/pdf') { throw new Failure('invalidPdf'); }
        if ($service->fs->fileSize($stored->path) > PdfExtractor::MAX_BYTES) { throw new Failure('limit'); }
        $root = self::tempRoot();
        $directory = $root . '/pdfmd-' . bin2hex(random_bytes(16));
        if (!mkdir($directory, 0700)) { throw new Failure('configuration', 503); }
        $path = $directory . '/input.pdf';
        $input = $output = null;
        try {
            $input = $service->fs->readStream($stored->path);
            $output = fopen($path, 'xb');
            if (!$input || !$output) { throw new Failure('invalidPdf'); }
            chmod($path, 0600);
            $bytes = stream_copy_to_stream($input, $output, PdfExtractor::MAX_BYTES + 1);
            fclose($output); $output = null;
            if (!$bytes || $bytes > PdfExtractor::MAX_BYTES) { throw new Failure('limit'); }
            return $callback($path);
        } finally {
            if (is_resource($input)) { fclose($input); }
            if (is_resource($output)) { fclose($output); }
            if (is_file($path)) { unlink($path); }
            rmdir($directory);
        }
    }
}
