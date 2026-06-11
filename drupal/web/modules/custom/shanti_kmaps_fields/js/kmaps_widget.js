/**
 * @file
 * KMaps field widget JS.
 *
 * The autocomplete controller returns suggestions with three properties:
 *   value — human-readable label, e.g. "Buddhism (subjects-385)"
 *   label — same as value (shown in the dropdown and set in the visible input)
 *   raw   — pipe-delimited machine value: id|header|domain|path|defids
 *
 * On selection, Drupal's jQuery UI autocomplete sets the visible input to
 * ui.item.value (the human label). This handler's only job is to copy
 * ui.item.raw into the paired hidden field so massageFormValues() can parse it.
 */
(function ($, Drupal) {
  'use strict';

  Drupal.behaviors.kmapsWidget = {
    attach: function (context, settings) {
      once('kmaps-widget', '.kmaps-search-input', context).forEach(function (input) {
        var $input = $(input);
        var rawTargetId = $input.data('kmapsRawTarget');

        // autocompleteselect fires after jQuery UI has set the input value to
        // ui.item.value (the human label). We just need to stash the raw string.
        $input.on('autocompleteselect', function (event, ui) {
          var raw = ui.item.raw || '';
          if (rawTargetId) {
            $('#' + rawTargetId).val(raw);
          } else {
            $input.siblings('input.kmaps-raw-value').val(raw);
          }
          // Do NOT preventDefault — let Drupal set the visible input to the label.
        });

        // Clearing the visible field also clears the stored raw value.
        $input.on('input', function () {
          if ($(this).val() === '') {
            if (rawTargetId) {
              $('#' + rawTargetId).val('');
            } else {
              $input.siblings('input.kmaps-raw-value').val('');
            }
          }
        });
      });
    }
  };

})(jQuery, Drupal);
