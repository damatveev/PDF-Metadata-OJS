<?php
namespace APP\plugins\generic\pdfMetadata\classes;

use PKP\config\Config;
use Symfony\Component\Process\Process;

/** Poppler runs locally, with argv (no shell), bounded time/output/address space. */
class PdfExtractor
{
    public const MAX_BYTES = 20971520;
    public const MAX_PAGES = 50;
    public const MAX_ARTICLE_PAGES = 80;
    private const MAX_OUTPUT = 4194304;

    /** Existing submission-PDF extraction retained for backwards compatibility. */
    public function extract(string $path): array
    {
        if (PHP_OS_FAMILY !== 'Linux') { throw new Failure('unavailable', 503); }
        return $this->withLock(function () use ($path) {
            $infoData = $this->inspectLocked($path, self::MAX_BYTES);
            $info = $infoData['raw'];
            $warnings = [];
            if ($infoData['pages'] > self::MAX_PAGES) { $warnings[] = 'partial'; }
            $xmp = '';
            try { $xmp = $this->run('pdfinfo', ['-meta', $path]); }
            catch (Failure $e) { if ($e->reason === 'limit') { throw $e; } $warnings[] = 'partial'; }
            $text = '';
            try {
                $text = $this->run('pdftotext', ['-f', '1', '-l', (string) self::MAX_PAGES, '-enc', 'UTF-8', '-layout', '-nopgbrk', $path, '-']);
            } catch (Failure $e) { if ($e->reason === 'limit') { throw $e; } $warnings[] = 'partial'; }
            $result = (new MetadataParser())->parse($text, $info, $xmp);
            $result['warnings'] = array_values(array_unique(array_merge($result['warnings'], $warnings)));
            $result['pages'] = $infoData['pages'];
            return $result;
        });
    }

    /** Validate a complete issue PDF and return only safe document facts. */
    public function inspectDocument(string $path, int $maxBytes = IssueWorkspace::MAX_ISSUE_BYTES): array
    {
        if (PHP_OS_FAMILY !== 'Linux') { throw new Failure('unavailable', 503); }
        return $this->withLock(function () use ($path, $maxBytes) {
            $info = $this->inspectLocked($path, $maxBytes);
            return ['pages' => $info['pages']];
        });
    }

    /** Extract only a manually selected article page range from a complete issue PDF. */
    public function extractRange(string $path, int $startPage, int $endPage, int $maxBytes = IssueWorkspace::MAX_ISSUE_BYTES): array
    {
        if (PHP_OS_FAMILY !== 'Linux') { throw new Failure('unavailable', 503); }
        return $this->withLock(function () use ($path, $startPage, $endPage, $maxBytes) {
            $info = $this->inspectLocked($path, $maxBytes);
            $pages = $info['pages'];
            if ($startPage < 1 || $endPage < $startPage || $endPage > $pages || ($endPage - $startPage + 1) > self::MAX_ARTICLE_PAGES) {
                throw new Failure('validation');
            }
            $text = $this->run('pdftotext', [
                '-f', (string) $startPage,
                '-l', (string) $endPage,
                '-enc', 'UTF-8', '-layout', '-nopgbrk', $path, '-'
            ]);
            // Whole-issue XMP/PDF Info usually describes the issue, not the article; do not use it here.
            $result = (new MetadataParser())->parse($text, '', '');
            $result['range'] = ['startPage' => $startPage, 'endPage' => $endPage, 'documentPages' => $pages];
            return $result;
        });
    }

    private function inspectLocked(string $path, int $maxBytes): array
    {
        if (!is_file($path) || filesize($path) > $maxBytes || file_get_contents($path, false, null, 0, 5) !== '%PDF-') {
            throw new Failure('invalidPdf');
        }
        $info = $this->run('pdfinfo', ['-enc', 'UTF-8', $path]);
        if (preg_match('/^Encrypted:\s+yes/im', $info)) { throw new Failure('invalidPdf'); }
        $pages = preg_match('/^Pages:\s+(\d+)/m', $info, $m) ? (int) $m[1] : 0;
        if ($pages < 1 || $pages > 5000) { throw new Failure('invalidPdf'); }
        return ['pages' => $pages, 'raw' => $info];
    }

    private function withLock(callable $callback): mixed
    {
        $root = SubmissionPdf::tempRoot();
        $lock = fopen($root . '/extract.lock', 'c');
        if (!$lock) { throw new Failure('configuration', 503); }
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) { throw new Failure('busy', 429); }
            return $callback();
        } finally {
            fclose($lock);
        }
    }

    private function run(string $tool, array $args): string
    {
        $binary = Config::getVar('pdf_metadata', $tool, '/usr/bin/' . $tool);
        $limiter = Config::getVar('pdf_metadata', 'prlimit', '/usr/bin/prlimit');
        if (PHP_OS_FAMILY !== 'Linux' || !is_executable($binary) || !is_executable($limiter) || !str_starts_with($binary, '/') || !str_starts_with($limiter, '/')) {
            throw new Failure('unavailable', 503);
        }
        $process = new Process([$limiter, '--as=536870912', '--cpu=15', '--fsize=4194304', '--nofile=64', '--', $binary, ...$args], null, ['LC_ALL' => 'C']);
        $process->setTimeout(25);
        $output = '';
        $bytes = 0;
        try {
            $process->run(function ($type, $buffer) use ($process, &$output, &$bytes) {
                $bytes += strlen($buffer);
                if ($bytes > self::MAX_OUTPUT) { throw new Failure('limit'); }
                if ($type === Process::OUT) { $output .= $buffer; }
                $process->clearOutput();
                $process->clearErrorOutput();
            });
            if (!$process->isSuccessful()) { throw new Failure('invalidPdf'); }
        } catch (\Symfony\Component\Process\Exception\ProcessTimedOutException) {
            throw new Failure('limit');
        } finally {
            if ($process->isRunning()) { $process->stop(0); }
        }
        return $output;
    }
}
