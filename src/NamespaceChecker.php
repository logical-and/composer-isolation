<?php

namespace Ox6d617474\Isolate;

final class NamespaceChecker
{
    /**
     * Discovered namespaces
     *
     * @var array
     */
    private $namespaces;

    /**
     * Prefix
     *
     * @var string
     */
    private $prefix;

    /**
     * Class constructor
     *
     * @param array  $namespaces
     * @param string $prefix
     */
    public function __construct(array $namespaces, $prefix)
    {
        $this->namespaces = $namespaces;
        $this->prefix = $prefix;
    }

    /**
     * Is the given string a valid namespace
     *
     * @param string $string
     *
     * @return bool
     */
    public static function isNamespace($string)
    {
        // Must contain a backslash, and may only contain alphanumeric and underscore
        if (!preg_match('/^[0-9a-z_\\\]+$/i', $string) || !preg_match('/[\\\]+/i', $string)) {
            return false;
        }

        // Don't match only slashes...
        if (preg_match('/^[\\\]+$/i', $string)) {
            return false;
        }
        // Don't match a single word between slashes...
        if (preg_match('/^[\\\]+[0-9a-z_]+[\\\]+$/i', $string)) {
            return false;
        }

        // Sections should not begin with a number
        $parts = array_filter(explode('\\', $string));
        foreach ($parts as $part) {
            if (preg_match('/^[0-9]+/', $part)) {
                return false;
                break;
            }
        }

        return true;
    }

    /**
     * Should the given namespace be transformed?
     *
     * @param string $string
     *
     * @return bool
     */
    public function shouldTransform($string)
    {
        // Never transform non-namespace strings
        if (!self::isNamespace($string)) {
            return false;
        }

        // Trim ends to ensure valid matches
        $string = trim($string, '\\');

        // We never want to match our own prefix
        if (preg_match('/^' . preg_quote($this->prefix, '/') . '/i', $string)) {
            return false;
        }

        // We only want to match our list of namespaces
        return isset($this->namespaces[$string]);
    }
}
