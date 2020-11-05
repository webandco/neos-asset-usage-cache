<?php
namespace Webandco\AssetUsageCache\Domain\Strategy;

/*
 * This file is part of the Neos.Neos package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\ContentRepository\Domain\Model\NodeData;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Media\Domain\Model\AssetInterface;
use Neos\Media\Domain\Strategy\AssetUsageStrategyInterface;
use Neos\Neos\Domain\Model\Dto\AssetUsageInNodeProperties;
use Webandco\AssetUsageCache\Service\AssetUsageService;

/**
 * @Flow\Scope("singleton")
 */
class AssetUsageCacheStrategy implements AssetUsageStrategyInterface
{
    /**
     * @Flow\Inject
     * @var AssetUsageService
     */
    protected $assetUsageService;

    /**
     * @Flow\Inject
     * @var PersistenceManagerInterface
     */
    protected $persistenceManager;

    /**
     * Returns true if the asset is used.
     *
     * @param AssetInterface $asset
     * @return boolean
     */
    public function isInUse(AssetInterface $asset)
    {
        return 0 < $this->getUsageCount($asset);
    }

    /**
     * Returns the total count of usages found.
     *
     * @param AssetInterface $asset
     * @return integer
     */
    public function getUsageCount(AssetInterface $asset)
    {
        // The call to getUsageReferences() is needed because
        // it makes sure, that the related node really exists and old deleted
        // nodes are just ignored in case the cache is somehow incosistent in the sense
        // that deleted nodes are still in the cache
        // This can happen if a users workspace is published
        return count($this->getUsageReferences($asset));
    }

    /**
     * Returns an array of usage reference objects.
     *
     * @param AssetInterface $asset
     * @return array<\Neos\Neos\Domain\Model\Dto\AssetUsageInNodeProperties>
     * @throws \Neos\ContentRepository\Exception\NodeConfigurationException
     */
    public function getUsageReferences(AssetInterface $asset)
    {
        $relatedNodes = array_map(function (NodeData $relatedNodeData) use ($asset) {
            return new AssetUsageInNodeProperties(
                $asset,
                $relatedNodeData->getIdentifier(),
                $relatedNodeData->getWorkspace()->getName(),
                $relatedNodeData->getDimensionValues(),
                $relatedNodeData->getNodeType()->getName()
            );
        }, $this->assetUsageService->getRelatedNodes($asset));

        return $relatedNodes;
    }
}
