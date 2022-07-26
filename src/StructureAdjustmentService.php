<?php

declare(strict_types=1);

namespace Neos\ContentRepository\StructureAdjustment;

use Neos\ContentRepository\ContentRepository;
use Neos\ContentRepository\DimensionSpace\DimensionSpace\InterDimensionalVariationGraph;
use Neos\ContentRepository\EventStore\EventPersister;
use Neos\ContentRepository\EventStore\EventsToPublish;
use Neos\ContentRepository\Factory\ContentRepositoryServiceInterface;
use Neos\ContentRepository\Service\Infrastructure\ReadSideMemoryCacheManager;
use Neos\ContentRepository\SharedModel\NodeType\NodeTypeManager;
use Neos\ContentRepository\SharedModel\NodeType\NodeTypeName;
use Neos\ContentRepository\StructureAdjustment\Adjustment\DimensionAdjustment;
use Neos\ContentRepository\StructureAdjustment\Adjustment\DisallowedChildNodeAdjustment;
use Neos\ContentRepository\StructureAdjustment\Adjustment\ProjectedNodeIterator;
use Neos\ContentRepository\StructureAdjustment\Adjustment\PropertyAdjustment;
use Neos\ContentRepository\StructureAdjustment\Adjustment\StructureAdjustment;
use Neos\ContentRepository\StructureAdjustment\Adjustment\TetheredNodeAdjustments;
use Neos\ContentRepository\StructureAdjustment\Adjustment\UnknownNodeTypeAdjustment;

class StructureAdjustmentService implements ContentRepositoryServiceInterface
{
    protected TetheredNodeAdjustments $tetheredNodeAdjustments;
    protected UnknownNodeTypeAdjustment $unknownNodeTypeAdjustment;
    protected DisallowedChildNodeAdjustment $disallowedChildNodeAdjustment;
    protected PropertyAdjustment $propertyAdjustment;
    protected DimensionAdjustment $dimensionAdjustment;

    public function __construct(
        private readonly ContentRepository $contentRepository,
        private readonly EventPersister $eventPersister,
        NodeTypeManager $nodeTypeManager,
        InterDimensionalVariationGraph $interDimensionalVariationGraph,
        private readonly ReadSideMemoryCacheManager $readSideMemoryCacheManager,
    )
    {
        $projectedNodeIterator = new ProjectedNodeIterator(
            $contentRepository->getWorkspaceFinder(),
            $contentRepository->getContentGraph(),
        );

        $this->tetheredNodeAdjustments = new TetheredNodeAdjustments(
            $contentRepository,
            $projectedNodeIterator,
            $nodeTypeManager,
            $interDimensionalVariationGraph,
        );

        $this->unknownNodeTypeAdjustment = new UnknownNodeTypeAdjustment(
            $projectedNodeIterator,
            $nodeTypeManager
        );
        $this->disallowedChildNodeAdjustment = new DisallowedChildNodeAdjustment(
            $this->contentRepository,
            $projectedNodeIterator,
            $nodeTypeManager
        );
        $this->propertyAdjustment = new PropertyAdjustment(
            $projectedNodeIterator,
            $nodeTypeManager
        );
        $this->dimensionAdjustment = new DimensionAdjustment(
            $projectedNodeIterator,
            $interDimensionalVariationGraph
        );
    }

    /**
     * @return \Generator|StructureAdjustment[]
     */
    public function findAllAdjustments(): \Generator
    {
        foreach ($this->contentRepository->getContentGraph()->findUsedNodeTypeNames() as $nodeTypeName) {
            yield from $this->findAdjustmentsForNodeType($nodeTypeName);
        }
    }

    /**
     * @param NodeTypeName $nodeTypeName
     * @return \Generator|StructureAdjustment[]
     */
    public function findAdjustmentsForNodeType(NodeTypeName $nodeTypeName): \Generator
    {
        yield from $this->tetheredNodeAdjustments->findAdjustmentsForNodeType($nodeTypeName);
        yield from $this->unknownNodeTypeAdjustment->findAdjustmentsForNodeType($nodeTypeName);
        yield from $this->disallowedChildNodeAdjustment->findAdjustmentsForNodeType($nodeTypeName);
        yield from $this->propertyAdjustment->findAdjustmentsForNodeType($nodeTypeName);
        yield from $this->dimensionAdjustment->findAdjustmentsForNodeType($nodeTypeName);
    }

    public function fixError(StructureAdjustment $adjustment): void
    {
        if ($adjustment->remediation) {
            $remediation = $adjustment->remediation;
            $eventsToPublish = $remediation();
            assert($eventsToPublish instanceof EventsToPublish);
            $this->readSideMemoryCacheManager->disableCache();
            $this->eventPersister->publishEvents($eventsToPublish)->block(); // TODO: block or not?
        }
    }
}
