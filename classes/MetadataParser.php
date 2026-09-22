<?php
namespace APP\plugins\generic\pdfMetadata\classes;

/** Deliberately conservative suggestions, not an assertion of bibliographic truth. */
class MetadataParser
{
    public function parse(string $text, string $info = '', string $xmp = ''): array
    {
        $text = $this->clean($text);
        $front = mb_substr($text, 0, 14000);
        // Exclude bibliography before looking for the article DOI/title.
        $front = preg_split('/^\h*(?:References|Bibliography|Литература|Список литературы)\h*$/miu', $front)[0];
        $fields = [];
        $put = function ($key, $value, $source, $confidence) use (&$fields) {
            if ($value !== '' && $value !== []) {
                $fields[$key] = compact('value', 'source', 'confidence');
            }
        };
        $xml = $this->xmp($xmp);
        $pdf = [];
        foreach (['Title', 'Author', 'Keywords', 'Subject'] as $key) {
            if (preg_match('/^' . $key . ':\h*(.+)$/miu', $info, $m)) {
                $pdf[$key] = trim($this->clean($m[1]));
            }
        }
        $title = $xml['title'] ?? $pdf['Title'] ?? '';
        $chosenTitle = '';
        if ($title && !preg_match('/^(?:untitled|document\d*|Microsoft Word|без названия)/iu', $title)) {
            $chosenTitle = mb_substr($title, 0, 1000);
            $put('title', $chosenTitle, isset($xml['title']) ? 'xmp' : 'pdf-info', 'medium');
        } else {
            foreach (preg_split('/\R/u', mb_substr($front, 0, 3000)) as $line) {
                $line = trim($line);
                if (mb_strlen($line) >= 20 && mb_strlen($line) <= 300 && !preg_match('/(?:https?:|doi|issn|@|copyright|©)/iu', $line)) {
                    $chosenTitle = $line;
                    $put('title', $line, 'first-lines', 'low');
                    break;
                }
            }
        }
        if (!empty($xml['description'])) {
            $put('abstract', mb_substr($xml['description'], 0, 30000), 'xmp', 'medium');
        } elseif (preg_match('/(?:^|\n)\h*(?:Abstract|Аннотация|Резюме)\h*[:.\-]?\h*(.+?)(?=\n\h*(?:Key\s*words|Ключевые слова|\d*\.?\h*Introduction|\d*\.?\h*Введение|Background)\b|\z)/isu', $front, $m)) {
            $put('abstract', trim(mb_substr($m[1], 0, 15000)), 'heading', 'medium');
        }
        $keywords = $xml['subject'] ?? $pdf['Keywords'] ?? '';
        if (preg_match('/(?:^|\n)\h*(?:Key\s*words|Ключевые слова)\h*[:.\-]\h*([^\n]+)/iu', $front, $m)) {
            $keywords = $m[1];
        }
        $put('keywords', array_slice(array_values(array_filter(array_map('trim', preg_split('/[;,\n]/u', $keywords)))), 0, 40), 'keywords', 'medium');
        $doiText = ($xml['identifier'] ?? '') . "\n" . $front;
        if (preg_match('~\b10\.\d{4,9}/[-._;()/:A-Z0-9]+~iu', $doiText, $m)) {
            $put('doi', rtrim($m[0], '.,;:'), 'identifier-or-front', 'low');
        }
        $authors = $xml['creators'] ?? [];
        $authorSource = 'xmp-or-pdf-info';
        if (!$authors && !empty($pdf['Author'])) {
            // Semicolons are unambiguous enough as separators. Do not interpret comma as surname/given name.
            $authors = preg_split('/\h*;\h*/u', $pdf['Author']);
        }
        if (!$authors) {
            $authors = $this->authorsFromFront($front, $chosenTitle);
            $authorSource = 'first-lines';
        }
        $put('authors', array_map(fn ($name) => ['name' => mb_substr($name, 0, 500), 'givenName' => '', 'familyName' => '', 'email' => ''], array_slice($authors, 0, 40)), $authorSource, 'low');
        preg_match_all('/^.*(?:university|institute|academy|университет|институт|академи).*/miu', mb_substr($front, 0, 6000), $aff);
        $put('affiliations', array_slice(array_values(array_unique(array_filter(array_map('trim', $aff[0])))), 0, 20), 'first-lines', 'low');
        if (preg_match('/^\h*(?:References|Bibliography|Литература|Список литературы)\h*\n(.+)$/misu', $text, $m)) {
            $put('references', trim(mb_substr($m[1], 0, 100000)), 'heading', 'low');
        }
        return ['fields' => $fields, 'warnings' => mb_strlen(trim($text)) < 100 ? ['noText'] : [], 'text' => mb_substr($text, 0, 30000)];
    }

    /**
     * Suggest author display names from lines immediately following the detected title.
     * This is intentionally conservative and never guesses given/family-name ordering.
     */
    private function authorsFromFront(string $front, string $title): array
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/u', mb_substr($front, 0, 4000))), fn ($v) => $v !== ''));
        if (!$lines) { return []; }
        $start = 0;
        if ($title !== '') {
            foreach ($lines as $i => $line) {
                if ($line === $title || mb_stripos($line, $title) !== false || mb_stripos($title, $line) !== false) {
                    $start = $i + 1;
                    break;
                }
            }
        }
        $candidates = [];
        for ($i = $start; $i < min(count($lines), $start + 6); $i++) {
            $line = trim($lines[$i]);
            if ($line === '' || mb_strlen($line) > 500) { continue; }
            if (preg_match('/^(?:Abstract|Аннотация|Резюме|Keywords?|Ключевые слова|Введение|Introduction|УДК|UDC)\b/iu', $line)) { break; }
            if (preg_match('/(?:university|institute|academy|department|faculty|университет|институт|академи|кафедр|факультет|https?:|www\.|@|doi\b|issn\b)/iu', $line)) {
                if ($candidates) { break; }
                continue;
            }
            // Strong author-line cues: explicit separators or initials. Otherwise allow a short human-name-like line.
            $looksLikeName = preg_match('/[;,]/u', $line)
                || preg_match('/\b\p{L}[.]\s*\p{L}[.]|\b\p{Lu}\p{Ll}+\s+\p{Lu}\p{Ll}+/u', $line);
            if (!$looksLikeName) { continue; }
            $parts = preg_split('/\s*;\s*/u', $line);
            if (count($parts) === 1 && substr_count($line, ',') >= 1) {
                $commaParts = array_values(array_filter(array_map('trim', preg_split('/\s*,\s*/u', $line))));
                $allNameLike = count($commaParts) <= 12;
                foreach ($commaParts as $part) {
                    if (!preg_match('/^\p{L}[\p{L}\'’.-]*(?:\s+[\p{L}.\'’\-]+){1,5}$/u', $part)) { $allNameLike = false; break; }
                }
                if ($allNameLike) { $parts = $commaParts; }
            }
            foreach ($parts as $part) {
                $part = trim($part, " \t\n\r\0\x0B,;");
                if ($part !== '' && mb_strlen($part) <= 200) { $candidates[] = $part; }
            }
            // Usually the author block is one or two lines. Stop before consuming affiliations/body.
            if (count($candidates) >= 1 && $i >= $start + 1) { break; }
        }
        return array_values(array_unique($candidates));
    }

    private function clean(string $text): string
    {
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        return preg_replace('/[\x00-\x08\x0B\x0E-\x1F\x7F]/u', '', str_replace(["\r\n", "\r", "\f"], "\n", $text));
    }

    private function xmp(string $xml): array
    {
        if (!$xml || strlen($xml) > 1048576 || preg_match('/<!DOCTYPE|<!ENTITY/i', $xml)) {
            return [];
        }
        $old = libxml_use_internal_errors(true);
        try {
            $doc = new \DOMDocument();
            if (!$doc->loadXML($xml, LIBXML_NONET)) {
                return [];
            }
            $xp = new \DOMXPath($doc);
            $xp->registerNamespace('dc', 'http://purl.org/dc/elements/1.1/');
            $xp->registerNamespace('rdf', 'http://www.w3.org/1999/02/22-rdf-syntax-ns#');
            $result = [];
            foreach (['title', 'description', 'subject', 'identifier', 'creator'] as $key) {
                $nodes = $xp->query('//dc:' . $key . '//rdf:li');
                if (!$nodes->length) {
                    $nodes = $xp->query('//dc:' . $key);
                }
                $values = [];
                foreach ($nodes as $node) {
                    $value = trim($this->clean($node->textContent));
                    if ($value !== '') { $values[] = $value; }
                }
                if ($values) {
                    $result[$key === 'creator' ? 'creators' : $key] = $key === 'creator' ? $values : implode($key === 'subject' ? '; ' : "\n", $values);
                }
            }
            return $result;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($old);
        }
    }
}
