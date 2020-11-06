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
use Neos\ContentRepository\Domain\Model\NodeData;
use Neos\ContentRepository\Domain\Model\NodeInterface;
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
class DatabaseService
{
    /**
     * @Flow\Inject
     * @var PersistenceManagerInterface
     */
    protected $persistenceManager;

    /**
     * Doctrine's Entity Manager.
     *
     * @Flow\Inject
     * @var EntityManagerInterface
     */
    protected $entityManager;

    /**
     * @Flow\Inject
     * @var NodeDataRepository
     */
    protected $nodeDataRepository;

    public function getAssetIdentifiersIncludingVariants(AssetInterface $asset){
        $identifiersToSearch = [];

        $poid = $this->getPOIDByObject($asset);
        $identifiersToSearch[] = $poid;

        if ($asset instanceof Image) {
            foreach ($asset->getVariants() as $variant) {
                $poid = $this->getPOIDByObject($variant);
                $identifiersToSearch[] = $poid;
            }
        }

        return $identifiersToSearch;
    }

    public function queryAssetAndIdentifiers(){
        // To support various database systems we only query for `asset:` instead of the json string `asset:\/\/`
        $sql = 'SELECT persistence_object_identifier, properties FROM neos_contentrepository_domain_model_nodedata';
        $sql .= ' WHERE ';
        $sql .= '   (properties like \'%\_\_identifier%\' OR properties like \'%asset:%\')';
        $sql .= '   AND path like \'' . SiteService::SITES_ROOT_PATH . '%\'';

        /** @var \Doctrine\ORM\QueryBuilder $queryBuilder */
        $queryBuilder = $this->entityManager->createQueryBuilder();
        $nodeList = $queryBuilder->getEntityManager()->getConnection()->query($sql)->fetchAll();

        foreach ($nodeList as $row) {
            $nodes[$row['persistence_object_identifier']] = $row['properties'];
        }

        return $nodes;
    }

    public function getNodesByPOIDs($poids){
        if(count($poids) == 0){
            return [];
        }

        $query = $this->nodeDataRepository->createQuery();

        $query->matching(
            $query->in('Persistence_Object_Identifier', $poids)
        );

        $nodes = $query->execute()->toArray();

        return $nodes;
    }

    public function fetchPoidProperties($poid){
        if(empty($poid)){
            return null;
        }

        $sql = 'SELECT properties FROM neos_contentrepository_domain_model_nodedata';
        $sql .= ' WHERE ';
        $sql .= '   persistence_object_identifier = \''.$poid.'\'';

        /** @var \Doctrine\ORM\QueryBuilder $queryBuilder */
        $queryBuilder = $this->entityManager->createQueryBuilder();
        $node = $queryBuilder->getEntityManager()->getConnection()->query($sql)->fetch();
        if($node) {
            return $node['properties'];
        }
        else{
            return null;
        }
    }

    public function getJsonEncodedProperties(NodeInterface $node){
        /** @var Connection $connection */
        $connection = $this->entityManager->getConnection();

        $jsonPropertiesDataTypeHandler = JsonArrayType::getType(JsonArrayType::FLOW_JSON_ARRAY);
        $nodeProperties = $node->getNodeData()->getProperties();
        $properties = $jsonPropertiesDataTypeHandler->convertToDatabaseValue($nodeProperties, $connection->getDatabasePlatform());

        return $properties;
    }

    public function getPOIDByObject($object){
        return $this->persistenceManager->getIdentifierByObject($object);
    }
}
