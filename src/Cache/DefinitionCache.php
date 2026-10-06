<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Cache;

use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Cache\Repository;
use Psr\Log\LoggerInterface;
use RoundlyConsulting\OpeningHours\DataTransferObjects\CalendarData;
use RoundlyConsulting\OpeningHours\Engine\Definition;
use RoundlyConsulting\OpeningHours\Support\Settings;
use RoundlyConsulting\PackageToolkit\Support\Config;
use Throwable;
use UnexpectedValueException;

/**
 * Caches a calendar's canonical definition (plain arrays — never serialized
 * objects) under `{prefix}:{calendarId}:{revision}:v{format}`. Every write
 * bumps the revision inside its transaction, so a reader can never store stale
 * data under a current key; old keys simply expire. The manager never stores a
 * definition read inside an open transaction: a rollback reuses that revision.
 * Works on every store (no tags).
 */
final readonly class DefinitionCache
{
    public function __construct(
        private Factory $cache,
        private LoggerInterface $logger,
    ) {}

    public function enabled(): bool
    {
        return Config::boolean('opening-hours.cache.enabled', true);
    }

    public function key(int $calendarId, int $revision): string
    {
        return Settings::cachePrefix().":{$calendarId}:{$revision}:v".Definition::FORMAT;
    }

    public function get(int $calendarId, int $revision): ?CalendarData
    {
        if (! $this->enabled()) {
            return null;
        }

        $key = $this->key($calendarId, $revision);
        $payload = $this->store()->get($key);

        if ($payload === null) {
            return null;
        }

        try {
            if (! is_array($payload)) {
                throw new UnexpectedValueException('Cached opening hours payload is not an array.');
            }

            return CalendarData::fromTrustedArray($payload);
        } catch (Throwable $exception) {
            // A corrupt or foreign payload is a cache miss, never an error.
            $this->store()->forget($key);
            $this->logger->debug('opening-hours: discarded an unreadable cached definition', ['key' => $key, 'error' => $exception->getMessage()]);

            return null;
        }
    }

    public function put(int $calendarId, int $revision, CalendarData $data): void
    {
        if (! $this->enabled()) {
            return;
        }

        $key = $this->key($calendarId, $revision);
        $ttl = $this->ttl();

        if ($ttl === null) {
            $this->store()->forever($key, $data->toArray());
        } else {
            $this->store()->put($key, $data->toArray(), $ttl);
        }
    }

    public function forget(int $calendarId, int $revision): void
    {
        $this->store()->forget($this->key($calendarId, $revision));
    }

    public function ttl(): ?int
    {
        // Null means forever; read it raw because the toolkit accessor maps null to its default.
        // A blank `OPENING_HOURS_CACHE_TTL=` is not set, so it takes the default (a day), not forever.
        if (config('opening-hours.cache.ttl') === null) {
            return null;
        }

        return Config::integer('opening-hours.cache.ttl', 86400, min: 1, max: 31_536_000);
    }

    public function store(): Repository
    {
        return $this->cache->store(Settings::cacheStore());
    }
}
