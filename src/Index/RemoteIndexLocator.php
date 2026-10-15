<?php

declare(strict_types=1);

namespace Tag1\Scolta\Index;

use GuzzleHttp\ClientInterface;
use Tag1\Scolta\Cache\CacheDriverInterface;

/**
 * Finds an index another host serves, by fetching its entry file.
 *
 * It fetches the entry file rather than the directory: a host can answer 200
 * for a directory it serves no index from, and the entry file is the first
 * thing the browser asks for, so this fails exactly when the browser would.
 * It cannot see what only a browser enforces, such as CORS headers or the
 * content type the .wasm file is served with.
 *
 * Health endpoints are usually reachable anonymously so uptime monitors work,
 * and an unreachable host holds the request open for the full timeout. So the
 * answer, either way, is cached for a short while per origin when a cache is
 * given: a failure is the slow case, so it is cached too.
 *
 * @since 2.0.0
 * @stability experimental
 */
final class RemoteIndexLocator implements IndexLocatorInterface
{
    /**
     * Default seconds an answer is reused, well inside a monitor's window.
     *
     * @since 2.0.0
     * @stability experimental
     */
    public const DEFAULT_TTL = 60;

    private readonly string $baseUrl;

    /**
     * @param string $baseUrl The URL the remote pagefind/ directory sits
     *   under, e.g. https://search.example.com. A trailing slash is ignored.
     * @param ClientInterface $httpClient The client that fetches the entry
     *   file.
     * @param CacheDriverInterface|null $cache Where answers are reused from.
     *   Without one, every exists() call makes a request.
     * @param int $ttlSeconds How long an answer is reused.
     * @param float $timeout Seconds the whole request may take.
     * @param float $connectTimeout Seconds connecting may take.
     *
     * @since 2.0.0
     * @stability experimental
     */
    public function __construct(
        string $baseUrl,
        private readonly ClientInterface $httpClient,
        private readonly ?CacheDriverInterface $cache = null,
        private readonly int $ttlSeconds = self::DEFAULT_TTL,
        private readonly float $timeout = 5.0,
        private readonly float $connectTimeout = 3.0,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /**
     * {@inheritdoc}
     *
     * Any answer other than 200 from the entry file, or no answer at all,
     * counts as no index.
     *
     * @since 2.0.0
     * @stability experimental
     */
    public function exists(): bool
    {
        $key = 'scolta_remote_index_reachable:' . hash('sha256', $this->baseUrl);

        // Stored as a string: a cache driver answers null for a miss, so a
        // cached FALSE must not look like one.
        $cached = $this->cache?->get($key);
        if ($cached === 'yes' || $cached === 'no') {
            return $cached === 'yes';
        }

        $exists = $this->fetchEntryFile();
        $this->cache?->set($key, $exists ? 'yes' : 'no', $this->ttlSeconds);

        return $exists;
    }

    /**
     * {@inheritdoc}
     *
     * @since 2.0.0
     * @stability experimental
     */
    public function isRemote(): bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     *
     * @since 2.0.0
     * @stability experimental
     */
    public function origin(): string
    {
        return $this->baseUrl;
    }

    private function fetchEntryFile(): bool
    {
        try {
            $response = $this->httpClient->request('GET', $this->baseUrl . '/pagefind/pagefind-entry.json', [
                'timeout' => $this->timeout,
                'connect_timeout' => $this->connectTimeout,
                'http_errors' => false,
            ]);

            return $response->getStatusCode() === 200;
        } catch (\Throwable) {
            return false;
        }
    }
}
