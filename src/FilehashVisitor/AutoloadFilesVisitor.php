<?php

namespace Ox6d617474\Isolate\FilehashVisitor;

use PhpParser\Node;

class AutoloadFilesVisitor extends AbstractVisitor
{

    private $entered = false;

    /**
     * {@inheritdoc}
     */
    public function enterNode(Node $node)
    {
        if ($this->entered and $node instanceof Node\Expr\Array_) {
            $this->transformFilehashArray($node);

            // Don't catch anything more
            $this->entered = false;
        }

        if ($node instanceof Node\Stmt\Return_) {
            $this->entered = true;
        }
    }
}