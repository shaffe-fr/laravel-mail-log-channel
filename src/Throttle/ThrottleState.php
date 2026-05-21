<?php

namespace Shaffe\MailLogChannel\Throttle;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Monolog\LogRecord;

class ThrottleState
{
    protected CacheRepository $cache;

    protected int $ttl;

    protected string $cachePrefix;

    public function __construct(CacheRepository $cache, int $ttl = 60, string $cachePrefix = 'mail_log_throttle:')
    {
        $this->cache = $cache;
        $this->ttl = $ttl;
        $this->cachePrefix = $cachePrefix;
    }

    /**
     * Determine if the given log record should be throttled (i.e., skipped).
     */
    public function isThrottled(LogRecord $record): bool
    {
        $fingerprint = $this->fingerprint($record);
        $lockKey = $this->cachePrefix.$fingerprint;
        $countKey = $this->cachePrefix.'count:'.$fingerprint;
        $firstSeenKey = $this->cachePrefix.'first:'.$fingerprint;

        // Record the first occurrence timestamp (only set once, sliding TTL)
        if (! $this->cache->has($firstSeenKey)) {
            $this->cache->put($firstSeenKey, time(), $this->ttl * 2);
        } else {
            // Extend TTL as long as the error keeps happening
            $this->cache->put($firstSeenKey, $this->cache->get($firstSeenKey), $this->ttl * 2);
        }

        // Increment the total occurrence counter (sliding TTL)
        // Initialize the key if it doesn't exist (some drivers don't support increment on missing keys)
        if (! $this->cache->has($countKey)) {
            $this->cache->put($countKey, 1, $this->ttl * 2);
        } else {
            $newCount = $this->cache->increment($countKey);
            $this->cache->put($countKey, (int) $newCount, $this->ttl * 2);
        }

        if ($this->cache->has($lockKey)) {
            return true;
        }

        // Not throttled — set the lock
        $this->cache->put($lockKey, true, $this->ttl);

        return false;
    }

    /**
     * Get the total number of occurrences for this record (including the current one).
     */
    public function getOccurrenceCount(LogRecord $record): int
    {
        $fingerprint = $this->fingerprint($record);
        $countKey = $this->cachePrefix.'count:'.$fingerprint;

        return (int) $this->cache->get($countKey, 1);
    }

    /**
     * Get the timestamp of the first occurrence of this record.
     */
    public function getFirstSeenAt(LogRecord $record): ?int
    {
        $fingerprint = $this->fingerprint($record);
        $firstSeenKey = $this->cachePrefix.'first:'.$fingerprint;

        $timestamp = $this->cache->get($firstSeenKey);

        return $timestamp !== null ? (int) $timestamp : null;
    }

    /**
     * Generate a fingerprint for the log record based on exception details or message.
     */
    public function fingerprint(LogRecord $record): string
    {
        $context = $record->context;
        $exception = $context['exception'] ?? null;

        if ($exception instanceof \Throwable) {
            return md5(
                get_class($exception)
                .$exception->getCode()
                .$exception->getMessage()
                .$exception->getFile()
                .$exception->getLine()
            );
        }

        // Fallback for non-exception log records: use channel + level + message
        return md5(
            $record->channel
            .$record->level->value
            .$record->message
        );
    }
}
