<?php

namespace ApacheSolrForTypo3\Solr\Tests\Unit\Domain\Index;

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2017- Timo Hund <timo.hund@dkd.de>
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

use ApacheSolrForTypo3\Solr\Domain\Index\Queue\RecordMonitor\Helper\ConfigurationAwareRecordService;
use ApacheSolrForTypo3\Solr\Domain\Index\Queue\RecordMonitor\Helper\RootPageResolver;
use ApacheSolrForTypo3\Solr\System\Cache\TwoLevelCache;
use ApacheSolrForTypo3\Solr\Tests\Unit\UnitTest;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * @author Timo Hund <timo.hund@dkd.de>
 */
class RootPageResolverTest extends UnitTest
{
    /**
     * @var TwoLevelCache
     */
    protected $cacheMock;

    /**
     * @var ConfigurationAwareRecordService
     */
    protected $recordServiceMock;

    /**
     * @var RootPageResolver
     */
    protected $rootPageResolver;

    /**
     * @return void
     */
    protected function setUp()
    {
        $this->fakeDisabledCache();

        $this->recordServiceMock = $this->getDumbMock(ConfigurationAwareRecordService::class);

        /** @var $rootPageResolver RootPageResolver */
        $this->rootPageResolver = $this->getMockBuilder(RootPageResolver::class)
            ->setConstructorArgs([$this->recordServiceMock, $this->cacheMock])
            ->setMethods(['getIsRootPageId','getAlternativeSiteRootPagesIds', 'getRootPageIdByTableAndUid', 'getRecordPageId'])->getMock();
    }

    /**
     * @test
     */
    public function getResponsibleRootPageIdsMergesRootLineAndTypoScriptReferences()
    {
        $this->rootPageResolver->expects($this->once())->method('getRootPageIdByTableAndUid')->will($this->returnValue(222));
        $this->rootPageResolver->expects($this->once())->method('getRecordPageId')->will($this->returnValue(111));

        $this->rootPageResolver->expects($this->once())->method('getIsRootPageId')->will($this->returnValue(true));
        $this->rootPageResolver->expects($this->once())->method('getAlternativeSiteRootPagesIds')->will($this->returnValue([333,444]));

        $resolvedRootPages = $this->rootPageResolver->getResponsibleRootPageIds('pages', 41);

        $message = 'Root page resolver did not retrieve and merge root page ids from root line and typoscript references';
        $this->assertEquals([222,333,444], $resolvedRootPages, $message);
    }

    /**
     * @test
     */
    public function getResponsibleRootPageIdsIgnoresPageFromRootLineThatIsNoSiteRoot()
    {
        $this->rootPageResolver->expects($this->once())->method('getRootPageIdByTableAndUid')->will($this->returnValue(222));
        $this->rootPageResolver->expects($this->once())->method('getRecordPageId')->will($this->returnValue(111));

        $this->rootPageResolver->expects($this->once())->method('getIsRootPageId')->will($this->returnValue(false));
        $this->rootPageResolver->expects($this->once())->method('getAlternativeSiteRootPagesIds')->will($this->returnValue([333,444]));

        $resolvedRootPages = $this->rootPageResolver->getResponsibleRootPageIds('pages', 41);

        $message = 'Root page resolver should only return rootPageIds from references';
        $this->assertEquals([333,444], $resolvedRootPages, $message);
    }

    /**
     * @test
     */
    public function getIsRootPageIdWithPageIdZero() {
        $rootPageResolver = GeneralUtility::makeInstance(RootPageResolver::class);
        $rootPage = $rootPageResolver->getIsRootPageId(0);

        $this->assertEquals(false, $rootPage);
    }

    /**
     * @test
     */
    public function getIsRootPageWithPageIdMinusOne() {
        $rootPageResolver = GeneralUtility::makeInstance(RootPageResolver::class);
        $rootPage = $rootPageResolver->getIsRootPageId(-1);

        $this->assertEquals(false, $rootPage);
    }

    /**
     * @test
     */
    public function getIsRootPageIdWithUnknownPageId() {
        $rootPageResolver = GeneralUtility::makeInstance(RootPageResolver::class);

        $this->setExpectedException(\InvalidArgumentException::class);
        $rootPageResolver->getIsRootPageId(42);
    }

    /**
     * @return void
     */
    protected function fakeDisabledCache()
    {
        $this->cacheMock = $this->getDumbMock(TwoLevelCache::class);
        $this->cacheMock->method('get')->will($this->returnValue(false));
    }


}