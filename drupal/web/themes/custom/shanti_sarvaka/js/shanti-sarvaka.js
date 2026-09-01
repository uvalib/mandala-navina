/**
 * @file
 * Two small behaviors:
 * - Explore panel toggle, ported from the essential piece of D7's
 *   shanti-main.js .menu-exploretoggle click handler (search, scrollbars
 *   aren't wired up yet).
 * - Drilldown submenu slide (menu.html.twig): click a parent item to
 *   slide its submenu into view, click "Back" to slide it back out --
 *   approximates D7's multilevelpushmenu plugin without porting it.
 * The hamburger's own open/close uses Bootstrap 5's native Offcanvas JS,
 * no custom code needed for that part.
 */
((Drupal, once) => {
  Drupal.behaviors.shantiSarvakaExplorePanel = {
    attach(context) {
      once('shanti-explore-toggle', '[data-explore-toggle]', context).forEach((el) => {
        el.addEventListener('click', (event) => {
          event.preventDefault();
          const panel = document.getElementById(el.getAttribute('data-explore-toggle'));
          if (panel) {
            panel.classList.toggle('show-topmenu');
          }
        });
      });
    },
  };

  Drupal.behaviors.shantiSarvakaDrilldown = {
    attach(context) {
      once('shanti-drilldown-toggle', '.shanti-drilldown-toggle', context).forEach((el) => {
        el.addEventListener('click', (event) => {
          event.preventDefault();
          const submenu = el.nextElementSibling;
          if (submenu && submenu.classList.contains('shanti-drilldown-submenu')) {
            submenu.classList.add('shanti-drilldown-active');
          }
        });
      });
      once('shanti-drilldown-back', '.shanti-drilldown-back', context).forEach((el) => {
        el.addEventListener('click', (event) => {
          event.preventDefault();
          const submenu = el.closest('.shanti-drilldown-submenu');
          if (submenu) {
            submenu.classList.remove('shanti-drilldown-active');
          }
        });
      });
    },
  };
})(Drupal, once);
