/**
 * @file
 * Masonry grid init (pig.js) + click-to-open info panel, ported from D7's
 * shanti_grid_view (pig.js + pig-shanti-ext.js). Unlike D7, thumbnail URLs
 * are precomputed server-side per row (GridView::render()) rather than
 * built client-side from a __FNAME__/__SIZE__ template, so pig.js's
 * `filename` field is repurposed here as "the ready-to-use thumbnail URL"
 * -- urlForSize() below is an identity function, not a real size lookup.
 *
 * pig.js recycles/virtualizes DOM figures as the user scrolls (see its own
 * ProgressiveImage.load()/hide()), so there's no stable per-tile DOM
 * attribute to hang a node ID off of without patching the vendored file.
 * Instead: since each image's thumbnail URL is unique, a delegated click
 * listener looks up the clicked <img>'s `src` in a Map built from the same
 * data pig.js was given.
 */
((Drupal, once, drupalSettings) => {
  Drupal.behaviors.shantiGridView = {
    attach(context) {
      once('shanti-grid-view', '[data-shanti-grid-view]', context).forEach((container) => {
        const settings = drupalSettings.shantiGridView && drupalSettings.shantiGridView[container.id];
        if (!settings || !Array.isArray(settings.images) || settings.images.length === 0) {
          return;
        }

        const infoByThumbUrl = new Map();
        const imageData = settings.images.map((image) => {
          infoByThumbUrl.set(image.thumbUrl, image);
          return { filename: image.thumbUrl, aspectRatio: image.aspectRatio };
        });

        // eslint-disable-next-line no-undef -- Pig is the vendored global (shanti_grid_view/pig).
        const pig = new Pig(imageData, {
          containerId: container.id,
          urlForSize: (filename) => filename,
          thumbnailSize: settings.thumbnailHeight,
        });
        pig.enable();

        let panel;

        const closePanel = () => {
          if (panel) {
            panel.remove();
            panel = null;
          }
        };

        container.addEventListener('click', (event) => {
          const img = event.target.closest('img');
          if (!img) {
            return;
          }
          const image = infoByThumbUrl.get(img.src);
          if (!image) {
            return;
          }

          closePanel();
          panel = document.createElement('div');
          panel.className = 'shanti-grid-view-panel';
          panel.innerHTML = '<button type="button" class="shanti-grid-view-panel-close" aria-label="Close">×</button><div class="shanti-grid-view-panel-body">Loading…</div>';
          panel.querySelector('.shanti-grid-view-panel-close').addEventListener('click', closePanel);
          container.insertAdjacentElement('afterend', panel);
          panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });

          fetch(image.infoUrl, { headers: { Accept: 'text/html' } })
            .then((response) => {
              if (!response.ok) {
                throw new Error(`${response.status}`);
              }
              return response.text();
            })
            .then((html) => {
              const body = panel && panel.querySelector('.shanti-grid-view-panel-body');
              if (body) {
                body.innerHTML = html;
              }
            })
            .catch(() => {
              const body = panel && panel.querySelector('.shanti-grid-view-panel-body');
              if (body) {
                body.textContent = Drupal.t('Unable to load details for this image.');
              }
            });
        });
      });
    },
  };
})(Drupal, once, drupalSettings);
