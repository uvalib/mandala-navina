/**
 * @file
 * Wires Bootstrap 5's popover component to KMaps tags.
 *
 * Ports shanti-main.js's popover-link behavior: the popover content is
 * already rendered server-side into a hidden sibling `.popover` div (see
 * kmap-popover.html.twig) - this just reads that static content and hands
 * it to Bootstrap's popover, on hover. No AJAX fetch.
 */
(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.kmapsPopover = {
    attach: function (context) {
      once('kmaps-popover', '.popover-link', context).forEach(function (trigger) {
        var group = trigger.closest('.kmap-tag-group');
        var popoverEl = group ? group.nextElementSibling : trigger.nextElementSibling;
        if (!popoverEl || !popoverEl.classList.contains('popover')) {
          return;
        }

        var title = popoverEl.getAttribute('data-title') || '';
        var content = popoverEl.innerHTML;

        // sanitize:false - content is server-rendered by
        // kmap-popover.html.twig from Solr term data (Twig auto-escapes
        // the label/link text at render time), not raw user input; BS5's
        // default sanitizer would strip the footer buttons' classes/hrefs.
        new bootstrap.Popover(trigger, {
          title: title,
          content: content,
          html: true,
          trigger: 'hover focus',
          placement: 'bottom',
          container: 'body',
          sanitize: false,
        });

        // Hide any other open popover before showing this one, matching
        // D7's single-popover-at-a-time behavior.
        trigger.addEventListener('show.bs.popover', function () {
          document.querySelectorAll('.popover-link').forEach(function (other) {
            if (other !== trigger) {
              var otherInstance = bootstrap.Popover.getInstance(other);
              if (otherInstance) {
                otherInstance.hide();
              }
            }
          });
        });
      });
    },
  };

})(Drupal, once);
