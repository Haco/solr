<?php
namespace ApacheSolrForTypo3\Solr\IndexQueue;

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2009-2015 Ingo Renner <ingo@typo3.org>
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

use Apache_Solr_Document;
use Apache_Solr_Response;
use ApacheSolrForTypo3\Solr\ConnectionManager;
use ApacheSolrForTypo3\Solr\Domain\Site\SiteRepository;
use ApacheSolrForTypo3\Solr\Domain\Variants\IdBuilder;
use ApacheSolrForTypo3\Solr\FieldProcessor\Service;
use ApacheSolrForTypo3\Solr\NoSolrConnectionFoundException;
use ApacheSolrForTypo3\Solr\SolrService;
use ApacheSolrForTypo3\Solr\System\Logging\SolrLogManager;
use ApacheSolrForTypo3\Solr\Util;
use TYPO3\CMS\Backend\Configuration\TranslationConfigurationProvider;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Page\PageRepository;

/**
 * A general purpose indexer to be used for indexing of any kind of regular
 * records like tt_news, tt_address, and so on.
 * Specialized indexers can extend this class to handle advanced stuff like
 * category resolution in tt_news or file indexing.
 *
 * @author Ingo Renner <ingo@typo3.org>
 */
class Indexer extends AbstractIndexer
{

    # TODO change to singular $document instead of plural $documents

    /**
     * A Solr service instance to interact with the Solr server
     *
     * @var SolrService
     */
    protected $solr;

    /**
     * @var ConnectionManager
     */
    protected $connectionManager;

    /**
     * Holds options for a specific indexer
     *
     * @var array
     */
    protected $options = [];

    /**
     * To log or not to log... #Shakespeare
     *
     * @var bool
     */
    protected $loggingEnabled = false;

    /**
     * @var IdBuilder
     */
    protected $variantIdBuilder;

    /**
     * @var \ApacheSolrForTypo3\Solr\System\Logging\SolrLogManager
     */
    protected $logger = null;

    /**
     * Constructor
     *
     * @param array $options array of indexer options
     * @param IdBuilder $idBuilder
     */
    public function __construct(array $options = [], IdBuilder $idBuilder = null)
    {
        $this->logger = GeneralUtility::makeInstance(SolrLogManager::class, __CLASS__);
        $this->options = $options;
        $this->connectionManager = GeneralUtility::makeInstance(ConnectionManager::class);
        $this->variantIdBuilder = is_null($idBuilder) ? GeneralUtility::makeInstance(IdBuilder::class) : $idBuilder;
    }

    /**
     * Indexes an item from the indexing queue.
     *
     * @param Item $item An index queue item
     * @return bool returns true when indexed, false when not
     */
    public function index(Item $item)
    {
        $indexed = true;

        $this->type = $item->getType();
        $this->setLogging($item);

        $solrConnections = $this->getSolrConnectionsByItem($item);

        foreach ($solrConnections as $systemLanguageUid => $solrConnection) {
            $this->solr = $solrConnection;

            if (!$this->indexItem($item, $systemLanguageUid)) {
                /*
                 * A single language voting for "not indexed" should make the whole
                 * item count as being not indexed, even if all other languages are
                 * indexed.
                 * If there is no translation for a single language, this item counts
                 * as TRUE since it's not an error which that should make the item
                 * being reindexed during another index run.
                 */
                $indexed = false;
            }
        }

        return $indexed;
    }

    /**
     * Creates a single Solr Document for an item in a specific language.
     *
     * @param Item $item An index queue item to index.
     * @param int $language The language to use.
     * @return bool TRUE if item was indexed successfully, FALSE on failure
     */
    protected function indexItem(Item $item, $language = 0)
    {
        $itemIndexed = false;
        $documents = [];

        $itemDocument = $this->itemToDocument($item, $language);
        if (is_null($itemDocument)) {
            /*
             * If there is no itemDocument, this means there was no translation
             * for this record. This should not stop the current item to count as
             * being valid because not-indexing not-translated items is perfectly
             * fine.
             */
            return true;
        }

        $documents[] = $itemDocument;
        $documents = array_merge($documents, $this->getAdditionalDocuments($item, $language, $itemDocument));
        $documents = $this->processDocuments($item, $documents);
        $documents = $this->preAddModifyDocuments($item, $language, $documents);

        $response = $this->solr->addDocuments($documents);
        if ($response->getHttpStatus() == 200) {
            $itemIndexed = true;
        }

        $this->log($item, $documents, $response);

        return $itemIndexed;
    }

    /**
     * Gets the full item record.
     *
     * This general record indexer simply gets the record from the item. Other
     * more specialized indexers may provide more data for their specific item
     * types.
     *
     * @param Item $item The item to be indexed
     * @param int $language Language Id (sys_language.uid)
     * @return array|NULL The full record with fields of data to be used for indexing or NULL to prevent an item from being indexed
     */
    protected function getFullItemRecord(Item $item, $language = 0)
    {
        Util::initializeTsfe($item->getRootPageUid(), $language);

        $systemLanguageContentOverlay = $GLOBALS['TSFE']->sys_language_contentOL;
        $itemRecord = $this->getItemRecordOverlayed($item, $language, $systemLanguageContentOverlay);

        /*
         * Skip disabled records. This happens if the default language record
         * is hidden but a certain translation isn't. Then the default language
         * document appears here but must not be indexed.
         */
        if (!empty($GLOBALS['TCA'][$item->getType()]['ctrl']['enablecolumns']['disabled'])
            && $itemRecord[$GLOBALS['TCA'][$item->getType()]['ctrl']['enablecolumns']['disabled']]
        ) {
            $itemRecord = null;
        }

        /*
         * Skip translation mismatching records. Sometimes the requested language
         * doesn't fit the returned language. This might happen with content fallback
         * and is perfectly fine in general.
         * But if the requested language doesn't match the returned language and
         * the given record has no translation parent, the indexqueue_item most
         * probably pointed to a non-translated language record that is dedicated
         * to a very specific language. Now we have to avoid indexing this record
         * into all language cores.
         */
        $translationOriginalPointerField = 'l10n_parent';
        if (!empty($GLOBALS['TCA'][$item->getType()]['ctrl']['transOrigPointerField'])) {
            $translationOriginalPointerField = $GLOBALS['TCA'][$item->getType()]['ctrl']['transOrigPointerField'];
        }

        $languageField = $GLOBALS['TCA'][$item->getType()]['ctrl']['languageField'];
        if ($itemRecord[$translationOriginalPointerField] == 0
            && $systemLanguageContentOverlay != 1
            && !empty($languageField)
            && $itemRecord[$languageField] != $language
            && $itemRecord[$languageField] != '-1'
        ) {
            $itemRecord = null;
        }

        if (!is_null($itemRecord)) {
            $itemRecord['__solr_index_language'] = $language;
        }

        return $itemRecord;
    }

    /**
     * Returns the overlayed item record.
     *
     * @param Item $item
     * @param int $language
     * @param string|null $systemLanguageContentOverlay
     * @return array|mixed|null
     */
    protected function getItemRecordOverlayed(Item $item, $language, $systemLanguageContentOverlay)
    {
        $itemRecord = $item->getRecord();

        if ($language > 0) {
            $page = GeneralUtility::makeInstance(PageRepository::class);
            $page->init(false);
            $itemRecord = $page->getRecordOverlay($item->getType(), $itemRecord, $language, $systemLanguageContentOverlay);
        }

        if (!$itemRecord) {
            $itemRecord = null;
        }

        return $itemRecord;
    }

    /**
     * Gets the configuration how to process an item's fields for indexing.
     *
     * @param Item $item An index queue item
     * @param int $language Language ID
     * @throws \RuntimeException
     * @return array Configuration array from TypoScript
     */
    protected function getItemTypeConfiguration(Item $item, $language = 0)
    {
        $indexConfigurationName = $item->getIndexingConfigurationName();
        $fields = $this->getFieldConfigurationFromItemRecordPage($item, $language, $indexConfigurationName);
        if (count($fields) === 0) {
            $fields = $this->getFieldConfigurationFromItemRootPage($item, $language, $indexConfigurationName);
            if (count($fields) === 0) {
                throw new \RuntimeException('The item indexing configuration "' . $item->getIndexingConfigurationName() .
                    '" on root page uid ' . $item->getRootPageUid() . ' could not be found!', 1455530112);
            }
        }

        return $fields;
    }

    /**
     * The method retrieves the field configuration of the items record page id (pid).
     *
     * @param Item $item
     * @param integer $language
     * @param string $indexConfigurationName
     * @return array
     */
    protected function getFieldConfigurationFromItemRecordPage(Item $item, $language, $indexConfigurationName)
    {
        try {
            $solrConfiguration = Util::getSolrConfigurationFromPageId($item->getRecordPageId(), true, $language);
            return $solrConfiguration->getIndexQueueFieldsConfigurationByConfigurationName($indexConfigurationName, []);
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * The method returns the field configuration of the items root page id (uid of the related root page).
     *
     * @param Item $item
     * @param integer $language
     * @param string $indexConfigurationName
     * @return array
     */
    protected function getFieldConfigurationFromItemRootPage(Item $item, $language, $indexConfigurationName)
    {
        $solrConfiguration = Util::getSolrConfigurationFromPageId($item->getRootPageUid(), true, $language);
        if (empty($solrConfiguration->getIndexQueueAdditionalPageIdsByConfigurationName($indexConfigurationName))) {
            return [];
        }

        return $solrConfiguration->getIndexQueueFieldsConfigurationByConfigurationName($indexConfigurationName, []);
    }

    /**
     * Converts an item array (record) to a Solr document by mapping the
     * record's fields onto Solr document fields as configured in TypoScript.
     *
     * @param Item $item An index queue item
     * @param int $language Language Id
     * @return Apache_Solr_Document The Solr document converted from the record
     */
    protected function itemToDocument(Item $item, $language = 0)
    {
        $document = null;

        $itemRecord = $this->getFullItemRecord($item, $language);
        if (!is_null($itemRecord)) {
            $itemIndexingConfiguration = $this->getItemTypeConfiguration($item, $language);
            $document = $this->getBaseDocument($item, $itemRecord);
            $document = $this->addDocumentFieldsFromTyposcript($document, $itemIndexingConfiguration, $itemRecord);
        }

        return $document;
    }

    /**
     * Creates a Solr document with the basic / core fields set already.
     *
     * @param Item $item The item to index
     * @param array $itemRecord The record to use to build the base document
     * @return Apache_Solr_Document A basic Solr document
     */
    protected function getBaseDocument(Item $item, array $itemRecord)
    {
        $siteRepository = GeneralUtility::makeInstance(SiteRepository::class);
        $site = $siteRepository->getSiteByRootPageId($item->getRootPageUid());

        $document = GeneralUtility::makeInstance(Apache_Solr_Document::class);
        /* @var $document Apache_Solr_Document */

        // required fields
        $document->setField('id', Util::getDocumentId($item->getType(), $site->getRootPageId(), $itemRecord['uid']));
        $document->setField('type', $item->getType());
        $document->setField('appKey', 'EXT:solr');

        // site, siteHash
        $document->setField('site', $site->getDomain());
        $document->setField('siteHash', $site->getSiteHash());

        // uid, pid
        $document->setField('uid', $itemRecord['uid']);
        $document->setField('pid', $itemRecord['pid']);

        // variantId
        $variantId = $this->variantIdBuilder->buildFromTypeAndUid($item->getType(), $itemRecord['uid']);
        $document->setField('variantId', $variantId);

        // created, changed
        if (!empty($GLOBALS['TCA'][$item->getType()]['ctrl']['crdate'])) {
            $document->setField('created',
                $itemRecord[$GLOBALS['TCA'][$item->getType()]['ctrl']['crdate']]);
        }
        if (!empty($GLOBALS['TCA'][$item->getType()]['ctrl']['tstamp'])) {
            $document->setField('changed',
                $itemRecord[$GLOBALS['TCA'][$item->getType()]['ctrl']['tstamp']]);
        }

        // access, endtime
        $document->setField('access', $this->getAccessRootline($item));
        if (!empty($GLOBALS['TCA'][$item->getType()]['ctrl']['enablecolumns']['endtime'])
            && $itemRecord[$GLOBALS['TCA'][$item->getType()]['ctrl']['enablecolumns']['endtime']] != 0
        ) {
            $document->setField('endtime',
                $itemRecord[$GLOBALS['TCA'][$item->getType()]['ctrl']['enablecolumns']['endtime']]);
        }

        return $document;
    }

    /**
     * Generates an Access Rootline for an item.
     *
     * @param Item $item Index Queue item to index.
     * @return string The Access Rootline for the item
     */
    protected function getAccessRootline(Item $item)
    {
        $accessRestriction = '0';
        $itemRecord = $item->getRecord();

        // TODO support access restrictions set on storage page

        if (isset($GLOBALS['TCA'][$item->getType()]['ctrl']['enablecolumns']['fe_group'])) {
            $accessRestriction = $itemRecord[$GLOBALS['TCA'][$item->getType()]['ctrl']['enablecolumns']['fe_group']];

            if (empty($accessRestriction)) {
                // public
                $accessRestriction = '0';
            }
        }

        return 'r:' . $accessRestriction;
    }

    /**
     * Sends the documents to the field processing service which takes care of
     * manipulating fields as defined in the field's configuration.
     *
     * @param Item $item An index queue item
     * @param array $documents An array of Apache_Solr_Document objects to manipulate.
     * @return array Array of manipulated Apache_Solr_Document objects.
     */
    protected function processDocuments(Item $item, array $documents)
    {
        // needs to respect the TS settings for the page the item is on, conditions may apply
        $solrConfiguration = Util::getSolrConfigurationFromPageId($item->getRootPageUid());
        $fieldProcessingInstructions = $solrConfiguration->getIndexFieldProcessingInstructionsConfiguration();

        // same as in the FE indexer
        if (is_array($fieldProcessingInstructions)) {
            $service = GeneralUtility::makeInstance(Service::class);
            $service->processDocuments(
                $documents,
                $fieldProcessingInstructions
            );
        }

        return $documents;
    }

    /**
     * Allows third party extensions to provide additional documents which
     * should be indexed for the current item.
     *
     * @param Item $item The item currently being indexed.
     * @param int $language The language uid currently being indexed.
     * @param Apache_Solr_Document $itemDocument The document representing the item for the given language.
     * @return array An array of additional Apache_Solr_Document objects to index.
     */
    protected function getAdditionalDocuments(
        Item $item,
        $language,
        Apache_Solr_Document $itemDocument
    ) {
        $documents = [];

        if (is_array($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['solr']['IndexQueueIndexer']['indexItemAddDocuments'])) {
            foreach ($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['solr']['IndexQueueIndexer']['indexItemAddDocuments'] as $classReference) {
                if (!class_exists($classReference)) {
                    throw new \InvalidArgumentException('Class does not exits' . $classReference, 1490363487);
                }
                $additionalIndexer = GeneralUtility::makeInstance($classReference);
                if ($additionalIndexer instanceof AdditionalIndexQueueItemIndexer) {
                    $additionalDocuments = $additionalIndexer->getAdditionalItemDocuments($item,
                        $language, $itemDocument);

                    if (is_array($additionalDocuments)) {
                        $documents = array_merge($documents,
                            $additionalDocuments);
                    }
                } else {
                    throw new \UnexpectedValueException(
                        get_class($additionalIndexer) . ' must implement interface ' . AdditionalIndexQueueItemIndexer::class,
                        1326284551
                    );
                }
            }
        }
        return $documents;
    }

    /**
     * Provides a hook to manipulate documents right before they get added to
     * the Solr index.
     *
     * @param Item $item The item currently being indexed.
     * @param int $language The language uid of the documents
     * @param array $documents An array of documents to be indexed
     * @return array An array of modified documents
     */
    protected function preAddModifyDocuments(
        Item $item,
        $language,
        array $documents
    ) {
        if (is_array($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['solr']['IndexQueueIndexer']['preAddModifyDocuments'])) {
            foreach ($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['solr']['IndexQueueIndexer']['preAddModifyDocuments'] as $classReference) {
                $documentsModifier = GeneralUtility::getUserObj($classReference);

                if ($documentsModifier instanceof PageIndexerDocumentsModifier) {
                    $documents = $documentsModifier->modifyDocuments($item,
                        $language, $documents);
                } else {
                    throw new \RuntimeException(
                        'The class "' . get_class($documentsModifier)
                        . '" registered as document modifier in hook
							preAddModifyDocuments must implement interface
							ApacheSolrForTypo3\Solr\IndexQueue\PageIndexerDocumentsModifier',
                        1309522677
                    );
                }
            }
        }

        return $documents;
    }

    // Initialization

    /**
     * Gets the Solr connections applicable for an item.
     *
     * The connections include the default connection and connections to be used
     * for translations of an item.
     *
     * @param Item $item An index queue item
     * @return array An array of ApacheSolrForTypo3\Solr\SolrService connections, the array's keys are the sys_language_uid of the language of the connection
     */
    protected function getSolrConnectionsByItem(Item $item)
    {
        $solrConnections = [];

        $pageId = $item->getRootPageUid();
        if ($item->getType() == 'pages') {
            $pageId = $item->getRecordUid();
        }

        // Solr configurations possible for this item
        $site = $item->getSite();
        $solrConfigurationsBySite = $this->connectionManager->getConfigurationsBySite($site);

        $siteLanguages = [];
        foreach ($solrConfigurationsBySite as $solrConfiguration) {
            $siteLanguages[] = $solrConfiguration['language'];
        }

        $defaultLanguageUid = $this->getDefaultLanguageUid($item, $site->getRootPage(), $siteLanguages);

        $translationOverlays = $this->getTranslationOverlaysForPage(
            $pageId,
            $site->getSysLanguageMode($defaultLanguageUid)
        );

        foreach ($translationOverlays as $key => $translationOverlay) {
            if (!in_array($translationOverlay['sys_language_uid'],
                $siteLanguages)
            ) {
                unset($translationOverlays[$key]);
            }
        }

        $defaultConnection = $this->connectionManager->getConnectionByPageId($pageId);
        $translationConnections = $this->getConnectionsForIndexableLanguages($translationOverlays);

        if ($defaultLanguageUid == 0) {
            $solrConnections[0] = $defaultConnection;
        }

        foreach ($translationConnections as $systemLanguageUid => $solrConnection) {
            $solrConnections[$systemLanguageUid] = $solrConnection;
        }

        return $solrConnections;
    }

    /**
     * @param Item $item An index queue item
     * @param array $rootPage
     * @param array $siteLanguages
     *
     * @return int
     * @throws \Apache_Solr_Exception
     */
    private function getDefaultLanguageUid(Item $item, array $rootPage, array $siteLanguages)
    {
        $defaultLanguageUid = 0;
        if (($rootPage['l18n_cfg'] & 1) == 1 && count($siteLanguages) > 1) {
            unset($siteLanguages[array_search('0', $siteLanguages)]);
            $defaultLanguageUid = $siteLanguages[min(array_keys($siteLanguages))];
        } elseif (($rootPage['l18n_cfg'] & 1) == 1 && count($siteLanguages) == 1) {
            throw new \Apache_Solr_Exception('Root page ' .
                                             $item->getRootPageUid() .
                                             ' is set to hide default translation, but no other language is configured!');
        }

        return $defaultLanguageUid;
    }

    /**
     * Finds the alternative page language overlay records for a page based on
     * the sys_language_mode.
     *
     * Possible Language Modes:
     * 1) content_fallback --> all languages
     * 2) strict --> available languages with page overlay
     * 3) ignore --> available languages with page overlay
     * 4) unknown mode or blank --> all languages
     *
     * @param int $pageId Page ID.
     * @param string $languageMode
     * @return array An array of translation overlays (or fake overlays) found for the given page.
     */
    protected function getTranslationOverlaysForPage($pageId, $languageMode)
    {
        $translationOverlays = [];
        $pageId = intval($pageId);

        $languageModes = ['content_fallback', 'strict', 'ignore'];
        $hasOverlayMode = in_array($languageMode, $languageModes,
            true);
        $isContentFallbackMode = ($languageMode === 'content_fallback');

        if ($hasOverlayMode && !$isContentFallbackMode) {
            $translationOverlays = $GLOBALS['TYPO3_DB']->exec_SELECTgetRows(
                'pid, sys_language_uid',
                'pages_language_overlay',
                'pid = ' . $pageId
                . BackendUtility::deleteClause('pages_language_overlay')
                . BackendUtility::BEenableFields('pages_language_overlay')
            );
        } else {
            // ! If no sys_language_mode is configured, all languages will be indexed !
            $languages = $this->getSystemLanguages();
            foreach ($languages as $language) {
                if ($language['uid'] <= 0) {
                    continue;
                }
                $translationOverlays[] = [
                    'pid' => $pageId,
                    'sys_language_uid' => $language['uid'],
                ];
            }
        }

        return $translationOverlays;
    }

    /**
     * Returns an array of system languages.
     *
     * @return array
     */
    protected function getSystemLanguages()
    {
        return GeneralUtility::makeInstance(TranslationConfigurationProvider::class)->getSystemLanguages();
    }

    /**
     * Checks for which languages connections have been configured and returns
     * these connections.
     *
     * @param array $translationOverlays An array of translation overlays to check for configured connections.
     * @return array An array of ApacheSolrForTypo3\Solr\SolrService connections.
     */
    protected function getConnectionsForIndexableLanguages(
        array $translationOverlays
    ) {
        $connections = [];

        foreach ($translationOverlays as $translationOverlay) {
            $pageId = $translationOverlay['pid'];
            $languageId = $translationOverlay['sys_language_uid'];

            try {
                $connection = $this->connectionManager->getConnectionByPageId($pageId,
                    $languageId);
                $connections[$languageId] = $connection;
            } catch (NoSolrConnectionFoundException $e) {
                // ignore the exception as we seek only those connections
                // actually available
            }
        }

        return $connections;
    }

    // Utility methods

    // FIXME extract log() and setLogging() to ApacheSolrForTypo3\Solr\IndexQueue\AbstractIndexer
    // FIXME extract an interface Tx_Solr_IndexQueue_ItemInterface

    /**
     * Enables logging dependent on the configuration of the item's site
     *
     * @param Item $item An item being indexed
     * @return    void
     */
    protected function setLogging(Item $item)
    {
        $solrConfiguration = Util::getSolrConfigurationFromPageId($item->getRootPageUid());
        $this->loggingEnabled = $solrConfiguration->getLoggingIndexingQueueOperationsByConfigurationNameWithFallBack(
            $item->getIndexingConfigurationName()
        );
    }

    /**
     * Logs the item and what document was created from it
     *
     * @param Item $item The item that is being indexed.
     * @param array $itemDocuments An array of Solr documents created from the item's data
     * @param Apache_Solr_Response $response The Solr response for the particular index document
     */
    protected function log(
        Item $item,
        array $itemDocuments,
        Apache_Solr_Response $response
    ) {
        if (!$this->loggingEnabled) {
            return;
        }

        $message = 'Index Queue indexing ' . $item->getType() . ':'
            . $item->getRecordUid() . ' - ';

        // preparing data
        $documents = [];
        foreach ($itemDocuments as $document) {
            $documents[] = (array)$document;
        }

        $logData = [
            'item' => (array)$item,
            'documents' => $documents,
            'response' => (array)$response
        ];

        if ($response->getHttpStatus() == 200) {
            $severity = SolrLogManager::NOTICE;
            $message .= 'Success';
        } else {
            $severity = SolrLogManager::ERROR;
            $message .= 'Failure';

            $logData['status'] = $response->getHttpStatus();
            $logData['status message'] = $response->getHttpStatusMessage();
        }

        $this->logger->log(
            $severity,
            $message,
            $logData
        );
    }
}
