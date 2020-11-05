<?php

namespace Webandco\AssetUsageCache\Aspect;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Aop\JoinPointInterface;

/**
 * @Flow\Scope("singleton")
 * @Flow\Aspect
 */
class AssetUsageStrategiesAspect
{
    /**
     * @Flow\InjectConfiguration(package="Webandco.AssetUsageCache", path="assetUsageStrategies")
     * @var array
     */
    protected $assetUsageStrategies;

    /**
     *
     * @Flow\Around("method(Neos\Media\Domain\Service\AssetService->getUsageStrategies())")
     * @param JoinPointInterface $joinPoint The current join point
     * @return void
     */
    public function filterAssetUsageStrategies(JoinPointInterface $joinPoint)
    {
        $assetServiceUsageStrategies = $joinPoint->getAdviceChain()->proceed($joinPoint);

        $enabledAssetUsageStrategies = $this->getEnabledAssetUsageStrategies();

        $filteredAssetUsages = array_filter($assetServiceUsageStrategies, function($asetUsageStrategy) use(&$enabledAssetUsageStrategies) {
            foreach ($enabledAssetUsageStrategies as $strategyClassName) {
                if($asetUsageStrategy instanceof $strategyClassName){
                    return true;
                }
            }

            return false;
        });

        return $filteredAssetUsages;
    }

    protected function getEnabledAssetUsageStrategies(){
        $enabledAssetUsageStrategies = [];
        foreach ($this->assetUsageStrategies as $name => $strategyConfiguration) {
            if(!isset($strategyConfiguration['disable']) || !$strategyConfiguration['disable']){
                $enabledAssetUsageStrategies[] = $strategyConfiguration['className'];
            }
        }
        return $enabledAssetUsageStrategies;
    }
}
