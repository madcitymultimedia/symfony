<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Cache\Traits;

use Psr\Cache\CacheItemInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\Cache\CacheItem;
use Symfony\Component\Cache\Exception\InvalidArgumentException;

/**
 * @author Nicolas Grekas <p@tchwork.com>
 *
 * @internal
 */
trait AbstractAdapterTrait
{
    use LoggerAwareTrait;

    /**
     * needs to be set by class, signature is function(string <key>, mixed <value>, bool <isHit>)
     */
    private static \Closure $createCacheItem;

    /**
     * needs to be set by class, signature is function(array <deferred>, string <namespace>, array <&expiredIds>)
     */
    private static \Closure $mergeByLifetime;

    private string $namespace = '';
    private int $defaultLifetime;
    private string $namespaceVersion = '';
    private bool $versioningIsEnabled = false;
    private array $deferred = [];
    private array $ids = [];

    /**
     * @var int|null The maximum length to enforce for identifiers or null when no limit applies
     */
    protected $maxIdLength;

    /**
     * Fetches several cache items.
     *
     * @param array $ids The cache identifiers to fetch
     *
     * @return array|\Traversable
     */
    abstract protected function doFetch(array $ids): iterable;

    /**
     * Confirms if the cache contains specified cache item.
     *
     * @param string $id The identifier for which to check existence
     */
    abstract protected function doHave(string $id): bool;

    /**
     * Deletes all items in the pool.
     *
     * @param string $namespace The prefix used for all identifiers managed by this pool
     */
    abstract protected function doClear(string $namespace): bool;

    /**
     * Removes multiple items from the pool.
     *
     * @param array $ids An array of identifiers that should be removed from the pool
     */
    abstract protected function doDelete(array $ids): bool;

    /**
     * Persists several cache items immediately.
     *
     * @param array $values   The values to cache, indexed by their cache identifier
     * @param int   $lifetime The lifetime of the cached values, 0 for persisting until manual cleaning
     *
     * @return array|bool The identifiers that failed to be cached or a boolean stating if caching succeeded or not
     */
    abstract protected function doSave(array $values, int $lifetime): array|bool;

    /**
     * {@inheritdoc}
     */
    public function hasItem(mixed $key): bool
    {
        $id = $this->getId($key);

        if (isset($this->deferred[$key])) {
            $this->commit();
        }

        try {
            return $this->doHave($id);
        } catch (\Exception $e) {
            CacheItem::log($this->logger, 'Failed to check if key "{key}" is cached: '.$e->getMessage(), ['key' => $key, 'exception' => $e, 'cache-adapter' => get_debug_type($this)]);

            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function clear(string $prefix = ''): bool
    {
        $this->deferred = [];
        if ($cleared = $this->versioningIsEnabled) {
            if ('' === $namespaceVersionToClear = $this->namespaceVersion) {
                foreach ($this->doFetch([static::NS_SEPARATOR.$this->namespace]) as $v) {
                    $namespaceVersionToClear = $v;
                }
            }
            $namespaceToClear = $this->namespace.$namespaceVersionToClear;
            $namespaceVersion = strtr(substr_replace(base64_encode(pack('V', mt_rand())), static::NS_SEPARATOR, 5), '/', '_');
            try {
                $cleared = $this->doSave([static::NS_SEPARATOR.$this->namespace => $namespaceVersion], 0);
            } catch (\Exception $e) {
                $cleared = false;
            }
            if ($cleared = true === $cleared || [] === $cleared) {
                $this->namespaceVersion = $namespaceVersion;
                $this->ids = [];
            }
        } else {
            $namespaceToClear = $this->namespace.$prefix;
        }

        try {
            return $this->doClear($namespaceToClear) || $cleared;
        } catch (\Exception $e) {
            CacheItem::log($this->logger, 'Failed to clear the cache: '.$e->getMessage(), ['exception' => $e, 'cache-adapter' => get_debug_type($this)]);

            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function deleteItem(mixed $key): bool
    {
        return $this->deleteItems([$key]);
    }

    /**
     * {@inheritdoc}
     */
    public function deleteItems(array $keys): bool
    {
        $ids = [];

        foreach ($keys as $key) {
            $ids[$key] = $this->getId($key);
            unset($this->deferred[$key]);
        }

        try {
            if ($this->doDelete($ids)) {
                return true;
            }
        } catch (\Exception $e) {
        }

        $ok = true;

        // When bulk-delete failed, retry each item individually
        foreach ($ids as $key => $id) {
            try {
                $e = null;
                if ($this->doDelete([$id])) {
                    continue;
                }
            } catch (\Exception $e) {
            }
            $message = 'Failed to delete key "{key}"'.($e instanceof \Exception ? ': '.$e->getMessage() : '.');
            CacheItem::log($this->logger, $message, ['key' => $key, 'exception' => $e, 'cache-adapter' => get_debug_type($this)]);
            $ok = false;
        }

        return $ok;
    }

    /**
     * {@inheritdoc}
     */
    public function getItem(mixed $key): CacheItem
    {
        if ($this->deferred) {
            $this->commit();
        }
        $id = $this->getId($key);

        $isHit = false;
        $value = null;

        try {
            foreach ($this->doFetch([$id]) as $value) {
                $isHit = true;
            }

            return (self::$createCacheItem)($key, $value, $isHit);
        } catch (\Exception $e) {
            CacheItem::log($this->logger, 'Failed to fetch key "{key}": '.$e->getMessage(), ['key' => $key, 'exception' => $e, 'cache-adapter' => get_debug_type($this)]);
        }

        return (self::$createCacheItem)($key, null, false);
    }

    /**
     * {@inheritdoc}
     */
    public function getItems(array $keys = []): iterable
    {
        if ($this->deferred) {
            $this->commit();
        }
        $ids = [];

        foreach ($keys as $key) {
            $ids[] = $this->getId($key);
        }
        try {
            $items = $this->doFetch($ids);
        } catch (\Exception $e) {
            CacheItem::log($this->logger, 'Failed to fetch items: '.$e->getMessage(), ['keys' => $keys, 'exception' => $e, 'cache-adapter' => get_debug_type($this)]);
            $items = [];
        }
        $ids = array_combine($ids, $keys);

        return $this->generateItems($items, $ids);
    }

    /**
     * {@inheritdoc}
     */
    public function save(CacheItemInterface $item): bool
    {
        if (!$item instanceof CacheItem) {
            return false;
        }
        $this->deferred[$item->getKey()] = $item;

        return $this->commit();
    }

    /**
     * {@inheritdoc}
     */
    public function saveDeferred(CacheItemInterface $item): bool
    {
        if (!$item instanceof CacheItem) {
            return false;
        }
        $this->deferred[$item->getKey()] = $item;

        return true;
    }

    /**
     * Enables/disables versioning of items.
     *
     * When versioning is enabled, clearing the cache is atomic and doesn't require listing existing keys to proceed,
     * but old keys may need garbage collection and extra round-trips to the back-end are required.
     *
     * Calling this method also clears the memoized namespace version and thus forces a resynchonization of it.
     *
     * @return bool the previous state of versioning
     */
    public function enableVersioning(bool $enable = true): bool
    {
        $wasEnabled = $this->versioningIsEnabled;
        $this->versioningIsEnabled = $enable;
        $this->namespaceVersion = '';
        $this->ids = [];

        return $wasEnabled;
    }

    /**
     * {@inheritdoc}
     */
    public function reset()
    {
        if ($this->deferred) {
            $this->commit();
        }
        $this->namespaceVersion = '';
        $this->ids = [];
    }

    public function __sleep(): array
    {
        throw new \BadMethodCallException('Cannot serialize '.__CLASS__);
    }

    public function __wakeup()
    {
        throw new \BadMethodCallException('Cannot unserialize '.__CLASS__);
    }

    public function __destruct()
    {
        if ($this->deferred) {
            $this->commit();
        }
    }

    private function generateItems(iterable $items, array &$keys): \Generator
    {
        $f = self::$createCacheItem;

        try {
            foreach ($items as $id => $value) {
                if (!isset($keys[$id])) {
                    throw new InvalidArgumentException(sprintf('Could not match value id "%s" to keys "%s".', $id, implode('", "', $keys)));
                }
                $key = $keys[$id];
                unset($keys[$id]);
                yield $key => $f($key, $value, true);
            }
        } catch (\Exception $e) {
            CacheItem::log($this->logger, 'Failed to fetch items: '.$e->getMessage(), ['keys' => array_values($keys), 'exception' => $e, 'cache-adapter' => get_debug_type($this)]);
        }

        foreach ($keys as $key) {
            yield $key => $f($key, null, false);
        }
    }

    private function getId(mixed $key)
    {
        if ($this->versioningIsEnabled && '' === $this->namespaceVersion) {
            $this->ids = [];
            $this->namespaceVersion = '1'.static::NS_SEPARATOR;
            try {
                foreach ($this->doFetch([static::NS_SEPARATOR.$this->namespace]) as $v) {
                    $this->namespaceVersion = $v;
                }
                if ('1'.static::NS_SEPARATOR === $this->namespaceVersion) {
                    $this->namespaceVersion = strtr(substr_replace(base64_encode(pack('V', time())), static::NS_SEPARATOR, 5), '/', '_');
                    $this->doSave([static::NS_SEPARATOR.$this->namespace => $this->namespaceVersion], 0);
                }
            } catch (\Exception $e) {
            }
        }

        if (\is_string($key) && isset($this->ids[$key])) {
            return $this->namespace.$this->namespaceVersion.$this->ids[$key];
        }
        \assert('' !== CacheItem::validateKey($key));
        $this->ids[$key] = $key;

        if (null === $this->maxIdLength) {
            return $this->namespace.$this->namespaceVersion.$key;
        }
        if (\strlen($id = $this->namespace.$this->namespaceVersion.$key) > $this->maxIdLength) {
            // Use MD5 to favor speed over security, which is not an issue here
            $this->ids[$key] = $id = substr_replace(base64_encode(hash('md5', $key, true)), static::NS_SEPARATOR, -(\strlen($this->namespaceVersion) + 2));
            $id = $this->namespace.$this->namespaceVersion.$id;
        }

        return $id;
    }

    /**
     * @internal
     */
    public static function handleUnserializeCallback(string $class)
    {
        throw new \DomainException('Class not found: '.$class);
    }
}
