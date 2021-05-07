<?php

namespace Ox6d617474\Isolate;

use PhpParser\Node;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\NodeVisitorAbstract;

final class DiscoveryVisitor extends NodeVisitorAbstract
{
    /**
     * Discovered namespaces
     *
     * @var array
     */
    private $namespaces;

    /**
     * Class constructor
     */
    public function __construct()
    {
        $this->namespaces = [];
    }

    /**
     * {@inheritdoc}
     */
    public function enterNode(Node $node)
    {
        if ($node instanceof Namespace_) {
            if (isset($node->name)) {
                $this->namespaces[implode('\\', $node->name->parts)] = true;
            }
        }
    }

    /**
     * Get the list of discovered namespaces
     *
     * @return array
     */
    public function getNamespaces()
    {
        return $this->namespaces;
    }
}
