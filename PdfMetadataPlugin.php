<?php
/** SPDX-License-Identifier: GPL-3.0-or-later */
namespace APP\plugins\generic\pdfMetadata;

use APP\core\Application;
use APP\template\TemplateManager;
use APP\plugins\generic\pdfMetadata\classes\MetadataController;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\security\Role;

class PdfMetadataPlugin extends GenericPlugin
{
    public function register($category, $path, $mainContextId = null)
    {
        if (!parent::register($category, $path, $mainContextId)) {
            return false;
        }
        if (!$this->getEnabled($mainContextId)) {
            return true;
        }
        $version = Application::get()->getCurrentVersion()->getVersionString();
        if (!preg_match('/^3\.5\./', $version)) {
            return false;
        }
        Hook::add('APIHandler::endpoints::plugin', function ($hook, $args) {
            $args[0]->registerPluginApiControllers([new MetadataController()]);
            return Hook::CONTINUE;
        });
        Hook::add('TemplateManager::display', function ($hook, $args) {
            $request = Application::get()->getRequest();
            if (!$request->getContext() || !$request->getUser()) {
                return Hook::CONTINUE;
            }
            if (!$request->getUser()->hasRole([Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR], $request->getContext()->getId())) {
                return Hook::CONTINUE;
            }
            $manager = $args[0];
            $base = $request->getBaseUrl() . '/' . $this->getPluginPath();
            $manager->addJavaScript('pdfMetadata', $base . '/js/pdfMetadata.js', [
                'contexts' => ['backend'], 'priority' => TemplateManager::STYLE_SEQUENCE_LATE + 2,
            ]);
            $manager->addJavaScript('submissionMetadata', $base . '/js/submissionMetadata.js', [
                'contexts' => ['backend'], 'priority' => TemplateManager::STYLE_SEQUENCE_LATE + 3,
            ]);
            $manager->addStyleSheet('pdfMetadata', $base . '/styles/pdfMetadata.css', ['contexts' => ['backend']]);
            $labels = [];
            foreach (['name', 'description', 'load', 'extract', 'apply', 'file', 'locale', 'current', 'proposed',
                'select', 'title', 'abstract', 'keywords', 'doi', 'references', 'authors', 'affiliations',
                'givenName', 'familyName', 'email', 'addAuthor', 'skip', 'newAuthor', 'mapping', 'group',
                'busy', 'saved', 'review', 'empty', 'reload', 'source', 'confidence', 'authorHelp',
                'affiliationHelp', 'error', 'warning', 'text', 'noText', 'partial', 'unavailable',
                'invalidPdf', 'limit', 'expired', 'conflict', 'forbidden', 'validation', 'doiExists',
                'configuration', 'selectFields', 'issueWorkspace', 'issueWorkspaceDescription', 'openWorkspace',
                'uploadIssue', 'issuePdf', 'documentPages', 'startPage', 'endPage', 'extractRange', 'reviewDraft',
                'section', 'pages', 'subtitle', 'preferredPublicName', 'orcid', 'country', 'url', 'userGroup',
                'saveReview', 'reviewedArticles', 'ready', 'notReady', 'edit', 'remove', 'exportJson', 'clearWorkspace',
                'confirmClear', 'unassignedAffiliations', 'addReviewAuthor', 'close', 'uploadHelp', 'workspaceEmpty',
                'rangeHelp', 'rawText', 'issueUploaded', 'articleSaved', 'schemaNote', 'status', 'issueAuthorHelp'] as $key) {
                $labels[$key] = __('plugins.generic.pdfMetadata.' . $key);
            }
            $manager->addJavaScript('pdfMetadataConfig', 'window.pdfMetadataConfig = ' . json_encode([
                'api' => $request->getDispatcher()->url($request, Application::ROUTE_API, $request->getContext()->getPath(), 'pdf-metadata'),
                'csrf' => $request->getSession()->token(), 'labels' => $labels,
            ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) . ';', [
                'inline' => true, 'contexts' => ['backend'], 'priority' => TemplateManager::STYLE_SEQUENCE_LATE + 1,
            ]);
            return Hook::CONTINUE;
        });
        return true;
    }

    public function getName() { return 'pdfmetadataplugin'; }
    public function getDisplayName() { return __('plugins.generic.pdfMetadata.name'); }
    public function getDescription() { return __('plugins.generic.pdfMetadata.description'); }
}
