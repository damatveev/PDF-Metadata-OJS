<?php
namespace APP\plugins\generic\pdfMetadata\classes;

use APP\facades\Repo;
use PKP\doi\Doi;
use PKP\security\Role;
use PKP\userGroup\UserGroup;

/** OJS adapter. All writes are validated and invoked within the controller transaction. */
class MetadataService
{
    public function snapshot($submission, $publication): array
    {
        $authors = [];
        foreach (Repo::author()->getCollector()->filterByPublicationIds([$publication->getId()])->getMany() as $author) {
            $affiliations = [];
            foreach ($author->getAffiliations() as $affiliation) {
                $affiliations[] = ['id' => $affiliation->getId(), 'name' => $affiliation->getName(), 'ror' => $affiliation->getRor()];
            }
            $authors[] = [
                'id' => $author->getId(), 'givenName' => $author->getData('givenName'),
                'familyName' => $author->getData('familyName'), 'email' => $author->getData('email'),
                'userGroupId' => $author->getData('userGroupId'), 'seq' => $author->getData('seq'),
                'affiliations' => $affiliations,
            ];
        }
        usort($authors, fn ($a, $b) => $a['id'] <=> $b['id']);
        $doi = $publication->getData('doiId') ? Repo::doi()->get($publication->getData('doiId')) : null;
        $data = [
            'publicationId' => $publication->getId(), 'status' => $publication->getData('status'),
            'latestPublicationId' => $submission->getLatestPublication()->getId(),
            'primaryLocale' => $submission->getData('locale'), 'authors' => $authors,
            'doi' => $doi?->getData('doi') ?? '', 'doiId' => $publication->getData('doiId'),
            'doiStatus' => $doi?->getData('status'),
        ];
        foreach (['title', 'abstract', 'keywords', 'citationsRaw'] as $key) { $data[$key] = $publication->getData($key); }
        // OJS 3.5 lazily exposes references as a Stringable object, not a JSON scalar.
        $data['citationsRaw'] = (string) ($data['citationsRaw'] ?? '');
        $data['dateModified'] = $publication->getData('dateModified');
        return $data;
    }

    public function fingerprint(array $data): string
    {
        $sort = function (&$value) use (&$sort) {
            if (!is_array($value)) { return; }
            if (!array_is_list($value)) { ksort($value); }
            foreach ($value as &$child) { $sort($child); }
        };
        $sort($data);
        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    }

    public function groups(int $contextId): array
    {
        return UserGroup::withContextIds([$contextId])->withRoleIds([Role::ROLE_ID_AUTHOR])->get()
            ->map(fn ($group) => ['id' => $group->id, 'name' => $group->getLocalizedData('name')])->values()->all();
    }

    public function apply($submission, $publication, $context, array $body): array
    {
        $locale = $body['locale'] ?? null;
        $allowed = $submission->getPublicationLanguages($context->getSupportedSubmissionMetadataLocales());
        if (!is_string($locale) || !in_array($locale, $allowed, true)) { throw new Failure('validation'); }
        $primary = $submission->getData('locale');
        $fields = $body['fields'] ?? [];
        $operations = $body['authors'] ?? [];
        if (!is_array($fields) || !is_array($operations) || count($operations) > 40 || array_diff(array_keys($fields), ['title', 'abstract', 'keywords', 'doi', 'references'])) {
            throw new Failure('validation');
        }
        if (!$fields && !$operations) { throw new Failure('selectFields'); }
        $params = [];
        foreach (['title' => 1000, 'abstract' => 30000] as $key => $limit) {
            if (array_key_exists($key, $fields)) {
                $value = $this->plain($fields[$key], $limit);
                if ($key === 'abstract') { $value = nl2br(htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')); }
                $params[$key] = array_replace($publication->getData($key) ?? [], [$locale => $value]);
            }
        }
        if (array_key_exists('keywords', $fields)) {
            if (!is_array($fields['keywords']) || count($fields['keywords']) > 40) { throw new Failure('validation'); }
            $keywords = array_values(array_unique(array_map(fn ($s) => $this->plain($s, 255), $fields['keywords'])));
            if (!$keywords) { throw new Failure('validation'); }
            $params['keywords'] = array_replace($publication->getData('keywords') ?? [], [$locale => $keywords]);
        }
        if (array_key_exists('references', $fields)) {
            $params['citationsRaw'] = $this->plain($fields['references'], 100000);
        }
        $doiProps = null;
        if (array_key_exists('doi', $fields)) {
            $doiValue = $this->plain($fields['doi'], 255);
            if ($publication->getData('doiId')) { throw new Failure('doiExists', 409); }
            if (!preg_match('~^10\.\d{4,9}/[-._;()/:A-Z0-9]+$~iD', $doiValue)) { throw new Failure('validation'); }
            if (!in_array('publication', (array) $context->getData('enabledDoiTypes'), true)) { throw new Failure('validation'); }
            $doiProps = ['doi' => $doiValue, 'contextId' => $context->getId(), 'status' => Doi::STATUS_UNREGISTERED];
            $this->errors(Repo::doi()->validate(null, $doiProps));
        }
        $this->errors(Repo::publication()->validate($publication, $params + ['id' => $publication->getId()], $submission, $context));
        $groups = array_column($this->groups($context->getId()), 'id');
        $plans = [];
        $seen = [];
        foreach ($operations as $op) {
            if (!is_array($op) || array_diff(array_keys($op), ['id', 'action', 'givenName', 'familyName', 'email', 'userGroupId', 'affiliations'])) { throw new Failure('validation'); }
            $action = $op['action'] ?? '';
            if (!in_array($action, ['add', 'update', 'affiliations'], true)) { throw new Failure('validation'); }
            $author = null;
            if ($action !== 'add') {
                $id = filter_var($op['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                $author = $id ? Repo::author()->get($id, $publication->getId()) : null;
                if (!$author || isset($seen[$id])) { throw new Failure('validation'); }
                $seen[$id] = true;
            }
            $props = ['publicationId' => $publication->getId()];
            if ($action !== 'affiliations') {
                $props['givenName'] = array_replace($author?->getData('givenName') ?? [], [$primary => $this->plain($op['givenName'] ?? null, 255)]);
                $family = $this->plain($op['familyName'] ?? '', 255, true);
                $props['familyName'] = array_replace($author?->getData('familyName') ?? [], [$primary => $family]);
                $props['email'] = $this->plain($op['email'] ?? null, 254);
                if (!filter_var($props['email'], FILTER_VALIDATE_EMAIL)) { throw new Failure('validation'); }
                if ($action === 'add') {
                    $group = filter_var($op['userGroupId'] ?? null, FILTER_VALIDATE_INT);
                    if (!in_array($group, $groups, true)) { throw new Failure('validation'); }
                    $props['userGroupId'] = $group;
                    $props['includeInBrowse'] = true;
                }
                // Core validates journal-required fields too, e.g. competing interests.
                $validationProps = $props;
                if ($author && $context->getData('requireAuthorCompetingInterests')) {
                    $validationProps['competingInterests'] = $author->getData('competingInterests');
                }
                $this->errors(Repo::author()->validate($author, $validationProps, $submission, $context));
            }
            $affiliations = $author?->getAffiliations() ?? [];
            $names = array_map(fn ($aff) => mb_strtolower($aff->getLocalizedName($primary) ?? ''), $affiliations);
            $addNames = $op['affiliations'] ?? [];
            $newAffiliations = [];
            if (!is_array($addNames) || count($addNames) > 20) { throw new Failure('validation'); }
            if ($action === 'affiliations' && !$addNames) { throw new Failure('validation'); }
            foreach ($addNames as $name) {
                $name = $this->plain($name, 1000);
                if (in_array(mb_strtolower($name), $names, true)) { continue; }
                $affProps = ['name' => [$primary => $name]];
                $newAffiliations[] = $affProps;
                $names[] = mb_strtolower($name);
            }
            $plans[] = [$author, $props, $newAffiliations];
        }
        // Affiliations of new authors are validated once an authorId exists, in this same transaction.
        if ($doiProps) { $params['doiId'] = Repo::doi()->add(Repo::doi()->newDataObject($doiProps)); }
        if ($params) { Repo::publication()->edit($publication, $params); }
        foreach ($plans as [$author, $props, $newAffiliations]) {
            if ($author) { Repo::author()->edit($author, $props); $authorId = $author->getId(); }
            else { $authorId = Repo::author()->add(Repo::author()->newDataObject($props)); }
            foreach ($newAffiliations as $affProps) {
                $affProps['authorId'] = $authorId;
                $this->errors(Repo::affiliation()->validate(null, $affProps, $submission, $context));
                Repo::affiliation()->add(Repo::affiliation()->newDataObject($affProps));
            }
        }
        return ['fields' => array_keys($fields), 'authors' => count($plans)];
    }

    private function plain(mixed $value, int $max, bool $allowEmpty = false): string
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value) > $max || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value)) { throw new Failure('validation'); }
        $value = trim(strip_tags($value));
        if (!$allowEmpty && $value === '') { throw new Failure('validation'); }
        return $value;
    }

    private function errors(array $errors): void
    {
        if ($errors) { throw new Failure('validation', 422, $errors); }
    }
}
