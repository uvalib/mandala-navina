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
          // Pig's own default (max 6, at >1920px) packs far fewer, much
          // taller tiles per row than production -- ported verbatim from
          // production's own Pig construction options (shanti_grid_view.js,
          // the `new Pig(...)` call activatePopdown() is chained off of) so
          // row height/tile size matches exactly.
          getMinAspectRatio: (lastWindowWidth) => {
            if (lastWindowWidth <= 400) return 2;
            if (lastWindowWidth <= 640) return 3;
            if (lastWindowWidth <= 800) return 4;
            if (lastWindowWidth <= 1100) return 6;
            if (lastWindowWidth <= 1400) return 8;
            if (lastWindowWidth <= 1600) return 10;
            if (lastWindowWidth <= 1800) return 12;
            if (lastWindowWidth <= 2000) return 14;
            return 13;
          },
        });
        pig.enable();

        let panel = null;
        let panelRowY = null;
        let panelHeight = 0;
        let selectedFigure = null;

        // Pig's own row-layout transition is 10ms (settings.transitionSpeed)
        // -- effectively an instant snap, not a visible animation. Production
        // explicitly re-sets a real transition on each shifted figure at
        // shift time (shiftImages() in pig-shanti-ext.js); do the same here
        // rather than relying on whatever transition pig last computed.
        const SHIFT_TRANSITION = 'transform 220ms cubic-bezier(0.25, 1, 0.5, 1)';

        // Gap between the clicked row and the panel -- without it the panel
        // starts flush against the row, and its own top edge (same color as
        // the arrow) hides the arrow completely. Sized to exactly match the
        // arrow's own height (`.shanti-grid-view-selected::after`, 10px)
        // rather than production's 0.8rem, so the arrow's base sits flush
        // against the panel's top edge instead of leaving a sliver of
        // visible gap between the two.
        const PANEL_GAP = 10;

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
              image.style.transition = SHIFT_TRANSITION;
              if (image.existsOnPage) {
                image._updateStyles();
              }
            }
          });
          pig.totalHeight += delta;
          // eslint-disable-next-line no-param-reassign
          container.style.height = `${pig.totalHeight}px`;
        };

        /**
         * Measures the panel's current rendered height (content + gap above
         * it) and shifts rows below by however much that grew or shrank
         * since the last measurement. Called synchronously right after the
         * panel's content changes -- not after its image has loaded -- so
         * opening the panel isn't gated on image load time. This is only
         * correct because the panel content's own image reserves its final
         * box size via CSS `aspect-ratio` (node--shanti-image--grid-details
         * .html.twig, computed server-side from the node's known width/
         * height) rather than the image's actual decoded pixels, so the
         * panel's total height is already final the instant its HTML is
         * inserted -- the image bytes can arrive whenever without changing
         * panel height or triggering another shift.
         */
        const measureAndShift = () => {
          if (!panel) {
            return;
          }
          const newHeight = PANEL_GAP + panel.getBoundingClientRect().height;
          shiftBelow(panelRowY, newHeight - panelHeight);
          panelHeight = newHeight;
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
          if (selectedFigure) {
            selectedFigure.classList.remove('shanti-grid-view-selected');
            selectedFigure = null;
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
          const figure = img.closest('figure');
          const progressiveImage = pig.images.find((candidate) => candidate.element === figure);
          if (!progressiveImage) {
            return;
          }

          // A currently-open panel on an *earlier* row shifts this row's
          // translateY down; reading rowY before closing that panel would
          // capture that shifted (soon to be stale) value. closePanel()
          // reverses the shift synchronously, so read rowY only after it
          // runs -- otherwise the new panel gets positioned using a Y that
          // no longer matches the row's real (shifted-back) position,
          // leaving a gap (or overlap) between the row and the panel.
          const sameRowAlreadyOpen = panel && panelRowY === progressiveImage.style.translateY;
          if (!sameRowAlreadyOpen) {
            closePanel();
          }

          const rowY = progressiveImage.style.translateY;

          if (selectedFigure) {
            selectedFigure.classList.remove('shanti-grid-view-selected');
          }
          figure.classList.add('shanti-grid-view-selected');
          selectedFigure = figure;

          if (!panel) {
            panel = document.createElement('div');
            panel.className = 'shanti-grid-view-panel';
            panel.style.top = `${rowY + progressiveImage.style.height + PANEL_GAP}px`;
            container.appendChild(panel);
            panelRowY = rowY;
          }
          panel.innerHTML = '<button type="button" class="shanti-grid-view-panel-close" aria-label="Close">×</button><div class="shanti-grid-view-panel-body">' + Drupal.t('Loading…') + '</div>';
          panel.querySelector('.shanti-grid-view-panel-close').addEventListener('click', closePanel);
          measureAndShift();
          panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });

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
              const panelBody = panel.querySelector('.shanti-grid-view-panel-body');
              panelBody.innerHTML = html;
              // The fragment is a raw fetch() response, not run through
              // Drupal's AJAX framework, so behaviors never auto-attach to
              // it (e.g. kmapsPopover's Bootstrap-popover wiring) -- attach
              // explicitly, scoped to just the inserted content.
              Drupal.attachBehaviors(panelBody);
              measureAndShift();
            })
            .catch(() => {
              if (!panel) {
                return;
              }
              panel.querySelector('.shanti-grid-view-panel-body').textContent = Drupal.t('Unable to load details for this image.');
              measureAndShift();
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
