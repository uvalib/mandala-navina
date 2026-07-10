<?php

declare(strict_types=1);

namespace Drupal\mandala_solr_visibility;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Reads/writes the mandala_solr_fq:{uid} Redis key (ADR 014).
 *
 * This is the write side of the shared Redis instance the solr-proxy reads
 * from (proxy/Searcher.php::getVisibilityToken()). A Redis outage here must
 * not break login/logout/membership changes — failures are logged and
 * swallowed. The proxy already fails closed to the anonymous filter on a
 * Redis miss, so a failed write here just means that user gets the
 * anonymous-level filter until the next successful write, not a crash.
 */
class VisibilityTokenStore {

  protected const KEY_PREFIX = 'mandala_solr_fq:';

  protected ConfigFactoryInterface $configFactory;

  protected LoggerInterface $logger;

  protected ?\Redis $redis = NULL;

  protected bool $connectionFailed = FALSE;

  public function __construct(ConfigFactoryInterface $config_factory, LoggerChannelFactoryInterface $logger_factory) {
    $this->configFactory = $config_factory;
    $this->logger = $logger_factory->get('mandala_solr_visibility');
  }

  /**
   * Writes the visibility fq for a uid, with the configured TTL.
   */
  public function write(int $uid, string $fq): void {
    $redis = $this->getConnection();
    if ($redis === NULL) {
      return;
    }
    $ttl = (int) $this->configFactory->get('mandala_solr_visibility.settings')->get('ttl');
    try {
      $redis->setex(self::KEY_PREFIX . $uid, $ttl, $fq);
    }
    catch (\RedisException $e) {
      $this->logger->error('Failed to write visibility token for uid @uid: @message', [
        '@uid' => $uid,
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Deletes the visibility token for a uid (logout, or "no filter" cases).
   */
  public function delete(int $uid): void {
    $redis = $this->getConnection();
    if ($redis === NULL) {
      return;
    }
    try {
      $redis->del(self::KEY_PREFIX . $uid);
    }
    catch (\RedisException $e) {
      $this->logger->error('Failed to delete visibility token for uid @uid: @message', [
        '@uid' => $uid,
        '@message' => $e->getMessage(),
      ]);
    }
  }

  protected function getConnection(): ?\Redis {
    if ($this->redis !== NULL) {
      return $this->redis;
    }
    if ($this->connectionFailed) {
      // Don't retry a connection within the same request once it's failed.
      return NULL;
    }

    $settings = $this->configFactory->get('mandala_solr_visibility.settings');
    try {
      $redis = new \Redis();
      $redis->connect($settings->get('redis_host'), (int) $settings->get('redis_port'), 1.0);
      $this->redis = $redis;
      return $this->redis;
    }
    catch (\RedisException $e) {
      $this->connectionFailed = TRUE;
      $this->logger->error('Redis connection failed: @message', ['@message' => $e->getMessage()]);
      return NULL;
    }
  }

}
