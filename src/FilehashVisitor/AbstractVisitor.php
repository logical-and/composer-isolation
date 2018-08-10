<?php

namespace Ox6d617474\Isolate\FilehashVisitor;

use PhpParser\NodeVisitorAbstract;
use PhpParser\Node;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

abstract class AbstractVisitor extends NodeVisitorAbstract
{
    /**
     * Did we perform a transform?
     *
     * @var bool
     */
    protected $transformed;

    /**
     * @var string To handle proper scope
     */
    protected $vendorsDir;

    /**
     * @var int Time to use as salt
     */
    protected $now = 0;

    public function __construct($filePath, $vendorsDir)
    {
        $this->vendorsDir = realpath($vendorsDir);
        $this->now = time();
    }

    /**
     * Did we perform a transformation
     *
     * @return bool
     */
    public function didTransform()
    {
        return $this->transformed;
    }

    protected function transformFilehashArray(Node\Expr\Array_ $arrayNode)
    {
        foreach ($arrayNode->items as $i => $item) {
            if ($item->key instanceof Node\Scalar\String_ and false === strpos($item->key->value, 'isolated-')) {
                $key =
                    // Point out as already processed
                    'isolated-' .
                    // Absolute path + timestamp (as salt, as path can be the same if app is built in the same dir)
                    sha1($this->vendorsDir . $this->now) . '-' .
                    // Original key
                    $item->key->value;
                $key = preg_replace('~[^a-z0-9\-]~', '-', $key);
                $key = preg_replace('~\-+~', '-', $key);
                $key = trim($key, '-');
                $item->key->value = $key;

                // Notify the source has been mutated
                $this->transformed = true;
            }
        }
    }
}
