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
        if ($title && !preg_match('/^(?:untitled|document\d*|Microsoft Word|без названия)/iu', $title)) {
            $put('title', mb_substr($title, 0, 1000), isset($xml['title']) ? 'xmp' : 'pdf-info', 'medium');
        } else {
            foreach (preg_split('/\R/u', mb_substr($front, 0, 3000)) as $line) {
                $line = trim($line);
                if (mb_strlen($line) >= 20 && mb_strlen($line) <= 300 && !preg_match('/(?:https?:|doi|issn|@|copyright|©)/iu', $line)) {
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
        if (!$authors && !empty($pdf['Author'])) {
            // Commas may separate surname/given name. Never split them automatically.
            $authors = preg_split('/\h*;\h*/u', $pdf['Author']);
        }
        $put('authors', array_map(fn ($name) => ['name' => mb_substr($name, 0, 500), 'givenName' => '', 'familyName' => '', 'email' => ''], array_slice($authors, 0, 40)), 'xmp-or-pdf-info', 'low');
        preg_match_all('/^.*(?:university|institute|academy|университет|институт|академи).*/miu', mb_substr($front, 0, 6000), $aff);
        $put('affiliations', array_slice(array_values(array_unique(array_filter(array_map('trim', $aff[0])))), 0, 20), 'first-lines', 'low');
        if (preg_match('/^\h*(?:References|Bibliography|Литература|Список литературы)\h*\n(.+)$/misu', $text, $m)) {
            $put('references', trim(mb_substr($m[1], 0, 100000)), 'heading', 'low');
        }
        return ['fields' => $fields, 'warnings' => mb_strlen(trim($text)) < 100 ? ['noText'] : [], 'text' => mb_substr($text, 0, 30000)];
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
