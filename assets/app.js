/* 创高题库 · 工具型前端
   吸顶工具栏、高密度结果、键盘优先。 */
(() => {
  'use strict';

  const $  = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
  const API = '/api.php';
  const reduceMotion = matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* ── toast ── */
  let toastTimer;
  function toast(msg, ms = 2400) {
    const el = $('#toast');
    if (!el) return;
    el.textContent = msg;
    el.classList.add('is-on');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => el.classList.remove('is-on'), ms);
  }

  /** 需要登录 / 额度耗尽 */
  class LoginNeeded extends Error {
    constructor() { super('login'); this.needLogin = true; }
  }
  class QuotaOut extends Error {
    constructor(info) { super('quota'); this.quota = info; }
  }

  /* ── 缓存 ── */
  const cache = {
    get(k, ttl) {
      try {
        const raw = localStorage.getItem('cgtk:' + k);
        if (!raw) return null;
        const o = JSON.parse(raw);
        if (!o || Date.now() - o.t > ttl) return null;
        return o.v;
      } catch (e) { return null; }
    },
    set(k, v) {
      try { localStorage.setItem('cgtk:' + k, JSON.stringify({ t: Date.now(), v })); } catch (e) {}
    },
  };

  /* ── 令牌 ── */
  let token = ($('meta[name="csrf-token"]') || {}).content || '';
  let tokenExp = Date.now() + 3 * 3600 * 1000;

  async function refreshToken() {
    try {
      const r = await fetch(`${API}?action=refresh`, {
        headers: { 'X-CG-Token': token, Accept: 'application/json' },
        credentials: 'same-origin',
      });
      if (!r.ok) return false;
      const j = await r.json();
      if (j.ok && j.token) {
        token = j.token;
        tokenExp = Date.now() + (j.expires_in || 3600) * 1000;
        return true;
      }
    } catch (e) { /* ignore */ }
    return false;
  }

  // 含手机号/验证码等敏感参数的动作一律 POST, 避免落进访问日志
  const POST_ACTIONS = new Set(['sms_send', 'sms_login']);

  async function api(action, params = {}) {
    if (Date.now() > tokenExp - 120000) await refreshToken();
    const isPost = POST_ACTIONS.has(action);
    let url = `${API}?action=${encodeURIComponent(action)}`;
    const opts = {
      headers: { 'X-CG-Token': token, Accept: 'application/json' },
      credentials: 'same-origin',
      method: isPost ? 'POST' : 'GET',
    };
    if (isPost) {
      const fd = new FormData();
      Object.keys(params).forEach((k) => {
        if (params[k] !== undefined && params[k] !== null) fd.append(k, params[k]);
      });
      opts.body = fd;
    } else {
      const qs = new URLSearchParams(params);
      if (qs.toString()) url += '&' + qs;
    }
    const r = await fetch(url, opts);
    if (r.status === 401) {
      let j = {};
      try { j = await r.json(); } catch (e) {}
      if (j.reason === 'login_required') throw new LoginNeeded();
      if (await refreshToken()) return api(action, params);
      throw new Error(j.error || '会话已失效，请刷新页面');
    }
    if (r.status === 429) {
      let j = {};
      try { j = await r.json(); } catch (e) {}
      if (j.reason === 'daily_budget') {
        throw new Error('今天的浏览额度已用完，明天再来');
      }
      const sec = j.retry_after || 60;
      throw new Error(`请求过于频繁，请 ${sec >= 60 ? Math.ceil(sec / 60) + ' 分钟' : sec + ' 秒'}后再试`);
    }
    if (r.status === 403) {
      let j = {};
      try { j = await r.json(); } catch (e) {}
      if (j.reason === 'quota_exhausted') throw new QuotaOut(j);
      throw new Error(j.error || '请求被拒绝');
    }
    if (r.status === 400) {
      let j = {};
      try { j = await r.json(); } catch (e) {}
      throw new Error(j.error || '请求参数有误');
    }
    if (!r.ok) throw new Error('服务异常 (' + r.status + ')');
    return r.json();
  }

  /* ── 工具 ── */
  const esc = (s) => String(s == null ? '' : s)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&#39;');

  function highlight(text, kw) {
    const t = esc(text);
    if (!kw) return t;
    const k = esc(kw).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    try { return t.replace(new RegExp(k, 'gi'), (m) => `<mark>${m}</mark>`); }
    catch (e) { return t; }
  }

  const pad = (n) => String(n).padStart(2, '0');

  // 旧数据里选项文本可能自带 "A." 前缀, 显示时去掉, 避免出现 "A A.xxx"
  const OPT_PREFIX = /^\s*[A-Ha-h]\s*[.、．)）:：]\s*/;
  const optText = (t) => String(t == null ? '' : t).replace(OPT_PREFIX, '').trim() || String(t || '');
  const isJudge = (t) => t === '判断';

  function countUp(el, to, dur = 700) {
    if (!el) return;
    if (reduceMotion) { el.textContent = Number(to).toLocaleString(); return; }
    const t0 = performance.now();
    (function step(t) {
      const p = Math.min(1, (t - t0) / dur);
      el.textContent = Math.round(to * (1 - Math.pow(1 - p, 3))).toLocaleString();
      if (p < 1) requestAnimationFrame(step);
    })(t0);
  }

  /* ── 主题 ── */
  function initTheme() {
    const root = document.documentElement;
    $('#themeBtn').addEventListener('click', () => {
      const next = root.dataset.theme === 'ink' ? 'paper' : 'ink';
      root.dataset.theme = next;
      try { localStorage.setItem('cgtk-theme', next); } catch (e) {}
      const meta = document.querySelector('meta[name="theme-color"]');
      if (meta) meta.content = next === 'ink' ? '#13110D' : '#F7F5F0';
    });
    const meta = document.querySelector('meta[name="theme-color"]');
    if (meta && root.dataset.theme === 'ink') meta.content = '#13110D';
  }

  /* ── 滚动进度 ── */
  function initScrollLine() {
    const line = $('#scrollLine');
    const on = () => {
      const h = document.documentElement;
      const max = h.scrollHeight - h.clientHeight;
      line.style.width = max > 0 ? (h.scrollTop / max) * 100 + '%' : '0%';
    };
    addEventListener('scroll', on, { passive: true });
    on();
  }

  /* ── 账号 ── */
  let currentUser = null;
  let afterLogin = null;

  function paintAuth() {
    const qp = $('#quotaPill'), lb = $('#loginBtn'), up = $('#userPill'), uph = $('#userPhone');
    if (currentUser) {
      qp.hidden = false;
      qp.textContent = '剩余 ' + currentUser.left + ' 次';
      lb.hidden = true;
      up.hidden = false;
      uph.textContent = currentUser.phone;
    } else {
      qp.hidden = true;
      lb.hidden = false;
      up.hidden = true;
    }
  }

  async function loadMe() {
    try {
      const j = await api('me');
      currentUser = j.user || null;
    } catch (e) { currentUser = null; }
    paintAuth();
  }

  /** 初始化阿里云验证码 2.0 (页面加载后尽早调用, 保证与验证请求间隔 > 2s) */
  function initCaptcha2() {
    if (!window.__CG_CAPTCHA2) return;
    if (window.__capReady) return;
    if (typeof window.initAliyunCaptcha !== 'function') return;
    try {
      window.initAliyunCaptcha({
        SceneId: window.__CG_CAPTCHA2.sceneId,
        mode: 'popup',
        element: '#captcha-element',
        button: '#smsBtn',
        success: function (captchaVerifyParam) {
          const phone = ($('#loginPhone').value || '').replace(/\D/g, '');
          if (!/^1[3-9]\d{9}$/.test(phone)) { toast('请输入正确的手机号'); return; }
          api('sms_send', { phone, captcha: captchaVerifyParam }).then(() => {
            toast('验证码已发送');
            const btn = $('#smsBtn');
            let n = 60; btn.disabled = true; btn.textContent = n + 's';
            const t = setInterval(() => {
              n--; btn.textContent = n + 's';
              if (n <= 0) { clearInterval(t); btn.disabled = false; btn.textContent = '获取验证码'; }
            }, 1000);
            $('#loginCode').focus();
            // 官方要求: 一次验证周期结束后需重新初始化, 否则再次点击不会触发
            window.__capReady = false;
            setTimeout(initCaptcha2, 1200);
          }).catch((e) => toast(e.message));
        },
        fail: function () {
          toast('人机验证未通过，请稍后重试');
          window.__capReady = false;
          setTimeout(initCaptcha2, 1200);
        },
        getInstance: function (i) { window.__capInstance = i; },
        slideStyle: { width: 320, height: 40 },
      });
      window.__capReady = true;
    } catch (e) {
      window.__capReady = false;
    }
  }

  function openLogin(retry) {
    afterLogin = retry || afterLogin;
    $('#loginModal').hidden = false;
    initCaptcha2();          // 兜底: 若启动时未初始化成功, 这里再试一次
    $('#loginPhone').focus();
  }

  function closeLogin() { $('#loginModal').hidden = true; }

  /** 首屏空闲态: 不发起检索, 避免无谓消耗额度 */
  function showIdle() {
    const list = $('#list');
    const hint = currentUser
      ? '输入关键词开始检索 · 每次检索消耗 1 次机会'
      : '登录后即可检索题库';
    list.innerHTML = `
      <li class="empty">
        <span class="big">题</span>
        <p class="empty-txt">${hint}</p>
        <div class="empty-tags">
          ${currentUser
            ? ['排球','篮球','太极拳','奥林匹克','健美操','游泳'].map(k =>
                `<button class="go" data-suggest="${k}">${k}</button>`).join('')
            : `<button class="go solid" id="idleLogin" type="button">手机号登录</button>`}
        </div>
      </li>`;
    $('#meta').innerHTML = currentUser
      ? `剩余 <b>${currentUser.left}</b> 次搜题机会`
      : '';
    $('#pagePos').textContent = '';
    $('#pager').innerHTML = '';
    $$('#list [data-suggest]').forEach((b) => b.addEventListener('click', () => {
      $('#q').value = b.dataset.suggest;
      state.q = b.dataset.suggest;
      doSearch(0, true);
    }));
    const il = $('#idleLogin');
    if (il) il.addEventListener('click', () => openLogin(() => showIdle()));
  }

  function showQuotaOut(info) {
    const list = $('#list');
    list.innerHTML = `
      <li class="empty">
        <span class="big">额</span>
        <p class="empty-txt">你的 ${info.limit || 150} 次搜题机会已用完</p>
        <p class="empty-sub">如需更多额度请联系管理员</p>
      </li>`;
    $('#meta').textContent = '额度已用尽';
    $('#pagePos').textContent = '';
    $('#pager').innerHTML = '';
  }

  function bindAuth() {
    $('#loginBtn').addEventListener('click', () => openLogin(null));
    $('#logoutBtn').addEventListener('click', async () => {
      try { await api('logout'); } catch (e) {}
      currentUser = null;
      paintAuth();
      toast('已退出');
      doSearch(0);
    });
    $$('#loginModal [data-close]').forEach((el) =>
      el.addEventListener('click', closeLogin));
    $('#loginModal').addEventListener('keydown', (e) => {
      if (e.key === 'Escape') closeLogin();
    });

    let tick = null;
    let captchaReady = false;

    function startCountdown() {
      const btn = $('#smsBtn');
      let n = 60;
      btn.disabled = true;
      btn.textContent = `${n}s`;
      tick = setInterval(() => {
        n--; btn.textContent = n + 's';
        if (n <= 0) { clearInterval(tick); btn.disabled = false; btn.textContent = '获取验证码'; }
      }, 1000);
      $('#loginCode').focus();
    }

    async function sendSms(phone, captchaParam) {
      try {
        const params = { phone };
        if (captchaParam) params.captcha = captchaParam;
        await api('sms_send', params);
        toast('验证码已发送');
        startCountdown();
      } catch (e) {
        toast(e.message);
        const btn = $('#smsBtn');
        btn.disabled = false;
        btn.textContent = '获取验证码';
      }
    }

    // 「获取验证码」按钮: 未启用验证码时直接发送; 启用时由 SDK 接管点击
    $('#smsBtn').addEventListener('click', async () => {
      if (window.__CG_CAPTCHA2) return;   // SDK 绑定在 button 上, 这里不重复处理
      const phone = $('#loginPhone').value.replace(/\D/g, '');
      if (!/^1[3-9]\d{9}$/.test(phone)) { toast('请输入正确的手机号'); return; }
      const btn = $('#smsBtn');
      btn.disabled = true;
      await sendSms(phone, null);
    });

    const submit = async () => {
      const phone = $('#loginPhone').value.replace(/\D/g, '');
      const code = $('#loginCode').value.replace(/\D/g, '');
      if (!/^1[3-9]\d{9}$/.test(phone)) { toast('请输入正确的手机号'); return; }
      if (code.length < 4) { toast('请输入验证码'); return; }
      const btn = $('#loginSubmit');
      btn.disabled = true; btn.textContent = '验证中…';
      try {
        const j = await api('sms_login', { phone, code });
        currentUser = j.user;
        paintAuth();
        closeLogin();
        toast(j.registered ? '注册成功，欢迎使用' : '登录成功');
        const fn = afterLogin; afterLogin = null;
        if (fn) fn(); else showIdle();
      } catch (e) {
        toast(e.message);
      } finally {
        btn.disabled = false; btn.textContent = '登录 / 注册';
      }
    };
    $('#loginSubmit').addEventListener('click', submit);
    $('#loginCode').addEventListener('keydown', (e) => { if (e.key === 'Enter') submit(); });
    $('#loginPhone').addEventListener('keydown', (e) => { if (e.key === 'Enter') $('#smsBtn').click(); });
  }

  /* ── 检索 ── */
  const state = { q: '', subject: '', type: '', page: 0, total: 0, pages: 0 };

  function renderCard(item, kw, i) {
    const opts = (item.options && item.options.length)
      ? `<ul class="q-opts">${item.options.map((o) =>
          `<li><span class="k">${esc(o.key)}</span><span>${esc(optText(o.text))}</span></li>`).join('')}</ul>`
      : '';
    const legacy = item.source === 'legacy' ? '<span class="legacy">旧库</span>' : '';
    const subj = item.subjects ? `<span class="sep">·</span><span>${esc(item.subjects)}</span>` : '';
    return `
      <li class="q" data-id="${item.id}">
        <div class="q-index">${pad(i + 1)}</div>
        <div>
          <div class="q-meta"><span class="tag">${esc(item.type)}</span>${subj}${legacy}</div>
          <p class="q-stem">${highlight(item.stem, kw)}</p>
          ${opts}
        </div>
        <div class="q-ans">
          <span class="lab">答案</span><span class="v${isJudge(item.type) ? ' is-judge' : ''}">${esc(item.answer)}</span>
        </div>
      </li>`;
  }

  function scrollToList() {
    // 滚到列表头(含计数行), 让计数与首条同时可见
    const head = document.querySelector('#view-search .list-head');
    if (!head) return;
    const y = head.getBoundingClientRect().top + scrollY - 110;
    scrollTo({ top: Math.max(0, y), behavior: reduceMotion ? 'auto' : 'smooth' });
  }

  async function doSearch(page = 0, jump = false) {
    // 关键词是硬门槛: 空关键词直接本地拦截, 不请求、不扣额度
    const kw = (state.q || '').trim();
    if (kw.length < 2) {
      const list = $('#list');
      list.innerHTML = `
        <li class="empty">
          <span class="big">搜</span>
          <p class="empty-txt">请输入至少 2 个字的关键词</p>
          <p class="empty-sub">题库不支持随机浏览，请按题干或答案关键词检索</p>
          <div class="empty-tags">
            ${['排球','篮球','太极拳','奥林匹克','健美操','游泳'].map(k =>
              `<button class="go" data-suggest="${k}">${k}</button>`).join('')}
          </div>
        </li>`;
      $$('#list [data-suggest]').forEach((b) => b.addEventListener('click', () => {
        $('#q').value = b.dataset.suggest;
        state.q = b.dataset.suggest;
        doSearch(0, true);
      }));
      $('#meta').textContent = '等待关键词';
      $('#pagePos').textContent = '';
      $('#pager').innerHTML = '';
      return;
    }

    const list = $('#list'), meta = $('#meta'), pos = $('#pagePos');
    state.page = page;
    list.innerHTML = '<div class="skel"></div><div class="skel"></div><div class="skel"></div>';
    meta.textContent = '检索中…';
    pos.textContent = '';

    try {
      const j = await api('search', { q: state.q, subject: state.subject, type: state.type, page });
      state.total = j.total;
      state.pages = j.pages;
      if (typeof j.left === 'number' && currentUser) {
        currentUser.left = j.left;
        currentUser.used = Math.max(0, (currentUser.limit || 150) - j.left);
        paintAuth();
      }

      if (!j.items.length) {
        const suggestions = ['排球', '篮球', '太极拳', '奥林匹克', '健美操', '游泳'];
        const hint = (!state.q && !state.subject && !state.type)
          ? '输入关键词开始检索，或选择科目与题型'
          : '没有匹配的题目，换个关键词试试';
        list.innerHTML = `
          <li class="empty">
            <span class="big">空</span>
            <p class="empty-txt">${hint}</p>
            ${state.q ? `<div class="empty-tags">${suggestions.map((k) =>
              `<button class="go" data-suggest="${k}">${k}</button>`).join('')}</div>` : ''}
          </li>`;
        $$('#list [data-suggest]').forEach((b) => b.addEventListener('click', () => {
          $('#q').value = b.dataset.suggest;
          state.q = b.dataset.suggest;
          doSearch(0, true);
        }));
        meta.textContent = '';
        renderPager();
        if (jump) scrollToList();
        return;
      }

      const cond = [state.q ? `“${state.q}”` : '', state.subject, state.type].filter(Boolean).join(' ');
      meta.innerHTML = `${cond ? esc(cond) + ' — ' : ''}共 <b>${j.total.toLocaleString()}</b> 条`;
      pos.textContent = `${pad(page + 1)} / ${pad(Math.max(1, j.pages))}`;
      list.innerHTML = j.items.map((it, i) => renderCard(it, state.q, i)).join('');
      renderPager();
      if (jump) scrollToList();
    } catch (e) {
      if (e instanceof LoginNeeded) { openLogin(() => doSearch(0, true)); return; }
      if (e instanceof QuotaOut) { showQuotaOut(e.quota); return; }
      list.innerHTML = '';
      meta.textContent = '';
      toast(e.message);
    }
  }

  function renderPager() {
    const p = $('#pager'), cur = state.page, all = state.pages;
    if (all <= 1) { p.innerHTML = ''; return; }
    const btn = (i, label, cls = '', dis = false) =>
      `<button class="${cls}" data-page="${i}" ${dis ? 'disabled' : ''}>${label}</button>`;
    const win = [];
    for (let i = 0; i < all; i++) {
      if (i === 0 || i === all - 1 || Math.abs(i - cur) <= 1) win.push(i);
    }
    let nums = '', last = -2;
    win.forEach((i) => {
      if (i - last > 1) nums += '<button disabled>…</button>';
      nums += btn(i, pad(i + 1), i === cur ? 'on' : '');
      last = i;
    });
    p.innerHTML = `
      <div class="nums">
        ${btn(cur - 1, '←', '', cur <= 0)}
        ${nums}
        ${btn(cur + 1, '→', '', cur >= all - 1)}
      </div>
      <span class="pos">第 ${pad(cur + 1)} / ${all} 页</span>`;
    $$('#pager button[data-page]').forEach((b) => b.addEventListener('click', () => {
      doSearch(Number(b.dataset.page));
      scrollToList();
    }));
  }

  /* ── 科目 ── */
  async function loadSubjects() {
    try {
      let j = cache.get('subjects', 12 * 3600 * 1000);
      if (!j) {
        j = await api('subjects');
        cache.set('subjects', j);
      }
      const sel = $('#subject');
      j.subjects.forEach((s) => {
        const o = document.createElement('option');
        o.value = s.name;
        o.textContent = `${s.name} (${s.count})`;
        sel.appendChild(o);
      });
      if (j.total) {
        $('#topCount').textContent = j.total.toLocaleString() + ' 题';
      }
    } catch (e) { toast(e.message); }
  }

  /* ── 统计 ── */
  async function loadStats(force = false) {
    try {
      let j = force ? null : cache.get('stats', 10 * 60 * 1000);
      if (!j) {
        j = await api('stats');
        cache.set('stats', j);
      }
      const t = j.by_type || {};
      const cells = [
        [j.total, '题库总量'],
        [t['判断'] || 0, '判断题'],
        [(t['单选'] || 0) + (t['多选'] || 0), '选择题'],
        [Object.keys(t).length, '题型数'],
      ];
      $('#statGrid').innerHTML = cells.map(([n, l]) =>
        `<div class="stat"><div class="n" data-n="${n}">0</div><div class="l">${l}</div></div>`).join('');
      $$('#statGrid .n').forEach((el) => countUp(el, Number(el.dataset.n)));

      const max = Math.max(...j.top_subjects.map((s) => s.count), 1);
      $('#statBars').innerHTML = j.top_subjects.map((s, i) => `
        <div class="bar">
          <span class="name" title="${esc(s.name)}">${esc(s.name)}</span>
          <span class="track"><span class="fill" style="width:${(s.count / max * 100).toFixed(1)}%;animation-delay:${i * 35}ms"></span></span>
          <span class="num">${s.count}</span>
        </div>`).join('');

      $('#footTotal').textContent = j.total.toLocaleString();
      const fu = $('#footUpdated');
      if (fu && j.updated_at) fu.textContent = j.updated_at;
      $('#topCount').textContent = j.total.toLocaleString() + ' 题';
    } catch (e) { toast(e.message); }
  }

  /* ── Tab ── */
  const TABS = ['search', 'stats'];
  function switchTab(name, push = true) {
    if (!TABS.includes(name)) name = 'search';
    $$('.nav-link').forEach((t) => t.classList.toggle('is-active', t.dataset.tab === name));
    $$('.view').forEach((v) => v.classList.toggle('is-active', v.id === 'view-' + name));
    if (push && location.hash !== '#' + name) history.replaceState(null, '', '#' + name);
    if (name === 'stats') loadStats();
  }

  /* ── 绑定 ── */
  function bind() {
    $$('.nav-link').forEach((t) =>
      t.addEventListener('click', () => switchTab(t.dataset.tab)));

    $('#search-form').addEventListener('submit', (e) => {
      e.preventDefault();
      const q = $('#q').value.trim();
      if (q.length > 40) { toast('关键词最多 40 字'); return; }
      state.q = q;
      switchTab('search');
      doSearch(0, true);
    });

    $('#q').addEventListener('input', () => { state.q = $('#q').value.trim(); });
    $('#q').addEventListener('keydown', (e) => {
      if (e.key === 'Escape') {
        $('#q').value = ''; state.q = '';
        doSearch(0, true);
      }
    });

    $('#subject').addEventListener('change', () => {
      state.subject = $('#subject').value;
      switchTab('search');
      doSearch(0, true);
    });
    $('#type').addEventListener('change', () => {
      state.type = $('#type').value;
      switchTab('search');
      doSearch(0, true);
    });
    $('#reset').addEventListener('click', () => {
      $('#q').value = ''; $('#subject').value = ''; $('#type').value = '';
      state.q = state.subject = state.type = '';
      switchTab('search');
      doSearch(0, true);
    });

    addEventListener('keydown', (e) => {
      const tag = (document.activeElement || {}).tagName;
      if (e.key === '/' && tag !== 'INPUT' && tag !== 'SELECT' && tag !== 'TEXTAREA') {
        e.preventDefault();
        switchTab('search');
        $('#q').focus();
      }
    });

    addEventListener('hashchange', () =>
      switchTab((location.hash || '').replace('#', ''), false));
  }

  /* ── 启动 ── */
  initTheme();
  initScrollLine();
  bindAuth();
  bind();
  initCaptcha2();          // 尽早初始化验证码(文档要求与验证间隔 > 2s)

  loadMe().then(() => {
    loadSubjects();
    showIdle();          // 首屏不检索, 不消耗额度
  });
  (async () => {
    const j = cache.get('stats', 10 * 60 * 1000);
    if (j) {
      $('#topCount').textContent = Number(j.total).toLocaleString() + ' 题';
      $('#footTotal').textContent = Number(j.total).toLocaleString();
      const fu = $('#footUpdated');
      if (fu && j.updated_at) fu.textContent = j.updated_at;
    } else {
      await loadStats();
    }
  })();

  const h = (location.hash || '').replace('#', '');
  if (TABS.includes(h) && h !== 'search') switchTab(h, false);

  setInterval(() => { if (Date.now() > tokenExp - 300000) refreshToken(); }, 120000);
})();
