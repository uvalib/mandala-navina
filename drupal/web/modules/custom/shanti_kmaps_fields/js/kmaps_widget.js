/**
 * @file
 * KMaps field widget JS.
 *
 * When the user selects a suggestion from the Drupal autocomplete, the
 * suggestion's 'value' (the pipe-delimited raw string) is copied into the
 * associated hidden <input class="kmaps-raw-value"> field so that
 * massageFormValues() on the server can parse it.
 *
 * The visible autocomplete input keeps the human-readable label.
 */
(function ($, Drupal) {
  'use strict';

  Drupal.behaviors.kmapsWidget = {
    attach: function (context, settings) {
      // For each KMaps search input, wire up the autocompleteselect event.
      // Drupal's autocomplete fires 'autocompleteselect' on the input element
      // when a suggestion is chosen from the dropdown.
      once('kmaps-widget', '.kmaps-search-input', context).forEach(function (input) {
        var $input = $(input);
        var rawTargetId = $input.data('kmapsRawTarget');

        $input.on('autocompleteselect', function (event, ui) {
          // ui.item.value is the machine value returned by the controller:
          // "id|header|domain|path|defids"
          // ui.item.label is the human string: "header (domain-id)"
          var raw = ui.item.value;
          var label = ui.item.label;

          // Store the raw value in the hidden field.
          if (rawTargetId) {
            $('#' + rawTargetId).val(raw);
          } else {
            $input.siblings('input.kmaps-raw-value').val(raw);
          }

          // Show the label in the visible field instead of the raw value.
          $input.val(label);

          // Prevent the default which would set input.val to ui.item.value.
          event.preventDefault();
        });

        // When the visible field is cleared, also clear the hidden raw value.
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
