<?php

namespace Drupal\shanti_kmaps_fields\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Autocomplete controller for KMaps term search.
 *
 * Proxies queries to the kmterms Solr index and returns D10 autocomplete JSON.
 * The widget value format stored in 'raw' is: id|header|domain|path|defids
 */
class KmapsAutocompleteController extends ControllerBase {

  public function autocomplete(string $domain, Request $request): JsonResponse {
    $input = $request->query->get('q', '');
    $input = trim($input);

    if ($input === '') {
      return new JsonResponse([]);
    }

    $config = \Drupal::config('shanti_kmaps_admin.settings');
    $solr_url = $config->get('server_solr_terms');

    if (empty($solr_url)) {
      return new JsonResponse([['value' => '', 'label' => 'KMaps Solr not configured — visit /admin/config/content/shanti-kmaps-admin']]);
    }

    $results = $this->querySolr($solr_url, $domain, $input);
    return new JsonResponse($results);
  }

  private function querySolr(string $solr_url, string $domain, string $input): array {
    $escaped = $this->escapeSolrQuery($input);

    // Search on header (name) with prefix match, filtered to the requested domain.
    $params = http_build_query([
      'q'    => "header:{$escaped}* OR name_autocomplete:{$escaped}*",
      'fq'   => "tree:{$domain}",
      'fl'   => 'id,header,ancestor_id_path',
      'rows' => 20,
      'wt'   => 'json',
    ]);

    $url = rtrim($solr_url, '/') . '/select?' . $params;

    try {
      $client = \Drupal::httpClient();
      $response = $client->get($url, ['timeout' => 5]);
      $body = json_decode($response->getBody()->getContents(), TRUE);
    }
    catch (\Exception $e) {
      // Solr unreachable (e.g. no VPN) — return a helpful hint.
      return [[
        'value' => '',
        'label' => 'KMaps Solr unreachable: ' . $e->getMessage(),
      ]];
    }

    $matches = [];
    foreach ($body['response']['docs'] ?? [] as $doc) {
      // id format from Solr: "subjects-385"
      $parts = explode('-', $doc['id'] ?? '', 2);
      if (count($parts) !== 2) {
        continue;
      }
      [$doc_domain, $kmap_id] = $parts;
      $header = $doc['header'] ?? '';
      $ancestorPath = $doc['ancestor_id_path'] ?? [];
      $path = implode('/', is_array($ancestorPath) ? $ancestorPath : [$ancestorPath]);

      // raw format: id|header|domain|path|defids
      $raw = implode('|', [$kmap_id, $header, $doc_domain, $path, '']);
      $label = "{$header} ({$doc_domain}-{$kmap_id})";

      // value = label so Drupal's autocomplete shows readable text in the input.
      // raw is a custom property read by kmaps_widget.js to populate the hidden field.
      $matches[] = [
        'value' => $label,
        'label' => $label,
        'raw'   => $raw,
      ];
    }

    return $matches;
  }

  private function escapeSolrQuery(string $input): string {
    // Escape Solr special characters.
    $special = ['\\', '+', '-', '&&', '||', '!', '(', ')', '{', '}', '[', ']', '^', '"', '~', '*', '?', ':', '/'];
    foreach ($special as $char) {
      $input = str_replace($char, '\\' . $char, $input);
    }
    return $input;
  }

}
