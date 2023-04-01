<?php

declare(strict_types=1);

namespace Neos\ContentRepository\StructureAdjustment\Adjustment;

use Neos\ContentRepository\Core\NodeType\NodeType;
use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\ContentRepository\Core\NodeType\NodeTypeManager;
use Neos\ContentRepository\Core\SharedModel\Exception\NodeTypeNotFoundException;

trait LoadNodeTypeTrait
{
    abstract protected function getNodeTypeManager(): NodeTypeManager;

    /**
     * loads and returns the node type, but only if it is not the FallbackNodeType
     *
     * @param NodeTypeName $nodeTypeName
     * @return NodeType|null
     */
    protected function loadNodeType(NodeTypeName $nodeTypeName): ?NodeType
    {
        try {
            $nodeType = $this->getNodeTypeManager()->getNodeType($nodeTypeName->value);
            if ($nodeType->getName() !== $nodeTypeName->jsonSerialize()) {
                // the $nodeTypeName was different than the fetched node type; so that means
                // that the FallbackNodeType has been returned.
                return null;
            }
            return $nodeType;
        } catch (NodeTypeNotFoundException $e) {
            // the $nodeTypeName was not found; so we need to remove all nodes of this type.
            // This case applies if the fallbackNodeType is not configured.
            return null;
        }
    }
}
