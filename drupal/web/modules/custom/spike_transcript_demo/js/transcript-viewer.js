(function (Drupal, drupalSettings, once) {
  'use strict';

  // Tier display order / labels; unknown tiers fall through after these.
  const TIER_ORDER = ['content_bod', 'dzo_bod', 'ts_content_wylie', 'ts_content_eng'];

  function fmt(t) {
    const s = Math.max(0, Math.floor(t));
    return Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0');
  }

  function build(el, rows, seek) {
    const list = document.createElement('ol');
    list.className = 'spike-tcu-list';
    rows.forEach(function (r, i) {
      const li = document.createElement('li');
      li.className = 'spike-tcu';
      li.dataset.index = i;
      const time = document.createElement('button');
      time.type = 'button';
      time.className = 'spike-tcu-time';
      time.textContent = fmt(r.start);
      time.addEventListener('click', function () { seek(r.start); });
      li.appendChild(time);
      const tierNames = Object.keys(r.tiers).sort(function (a, b) {
        const ia = TIER_ORDER.indexOf(a), ib = TIER_ORDER.indexOf(b);
        return (ia < 0 ? 99 : ia) - (ib < 0 ? 99 : ib);
      });
      tierNames.forEach(function (tn) {
        const p = document.createElement('p');
        p.className = 'spike-tier spike-tier--' + tn;
        p.textContent = r.tiers[tn];
        li.appendChild(p);
      });
      list.appendChild(li);
    });
    el.appendChild(list);
    return list;
  }

  Drupal.behaviors.spikeTranscript = {
    attach: function (context) {
      once('spike-transcript', '#spike-transcript', context).forEach(function (el) {
        const cfg = drupalSettings.spikeTranscript;
        let kdp = null;
        const seek = function (t) { if (kdp) { kdp.sendNotification('doSeek', t); kdp.sendNotification('doPlay'); } };
        const list = build(el, cfg.rows, seek);
        let current = -1;

        function highlight(t) {
          // Last TCU whose start <= t; handles zero/inverted ends gracefully.
          let idx = -1;
          for (let i = 0; i < cfg.rows.length; i++) {
            if (cfg.rows[i].start <= t) { idx = i; } else { break; }
          }
          if (idx === current) { return; }
          current = idx;
          list.querySelectorAll('.spike-tcu--active').forEach(function (n) { n.classList.remove('spike-tcu--active'); });
          if (idx >= 0) {
            const li = list.children[idx];
            li.classList.add('spike-tcu--active');
            li.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
          }
        }
        window.spikeTranscriptHighlight = highlight; // test hook

        const s = document.createElement('script');
        s.src = cfg.serverUrl + '/p/' + cfg.partnerId + '/sp/' + cfg.subpId +
          '/embedIframeJs/uiconf_id/' + cfg.uiconfId + '/partner_id/' + cfg.partnerId;
        s.onload = function () {
          kWidget.embed({
            targetId: 'spike-kplayer',
            wid: '_' + cfg.partnerId,
            uiconf_id: cfg.uiconfId,
            entry_id: cfg.entryId,
            readyCallback: function (playerId) {
              kdp = document.getElementById(playerId);
              kdp.kBind('playerUpdatePlayhead.spike', function (t) { highlight(t); });
            }
          });
        };
        document.head.appendChild(s);
      });
    }
  };
})(Drupal, drupalSettings, once);
