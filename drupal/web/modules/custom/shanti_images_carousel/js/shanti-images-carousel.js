/**
 * @file
 * Fetches and renders the sibling-image carousel on the single-image page.
 *
 * Deliberately NOT built on the theme's vendored jssor-slider
 * (shanti_sarvaka/jssor-slider): nothing else in this codebase actually
 * invokes it yet (grepped -- it's declared in shanti_sarvaka.libraries.yml
 * and pulled in as a dependency, but zero real usage), so there's no proven
 * markup/init convention to follow, and blind-integrating an unfamiliar
 * third-party API without a working local reference is a real risk. A
 * plain horizontally-scrolling strip (CSS scroll-snap + prev/next buttons
 * that scrollBy a fixed amount) gets the same "browse siblings" behavior
 * D7's flexslider carousel provided, using patterns already proven in this
 * codebase (shanti_grid_view.js's own prev/next click handling).
 */

(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.shantiImagesCarousel = {
    attach(context) {
      once('shanti-images-carousel', '.shanti-image-page-carousel', context).forEach((el) => {
        const nid = el.getAttribute('data-nid');
        if (!nid) {
          return;
        }
        fetch(`/api/carouseldata/${nid}`)
          .then((response) => (response.ok ? response.json() : { siblings: [] }))
          .then((data) => renderCarousel(el, data.siblings || []))
          .catch(() => {
            el.classList.add('shanti-image-page-carousel--empty');
          });
      });
    },
  };

  function renderCarousel(el, siblings) {
    if (!siblings.length) {
      el.classList.add('shanti-image-page-carousel--empty');
      return;
    }

    const strip = document.createElement('div');
    strip.className = 'shanti-image-page-carousel-strip';

    let activeLink = null;
    siblings.forEach((sibling) => {
      const link = document.createElement('a');
      link.href = sibling.url;
      link.className = 'shanti-image-page-carousel-item';
      if (sibling.portrait) {
        link.classList.add('shanti-image-page-carousel-item--portrait');
      }
      if (sibling.active) {
        link.classList.add('shanti-image-page-carousel-item--active');
        activeLink = link;
      }
      link.title = sibling.title;

      const img = document.createElement('img');
      img.src = sibling.thumbUrl;
      img.alt = sibling.title;
      img.loading = 'lazy';
      link.appendChild(img);

      strip.appendChild(link);
    });

    const prevBtn = document.createElement('button');
    prevBtn.type = 'button';
    prevBtn.className = 'shanti-image-page-carousel-nav shanti-image-page-carousel-nav--prev';
    prevBtn.setAttribute('aria-label', Drupal.t('Scroll siblings left'));
    prevBtn.addEventListener('click', () => strip.scrollBy({ left: -strip.clientWidth * 0.8, behavior: 'smooth' }));

    const nextBtn = document.createElement('button');
    nextBtn.type = 'button';
    nextBtn.className = 'shanti-image-page-carousel-nav shanti-image-page-carousel-nav--next';
    nextBtn.setAttribute('aria-label', Drupal.t('Scroll siblings right'));
    nextBtn.addEventListener('click', () => strip.scrollBy({ left: strip.clientWidth * 0.8, behavior: 'smooth' }));

    el.appendChild(prevBtn);
    el.appendChild(strip);
    el.appendChild(nextBtn);

    if (activeLink) {
      activeLink.scrollIntoView({ inline: 'center', block: 'nearest' });
    }
  }
})(Drupal, once);
