// Repol live layer: connects the studio to the real PHP API.
// Demo mode keeps its sample content; signed-in users get live data.
(() => {
  Object.assign(icons, {
    trash: '<path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13M10 11v6M14 11v6"/>',
    gate: '<path d="M12 3a5 5 0 0 0-5 5v3H5v10h14V11h-2V8a5 5 0 0 0-5-5Z"/><path d="m9 16 2 2 4-4"/>',
    zarin: '<rect x="2" y="5" width="20" height="14" rx="3"/><path d="M2 10h20M6 15h5M16 15h2"/>'
  });
  const live = () => !state.demo && state.data;
  const pct = v => (v > 0 ? '↗ ' : v < 0 ? '↘ ' : '') + fa(Math.abs(v)) + '٪';

  /* ---------- API hooks: payment redirect + extra business fields ---------- */
  const baseApi = api;
  api = async function (url, options = {}) {
    if (url === '/admin/business' && options.method === 'PUT') {
      const body = JSON.parse(options.body);
      const z = $('#biz-zarinpal'), r = $('#biz-trx-rate');
      if (z) body.zarinpalEnabled = z.checked;
      if (r) body.trxPriceIRR = Number(r.value || 0) * 10;
      options = { ...options, body: JSON.stringify(body) };
    }
    const result = await baseApi(url, options);
    if (url === '/orders' && result?.payUrl) {
      toast('در حال انتقال به درگاه امن زرین‌پال…');
      location.href = result.payUrl;
      return new Promise(() => {}); // page is leaving
    }
    return result;
  };

  /* ---------- Dashboard: real numbers and chart ---------- */
  const baseStats = stats, baseChart = chart;
  stats = function () {
    if (!live() || !state.data.stats) return baseStats();
    const s = state.data.stats, w = s[state.period] || s.week;
    const cards = [
      ['message', 'گفتگوهای شروع‌شده', w.conversations, w.conversationsDelta, 'کامنت، دایرکت و استوری'],
      ['bolt', 'پاسخ‌های خودکار', w.replies, w.repliesDelta, `${fa(w.files)} فایل ارسال‌شده`],
      ['users', 'مخاطب‌های درگیر', w.engaged, w.engagedDelta, `${fa(w.follows)} فالو تأییدشده`],
      ['flow', 'سناریوهای آماده', s.rulesReady, null, state.data.connections.instagram ? 'روی پیج فعال' : 'منتظر اتصال اینستاگرام'],
    ];
    return `<div class="stats">${cards.map(([i, t, v, d, sub]) => `<article class="stat"><div class="stat-top"><span>${t}</span><div class="stat-icon">${icon(i)}</div></div><div class="stat-value">${fa(v)}</div><div class="stat-bottom">${d !== null ? `<span class="trend ${d < 0 ? 'down' : ''}">${pct(d)}</span>` : ''}${sub}</div></article>`).join('')}</div>`;
  };

  chart = function () {
    if (!live() || !state.data.stats) return baseChart();
    const w = state.data.stats[state.period] || state.data.stats.week, pts = w.series;
    const max = Math.max(4, ...pts.map(p => Math.max(p.in, p.out)));
    const nice = Math.ceil(max / 3) * 3, X = i => 40 + i * (498 / Math.max(1, pts.length - 1)), Y = v => 140 - v / nice * 120;
    const line = k => pts.map((p, i) => `${i ? 'L' : 'M'}${X(i).toFixed(1)} ${Y(p[k]).toFixed(1)}`).join(' ');
    const days = ['یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه', 'شنبه'];
    const labels = pts.map((p, i) => ({ i, t: state.period === 'week' ? days[new Date(p.day).getDay()] : new Date(p.day).toLocaleDateString('fa-IR', { day: 'numeric', month: 'short' }) }))
      .filter(l => state.period === 'week' || l.i % 5 === 0);
    const total = pts.reduce((s, p) => s + p.out, 0);
    return `<section class="card"><div class="card-head"><div><h2>نبض گفتگوها</h2><p>پاسخ‌های خودکار و گفتگوهای تازه‌ی پیجت</p></div><div class="segmented"><button data-period="week" class="${state.period === 'week' ? 'active' : ''}">هفتگی</button><button data-period="month" class="${state.period === 'month' ? 'active' : ''}">ماهانه</button></div></div><div class="chart-legend"><span><i class="legend-dot"></i>پاسخ‌های خودکار</span><span><i class="legend-dot gray"></i>گفتگوهای جدید</span></div><div class="chart-wrap"><svg class="chart" viewBox="0 0 560 180" role="img" aria-label="نمودار ${fa(total)} پاسخ خودکار در این دوره">${[0, 1, 2, 3].map(k => `<line x1="35" x2="540" y1="${20 + k * 40}" y2="${20 + k * 40}"/><text x="0" y="${24 + k * 40}">${fa(nice * (3 - k) / 3)}</text>`).join('')}<path class="area" d="${line('out')} L${X(pts.length - 1)} 140 L40 140Z"/><path class="curve2" d="${line('in')}"/><path class="curve" d="${line('out')}"/>${labels.map(l => `<text x="${X(l.i) - 12}" y="168">${l.t}</text>`).join('')}</svg></div><div class="chart-footer"><span>${total ? `${fa(total)} پاسخ خودکار در ${state.period === 'week' ? '۷' : '۳۰'} روز گذشته` : 'هنوز گفتگویی در این دوره ثبت نشده'}</span><strong>${state.data.connections.instagram ? 'داده‌ی زنده از پیج' : 'پیج هنوز وصل نیست'}</strong></div></section>`;
  };

  const baseMini = connectionsMini;
  connectionsMini = function () {
    if (!live()) return baseMini();
    const c = state.data.connections, ig = state.data.instagramAccount;
    const row = (id, t, sub, ok) => `<div class="connection-item"><div class="channel-icon ${id}">${icon(id === 'email' ? 'mail' : id)}</div><div><strong>${t}</strong><small>${sub}</small></div><span class="chip ${ok ? 'green' : 'orange'}">${ok ? 'متصل' : 'متصل نشده'}</span></div>`;
    return `<section class="card"><div class="card-head"><div><h2>همه‌چیز به هم متصل</h2><p>وضعیت کانال‌های فضای کار شما</p></div><button class="text-link" data-page="connections">مدیریت${icon('chevron')}</button></div>${row('instagram', 'اینستاگرام', ig ? '@' + esc(ig.username) : 'اتصال حساب حرفه‌ای', c.instagram)}${row('email', 'ایمیل ورود', 'کد تأیید و اعلان‌ها', c.email)}${row('telegram', 'گزارش تلگرام', 'گزارش مدیر', c.telegram)}</section>`;
  };

  /* ---------- Pages with live content ---------- */
  const basePage = pageContent;
  pageContent = function () {
    if (live()) {
      if (state.page === 'connections') return connectionsPage();
      if (state.page === 'inbox') return inboxPage();
      if (state.page === 'files') return filesPage();
    }
    return basePage();
  };

  function connectionsPage() {
    const d = state.data, ig = d.instagramAccount, ready = d.integrations?.instagramReady;
    const card = (id, title, text, status, ok, action) => `<article class="card integration-card"><div class="integration-top"><div class="channel-icon ${id}">${icon(id === 'email' ? 'mail' : id === 'storage' ? 'cloud' : id)}</div><span class="chip ${ok ? 'green' : 'orange'}">${status}</span></div><h3>${title}</h3><p>${text}</p>${action}</article>`;
    return `${heading('یک فضای کار، چند اتصال', 'پیج اینستاگرامت را وصل کن تا دایرکت هوشمند روی کامنت، دایرکت و استوری کار کند.')}<div class="integration-grid">${
      card('instagram', 'اینستاگرام', ig ? `@${esc(ig.username)} وصل است. اعتبار اتصال تا ${new Date(ig.expires).toLocaleDateString('fa-IR')} (خودکار تمدید می‌شود).` : 'با حساب حرفه‌ای (Business یا Creator) وارد شو و اجازه‌ی مدیریت پیام‌ها و کامنت‌ها را بده. رمز اینستاگرام هیچ‌وقت به ریپل داده نمی‌شود.',
        ig ? 'متصل' : ready ? 'آماده‌ی اتصال' : 'در انتظار تنظیم سرور', !!ig,
        ig ? `<button class="btn" data-live="ig-disconnect">${icon('close')}قطع اتصال</button>` : ready ? `<a class="btn primary" href="/api/instagram/connect">${icon('instagram')}اتصال با اینستاگرام</a>` : `<button class="btn" disabled>برنامه‌ی Meta هنوز روی سرور تنظیم نشده</button>`)
    }${card('email', 'ایمیل ورود', d.connections.email ? 'کد ورود و تأیید ثبت‌نام از info@repol.ir فرستاده می‌شود.' : 'ارسال ایمیل هنوز روی سرور تنظیم نشده است.', d.connections.email ? 'فعال' : 'نیازمند تنظیم', d.connections.email, '')
    }${card('telegram', 'گزارش تلگرام', d.connections.telegram ? 'گزارش سفارش‌ها و آمار روزانه برای مدیر ارسال می‌شود.' : 'با تنظیم توکن ربات روی سرور، گزارش‌ها به تلگرام مدیر می‌رسد.', d.connections.telegram ? 'فعال' : 'اختیاری', d.connections.telegram, '')
    }${card('storage', 'کتابخانه‌ی فایل', `${fa(d.files.length)} فایل روی سرور ریپل. هر فایل با لینک امن و غیرقابل‌حدس برای اینستاگرام فرستاده می‌شود.`, 'فعال', true, `<button class="btn" data-page="files">${icon('image')}مدیریت فایل‌ها</button>`)}</div>`;
  }

  let activeChat = 0;
  function inboxPage() {
    const list = state.data.conversations || [];
    if (!list.length) return `${heading('گفتگوها، نزدیک‌تر از همیشه', 'پیام‌ها و پاسخ‌های دایرکت هوشمند، همه در یک صندوق.')}<section class="card">${empty('message', 'اولین گفتگوی تو هنوز نرسیده.', state.data.connections.instagram ? 'به‌محض اینکه کسی کامنت بگذارد یا دایرکت بدهد، گفتگو اینجا ظاهر می‌شود.' : 'حساب اینستاگرام را متصل کن تا پیام‌های واقعی اینجا نمایش داده شوند.', state.data.connections.instagram ? '' : '<button class="btn primary" data-page="connections">اتصال حساب</button>')}</section>`;
    activeChat = Math.min(activeChat, list.length - 1);
    const ago = t => { const m = Math.round((Date.now() - t) / 60000); return m < 1 ? 'الان' : m < 60 ? fa(m) + ' دقیقه' : m < 1440 ? fa(Math.round(m / 60)) + ' ساعت' : fa(Math.round(m / 1440)) + ' روز'; };
    return `${heading('گفتگوها، نزدیک‌تر از همیشه', 'پیام‌ها و پاسخ‌های دایرکت هوشمند، همه در یک صندوق.')}<section class="card"><div class="inbox"><div class="inbox-list"><div class="inbox-search"><span class="chip purple">${fa(list.length)} گفتگو</span></div>${list.map((c, i) => {
      const last = c.messages.at(-1) || { text: '' };
      return `<button class="conversation ${i === activeChat ? 'active' : ''}" data-live-chat="${i}"><div class="avatar">${esc((c.handle || c.name).charAt(0))}</div><div><strong>${esc(c.handle ? '@' + c.handle : c.name)}</strong><p>${esc(last.text.slice(0, 60))}</p></div><small>${ago(c.lastSeen)}</small></button>`;
    }).join('')}</div><div class="chat-pane" id="chat-pane">${chatView(list[activeChat])}</div></div></section>`;
  }

  function chatView(c) {
    return `<div class="chat-head"><strong>${esc(c.handle ? '@' + c.handle : c.name)}</strong><span class="chip ${c.follows ? 'green' : 'purple'}">${c.follows ? 'فالوور' : 'مخاطب'}</span></div><div class="chat-body">${c.messages.map(m => `<div class="bubble ${m.out ? 'out' : ''}">${esc(m.text)}</div>`).join('')}</div><form class="chat-compose" id="live-reply" data-contact="${esc(c.id)}"><input name="text" maxlength="1000" placeholder="پاسخ دستی (تا ۲۴ ساعت بعد از آخرین پیام مخاطب)" aria-label="متن پاسخ" required><button class="btn primary" type="submit" aria-label="ارسال">${icon('telegram')}</button></form>`;
  }

  function filesPage() {
    const f = state.data.files, used = f.reduce((s, x) => s + x.size, 0);
    return `${heading('محتواهایت، مرتب و در دسترس', 'فایل آموزشی، عکس، ویدیو یا ویس را اینجا بگذار و در سناریو انتخابش کن تا بعد از فالو برای مخاطب برود.', `<button class="btn primary" data-action="upload">${icon('plus')}افزودن فایل</button>`)}<button class="upload-zone" data-action="upload">${icon('upload')}<strong>فایلت را انتخاب کن</strong><small>عکس، ویدیوی MP4، ویس و صدا، PDF · حداکثر ۲۵ مگابایت برای هر فایل</small></button><input class="hidden" type="file" id="file-input" accept=".png,.jpg,.jpeg,.webp,.pdf,.mp4,.mp3,.ogg,.m4a,.aac,.txt"><div class="usage">${fa(f.length)} فایل · ${formatSize(used)} از ۲ گیگابایت</div>${f.length ? `<div class="file-grid">${f.map(x => `<article class="card file-card"><div class="file-preview">${icon(x.type.startsWith('image') ? 'image' : x.type.startsWith('video') ? 'video' : x.type.startsWith('audio') ? 'music' : 'file')}</div><h3 title="${esc(x.name)}">${esc(x.name)}</h3><div class="file-meta"><span>${formatSize(x.size)}</span><span><button class="icon-btn" data-live-delfile="${esc(x.id)}" aria-label="حذف ${esc(x.name)}">${icon('trash')}</button><button class="icon-btn" data-download="${esc(x.id)}" aria-label="دانلود ${esc(x.name)}">${icon('download')}</button></span></div></article>`).join('')}</div>` : `<section class="card">${empty('image', 'جا برای ایده‌هایت خالی است.', 'اولین فایل آموزشی را اضافه کن تا در سناریوها قابل انتخاب باشد.')}</section>`}`;
  }

  /* ---------- Uploads: larger limit, voice formats ---------- */
  const baseUpload = uploadFile;
  uploadFile = async function (e) {
    if (state.demo) return baseUpload(e);
    const file = e.target.files[0];
    if (!file) return;
    if (!file.size || file.size > 25 * 1024 * 1024) return toast('فایل باید حداکثر ۲۵ مگابایت باشد.');
    toast('در حال بارگذاری…');
    try {
      const content = await new Promise((res, rej) => { const r = new FileReader(); r.onload = () => res(r.result.split(',')[1]); r.onerror = rej; r.readAsDataURL(file); });
      await api('/files', { method: 'POST', body: JSON.stringify({ name: file.name, type: file.type, content }) });
      state.data = await api('/data');
      render();
      toast('فایل به کتابخانه اضافه شد.');
    } catch (ex) { toast(ex.message); }
  };

  /* ---------- Scenario drawer: follow gate, file, public reply ---------- */
  const baseDrawer = ruleDrawer;
  ruleDrawer = function (rule) {
    baseDrawer(rule);
    const form = $('#rule-form');
    if (!form) return;
    $('#rule-response').maxLength = 1000;
    $$('.field-note', form).at(-1)?.remove();
    $('.drawer-notice', form)?.remove();
    const files = state.data.files || [];
    const extra = document.createElement('div');
    extra.className = 'live-rule-extra';
    extra.innerHTML = `<label for="rule-file">فایلی که بعد از پاسخ فرستاده شود</label><select id="rule-file" name="fileId"><option value="">بدون فایل</option>${files.map(f => `<option value="${esc(f.id)}" ${rule?.fileId === f.id ? 'selected' : ''}>${esc(f.name)}</option>`).join('')}</select>${files.length ? '' : '<p class="field-note">ابتدا از «کتابخانه‌ی فایل‌ها» فایل آموزشی، عکس، ویدیو یا ویس اضافه کن.</p>'}
      <label class="check-label gate-toggle"><input type="checkbox" name="followGate" ${rule?.followGate ? 'checked' : ''}><span>${icon('gate')}<b>قفل فالو</b><small>پاسخ و فایل فقط بعد از اینکه مخاطب پیج را فالو کرد فرستاده می‌شود.</small></span></label>
      <div class="gate-fields"><label for="rule-gate">پیام درخواست فالو</label><textarea id="rule-gate" name="gateMessage" maxlength="600" placeholder="سلام! 👋 برای دریافت، اول پیج ما را فالو کن و بعد دکمه‌ی «فالو کردم» را بزن.">${esc(rule?.gateMessage || '')}</textarea></div>
      <div class="comment-fields"><label for="rule-creply">پاسخ عمومی زیر کامنت (اختیاری)</label><input id="rule-creply" name="commentReply" maxlength="300" placeholder="دایرکتت رو چک کن 💌" value="${esc(rule?.commentReply || '')}"></div>`;
    $('#rule-error', form).before(extra);
    const sync = () => {
      extra.querySelector('.gate-fields').hidden = !form.followGate.checked;
      extra.querySelector('.comment-fields').hidden = form.trigger.value !== 'comment';
    };
    form.followGate.onchange = sync; form.trigger.onchange = sync; sync();
    if (rule?.id && !state.demo) {
      const del = document.createElement('button');
      del.type = 'button'; del.className = 'btn ghost danger'; del.innerHTML = icon('trash') + 'حذف سناریو';
      del.onclick = async () => {
        if (!confirm('این سناریو حذف شود؟')) return;
        try { await api('/rules/' + rule.id, { method: 'DELETE' }); state.data = await api('/data'); closeDialog(); render(); toast('سناریو حذف شد.'); } catch (ex) { toast(ex.message); }
      };
      $('.drawer-actions', form).appendChild(del);
    }
  };
  const baseSave = saveRule;
  saveRule = rule => baseSave({ ...rule, followGate: rule.followGate === 'on' || rule.followGate === true, fileId: rule.fileId || null });

  /* ---------- Checkout: online Rial payment (ZarinPal) ---------- */
  const baseCheckout = checkout;
  checkout = function (planId, topup = false) {
    baseCheckout(planId, topup);
    const b = state.data.business || {};
    const opts = $('.payment-options');
    if (!opts || state.demo || !(b.zarinpalEnabled && b.zarinpalReady)) return;
    const label = document.createElement('label');
    label.innerHTML = `<input type="radio" name="method" value="zarinpal" checked><span>${icon('zarin')}<strong>پرداخت آنلاین</strong><small>کارت بانکی · فعال‌سازی فوری</small></span>`;
    opts.prepend(label);
  };

  /* ---------- Sign-up with e-mail verification ---------- */
  registerForm = function () {
    dialog(`${dialogHead('فضای کارت را بساز.', 'بعد از تأیید ایمیل، حسابت آماده است.')}<form id="register-form"><label for="reg-name">نام شما</label><input id="reg-name" name="name" autocomplete="name" required maxlength="60"><label for="reg-email">ایمیل</label><input id="reg-email" name="email" type="email" autocomplete="username" dir="ltr" required><label for="reg-password">رمز عبور</label><input id="reg-password" name="password" type="password" autocomplete="new-password" minlength="12" maxlength="128" required><p class="hint">یک کد ۶ رقمی به ایمیلت می‌فرستیم. رمز حداقل ۱۲ کاراکتر باشد.</p><button class="btn primary full" type="submit">ارسال کد تأیید${icon('arrow')}</button><div id="register-error"></div></form>`);
    $('#register-form').onsubmit = e => {
      e.preventDefault();
      withForm(e.target, async () => {
        const body = Object.fromEntries(new FormData(e.target));
        const r = await api('/auth/register', { method: 'POST', body: JSON.stringify(body) });
        verifyStep(r.email || body.email);
      }, '#register-error');
    };
  };
  function verifyStep(email) {
    dialog(`${dialogHead('ایمیلت را چک کن', `کد ۶ رقمی به <b dir="ltr">${esc(email)}</b> فرستاده شد.`)}<form id="register-verify"><label for="reg-code">کد تأیید</label><input id="reg-code" class="ltr otp-input" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="— — — — — —" required><p class="hint">کد تا ۱۰ دقیقه معتبر است. پوشه‌ی Spam را هم نگاه کن.</p><button class="btn primary full" type="submit">تأیید و ساخت حساب${icon('check')}</button><button class="btn ghost full" type="button" id="reg-back">ایمیل را اشتباه نوشتم</button><div id="verify-error"></div></form>`);
    $('#reg-back').onclick = registerForm;
    $('#register-verify').onsubmit = e => {
      e.preventDefault();
      withForm(e.target, async () => {
        await api('/auth/register/verify', { method: 'POST', body: JSON.stringify({ email, code: new FormData(e.target).get('code') }) });
        state.demo = false; state.page = 'dashboard';
        closeDialog();
        state.data = await api('/data');
        render();
        toast('خوش آمدی! حسابت ساخته شد.');
      }, '#verify-error');
    };
  }

  /* ---------- Admin: online payment + TRX rate on the sales page ---------- */
  const baseBusiness = businessPage;
  businessPage = function (a) {
    const html = baseBusiness(a);
    if (state.demo) return html;
    const b = a.business;
    return html.replace('<div id="business-error"', `<div class="card settings-card live-biz"><h3>${icon('shield')}سلامت سرور</h3><p class="hint">بررسی می‌کند سرور به اینستاگرام، زرین‌پال، شبکه‌ی TRON، تلگرام و سرور ایمیل دسترسی دارد یا نه.</p><button type="button" class="btn" data-live="diagnostics">${icon('refresh')}بررسی اتصال‌های سرور</button></div><div class="card settings-card live-biz"><h3>${icon('zarin')}پرداخت آنلاین ریالی</h3><label class="check-label"><input type="checkbox" id="biz-zarinpal" ${b.zarinpalEnabled ? 'checked' : ''} ${b.zarinpalReady ? '' : 'disabled'}> فعال‌سازی درگاه زرین‌پال ${b.zarinpalReady ? '' : '<small>(ابتدا merchant_id را در فایل تنظیمات سرور وارد کن)</small>'}</label><label for="biz-trx-rate">نرخ هر TRX (تومان) — خالی بگذار تا از بازار خوانده شود</label><input id="biz-trx-rate" type="number" min="0" dir="ltr" value="${b.trxPriceIRR ? Math.round(b.trxPriceIRR / 10) : ''}"></div><div id="business-error"`);
  };

  /* ---------- Delegated actions ---------- */
  document.addEventListener('click', async e => {
    const chat = e.target.closest('[data-live-chat]');
    if (chat) { activeChat = Number(chat.dataset.liveChat); render(); return; }
    const del = e.target.closest('[data-live-delfile]');
    if (del) {
      if (!confirm('این فایل حذف شود؟ سناریوهایی که از آن استفاده می‌کنند بدون فایل ادامه می‌دهند.')) return;
      try { await api('/files/' + del.dataset.liveDelfile, { method: 'DELETE' }); state.data = await api('/data'); render(); toast('فایل حذف شد.'); } catch (ex) { toast(ex.message); }
      return;
    }
    if (e.target.closest('[data-live="diagnostics"]')) {
      toast('در حال بررسی…');
      try {
        const d = await api('/admin/diagnostics');
        const row = (t, r, hint) => `<div class="settings-row"><span>${t}</span>${r.ok ? '<span class="chip green">در دسترس</span>' : `<span class="chip orange">${esc(hint)}</span>`}</div>`;
        dialog(`${dialogHead('سلامت سرور', 'PHP ' + esc(d.php))}<div class="diag">${d.configured.relay ? row('پل Cloudflare', d.relay, 'پل در دسترس نیست') : ''}${row('اینستاگرام (Graph API)' + (d.configured.relay ? ' از طریق پل' : ''), d.instagram, d.configured.relay ? 'از طریق پل هم بسته است' : 'بسته است — پل Cloudflare لازم است')}${row('تلگرام' + (d.configured.relay ? ' از طریق پل' : ''), d.telegram, 'در دسترس نیست')}${row('زرین‌پال', d.zarinpal, 'در دسترس نیست')}${row('شبکه‌ی TRON', d.trongrid, 'در دسترس نیست')}${row('سرور ایمیل (SMTP)', d.smtp, d.configured.mail ? 'اتصال برقرار نشد' : 'تنظیم نشده')}${row('پوشه‌ی فایل‌ها', { ok: d.storageWritable }, 'قابل نوشتن نیست')}${Object.entries(d.extensions).map(([k, v]) => row('افزونه‌ی ' + k, { ok: v }, 'نصب نیست')).join('')}</div>`, true);
      } catch (ex) { toast(ex.message); }
      return;
    }
    if (e.target.closest('[data-live="ig-disconnect"]')) {
      if (!confirm('اتصال اینستاگرام قطع شود؟ دایرکت هوشمند تا اتصال دوباره متوقف می‌شود.')) return;
      try { await api('/instagram/disconnect', { method: 'POST' }); state.data = await api('/data'); render(); toast('اتصال قطع شد.'); } catch (ex) { toast(ex.message); }
    }
  });
  document.addEventListener('submit', async e => {
    if (e.target.id !== 'live-reply') return;
    e.preventDefault();
    const input = e.target.text, text = input.value.trim();
    if (!text) return;
    withForm(e.target, async () => {
      await api(`/contacts/${e.target.dataset.contact}/reply`, { method: 'POST', body: JSON.stringify({ text }) });
      state.data = await api('/data');
      render();
      toast('پیام ارسال شد.');
    });
  });

  /* ---------- Messages after returning from the bank or Instagram ---------- */
  const q = new URLSearchParams(location.search);
  const notes = {
    payment: { ok: 'پرداخت موفق بود و اشتراکت فعال شد. 🎉', cancel: 'پرداخت لغو شد. هر وقت خواستی دوباره امتحان کن.', failed: 'بانک پرداخت را تأیید نکرد. اگر مبلغی کم شده، خودکار برمی‌گردد.', invalid: 'اطلاعات بازگشت از بانک معتبر نبود.' },
    instagram: { connected: 'پیج اینستاگرامت وصل شد. حالا اولین سناریو را بساز. ✨', denied: 'اجازه‌ی دسترسی داده نشد.', failed: 'اتصال اینستاگرام کامل نشد. دوباره امتحان کن.', taken: 'این پیج قبلاً به حساب دیگری وصل شده است.', state: 'نشست اتصال منقضی شد. دوباره امتحان کن.', 'not-configured': 'برنامه‌ی Meta هنوز روی سرور تنظیم نشده است.' },
  };
  for (const k of Object.keys(notes)) {
    const v = q.get(k);
    if (v && notes[k][v]) {
      setTimeout(() => toast(notes[k][v]), 900);
      history.replaceState({}, '', location.pathname);
    }
  }
})();
