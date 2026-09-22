<?php
namespace APP\plugins\generic\pdfMetadata\classes;

use PKP\config\Config;
use Symfony\Component\Process\Process;

/** Poppler runs locally, with argv (no shell), bounded time/output/address space. */
class PdfExtractor
{
    public const MAX_BYTES = 20971520;
    public const MAX_PAGES = 50;
    private const MAX_OUTPUT = 2097152;

    public function extract(string $path): array
    {
        if (PHP_OS_FAMILY !== 'Linux') { throw new Failure('unavailable', 503); }
        $root = SubmissionPdf::tempRoot();
        // One Poppler extraction at a time per shared temporary directory.
        $lock = fopen($root . '/extract.lock', 'c');
        if (!$lock) { throw new Failure('configuration', 503); }
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) { throw new Failure('busy', 429); }
            return $this->extractLocked($path);
        } finally {
            fclose($lock);
        }
    }

    private function extractLocked(string $path): array
    {
        if (!is_file($path) || filesize($path) > self::MAX_BYTES || file_get_contents($path, false, null, 0, 5) !== '%PDF-') {
            throw new Failure('invalidPdf');
        }
        $info = $this->run('pdfinfo', ['-enc', 'UTF-8', $path]);
        if (preg_match('/^Encrypted:\s+yes/im', $info)) {
            throw new Failure('invalidPdf');
        }
        $warnings = [];
        $pages = preg_match('/^Pages:\s+(\d+)/m', $info, $m) ? (int) $m[1] : 0;
        if ($pages > self::MAX_PAGES) { $warnings[] = 'partial'; }
        $xmp = '';
        try {
            $xmp = $this->run('pdfinfo', ['-meta', $path]);
        } catch (Failure $e) {
            if ($e->reason === 'limit') { throw $e; }
            $warnings[] = 'partial';
        }
        $text = '';
        try {
            $text = $this->run('pdftotext', ['-f', '1', '-l', (string) self::MAX_PAGES, '-enc', 'UTF-8', '-layout', '-nopgbrk', $path, '-']);
        } catch (Failure $e) {
            if ($e->reason === 'limit') { throw $e; }
            $warnings[] = 'partial';
        }
        $result = (new MetadataParser())->parse($text, $info, $xmp);
        $result['warnings'] = array_values(array_unique(array_merge($result['warnings'], $warnings)));
        return $result;
    }

    private function run(string $tool, array $args): string
    {
        $binary = Config::getVar('pdf_metadata', $tool, '/usr/bin/' . $tool);
        $limiter = Config::getVar('pdf_metadata', 'prlimit', '/usr/bin/prlimit');
        // Linux is the supported extraction host. No unbounded fallback on other platforms.
        if (PHP_OS_FAMILY !== 'Linux' || !is_executable($binary) || !is_executable($limiter) || !str_starts_with($binary, '/') || !str_starts_with($limiter, '/')) {
            throw new Failure('unavailable', 503);
        }
        $process = new Process([$limiter, '--as=536870912', '--cpu=10', '--fsize=2097152', '--nofile=64', '--', $binary, ...$args], null, ['LC_ALL' => 'C']);
        $process->setTimeout(15);
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
        } catch (\Symfony\Component\Process\Exception\ProcessTimedOutException $e) {
            throw new Failure('limit');
        } finally {
            if ($process->isRunning()) { $process->stop(0); }
        }
        return $output;
    }
}
