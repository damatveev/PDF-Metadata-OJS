<?php
namespace APP\plugins\generic\pdfMetadata\classes;

use Illuminate\Http\UploadedFile;

/**
 * Session-bound workspace for a complete journal issue PDF.
 * Files live only in the configured private pdf_metadata.temp_dir.
 */
class IssueWorkspace
{
    public const MAX_ISSUE_BYTES = 157286400; // 150 MiB
    public const MAX_ARTICLES = 200;

    private string $dir;
    private string $pdf;
    private string $meta;
    private string $articles;

    public function __construct(int $contextId, int $userId, string $sessionToken)
    {
        if ($contextId < 1 || $userId < 1 || $sessionToken === '') {
            throw new Failure('forbidden', 403);
        }
        $root = SubmissionPdf::tempRoot();
        $key = hash('sha256', $contextId . '|' . $userId . '|' . $sessionToken);
        $this->dir = $root . '/issue-' . $key;
        $this->pdf = $this->dir . '/issue.pdf';
        $this->meta = $this->dir . '/workspace.json';
        $this->articles = $this->dir . '/articles.json';
    }

    public function state(): array
    {
        $meta = $this->readJson($this->meta, null);
        $articles = $this->readJson($this->articles, []);
        if (!is_array($articles)) { $articles = []; }
        usort($articles, fn ($a, $b) => (($a['startPage'] ?? 0) <=> ($b['startPage'] ?? 0)) ?: (($a['endPage'] ?? 0) <=> ($b['endPage'] ?? 0)));
        return ['document' => is_array($meta) ? $meta : null, 'articles' => array_values($articles)];
    }

    /** Serialize the complete read/modify/write operation, including upload/extract. */
    public function synchronize(callable $action): mixed
    {
        // Outside the workspace directory so clear() cannot replace the lock inode.
        $handle = fopen($this->dir . '.lock', 'c');
        if (!$handle) { throw new Failure('configuration', 503); }
        try {
            chmod($this->dir . '.lock', 0600);
            if (!flock($handle, LOCK_EX | LOCK_NB)) { throw new Failure('busy', 429); }
            return $action();
        } finally {
            fclose($handle);
        }
    }

    public function upload(UploadedFile $file, PdfExtractor $extractor): array
    {
        if (!$file->isValid()) { throw new Failure('invalidPdf'); }
        $size = (int) $file->getSize();
        if ($size < 5 || $size > self::MAX_ISSUE_BYTES) { throw new Failure('limit', 413); }
        $name = trim((string) $file->getClientOriginalName());
        if ($name === '' || !preg_match('/\.pdf$/iu', $name)) { throw new Failure('invalidPdf'); }
        $source = $file->getRealPath();
        if (!$source || !is_file($source)) { throw new Failure('invalidPdf'); }
        $head = file_get_contents($source, false, null, 0, 5);
        if ($head !== '%PDF-') { throw new Failure('invalidPdf'); }

        $this->ensureDirectory();
        $tmp = $this->dir . '/upload-' . bin2hex(random_bytes(8)) . '.pdf';
        $in = $out = null;
        try {
            $in = fopen($source, 'rb');
            $out = fopen($tmp, 'xb');
            if (!$in || !$out) { throw new Failure('configuration', 503); }
            chmod($tmp, 0600);
            $copied = stream_copy_to_stream($in, $out, self::MAX_ISSUE_BYTES + 1);
            if (!$copied || $copied > self::MAX_ISSUE_BYTES) { throw new Failure('limit', 413); }
            fclose($in); $in = null;
            fclose($out); $out = null;

            $info = $extractor->inspectDocument($tmp, self::MAX_ISSUE_BYTES);
            if (!rename($tmp, $this->pdf)) { throw new Failure('configuration', 503); }
            chmod($this->pdf, 0600);
            $meta = [
                'fileName' => mb_substr($name, 0, 255),
                'size' => filesize($this->pdf),
                'sha256' => hash_file('sha256', $this->pdf),
                'pages' => $info['pages'],
                'uploadedAt' => gmdate('c'),
            ];
            $this->writeJson($this->meta, $meta);
            $this->writeJson($this->articles, []);
            return ['document' => $meta, 'articles' => []];
        } finally {
            if (is_resource($in)) { fclose($in); }
            if (is_resource($out)) { fclose($out); }
            if (is_file($tmp)) { unlink($tmp); }
        }
    }

    public function extract(int $startPage, int $endPage, PdfExtractor $extractor): array
    {
        $meta = $this->requireDocument();
        $this->validateRange($startPage, $endPage, (int) $meta['pages']);
        return $extractor->extractRange($this->pdf, $startPage, $endPage, self::MAX_ISSUE_BYTES);
    }

    public function saveArticle(array $article): array
    {
        $meta = $this->requireDocument();
        $start = filter_var($article['startPage'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $end = filter_var($article['endPage'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$start || !$end) { throw new Failure('validation'); }
        $this->validateRange($start, $end, (int) $meta['pages']);

        $id = isset($article['id']) && is_string($article['id']) && preg_match('/^[a-f0-9]{16}$/', $article['id'])
            ? $article['id'] : bin2hex(random_bytes(8));
        $locale = $this->plain($article['locale'] ?? '', 14);
        $publication = $article['publication'] ?? null;
        $authors = $article['authors'] ?? null;
        if (!is_array($publication) || !is_array($authors) || count($authors) > 80) { throw new Failure('validation'); }

        $cleanPublication = [
            'locale' => $locale,
            'sectionId' => $this->nullableInt($publication['sectionId'] ?? null),
            'issueId' => null,
            'pages' => $this->plain($publication['pages'] ?? ($start . '–' . $end), 255, true),
            'prefix' => [$locale => $this->plain($this->localized($publication['prefix'] ?? [], $locale), 1000, true)],
            'title' => [$locale => $this->plain($this->localized($publication['title'] ?? [], $locale), 1000, true)],
            'subtitle' => [$locale => $this->plain($this->localized($publication['subtitle'] ?? [], $locale), 1000, true)],
            'abstract' => [$locale => $this->plain($this->localized($publication['abstract'] ?? [], $locale), 30000, true)],
            'keywords' => [$locale => $this->terms($this->localized($publication['keywords'] ?? [], $locale), 40)],
            'citationsRaw' => $this->plain($publication['citationsRaw'] ?? '', 100000, true),
            'doi' => $this->plain($publication['doi'] ?? '', 255, true),
        ];

        if ($cleanPublication['doi'] !== '' && !preg_match('~^10\.\d{4,9}/[-._;()/:A-Z0-9]+$~iD', $cleanPublication['doi'])) {
            throw new Failure('validation');
        }

        $cleanAuthors = [];
        foreach ($authors as $index => $author) {
            if (!is_array($author)) { throw new Failure('validation'); }
            $affiliations = [];
            foreach (($author['affiliations'] ?? []) as $affiliation) {
                if (!is_array($affiliation)) { throw new Failure('validation'); }
                $affiliations[] = [
                    'name' => [$locale => $this->plain($this->localized($affiliation['name'] ?? [], $locale), 1000, true)],
                    'ror' => $this->plain($affiliation['ror'] ?? '', 255, true),
                ];
            }
            if (count($affiliations) > 20) { throw new Failure('validation'); }
            $email = $this->plain($author['email'] ?? '', 254, true);
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { throw new Failure('validation'); }
            $cleanAuthors[] = [
                'seq' => $index + 1,
                'givenName' => [$locale => $this->plain($this->localized($author['givenName'] ?? [], $locale), 255, true)],
                'familyName' => [$locale => $this->plain($this->localized($author['familyName'] ?? [], $locale), 255, true)],
                'preferredPublicName' => [$locale => $this->plain($this->localized($author['preferredPublicName'] ?? [], $locale), 500, true)],
                'email' => $email,
                'orcid' => $this->plain($author['orcid'] ?? '', 255, true),
                'country' => $this->plain($author['country'] ?? '', 2, true),
                'url' => $this->plain($author['url'] ?? '', 2000, true),
                'userGroupId' => $this->nullableInt($author['userGroupId'] ?? null),
                'includeInBrowse' => !array_key_exists('includeInBrowse', $author) || (bool) $author['includeInBrowse'],
                'affiliations' => $affiliations,
            ];
        }

        $ready = $cleanPublication['title'][$locale] !== '' && $cleanPublication['sectionId'] !== null && count($cleanAuthors) > 0;
        foreach ($cleanAuthors as $author) {
            if ($author['givenName'][$locale] === '' || $author['email'] === '' || $author['userGroupId'] === null) { $ready = false; break; }
        }

        $saved = [
            'id' => $id,
            'startPage' => $start,
            'endPage' => $end,
            'locale' => $locale,
            'publication' => $cleanPublication,
            'authors' => $cleanAuthors,
            'readyForOjs' => $ready,
            'reviewedAt' => gmdate('c'),
        ];
        $items = $this->readJson($this->articles, []);
        if (!is_array($items)) { $items = []; }
        $found = false;
        foreach ($items as $i => $item) {
            if (($item['id'] ?? null) === $id) { $items[$i] = $saved; $found = true; break; }
        }
        if (!$found) {
            if (count($items) >= self::MAX_ARTICLES) { throw new Failure('limit', 413); }
            $items[] = $saved;
        }
        $this->writeJson($this->articles, array_values($items));
        return $saved;
    }

    public function removeArticle(string $id): void
    {
        if (!preg_match('/^[a-f0-9]{16}$/', $id)) { throw new Failure('validation'); }
        $items = $this->readJson($this->articles, []);
        if (!is_array($items)) { return; }
        $items = array_values(array_filter($items, fn ($item) => ($item['id'] ?? null) !== $id));
        $this->writeJson($this->articles, $items);
    }

    public function clear(): void
    {
        foreach ([$this->pdf, $this->meta, $this->articles] as $path) {
            if (is_file($path)) { unlink($path); }
        }
        if (is_dir($this->dir)) { @rmdir($this->dir); }
    }

    public function export(): array
    {
        $state = $this->state();
        if (!$state['document']) { throw new Failure('empty', 404); }
        return [
            'schema' => 'OJS-3.5-review-draft',
            'pluginVersion' => '1.0.1.1',
            'document' => $state['document'],
            'articles' => $state['articles'],
        ];
    }

    private function requireDocument(): array
    {
        $meta = $this->readJson($this->meta, null);
        if (!is_array($meta) || !is_file($this->pdf)) { throw new Failure('empty', 404); }
        if (!isset($meta['sha256']) || !hash_equals((string) $meta['sha256'], hash_file('sha256', $this->pdf))) { throw new Failure('conflict', 409); }
        return $meta;
    }

    private function validateRange(int $start, int $end, int $pages): void
    {
        if ($start < 1 || $end < $start || $end > $pages || ($end - $start + 1) > PdfExtractor::MAX_ARTICLE_PAGES) {
            throw new Failure('validation');
        }
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->dir) && !mkdir($this->dir, 0700)) { throw new Failure('configuration', 503); }
        chmod($this->dir, 0700);
    }

    private function readJson(string $path, mixed $default): mixed
    {
        if (!is_file($path)) { return $default; }
        $raw = file_get_contents($path);
        if ($raw === false || strlen($raw) > 10485760) { throw new Failure('conflict', 409); }
        try { return json_decode($raw, true, 64, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new Failure('conflict', 409); }
    }

    private function writeJson(string $path, array $data): void
    {
        $this->ensureDirectory();
        $tmp = $path . '.tmp-' . bin2hex(random_bytes(6));
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        if (strlen($json) > 10485760) { throw new Failure('limit', 413); }
        if (file_put_contents($tmp, $json, LOCK_EX) === false) { throw new Failure('configuration', 503); }
        chmod($tmp, 0600);
        if (!rename($tmp, $path)) { @unlink($tmp); throw new Failure('configuration', 503); }
        chmod($path, 0600);
    }

    private function localized(mixed $value, string $locale): mixed
    {
        return is_array($value) ? ($value[$locale] ?? '') : $value;
    }

    private function terms(mixed $value, int $max): array
    {
        if (!is_array($value)) { return []; }
        $result = [];
        foreach ($value as $term) {
            if (is_array($term)) { $term = $term['name'] ?? ''; }
            $term = $this->plain($term, 255, true);
            if ($term !== '') { $result[] = ['name' => $term, 'source' => null, 'identifier' => null]; }
            if (count($result) >= $max) { break; }
        }
        return $result;
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') { return null; }
        $int = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$int) { throw new Failure('validation'); }
        return (int) $int;
    }

    private function plain(mixed $value, int $max, bool $allowEmpty = false): string
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value) > $max || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value)) {
            throw new Failure('validation');
        }
        $value = trim(strip_tags($value));
        if (!$allowEmpty && $value === '') { throw new Failure('validation'); }
        return $value;
    }
}
