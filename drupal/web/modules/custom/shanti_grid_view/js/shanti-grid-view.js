/**
 * @file
 * Masonry grid init (pig.js) + click-to-open info panel, ported from D7's
 * shanti_grid_view (pig.js + pig-shanti-ext.js). Unlike D7, thumbnail URLs
 * are precomputed server-side per row (GridView::render()) rather than
 * built client-side from a __FNAME__/__SIZE__ template, so pig.js's
 * `filename` field is repurposed here as "the ready-to-use thumbnail URL"
 * -- urlForSize() below is an identity function, not a real size lookup.
 *
 * The info panel opens in place, directly below the clicked image's row,
 * pushing every row below it down -- same UX as D7's popdown. D7 achieves
 * this by mutating each affected figure's DOM transform directly *and*
 * disabling pig.js's own scroll-driven re-layout (Pig.prototype._getOnScroll
 * patched to a no-op) so those direct DOM edits never get overwritten.
 * Here, scroll virtualization is left enabled (proven working against the
 * real page during testing) -- instead, each affected ProgressiveImage
 * instance's *own* `style.translateY` (the model pig.js itself reads
 * every time it (re)renders a figure) is mutated, so pig.js's normal
 * scroll/resize cycle naturally keeps rendering the shifted position
 * without fighting it. `pig.images` and `ProgressiveImage.prototype.
 * _updateStyles` are plain (if underscore-prefixed) instance properties,
 * not really private in a vendored, un-bundled script -- relied on here
 * rather than duplicating pig.js's row-packing math ourselves.
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

        let panel = null;
        let panelRowY = null;
        let panelHeight = 0;

        /**
         * Shifts every image below {rowY} by {delta}px (negative to shift
         * back up), and grows/shrinks the container to match, so later page
         * content doesn't overlap.
         */
        const shiftBelow = (rowY, delta) => {
          if (!delta) {
            return;
          }
          pig.images.forEach((image) => {
            if (image.style.translateY > rowY) {
              image.style.translateY += delta;
              if (image.existsOnPage) {
                image._updateStyles();
              }
            }
          });
          pig.totalHeight += delta;
          // eslint-disable-next-line no-param-reassign
          container.style.height = `${pig.totalHeight}px`;
        };

        const closePanel = () => {
          if (!panel) {
            return;
          }
          shiftBelow(panelRowY, -panelHeight);
          panel.remove();
          panel = null;
          panelRowY = null;
          panelHeight = 0;
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
          const figure = img.closest('figure');
          const progressiveImage = pig.images.find((candidate) => candidate.element === figure);
          if (!progressiveImage) {
            return;
          }

          const rowY = progressiveImage.style.translateY;
          const sameRowAlreadyOpen = panel && panelRowY === rowY;
          if (!sameRowAlreadyOpen) {
            closePanel();
          }

          if (!panel) {
            panel = document.createElement('div');
            panel.className = 'shanti-grid-view-panel';
            panel.style.top = `${rowY + progressiveImage.style.height}px`;
            container.appendChild(panel);
            panelRowY = rowY;
          }
          panel.innerHTML = '<button type="button" class="shanti-grid-view-panel-close" aria-label="Close">×</button><div class="shanti-grid-view-panel-body">' + Drupal.t('Loading…') + '</div>';
          panel.querySelector('.shanti-grid-view-panel-close').addEventListener('click', closePanel);

          fetch(image.infoUrl, { headers: { Accept: 'text/html' } })
            .then((response) => {
              if (!response.ok) {
                throw new Error(`${response.status}`);
              }
              return response.text();
            })
            .then((html) => {
              if (!panel) {
                return;
              }
              panel.querySelector('.shanti-grid-view-panel-body').innerHTML = html;
              const newHeight = panel.getBoundingClientRect().height;
              shiftBelow(panelRowY, newHeight - panelHeight);
              panelHeight = newHeight;
              panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            })
            .catch(() => {
              if (!panel) {
                return;
              }
              panel.querySelector('.shanti-grid-view-panel-body').textContent = Drupal.t('Unable to load details for this image.');
              const newHeight = panel.getBoundingClientRect().height;
              shiftBelow(panelRowY, newHeight - panelHeight);
              panelHeight = newHeight;
            });
        });

        // pig.js's own resize handler fully recomputes every image's
        // translateY from scratch, which would strand an open panel at a
        // stale row position -- close it rather than let it drift.
        window.addEventListener('resize', () => closePanel());

        document.addEventListener('keyup', (event) => {
          if (event.key === 'Escape') {
            closePanel();
          }
        });
      });
    },
  };
})(Drupal, once, drupalSettings);
