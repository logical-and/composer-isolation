<?php

namespace Ox6d617474\Isolate;

use Composer\IO\IOInterface;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

class Mutator
{

    /** @var NamespaceChecker */
    protected $checker;

    /**
     * IO Instance
     *
     * @var IOInterface
     */
    protected $io;

    /**
     * Replacements
     *
     * @var array
     */
    protected $fileReplacements = [];

    /**
     * Blacklisted glob
     *
     * @var array
     */
    protected $blacklist = [];

    public function setIO($io)
    {
        $this->io = $io;

        return $this;
    }

    /**
     * @param $directory
     * @param $prefix
     * @param array $blacklist Ignore files / directories on patch
     * @param array $fileReplacements What files to patch additionally
     */
    public function mutateNamespaces($directory, $prefix, array $blacklist = [], array $fileReplacements = [])
    {
        $namespaces = [];
        foreach ((array) $directory as $d) {
            $namespaces = array_merge($namespaces, $this->discoverNamespaces($d));
        }
        $this->fileReplacements = $fileReplacements;
        $prefix = rtrim($prefix, "\\");

        // Make sure we get all the interim namespaces too
        foreach ($namespaces as $ns => $null) {
            while (strlen($ns) > 0) {
                $ns = implode('\\', array_slice(explode('\\', $ns), 0, -1));
                if (!empty($ns)) {
                    $namespaces[$ns] = true;
                }
            }
        }

        // Build the namespace checker from the whitelist and the prefix
        $this->checker = new NamespaceChecker($namespaces, $prefix);

        // Process each file in the package directory
        foreach ($directory as $d) {
            $di = new \RecursiveDirectoryIterator($d, \RecursiveDirectoryIterator::SKIP_DOTS);
            $it = new \RecursiveIteratorIterator($di);
            $transformed = false;
            foreach ($it as $file) {
                $ext = pathinfo($file, PATHINFO_EXTENSION);
                $file = (string)$file;

                foreach ($blacklist as $ignorePath) {
                    $ignorePath = str_replace('**', '.+', $ignorePath);
                    $ignorePath = str_replace('*', '[^/]+', $ignorePath);

                    // Ignore file from being patched
                    if (preg_match("~^$ignorePath~", $file)) {
                        continue 2;
                    }
                }

                if ($ext == 'php') {
                    $transformed = $this->transformFile($prefix, $file);
                } elseif (empty($ext)) {
                    // Also grab files with no extension that contain <?php
                    // These are usually executables, but still need to be parsed
                    if (preg_match('/'.preg_quote('<?php', '/').'/i', file_get_contents($file))) {
                        $transformed = $this->transformFile($prefix, $file);
                    }
                }
            }
        }
    }

    /**
     * Discover all the namespaces in a directory
     *
     * @return array
     */
    public function discoverNamespaces($directory)
    {
        $namespaces = [];

        // Make sure it's actually installed...
        $directory = rtrim($directory, '/');
        if (!is_dir($directory)) {
            return $namespaces;
        }

        // Process each file in the package directory
        $di = new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS);
        $it = new \RecursiveIteratorIterator($di);
        foreach ($it as $file) {
            $ext = pathinfo($file, PATHINFO_EXTENSION);
            $file = (string) $file;
            if ($ext == 'php') {
                $namespaces = array_merge($namespaces, $this->discoverFile($file));
            } elseif (empty($ext)) {
                // Also grab files with no extension that contain <?php
                // These are usually executables, but still need to be parsed
                if (preg_match('/' . preg_quote('<?php', '/') . '/i', file_get_contents($file))) {
                    $namespaces = array_merge($namespaces, $this->discoverFile($file));
                }
            }
        }

        return $namespaces;
    }

    /**
     * Discover all the namespaces in a file
     *
     * @param string $filepath
     *
     * @return array
     */
    protected function discoverFile($filepath)
    {
        $namespaces = [];
        $contents = file_get_contents($filepath);

        $parser = (new ParserFactory())->create(ParserFactory::PREFER_PHP7);
        $traverser = new NodeTraverser();
        $visitor = new DiscoveryVisitor();
        $traverser->addVisitor($visitor);
        try {
            $stmts = $parser->parse($contents);
            $traverser->traverse($stmts);
            $namespaces = $visitor->getNamespaces();
        } catch (\Exception $e) {
            throw new \ErrorException("Error during Isolation AST traversal: %s : %s\n%s\n", $e->getCode(), 1, $e->getFile(), $e->getLine(), $e);
        }

        return $namespaces;
    }

    /**
     * Transform an individual file
     *
     * @param string $prefix
     * @param string $filepath
     *
     * @return bool Whether file was transformed
     */
    protected function transformFile($prefix, $filepath)
    {
        $transformed = false;
        $contents = file_get_contents($filepath);

        $parser = (new ParserFactory())->create(ParserFactory::PREFER_PHP7);
        $prettyPrinter = new Standard();
        $traverser = new NodeTraverser();
        $visitor = new NodeVisitor($prefix, $this->checker);
        $traverser->addVisitor($visitor);
        try {
            $stmts = $parser->parse($contents);
            $stmts = $traverser->traverse($stmts);
            if ($visitor->didTransform()) {
                $contents = $prettyPrinter->prettyPrintFile($stmts);
                $transformed = true;
            }

            if (isset($this->fileReplacements[$filepath])) {
                foreach ($this->fileReplacements[$filepath] as $search => $replace) {
                    $contents = str_replace($search, $replace, $contents);
                    $transformed = true;
                }
            }
        } catch (\Exception $e) {
            $this->io->writeError(
                sprintf("Error during Isolation AST traversal: %s : %s\n%s\n", $filepath, $e->getMessage(), $e->getTraceAsString()),
                true, IOInterface::QUIET
            );
        }

        // Only write if we actually did a transform. Otherwise leave it alone
        if ($transformed) {
            file_put_contents($filepath, $contents);
        }

        return $transformed;
    }
}