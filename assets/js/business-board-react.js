/**
 * WorkBoard React Web bridge — v4.17.0
 *
 * Phase 1 of the frontend migration. The existing PHP/WordPress backend and
 * mature WorkBoard view logic stay untouched, while the global application
 * header is now owned by React (WordPress wp-element). Existing delegated
 * event handlers continue to work through the same CSS/data hooks.
 */
(function () {
  'use strict';

  var el = window.wp && window.wp.element;
  if (!el || !el.createElement) return;

  var h = el.createElement;
  var render = el.render;
  var createRoot = el.createRoot;
  var config = window.BBReactConfig || {};

  function Icon(props) {
    var name = props.name;
    var common = { viewBox: '0 0 24 24', 'aria-hidden': 'true' };
    if (name === 'menu') return h('svg', common, h('path', { d: 'M4 6h16M4 12h16M4 18h16' }));
    if (name === 'analysis') return h('svg', common, h('path', { d: 'M4 19h16M6 16V9m6 7V5m6 11v-4' }));
    if (name === 'plus') return h('svg', common, h('path', { d: 'M12 5v14M5 12h14' }));
    if (name === 'filter') return h('svg', common, h('path', { d: 'M4 5h16l-6.5 7.2V18l-3 1v-6.8L4 5z' }));
    if (name === 'clear') return h('svg', common, h('path', { d: 'M6 6l12 12M18 6 6 18' }));
    if (name === 'collapse') return h('svg', common, h('path', { d: 'M7 10l5 5 5-5' }));
    return null;
  }

  function Header() {
    return h('header', { className: 'bb-topbar bb-react-topbar', 'data-react-owned': '1' },
      h('div', { className: 'bb-topbar-left' },
        h('button', { type: 'button', className: 'bb-sidebar-toggle', 'aria-label': 'Collapse menu', title: 'Show / hide menu' }, '☰'),
        h('button', { type: 'button', className: 'bb-mobile-menu', 'aria-label': 'Show menu', title: 'Show menu', 'aria-expanded': 'false' }, h(Icon, { name: 'menu' })),
        h('div', null,
          h('h1', { id: 'bb-page-title' }, 'Workspaces'),
          h('p', { id: 'bb-page-subtitle' }, 'Select a Workspace to open its operational board')
        )
      ),
      h('div', { className: 'bb-top-actions' },
        h('button', { className: 'bb-icon-btn bb-mobile-analysis-toggle', type: 'button', 'aria-label': 'Workspace analysis', title: 'Workspace analysis' }, h(Icon, { name: 'analysis' })),
        h('button', { className: 'bb-icon-btn bb-mobile-add-area', type: 'button', 'aria-label': 'Add Work Area', title: 'Add Work Area' }, h(Icon, { name: 'plus' })),
        h('button', { className: 'bb-icon-btn bb-mobile-filter-top', type: 'button', 'aria-label': 'Filters', title: 'Filters' }, h(Icon, { name: 'filter' })),
        h('button', { className: 'bb-icon-btn bb-top-filter-reset', type: 'button', 'aria-label': 'Clear filters', title: 'Clear filters', hidden: true }, h(Icon, { name: 'clear' })),
        h('button', { className: 'bb-icon-btn bb-workspace-top-toggle', type: 'button', 'aria-label': 'Hide workspace controls', title: 'Hide workspace controls', hidden: true }, h(Icon, { name: 'collapse' })),
        h('div', { className: 'bb-header-logo', title: 'WorkBoard' },
          h('img', { className: 'bb-header-logo-img bb-header-wordmark-img', src: config.wordmarkUrl || '', alt: 'WorkBoard' })
        )
      )
    );
  }

  function mountHeader() {
    var current = document.querySelector('#bb-app .bb-topbar');
    if (!current || current.getAttribute('data-react-owned') === '1') return;

    // Preserve the current text because legacy routing may have already updated it.
    var title = current.querySelector('#bb-page-title');
    var subtitle = current.querySelector('#bb-page-subtitle');
    var titleText = title ? title.textContent : 'Workspaces';
    var subtitleText = subtitle ? subtitle.textContent : '';

    var host = document.createElement('div');
    host.className = 'bb-react-header-root';
    current.parentNode.replaceChild(host, current);

    var node = h(Header);
    if (createRoot) {
      var root = createRoot(host);
      root.render(node);
      host.__bbReactRoot = root;
    } else if (render) {
      render(node, host);
    } else {
      return;
    }

    // Restore route text after React's initial render.
    setTimeout(function () {
      var t = host.querySelector('#bb-page-title');
      var st = host.querySelector('#bb-page-subtitle');
      if (t) t.textContent = titleText;
      if (st) st.textContent = subtitleText;
    }, 0);

    document.documentElement.classList.add('bb-react-web-enabled');
    window.WorkboardReact = window.WorkboardReact || {};
    window.WorkboardReact.version = config.version || '';
    window.WorkboardReact.phase = 1;
    window.WorkboardReact.headerMounted = true;
  }

  function boot() {
    mountHeader();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, { once: true });
  } else {
    boot();
  }
})();
