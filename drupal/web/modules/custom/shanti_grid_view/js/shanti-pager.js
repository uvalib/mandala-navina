/**
 * @file
 * ShantiPager's page-jump widget input -- a focused port of D7 pagerer.js's
 * '.pagerer-page' input behavior (pagerer.js: Drupal.behaviors.pagerer),
 * scoped to just the "widget" display type this view actually uses (no
 * slider/scrollpane/adaptive-links -- see pagerer.js for the rest, not
 * carried over here).
 *
 * The widget's state (current page, total pages, and a URL template with a
 * placeholder for the target page) is embedded as JSON in the input's
 * `name` attribute by ShantiPager::render() -- same technique D7 used, so
 * no separate drupalSettings payload is needed per pager instance.
 */
((Drupal, once) => {
  const PLACEHOLDER = '__SHANTI_PAGE__';

  Drupal.behaviors.shantiPager = {
    attach(context) {
      once('shanti-pager', '.pagerer-page', context).forEach((input) => {
        const state = JSON.parse(input.getAttribute('name'));

        const clamp = (value) => Math.min(Math.max(value, 1), state.total);

        const relocate = (targetPage) => {
          const index = targetPage - 1;
          if (index === state.current) {
            return;
          }
          window.location.href = state.path.replace(PLACEHOLDER, String(index));
        };

        input.addEventListener('focus', () => {
          input.select();
        });

        input.addEventListener('keydown', (event) => {
          switch (event.key) {
            case 'Enter':
              relocate(clamp(parseInt(input.value, 10) || 1));
              event.preventDefault();
              break;
            case 'Escape':
              input.value = String(state.current + 1);
              break;
            case 'ArrowUp':
              input.value = String(clamp((parseInt(input.value, 10) || 1) - 1));
              event.preventDefault();
              break;
            case 'ArrowDown':
              input.value = String(clamp((parseInt(input.value, 10) || 1) + 1));
              event.preventDefault();
              break;
            default:
              break;
          }
        });
      });
    },
  };
})(Drupal, once);
