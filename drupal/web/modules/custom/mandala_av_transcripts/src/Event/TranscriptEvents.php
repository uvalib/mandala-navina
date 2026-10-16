<?php

declare(strict_types=1);

namespace Drupal\mandala_av_transcripts\Event;

/**
 * Names of the events this module dispatches (Symfony event dispatcher).
 *
 * The hook surface for everything that reacts to transcript changes: the
 * kmassets reindex of the parent node (mandala_kmassets_sync) and, in T4, the
 * transcript-core Solr writes. A subscriber that throws cannot undo the save that
 * caused it: the notifier catches and logs it. Subscribers should still handle
 * their own failures (log and, in T4, queue a retry).
 */
final class TranscriptEvents {

  /**
   * A transcript was created or updated. Event: TranscriptEvent.
   *
   * Carries previousNodeId when the update moved the transcript to another node,
   * so both nodes' documents can be refreshed.
   */
  public const TRANSCRIPT_SAVED = 'mandala_av_transcripts.transcript_saved';

  /**
   * A transcript was deleted. Event: TranscriptEvent.
   *
   * Its units are not deleted with it (see the T2 plan, open questions).
   */
  public const TRANSCRIPT_DELETED = 'mandala_av_transcripts.transcript_deleted';

  /**
   * One unit was created or updated. Event: TranscriptEvent (with unitId).
   *
   * Not dispatched inside TranscriptChangeNotifier::suppressed(); see
   * UNITS_REPLACED.
   */
  public const UNIT_SAVED = 'mandala_av_transcripts.unit_saved';

  /**
   * One unit was deleted. Event: TranscriptEvent (with unitId).
   */
  public const UNIT_DELETED = 'mandala_av_transcripts.unit_deleted';

  /**
   * A whole transcript's units changed at once. Event: TranscriptEvent.
   *
   * Dispatched once per transcript by TranscriptChangeNotifier::unitsReplaced()
   * for operations that touch many units (migration, re-import, revert to
   * upload), and automatically at the end of suppressed() for every transcript
   * whose units were saved or deleted inside it. Index consumers re-write the
   * transcript's documents from this event rather than from 1,000 unit events.
   */
  public const UNITS_REPLACED = 'mandala_av_transcripts.units_replaced';

}
