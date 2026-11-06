<?php

/**
 * @package     Merserwis.Plugin
 * @subpackage  System.mertools
 *
 * Right after an update PHP may still run some of the plugin's files in their previous version from
 * OPcache, next to new ones: on hosts where Joomla may not drop them (opcache.restrict_api), where each
 * PHP process keeps its own OPcache (LiteSpeed, CGI) or where PHP checks the files rarely
 * (revalidate_freq 60 and more) or never. A new file then calls what its old neighbour does not have
 * yet (1.13.1: "Call to undefined method …Compress::boxes()" on every page of the administrator for
 * minutes). Such an error is told apart here, so the plugin can skip its work on that request instead
 * of breaking the page.
 */

namespace Merserwis\Plugin\System\MerTools\Tool;

\defined('_JEXEC') or die;

final class StaleCode
{
    /**
     * Whether $e is what files of two versions do to each other: a method, function, class or constant
     * of MerTools that is not there, a call with fewer arguments than the other file wants, a method that
     * became private. Only these PHP errors, and only about MerTools' own code.
     */
    public static function matches(\Throwable $e): bool
    {
        if (\get_class($e) !== \Error::class && !$e instanceof \ArgumentCountError) {
            return false;
        }

        return (bool) preg_match('/^(?:Call to undefined (?:method|function) |Class "|Interface "|Trait "|Enum "|Undefined constant '
            . '|Call to (?:private|protected) method |Too few arguments to function )\\\\?Merserwis\\\\(?:Plugin\\\\System|Component)\\\\MerTools\\\\/',
            $e->getMessage());
    }

    /** Every PHP file under $dirs dropped from OPcache (compiled again at its next use); false: not allowed here. */
    public static function refreshOpcache(array $dirs): bool
    {
        if (!\function_exists('opcache_invalidate')) {
            return false;
        }
        $done = false;
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            try {
                foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
                    if ($file->getExtension() === 'php' && @opcache_invalidate($file->getPathname(), true)) {
                        $done = true;
                    }
                }
            } catch (\Throwable $e) {
            }
        }

        return $done;
    }
}
