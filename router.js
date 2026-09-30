// URL routing for the studio: every panel page gets its own address
// (/app/<page>), reloads stay on the same page, back/forward work,
// and unknown addresses show a proper 404 view.
(() => {
  const toSlug = p => p.replace(/[A-Z]/g, c => '-' + c.toLowerCase());
  const toPage = s => s.replace(/-([a-z])/g, (_, c) => c.toUpperCase());
  const known = () => new Set(Object.keys(titleMap));

  function parse(path) {
    path = path.replace(/\/+$/, '') || '/';
    if (path === '/' || path === '/login' || path === '/app') return { page: null };
    const m = path.match(/^\/app\/([a-z-]+)$/);
    if (m && known().has(toPage(m[1]))) return { page: toPage(m[1]) };
    return { notFound: true };
  }

  const initial = parse(location.pathname);
  let pending = initial.page, notFound = !!initial.notFound, fromPop = false;

  function sync(url) {
    if (location.pathname === url) return;
    history[fromPop ? 'replaceState' : 'pushState']({}, '', url);
  }

  function notFoundView() {
    document.title = 'صفحه پیدا نشد · Repol';
    $('#app').innerHTML = `<main class="nf"><div class="nf-glow" aria-hidden="true"></div><div class="nf-card">${brand()}<div class="nf-code" aria-hidden="true">۴۰۴</div><h1>این صفحه اینجا نیست.</h1><p>آدرسی که باز کردی وجود ندارد یا جابه‌جا شده است. از اینجا به مسیر درست برگرد.</p><div class="nf-actions"><button class="btn primary" data-nf="home">${icon('arrow')}بازگشت به پنل</button><button class="btn" data-nf="back">صفحهٔ قبل</button></div></div></main>`;
    $$('[data-nf]').forEach(b => b.onclick = () => {
      if (b.dataset.nf === 'back' && history.length > 1) return history.back();
      notFound = false;
      history.replaceState({}, '', '/');
      boot();
    });
  }

  const baseRender = render, baseAuth = auth;
  render = function () {
    if (notFound) return notFoundView();
    baseRender();
    if (pending && pending !== state.page && $('.studio-layout')) {
      const p = pending; pending = null;
      return go(p); // go() loads admin data and checks access before rendering
    }
    pending = null;
    sync(state.page === 'dashboard' ? '/app' : '/app/' + toSlug(state.page));
  };
  auth = function () {
    if (notFound) return notFoundView();
    baseAuth();
    sync('/login');
  };

  window.addEventListener('popstate', async () => {
    const r = parse(location.pathname);
    fromPop = true;
    try {
      if (r.notFound) { notFound = true; notFoundView(); }
      else if (notFound) { notFound = false; pending = r.page; await boot(); }
      else if (state.data && $('.studio-layout')) await go(r.page || 'dashboard');
    } finally { fromPop = false; }
  });
})();
