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

        // Record the first occurrence timestamp using add() which is atomic:
        // it only writes if the key doesn't already exist.
        $this->cache->add($firstSeenKey, time(), $this->ttl * 2);

        // Increment the total occurrence counter atomically.
        // Use add() to initialize if missing, then increment for subsequent hits.
        if (! $this->cache->add($countKey, 1, $this->ttl * 2)) {
            $this->cache->increment($countKey);
        }

        // Check and set the throttle lock atomically using add().
        // add() returns false if the key already exists (= we are throttled).
        if (! $this->cache->add($lockKey, true, $this->ttl)) {
            return true;
        }

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
