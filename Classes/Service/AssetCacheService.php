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
use Neos\ContentRepository\Domain\Repository\NodeDataRepository;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\Doctrine\DataTypes\JsonArrayType;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Media\Domain\Model\AssetInterface;
use Neos\Media\Domain\Model\Image;
use Neos\Neos\Domain\Service\SiteService;

/**
 * @Flow\Scope("singleton")
 */
class AssetCacheService
{
    /**
     * @Flow\InjectConfiguration(package="Webandco.AssetUsageCache", path="queryCache")
     * @var array
     */
    protected $queryCacheConfig;

    /**
     * @Flow\Inject
     * @var VariableFrontend
     */
    protected $assetUsageCache;

    public function flushCache(){
        $this->assetUsageCache->flush();
    }

    public function flushByPOIDs(array $poids){
        foreach($poids as $poid){
            $this->removePOIDFromCache($poid);
        }
    }

    public function isRealTimeUpdateEnabled() : bool{
        return (bool)$this->queryCacheConfig['realTimeUpdate'];
    }

    public function setRealTimeUpdate(bool $val){
        $this->queryCacheConfig['realTimeUpdate'] = $val;
        return $this;
    }

    public function isDisabled() : bool{
        return (bool)$this->queryCacheConfig['disable'];
    }

    public function setCacheDisabled(bool $val) : bool{
        $this->queryCacheConfig['disable'] = $val;
        return $this;
    }

    public function isPopulated() : bool{
        if($this->isDisabled() || !$this->assetUsageCache->get('isPopulated')){
            return false;
        }

        return (bool)$this->assetUsageCache->get('isPopulated');
    }

    protected function populateCache($uuidToNodes, $prefix=null){
        foreach($uuidToNodes as $uuid => $poids){
            if(empty($prefix)){
                $key = $uuid;
            }
            else{
                $key = $prefix.':'.$uuid;
                $key = sha1($key);
            }

            $tags = array_map(function ($poid){
                return sha1('poid:'.$poid);
            }, $poids);

            $this->assetUsageCache->set($key, $poids, $tags);
        }
    }

    public function populateCacheByUUIDLists(array $identifiersToNodes, array $assetsToNodes) {
        if($this->isDisabled()){
            return;
        }

        $this->assetUsageCache->set('isPopulated', false);

        $this->populateCache($identifiersToNodes, 'identifier');
        $this->populateCache($assetsToNodes, 'asset');

        $this->assetUsageCache->set('isPopulated', true);
    }

    public function readUUIDListsFromCache($identifiersToSearch, &$identifiersToNodes, &$assetsToNodes){
        if(!$this->isPopulated()){
            return;
        }

        $fillNodesList = function($prefix, $uuid, &$nodesList){
            $key = sha1($prefix.':'.$uuid);
            if($this->assetUsageCache->has($key)) {
                $nodesList[$uuid] = array_values(array_unique(array_merge(
                    isset($nodesList[$uuid]) ? $nodesList[$uuid] : [],
                    (array)$this->assetUsageCache->get($key)
                )));
            }
        };

        foreach($identifiersToSearch as $uuid){
            $fillNodesList('identifier', $uuid, $identifiersToNodes);
            $fillNodesList('asset', $uuid, $assetsToNodes);
        }
    }

    public function addPOIDToUUIDList($poid, $uuid){
        if(!$this->isPopulated()){
            return;
        }

        $getMergedUUIDList = function($prefix, $uuid, $poid){
            $uuidList = $this->assetUsageCache->get(sha1($prefix.':'.$uuid));
            if(!is_array($uuidList)){
                $uuidList = [];
            }
            $uuidList[] = $poid;
        };

        $this->populateCacheByUUIDLists([$uuid], $getMergedUUIDList('identifier', $uuid, $poid), []);
        $this->populateCacheByUUIDLists([$uuid], [], $getMergedUUIDList('asset', $uuid, $poid));
    }

    public function getEntriesByPOID($poid){
        if(!$this->isPopulated()){
            return [];
        }

        return $this->assetUsageCache->getByTag(sha1('poid:'.$poid));
    }

    public function removePOIDFromCache($poid){
        if(!$this->isPopulated()){
            return;
        }

        $tag = sha1('poid:'.$poid);
        $cacheEntries = $this->assetUsageCache->getByTag($tag);

        if(count($cacheEntries)) {
            foreach ($cacheEntries as $uuidKey => $poids) {
                $cacheEntries[$uuidKey] = array_values(array_diff($poids, [$poid]));
            }

            $this->populateCache($cacheEntries);
        }
    }
}
