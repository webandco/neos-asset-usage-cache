<?php

namespace Webandco\AssetUsageCache\Service;

/*
 * This file is part of the Webandco.AssetUsageCache package
 *
 * (c) web&co - www.webandco.com
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
use Neos\Flow\Configuration\ConfigurationManager;
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

    /**
     * @Flow\Inject
     * @var ConfigurationManager
     */
    protected $configurationManager;

    /**
     * @var int Unix timestamp when the cache has been populated
     */
    protected $cachePopulationTime = null;

    public function flushCache()
    {
        $this->assetUsageCache->flush();
    }

    public function flushByPOIDs(array $poids)
    {
        foreach ($poids as $poid) {
            $this->removePOIDFromCache($poid);
        }
    }

    public function isRealTimeUpdateEnabled(): bool
    {
        return (bool)$this->queryCacheConfig['realTimeUpdate'];
    }

    public function setRealTimeUpdate(bool $val)
    {
        $this->queryCacheConfig['realTimeUpdate'] = $val;
        return $this;
    }

    public function isDisabled(): bool
    {
        return (bool)$this->queryCacheConfig['disable'];
    }

    public function setCacheDisabled(bool $val): bool
    {
        $this->queryCacheConfig['disable'] = $val;
        return $this;
    }

    public function isPopulated(): bool
    {
        if ($this->isDisabled()) {
            return false;
        }

        $populationTime = $this->assetUsageCache->get('isPopulated');
        if (0 < $populationTime) {
            $this->cachePopulationTime = $populationTime;

            return true;
        }

        return false;
    }

    protected function populateCache($uuidToNodes, $prefix = null)
    {
        foreach ($uuidToNodes as $uuid => $poids) {
            if (empty($prefix)) {
                $key = $uuid;
            } else {
                $key = $prefix . ':' . $uuid;
                $key = sha1($key);
            }

            $tags = array_map(function ($poid) {
                return sha1('poid:' . $poid);
            }, $poids);

            $this->setCacheEntry($key, $poids, $tags);
        }
    }

    public function populateCacheByUUIDLists(array $identifiersToNodes, array $assetsToNodes, bool $initial)
    {
        if ($this->isDisabled()) {
            return;
        }

        if ($initial || !$this->isPopulated()) {
            $this->cachePopulationTime = time();
            $this->setCacheEntry('isPopulated', 0);
        }

        $this->populateCache($identifiersToNodes, 'identifier');
        $this->populateCache($assetsToNodes, 'asset');

        $this->setCacheEntry('isPopulated', $this->cachePopulationTime);
    }

    protected function setCacheEntry(string $entryIdentifier, $variable, array $tags = [])
    {
        $pendingCachetime = $this->getCacheLifetime($this->cachePopulationTime);
        // negative cache time will not be cached
        if ($pendingCachetime < 0) {
            return;
        }

        $this->assetUsageCache->set($entryIdentifier, $variable, $tags, $pendingCachetime);
    }

    public function readUUIDListsFromCache($identifiersToSearch, &$identifiersToNodes, &$assetsToNodes)
    {
        if (!$this->isPopulated()) {
            return;
        }

        $fillNodesList = function ($prefix, $uuid, &$nodesList) {
            $key = sha1($prefix . ':' . $uuid);
            if ($this->assetUsageCache->has($key)) {
                $nodesList[$uuid] = array_values(array_unique(array_merge(
                    isset($nodesList[$uuid]) ? $nodesList[$uuid] : [],
                    (array)$this->assetUsageCache->get($key)
                )));
            }
        };

        foreach ($identifiersToSearch as $uuid) {
            $fillNodesList('identifier', $uuid, $identifiersToNodes);
            $fillNodesList('asset', $uuid, $assetsToNodes);
        }
    }

    public function addPOIDToUUIDList($poid, $uuid)
    {
        if (!$this->isPopulated()) {
            return;
        }

        $getMergedUUIDList = function ($prefix, $uuid, $poid) {
            $uuidList = $this->assetUsageCache->get(sha1($prefix . ':' . $uuid));
            if (!is_array($uuidList)) {
                $uuidList = [];
            }
            $uuidList[] = $poid;
        };

        $this->populateCacheByUUIDLists([$uuid], $getMergedUUIDList('identifier', $uuid, $poid), [], false);
        $this->populateCacheByUUIDLists([$uuid], [], $getMergedUUIDList('asset', $uuid, $poid), false);
    }

    public function getEntriesByPOID($poid)
    {
        if (!$this->isPopulated()) {
            return [];
        }

        return $this->assetUsageCache->getByTag(sha1('poid:' . $poid));
    }

    public function removePOIDFromCache($poid)
    {
        if (!$this->isPopulated()) {
            return;
        }

        $tag = sha1('poid:' . $poid);
        $cacheEntries = $this->assetUsageCache->getByTag($tag);

        if (count($cacheEntries)) {
            foreach ($cacheEntries as $uuidKey => $poids) {
                $cacheEntries[$uuidKey] = array_values(array_diff($poids, [$poid]));
            }

            $this->populateCache($cacheEntries);
        }
    }

    protected function getBackendConfiguredLifetime()
    {
        $defaultLifetime = $this->configurationManager->getConfiguration(ConfigurationManager::CONFIGURATION_TYPE_CACHES, 'Webandco_AssetUsageCache.backendOptions.defaultLifetime');

        if (is_null($defaultLifetime)) {
            $defaultLifetime = 3600;
        }

        return $defaultLifetime;
    }

    protected function getCacheLifetime($populationTime)
    {
        $backendLifetime = $this->getBackendConfiguredLifetime();
        if ($backendLifetime === 0) {
            return 0;
        }

        $cacheEndTime = $populationTime + $backendLifetime;

        $pendingLifeTime = $cacheEndTime - time();

        if ($pendingLifeTime <= 0) {
            // dont cache it!
            return -1;
        }

        return $pendingLifeTime;
    }
}
