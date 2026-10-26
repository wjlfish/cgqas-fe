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

  /** 需要人机验证时抛出 */
  class CaptchaNeeded extends Error {
    constructor(id) { super('captcha'); this.captchaId = id; }
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

  async function api(action, params = {}) {
    if (Date.now() > tokenExp - 120000) await refreshToken();
    const qs = new URLSearchParams({ action, ...params });
    const r = await fetch(`${API}?${qs}`, {
      headers: { 'X-CG-Token': token, Accept: 'application/json' },
      credentials: 'same-origin',
    });
    if (r.status === 401) {
      if (await refreshToken()) return api(action, params);
      throw new Error('会话已失效，请刷新页面');
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
      if (j.reason === 'captcha_required' && j.captcha) throw new CaptchaNeeded(j.captcha);
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

  let pendingRetry = null;

  function renderCaptcha(id, retry) {
    pendingRetry = retry || (() => doSearch(0, true));
    const list = $('#list');
    const qs = new URLSearchParams({ action: 'captcha', id });
    list.innerHTML = `
      <li class="empty captcha-wrap">
        <span class="big">验</span>
        <p class="empty-txt">检测到访问频率异常，请完成人机验证后继续</p>
        <div class="captcha-box">
          <img src="/api.php?${qs}" alt="验证码" width="132" height="46" id="capImg">
          <input id="capAnswer" type="text" maxlength="6" placeholder="输入图中字符"
                 autocomplete="off" spellcheck="false" aria-label="验证码">
          <button class="go" type="button" id="capSubmit">确认</button>
        </div>
        <p class="captcha-hint">通过后 10 分钟内不再验证 ·
          <button class="link-btn" type="button" id="capRefresh">换一张</button></p>
      </li>`;
    $('#meta').textContent = '需要人机验证';
    $('#pagePos').textContent = '';
    $('#pager').innerHTML = '';
    const inp = $('#capAnswer');
    inp.focus();

    const submit = async () => {
      const ans = inp.value.trim();
      if (!ans) { inp.focus(); return; }
      const btn = $('#capSubmit');
      btn.disabled = true; btn.textContent = '校验中';
      try {
        await api('captcha_verify', { id, answer: ans });
        toast('验证通过');
        const fn = pendingRetry; pendingRetry = null;
        if (fn) fn();
      } catch (e) {
        toast(e.message || '验证失败');
        btn.disabled = false; btn.textContent = '确认';
        inp.value = ''; inp.focus();
        if (e.message && e.message.includes('失效')) doSearch(0, true);
      }
    };
    $('#capSubmit').addEventListener('click', submit);
    inp.addEventListener('keydown', (e) => { if (e.key === 'Enter') submit(); });
    $('#capRefresh').addEventListener('click', () => {
      $('#capImg').src = `/api.php?action=captcha&id=${encodeURIComponent(id)}&t=${Date.now()}`;
      inp.value = ''; inp.focus();
    });
  }

  function scrollToList() {
    // 滚到列表头(含计数行), 让计数与首条同时可见
    const head = document.querySelector('#view-search .list-head');
    if (!head) return;
    const y = head.getBoundingClientRect().top + scrollY - 110;
    scrollTo({ top: Math.max(0, y), behavior: reduceMotion ? 'auto' : 'smooth' });
  }

  async function doSearch(page = 0, jump = false) {
    const list = $('#list'), meta = $('#meta'), pos = $('#pagePos');
    state.page = page;
    list.innerHTML = '<div class="skel"></div><div class="skel"></div><div class="skel"></div>';
    meta.textContent = '检索中…';
    pos.textContent = '';

    try {
      const j = await api('search', { q: state.q, subject: state.subject, type: state.type, page });
      state.total = j.total;
      state.pages = j.pages;

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
      if (j.mode === 'random') {
        meta.innerHTML = `随机浏览 <b>${j.items.length}</b> 题 · 命中 <b>${j.total.toLocaleString()}</b> 条`;
        pos.innerHTML = '每批随机抽取 · 输入关键字可精确检索并翻页';
        list.innerHTML = j.items.map((it, i) => renderCard(it, '', i)).join('');
        renderRefresh();
        if (jump) scrollToList();
        return;
      }
      meta.innerHTML = `${cond ? esc(cond) + ' — ' : ''}共 <b>${j.total.toLocaleString()}</b> 条`;
      pos.textContent = `${pad(page + 1)} / ${pad(Math.max(1, j.pages))}`;
      list.innerHTML = j.items.map((it, i) => renderCard(it, state.q, i)).join('');
      renderPager();
      if (jump) scrollToList();
    } catch (e) {
      if (e instanceof CaptchaNeeded) { renderCaptcha(e.captchaId, () => doSearch(0, true)); return; }
      list.innerHTML = '';
      meta.textContent = '';
      toast(e.message);
    }
  }

  function renderRefresh() {
    const p = $('#pager');
    p.innerHTML = `<div class="nums"><button data-refresh="1">换一批 ↻</button></div>`;
    $('[data-refresh]').addEventListener('click', () => doSearch(0));
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
      const sel = $('#subject'), qsel = $('#quizSubject');
      j.subjects.forEach((s) => {
        const o = document.createElement('option');
        o.value = s.name;
        o.textContent = `${s.name} (${s.count})`;
        sel.appendChild(o);
        const o2 = document.createElement('option');
        o2.value = s.name;
        o2.textContent = s.name;
        qsel.appendChild(o2);
      });
      if (j.total) {
        $('#topCount').textContent = j.total.toLocaleString() + ' 题';
      }
    } catch (e) { toast(e.message); }
  }

  /* ── 练习 ── */
  let quizItems = [], quizDone = 0, quizRight = 0;

  function ansSet(v) {
    const s = String(v == null ? '' : v).toUpperCase().trim();
    if (['正确', '对', '√', 'T', 'TRUE'].includes(s)) return 'T';
    if (['错误', '错', '×', 'F', 'FALSE'].includes(s)) return 'F';
    return s.replace(/[^A-Z0-9]/g, '').split('').sort().join('');
  }

  async function startQuiz() {
    const subject = $('#quizSubject').value;
    const n = Number($('#quizN').value) || 10;
    const list = $('#quizList');
    list.innerHTML = '<div class="skel"></div><div class="skel"></div>';
    $('#quizScore').textContent = '';
    $('#quizHint').textContent = '抽题中…';
    quizItems = []; quizDone = 0; quizRight = 0;

    try {
      const j = await api('quiz', { subject, n });
      if (!j.items.length) {
        list.innerHTML = '<li class="empty"><span class="big">空</span><p class="empty-txt">该科目暂无可用题目</p></li>';
        $('#quizHint').textContent = '';
        return;
      }
      quizItems = j.items;
      $('#quizHint').textContent = `${subject || '全部科目'} · ${j.items.length} 题`;
      list.innerHTML = j.items.map((it, i) => renderQuiz(it, i)).join('');
      bindQuiz();
      updateScore();
    } catch (e) {
      if (e instanceof CaptchaNeeded) { renderCaptcha(e.captchaId, () => startQuiz()); return; }
      list.innerHTML = '';
      $('#quizHint').textContent = '';
      toast(e.message);
    }
  }

  function renderQuiz(it, i) {
    let opts = '';
    if (it.type === '判断') {
      opts = [['对', '正确'], ['错', '错误']].map(([lab, v]) =>
        `<div class="qopt" data-i="${i}" data-v="${esc(v)}"><span class="k">${lab}</span></div>`).join('');
    } else if (it.options && it.options.length) {
      opts = it.options.map((o) =>
        `<div class="qopt" data-i="${i}" data-v="${esc(o.key)}"><span class="k">${esc(o.key)}</span> ${esc(optText(o.text))}</div>`
      ).join('');
    } else {
      opts = '<div class="qopt" style="cursor:default"><span class="k">·</span> 该题型请自行核对</div>';
    }
    const multi = it.type === '多选';
    return `
      <li class="q" id="qz-${i}">
        <div class="q-index">${pad(i + 1)}</div>
        <div>
          <div class="q-meta"><span class="tag">${esc(it.type)}</span>${multi ? '<span class="sep">·</span><span>可多选</span>' : ''}</div>
          <p class="q-stem">${esc(it.stem)}</p>
          <div style="margin-top:6px">${opts}</div>
          ${multi ? `<button class="confirm" data-confirm="${i}">确认答案 →</button>` : ''}
        </div>
        <div class="q-ans" hidden><span class="lab">答案</span><span class="v"></span></div>
      </li>`;
  }

  function revealAns(card, item) {
    const box = card.querySelector('.q-ans');
    box.hidden = false;
    box.querySelector('.v').textContent = item.answer;
  }

  function markOpts(card, item, pickedSet) {
    const target = ansSet(item.answer);
    card.querySelectorAll('.qopt').forEach((o) => {
      const v = ansSet(o.dataset.v);
      if (target.includes(v) && v !== '') o.classList.add('right');
      else if (pickedSet.includes(v)) o.classList.add('wrong');
    });
  }

  function bindQuiz() {
    $$('#quizList .qopt[data-i]').forEach((el) => {
      el.addEventListener('click', () => {
        const i = Number(el.dataset.i);
        const card = document.getElementById('qz-' + i);
        if (!card || card.dataset.answered) return;
        const item = quizItems[i];
        if (item.type === '多选') { el.classList.toggle('picked'); return; }
        card.dataset.answered = '1';
        const picked = ansSet(el.dataset.v);
        const right = picked === ansSet(item.answer);
        markOpts(card, item, picked);
        revealAns(card, item);
        quizDone++; if (right) quizRight++;
        updateScore();
      });
    });
    $$('#quizList [data-confirm]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const i = Number(btn.dataset.confirm);
        const card = document.getElementById('qz-' + i);
        if (!card || card.dataset.answered) return;
        const item = quizItems[i];
        const picked = Array.from(card.querySelectorAll('.qopt.picked'))
          .map((o) => ansSet(o.dataset.v)).sort().join('');
        if (!picked) { toast('请先选择至少一个选项'); return; }
        card.dataset.answered = '1';
        btn.remove();
        const right = picked === ansSet(item.answer);
        markOpts(card, item, picked);
        revealAns(card, item);
        quizDone++; if (right) quizRight++;
        updateScore();
      });
    });
  }

  function updateScore() {
    const el = $('#quizScore');
    if (!quizDone) { el.textContent = ''; return; }
    el.innerHTML = `已答 <b>${quizDone}</b> · 正确 <b>${quizRight}</b>` +
      (quizDone === quizItems.length ? ' · 完成' : '');
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
        [j.today_requests, '今日请求'],
        [j.today_ips, '今日访客'],
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
  const TABS = ['search', 'quiz', 'stats'];
  function switchTab(name, push = true) {
    if (!TABS.includes(name)) name = 'search';
    $$('.nav-link').forEach((t) => t.classList.toggle('is-active', t.dataset.tab === name));
    $$('.view').forEach((v) => v.classList.toggle('is-active', v.id === 'view-' + name));
    if (push && location.hash !== '#' + name) history.replaceState(null, '', '#' + name);
    if (name === 'stats') loadStats();
    if (name === 'quiz' && !quizItems.length) startQuiz();
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

    $('#quizStart').addEventListener('click', startQuiz);

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
  bind();
  loadSubjects().then(() => doSearch(0));
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
