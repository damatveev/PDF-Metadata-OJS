<?php
/** Run on the configured Linux OJS host, outside the web server:
 * php plugins/generic/pdfMetadata/tests/extract-smoke.php /absolute/ojs /absolute/test.pdf
 * The test PDF must be disposable and contain a searchable title and Abstract heading.
 */
if (count($argv) !== 3) { fwrite(STDERR, "Usage: php extract-smoke.php OJS_ROOT PDF\n"); exit(2); }
$ojs = realpath($argv[1]);
$pdf = realpath($argv[2]);
if (!$ojs || !$pdf) { fwrite(STDERR, "Input path does not exist\n"); exit(2); }
chdir($ojs);
define('INDEX_FILE_LOCATION', $ojs . '/index.php');
require 'lib/pkp/includes/bootstrap.php';
try {
    $result = (new \APP\plugins\generic\pdfMetadata\classes\PdfExtractor())->extract($pdf);
    if (!isset($result['fields']['title'], $result['fields']['abstract'])) {
        throw new RuntimeException('Expected title and abstract in the disposable test PDF');
    }
    echo "Poppler smoke test passed. Fields: " . implode(', ', array_keys($result['fields'])) . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Smoke test failed: " . get_class($e) . "\n"); exit(1);
}
