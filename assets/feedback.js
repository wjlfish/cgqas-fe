/* 问题反馈: 错题申报 / Bug 反馈 / 点赞
   同一套逻辑同时用于右下角弹框(panel)与独立页面(page) */
(function () {
  'use strict';

  const API = '/api.php';
  let token = '';
  let tokenExp = 0;

  const $ = (sel, root) => (root || document).querySelector(sel);
  const $$ = (sel, root) => Array.from((root || document).querySelectorAll(sel));
  const esc = (s) => String(s == null ? '' : s)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  const cut = (s, n) => {
    s = String(s || '');
    return s.length > n ? s.slice(0, n) + '…' : s;
  };

  /* ── 令牌 ── */
  function readToken() {
    const m = document.querySelector('meta[name="csrf-token"]');
    token = m ? m.content : '';
    tokenExp = Date.now() + 50 * 60 * 1000;
  }

  async function refreshToken() {
    try {
      const r = await fetch(API + '?action=refresh', {
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

  class NeedLogin extends Error {}

  async function request(action, params, isPost) {
    if (!token) readToken();
    if (Date.now() > tokenExp - 120000) await refreshToken();

    let url = API + '?action=' + encodeURIComponent(action);
    let opts = {
      headers: { 'X-CG-Token': token, Accept: 'application/json' },
      credentials: 'same-origin',
      method: 'GET',
    };
    if (isPost) {
      opts.method = 'POST';
      opts.body = params;
    } else {
      const qs = new URLSearchParams(params || {});
      if (qs.toString()) url += '&' + qs;
    }

    const r = await fetch(url, opts);
    let j = {};
    try { j = await r.json(); } catch (e) { /* ignore */ }

    if (r.status === 401) {
      if (j.reason === 'login_required') throw new NeedLogin('login_required');
      if (await refreshToken()) return request(action, params, isPost);
      throw new Error(j.error || '会话已失效，请刷新页面');
    }
    if (r.status === 403 && j.reason === 'quota_exhausted') {
      const e = new Error('搜题次数已用完');
      e.quota = true;
      throw e;
    }
    if (r.status === 429) {
      throw new Error(j.error || '操作过于频繁，请稍后再试');
    }
    if (!r.ok) throw new Error(j.error || ('请求失败 (' + r.status + ')'));
    return j;
  }

  /* ── 组件 ── */
  function mount(root, mode) {
    if (!root) return;
    const isPage = mode === 'page';
    const S = { tab: 'wrong', results: [], pick: null };

    root.innerHTML = `
      <div class="fb${isPage ? ' fb--page' : ''}">
        <nav class="fb-tabs" role="tablist">
          <button type="button" data-tab="wrong" class="on" role="tab">错题申报</button>
          <button type="button" data-tab="bug" role="tab">Bug 反馈</button>
          <button type="button" data-tab="like" role="tab">点赞</button>
        </nav>

        <section class="fb-pane" data-pane="wrong">
          <p class="fb-note">检索题目会消耗 <b>1 次</b>搜题额度（与首页共用）</p>
          <form class="fb-search" data-fsearch>
            <input type="search" maxlength="40" placeholder="输入题干或答案关键词，至少 2 字" aria-label="检索错题">
            <button type="submit">检索</button>
          </form>
          <div class="fb-res" data-res></div>
          <form class="fb-form" data-fform hidden>
            <div class="fb-picked" data-picked></div>
            <div class="fb-reasons" data-reasons>
              ${['答案错误', '题干错误', '选项错误', '答案缺失', '其他'].map((r, i) =>
                `<button type="button" class="chip${i === 0 ? ' on' : ''}" data-reason="${r}">${r}</button>`
              ).join('')}
            </div>
            <textarea data-note maxlength="500" rows="2"
                      placeholder="补充说明（可选）"></textarea>
            <div class="fb-acts">
              <button type="submit" class="solid" data-fsubmit>提交申报</button>
              <button type="button" data-fcancel>取消</button>
            </div>
          </form>
        </section>

        <section class="fb-pane" data-pane="bug" hidden>
          <p class="fb-note">页面打不开、按钮没反应、显示错乱等问题都可以提。</p>
          <form class="fb-bug" data-bform>
            <textarea data-bug maxlength="1000" rows="${isPage ? 8 : 5}"
                      placeholder="描述一下具体问题（至少 5 个字）…"></textarea>
            <input type="text" data-contact maxlength="64" placeholder="联系方式（可选，便于回复你）">
            <div class="fb-acts">
              <button type="submit" class="solid" data-bsubmit>提交反馈</button>
            </div>
          </form>
        </section>

        <section class="fb-pane" data-pane="like" hidden>
          <p class="fb-note">如果这个题库帮到了你，可以点个赞，也可以留句话。</p>
          <div class="fb-like">
            <button type="button" class="fb-like-btn" data-like aria-label="点赞">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
                   stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M12 20.3l-1.5-1.4C5.6 14.4 2.8 11.9 2.8 8.7 2.8 6.1 4.8 4.1 7.4 4.1c1.5 0 2.9.7 3.8 1.8l.8 1 .8-1c.9-1.1 2.3-1.8 3.8-1.8 2.6 0 4.6 2 4.6 4.6 0 3.2-2.8 5.7-7.7 10.2L12 20.3z"></path>
              </svg>
              <span data-like-label>点赞</span>
            </button>
            <span class="fb-like-n"><b data-like-n>—</b> 人已赞</span>
          </div>

          <form class="fb-cm-form" data-cform>
            <textarea data-cm maxlength="300" rows="2"
                      placeholder="说点什么…（最多 300 字）"></textarea>
            <div class="fb-acts">
              <button type="submit" class="solid" data-csubmit>发表</button>
            </div>
          </form>

          <div class="fb-cms" data-cms></div>
        </section>
      </div>`;

    const panes = $$('.fb-pane', root);
    const tabBtns = $$('.fb-tabs button', root);

    function switchTab(name) {
      S.tab = name;
      tabBtns.forEach((b) => b.classList.toggle('on', b.dataset.tab === name));
      panes.forEach((p) => { p.hidden = p.dataset.pane !== name; });
      if (name === 'like') loadLike();
    }
    tabBtns.forEach((b) => b.addEventListener('click', () => switchTab(b.dataset.tab)));

    /* ── 提示 ── */
    function msg(html, kind) {
      const res = $('[data-res]', root);
      res.innerHTML = `<p class="fb-msg${kind ? ' is-' + kind : ''}">${html}</p>`;
    }

    function askLogin() {
      msg('请先完成手机号验证后再申报。<a href="/" data-gologin>去登录</a>', 'warn');
      const a = $('[data-gologin]', root);
      if (a) a.addEventListener('click', (e) => {
        const btn = document.getElementById('loginBtn');
        if (btn && btn.offsetParent !== null) {
          e.preventDefault();
          btn.click();
        }
      });
    }

    /* ── 错题检索 ── */
    const resBox = $('[data-res]', root);
    const form = $('[data-fform]', root);

    $('[data-fsearch]', root).addEventListener('submit', async (e) => {
      e.preventDefault();
      const inp = $('[data-fsearch] input', root);
      const kw = inp.value.trim();
      if (kw.length < 2) {
        msg('请输入至少 2 个字的关键词', 'warn');
        return;
      }
      form.hidden = true;
      S.pick = null;
      const oldFlash = paneOf('wrong').querySelector('.fb-flash');
      if (oldFlash) oldFlash.remove();
      msg('检索中…');
      try {
        const j = await request('search', { q: kw, page: 0 });
        S.results = j.items || [];
        if (!S.results.length) {
          msg('没有匹配的题目，换个关键词试试');
          return;
        }
        const left = typeof j.left === 'number' ? `，剩余 <b>${j.left}</b> 次额度` : '';
        resBox.innerHTML =
          `<p class="fb-hint">共 ${j.total} 条，本页 ${S.results.length} 条${left}。点「申报」选择要反馈的题目。</p>` +
          `<ul class="fb-list">` + S.results.map((it, i) => `
            <li class="fb-item">
              <div class="fb-item-main">
                <span class="tag">${esc(it.type)}</span>
                <span class="fb-stem">${esc(cut(it.stem, 46))}</span>
              </div>
              <div class="fb-item-side">
                <span class="fb-ans">${esc(cut(it.answer, 18))}</span>
                <button type="button" data-pick="${i}">申报</button>
              </div>
            </li>`).join('') + `</ul>`;

        $$('[data-pick]', root).forEach((b) => b.addEventListener('click', () => {
          const it = S.results[Number(b.dataset.pick)];
          S.pick = it;
          $$('.fb-item', root).forEach((li) => li.classList.remove('on'));
          b.closest('.fb-item').classList.add('on');
          $('[data-picked]', root).innerHTML =
            `<span class="tag">${esc(it.type)}</span> <span class="fb-picked-stem">${esc(cut(it.stem, 60))}</span>`
            + `<span class="fb-picked-ans">答案：${esc(cut(it.answer, 20))}</span>`;
          form.hidden = false;
          form.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        }));
      } catch (err) {
        if (err instanceof NeedLogin) askLogin();
        else if (err.quota) msg('搜题次数已用完，无法再检索题目。', 'bad');
        else msg(esc(err.message), 'bad');
      }
    });

    $$('[data-reasons] .chip', root).forEach((c) => c.addEventListener('click', () => {
      $$('[data-reasons] .chip', root).forEach((x) => x.classList.remove('on'));
      c.classList.add('on');
    }));

    $('[data-fcancel]', root).addEventListener('click', () => {
      form.hidden = true;
      S.pick = null;
      $$('.fb-item', root).forEach((li) => li.classList.remove('on'));
    });

    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      if (!S.pick) { msg('请先选择要申报的题目', 'warn'); return; }
      const reason = ($('[data-reasons] .chip.on', root) || {}).dataset?.reason || '答案错误';
      const note = $('[data-note]', root).value.trim();
      const btn = $('[data-fsubmit]', root);
      btn.disabled = true;
      btn.textContent = '提交中…';
      try {
        const fd = new FormData();
        fd.append('kind', 'wrong');
        fd.append('question_id', S.pick.id);
        fd.append('reason', reason);
        fd.append('content', note);
        await request('feedback_submit', fd, true);
        form.hidden = true;
        S.pick = null;
        $('[data-note]', root).value = '';
        $$('.fb-item', root).forEach((li) => li.classList.remove('on'));
        flash('wrong', '已收到申报，感谢！可以继续点选其他题目。', 'ok');
      } catch (err) {
        if (err instanceof NeedLogin) askLogin();
        else msg(esc(err.message), 'bad');
      } finally {
        btn.disabled = false;
        btn.textContent = '提交申报';
      }
    });

    /* ── Bug 反馈 ── */
    $('[data-bform]', root).addEventListener('submit', async (e) => {
      e.preventDefault();
      const text = $('[data-bug]', root).value.trim();
      const contact = $('[data-contact]', root).value.trim();
      if (text.length < 5) { flash('bug', '请至少写 5 个字描述问题'); return; }
      const btn = $('[data-bsubmit]', root);
      btn.disabled = true;
      btn.textContent = '提交中…';
      try {
        const fd = new FormData();
        fd.append('kind', 'bug');
        fd.append('content', text);
        fd.append('contact', contact);
        await request('feedback_submit', fd, true);
        $('[data-bug]', root).value = '';
        $('[data-contact]', root).value = '';
        flash('bug', '已提交，感谢反馈！', 'ok');
      } catch (err) {
        if (err instanceof NeedLogin) sayLogin('bug');
        else flash('bug', err.message, 'bad');
      } finally {
        btn.disabled = false;
        btn.textContent = '提交反馈';
      }
    });

    function paneOf(name) { return root.querySelector(`[data-pane="${name}"]`); }
    function flash(name, text, kind) {
      const p = paneOf(name);
      let el = p.querySelector('.fb-flash');
      if (!el) {
        el = document.createElement('p');
        el.className = 'fb-flash';
        const anchor = Array.from(p.children)
          .find((c) => c.matches('.fb-cms, .fb-acts'));
        if (anchor) p.insertBefore(el, anchor);
        else p.appendChild(el);
      }
      el.className = 'fb-flash' + (kind ? ' is-' + kind : '');
      el.textContent = text;
    }
    function sayLogin(name) {
      const p = paneOf(name);
      let el = p.querySelector('.fb-flash');
      if (!el) {
        el = document.createElement('p');
        el.className = 'fb-flash';
        const anchor = Array.from(p.children)
          .find((c) => c.matches('.fb-cms, .fb-acts'));
        if (anchor) p.insertBefore(el, anchor);
        else p.appendChild(el);
      }
      el.className = 'fb-flash is-warn';
      el.innerHTML = '请先完成手机号验证。<a href="/" data-gologin>去登录</a>';
      const a = el.querySelector('[data-gologin]');
      if (a) a.addEventListener('click', (ev) => {
        const b = document.getElementById('loginBtn');
        if (b && b.offsetParent !== null) { ev.preventDefault(); b.click(); }
      });
    }

    /* ── 点赞 ── */
    let likeLoaded = false;
    async function loadLike() {
      try {
        const j = await request('feedback_stat', {});
        $('[data-like-n]', root).textContent = j.likes;
        if (j.liked) {
          const btn = $('[data-like]', root);
          btn.classList.add('is-on');
          $('[data-like-label]', root).textContent = '已点赞';
        }
        likeLoaded = true;
      } catch (e) { /* 静默 */ }
    }
    $('[data-like]', root).addEventListener('click', async function () {
      this.disabled = true;
      try {
        const j = await request('feedback_like', new FormData(), true);
        $('[data-like-n]', root).textContent = j.total;
        this.classList.add('is-on');
        $('[data-like-label]', root).textContent = '已点赞';
        const pane = paneOf('like');
        let el = pane.querySelector('.fb-flash');
        if (!el) { el = document.createElement('p'); el.className = 'fb-flash'; pane.appendChild(el); }
        el.className = 'fb-flash is-ok';
        el.textContent = '谢谢！';
      } catch (err) {
        if (err instanceof NeedLogin) sayLogin('like');
        else {
          const pane = paneOf('like');
          let el = pane.querySelector('.fb-flash');
          if (!el) { el = document.createElement('p'); el.className = 'fb-flash'; pane.appendChild(el); }
          el.className = 'fb-flash is-bad';
          el.textContent = err.message;
        }
      } finally {
        this.disabled = false;
      }
    });

    /* ── 评论 ── */
    function renderComments(items) {
      const box = $('[data-cms]', root);
      if (!items || !items.length) {
        box.innerHTML = '<p class="fb-cm-empty">还没有留言，来做第一个。</p>';
        return;
      }
      box.innerHTML = '<ul class="fb-cm-list">' + items.map((c) => `
        <li class="fb-cm">
          <div class="fb-cm-head"><span class="fb-cm-name">${esc(c.name)}</span>
            <span class="fb-cm-at">${esc(c.at)}</span></div>
          <p class="fb-cm-text">${esc(c.content)}</p>
        </li>`).join('') + '</ul>';
    }

    async function loadComments() {
      try {
        const j = await request('feedback_comments', {});
        renderComments(j.items);
      } catch (e) {
        $('[data-cms]', root).innerHTML = '<p class="fb-cm-empty">留言加载失败</p>';
      }
    }

    $('[data-cform]', root).addEventListener('submit', async (e) => {
      e.preventDefault();
      const ta = $('[data-cm]', root);
      const text = ta.value.trim();
      if (!text) { flash('like', '写点什么吧', 'warn'); return; }
      const btn = $('[data-csubmit]', root);
      btn.disabled = true;
      btn.textContent = '发表中…';
      try {
        const fd = new FormData();
        fd.append('content', text);
        const j = await request('feedback_comment', fd, true);
        ta.value = '';
        renderComments(j.items);
        flash('like', '已发表，谢谢！', 'ok');
      } catch (err) {
        if (err instanceof NeedLogin) sayLogin('like');
        else flash('like', err.message, 'bad');
      } finally {
        btn.disabled = false;
        btn.textContent = '发表';
      }
    });

    loadLike();
    loadComments();
    return { switchTab };
  }

  window.CGFB = { mount };
  readToken();
})();
