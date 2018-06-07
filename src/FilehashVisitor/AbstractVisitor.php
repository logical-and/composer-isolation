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

    public function __construct($filePath, $vendorsDir)
    {
        $this->vendorsDir = $vendorsDir;
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
        $printer = new Standard();
        $parser = (new ParserFactory())->create(ParserFactory::PREFER_PHP7);

        foreach ($arrayNode->items as $i => $item) {
            if ($item->key instanceof Node\Scalar\String_ and false === strpos($item->key->value, 'isolated-')) {
                // Let's cook some pretty key
                $key = 'isolated-' .
                    strtolower(str_replace(dirname(dirname(dirname($this->vendorsDir))), '', $this->vendorsDir)) .
                    str_replace(['$vendorDir . ', '.php'], '', $printer->prettyPrintExpr($item->value)) .
                    $item->key->value;
                $key = preg_replace('~[^a-z0-9\-]~', '-', $key);
                $key = preg_replace('~\-+~', '-', $key);
                $key = trim($key, '-');
                $item->key->value = $key;

                /*$parsed = $parser->parse(
                    '<?php md5('.
                    "'" . $item->key->value."' . ".
                    $printer->prettyPrintExpr($item->value).
                    ');');
                $item->key = $parsed[ 0 ];*/

                // Notify the source has been mutated
                $this->transformed = true;
            }
        }
    }
}
