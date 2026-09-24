const fs = require('node:fs');
const assert = require('node:assert/strict');

const extractor = fs.readFileSync(__dirname + '/../classes/PdfExtractor.php', 'utf8');

assert(extractor.includes("return 'poppler';"), 'Poppler remains the preferred backend');
assert(extractor.includes("return 'ghostscript';"), 'Ghostscript fallback backend is available');
assert(extractor.includes("'gs' => '/usr/bin/gs'"), 'Ghostscript has a configurable absolute-path default');
assert(extractor.includes("'-sDEVICE=txtwrite'"), 'Ghostscript txtwrite is used for text extraction');
assert(extractor.includes("'-dSAFER'"), 'Ghostscript runs in safer mode');
assert(extractor.includes("pdfpagecount = quit"), 'Ghostscript page count is used when Poppler is unavailable');
assert(extractor.includes("$this->toolAvailable('pdfinfo') && $this->toolAvailable('pdftotext')"), 'Poppler is selected only when both tools are executable');

console.log('Extractor contract: Poppler preferred with Ghostscript fallback passed.');
