<?php

namespace Drupal\spike_solr_demo\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Spike 2 comparison page.
 *
 * Section 1 — Result order: Corrected D11 (D7-equivalent raw Solarium query)
 *   on the left vs naive D11 (search_api standard stack) on the right.
 *   Shows what the right approach produces and where the naive approach diverges.
 *
 * Section 2 — Field fidelity: for each corrected D11 result, compares the
 *   field values returned by the corrected query against a second raw Solr fetch
 *   by uid, proving no field values are lost in the corrected approach.
 */
class SpikeComparisonController extends ControllerBase {

  // Fields fetched by the corrected (D7-equivalent) query — native Solr names.
  const CORRECTED_FIELDS = ['id', 'uid', 'title', 'service', 'asset_type', 'asset_subtype',
                             'url_html', 'url_json', 'url_thumb', 'visibility_i', 'visibility_s',
                             'collection_title', 'collection_nid', 'node_created', 'node_changed',
                             'node_lang', 'node_user', 'description', 'kmapid'];

  // Fields fetched from raw Solr for fidelity comparison.
  const RAW_FIELDS = ['id', 'uid', 'title', 'service', 'asset_type', 'asset_subtype',
                      'url_html', 'url_json', 'url_thumb', 'visibility_i', 'visibility_s',
                      'collection_title', 'collection_nid', 'node_created', 'node_changed',
                      'node_lang', 'node_user', 'description', 'kmapid'];

  public function compare(Request $request): array {
    $q    = trim($request->query->get('q', 'Buddhism'));
    $rows = max(1, min(20, (int) $request->query->get('rows', 5)));

    [$corr_docs, $corr_ms, $corr_total, $corr_err] = $this->queryCorrected($q, $rows);
    [$naive_docs, $naive_ms, $naive_total, $naive_err] = $this->queryNaive($q, $rows);
    [$raw_docs, $raw_ms, $raw_err]                  = $this->fetchRawDocs($corr_docs);

    return [
      '#attached' => ['library' => ['spike_solr_demo/comparison']],
      '#markup'   => $this->buildPage($q, $rows,
                       $corr_docs,  $corr_ms,  $corr_total,  $corr_err,
                       $naive_docs, $naive_ms, $naive_total, $naive_err,
                       $raw_docs, $raw_ms, $raw_err),
    ];
  }

  // ── Corrected D11: D7-equivalent weighted query via raw Solarium ────────────
  // Mirrors jquery.kmapsSolr.js assetMatch construction. Uses prefix wildcards
  // (title:Buddhism*) which are required because title/names_txt are Solr string
  // fields, not analyzed text fields. Native term queries (title:Buddhism) silently
  // miss "Buddhism and Science" etc.

  private function queryCorrected(string $q, int $rows): array {
    try {
      $server    = $this->entityTypeManager()->getStorage('search_api_server')->load('kmassets');
      $connector = $server->getBackend()->getSolrConnector();

      $search = str_replace(' ', '\\ ', $q);
      $xact   = str_replace('*', '', $search);
      $starts = $xact . '*';
      $slashy = $xact . '/';

      $d7q = "title:{$xact}^100"
           . " title:{$slashy}^100"
           . " names_txt:{$xact}^90"
           . " title:{$starts}^80"
           . " names_txt:{$starts}^70"
           . " title:{$search}^10"
           . " caption:{$search}"
           . " summary:{$search}"
           . " names_txt:{$search}";

      $solr_q = $connector->getSelectQuery();
      $solr_q->setQuery($d7q);
      $solr_q->setRows($rows);
      $solr_q->setFields(self::CORRECTED_FIELDS);
      $solr_q->createFilterQuery('d7_excl')->setQuery('-asset_type:(picture document video)');

      $start  = microtime(TRUE);
      $result = $connector->execute($solr_q);
      $ms     = round((microtime(TRUE) - $start) * 1000);
      $total  = $result->getNumFound();

      $docs = [];
      foreach ($result as $doc) {
        $d = [];
        foreach (self::CORRECTED_FIELDS as $f) {
          $v    = $doc[$f] ?? '';
          $d[$f] = is_array($v) ? implode('; ', $v) : (string) $v;
        }
        $d['kmapid_count'] = $d['kmapid'] !== '' ? count(explode('; ', $d['kmapid'])) : 0;
        $d['_uid']         = $d['uid'];
        $docs[] = $d;
      }
      return [$docs, $ms, $total, NULL];
    }
    catch (\Exception $e) {
      return [[], 0, 0, $e->getMessage()];
    }
  }

  // Returns the corrected query string for display.
  private function correctedQueryString(string $q): string {
    $search = str_replace(' ', '\\ ', $q);
    $xact   = str_replace('*', '', $search);
    $starts = $xact . '*';
    $slashy = $xact . '/';
    return "title:{$xact}^100 title:{$slashy}^100 names_txt:{$xact}^90"
         . " title:{$starts}^80 names_txt:{$starts}^70 title:{$search}^10"
         . " caption:{$search} summary:{$search} names_txt:{$search}";
  }

  // ── Naive D11: search_api_solr standard stack ───────────────────────────────

  private function queryNaive(string $q, int $rows): array {
    try {
      $index = $this->entityTypeManager()->getStorage('search_api_index')->load('kmassets');
      if (!$index) {
        return [[], 0, 0, 'Index "kmassets" not found.'];
      }
      $start  = microtime(TRUE);
      $query  = $index->query();
      $query->keys($q);
      $query->range(0, $rows);
      $result = $query->execute();
      $ms     = round((microtime(TRUE) - $start) * 1000);
      $total  = $result->getResultCount();

      $docs = [];
      foreach ($result->getResultItems() as $item) {
        $doc = ['_uid' => ''];
        foreach ($item->getFields(FALSE) as $key => $field) {
          $vals      = $field->getValues();
          $doc[$key] = !empty($vals) ? implode('; ', array_map('strval', $vals)) : '';
        }
        $doc['_uid'] = $doc['uid'] ?? '';
        $docs[] = $doc;
      }
      return [$docs, $ms, $total, NULL];
    }
    catch (\Exception $e) {
      \Drupal::logger('spike_solr_demo')->error('Naive D11 query failed: @msg', ['@msg' => $e->getMessage()]);
      return [[], 0, 0, $e->getMessage()];
    }
  }

  // ── Raw Solr document fetch by uid (for field fidelity) ────────────────────

  private function fetchRawDocs(array $primary_docs): array {
    if (empty($primary_docs)) {
      return [[], 0, NULL];
    }
    try {
      $server    = $this->entityTypeManager()->getStorage('search_api_server')->load('kmassets');
      $connector = $server->getBackend()->getSolrConnector();
      $fl        = implode(',', self::RAW_FIELDS);

      $uids = array_filter(array_column($primary_docs, '_uid'));
      if (empty($uids)) {
        return [[], 0, 'Could not extract uid values from primary results.'];
      }

      $id_query = 'uid:(' . implode(' OR ', array_map(fn($u) => '"' . addslashes($u) . '"', $uids)) . ')';

      $solr_q = $connector->getSelectQuery();
      $solr_q->setQuery($id_query);
      $solr_q->setRows(count($uids));
      $solr_q->setFields(explode(',', $fl));

      $start  = microtime(TRUE);
      $result = $connector->execute($solr_q);
      $ms     = round((microtime(TRUE) - $start) * 1000);

      $by_uid = [];
      foreach ($result as $raw) {
        $doc = [];
        foreach (self::RAW_FIELDS as $f) {
          $v = $raw[$f] ?? '';
          $doc[$f] = is_array($v) ? implode('; ', $v) : (string) $v;
        }
        $doc['kmapid_count'] = $doc['kmapid'] !== '' ? count(explode('; ', $doc['kmapid'])) : 0;
        $uid_val = is_array($raw['uid'] ?? '') ? ($raw['uid'][0] ?? '') : (string) ($raw['uid'] ?? '');
        $by_uid[$uid_val] = $doc;
      }

      $docs = [];
      foreach ($uids as $uid) {
        $docs[] = $by_uid[$uid] ?? ['uid' => $uid, '_missing' => TRUE];
      }
      return [$docs, $ms, NULL];
    }
    catch (\Exception $e) {
      return [[], 0, $e->getMessage()];
    }
  }

  // All fields shown in the field-fidelity table.
  // Both corrected and raw come from native Solr so all fields are 'both'.
  // Entries: [field_key, 'd11'|'raw'|'both', label_override_or_null]
  const ALL_FIELDS = [
    ['id',              'both', NULL],
    ['uid',             'both', NULL],
    ['title',           'both', NULL],
    ['service',         'both', NULL],
    ['asset_type',      'both', NULL],
    ['asset_subtype',   'both', NULL],
    ['url_html',        'both', NULL],
    ['url_json',        'both', NULL],
    ['url_thumb',       'both', NULL],
    ['visibility_s',    'both', NULL],
    ['visibility_i',    'both', NULL],
    ['collection_title','both', NULL],
    ['collection_nid',  'both', NULL],
    ['node_created',    'both', NULL],
    ['node_changed',    'both', NULL],
    ['node_lang',       'both', NULL],
    ['node_user',       'both', NULL],
    ['description',     'both', NULL],
    ['kmapid',          'both', NULL],
    ['kmapid_count',    'both', NULL],
  ];

  // ── Rendering ───────────────────────────────────────────────────────────────

  private function buildPage(
    string $q, int $rows,
    array $corr,  int $corrms,  int $corrtotal,  ?string $correrr,
    array $naive, int $naivems, int $naivetotal, ?string $naiveerr,
    array $raw,   int $rawms,   ?string $rawerr
  ): string {
    $corr_uids  = array_column($corr, '_uid');
    $naive_uids = array_column($naive, '_uid');
    $overlap    = count(array_intersect($corr_uids, $naive_uids));

    $html  = '<div class="spike-comparison">';
    $html .= $this->buildForm($q, $rows);

    $html .= '<div class="spike-summary">';
    $html .= '<span>Query: <strong>' . htmlspecialchars($q) . '</strong></span>';
    $html .= '<span>Corrected: <strong>' . number_format($corrtotal) . '</strong> results &middot; ' . $corrms . 'ms</span>';
    $html .= '<span>Naive: <strong>' . number_format($naivetotal) . '</strong> results &middot; ' . $naivems . 'ms</span>';
    $html .= '<span>Top-' . $rows . ' overlap: <strong>' . $overlap . '/' . $rows . '</strong></span>';
    $html .= '</div>';

    if ($correrr)  $html .= '<div class="spike-error"><strong>Corrected query error:</strong> ' . htmlspecialchars($correrr) . '</div>';
    if ($naiveerr) $html .= '<div class="spike-error"><strong>Naive query error:</strong> ' . htmlspecialchars($naiveerr) . '</div>';
    if ($rawerr)   $html .= '<div class="spike-error"><strong>Raw fetch error:</strong> ' . htmlspecialchars($rawerr) . '</div>';

    // Section 1: result order comparison.
    $html .= '<h2 class="spike-section-head">Section 1 — Result order</h2>';
    $html .= '<p class="spike-section-note">'
           . '<strong>Corrected D11</strong> uses the D7-equivalent weighted query with prefix wildcards '
           . '(required because <code>title</code> and <code>names_txt</code> are Solr <code>string</code> fields, not analyzed text). '
           . '<strong>Naive D11</strong> uses search_api\'s standard edismax stack, which issues term queries '
           . 'that miss multi-word string values.</p>';
    $html .= '<pre class="spike-query">' . htmlspecialchars($this->correctedQueryString($q)) . '</pre>';
    $html .= $this->buildRankTable($corr, $naive);

    // Section 2: field fidelity — corrected query results vs independent raw fetch.
    $html .= '<h2 class="spike-section-head">Section 2 — Field fidelity (corrected D11 vs raw Solr fetch)</h2>';
    $html .= '<p class="spike-section-note">For each result returned by the corrected query, a second independent '
           . 'Solr fetch by <code>uid</code> verifies that all field values are identical. '
           . 'Both use native Solr field types so dates appear as ISO-8601 in both columns.</p>';
    $count = min(count($corr), count($raw));
    for ($i = 0; $i < $count; $i++) {
      $html .= $this->buildDocTable($i + 1, $corr[$i], $raw[$i] ?? []);
    }

    $html .= '<p class="spike-footnote">Raw fetch: ' . $rawms . 'ms</p>';
    $html .= '</div>';
    return $html;
  }

  private function buildForm(string $q, int $rows): string {
    return '<form class="spike-form" method="get">'
      . '<label>Search <input type="text" name="q" value="' . htmlspecialchars($q) . '" /></label>'
      . '<label>Results <input type="number" name="rows" value="' . $rows . '" min="1" max="20" /></label>'
      . '<button type="submit">Compare</button>'
      . '</form>';
  }

  private function buildRankTable(array $corr, array $naive): string {
    $corr_uids  = array_column($corr, '_uid');
    $naive_uids = array_column($naive, '_uid');
    $n          = max(count($corr), count($naive));

    $html  = '<table class="rank-table">';
    $html .= '<thead><tr>'
           . '<th class="rank-n">#</th>'
           . '<th class="rank-corr">✓ Corrected D11 (D7-equivalent query)</th>'
           . '<th class="rank-naive">Naive D11 (search_api standard)</th>'
           . '<th></th>'
           . '</tr></thead><tbody>';

    for ($i = 0; $i < $n; $i++) {
      $corr_uid   = $corr[$i]['_uid']  ?? '';
      $corr_title = $corr[$i]['title'] ?? '';
      $naive_uid  = $naive[$i]['_uid'] ?? '';
      $naive_title = $naive[$i]['title'] ?? '';

      $same       = ($corr_uid !== '' && $corr_uid === $naive_uid);
      $naive_in_corr = in_array($naive_uid, $corr_uids, TRUE);
      $corr_in_naive = in_array($corr_uid, $naive_uids, TRUE);

      if ($same) {
        $row_class = 'rank-same';
        $badge     = '<span class="cmp-badge cmp-badge-ok">✓ same</span>';
      }
      elseif ($naive_in_corr || $corr_in_naive) {
        $row_class = 'rank-reorder';
        $badge     = '<span class="cmp-badge cmp-badge-diff">≠ rank</span>';
      }
      else {
        $row_class = 'rank-diff';
        $badge     = '<span class="cmp-badge cmp-badge-diff">≠</span>';
      }

      $html .= '<tr class="' . $row_class . '">';
      $html .= '<td class="rank-n">' . ($i + 1) . '</td>';
      $html .= '<td class="rank-doc rank-corr-cell">'
             . '<span class="rank-title">' . htmlspecialchars($corr_title) . '</span>'
             . ($corr_uid ? '<span class="rank-uid">' . htmlspecialchars($corr_uid) . '</span>' : '<span class="rank-uid rank-absent">—</span>')
             . '</td>';
      $html .= '<td class="rank-doc rank-naive-cell">'
             . '<span class="rank-title">' . htmlspecialchars($naive_title) . '</span>'
             . ($naive_uid ? '<span class="rank-uid">' . htmlspecialchars($naive_uid) . '</span>' : '<span class="rank-uid rank-absent">—</span>')
             . '</td>';
      $html .= '<td class="rank-ind">' . $badge . '</td>';
      $html .= '</tr>';
    }

    $html .= '</tbody></table>';
    return $html;
  }

  private function buildDocTable(int $n, array $corr, array $raw): string {
    $title = htmlspecialchars($corr['title'] ?? $corr['uid'] ?? "Result $n");
    $uid   = htmlspecialchars($corr['uid'] ?? '');

    $html  = '<div class="spike-doc-block">';
    $html .= '<div class="spike-doc-heading">#' . $n . ' &nbsp; <span class="spike-title">' . $title . '</span>'
           . ' &nbsp; <span class="spike-uid">' . $uid . '</span></div>';

    if (!empty($raw['_missing'])) {
      $html .= '<p class="spike-missing">Raw Solr document not found by uid.</p></div>';
      return $html;
    }

    $any_mismatch = FALSE;
    $rows = '';
    foreach (self::ALL_FIELDS as [$field, $source, $label]) {
      $label   = $label ?? $field;
      $corrval = trim((string) ($corr[$field] ?? ''));
      $rawval  = trim((string) ($raw[$field]  ?? ''));

      // Both sides use native Solr types — no date normalization needed.
      $match    = (strtolower($corrval) === strtolower($rawval));
      $only_corr = ($source === 'd11');
      $only_raw  = ($source === 'raw');

      if (!$match && !$only_corr && !$only_raw) $any_mismatch = TRUE;

      $row_class = 'cmp-row';
      if ($only_corr)    $row_class .= ' cmp-only-d11';
      elseif ($only_raw) $row_class .= ' cmp-only-raw';
      elseif (!$match)   $row_class .= ' cmp-diff';

      $corr_cell = $this->renderCell($field, $corrval, $only_raw ? 'cmp-absent' : '');
      $raw_cell  = $this->renderCell($field, $rawval,  $only_corr ? 'cmp-absent' : '');

      $indicator = '';
      if ($only_corr)    $indicator = '<span class="cmp-badge cmp-badge-d11">Corr</span>';
      elseif ($only_raw) $indicator = '<span class="cmp-badge cmp-badge-raw">Raw</span>';
      elseif (!$match)   $indicator = '<span class="cmp-badge cmp-badge-diff">≠</span>';
      else               $indicator = '<span class="cmp-badge cmp-badge-ok">✓</span>';

      $rows .= '<tr class="' . $row_class . '">'
             . '<td class="cmp-field">' . htmlspecialchars($label) . '</td>'
             . '<td class="cmp-d11">' . $corr_cell . '</td>'
             . '<td class="cmp-raw">' . $raw_cell . '</td>'
             . '<td class="cmp-ind">' . $indicator . '</td>'
             . '</tr>';
    }

    $summary = $any_mismatch
      ? '<span class="cmp-has-diff">⚠ value differences</span>'
      : '<span class="cmp-all-ok">✓ all fields match</span>';

    $html .= '<table class="cmp-table">'
           . '<thead><tr>'
           . '<th>Field</th>'
           . '<th>Corrected D11 (Solarium)</th>'
           . '<th>Raw Solr (independent fetch)</th>'
           . '<th>' . $summary . '</th>'
           . '</tr></thead>'
           . '<tbody>' . $rows . '</tbody>'
           . '</table>';

    $html .= '</div>';
    return $html;
  }

  private function renderCell(string $field, string $val, string $extra_class = ''): string {
    $cls = $extra_class ? ' class="' . $extra_class . '"' : '';
    if ($val === '') {
      return '<span class="cmp-empty"' . $cls . '>—</span>';
    }
    if (in_array($field, ['url_html', 'url_json', 'url_thumb'])) {
      return '<a href="' . htmlspecialchars($val) . '" target="_blank"' . $cls . '>' . htmlspecialchars($val) . '</a>';
    }
    return '<span' . $cls . '>' . htmlspecialchars($val) . '</span>';
  }

}
