<?php
/** Standalone tests: php -d extension=mbstring tests/run.php */
require __DIR__ . '/../classes/Failure.php';
require __DIR__ . '/../classes/Ticket.php';
require __DIR__ . '/../classes/MetadataParser.php';
require __DIR__ . '/../classes/MetadataService.php';

use APP\plugins\generic\pdfMetadata\classes\Failure;
use APP\plugins\generic\pdfMetadata\classes\MetadataParser;
use APP\plugins\generic\pdfMetadata\classes\MetadataService;
use APP\plugins\generic\pdfMetadata\classes\Ticket;

$count = 0;
function check(bool $ok, string $label): void {
    global $count;
    if (!$ok) { throw new RuntimeException('FAIL: ' . $label); }
    $count++;
    echo "PASS: $label\n";
}
function fails(callable $fn, string $reason): bool {
    try { $fn(); } catch (Failure $e) { return $e->reason === $reason; }
    return false;
}
$parser = new MetadataParser();
$english = "A Study of Reproducible Metadata Extraction\nJane Doe; John Doe\nExample University\nDOI: 10.12345/test.2026\nAbstract\nWe examine extraction from scholarly PDFs.\nKeywords: metadata; journals; PDF\nIntroduction\nThis is the body.\nReferences\n1. Someone. Earlier study. doi:10.54321/other\n2. Another source.";
$r = $parser->parse($english, "Title: A Study of Reproducible Metadata Extraction\nAuthor: Jane Doe; John Doe\n");
check($r['fields']['title']['source'] === 'pdf-info', 'PDF Info title');
check($r['fields']['abstract']['value'] === 'We examine extraction from scholarly PDFs.', 'Abstract stops at keywords');
check($r['fields']['keywords']['value'] === ['metadata', 'journals', 'PDF'], 'Keyword split');
check($r['fields']['doi']['value'] === '10.12345/test.2026', 'Article DOI instead of reference DOI');
check(count($r['fields']['authors']['value']) === 2, 'Author list');
check($r['fields']['authors']['value'][0]['email'] === '', 'Never fabricate email');
check($r['fields']['authors']['value'][0]['givenName'] === '', 'Never guess name splitting');
check($r['fields']['affiliations']['value'] === ['Example University'], 'Affiliation suggestion');
check(str_starts_with($r['fields']['references']['value'], '1. Someone.'), 'Raw references');
$r = $parser->parse("Исследование извлечения метаданных статей\nАннотация\nПроверяется работа с русскими статьями.\nКлючевые слова: журнал; метаданные\nВведение\nТекст.\nСписок литературы\n1. Иванов. Исследование.");
check($r['fields']['abstract']['value'] === 'Проверяется работа с русскими статьями.', 'Russian abstract');
check($r['fields']['keywords']['value'] === ['журнал', 'метаданные'], 'Russian keywords');
check(str_contains($r['fields']['references']['value'], 'Иванов'), 'Russian references');
$r = $parser->parse("A sufficiently long article title\nAbstract\nText\nIntroduction\nBody\nReferences\n10.5555/reference-only");
check(!isset($r['fields']['doi']), 'Do not promote a bibliography DOI');
check(in_array('noText', $parser->parse('')['warnings']), 'Scanned PDF fallback');
$xmp = '<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"><rdf:Description xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:title><rdf:Alt><rdf:li xml:lang="x-default">XMP title</rdf:li></rdf:Alt></dc:title><dc:creator><rdf:Seq><rdf:li>Doe, Jane</rdf:li></rdf:Seq></dc:creator></rdf:Description></rdf:RDF></x:xmpmeta>';
$r = $parser->parse('', 'Title: Old title', $xmp);
check($r['fields']['title']['value'] === 'XMP title', 'XMP preferred to Info');
check($r['fields']['authors']['value'][0]['name'] === 'Doe, Jane', 'Comma in name preserved');
$r = $parser->parse('', 'Title: Safe title', '<!DOCTYPE x [<!ENTITY external SYSTEM "file:///etc/passwd">]><x>&external;</x>');
check($r['fields']['title']['value'] === 'Safe title', 'XXE rejected before XML parse');
$r = $parser->parse('', 'Title: Safe title', '<broken');
check($r['fields']['title']['value'] === 'Safe title', 'Malformed XMP fallback');
check(strlen($parser->parse(str_repeat('A', 40000))['text']) === 30000, 'Text preview bounded');
$ticket = new Ticket(str_repeat('s', 64));
$identity = ['userId' => 1, 'contextId' => 2, 'submissionId' => 3, 'publicationId' => 4, 'session' => 'session-hash'];
$value = $ticket->issue($identity + ['fingerprint' => 'abc']);
check($ticket->verify($value, $identity)['fingerprint'] === 'abc', 'Valid review ticket');
check(fails(fn () => $ticket->verify($value . '0', $identity), 'expired'), 'Tampered ticket denied');
foreach (['userId', 'contextId', 'submissionId', 'publicationId', 'session'] as $key) {
    $wrong = $identity; $wrong[$key] = 'different';
    check(fails(fn () => $ticket->verify($value, $wrong), 'forbidden'), 'Ticket bound to ' . $key);
}
check(fails(fn () => $ticket->verify($ticket->issue($identity + ['expires' => time() - 1]), $identity), 'expired'), 'Expired ticket denied');
check(fails(fn () => new Ticket('short'), 'configuration'), 'Weak secret rejected');
check(fails(fn () => new Ticket('REPLACE_WITH_64_RANDOM_HEX_CHARACTERS'), 'configuration'), 'Example secret rejected');
$service = new MetadataService();
check($service->fingerprint(['a' => 1, 'b' => ['x' => 2]]) === $service->fingerprint(['b' => ['x' => 2], 'a' => 1]), 'Fingerprint independent of map order');
check($service->fingerprint(['authors' => [1, 2]]) !== $service->fingerprint(['authors' => [2, 1]]), 'Fingerprint detects author ordering');
check($service->fingerprint(['citationsRaw' => 'one']) !== $service->fingerprint(['citationsRaw' => 'two']), 'Fingerprint detects reference change');
$range = $parser->parse("Article Range Title for Workspace\nJane Doe; John Smith\nExample Research Institute\nAbstract\nWorkspace extraction test.\nKeywords: OJS; metadata\nReferences\n1. Example.");
check($range['fields']['title']['value'] === 'Article Range Title for Workspace', 'Workspace range title from article text');
check(count($range['fields']['authors']['value']) === 2, 'Workspace range suggests author display names');
check($range['fields']['authors']['value'][0]['givenName'] === '' && $range['fields']['authors']['value'][0]['familyName'] === '', 'Workspace does not guess given/family split');
check($range['fields']['affiliations']['value'] === ['Example Research Institute'], 'Workspace range affiliation suggestion');
echo "\n$count tests passed.\n";
