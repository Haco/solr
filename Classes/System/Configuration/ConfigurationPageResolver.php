<?php
namespace ApacheSolrForTypo3\Solr\System\Configuration;

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2010-2016 Timo Schmidt <timo.schmidt@dkd.de
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

use ApacheSolrForTypo3\Solr\System\Cache\TwoLevelCache;
use TYPO3\CMS\Core\Database\DatabaseConnection;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Page\PageRepository;

/**
 * This class is responsible to find the closest page id from the rootline where
 * a typoscript template is stored on.
 *
 * @package ApacheSolrForTypo3\Solr\System\Configuration
 * @author Timo Hund <timo.hund@dkd.de>
 */
class ConfigurationPageResolver
{
    /**
     * @var PageRepository
     */
    protected $pageRepository;

    /**
     * @var TwoLevelCache
     */
    protected $twoLevelCache;

    /**
     * @var TwoLevelCache
     */
    protected $runtimeCache;

    /**
     * ConfigurationPageResolver constructor.
     * @param PageRepository|null $pageRepository
     * @param TwoLevelCache|null $twoLevelCache
     */
    public function __construct(PageRepository $pageRepository = null, TwoLevelCache $twoLevelCache = null)
    {
        $this->pageRepository = isset($pageRepository) ? $pageRepository : GeneralUtility::makeInstance(PageRepository::class);
        $this->runtimeCache = isset($twoLevelCache) ? $twoLevelCache : GeneralUtility::makeInstance(TwoLevelCache::class, 'cache_runtime');
    }

    /**
     * This method fetches the rootLine and calculates the id of the closest template in the rootLine.
     * The result is stored in the runtime cache.
     *
     * @param integer $startPageId
     * @return integer
     */
    public function getClosestPageIdWithActiveTemplate($startPageId)
    {
        if ($startPageId === 0) {
            return 0;
        }

        $cacheId = 'ConfigurationPageResolver' . '_' . 'getClosestPageIdWithActiveTemplate' . '_' . $startPageId;
        $methodResult = $this->runtimeCache->get($cacheId);
        if (!empty($methodResult)) {
            return $methodResult;
        }

        $methodResult = $this->calculateClosestPageIdWithActiveTemplate($startPageId);
        $this->runtimeCache->set($cacheId, $methodResult);

        return $methodResult;
    }

    /**
     * This method fetches the rootLine and calculates the id of the closest template in the rootLine.
     *
     * @param integer $startPageId
     * @return int
     */
    protected function calculateClosestPageIdWithActiveTemplate($startPageId)
    {
        $rootLine = $this->pageRepository->getRootLine($startPageId);
        // when no rootline is present the startpage it's self is the closest page
        if (!is_array($rootLine)) {
            return $startPageId;
        }

        $closestPageIdWithTemplate = $this->getPageIdsWithTemplateInRootLineOrderedByDepth($rootLine);
        if ($closestPageIdWithTemplate === 0) {
            return $startPageId;
        }

        return (int)$closestPageIdWithTemplate;
    }

    /**
     * Retrieves the closest pageId with a template on and 0 when non is found.
     *
     * @param array $rootLine
     * @return int
     */
    protected function getPageIdsWithTemplateInRootLineOrderedByDepth($rootLine)
    {
        $extensionConfiguration = GeneralUtility::makeInstance(ExtensionConfiguration::class);
        $defaultRootPidFallback = $extensionConfiguration->getDefaultDomainRootPid();
       
        $rootLinePageIds = [0];
        
	foreach ($rootLine as $rootLineItem) {
            $rootLinePageIds[] = (int)$rootLineItem['uid'];
        }

	// return the default domain root pid, if not found in rootline - this makes sure that the current indexed page/folder loads the typoscript from the default domain root pid, if the current page/folder is not in the rootline of the domain root pid
	if ($defaultRootPidFallback > 0 && !in_array($defaultRootPidFallback, $rootLinePageIds)) return $defaultRootPidFallback;

        $pageIdsClause = implode(",", $rootLinePageIds);
        $where = 'pid IN (' . $pageIdsClause . ') AND deleted = 0 AND hidden = 0';
        $res = $this->getDatabaseConnection()->exec_SELECTgetRows('uid,pid', 'sys_template', $where);
        $firstTemplateRow = $res[0];
        return isset($firstTemplateRow['pid']) ? $firstTemplateRow['pid'] : 0;
    }

    /**
     * @return DatabaseConnection
     */
    protected function getDatabaseConnection()
    {
        return $GLOBALS['TYPO3_DB'];
    }
}
