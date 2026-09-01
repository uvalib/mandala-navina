/**
 * @file
 * Click-to-open OpenSeadragon deep-zoom viewer, ported from D7's
 * shanti-main-images.js Drupal.behaviors.shantiImagesIIIF (sarvaka_images
 * theme). Same lazy-load-on-click UX: the OpenSeadragon library itself is a
 * declared library dependency (loaded once, up front, since D11 doesn't have
 * D7's ad hoc $.getScript() pattern), but the Viewer instance is only
 * constructed the first time a given trigger is clicked.
 */
((Drupal, once, drupalSettings) => {
  Drupal.behaviors.shantiIiifDeepZoom = {
    attach(context) {
      once('shanti-iiif-deep-zoom', '.iiif-deep-zoom', context).forEach((wrapper) => {
        const trigger = wrapper.querySelector('.iiif-deep-zoom-trigger');
        const infoUrl = wrapper.dataset.iiifInfoUrl;
        if (!trigger || !infoUrl) {
          return;
        }

        // D7 stored rotation 0-360; OpenSeadragon's `degrees` expects the
        // shorter signed direction for anything past a half turn.
        let rotation = parseInt(wrapper.dataset.iiifRotation, 10) || 0;
        if (rotation > 180) {
          rotation = ((360 - rotation) % 360) * -1;
        }

        let overlay;
        let viewer;

        const close = () => {
          if (overlay) {
            overlay.classList.remove('is-open');
          }
          if (viewer) {
            viewer.viewport.goHome(true);
          }
        };

        const open = () => {
          if (!overlay) {
            overlay = document.createElement('div');
            overlay.className = 'iiif-deep-zoom-overlay';
            overlay.innerHTML =
              '<button type="button" class="iiif-deep-zoom-close" aria-label="Close zoomable image viewer"></button>' +
              '<div class="iiif-deep-zoom-canvas"></div>';
            document.body.appendChild(overlay);
            overlay.querySelector('.iiif-deep-zoom-close').addEventListener('click', close);
          }
          overlay.classList.add('is-open');

          if (!viewer) {
            viewer = OpenSeadragon({
              element: overlay.querySelector('.iiif-deep-zoom-canvas'),
              prefixUrl: `${drupalSettings.shantiIiif.imagesPath}/`,
              tileSources: infoUrl,
              degrees: rotation,
              showRotationControl: true,
              showNavigator: true,
              navigatorPosition: 'TOP_RIGHT',
              zoomPerScroll: 1.08,
              zoomPerSecond: 2,
              homeFillsViewer: true,
              visibilityRatio: 1,
              minZoomLevel: -1,
              maxZoomLevel: 25,
            });
          }
          else {
            viewer.viewport.goHome(true);
          }
        };

        trigger.addEventListener('click', open);

        document.addEventListener('keyup', (event) => {
          if (event.key === 'Escape' && overlay && overlay.classList.contains('is-open')) {
            close();
          }
        });
      });
    },
  };
})(Drupal, once, drupalSettings);
