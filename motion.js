// Repol motion layer.
// Focal moment: the live "comment → engine → reply" flow on the sign-in story.
// Supporting motion: cursor light on cards, count-up numbers, view transitions
// between panel pages (the active nav pill slides), circular theme reveal.
(() => {
  const root = document.documentElement;
  const reduce = matchMedia('(prefers-reduced-motion: reduce)');
  const canVT = () => 'startViewTransition' in document && !reduce.matches && root.dataset.motion !== 'off';
  const easeOut = t => 1 - Math.pow(2, -10 * t); // exponential ease-out

  /* ---------- cursor light on cards (one listener, rAF-throttled) ---------- */
  const LIT = '.stat,.card:not(.wallet-visual):not(.command-hero),.plan-card:not(.featured),.integration-card,.template-card,.auth-story';
  let frame = 0, last = null;
  document.addEventListener('pointermove', e => {
    if (e.pointerType !== 'mouse') return;
    last = e;
    if (frame) return;
    frame = requestAnimationFrame(() => {
      frame = 0;
      const el = last.target.closest?.(LIT);
      if (!el) return;
      const r = el.getBoundingClientRect();
      el.style.setProperty('--mx', `${last.clientX - r.left}px`);
      el.style.setProperty('--my', `${last.clientY - r.top}px`);
    });
  }, { passive: true });

  /* ---------- count-up for stat numbers ---------- */
  const faDigits = '۰۱۲۳۴۵۶۷۸۹';
  const toLatin = s => s.replace(/[۰-۹]/g, d => faDigits.indexOf(d)).replace(/[٬,]/g, '');
  function countUp(el) {
    if (el.dataset.counted) return;
    el.dataset.counted = '1';
    const raw = el.textContent.trim();
    const m = toLatin(raw).match(/^(\d+)(.*)$/s);
    if (!m || reduce.matches || root.dataset.motion === 'off') return;
    const target = +m[1], suffix = raw.slice(raw.length - m[2].length);
    if (!target) return;
    const t0 = performance.now(), dur = 900;
    const step = now => {
      const p = Math.min(1, (now - t0) / dur);
      el.textContent = Math.round(target * easeOut(p)).toLocaleString('fa-IR') + suffix;
      if (p < 1) requestAnimationFrame(step); else el.textContent = raw;
    };
    requestAnimationFrame(step);
  }

  /* ---------- the sign-in story: pause the loop when hidden ---------- */
  function syncStory() {
    const story = document.querySelector('.auth-story');
    if (story) story.classList.toggle('is-paused', document.hidden);
  }
  document.addEventListener('visibilitychange', syncStory);

  new MutationObserver(() => {
    document.querySelectorAll('.stat-value:not([data-counted])').forEach(countUp);
    syncStory();
  }).observe(document.getElementById('app'), { childList: true, subtree: true });

  /* ---------- view transitions between panel pages ---------- */
  const baseGo = go;
  go = function (p) {
    if (!canVT() || !document.querySelector('.studio-layout') || p === state.page) return baseGo(p);
    let done;
    const vt = document.startViewTransition(() => (done = baseGo(p)));
    return vt.updateCallbackDone.then(() => done);
  };

  /* ---------- theme switch: circular reveal from the button ---------- */
  document.addEventListener('click', e => {
    const b = e.target.closest('[data-studio="theme"],[data-theme-toggle]');
    if (!b || b.__vt || !canVT()) return;
    e.stopImmediatePropagation();
    e.preventDefault();
    const r = b.getBoundingClientRect(), x = r.left + r.width / 2, y = r.top + r.height / 2;
    const radius = Math.hypot(Math.max(x, innerWidth - x), Math.max(y, innerHeight - y));
    root.classList.add('vt-theme');
    const vt = document.startViewTransition(() => { b.__vt = true; b.click(); b.__vt = false; });
    vt.ready.then(() => root.animate(
      { clipPath: [`circle(0px at ${x}px ${y}px)`, `circle(${radius}px at ${x}px ${y}px)`] },
      { duration: 650, easing: 'cubic-bezier(.16,1,.3,1)', pseudoElement: '::view-transition-new(root)' }));
    vt.finished.finally(() => root.classList.remove('vt-theme'));
  }, true);
})();
