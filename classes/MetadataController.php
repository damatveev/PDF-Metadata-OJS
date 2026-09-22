<?php
namespace APP\plugins\generic\pdfMetadata\classes;

use APP\core\Application;
use APP\facades\Repo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PKP\config\Config;
use PKP\core\PKPBaseController;
use PKP\core\PKPRequest;
use PKP\security\authorization\ContextAccessPolicy;
use PKP\security\authorization\PublicationWritePolicy;
use PKP\security\Role;
use PKP\submission\PKPSubmission;
use PKP\observers\events\MetadataChanged;

class MetadataController extends PKPBaseController
{
    public function getHandlerPath(): string { return 'pdf-metadata'; }
    public function getRouteGroupMiddleware(): array { return ['has.user', 'has.context']; }

    public function getGroupRoutes(): void
    {
        Route::middleware([self::roleAuthorizer([Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR])])->group(function () {
            $base = '{submissionId}/publications/{publicationId}';
            Route::get($base, $this->inspect(...))->name('pdfMetadata.inspect')->whereNumber(['submissionId', 'publicationId']);
            Route::post($base . '/extract', $this->extract(...))->name('pdfMetadata.extract')->whereNumber(['submissionId', 'publicationId']);
            Route::post($base . '/apply', $this->apply(...))->name('pdfMetadata.apply')->whereNumber(['submissionId', 'publicationId']);
        });
    }

    public function authorize(PKPRequest $request, array &$args, array $roleAssignments): bool
    {
        $this->addPolicy(new ContextAccessPolicy($request, $roleAssignments));
        $this->addPolicy(new PublicationWritePolicy($request, $args, $roleAssignments));
        return parent::authorize($request, $args, $roleAssignments);
    }

    private function resources(Request $http): array
    {
        // Explicitly session-only; an Authorization header must never bypass CSRF.
        if ($http->header('Authorization') || $http->query('apiToken')) { throw new Failure('forbidden', 403); }
        if ($http->isMethod('post')) {
            $token = $this->getRequest()->getSession()->token();
            if (!$token || !hash_equals($token, (string) $http->header('X-CSRF-Token', ''))) { throw new Failure('forbidden', 403); }
            if (strlen($http->getContent()) > 262144) { throw new Failure('limit', 413); }
        }
        $submission = Repo::submission()->get((int) $http->route('submissionId'));
        $publication = Repo::publication()->get((int) $http->route('publicationId'));
        $context = $this->getRequest()->getContext();
        if (!$submission || !$publication || !$context
            || (int) $submission->getData('contextId') !== $context->getId()
            || (int) $publication->getData('submissionId') !== $submission->getId()
            || $publication->getId() !== $submission->getLatestPublication()->getId()
            || (int) $publication->getData('status') !== PKPSubmission::STATUS_QUEUED
            || !Repo::submission()->canEditPublication($submission->getId(), $this->getRequest()->getUser()->getId())) {
            throw new Failure('forbidden', 403);
        }
        return [$submission, $publication, $context];
    }

    private function identity($submission, $publication, $context): array
    {
        return ['submissionId' => $submission->getId(), 'publicationId' => $publication->getId(),
            'contextId' => $context->getId(), 'userId' => $this->getRequest()->getUser()->getId(),
            'session' => hash('sha256', $this->getRequest()->getSession()->token())];
    }

    private function tickets(): Ticket
    {
        return new Ticket((string) Config::getVar('pdf_metadata', 'secret', ''));
    }

    public function inspect(Request $http): JsonResponse
    {
        return $this->respond(function () use ($http) {
            [$s, $p, $c] = $this->resources($http);
            $service = new MetadataService();
            return ['current' => $service->snapshot($s, $p), 'files' => (new SubmissionPdf())->list($s->getId()),
                'locales' => $s->getPublicationLanguages($c->getSupportedSubmissionMetadataLocales()), 'groups' => $service->groups($c->getId())];
        });
    }

    public function extract(Request $http): JsonResponse
    {
        return $this->respond(function () use ($http) {
            [$s, $p, $c] = $this->resources($http);
            $fileId = filter_var($http->input('fileId'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$fileId) { throw new Failure('validation'); }
            $tickets = $this->tickets();
            $service = new MetadataService();
            $current = $service->snapshot($s, $p);
            return (new SubmissionPdf())->withCopy($s->getId(), $fileId, function ($path) use ($s, $p, $c, $fileId, $service, $current, $tickets) {
                $result = (new PdfExtractor())->extract($path);
                $result['current'] = $current;
                $result['ticket'] = $tickets->issue($this->identity($s, $p, $c) + [
                    'fingerprint' => $service->fingerprint($current), 'fileId' => $fileId,
                    'fileHash' => hash_file('sha256', $path),
                ]);
                return $result;
            });
        });
    }

    public function apply(Request $http): JsonResponse
    {
        return $this->respond(function () use ($http) {
            [$s, $p, $c] = $this->resources($http);
            $claims = $this->tickets()->verify((string) $http->input('ticket', ''), $this->identity($s, $p, $c));
            // Source must still belong to this submission and have the same bytes.
            (new SubmissionPdf())->withCopy($s->getId(), $claims['fileId'], function ($path) use ($claims) {
                if (!hash_equals($claims['fileHash'], hash_file('sha256', $path))) { throw new Failure('conflict', 409); }
            });
            $changed = DB::transaction(function () use ($http, $s, $p, $claims) {
                DB::table('submissions')->where('submission_id', $s->getId())->lockForUpdate()->first();
                DB::table('publications')->where('publication_id', $p->getId())->lockForUpdate()->first();
                [$s, $p, $c] = $this->resources($http);
                $service = new MetadataService();
                if (!hash_equals($claims['fingerprint'], $service->fingerprint($service->snapshot($s, $p)))) { throw new Failure('conflict', 409); }
                $changed = $service->apply($s, $p, $c, $http->input());
                DB::afterCommit(fn () => event(new MetadataChanged($s)));
                return $changed;
            });
            // No metadata, PDF text, emails, request body, or paths in the audit record.
            error_log('pdfMetadata applied ' . json_encode(['submissionId' => $s->getId(), 'publicationId' => $p->getId(), 'userId' => $this->getRequest()->getUser()->getId(), 'changed' => $changed]));
            return ['saved' => true];
        });
    }

    private function respond(callable $action): JsonResponse
    {
        try { return response()->json($action())->header('Cache-Control', 'no-store'); }
        catch (Failure $e) {
            return response()->json(['error' => $e->reason, 'message' => __('plugins.generic.pdfMetadata.' . $e->reason), 'details' => $e->details], $e->status)->header('Cache-Control', 'no-store');
        } catch (\Throwable $e) {
            // Exception class is sufficient for diagnostics, without leaking PDF content.
            error_log('pdfMetadata failure ' . get_class($e));
            return response()->json(['error' => 'error', 'message' => __('plugins.generic.pdfMetadata.error')], 500)->header('Cache-Control', 'no-store');
        }
    }
}
