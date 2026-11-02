/* 创高题库 · 工具型前端
   吸顶工具栏、高密度结果、键盘优先。 */
(() => {
  'use strict';

  const $  = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
  const API = '/api';
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
      const r = await fetch(`${API}/refresh`, {
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
  const POST_ACTIONS = new Set(['sms_send', 'sms_login', 'subject_set']);

  async function api(action, params = {}) {
    if (Date.now() > tokenExp - 120000) await refreshToken();
    const isPost = POST_ACTIONS.has(action);
    let url = `${API}/${encodeURIComponent(action)}`;
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
      if (qs.toString()) url += '?' + qs;
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
      const err = new Error(j.error || '请求被拒绝'); err.payload = j; throw err;
    }
    if (r.status === 400) {
      let j = {};
      try { j = await r.json(); } catch (e) {}
      const err = new Error(j.error || '请求参数有误'); err.payload = j; throw err;
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
      paintSubjectPill();
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
  /** 销毁旧实例并重建。
   *  阿里云验证码在同一元素上重复 init 不会重新挑战, 必须 destroy 后重建,
   *  否则第二次点「获取验证码」会直接复用上一次已通过的验证。 */
  function resetCaptcha2(delay) {
    try {
      if (window.__capInstance && typeof window.__capInstance.destroy === 'function') {
        window.__capInstance.destroy();
      }
    } catch (e) { /* 忽略 */ }
    window.__capInstance = null;
    window.__capReady = false;
    const host = document.getElementById('captcha-element');
    if (host) host.innerHTML = '';
    setTimeout(initCaptcha2, typeof delay === 'number' ? delay : 300);
  }

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
          if (!/^1[3-9]\d{9}$/.test(phone)) { toast('请输入正确的手机号'); resetCaptcha2(); return; }
          api('sms_send', { phone, captcha: captchaVerifyParam }).then(() => {
            toast('验证码已发送');
            const btn = $('#smsBtn');
            let n = 60; btn.disabled = true; btn.textContent = n + 's';
            const t = setInterval(() => {
              n--; btn.textContent = n + 's';
              if (n <= 0) {
                clearInterval(t); btn.disabled = false; btn.textContent = '获取验证码';
                resetCaptcha2(0);      // 倒计时结束, 换一套新的验证
              }
            }, 1000);
            $('#loginCode').focus();
            resetCaptcha2(1200);       // 本次验证已用完, 立即换新的
          }).catch((e) => { toast(e.message); resetCaptcha2(1200); });
        },
        fail: function () {
          toast('人机验证未通过，请稍后重试');
          resetCaptcha2(1200);
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
    syncScrollLock();                           // 有弹窗打开时锁背景滚动
    if (!window.__CG_TCAPTCHA) {
      resetCaptcha2(0);      // 阿里云验证码: 每次打开都换一套新的验证
    }
    // 移动端延迟聚焦, 等弹窗布局稳定, 避免键盘把弹窗顶飞
    setTimeout(() => { try { $('#loginPhone').focus({ preventScroll: true }); } catch (e) {} }, 60);
  }

  function closeLogin() {
    $('#loginModal').hidden = true;
    syncScrollLock();
  }

  /** 弹窗全部关闭时必须解锁, 否则整页滚不动 */
  function syncScrollLock() {
    const open = [...document.querySelectorAll('.modal')].some((m) => !m.hidden);
    document.body.classList.toggle('is-locked', open);
  }
  window.__syncScrollLock = syncScrollLock;

  /** 首屏空闲态: 不发起检索, 避免无谓消耗额度 */
  async function showIdle() {
    const list = $('#list');
    const hint = currentUser
      ? '输入关键词开始检索 · 每次检索消耗 1 次机会'
      : '登录后即可检索题库';
    const idleWords = currentUser ? await getSuggestWords() : [];
    list.innerHTML = `
      <li class="empty">
        <span class="big">题</span>
        <p class="empty-txt">${hint}</p>
        <div class="empty-tags">
          ${currentUser
            ? idleWords.map(k =>
                `<button class="go" data-suggest="${esc(k)}">${esc(k)}</button>`).join('')
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
        <p class="empty-txt">你的 ${info.limit || 110} 次搜题机会已用完</p>
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

    /** 腾讯云验证码: 点击后弹窗, 通过后带 ticket/randstr 发送短信 */
  function tcaptchaSend() {
    const phone = ($('#loginPhone').value || '').replace(/\D/g, '');
    if (!/^1[3-9]\d{9}$/.test(phone)) { toast('请输入正确的手机号'); return; }
    if (!window.TencentCaptcha) { toast('验证组件加载失败，请刷新重试'); return; }
    const btn = $('#smsBtn');
    if (btn.disabled) return;

    let cap;
    try {
      cap = new window.TencentCaptcha(window.__CG_TCAPTCHA.appId, (res) => {
      // res.ret: 0=成功 2=用户主动关闭
      if (!res || res.ret !== 0 || !res.ticket) return;
      // 容灾票据(trerror_ 前缀): JS 加载异常时后端会放行
      btn.disabled = true;
      api('sms_send', { phone, ticket: res.ticket, randstr: res.randstr })
        .then(() => {
          toast('验证码已发送');
          let n = 60; btn.textContent = n + 's';
          const t = setInterval(() => {
            n--; btn.textContent = n + 's';
            if (n <= 0) { clearInterval(t); btn.disabled = false; btn.textContent = '获取验证码'; }
          }, 1000);
          $('#loginCode').focus();
        })
        .catch((e) => {
          toast(e.message);
          btn.disabled = false;
          btn.textContent = '获取验证码';
        });
      }, { type: 'popup' });
      cap.show();
    } catch (e) {
      // SDK 加载失败: 走容灾票据, 不阻塞用户
      const ts = Math.floor(Date.now() / 1000);
      const ticket = 'trerror_1001_' + window.__CG_TCAPTCHA.appId + '_' + ts;
      btn.disabled = true;
      api('sms_send', { phone, ticket, randstr: '@' + Math.random().toString(36).slice(2) })
        .then(() => toast('验证码已发送'))
        .catch((err) => { toast(err.message); btn.disabled = false; });
    }
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
      if (window.__CG_TCAPTCHA) { tcaptchaSend(); return; }
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
        await buildSubjectSelect();
        if (!currentUser.subject_name) {
          setTimeout(() => openSubjectPicker({ reason: 'need' }), 400);
          return;
        }
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
        <div class="q-ans">
          <span class="lab">答案</span>
          <span class="v${isJudge(item.type) ? ' is-judge' : ''}">${esc(item.answer)}</span>
        </div>
        <div class="q-body">
          <div class="q-meta"><span class="q-index">${pad(i + 1)}</span><span class="tag">${esc(item.type)}</span>${subj}${legacy}</div>
          <p class="q-stem">${highlight(item.stem, kw)}</p>
          ${opts}
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
      const words = await getSuggestWords();
      list.innerHTML = `
        <li class="empty">
          <span class="big">搜</span>
          <p class="empty-txt">请输入至少 2 个字的关键词</p>
          <p class="empty-sub">题库不支持随机浏览，请按题干或答案关键词检索</p>
          <div class="empty-tags">
            ${words.map(k => `<button class="go" data-suggest="${esc(k)}">${esc(k)}</button>`).join('')}
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
      state.lastSearch = true;
      state.total = j.total;
      state.pages = j.pages;
      if (typeof j.left === 'number' && currentUser) {
        currentUser.left = j.left;
        currentUser.used = Math.max(0, (currentUser.limit || 110) - j.left);
        paintAuth();
      }

      // 跨科目提示: 可见范围内没有, 但别的科目里有
      if (j.hint && j.hint.subjects && j.hint.subjects.length) {
        const h = j.hint;
        const names = h.subjects.map((x) => esc(x.name)).join('、');
        list.insertAdjacentHTML('beforebegin', '');
        meta.innerHTML =
          `可见范围内没有结果 · 在 ${names} 中有匹配 ` +
          (h.can_switch
            ? `<button class="go mini" id="goSwitch">切换科目</button>`
            : `<span class="meta-warn">（更换次数已用完）</span>`);
        const sw = document.getElementById('goSwitch');
        if (sw) sw.addEventListener('click', () => openSubjectPicker({ subjects: h.subjects, hint: h }));
      }

      if (!j.items.length) {
        const suggestions = await getSuggestWords();
        const hint = (!state.q && !state.subject && !state.type)
          ? '输入关键词开始检索，或选择科目与题型'
          : '没有匹配的题目，换个关键词试试';
        list.innerHTML = `
          <li class="empty">
            <span class="big">空</span>
            <p class="empty-txt">${hint}</p>
            ${state.q ? `<div class="empty-tags">${suggestions.map((k) =>
              `<button class="go" data-suggest="${esc(k)}">${esc(k)}</button>`).join('')}</div>` : ''}
          </li>`;
        $$('#list [data-suggest]').forEach((b) => b.addEventListener('click', () => {
          $('#q').value = b.dataset.suggest;
          state.q = b.dataset.suggest;
          doSearch(0, true);
        }));
        if (!(j.hint && j.hint.subjects && j.hint.subjects.length)) meta.textContent = '';
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
      if (e.payload && e.payload.reason === 'need_subject') {
        list.innerHTML = '';
        meta.textContent = '';
        openSubjectPicker({ subjects: e.payload.subjects, public_id: e.payload.public_id, reason: 'need' });
        return;
      }
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


  /* ── 检索推荐词(随科目变化, 读取离线索引) ── */
  let suggestIdx = null;          // {subjectId: [words...]}
  let suggestLoading = null;

  async function loadSuggestIndex() {
    if (suggestIdx) return suggestIdx;
    if (suggestLoading) return suggestLoading;
    suggestLoading = (async () => {
      try {
        const r = await fetch('/assets/suggest.json', { credentials: 'same-origin' });
        if (r.ok) suggestIdx = await r.json();
      } catch (e) { /* 忽略 */ }
      return suggestIdx || {};
    })();
    return suggestLoading;
  }

  async function getSuggestWords() {
    const idx = await loadSuggestIndex();
    const sid = currentUser && currentUser.subject_id;
    const pid = 1;   // 公共题库
    const words = (sid && idx[String(sid)]) || idx[String(pid)] || [];
    // 去掉与科目名重复的首项由后端保证; 兜底给通用词
    return words.length ? words.slice(0, 6) : ['排球', '篮球', '太极拳', '奥林匹克', '健美操', '游泳'];
  }

  /* ── 科目范围 ── */
  let subjectListCache = null;

  function paintSubjectPill() {
    const el = document.getElementById('subjectPill');
    if (!el) return;
    const name = currentUser && currentUser.subject_name;
    if (!name) { el.hidden = true; return; }
    el.hidden = false;
    el.textContent = name;
    el.title = '检索范围：公共题库 + ' + name + '（点击更换）';
    const left = currentUser.changes_left;
    el.textContent = name + (left > 0 ? '  ⇄' : '');
  }

  async function fetchSubjects() {
    if (subjectListCache) return subjectListCache;
    let j = cache.get('subjects', 12 * 3600 * 1000);
    if (!j) { j = await api('subjects'); cache.set('subjects', j); }
    subjectListCache = j.subjects || [];
    return subjectListCache;
  }

  /** 打开科目选择: opts = {subjects?:[], reason?:'need'|'change', hint?:{...}} */
  async function openSubjectPicker(opts = {}) {
    const modal = document.getElementById('subjectModal');
    const listEl = document.getElementById('subjectList');
    const hintEl = document.getElementById('subjectHint');
    console.log('DBG picker called, modal=', !!modal, 'list=', !!listEl);
    if (!modal || !listEl) return;

    let subjects = opts.subjects;
    if (!subjects) {
      try { subjects = await fetchSubjects(); } catch (e) { console.log('DBG fetchSubjects error:', e && e.message); toast(e.message); return; }
    }
    const publicId = Number(opts.public_id) || (subjectListCache && subjectListCache.publicId) || 1;
    const isPublic = (s) => Number(s.id) === publicId || s.name === '公共理论考试题库';
    const mine = currentUser && currentUser.subject_id;
    const left = currentUser ? currentUser.changes_left : null;

    listEl.innerHTML = subjects
      .filter((s) => !isPublic(s))
      .map((s) => `<button class="subject-item${mine === s.id ? ' is-mine' : ''}" data-sid="${s.id}" data-sname="${esc(s.name)}">
          <span class="sname">${esc(s.name)}</span>
        </button>`).join('');

    if (opts.hint) {
      const h = opts.hint;
      hintEl.textContent = h.can_switch
        ? `在 ${h.subjects.map(x => x.name).join('、')} 中有匹配，可切换（剩余 ${h.changes_left} 次）`
        : '更换科目次数已用完，如需修改请联系管理员';
    } else if (left !== null && left !== undefined && mine) {
      hintEl.textContent = `已更换 ${(currentUser.max_changes || 2) - left} 次，剩余 ${left} 次`;
    } else {
      hintEl.textContent = '首次选择免费，之后可更换 2 次';
    }

    listEl.querySelectorAll('.subject-item').forEach((btn) => {
      btn.addEventListener('click', async () => {
        btn.disabled = true;
        try {
          const j = await api('subject_set', { subject: btn.dataset.sname });
          if (currentUser) {
            currentUser.subject_id = j.subject.id;
            currentUser.subject_name = j.subject.name;
            currentUser.changes_left = j.changes_left;
          }
          modal.hidden = true;
          if (window.__syncScrollLock) window.__syncScrollLock();
          paintSubjectPill();
          toast('已切换到「' + j.subject.name + '」');
          buildSubjectSelect();
          if (state.lastSearch) doSearch(0, true);
          else showIdle();   // 未检索时刷新推荐词
        } catch (e) {
          toast(e.message);
          btn.disabled = false;
        }
      });
    });

    modal.hidden = false;
    if (window.__syncScrollLock) window.__syncScrollLock();
  }

  /** 下拉框只保留公共题库 + 本人科目 */
  async function buildSubjectSelect() {
    const sel = document.getElementById('subject');
    if (!sel) return;
    const subjects = await fetchSubjects();
    const mine = currentUser && currentUser.subject_name;
    const publicName = '公共理论考试题库';
    const keep = new Set(['', publicName]);
    if (mine) keep.add(mine);
    const prev = sel.value;
    sel.innerHTML = '<option value="">全部可见</option>';
    subjects.forEach((s) => {
      if (!keep.has(s.name)) return;
      const o = document.createElement('option');
      o.value = s.name;
      o.textContent = s.name;
      sel.appendChild(o);
    });
    if ([...sel.options].some((o) => o.value === prev)) sel.value = prev;
    else sel.value = '';
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
        o.textContent = s.name;
        sel.appendChild(o);
      });
    } catch (e) { toast(e.message); }
  }

  /* ── Tab ── */
  const TABS = ['search'];
  function switchTab(name, push = true) {
    if (!TABS.includes(name)) name = 'search';
    $$('.nav-link').forEach((t) => t.classList.toggle('is-active', t.dataset.tab === name));
    $$('.view').forEach((v) => v.classList.toggle('is-active', v.id === 'view-' + name));
    if (push && location.hash !== '#' + name) history.replaceState(null, '', '#' + name);
  }

  /* ── 绑定 ── */
  function bindSubjectSwitch() {
    const sp = document.getElementById('subjectPill');
    if (!sp) return;
    sp.addEventListener('click', () => {
      if (!currentUser) { openLogin(); return; }
      openSubjectPicker({ reason: 'change' });
    });
  }

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
  bindSubjectSwitch();
  initCaptcha2();          // 尽早初始化验证码(文档要求与验证间隔 > 2s)

  // 启动顺序: 科目下拉 → 用户信息 → 未选科目则弹选择 → 首屏空态
  (async () => {
    try { await loadSubjects(); } catch (e) {}
    try {
      await loadMe();
      await buildSubjectSelect();
      if (currentUser && !currentUser.subject_name) {
        await openSubjectPicker({ reason: 'need' });
      }
    } catch (e) { console.log('init subject error:', e && e.message); }
    showIdle();          // 首屏不检索, 不消耗额度
  })();


  const h = (location.hash || '').replace('#', '');
  if (TABS.includes(h) && h !== 'search') switchTab(h, false);

  setInterval(() => { if (Date.now() > tokenExp - 300000) refreshToken(); }, 120000);
})();
