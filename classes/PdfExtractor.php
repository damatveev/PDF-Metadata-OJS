<?php
namespace APP\plugins\generic\pdfMetadata\classes;

use PKP\config\Config;
use Symfony\Component\Process\Process;

/** Local PDF extraction via Poppler, with Ghostscript txtwrite fallback. */
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
            if ($infoData['backend'] === 'poppler') {
                try { $xmp = $this->runTool('pdfinfo', ['-meta', $path]); }
                catch (Failure $e) { if ($e->reason === 'limit') { throw $e; } $warnings[] = 'partial'; }
            } else {
                // Ghostscript txtwrite does not expose the same PDF Info/XMP fields.
                $warnings[] = 'partial';
            }
            $text = '';
            try {
                $text = $this->extractText($path, 1, min($infoData['pages'], self::MAX_PAGES), $infoData['backend']);
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
            $text = $this->extractText($path, $startPage, $endPage, $info['backend']);
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

        $backend = $this->backend();
        if ($backend === 'poppler') {
            $info = $this->runTool('pdfinfo', ['-enc', 'UTF-8', $path]);
            if (preg_match('/^Encrypted:\s+yes/im', $info)) { throw new Failure('invalidPdf'); }
            $pages = preg_match('/^Pages:\s+(\d+)/m', $info, $m) ? (int) $m[1] : 0;
        } else {
            $info = '';
            $pagesOutput = $this->runTool('gs', [
                '-q', '-dSAFER', '-dNODISPLAY', '-sInputFile=' . $path,
                '-c', 'InputFile (r) file runpdfbegin pdfpagecount = quit',
            ]);
            $pages = preg_match('/^\s*(\d+)\s*$/m', $pagesOutput, $m) ? (int) $m[1] : 0;
        }

        if ($pages < 1 || $pages > 5000) { throw new Failure('invalidPdf'); }
        return ['pages' => $pages, 'raw' => $info, 'backend' => $backend];
    }

    private function extractText(string $path, int $firstPage, int $lastPage, string $backend): string
    {
        if ($backend === 'poppler') {
            return $this->runTool('pdftotext', [
                '-f', (string) $firstPage,
                '-l', (string) $lastPage,
                '-enc', 'UTF-8', '-layout', '-nopgbrk', $path, '-'
            ]);
        }

        return $this->runTool('gs', [
            '-q', '-dSAFER', '-dBATCH', '-dNOPAUSE',
            '-sDEVICE=txtwrite',
            '-dFirstPage=' . $firstPage,
            '-dLastPage=' . $lastPage,
            '-sOutputFile=-',
            $path,
        ]);
    }

    private function backend(): string
    {
        if ($this->toolAvailable('pdfinfo') && $this->toolAvailable('pdftotext')) {
            return 'poppler';
        }
        if ($this->toolAvailable('gs')) {
            return 'ghostscript';
        }
        throw new Failure('unavailable', 503);
    }

    private function toolAvailable(string $tool): bool
    {
        $binary = $this->toolPath($tool);
        return str_starts_with($binary, '/') && is_executable($binary);
    }

    private function toolPath(string $tool): string
    {
        $defaults = [
            'pdfinfo' => '/usr/bin/pdfinfo',
            'pdftotext' => '/usr/bin/pdftotext',
            'gs' => '/usr/bin/gs',
        ];
        return (string) Config::getVar('pdf_metadata', $tool, $defaults[$tool] ?? ('/usr/bin/' . $tool));
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

    private function runTool(string $tool, array $args): string
    {
        $binary = $this->toolPath($tool);
        $limiter = (string) Config::getVar('pdf_metadata', 'prlimit', '/usr/bin/prlimit');
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
