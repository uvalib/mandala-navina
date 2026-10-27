<?php

/**
 * Spike 5 prototype: Zotero item JSON (API v3) -> normalized bibcite values.
 *
 * Pure PHP, no Drupal. The type table is seeded from D7's
 * biblio_zotero ZoteroBiblioMap::biblioToZoteroType(), which supported only the
 * Zotero types below and sent everything else to Miscellaneous.
 */
class ZoteroMapper {

  public const FALLBACK_TYPE = 'miscellaneous';

  private const TYPES = [
    'book' => 'book',
    'bookSection' => 'book_chapter',
    'journalArticle' => 'journal_article',
    'magazineArticle' => 'magazine_article',
    'newspaperArticle' => 'newspaper_article',
    'thesis' => 'thesis',
    'conferencePaper' => 'conference_paper',
    'report' => 'report',
    'webpage' => 'web_article',
    'film' => 'film',
    'tvBroadcast' => 'broadcast',
    'artwork' => 'artwork',
    'manuscript' => 'manuscript',
    'map' => 'map',
    'patent' => 'patent',
    'statute' => 'statute',
    'bill' => 'bill',
    'case' => 'case',
    'hearing' => 'hearing',
    'computerProgram' => 'software',
  ];

  /** Zotero v3 field => bibcite field (from the D7 feed's mappings). */
  private const FIELDS = [
    'abstractNote' => 'bibcite_abst_e',
    'url' => 'bibcite_url',
    'language' => 'bibcite_lang',
    'rights' => 'bibcite_custom7',
    'shortTitle' => 'bibcite_short_title',
    'volume' => 'bibcite_volume',
    'issue' => 'bibcite_issue',
    'pages' => 'bibcite_pages',
    'publisher' => 'bibcite_publisher',
    'place' => 'bibcite_place_published',
    'edition' => 'bibcite_edition',
    'ISBN' => 'bibcite_isbn',
    'ISSN' => 'bibcite_issn',
    'DOI' => 'bibcite_doi',
    'extra' => 'bibcite_notes',
    'callNumber' => 'bibcite_call_number',
    'numberOfVolumes' => 'bibcite_number_of_volumes',
    'publicationTitle' => 'bibcite_secondary_title',
    'bookTitle' => 'bibcite_secondary_title',
    'proceedingsTitle' => 'bibcite_secondary_title',
    'websiteTitle' => 'bibcite_secondary_title',
    'seriesTitle' => 'bibcite_tertiary_title',
  ];

  /** Zotero creatorType => [bibcite category, role]. Unlisted -> author. */
  private const CREATORS = [
    'author' => ['primary', 'author'],
    'editor' => ['secondary', 'editor'],
    'seriesEditor' => ['secondary', 'series_editor'],
    'translator' => ['tertiary', 'translator'],
    'recipient' => ['subsidiary', 'recipient'],
    'producer' => ['subsidiary', 'producer'],
    'counsel' => ['subsidiary', 'counsel'],
  ];

  public function map(array $item): ?array {
    $d = $item['data'] ?? [];
    $zType = $d['itemType'] ?? '';
    if (in_array($zType, ['note', 'attachment', 'annotation'], TRUE)) {
      return NULL;
    }
    $fields = [];
    foreach (self::FIELDS as $z => $b) {
      if (isset($d[$z]) && $d[$z] !== '' && !isset($fields[$b])) {
        $fields[$b] = $d[$z];
      }
    }
    if (!empty($d['date'])) {
      $fields['bibcite_date'] = $d['date'];
      if (preg_match('/\b(1[5-9]\d\d|20\d\d)\b/', $d['date'], $m)) {
        $fields['bibcite_year'] = $m[1];
      }
    }
    $contributors = [];
    foreach ($d['creators'] ?? [] as $c) {
      [$cat, $role] = self::CREATORS[$c['creatorType'] ?? 'author'] ?? ['primary', 'author'];
      if (isset($c['name'])) {
        // Single-field name = an institution; D7 filed it as category 5.
        $contributors[] = ['first' => '', 'last' => $c['name'], 'category' => 'corporate_institutional', 'role' => 'corporate_author'];
      }
      else {
        $contributors[] = ['first' => $c['firstName'] ?? '', 'last' => $c['lastName'] ?? '', 'category' => $cat, 'role' => $role];
      }
    }
    return [
      'key' => $item['key'],
      'version' => $item['version'],
      'zotero_type' => $zType,
      'type' => self::TYPES[$zType] ?? self::FALLBACK_TYPE,
      'fallback' => !isset(self::TYPES[$zType]),
      'title' => $d['title'] ?? '',
      'fields' => $fields,
      'contributors' => $contributors,
      // Not storable in stock bibcite fields; D7 kept these in vocabularies.
      'tags' => array_column($d['tags'] ?? [], 'tag'),
      'collections' => $d['collections'] ?? [],
    ];
  }

}
