<?php
/** Contract tests with in-memory repository doubles; NOT database integration tests. */
namespace PKP\security { class Role { const ROLE_ID_AUTHOR = 65536; } }
namespace PKP\doi { class Doi { const STATUS_UNREGISTERED = 1; } }
namespace PKP\userGroup {
    class UserGroup {
        public static function withContextIds($ids) { return new \GroupQuery(); }
    }
}
namespace APP\facades {
    class Repo {
        public static array $repos = [];
        public static function __callStatic($name, $args) { return self::$repos[$name]; }
    }
}
namespace {
    require __DIR__ . '/run.php';
    use APP\facades\Repo;
    use APP\plugins\generic\pdfMetadata\classes\MetadataService;
    class Obj {
        public function __construct(public array $_data = []) {}
        public function getId() { return $this->_data['id']; }
        public function getData($key) { return $this->_data[$key] ?? null; }
        public function getAffiliations() { return $this->getData('affiliations') ?? []; }
        public function getLocalizedName($locale) { return $this->_data['name'][$locale] ?? ''; }
        public function getName() { return $this->getData('name'); }
        public function getRor() { return $this->getData('ror'); }
        public function getPublicationLanguages(...$args) { return ['en', 'ru']; }
        public function getSupportedSubmissionMetadataLocales() { return ['en', 'ru']; }
        public function getLatestPublication() { return $GLOBALS['publication']; }
    }
    class GroupQuery {
        public array $items = [];
        public function withRoleIds($ids) { return $this; }
        public function get() { return $this; }
        public function map($fn) { $this->items = [['id' => 9, 'name' => 'Author']]; return $this; }
        public function values() { return $this; }
        public function all() { return $this->items; }
    }
    class RepoDouble {
        public array $objects = [], $writes = [], $validations = [];
        public function get($id, $publicationId = null) {
            $item = $this->objects[$id] ?? null;
            return $item && ($publicationId === null || $item->getData('publicationId') === $publicationId) ? $item : null;
        }
        public function validate(...$args) { $this->validations[] = $args[1]; return []; }
        public function edit($object, $params) { $this->writes[] = $params; $object->_data = array_replace($object->_data, $params); }
        public function newDataObject($params) { return new Obj($params); }
        public function add($object) { $id = count($this->objects) + 100; $object->_data['id'] = $id; $this->objects[$id] = $object; $this->writes[] = $object->_data; return $id; }
        public function getCollector() { return $this; }
        public function filterByPublicationIds($ids) { return $this; }
        public function getMany() { return array_values($this->objects); }
    }
    foreach (['publication', 'author', 'affiliation', 'doi'] as $key) { Repo::$repos[$key] = new RepoDouble(); }
    $publication = new Obj(['id' => 7, 'title' => ['en' => 'Old title', 'ru' => 'Старое название'], 'abstract' => ['en' => 'Original abstract'], 'keywords' => ['ru' => ['исходный']], 'citationsRaw' => new class implements \Stringable {public function __toString(): string {return 'Original references';}}]);
    $submission = new Obj(['id' => 3, 'locale' => 'en']);
    $context = new Obj(['id' => 2, 'enabledDoiTypes' => ['publication']]);
    $service = new MetadataService();
    $service->apply($submission, $publication, $context, ['locale' => 'en', 'fields' => ['title' => 'New title']]);
    check($publication->getData('title')['ru'] === 'Старое название', 'Adapter preserves other locales');
    check($publication->getData('abstract')['en'] === 'Original abstract', 'Adapter leaves unselected fields unchanged');
    check($service->snapshot($submission, $publication)['citationsRaw'] === 'Original references', 'Adapter materializes lazy references');
    $service->apply($submission, $publication, $context, ['locale' => 'en', 'fields' => ['abstract' => '<script>bad()</script> & text']]);
    check($publication->getData('abstract')['en'] === 'bad() &amp; text', 'Adapter strips markup and encodes abstract');
    check(fails(fn () => $service->apply($submission, $publication, $context, ['locale' => 'xx', 'fields' => ['title' => 'A']]), 'validation'), 'Adapter rejects foreign locale');
    check(fails(fn () => $service->apply($submission, $publication, $context, ['locale' => 'en', 'fields' => ['status' => 3]]), 'validation'), 'Adapter rejects mass assignment');
    check(fails(fn () => $service->apply($submission, $publication, $context, ['locale' => 'en', 'fields' => ['title' => '']]), 'validation'), 'Adapter rejects destructive empty values');
    $author = new Obj(['id' => 1, 'publicationId' => 7, 'givenName' => ['en' => 'Jane', 'ru' => 'Джейн'], 'familyName' => ['en' => 'Doe'], 'email' => 'jane@example.org', 'orcid' => 'https://orcid.org/0000-0000-0000-0001', 'affiliations' => [new Obj(['id' => 2, 'name' => ['en' => 'Old University']])]]);
    Repo::$repos['author']->objects[1] = $author;
    $op = ['id' => 1, 'action' => 'update', 'givenName' => 'Janet', 'familyName' => 'Doe', 'email' => 'jane@example.org', 'affiliations' => ['New Institute', 'Old University']];
    $service->apply($submission, $publication, $context, ['locale' => 'ru', 'authors' => [$op]]);
    check($author->getData('givenName') === ['en' => 'Janet', 'ru' => 'Джейн'], 'Author update uses primary locale and preserves translations');
    check($author->getData('orcid') === 'https://orcid.org/0000-0000-0000-0001', 'ORCID preserved');
    check(!isset(Repo::$repos['author']->validations[0]['orcid']), 'ORCID not sent to author update validation');
    check(count(Repo::$repos['affiliation']->writes) === 1, 'Only new institution appended');
    check(count($author->getAffiliations()) === 1, 'Existing affiliation not removed by author update');
    $bad = $op; $bad['id'] = 999;
    check(fails(fn () => $service->apply($submission, $publication, $context, ['locale' => 'en', 'authors' => [$bad]]), 'validation'), 'Reject unknown author mapping');
    $bad = $op; $bad['action'] = 'add'; $bad['userGroupId'] = 999;
    check(fails(fn () => $service->apply($submission, $publication, $context, ['locale' => 'en', 'authors' => [$bad]]), 'validation'), 'Reject foreign contributor role');
    $publication->_data['doiId'] = 5;
    check(fails(fn () => $service->apply($submission, $publication, $context, ['locale' => 'en', 'fields' => ['doi' => '10.5555/example']]), 'doiExists'), 'Existing DOI cannot be overwritten');
    echo "\n$count combined tests passed (repository doubles, no OJS database).\n";
}
