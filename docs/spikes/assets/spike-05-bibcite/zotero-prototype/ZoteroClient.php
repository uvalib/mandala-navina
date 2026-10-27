<?php

/**
 * Spike 5 prototype: minimal Zotero API v3 reader. Throwaway, not a module.
 *
 * Public libraries need no credentials; a private one takes an API key sent
 * as a header (never put the key in a URL, config export, or node).
 */
class ZoteroClient {

  public function __construct(
    private string $libraryType,
    private int $libraryId,
    private ?string $apiKey = NULL,
  ) {}

  /**
   * Yields top-level items. The API accepts only ONE negation in itemType, so
   * attachments are filtered server-side and standalone notes are skipped by the
   * mapper (D7 skipped both). Follows the API's Link rel="next" paging.
   *
   * @param int|null $since
   *   Library version; only items changed after it are returned (D7 re-fetched
   *   everything every run and relied on a content hash).
   */
  public function items(?string $collectionKey = NULL, ?int $since = NULL, int $max = PHP_INT_MAX): Generator {
    $base = "https://api.zotero.org/{$this->libraryType}s/{$this->libraryId}";
    $path = $collectionKey ? "$base/collections/$collectionKey/items/top" : "$base/items/top";
    $query = ['format' => 'json', 'limit' => 100, 'itemType' => '-attachment'];
    if ($since !== NULL) {
      $query['since'] = $since;
    }
    $url = $path . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    $n = 0;
    while ($url && $n < $max) {
      [$headers, $body] = $this->get($url);
      foreach (json_decode($body, TRUE, 512, JSON_THROW_ON_ERROR) as $item) {
        if ($n++ >= $max) {
          return;
        }
        yield $item;
      }
      $url = NULL;
      if (preg_match('/<([^>]+)>;\s*rel="next"/', $headers['link'] ?? '', $m)) {
        $url = $m[1];
      }
    }
  }

  private function get(string $url): array {
    $headers = [];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => TRUE,
      CURLOPT_TIMEOUT => 30,
      CURLOPT_HTTPHEADER => array_filter([
        'Zotero-API-Version: 3',
        $this->apiKey ? 'Zotero-API-Key: ' . $this->apiKey : NULL,
      ]),
      CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$headers) {
        if (str_contains($line, ':')) {
          [$k, $v] = explode(':', $line, 2);
          $headers[strtolower(trim($k))] = trim($v);
        }
        return strlen($line);
      },
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($status === 429 || isset($headers['backoff'])) {
      // The API asks clients to wait; a real importer must honour this.
      sleep((int) ($headers['retry-after'] ?? $headers['backoff'] ?? 5));
    }
    if ($status !== 200) {
      throw new RuntimeException("Zotero API returned HTTP $status for $url");
    }
    return [$headers, $body];
  }

}
