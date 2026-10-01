<?php

declare(strict_types=1);

namespace Drupal\spike_transcript_demo\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Database;
use Drupal\mandala_kaltura\KalturaConfigResolver;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Spike 11 prototype: render a migrated AV node's player plus its D7 TCUs.
 *
 * TCUs are read straight from the D7 source DB (`migrate_av` connection) --
 * the audit found D7 already stores parsed TCUs in tcu/tcu_tier/tcu_speaker, so
 * no file parsing is involved. The D11 node is resolved by the legacy
 * composite key (ADR 017), never by a hardcoded D11 nid.
 */
class TranscriptDemoController extends ControllerBase {

  public function __construct(protected readonly KalturaConfigResolver $resolver) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('mandala_kaltura.resolver'));
  }

  public function view(int $legacy_nid): array {
    $nids = $this->entityTypeManager()->getStorage('node')->getQuery()
      ->accessCheck(TRUE)
      ->condition('field_legacy_site', 'audio-video')
      ->condition('field_legacy_nid', $legacy_nid)
      ->range(0, 1)
      ->execute();
    $node = $nids ? $this->entityTypeManager()->getStorage('node')->load(reset($nids)) : NULL;
    if (!$node || !$node->hasField('field_video') || $node->get('field_video')->isEmpty()) {
      throw new NotFoundHttpException('No migrated video node with a Kaltura entry for that legacy nid.');
    }
    $entry_id = $node->get('field_video')->entry_id;
    $preset = $this->resolver->resolve('default');

    $db = Database::getConnection('default', 'migrate_av');
    $trid = $db->query('SELECT trid FROM {transcripts_apachesolr_transcript} WHERE id = :n', [':n' => $legacy_nid])->fetchField();
    if (!$trid) {
      throw new NotFoundHttpException('No D7 transcript for that node.');
    }
    $tcus = $db->query('SELECT tcuid, start, end FROM {tcu} WHERE trid = :t ORDER BY start, tcuid', [':t' => $trid])->fetchAllAssoc('tcuid');
    $tiers = [];
    foreach ($db->query('SELECT tt.tcuid, tt.tier, tt.value FROM {tcu_tier} tt JOIN {tcu} t ON t.tcuid = tt.tcuid WHERE t.trid = :t', [':t' => $trid]) as $r) {
      $tiers[$r->tcuid][$r->tier] = $r->value;
    }
    $speakers = [];
    foreach ($db->query('SELECT s.tcuid, s.value FROM {tcu_speaker} s JOIN {tcu} t ON t.tcuid = s.tcuid WHERE t.trid = :t', [':t' => $trid]) as $r) {
      $speakers[$r->tcuid] = $r->value;
    }

    $rows = [];
    foreach ($tcus as $id => $t) {
      $rows[] = [
        'start' => (float) $t->start,
        'end' => (float) $t->end,
        'speaker' => $speakers[$id] ?? '',
        'tiers' => $tiers[$id] ?? [],
      ];
    }

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['spike-transcript-demo']],
      'title' => ['#markup' => '<h2>' . $node->label() . '</h2>'],
      'player' => ['#markup' => '<div id="spike-kplayer" style="width:100%;max-width:' . (int) $preset['player_width'] . 'px;height:' . (int) $preset['player_height'] . 'px"></div>'],
      'transcript' => ['#markup' => '<div id="spike-transcript" data-transcripts-role="transcript"></div>'],
      '#attached' => [
        'library' => ['spike_transcript_demo/viewer'],
        'drupalSettings' => ['spikeTranscript' => [
          'serverUrl' => $preset['server_url'],
          'partnerId' => $preset['partner_id'],
          'subpId' => $preset['subp_id'],
          'uiconfId' => $preset['uiconf_id'],
          'entryId' => $entry_id,
          'rows' => $rows,
        ]],
      ],
    ];
  }

}
