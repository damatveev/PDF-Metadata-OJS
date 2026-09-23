<?php
/** Filesystem workspace tests with an isolated tempRoot double; Poppler is not invoked. */
namespace APP\plugins\generic\pdfMetadata\classes {
    class SubmissionPdf {
        public static string $root;
        public static function tempRoot(): string { return self::$root; }
    }
}
namespace {
    require __DIR__ . '/run.php';
    require __DIR__ . '/../classes/PdfExtractor.php';
    require __DIR__ . '/../classes/IssueWorkspace.php';
    use APP\plugins\generic\pdfMetadata\classes\IssueWorkspace;
    use APP\plugins\generic\pdfMetadata\classes\SubmissionPdf;

    $root = sys_get_temp_dir() . '/pdfmd-test-' . bin2hex(random_bytes(8));
    mkdir($root, 0700);
    SubmissionPdf::$root = $root;
    $key = hash('sha256', '2|3|session');
    $dir = $root . '/issue-' . $key;
    mkdir($dir, 0700);
    file_put_contents($dir . '/issue.pdf', '%PDF-test');
    file_put_contents($dir . '/workspace.json', json_encode(['sha256' => hash_file('sha256', $dir . '/issue.pdf'), 'pages' => 100]));
    $workspace = new IssueWorkspace(2, 3, 'session');
    $article = ['locale' => 'en', 'startPage' => 1, 'endPage' => 4, 'publication' => [
        'title' => ['en' => 'Reviewed title'], 'sectionId' => 7,
    ], 'authors' => [['givenName' => ['en' => 'Jane'], 'email' => 'jane@example.org', 'userGroupId' => 5]]];
    try {
        $before = $count;
        $saved = $workspace->synchronize(fn () => $workspace->saveArticle($article));
        check($saved['readyForOjs'] === true, 'Reviewed draft readiness');
        check(count($workspace->state()['articles']) === 1, 'Draft persisted');
        $saved['publication']['title']['en'] = 'Updated title';
        $workspace->saveArticle($saved);
        check(count($workspace->state()['articles']) === 1 && $workspace->state()['articles'][0]['publication']['title']['en'] === 'Updated title', 'Edit retains draft ID');
        check($workspace->export()['pluginVersion'] === '1.0.1.1', 'Export release matches package');
        check((new IssueWorkspace(2, 4, 'session'))->state()['document'] === null, 'User isolation');
        check((new IssueWorkspace(8, 3, 'session'))->state()['document'] === null, 'Context isolation');
        check((new IssueWorkspace(2, 3, 'other'))->state()['document'] === null, 'Session isolation');
        check($workspace->synchronize(fn () => fails(fn () => (new IssueWorkspace(2, 3, 'session'))->synchronize(fn () => null), 'busy')), 'Concurrent workspace request rejected');
        $workspace->synchronize(fn () => null);
        check(true, 'Workspace lock released after action');
        $invalid = $article; $invalid['endPage'] = 81;
        check(fails(fn () => $workspace->saveArticle($invalid), 'validation'), 'Article range limit');
        $invalid = $article; $invalid['authors'][0]['email'] = 'invalid';
        check(fails(fn () => $workspace->saveArticle($invalid), 'validation'), 'Invalid email rejected');
        $invalid = $article; $invalid['publication']['doi'] = 'invalid';
        check(fails(fn () => $workspace->saveArticle($invalid), 'validation'), 'Invalid DOI rejected');
        $invalid = $article; $invalid['publication']['title']['en'] = str_repeat('x', 1001);
        check(fails(fn () => $workspace->saveArticle($invalid), 'validation'), 'Field size limit');
        check(count($workspace->state()['articles']) === 1, 'Rejected edits do not modify saved drafts');
        $workspace->removeArticle($saved['id']);
        check($workspace->state()['articles'] === [], 'Remove selected draft');
        file_put_contents($dir . '/issue.pdf', '%PDF-changed');
        check(fails(fn () => $workspace->saveArticle($article), 'conflict'), 'Changed PDF hash rejected');
        $workspace->synchronize(fn () => $workspace->clear());
        check($workspace->state()['document'] === null, 'Clear removes document');
        echo ($count - $before) . " workspace tests passed.\n";
    } finally {
        // Only this test's random directory and its known immediate children.
        foreach (glob($dir . '/*') ?: [] as $path) { unlink($path); }
        if (is_dir($dir)) { rmdir($dir); }
        foreach (glob($root . '/*.lock') ?: [] as $path) { unlink($path); }
        rmdir($root);
    }
}
