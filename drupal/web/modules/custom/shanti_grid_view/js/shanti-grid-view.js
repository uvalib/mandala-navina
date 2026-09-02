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
         * it), animates the panel's *own* box to that height (see the FLIP
         * comment below), and shifts rows below by however much that grew
         * or shrank since the last measurement. Called synchronously right
         * after the panel's content changes -- not after its image has
         * loaded -- so opening the panel isn't gated on image load time.
         * This is only correct because the panel content's own image
         * reserves its final box size via CSS `aspect-ratio`
         * (node--shanti-image--grid-details.html.twig, computed
         * server-side from the node's known width/height) rather than the
         * image's actual decoded pixels, so the panel's total height is
         * already final the instant its HTML is inserted -- the image
         * bytes can arrive whenever without changing panel height or
         * triggering another shift.
         */
        const measureAndShift = () => {
          if (!panel) {
            return;
          }

          // FLIP technique: `height: auto` can't be CSS-transitioned, so
          // to animate the panel's own box growing/shrinking (not just the
          // rows-below push, which already animates via shiftBelow's
          // transform transition) we need explicit FROM and TO pixel
          // heights. Clear any explicit height to measure the box's true
          // natural (content-driven) size, put the previous explicit
          // height back so the browser has a defined starting point, force
          // a reflow, then set the new explicit height -- the CSS
          // `transition: height` on .shanti-grid-view-panel animates the
          // rest. On the very first call (panel just created, height
          // initialized to 0px in the click handler below) this animates
          // 0 -> the loading skeleton's height, so the row visibly starts
          // growing the instant it's clicked rather than sitting static
          // until content arrives.
          const priorHeight = panel.style.height;
          panel.style.height = 'auto';
          const contentHeight = panel.getBoundingClientRect().height;
          panel.style.height = priorHeight;
          void panel.offsetHeight; // Force reflow so the FROM height registers before the change below.
          panel.style.height = `${contentHeight}px`;

          const newHeight = PANEL_GAP + contentHeight;
          shiftBelow(panelRowY, newHeight - panelHeight);
          panelHeight = newHeight;
        };

        // Ports pig-shanti-ext.js's Pig.prototype.scrollToView: rather than
        // a plain scrollIntoView (which only moves the minimum amount to
        // bring an edge into view, and does nothing further once any part
        // of the panel is visible), production positions the panel's
        // *bottom* ~50px above the viewport's bottom edge -- this reliably
        // shows the whole panel regardless of where on screen the click
        // happened, including when the clicked image is near the bottom
        // of the viewport (the case this exists for). Called after the
        // panel has reached its real (content-driven) height, not on open
        // -- our panel's height comes from real fetched content, unlike
        // D7's fixed-height popdown, so scrolling before content loads
        // would use a stale, too-short height.
        const scrollPanelIntoView = () => {
          if (!panel) {
            return;
          }
          const rect = panel.getBoundingClientRect();
          const diff = window.innerHeight - (rect.height + 50);
          const targetTop = window.scrollY + rect.top - diff;
          window.scrollTo({ top: Math.max(0, targetTop), behavior: 'smooth' });
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

        /**
         * Opens (or, if a panel is already open on the same row, swaps the
         * content of) the info panel for one image. Shared by the tile
         * click handler and the prev/next arrows -- production's own
         * gotoImage('prev'/'next') (pig-shanti-ext.js) works the same way,
         * just walking the image array and feeding the result through the
         * identical open-or-swap logic a real click uses, rather than
         * having separate navigation logic.
         */
        const openPanelFor = (figure, progressiveImage, image) => {
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
            // Starting height for measureAndShift()'s FLIP animation to
            // grow from -- so the panel visibly starts expanding the
            // instant it's created, rather than appearing already at its
            // loading-skeleton size.
            panel.style.height = '0px';
            container.appendChild(panel);
            panelRowY = rowY;
            // Delegated once here (not re-attached after every content
            // swap below, unlike the close button which is easier to
            // just recreate each time) since close/prev/next are the
            // panel's only interactive chrome and never leave the DOM.
            panel.addEventListener('click', (panelEvent) => {
              if (panelEvent.target.closest('.shanti-grid-view-panel-prev')) {
                gotoAdjacentImage('prev');
              }
              else if (panelEvent.target.closest('.shanti-grid-view-panel-next')) {
                gotoAdjacentImage('next');
              }
            });
          }
          // Ports production's two independent loading states
          // (pig-shanti-ext.js / shanti-pig-gallery.css): the 4-dot
          // orbiting/fading spinner + pulsing "Loading..." label for the
          // image side, and a simpler rotating-ring spinner for the
          // details side (production loads these via two separate AJAX
          // requests; this port fetches the whole panel as one, but still
          // shows both spinners positioned where their content will land,
          // since the *image bytes themselves* still load separately from
          // this fetch -- see the image-side handling below).
          //
          // The image slot reuses the real content template's own sizing
          // classes/aspect-ratio (node--shanti-image--grid-details
          // .html.twig, shanti-grid-details-image[--portrait]) with the
          // aspect ratio already known from the row's own thumbnail data
          // -- so this slot is already sized correctly, matching the real
          // image exactly, before the fetch even starts. The meta slot's
          // real height is unknowable in advance, so it gets a generous
          // fixed estimate instead; measureAndShift()'s FLIP animation
          // smooths over whatever gap remains once real content arrives.
          const isPortrait = image.aspectRatio > 0 && image.aspectRatio < 1;
          panel.innerHTML = '<button type="button" class="shanti-grid-view-panel-close" aria-label="Close">×</button>'
            // Ports production's ppd-nav-arrow buttons (pig-shanti-ext.js's
            // gotoImage('prev'/'next')): step to the adjacent image in
            // gallery order, staying open in place if it's on the same
            // row, closing and reopening at the new row otherwise -- the
            // exact same logic openPanelFor already applies to a real
            // click, since gotoAdjacentImage feeds its result through it.
            + '<button type="button" class="shanti-grid-view-panel-nav shanti-grid-view-panel-prev" aria-label="' + Drupal.t('Previous image') + '"><span class="icon shanticon-arrow3-left" aria-hidden="true"></span></button>'
            + '<button type="button" class="shanti-grid-view-panel-nav shanti-grid-view-panel-next" aria-label="' + Drupal.t('Next image') + '"><span class="icon shanticon-arrow3-right" aria-hidden="true"></span></button>'
            + '<div class="shanti-grid-view-panel-body">'
            + '<div class="shanti-grid-details">'
            + '<div class="shanti-grid-details-image' + (isPortrait ? ' shanti-grid-details-image--portrait' : '') + '"'
            + (image.aspectRatio ? ' style="aspect-ratio: ' + image.aspectRatio + ';"' : '') + '>'
            + '<div class="shanti-grid-view-loading shanti-grid-view-loading-image" role="alert" aria-live="assertive">'
            + '<ul class="shanti-grid-view-loading-dots"><li></li><li></li><li></li><li></li></ul>'
            + '<div class="shanti-grid-view-loading-text">' + Drupal.t('Loading…') + '</div>'
            + '</div>'
            + '</div>'
            + '<div class="shanti-grid-details-meta shanti-grid-view-loading-meta-slot">'
            + '<div class="shanti-grid-view-loading shanti-grid-view-loading-meta" role="alert" aria-live="assertive">'
            + '<div class="shanti-grid-view-loading-ring"></div>'
            + '</div>'
            + '</div>'
            + '</div>'
            + '</div>';
          panel.querySelector('.shanti-grid-view-panel-close').addEventListener('click', closePanel);
          measureAndShift();

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

              // The fetched HTML's metadata is now fully ready, but its
              // <img> tag still needs to actually download the real IIIF
              // image bytes -- a separate, often slower, browser-managed
              // request this fetch knows nothing about. Overlay the
              // 4-dot spinner on the image box until that finishes (or
              // the image is already cached and loads instantly).
              const imageBox = panelBody.querySelector('.shanti-grid-details-image');
              const img = imageBox ? imageBox.querySelector('img') : null;
              if (img && !(img.complete && img.naturalWidth > 0)) {
                const overlay = document.createElement('div');
                overlay.className = 'shanti-grid-view-loading shanti-grid-view-loading-overlay';
                overlay.innerHTML = '<ul class="shanti-grid-view-loading-dots"><li></li><li></li><li></li><li></li></ul>';
                imageBox.appendChild(overlay);
                const removeOverlay = () => {
                  overlay.remove();
                };
                img.addEventListener('load', removeOverlay, { once: true });
                img.addEventListener('error', removeOverlay, { once: true });
              }

              measureAndShift();
              scrollPanelIntoView();
            })
            .catch(() => {
              if (!panel) {
                return;
              }
              panel.querySelector('.shanti-grid-view-panel-body').textContent = Drupal.t('Unable to load details for this image.');
              measureAndShift();
              scrollPanelIntoView();
            });
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
          openPanelFor(figure, progressiveImage, image);
        });

        /**
         * Steps to the previous/next image in gallery order (wrapping at
         * either end), reusing openPanelFor's own same-row/different-row
         * logic -- matches production's gotoImage(). Looks the image data
         * up via the ProgressiveImage's own `filename` (== each image's
         * thumbUrl, see imageData above) rather than reading the target
         * figure's real <img src>, since pig.js only renders <img> DOM for
         * figures near the current scroll position -- the adjacent image
         * may not be one of them yet.
         */
        const gotoAdjacentImage = (direction) => {
          if (!selectedFigure) {
            return;
          }
          const currentProgressiveImage = pig.images.find((candidate) => candidate.element === selectedFigure);
          if (!currentProgressiveImage) {
            return;
          }
          const currentIndex = pig.images.indexOf(currentProgressiveImage);
          let nextIndex = direction === 'next' ? currentIndex + 1 : currentIndex - 1;
          if (nextIndex < 0) {
            nextIndex = pig.images.length - 1;
          }
          if (nextIndex > pig.images.length - 1) {
            nextIndex = 0;
          }
          const nextProgressiveImage = pig.images[nextIndex];
          const nextImage = infoByThumbUrl.get(nextProgressiveImage.filename);
          if (!nextImage) {
            return;
          }
          openPanelFor(nextProgressiveImage.element, nextProgressiveImage, nextImage);
        };

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
