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
        $printer = new Standard();
        $parser = (new ParserFactory())->create(ParserFactory::PREFER_PHP7);

        foreach ($arrayNode->items as $i => $item) {
            if ($item->key instanceof Node\Scalar\String_) {
                // '123hash' . $vendorDir . '/lib/name'
                $parsed = $parser->parse(
                    '<?php md5('.
                    "'" . $item->key->value."' . ".
                    $printer->prettyPrintExpr($item->value).
                    ');');
                $item->key = $parsed[ 0 ];

                // Notify the source has been mutated
                $this->transformed = true;
            }
        }
    }
}
