/**
 * @file
 * Explore panel toggle, ported from the essential piece of D7's
 * shanti-main.js .menu-exploretoggle click handler (the rest of that file
 * -- search, scrollbars, drilldown menu -- isn't wired up yet). The
 * hamburger's own toggle uses Bootstrap 5's native collapse JS instead of
 * D7's multilevelpushmenu plugin, which was never ported (see A3).
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
})(Drupal, once);
