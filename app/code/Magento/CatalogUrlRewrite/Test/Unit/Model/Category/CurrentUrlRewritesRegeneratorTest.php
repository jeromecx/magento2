<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\CatalogUrlRewrite\Test\Unit\Model\Category;

use Magento\Catalog\Model\Category;
use Magento\CatalogUrlRewrite\Model\Category\CurrentUrlRewritesRegenerator;
use Magento\CatalogUrlRewrite\Model\CategoryUrlPathGenerator;
use Magento\CatalogUrlRewrite\Model\CategoryUrlRewriteGenerator;
use Magento\CatalogUrlRewrite\Model\Map\UrlRewriteFinder;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\UrlRewrite\Model\MergeDataProvider;
use Magento\UrlRewrite\Model\MergeDataProviderFactory;
use Magento\UrlRewrite\Model\OptionProvider;
use Magento\UrlRewrite\Service\V1\Data\UrlRewrite;
use Magento\UrlRewrite\Service\V1\Data\UrlRewriteFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class CurrentUrlRewritesRegeneratorTest extends TestCase
{
    /** @var CurrentUrlRewritesRegenerator */
    private $currentUrlRewritesRegenerator;

    /** @var CategoryUrlPathGenerator|MockObject */
    private $categoryUrlPathGenerator;

    /** @var Category|MockObject */
    private $category;

    /** @var UrlRewriteFactory|MockObject */
    private $urlRewriteFactory;

    /** @var UrlRewrite|MockObject */
    private $urlRewrite;

    /** @var MockObject */
    private $mergeDataProvider;

    /** @var MockObject */
    private $urlRewriteFinder;

    protected function setUp(): void
    {
        $this->urlRewriteFactory = $this->getMockBuilder(UrlRewriteFactory::class)
            ->setMethods(['create'])
            ->disableOriginalConstructor()
            ->getMock();
        $this->urlRewrite = $this->getMockBuilder(UrlRewrite::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->category = $this->getMockBuilder(Category::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->categoryUrlPathGenerator = $this->getMockBuilder(
            CategoryUrlPathGenerator::class
        )->disableOriginalConstructor()
            ->getMock();
        $this->urlRewriteFinder = $this->getMockBuilder(UrlRewriteFinder::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->urlRewriteFactory->expects($this->once())->method('create')
            ->willReturn($this->urlRewrite);
        $mergeDataProviderFactory = $this->createPartialMock(
            MergeDataProviderFactory::class,
            ['create']
        );
        $this->mergeDataProvider = new MergeDataProvider();
        $mergeDataProviderFactory->expects($this->once())->method('create')->willReturn($this->mergeDataProvider);

        $this->currentUrlRewritesRegenerator = (new ObjectManager($this))->getObject(
            CurrentUrlRewritesRegenerator::class,
            [
                'categoryUrlPathGenerator' => $this->categoryUrlPathGenerator,
                'urlRewriteFactory' => $this->urlRewriteFactory,
                'mergeDataProviderFactory' => $mergeDataProviderFactory,
                'urlRewriteFinder' => $this->urlRewriteFinder
            ]
        );
    }

    public function testIsAutogeneratedWithoutSaveRewriteHistory()
    {
        $this->urlRewriteFinder->expects($this->once())->method('findAllByData')
            ->willReturn($this->getCurrentRewritesMocks([[UrlRewrite::IS_AUTOGENERATED => 1]]));
        $this->category->expects($this->once())->method('getData')->with('save_rewrites_history')
            ->willReturn(false);

        $this->assertEquals(
            [],
            $this->currentUrlRewritesRegenerator->generate('store_id', $this->category, $this->category)
        );
    }

    public function testSkipGenerationForAutogenerated()
    {
        $this->urlRewriteFinder->expects($this->once())->method('findAllByData')
            ->willReturn(
                $this->getCurrentRewritesMocks(
                    [
                        [UrlRewrite::IS_AUTOGENERATED => 1, UrlRewrite::REQUEST_PATH => 'same-path'],
                    ]
                )
            );
        $this->category->expects($this->once())->method('getData')->with('save_rewrites_history')
            ->willReturn(true);
        $this->categoryUrlPathGenerator->expects($this->once())->method('getUrlPathWithSuffix')
            ->willReturn('same-path');

        $this->assertEquals(
            [],
            $this->currentUrlRewritesRegenerator->generate('store_id', $this->category, $this->category)
        );
    }

    public function testIsAutogenerated()
    {
        $requestPath = 'autogenerated.html';
        $targetPath = 'some-path.html';
        $storeId = 2;
        $categoryId = 12;
        $this->urlRewriteFinder->expects($this->once())->method('findAllByData')
            ->willReturn(
                $this->getCurrentRewritesMocks(
                    [
                        [
                            UrlRewrite::REQUEST_PATH => $requestPath,
                            UrlRewrite::TARGET_PATH => 'custom-target-path',
                            UrlRewrite::STORE_ID => $storeId,
                            UrlRewrite::IS_AUTOGENERATED => 1,
                            UrlRewrite::METADATA => [],
                        ],
                    ]
                )
            );

        $this->category->method('getEntityId')->willReturn($categoryId);
        $this->category->expects($this->once())->method('getData')->with('save_rewrites_history')
            ->willReturn(true);
        $this->categoryUrlPathGenerator->expects($this->once())->method('getUrlPathWithSuffix')
            ->willReturn($targetPath);

        $this->prepareUrlRewriteMock($storeId, $categoryId, $requestPath, $targetPath, OptionProvider::PERMANENT, 0);

        $this->assertEquals(
            ['autogenerated.html_2' => $this->urlRewrite],
            $this->currentUrlRewritesRegenerator->generate($storeId, $this->category, $this->category)
        );
    }

    public function testSkipGenerationForCustom()
    {
        $this->urlRewriteFinder->expects($this->once())->method('findAllByData')
            ->willReturn(
                $this->getCurrentRewritesMocks(
                    [
                        [
                            UrlRewrite::IS_AUTOGENERATED => 0,
                            UrlRewrite::REQUEST_PATH => 'same-path',
                            UrlRewrite::REDIRECT_TYPE => 1,
                        ],
                    ]
                )
            );
        $this->categoryUrlPathGenerator->expects($this->once())->method('getUrlPathWithSuffix')
            ->willReturn('same-path');

        $this->assertEquals(
            [],
            $this->currentUrlRewritesRegenerator->generate('store_id', $this->category, $this->category)
        );
    }

    public function testGenerationForCustomWithoutTargetPathGeneration()
    {
        $storeId = 12;
        $categoryId = 123;
        $requestPath = 'generate-for-custom-without-redirect-type.html';
        $targetPath = 'custom-target-path.html';
        $description = 'description';
        $this->urlRewriteFinder->expects($this->once())->method('findAllByData')
            ->willReturn(
                $this->getCurrentRewritesMocks(
                    [
                        [
                            UrlRewrite::REQUEST_PATH => $requestPath,
                            UrlRewrite::TARGET_PATH => $targetPath,
                            UrlRewrite::REDIRECT_TYPE => 0,
                            UrlRewrite::IS_AUTOGENERATED => 0,
                            UrlRewrite::DESCRIPTION => $description,
                            UrlRewrite::METADATA => [],
                        ],
                    ]
                )
            );
        $this->categoryUrlPathGenerator->expects($this->never())->method('getUrlPathWithSuffix');
        $this->category->method('getEntityId')->willReturn($categoryId);
        $this->urlRewrite->expects($this->once())->method('setDescription')->with($description)->willReturnSelf();
        $this->prepareUrlRewriteMock($storeId, $categoryId, $requestPath, $targetPath, 0, 0);

        $this->assertEquals(
            ['generate-for-custom-without-redirect-type.html_12' => $this->urlRewrite],
            $this->currentUrlRewritesRegenerator->generate($storeId, $this->category, $this->category)
        );
    }

    public function testGenerationForCustomWithTargetPathGeneration()
    {
        $storeId = 12;
        $categoryId = 123;
        $requestPath = 'generate-for-custom-without-redirect-type.html';
        $targetPath = 'generated-target-path.html';
        $description = 'description';
        $this->urlRewriteFinder->expects($this->once())->method('findAllByData')
            ->willReturn(
                $this->getCurrentRewritesMocks(
                    [
                        [
                            UrlRewrite::REQUEST_PATH => $requestPath,
                            UrlRewrite::TARGET_PATH => 'custom-target-path.html',
                            UrlRewrite::REDIRECT_TYPE => 'code',
                            UrlRewrite::IS_AUTOGENERATED => 0,
                            UrlRewrite::DESCRIPTION => $description,
                            UrlRewrite::METADATA => [],
                        ],
                    ]
                )
            );
        $this->categoryUrlPathGenerator->method('getUrlPathWithSuffix')
            ->willReturn($targetPath);
        $this->category->method('getEntityId')->willReturn($categoryId);
        $this->urlRewrite->expects($this->once())->method('setDescription')->with($description)->willReturnSelf();
        $this->prepareUrlRewriteMock($storeId, $categoryId, $requestPath, $targetPath, 'code', 0);

        $this->assertEquals(
            ['generate-for-custom-without-redirect-type.html_12' => $this->urlRewrite],
            $this->currentUrlRewritesRegenerator->generate($storeId, $this->category, $this->category)
        );
    }

    /**
     * @param array $currentRewrites
     * @return array
     */
    protected function getCurrentRewritesMocks($currentRewrites)
    {
        $rewrites = [];
        foreach ($currentRewrites as $urlRewrite) {
            /** @var MockObject */
            $url = $this->getMockBuilder(UrlRewrite::class)
                ->disableOriginalConstructor()
                ->getMock();
            foreach ($urlRewrite as $key => $value) {
                $url
                    ->method('get' . str_replace('_', '', ucwords($key, '_')))
                    ->willReturn($value);
            }
            $rewrites[] = $url;
        }
        return $rewrites;
    }

    /**
     * @param mixed $storeId
     * @param mixed $categoryId
     * @param mixed $requestPath
     * @param mixed $targetPath
     * @param mixed $redirectType
     * @param int $isAutoGenerated
     */
    protected function prepareUrlRewriteMock(
        $storeId,
        $categoryId,
        $requestPath,
        $targetPath,
        $redirectType,
        $isAutoGenerated
    ) {
        $this->urlRewrite->method('setStoreId')->with($storeId)->willReturnSelf();
        $this->urlRewrite->method('setEntityId')->with($categoryId)->willReturnSelf();
        $this->urlRewrite->method('setEntityType')
            ->with(CategoryUrlRewriteGenerator::ENTITY_TYPE)->willReturnSelf();
        $this->urlRewrite->method('setRequestPath')->with($requestPath)->willReturnSelf();
        $this->urlRewrite->method('setTargetPath')->with($targetPath)->willReturnSelf();
        $this->urlRewrite->method('setIsAutogenerated')->with($isAutoGenerated)->willReturnSelf();
        $this->urlRewrite->method('setRedirectType')->with($redirectType)->willReturnSelf();
        $this->urlRewrite->method('setMetadata')->with([])->willReturnSelf();
        $this->urlRewrite->method('getTargetPath')->willReturn($targetPath);
        $this->urlRewrite->method('getRequestPath')->willReturn($requestPath);
        $this->urlRewrite->method('getStoreId')->willReturn($storeId);
        $this->urlRewriteFactory->method('create')->willReturn($this->urlRewrite);
    }
}
