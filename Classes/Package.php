<?php
namespace Webandco\AssetUsageCache;

/*
 * This file is part of the Webandco.AssetUsageCache package
 *
 * (c) web&co - www.webandco.com
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\ContentRepository\Domain\Model\Node;
use Neos\ContentRepository\Domain\Model\Workspace;
use Neos\Flow\Core\Bootstrap;
use Neos\Flow\Package\Package as BasePackage;
use Webandco\AssetUsageCache\Service\AssetUsageService;

/**
 * @inheritDoc
 */
class Package extends BasePackage
{
    /**
     * @inheritDoc
     */
    public function boot(Bootstrap $bootstrap)
    {
        $dispatcher = $bootstrap->getSignalSlotDispatcher();
        $dispatcher->connect(Node::class, 'nodeAdded', AssetUsageService::class, 'nodeAdded');
        $dispatcher->connect(Node::class, 'nodeUpdated', AssetUsageService::class, 'nodeUpdated');
        $dispatcher->connect(Node::class, 'nodeRemoved', AssetUsageService::class, 'nodeRemoved');

        $dispatcher->connect(Workspace::class, 'afterNodePublishing', AssetUsageService::class, 'nodeUpdated', false);
    }
}
