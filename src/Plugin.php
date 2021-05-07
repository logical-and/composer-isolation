<?php

namespace Ox6d617474\Isolate;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginInterface;
use Ox6d617474\Isolate\FilehashVisitor\AbstractVisitor;
use Ox6d617474\Isolate\FilehashVisitor\AutoloadFilesVisitor;
use Ox6d617474\Isolate\FilehashVisitor\AutoloadStaticVisitor;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

final class Plugin implements PluginInterface, EventSubscriberInterface, Capable
{
    /**
     * The name of this package
     */
    const PACKAGENAME = 'and/isolate-composer';

    /**
     * Reference to the running Composer instance
     *
     * @var Composer
     */
    private $composer;

    /**
     * Namespace prefix
     *
     * @var string
     */
    private $prefix;

    /**
     * Namespace checker
     *
     * @var NamespaceChecker
     */
    private $checker;

    /**
     * Package blacklist
     *
     * @var array
     */
    private $blacklist;

    /**
     * Prefix require-dev packages?
     *
     * @var bool
     */
    private $pkgdev;

    /**
     * Replacements
     *
     * @var array
     */
    private $replacements;

    /**
     * Autorun?
     *
     * @var bool
     */
    private static $autorun;

    /**
     * Initialization
     *
     * @param Composer    $composer
     * @param IOInterface $io
     *
     * @throws \Exception
     */
    public function activate(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
        $config = $composer->getConfig()->get('isolate');

        // Get the namespace prefix and validate it
        if (!isset($config['prefix'])) {
            throw new \Exception('You must specify a prefix in your composer.json file');
        }

        if (!NamespaceChecker::isNamespace($config['prefix'])) {
            throw new \Exception('Namespace prefix must be a valid namespace');
        }

        $this->prefix = trim($config['prefix'], '\\');

        // Get the list of blacklisted packages
        // These packages will not be searched for namespaces
        // They will still be rewritten if they contain namespaces found in other packages
        $blacklist = [
            self::PACKAGENAME,  // This package
            'nikic/php-parser', // The parser library

            // Composer
            'composer/composer',
            'composer/ca-bundle',
            'composer/semver',
            'composer/spdx-licenses',
        ];

        if (!empty($config['blacklist'])) {
            $blacklist = array_merge($blacklist, $config['blacklist']);
        }

        $this->blacklist = $blacklist;

        // These are string replacements that will be run after code rewrites
        // They are executed EVERY time, so make sure they are idempotent
        $replacements = [];
        if (!empty($config['replacements'])) {
            $replacements = array_merge($replacements, $config['replacements']);
        }

        $vendor = $composer->getConfig()->get('vendor-dir');
        foreach ($replacements as $file => $dict) {
            unset($replacements[$file]);
            $file = sprintf('%s/%s', $vendor, $file);
            $replacements[$file] = $dict;
        }

        $this->replacements = $replacements;

        // If this config value is found and set to true, then require-dev
        // packages will be prefixed as well. Default is to not prefix them.
        $this->pkgdev = false;
        if (!empty($config['require-dev']) && $config['require-dev']) {
            $this->pkgdev = true;
        }

        // If this config value is found and set to true, then the dependency
        // isolation process will automatically run before dumps
        self::$autorun = false;
        if (!empty($config['autorun']) && $config['autorun']) {
            self::$autorun = true;
        }
    }

    /**
     * Event registration
     *
     * @return array
     */
    public static function getSubscribedEvents()
    {
        $events = [
            '__isolate-dependencies' => [
                ['mutateNamespaces'],
                ['mutateStaticFiles']
            ],
        ];

        if (self::$autorun) {
            $events['pre-autoload-dump'] = 'mutateNamespaces';
            $events['post-autoload-dump'] = 'mutateStaticFiles';
        }

        return $events;
    }

    /**
     * Let composer know we can do commands
     *
     * @return array
     */
    public function getCapabilities()
    {
        return [
            'Composer\\Plugin\\Capability\\CommandProvider' => 'Ox6d617474\\Isolate\\CommandProvider',
        ];
    }

    /**
     * Main namespaces logic
     */
    public function mutateNamespaces()
    {
        // Build the list of required packages
        if (!$this->pkgdev) {
            $requiredpkg = [];
            $this->getRequired($this->composer->getPackage(), $requiredpkg);
        }

        /** @var \Composer\Repository\InstalledRepositoryInterface $repo */

        // Grab the list of packages
        $repo = $this->composer->getRepositoryManager()->getLocalRepository();
        $packages = $repo->getCanonicalPackages();

        // Grab the list of namespaces
        $namespaces = [];
        foreach ($packages as $package) {
            $name = $package->getName();
            if (!$this->pkgdev && !isset($requiredpkg[$name])) {
                continue;
            }

            if (in_array($name, $this->blacklist)) {
                // Ignore blacklisted packages
                continue;
            }

            $namespaces = array_merge($namespaces, $this->discover($package));
        }

        // Bail early if nothing needs to be replaced.
        if (empty($namespaces)) {
            return;
        }

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
        $this->checker = new NamespaceChecker($namespaces, $this->prefix);

        // Do the work
        foreach ($packages as $package) {
            // Skip self
            if ($package->getName() == self::PACKAGENAME) {
                continue;
            }

            // Prefix the namespaces in the installed.json (used to dump autoloaders)
            $repo->removePackage($package);
            $this->mutatePackage($package);
            $repo->addPackage($package);
            $repo->write();

            // Rewrite the files in vendor to use the prefixed namespaces
            $this->rewritePackage($package);
        }
    }

    /**
     * Static files logic
     */
    public function mutateStaticFiles()
    {
        $repo = $this->composer->getRepositoryManager()->getLocalRepository();
        $packages = $repo->getCanonicalPackages();
        $installManager = $this->composer->getInstallationManager();
        $vendorsDir = rtrim(dirname(dirname($installManager->getInstallPath($packages[0]))), '\\/');

        $parser = (new ParserFactory())->create(ParserFactory::PREFER_PHP7);
        $printer = new Standard();

        // Iterate over static files
        foreach ([
            "$vendorsDir/composer/autoload_files.php" => AutoloadFilesVisitor::class,
            "$vendorsDir/composer/autoload_static.php" => AutoloadStaticVisitor::class,
        ] as $filepath => $visitorClass) {
            if (!is_file($filepath)) {
                printf('File %s is not exist', $filepath);
                continue;
            }

            $traverser = new NodeTraverser();
            /** @var AbstractVisitor $visitor */
            $visitor = new $visitorClass($filepath, $vendorsDir);
            $traverser->addVisitor($visitor);

            try {
                $contents = file_get_contents($filepath);
                $stmts = $parser->parse($contents);
                $stmts = $traverser->traverse($stmts);

                // Only write if we actually did a transform. Otherwise leave it alone
                if ($visitor->didTransform()) {
                    file_put_contents($filepath, $printer->prettyPrintFile($stmts));
                }
            } catch (\Exception $e) {
                printf("Error during Isolation AST traversal: %s : %s\n%s\n", $filepath, $e->getMessage(), $e->getTraceAsString());
            }
        }
    }

    /**
     * Discover all the namespaces in a package
     *
     * @param PackageInterface $package
     *
     * @return array
     */
    private function discover(PackageInterface $package)
    {
        $namespaces = [];

        // Make sure it's actually installed...
        $installManager = $this->composer->getInstallationManager();
        $directory = rtrim($installManager->getInstallPath($package), '/');
        if (empty($directory)) {
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
    private function discoverFile($filepath)
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
            printf("Error during Isolation AST traversal: %s : %s\n%s\n", $filepath, $e->getMessage(), $e->getTraceAsString());
        }

        return $namespaces;
    }

    /**
     * Mutate autoloaded namespaces for a package
     *
     * @param PackageInterface $package
     */
    private function mutatePackage(PackageInterface $package)
    {
        $autoload = $package->getAutoload();
        foreach ($autoload as $type => $dict) {
            foreach ($autoload[$type] as $ns => $entry) {
                $tmp = sprintf('%s\\', trim($ns, '\\'));
                if (!$this->checker->shouldTransform($tmp)) {
                    continue;
                }

                unset($autoload[$type][$ns]);
                $autoload[$type][sprintf('%s\\%s', $this->prefix, $ns)] = $entry;
            }
        }
        $package->setAutoload($autoload);

        // Transform dev-autoloaded namespaces to be prefixed
        $autoload = $package->getDevAutoload();
        foreach ($autoload as $type => $dict) {
            foreach ($autoload[$type] as $ns => $entry) {
                if (!$this->checker->shouldTransform($ns)) {
                    continue;
                }

                unset($autoload[$type][$ns]);
                $autoload[$type][sprintf('%s\\%s', $this->prefix, $ns)] = $entry;
            }
        }
        $package->setDevAutoload($autoload);
    }

    /**
     * Rewrite code for a package
     *
     * @param PackageInterface $package
     */
    private function rewritePackage(PackageInterface $package)
    {
        // Make sure it's actually installed...
        $installManager = $this->composer->getInstallationManager();
        $directory = rtrim($installManager->getInstallPath($package), '/');
        if (empty($directory)) {
            return;
        }

        // Rewrite directory structure for PSR-0 packages
        $this->handlePSR0($package->getAutoload(), $directory);
        $this->handlePSR0($package->getDevAutoload(), $directory);

        // Process each file in the package directory
        $di = new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS);
        $it = new \RecursiveIteratorIterator($di);
        foreach ($it as $file) {
            $ext = pathinfo($file, PATHINFO_EXTENSION);
            $file = (string) $file;
            if ($ext == 'php') {
                $this->transformFile($file);
            } elseif (empty($ext)) {
                // Also grab files with no extension that contain <?php
                // These are usually executables, but still need to be parsed
                if (preg_match('/' . preg_quote('<?php', '/') . '/i', file_get_contents($file))) {
                    $this->transformFile($file);
                }
            }
        }
    }

    /**
     * Transform an individual file
     *
     * @param string $filepath
     */
    private function transformFile($filepath)
    {
        $transformed = false;
        $contents = file_get_contents($filepath);

        $parser = (new ParserFactory())->create(ParserFactory::PREFER_PHP7);
        $prettyPrinter = new Standard();
        $traverser = new NodeTraverser();
        $visitor = new NodeVisitor($this->prefix, $this->checker);
        $traverser->addVisitor($visitor);
        try {
            $stmts = $parser->parse($contents);
            $stmts = $traverser->traverse($stmts);
            if ($visitor->didTransform()) {
                $contents = $prettyPrinter->prettyPrintFile($stmts);
                $transformed = true;
            }

            if (isset($this->replacements[$filepath])) {
                foreach ($this->replacements[$filepath] as $search => $replace) {
                    $contents = str_replace($search, $replace, $contents);
                    $transformed = true;
                }
            }
        } catch (\Exception $e) {
            printf("Error during Isolation AST traversal: %s : %s\n%s\n", $filepath, $e->getMessage(), $e->getTraceAsString());
        }

        // Only write if we actually did a transform. Otherwise leave it alone
        if ($transformed) {
            file_put_contents($filepath, $contents);
        }
    }

    /**
     * If there are any PSR-0 namespaced files, the directory structure
     * needs to be updated as dictated by the new namespace
     *
     * @param array  $autoload
     * @param string $directory
     */
    private function handlePSR0(array $autoload, $directory)
    {
        $prefixpath = str_replace('\\', '/', $this->prefix);
        $vendordir = $this->composer->getConfig()->get('vendor-dir');

        $moved = [];
        if (isset($autoload['psr-0'])) {
            foreach ($autoload['psr-0'] as $ns => $path) {
                if (!preg_match('/^' . preg_quote($this->prefix, '/') . '/i', $ns)) {
                    continue;
                }

                $path = trim($path, '/');

                $fullpath = sprintf('%s/%s', $directory, $path);
                $tmppath = sprintf('%s/_isolate_tmp', $vendordir);
                $newpath = sprintf('%s/%s/%s', $directory, $path, $prefixpath);

                if (isset($moved[$fullpath]) || file_exists($newpath)) {
                    // Don't move twice
                    continue;
                }

                // Do the move
                rename($fullpath, $tmppath);
                mkdir(dirname($newpath), 0777, true);
                rename($tmppath, $newpath);

                $moved[$fullpath] = true;
            }
        }

        /*
         * Move any explicitly autoloaded files back if needed
         */
        if (isset($autoload['files'])) {
            foreach ($autoload['files'] as $file) {
                $fullpath = sprintf('%s/%s', $directory, $file);
                foreach ($moved as $path => $null) {
                    if (preg_match('/^' . preg_quote($path, '/') . '/i', $fullpath)) {
                        $subpath = str_replace($directory, '', $path);
                        $copiedpath = str_replace($subpath, sprintf('%s/%s', $subpath, $prefixpath), $fullpath);
                        if (file_exists($copiedpath)) {
                            $dirname = dirname($fullpath);
                            if (!file_exists($dirname)) {
                                mkdir($dirname, 0777, true);
                            }
                            rename($copiedpath, $fullpath);
                            break;
                        }
                    }
                }
            }
        }
    }

    /**
     * Get the list of required dependencies
     *
     * @param PackageInterface $package
     * @param array            $list
     */
    private function getRequired(PackageInterface $package, array &$list)
    {
        $required = array_keys($package->getRequires());
        foreach ($required as $pkg) {
            if (!isset($list[$pkg])) {
                $pkgobj = $this->composer->getRepositoryManager()->getLocalRepository()->findPackage($pkg, '*');
                if ($pkgobj != null) {
                    $list[$pkg] = true;
                    $this->getRequired($pkgobj, $list);
                }
            }
        }
    }
}
