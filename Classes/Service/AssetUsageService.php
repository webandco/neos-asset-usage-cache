<?php
namespace Webandco\AssetUsageCache\Service;

/*
 * This file is part of the Neos.Neos package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Neos\Cache\Frontend\VariableFrontend;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Model\Workspace;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\Doctrine\DataTypes\JsonArrayType;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Media\Domain\Model\AssetInterface;
use Neos\Media\Domain\Model\Image;
use Neos\Neos\Domain\Service\SiteService;

/**
 * @Flow\Scope("singleton")
 */
class AssetUsageService
{
    /**
     * @Flow\Inject
     * @var AssetCacheService
     */
    protected $assetCacheService;

    /**
     * @Flow\Inject
     * @var DatabaseService
     */
    protected $dbService;

    /**
     * Returns all nodes that use the asset in a node property.
     *
     * @param AssetInterface $asset
     * @return array
     */
    public function getRelatedNodes(AssetInterface $asset)
    {
        $poids = $this->getRelatedPOIDs($asset);

        $nodes = $this->dbService->getNodesByPOIDs($poids);
        return $nodes;
    }

    /**
     * Returns all nodes that use the asset in a node property.
     *
     * @param AssetInterface $asset
     * @return array
     */
    public function getRelatedPOIDs(AssetInterface $asset)
    {
        $identifiersToSearch = $this->dbService->getAssetIdentifiersIncludingVariants($asset);

        $cacheIsDisabled  = $this->assetCacheService->isDisabled();
        $cacheIsPopulated = $this->assetCacheService->isPopulated();

        $identifiersToNodes = [];
        $assetsToNodes      = [];

        if($cacheIsDisabled || !$cacheIsPopulated){
            $nodes = $this->dbService->queryAssetAndIdentifiers();
            foreach ($nodes as $poid => $properties) {
                $this->updateNodesLists($poid, $properties, $identifiersToNodes, $assetsToNodes);
            }
        }

        if(!$cacheIsDisabled){
            if(!$cacheIsPopulated){
                $this->assetCacheService->populateCacheByUUIDLists($identifiersToNodes, $assetsToNodes, true);
            }
            else{
                $this->assetCacheService->readUUIDListsFromCache($identifiersToSearch, $identifiersToNodes, $assetsToNodes);
            }
        }

        $finalPoids = $this->getPOIDsByAssetIdentifiers($identifiersToSearch, $identifiersToNodes, $assetsToNodes);

        return $finalPoids;
    }

    protected function updateNodesLists(string $poid, string $properties, &$identifiersToNodes, &$assetsToNodes){
        $uuidFinder = function(&$uuidToNodes, $regex, $poid, $properties){
            $matches            = [];
            if (preg_match_all($regex, $properties, $matches)) {
                foreach ($matches[1] as $uuid) {
                    if (!isset($uuidToNodes[$uuid])) {
                        $uuidToNodes[$uuid] = [];
                    }

                    if (!in_array($poid, $uuidToNodes[$uuid])) {
                        $uuidToNodes[$uuid][] = $poid;
                    }
                }
            }
        };

        $uuidFinder($identifiersToNodes, '/"__identifier": "([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})"/is', $poid, $properties);
        $uuidFinder($assetsToNodes, '/asset:\\\\\\/\\\\\\/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})"/is', $poid, $properties);
    }

    public function getPOIDsByAssetIdentifiers($identifiers, $assetsList, $identifiersList){
        $finalPoids = [];

        foreach($identifiers as $uuid){
            if (isset($assetsList[$uuid])) {
                foreach($assetsList[$uuid] as $poid) {
                    if(in_array($poid, $finalPoids)){
                        continue;
                    }
                    $finalPoids[] = $poid;
                }
            }

            if (isset($identifiersList[$uuid])) {
                foreach($identifiersList[$uuid] as $poid) {
                    if(in_array($poid, $finalPoids)){
                        continue;
                    }
                    $finalPoids[] = $poid;
                }
            }
        }

        return $finalPoids;
    }

    /**
     * Register a node change for a later cache flush. This method is triggered by a signal sent via ContentRepository's Node
     * model or the Neos Publishing Service.
     *
     * @param NodeInterface $node The node which has changed in some way
     * @param Workspace $targetWorkspace An optional workspace to flush
     * @return void
     * @throws \Neos\ContentRepository\Exception\NodeTypeNotFoundException
     */
    public function nodeAdded(NodeInterface $node): void
    {
        if(!$this->assetCacheService->isPopulated() || !$this->assetCacheService->isRealTimeUpdateEnabled()){
            return;
        }

        $properties = $this->dbService->getJsonEncodedProperties($node);
        if($this->propertiesNeedToBeConsidered($node, $properties)){
            $poid = $this->dbService->getPOIDByObject($node->getNodeData());

            $identifiersToNodes = [];
            $assetsToNodes      = [];
            $this->updateNodesLists($poid, $properties, $identifiersToNodes, $assetsToNodes);

            $identifiersToSearch = array_merge(array_keys($identifiersToNodes), array_keys($assetsToNodes));
            $this->assetCacheService->readUUIDListsFromCache($identifiersToSearch, $identifiersToNodes, $assetsToNodes);

            $this->assetCacheService->populateCacheByUUIDLists($identifiersToNodes, $assetsToNodes, false);
        }
    }

    public function nodeUpdated(NodeInterface $node): void
    {
        if(!$this->assetCacheService->isPopulated() || !$this->assetCacheService->isRealTimeUpdateEnabled()){
            return;
        }

        // if a linked image is removed, the properties don't have an __identifier or asset entry
        // so the poid needs to be cleared
        $this->nodeRemoved($node);
        $this->nodeAdded($node);
    }

    public function nodeRemoved(NodeInterface $node): void
    {
        if(!$this->assetCacheService->isPopulated() || !$this->assetCacheService->isRealTimeUpdateEnabled()){
            return;
        }

        $poid = $this->dbService->getPOIDByObject($node->getNodeData());
        $this->assetCacheService->removePOIDFromCache($poid);
    }

    protected function propertiesNeedToBeConsidered(NodeInterface $node, string $properties){
        return strpos($node->getPath(), SiteService::SITES_ROOT_PATH) === 0 &&
               (strpos($properties, '"__identifier": ') !== false || strpos($properties, 'asset:\\/\\/') !== false);
    }
}
