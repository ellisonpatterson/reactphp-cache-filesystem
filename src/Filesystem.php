<?php declare(strict_types=1);

namespace WyriHaximus\React\Cache;

use React\Cache\CacheInterface;
use React\Filesystem\AdapterInterface;
use React\Filesystem\Node\FileInterface;
use React\Filesystem\Node\DirectoryInterface;
use React\Filesystem\Node\NodeInterface;
use React\Filesystem\Node\NotExistInterface;
use React\Promise\Promise;
use React\Promise\PromiseInterface;
use Throwable;
use function React\Promise\all;
use function React\Promise\reject;
use function React\Promise\resolve;

final class Filesystem implements CacheInterface
{
    /**
     * @var AdapterInterface
     */
    private $filesystem;

    /**
     * @var string
     */
    private $path;

    /**
     * @var bool
     */
    private $supportsHighResolution;

    /**
     * filesystem constructor.
     * @param AdapterInterface $filesystem
     * @param string          $path
     */
    public function __construct(AdapterInterface $filesystem, string $path)
    {
        $this->filesystem = $filesystem;
        $this->path = $path;
        // prefer high-resolution timer, available as of PHP 7.3+
        $this->supportsHighResolution = \function_exists('hrtime');
    }

    /**
     * @param  string           $key
     * @param  null|mixed       $default
     */
    public function get($key, $default = null): PromiseInterface
    {
        return $this->has($key)->then(function (bool $has) use ($key, $default) {
            if ($has === true) {
                return $this->getFile($key)
                    ->then(static fn(FileInterface $file) => $file->getContents())
                    ->then(static fn(string $contents) => unserialize($contents))
                    ->then(
                        function (CacheItem $cacheItem) use ($key, $default) {
                            if ($cacheItem->hasExpired($this->now())) {
                                return $this->getFile($key)
                                    ->then(static fn($file) => $file->unlink())
                                    ->then(static fn($default) => resolve($default));
                            }

                            return resolve($cacheItem->data());
                        }
                    );
            }

            return resolve($default);
        });
    }

    /**
     * @param string     $key
     * @param mixed      $value
     * @param ?float     $ttl
     */
    public function set($key, $value, $ttl = null): PromiseInterface
    {
        return $this->getFile($key)->then(function($file) use($key, $value, $ttl) {
            if (\strpos($key, \DIRECTORY_SEPARATOR) === false) {
                return $this->putContents($file, $value, $ttl);
            }
    
            $path = \explode(\DIRECTORY_SEPARATOR, $key);
            \array_pop($path);
            $path = \implode(\DIRECTORY_SEPARATOR, $path);
    
            return $this->filesystem->detect($this->path . $path)->then(function($node) use($path) {
                if ($node instanceof NotExistInterface) {
                    return $node->createDirectory($this->path . $path);
                }
    
                return resolve(true);
            })->then(function () use ($file, $value, $ttl): PromiseInterface {
                return $this->putContents($file, $value, $ttl);
            });
        });
    }

    /**
     * @param string $key
     */
    public function delete($key): PromiseInterface
    {
        return $this->getFile($key)->then(
            static fn($file) => $file->unlink(),
            static fn() => resolve(false)
        );
    }

    public function getMultiple(array $keys, $default = null): PromiseInterface
    {
        $promises = [];
        foreach ($keys as $key) {
            $promises[$key] = $this->get($key, $default);
        }

        return all($promises);
    }

    public function setMultiple(array $values, $ttl = null): PromiseInterface
    {
        $promises = [];
        foreach ($values as $key => $value) {
            $promises[$key] = $this->set($key, $value, $ttl);
        }

        return all($promises)->then(function ($results) {
            foreach ($results as $result) {
                if ($result === false) {
                    return resolve(false);
                }
            }

            return resolve(true);
        });
    }

    public function deleteMultiple(array $keys): PromiseInterface
    {
        $promises = [];
        foreach ($keys as $key) {
            $promises[$key] = $this->delete($key);
        }

        return all($promises)->then(function ($results) {
            foreach ($results as $result) {
                if ($result === false) {
                    return resolve(false);
                }
            }

            return resolve(true);
        });
    }

    public function clear(): PromiseInterface
    {
        $structure = [];

        $ls = function(string $path) use(&$ls, &$structure) {
            return $this->filesystem->detect($path)->then(function($node) {
                return $node->ls();
            })->then(function (array $nodes) use(&$ls, &$structure) {
                $promises = [];

                foreach ($nodes as $node) {
                    assert($node instanceof NodeInterface);

                    $structure[] = $node;

                    if ($node instanceof DirectoryInterface) {
                        $promises[] = $ls($node->path() . $node->name());
                    }
                }

                return all($promises);
            });
        };

        return $ls($this->path)->then(function() use(&$structure) {
            // unlinking directories isn't consistent, need to reverse array and run in series?
        });
    }

    public function has($key): PromiseInterface
    {
        return $this->filesystem->detect($this->path . $key)->then(function($node) {
            if ($node instanceof FileInterface) {
                return resolve(true);
            }

            return resolve(false);
        });
    }

    private function putContents(FileInterface $file, $value, $ttl): PromiseInterface
    {
        $item = new CacheItem($value, is_null($ttl) ? null : ($this->now() + $ttl));
        return $file->putContents(serialize($item))->then(function () {
            return resolve(true);
        }, function () {
            return resolve(false);
        });
    }

    /**
     * @param $key
     * @return PromiseInterface
     */
    private function getFile($key): PromiseInterface
    {
        return $this->filesystem->detect($this->path . $key)->then(function($node) {
            if ($node instanceof NotExistInterface) {
                return $node->createFile();
            }

            return $node;
        });
    }

    /**
     * @return float
     */
    private function now()
    {
        return $this->supportsHighResolution ? \hrtime(true) * 1e-9 : \microtime(true);
    }
}
