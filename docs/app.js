/* Nova Two-Factor — Signal site
   Hash router, command palette, scroll spy, and the interactive pieces. */
const reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;
const $ = (s, r = document) => r.querySelector(s);
const $$ = (s, r = document) => [...r.querySelectorAll(s)];

/* ---------------- routes ---------------- */
const ROUTES = [
  {id: 'home',           title: 'Home',                     group: null},
  {id: 'docs',           title: 'Introduction',             group: 'Getting started'},
  {id: 'installation',   title: 'Installation',             group: 'Getting started'},
  {id: 'configuration',  title: 'Configuration',            group: 'Getting started'},
  {id: 'methods',        title: 'Authentication methods',   group: 'Guides'},
  {id: 'enforcement',    title: 'Enforcement',              group: 'Guides'},
  {id: 'step-up',        title: 'Step-up re-authentication',group: 'Guides'},
  {id: 'administration', title: 'Administration',           group: 'Guides'},
  {id: 'extending',      title: 'Extending',                group: 'Guides'},
  {id: 'security',       title: 'Security model',           group: 'Reference'},
  {id: 'translations',   title: 'Translations',             group: 'Reference'},
  {id: 'commands',       title: 'Artisan commands',         group: 'Reference'},
  {id: 'troubleshooting',title: 'Troubleshooting',          group: 'Reference'},
  {id: 'project',        title: 'Project & licence',        group: 'Reference'},
  {id: 'screens',        title: 'Screens',                  group: null},
  {id: 'upgrade',        title: 'Upgrading from 1.x',       group: null},
  {id: 'changelog',      title: 'Changelog',                group: null},
];
const DOC_ORDER = ROUTES.filter(r => r.group).map(r => r.id);

/* ---------------- sidebar ---------------- */
(() => {
  const groups = {};
  ROUTES.filter(r => r.group).forEach(r => (groups[r.group] ||= []).push(r));
  $('#side').innerHTML = Object.entries(groups).map(([g, rs]) =>
    `<div class="sgroup"><h4>${g}</h4>${rs.map(r => `<a href="#/${r.id}" data-r="${r.id}">${r.title}</a>`).join('')}</div>`
  ).join('') + `<div class="sgroup"><h4>More</h4>
      <a href="#/screens" data-r="screens">Screens</a>
      <a href="#/upgrade" data-r="upgrade">Upgrading from 1.x</a>
      <a href="#/changelog" data-r="changelog">Changelog</a></div>`;
})();

/* ---------------- router ---------------- */
const view = $('#view'), shell = $('#shell'), homeWrap = $('#home');
function routeId() {
  const h = location.hash.replace(/^#\/?/, '').split('#')[0];
  return ROUTES.some(r => r.id === h) ? h : 'home';
}
function go() {
  const id = routeId(), isHome = id === 'home';
  homeWrap.classList.toggle('hidden', !isHome);
  shell.classList.toggle('hidden', isHome);
  $('#field').classList.toggle('hidden', !isHome);
  $('.veil').classList.toggle('hidden', !isHome);

  $$('.page').forEach(p => p.classList.toggle('hidden', p.dataset.page !== id));
  $$('#side a').forEach(a => a.classList.toggle('act', a.dataset.r === id));
  $$('.navlinks a').forEach(a => a.classList.toggle('on', a.dataset.nav === id));
  $('#side').classList.remove('open');

  if (!isHome) { buildToc(id); buildPager(id); }
  const anchor = location.hash.split('#')[2];
  if (anchor) { const el = document.getElementById(anchor); if (el) { el.scrollIntoView(); return; } }
  scrollTo({top: 0, behavior: 'instant' in document.documentElement.style ? 'instant' : 'auto'});
  document.title = (id === 'home' ? 'Signal' : ROUTES.find(r => r.id === id).title + ' · Nova Two-Factor');
}
addEventListener('hashchange', go);

/* ---------------- TOC + scroll spy ---------------- */
let spy = null;
function buildToc(id) {
  const page = $(`.page[data-page="${id}"]`), toc = $('#toc');
  if (!page) return;
  const hs = $$('h2[id]', page);
  toc.innerHTML = hs.length
    ? `<h5>On this page</h5>` + hs.map(h => `<a href="#/${id}#${h.id}">${h.firstChild.textContent.trim()}</a>`).join('')
    : '';
  spy?.disconnect();
  if (!hs.length) return;
  spy = new IntersectionObserver(es => es.forEach(e => {
    if (!e.isIntersecting) return;
    $$('#toc a').forEach(a => a.classList.toggle('act', a.getAttribute('href').endsWith('#' + e.target.id)));
  }), {rootMargin: '-90px 0px -72% 0px'});
  hs.forEach(h => spy.observe(h));
}
function buildPager(id) {
  const page = $(`.page[data-page="${id}"]`);
  const old = $('.pager', page); if (old) old.remove();
  const i = DOC_ORDER.indexOf(id); if (i < 0) return;
  const prev = DOC_ORDER[i - 1], next = DOC_ORDER[i + 1];
  const t = x => ROUTES.find(r => r.id === x).title;
  page.insertAdjacentHTML('beforeend', `<div class="pager">
    ${prev ? `<a class="pg" href="#/${prev}"><div class="l">← Previous</div><div class="t">${t(prev)}</div></a>` : '<span></span>'}
    ${next ? `<a class="pg next" href="#/${next}"><div class="l">Next →</div><div class="t">${t(next)}</div></a>` : '<span></span>'}
  </div>`);
}

/* ---------------- syntax highlighting ---------------- */
const RULES = {
  php: [
    [/^\/\/[^\n]*/, 'cmt'], [/^#[^\n]*/, 'cmt'],
    [/^'(?:[^'\\]|\\.)*'/, 'str'], [/^"(?:[^"\\]|\\.)*"/, 'str'],
    [/^\$[A-Za-z_]\w*/, 'var'],
    [/^\b(?:use|class|extends|implements|return|public|protected|private|static|function|fn|new|namespace|if|else|foreach|as)\b/, 'kw'],
    [/^\b(?:true|false|null)\b/, 'lit'],
    [/^[A-Z][A-Za-z0-9_]*(?=\s*::)/, 'cls'],
    [/^(?:::|->|=>)/, 'op'],
    [/^[a-zA-Z_]\w*(?=\s*\()/, 'fn'],
    [/^\d+/, 'num'],
    [/^\s+/, null], [/^[\s\S]/, null],
  ],
  shell: [
    [/^#[^\n]*/, 'cmt'],
    [/^\b(?:composer|php|npm|git|artisan)\b/, 'cmd'],
    [/^--?[\w-]+/, 'flag'],
    [/^'(?:[^'\\]|\\.)*'/, 'str'], [/^"(?:[^"\\]|\\.)*"/, 'str'],
    [/^\s+/, null], [/^[\s\S]/, null],
  ],
  env: [
    [/^#[^\n]*/, 'cmt'],
    [/^[A-Z][A-Z0-9_]*(?==)/, 'var'],
    [/^=/, 'op'],
    [/^\s+/, null], [/^[^\s]+/, 'val'], [/^[\s\S]/, null],
  ],
};
function detect(t) {
  if (/^\s*(?:composer|php artisan|npm|git)\b/m.test(t) && !/[{;]/.test(t)) return 'shell';
  if (/^[A-Z][A-Z0-9_]*=/m.test(t)) return 'env';
  return 'php';
}
function highlight(text, lang = detect(text)) {
  const rules = RULES[lang], esc = s => s.replace(/&/g, '&amp;').replace(/</g, '&lt;');
  let out = '', i = 0, guard = 0;
  while (i < text.length && guard++ < 60000) {
    const rest = text.slice(i);
    for (const [re, cls] of rules) {
      const m = rest.match(re);
      if (!m) continue;
      out += cls ? `<span class="t-${cls}">${esc(m[0])}</span>` : esc(m[0]);
      i += m[0].length;
      break;
    }
  }
  return out;
}
$$('pre').forEach(pre => {
  if (pre.closest('.bout')) return;             // the builder paints its own output
  pre.innerHTML = highlight(pre.textContent);
});

/* ---------------- anchors + copy buttons ---------------- */
$$('.page h2[id], .page h3[id]').forEach(h => {
  const id = h.closest('.page').dataset.page;
  h.insertAdjacentHTML('beforeend', `<a class="anch" href="#/${id}#${h.id}">#</a>`);
});
$$('pre').forEach(pre => {
  if (pre.closest('.bout')) return;   // the builder ships its own copy button
  const wrap = document.createElement('div');
  wrap.className = 'codewrap';
  pre.parentNode.insertBefore(wrap, pre);
  wrap.appendChild(pre);
  const b = document.createElement('button');
  b.className = 'cp'; b.type = 'button'; b.textContent = 'Copy';
  b.addEventListener('click', async () => {
    try { await navigator.clipboard.writeText(pre.innerText); b.textContent = 'Copied'; b.classList.add('done');
      setTimeout(() => { b.textContent = 'Copy'; b.classList.remove('done'); }, 1600); }
    catch { b.textContent = 'Press ⌘C'; }
  });
  wrap.appendChild(b);
});

/* ---------------- command palette ---------------- */
const INDEX = [];
ROUTES.forEach(r => {
  INDEX.push({t: r.title, s: r.group || 'Page', h: `#/${r.id}`});
  const p = $(`.page[data-page="${r.id}"]`);
  if (p) $$('h2[id], h3[id]', p).forEach(h =>
    INDEX.push({t: h.firstChild.textContent.trim(), s: r.title, h: `#/${r.id}#${h.id}`}));
});
const pal = $('#pal'), palq = $('#palq'), palres = $('#palres');
let sel = 0, hits = [];
function openPal() { pal.classList.remove('hidden'); palq.value = ''; palq.focus(); render(''); }
function closePal() { pal.classList.add('hidden'); }
function render(q) {
  q = q.trim().toLowerCase();
  hits = (q ? INDEX.filter(i => i.t.toLowerCase().includes(q) || i.s.toLowerCase().includes(q)) : INDEX).slice(0, 30);
  sel = 0;
  palres.innerHTML = hits.length
    ? hits.map((i, n) => `<a href="${i.h}" class="${n === 0 ? 'sel' : ''}">${i.t}<small>${i.s}</small></a>`).join('')
    : '<div class="palempty">Nothing matches that.</div>';
}
palq.addEventListener('input', e => render(e.target.value));
$('#kbtn').addEventListener('click', openPal);
pal.addEventListener('click', e => { if (e.target === pal) closePal(); });
palres.addEventListener('click', closePal);
addEventListener('keydown', e => {
  if ((e.metaKey || e.ctrlKey) && e.key === 'k') { e.preventDefault(); pal.classList.contains('hidden') ? openPal() : closePal(); return; }
  if (pal.classList.contains('hidden')) return;
  if (e.key === 'Escape') closePal();
  if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
    e.preventDefault();
    sel = Math.max(0, Math.min(hits.length - 1, sel + (e.key === 'ArrowDown' ? 1 : -1)));
    $$('#palres a').forEach((a, n) => a.classList.toggle('sel', n === sel));
    $$('#palres a')[sel]?.scrollIntoView({block: 'nearest'});
  }
  if (e.key === 'Enter' && hits[sel]) { location.hash = hits[sel].h; closePal(); }
});

/* ---------------- burger + progress ---------------- */
$('#burger').addEventListener('click', () => $('#side').classList.toggle('open'));
addEventListener('scroll', () => {
  const h = document.documentElement;
  $('#prog').style.width = (h.scrollTop / Math.max(1, h.scrollHeight - h.clientHeight) * 100) + '%';
}, {passive: true});

/* ---------------- config option search ---------------- */
(() => {
  const q = $('#cfgq'); if (!q) return;
  const count = $('#cfgcount'), nores = $('#cfgnores');
  const opts = $$('.page[data-page="configuration"] .optlist .opt');
  let mode = 'all';
  function apply() {
    const s = q.value.trim().toLowerCase(); let n = 0;
    opts.forEach(o => {
      const env = !!o.querySelector('.oenv'), lock = !!o.querySelector('.lock');
      let ok = !s || o.textContent.toLowerCase().includes(s);
      if (ok && mode === 'env') ok = env;
      if (ok && mode === 'lock') ok = lock;
      o.classList.toggle('hide', !ok); if (ok) n++;
    });
    count.textContent = `${n} of ${opts.length} options`;
    nores.classList.toggle('hidden', n > 0);
    // hide a group heading whose options have all filtered out
    $$('.page[data-page="configuration"] .optlist').forEach(l => {
      const vis = [...l.querySelectorAll('.opt')].some(o => !o.classList.contains('hide'));
      l.style.display = vis ? '' : 'none';
      const intro = l.previousElementSibling, head = intro && intro.previousElementSibling;
      if (intro) intro.style.display = vis ? '' : 'none';
      if (head && head.tagName === 'H2') head.style.display = vis ? '' : 'none';
    });
  }
  q.addEventListener('input', apply);
  $$('.page[data-page="configuration"] .gfilter .chip').forEach(c => c.addEventListener('click', () => {
    $$('.page[data-page="configuration"] .gfilter .chip').forEach(x => x.classList.remove('on'));
    c.classList.add('on'); mode = c.dataset.f; apply();
  }));
  apply();
})();

/* ---------------- configuration builder ---------------- */
(() => {
  const root = document.getElementById('cfgbuilder'); if (!root) return;

  const GROUPS = [
    {id:'enf',   name:'Enforcement',     hint:'Who must enrol, and by when.'},
    {id:'rem',   name:'Reminders',       hint:'How often users are nudged.'},
    {id:'met',   name:'Methods',         hint:'Which factors are offered.'},
    {id:'pk',    name:'Passkeys',        hint:'WebAuthn relying party and origins.'},
    {id:'ses',   name:'Sessions',        hint:'Trusted devices and re-authentication.'},
    {id:'lim',   name:'Rate limits',     hint:'Ceilings per user, before lockout.'},
    {id:'adm',   name:'Administration',  hint:'The three pages, the menu, alerts.'},
  ];

  const F = [
    // enforcement
    {g:'enf', env:'NOVA_TWO_FACTOR_ENABLED', label:'Package enabled', t:'bool', def:'true',
      note:'The master switch. Never editable from the Settings page.'},
    {g:'enf', env:'NOVA_TWO_FACTOR_MODE', label:'Mode', t:'sel', def:'optional', opts:['optional','encouraged','required']},
    {g:'enf', env:'NOVA_TWO_FACTOR_GATE', label:'Targeting gate', t:'text', def:'', ph:'require-two-factor',
      note:'Restrict enforcement to whoever this ability allows.'},
    {g:'enf', env:'NOVA_TWO_FACTOR_GRACE_ENABLED', label:'Grace period', t:'bool', def:'true'},
    {g:'enf', env:'NOVA_TWO_FACTOR_GRACE_MODE', label:'Grace measured by', t:'sel', def:'days', opts:['days','date']},
    {g:'enf', env:'NOVA_TWO_FACTOR_GRACE_DAYS', label:'Grace days', t:'num', def:'7', min:0, max:365,
      note:'From each account’s created_at.'},
    {g:'enf', env:'NOVA_TWO_FACTOR_ENFORCED_FROM', label:'Enforced from', t:'date', def:'',
      note:'One shared deadline for everybody.'},
    // reminders
    {g:'rem', env:'NOVA_TWO_FACTOR_REMIND_DAYS', label:'Snooze window (days)', t:'num', def:'7', min:1, max:90,
      note:'How long a user may silence the prompt under encouraged.'},
    {g:'rem', env:'NOVA_TWO_FACTOR_REMIND_COOLDOWN', label:'Mail cooldown (hours)', t:'num', def:'24', min:1, max:168},
    {g:'rem', env:'NOVA_TWO_FACTOR_QUEUE_REMINDERS', label:'Queue reminder mail', t:'bool', def:'true',
      note:'Safe to queue — nobody is waiting on it.'},
    {g:'rem', env:'NOVA_TWO_FACTOR_LIMIT_REMIND', label:'Reminders per user', t:'num', def:'30', min:1, max:200},
    // methods
    {g:'met', env:'NOVA_TWO_FACTOR_TOTP_ENABLED', label:'Authenticator apps', t:'bool', def:'true'},
    {g:'met', env:'NOVA_TWO_FACTOR_WEBAUTHN_ENABLED', label:'Passkeys', t:'bool', def:'true'},
    {g:'met', env:'NOVA_TWO_FACTOR_EMAIL_ENABLED', label:'Email codes', t:'bool', def:'true'},
    {g:'met', env:'NOVA_TWO_FACTOR_EMAIL_TTL', label:'Email code TTL (s)', t:'num', def:'300', min:60, max:1800},
    {g:'met', env:'NOVA_TWO_FACTOR_EMAIL_RESEND_AFTER', label:'Resend after (s)', t:'num', def:'60', min:15, max:600},
    {g:'met', env:'NOVA_TWO_FACTOR_EMAIL_QUEUE', label:'Queue login codes', t:'bool', def:'false',
      note:'Off by default — a code that arrives late is a support ticket.'},
    // passkeys
    {g:'pk', env:'NOVA_TWO_FACTOR_WEBAUTHN_RP_ID', label:'Relying party ID', t:'text', def:'', ph:'admin.example.com',
      note:'Defaults to the APP_URL host, which on a separate panel domain is the wrong one.'},
    {g:'pk', env:'NOVA_TWO_FACTOR_WEBAUTHN_RP_NAME', label:'Relying party name', t:'text', def:'', ph:'Acme Admin',
      note:'Shown in the browser prompt.'},
    {g:'pk', env:'NOVA_TWO_FACTOR_WEBAUTHN_ORIGINS', label:'Extra origins', t:'text', def:'', ph:'https://a.test,https://b.test'},
    {g:'pk', env:'NOVA_TWO_FACTOR_LIMIT_WEBAUTHN', label:'Assertions per minute', t:'num', def:'30', min:1, max:300},
    // sessions
    {g:'ses', env:'NOVA_TWO_FACTOR_TRUSTED_DEVICES', label:'Trusted devices', t:'bool', def:'true'},
    {g:'ses', env:'NOVA_TWO_FACTOR_TRUSTED_DEVICE_DAYS', label:'Trusted for (days)', t:'num', def:'30', min:1, max:365},
    {g:'ses', env:'NOVA_TWO_FACTOR_PER_DEVICE', label:'Limit per device', t:'bool', def:'true'},
    {g:'ses', env:'NOVA_TWO_FACTOR_STEP_UP_TTL', label:'Step-up TTL (s)', t:'num', def:'300', min:60, max:3600},
    {g:'ses', env:'NOVA_TWO_FACTOR_PASSWORD_CONFIRMATION_TTL', label:'Password confirm TTL (s)', t:'num', def:'900', min:60, max:7200},
    {g:'ses', env:'NOVA_TWO_FACTOR_SECURE_COOKIES', label:'Secure cookies', t:'sel', def:'',
      opts:[['','follow session config'],['true','force on'],['false','force off']]},
    // limits
    {g:'lim', env:'NOVA_TWO_FACTOR_LIMIT_CHALLENGE', label:'Challenge attempts', t:'num', def:'5', min:1, max:20,
      note:'Per user, per decay window. A second ceiling of 60 per IP always applies.'},
    {g:'lim', env:'NOVA_TWO_FACTOR_LIMIT_STEP_UP', label:'Step-up attempts', t:'num', def:'5', min:1, max:20},
    {g:'lim', env:'NOVA_TWO_FACTOR_LIMIT_RECOVERY', label:'Recovery per hour', t:'num', def:'10', min:1, max:60,
      note:'Malformed submissions get a much tighter budget of 3.'},
    {g:'lim', env:'NOVA_TWO_FACTOR_LIMIT_ENROLL', label:'Enroll attempts', t:'num', def:'10', min:1, max:60},
    {g:'lim', env:'NOVA_TWO_FACTOR_LIMIT_ENROLL_DECAY', label:'Enroll decay (s)', t:'num', def:'600', min:60, max:7200},
    {g:'lim', env:'NOVA_TWO_FACTOR_LIMIT_OTP_SEND', label:'Code sends per user', t:'num', def:'3', min:1, max:30},
    {g:'lim', env:'NOVA_TWO_FACTOR_LIMIT_OTP_SEND_DECAY', label:'Send decay (s)', t:'num', def:'900', min:60, max:7200},
    // administration
    {g:'adm', env:'NOVA_TWO_FACTOR_ADMIN_GATE', label:'Admin gate', t:'text', def:'', ph:'nova-two-factor:admin',
      note:'The pages stay invisible until this ability exists.'},
    {g:'adm', env:'NOVA_TWO_FACTOR_COMPLIANCE', label:'Compliance page', t:'bool', def:'true'},
    {g:'adm', env:'NOVA_TWO_FACTOR_COMPLIANCE_MODELS', label:'Counted models', t:'text', def:'', ph:'App\\Models\\Admin', quote:1,
      note:'On an admin-only panel, counting every customer makes adoption look high and wrong.'},
    {g:'adm', env:'NOVA_TWO_FACTOR_SETTINGS', label:'Settings page editable', t:'bool', def:'false'},
    {g:'adm', env:'NOVA_TWO_FACTOR_PAUSE_MAX', label:'Max pause (minutes)', t:'num', def:'120', min:5, max:1440},
    {g:'adm', env:'NOVA_TWO_FACTOR_MENU', label:'Menu group', t:'bool', def:'true'},
    {g:'adm', env:'NOVA_TWO_FACTOR_MENU_BADGE', label:'Menu badge', t:'bool', def:'true'},
    {g:'adm', env:'NOVA_TWO_FACTOR_SHOW_TRADEOFFS', label:'Show method trade-offs', t:'bool', def:'true'},
    {g:'adm', env:'NOVA_TWO_FACTOR_LOCKOUT_ALERT', label:'Lockout-burst alert', t:'num', def:'0', min:0, max:100,
      note:'Distinct accounts locked out before an alert fires. Zero disables it.'},
    {g:'adm', env:'NOVA_TWO_FACTOR_LOCKOUT_ALERT_WINDOW', label:'Burst window (min)', t:'num', def:'15', min:1, max:240},
  ];

  const esc = t => String(t).replace(/&/g,'&amp;').replace(/</g,'&lt;');
  const ctl = f => {
    if (f.t === 'bool') return `<label class="sw"><input type="checkbox" data-env="${f.env}"${f.def==='true'?' checked':''}><span class="tk"></span></label>`;
    if (f.t === 'sel') return `<select data-env="${f.env}">` + f.opts.map(o => {
        const [v,l] = Array.isArray(o) ? o : [o,o];
        return `<option value="${esc(v)}"${v===f.def?' selected':''}>${esc(l)}</option>`;
      }).join('') + `</select>`;
    if (f.t === 'num')  return `<input type="number" data-env="${f.env}" value="${f.def}" min="${f.min}" max="${f.max}">`;
    if (f.t === 'date') return `<input type="date" data-env="${f.env}">`;
    return `<input type="text" data-env="${f.env}" placeholder="${esc(f.ph||'')}">`;
  };

  root.innerHTML = `
    <div class="bhead">
      <span class="bpill">44 options · 7 groups</span>
      <span class="bhint">Only what you change is written out</span>
    </div>
    <div class="bwrap">
      <nav class="btabs" role="tablist">
        ${GROUPS.map((g,i) => `<button class="btab${i===0?' on':''}" data-t="${g.id}" role="tab" type="button">
            <span class="n">${g.name}</span><span class="ct" data-ct="${g.id}"></span></button>`).join('')}
      </nav>
      <div class="bpanels">
        ${GROUPS.map((g,i) => `<section class="bpanel${i===0?'':' hidden'}" data-p="${g.id}">
            <p class="phint">${g.hint}</p>
            ${F.filter(f => f.g === g.id).map(f => `
              <div class="brow" data-row="${f.env}">
                <div class="bl">
                  <span class="bname">${f.label}</span>
                  <span class="benv">${f.env}</span>
                  ${f.note ? `<span class="bnote">${f.note}</span>` : ''}
                </div>
                <div class="bc">${ctl(f)}</div>
              </div>`).join('')}
          </section>`).join('')}
      </div>
    </div>
    <div class="bwarn hidden" id="b-warn"><span class="i">!</span><p></p></div>
    <div class="bout">
      <div class="bouthead">
        <span class="lbl">.env</span><span class="cnt" id="b-count"></span>
        <button class="bcopy" id="b-reset" type="button">Reset</button>
        <button class="bcopy" id="b-copy" type="button">Copy</button>
      </div>
      <pre id="b-env"></pre>
    </div>`;

  const fields = [...root.querySelectorAll('[data-env]')];
  const byEnv = e => root.querySelector(`[data-env="${e}"]`);
  const out = root.querySelector('#b-env'), count = root.querySelector('#b-count'), warn = root.querySelector('#b-warn');
  const val = f => f.type === 'checkbox' ? String(f.checked) : f.value.trim();

  root.querySelectorAll('.btab').forEach(t => t.addEventListener('click', () => {
    root.querySelectorAll('.btab').forEach(x => x.classList.toggle('on', x === t));
    root.querySelectorAll('.bpanel').forEach(p => p.classList.toggle('hidden', p.dataset.p !== t.dataset.t));
  }));

  function active() {
    const mode = byEnv('NOVA_TWO_FACTOR_MODE').value;
    const graceOn = byEnv('NOVA_TWO_FACTOR_GRACE_ENABLED').checked;
    const gmode = byEnv('NOVA_TWO_FACTOR_GRACE_MODE').value;
    return k => {
      if (k === 'NOVA_TWO_FACTOR_GRACE_ENABLED') return mode === 'required';
      if (k === 'NOVA_TWO_FACTOR_GRACE_MODE') return mode === 'required' && graceOn;
      if (k === 'NOVA_TWO_FACTOR_GRACE_DAYS') return mode === 'required' && graceOn && gmode === 'days';
      if (k === 'NOVA_TWO_FACTOR_ENFORCED_FROM') return mode === 'required' && graceOn && gmode === 'date';
      if (k === 'NOVA_TWO_FACTOR_REMIND_DAYS') return mode === 'encouraged';
      if (['NOVA_TWO_FACTOR_WEBAUTHN_RP_ID','NOVA_TWO_FACTOR_WEBAUTHN_RP_NAME','NOVA_TWO_FACTOR_WEBAUTHN_ORIGINS','NOVA_TWO_FACTOR_LIMIT_WEBAUTHN']
          .includes(k)) return byEnv('NOVA_TWO_FACTOR_WEBAUTHN_ENABLED').checked;
      if (['NOVA_TWO_FACTOR_EMAIL_TTL','NOVA_TWO_FACTOR_EMAIL_RESEND_AFTER','NOVA_TWO_FACTOR_EMAIL_QUEUE','NOVA_TWO_FACTOR_LIMIT_OTP_SEND','NOVA_TWO_FACTOR_LIMIT_OTP_SEND_DECAY']
          .includes(k)) return byEnv('NOVA_TWO_FACTOR_EMAIL_ENABLED').checked;
      if (k === 'NOVA_TWO_FACTOR_TRUSTED_DEVICE_DAYS') return byEnv('NOVA_TWO_FACTOR_TRUSTED_DEVICES').checked;
      if (k === 'NOVA_TWO_FACTOR_LOCKOUT_ALERT_WINDOW') return +byEnv('NOVA_TWO_FACTOR_LOCKOUT_ALERT').value > 0;
      if (['NOVA_TWO_FACTOR_COMPLIANCE_MODELS'].includes(k)) return byEnv('NOVA_TWO_FACTOR_COMPLIANCE').checked;
      if (k === 'NOVA_TWO_FACTOR_MENU_BADGE') return byEnv('NOVA_TWO_FACTOR_MENU').checked;
      return true;
    };
  }

  function build() {
    const on = active(), spec = Object.fromEntries(F.map(f => [f.env, f]));
    const lines = [], per = {};

    fields.forEach(f => {
      const k = f.dataset.env, s = spec[k], live = on(k);
      root.querySelector(`[data-row="${k}"]`).classList.toggle('off', !live);
      if (!live) return;
      const v = val(f);
      if (v === s.def || v === '') return;
      per[s.g] = (per[s.g] || 0) + 1;
      lines.push([k, s.quote ? `'${v}'` : v]);
    });

    GROUPS.forEach(g => {
      const el = root.querySelector(`[data-ct="${g.id}"]`);
      el.textContent = per[g.id] || '';
      el.classList.toggle('has', !!per[g.id]);
    });

    const notes = [];
    const enabled = byEnv('NOVA_TWO_FACTOR_ENABLED').checked;
    const anyMethod = ['TOTP','WEBAUTHN','EMAIL'].some(m => byEnv(`NOVA_TWO_FACTOR_${m}_ENABLED`).checked);
    if (!enabled) notes.push('<b>The package is switched off.</b> A legitimate setting — a multi-domain install turns it off per domain — so <code>doctor</code> reports it valid and no test fails. An application with it set is indistinguishable from one without the package. To unblock local work, pause from the Settings page instead.');
    if (!anyMethod) notes.push('<b>Every method is disabled.</b> Refused at runtime — an enforcement policy with nothing to enrol would lock everyone out.');
    if (byEnv('NOVA_TWO_FACTOR_MODE').value === 'required' && !byEnv('NOVA_TWO_FACTOR_GRACE_ENABLED').checked)
      notes.push('<b>No grace period.</b> The enrollment wall appears at the very next request. Check <code>impact.without_factor</code> on the compliance page before deploying this.');
    if (byEnv('NOVA_TWO_FACTOR_WEBAUTHN_ENABLED').checked && !byEnv('NOVA_TWO_FACTOR_WEBAUTHN_RP_ID').value.trim())
      notes.push('The passkey relying party falls back to the <code>APP_URL</code> host. Where Nova has its own domain that is the customer site, not the panel.');
    warn.classList.toggle('hidden', !notes.length);
    warn.classList.toggle('soft', notes.length > 0 && enabled && anyMethod);
    if (notes.length) warn.querySelector('p').innerHTML = notes.join('<br><br>');

    if (!lines.length) {
      out.innerHTML = '<span class="t-cmt"># Nothing to add — everything you picked is already the default.</span>';
      count.textContent = '0 of ' + fields.length + ' changed';
      return;
    }
    const w = Math.max(...lines.map(l => l[0].length));
    out.innerHTML = '<span class="t-cmt"># Nova Two-Factor — only what differs from the defaults</span>\n' +
      lines.map(([k, v]) => `<span class="t-var">${k}</span>${' '.repeat(w - k.length)}<span class="t-op">=</span><span class="t-val">${esc(v)}</span>`).join('\n');
    count.textContent = `${lines.length} of ${fields.length} changed`;
  }

  fields.forEach(f => f.addEventListener('input', build));
  root.querySelector('#b-reset').addEventListener('click', () => {
    F.forEach(f => {
      const el = byEnv(f.env);
      if (f.t === 'bool') el.checked = f.def === 'true';
      else if (f.t === 'date' || f.t === 'text') el.value = '';
      else el.value = f.def;
    });
    build();
  });
  root.querySelector('#b-copy').addEventListener('click', async () => {
    const b = root.querySelector('#b-copy');
    try { await navigator.clipboard.writeText(out.innerText); b.textContent = 'Copied'; b.classList.add('done');
      setTimeout(() => { b.textContent = 'Copy'; b.classList.remove('done'); }, 1600); }
    catch { b.textContent = 'Press ⌘C'; }
  });
  build();
})();

/* ---------------- screens: filter, compare slider, lightbox ---------------- */
(() => {
  const gal = $('#gal'); if (!gal) return;
  $$('#gfilter .chip').forEach(c => c.addEventListener('click', () => {
    $$('#gfilter .chip').forEach(x => x.classList.remove('on'));
    c.classList.add('on');
    const f = c.dataset.g;
    $$('.gitem', gal).forEach(g => g.classList.toggle('out', f !== 'all' && g.dataset.area !== f));
  }));

  const cw = $('#cmp');
  if (cw) {
    const set = x => {
      const r = cw.getBoundingClientRect();
      const p = Math.max(0, Math.min(1, (x - r.left) / r.width));
      $('.over', cw).style.width = (p * 100) + '%';
      $('.handle', cw).style.left = (p * 100) + '%';
    };
    let down = false;
    cw.addEventListener('pointerdown', e => { down = true; cw.setPointerCapture(e.pointerId); set(e.clientX); });
    cw.addEventListener('pointermove', e => { if (down) set(e.clientX); });
    cw.addEventListener('pointerup', () => down = false);
    cw.addEventListener('pointercancel', () => down = false);
  }

  const lb = $('#lb');
  gal.addEventListener('click', e => {
    const img = e.target.closest('.fr')?.querySelector('img:not([style*="display: none"])');
    const fig = e.target.closest('.gitem'); if (!fig) return;
    const shown = [...fig.querySelectorAll('img')].find(i => getComputedStyle(i).display !== 'none');
    if (!shown) return;
    $('#lbimg').src = shown.src;
    $('#lbcap').textContent = fig.querySelector('figcaption b')?.textContent || '';
    lb.classList.remove('hidden');
  });
  lb.addEventListener('click', () => lb.classList.add('hidden'));
  addEventListener('keydown', e => { if (e.key === 'Escape') lb.classList.add('hidden'); });
})();

/* ---------------- upgrade checklist ---------------- */
(() => {
  const ck = $('#ck'); if (!ck) return;
  const boxes = $$('input[type=checkbox]', ck), fill = $('#ckfill'), pct = $('#ckpct');
  const KEY = 'n2f-upgrade';
  const load = () => { try { return JSON.parse(localStorage.getItem(KEY)) || {}; } catch { return {}; } };
  let state = load();
  function sync() {
    let done = 0;
    boxes.forEach((b, i) => {
      b.checked = !!state[i];
      b.closest('label').classList.toggle('done', b.checked);
      if (b.checked) done++;
    });
    const p = Math.round(done / boxes.length * 100);
    fill.style.width = p + '%';
    pct.textContent = `${done} of ${boxes.length} done`;
  }
  boxes.forEach((b, i) => b.addEventListener('change', () => {
    state[i] = b.checked;
    try { localStorage.setItem(KEY, JSON.stringify(state)); } catch {}
    sync();
  }));
  $('#ckreset').addEventListener('click', () => { state = {}; try { localStorage.removeItem(KEY); } catch {} sync(); });
  sync();
})();

/* ---------------- enforcement playground ---------------- */
(() => {
  const mode = $('#pl-mode'); if (!mode) return;
  const gmode = $('#pl-gmode'), days = $('#pl-days'), age = $('#pl-age'), has = $('#pl-has');
  const out = $('#pl-out'), env = $('#pl-env');
  function calc() {
    const m = mode.value, enrolled = has.value === 'yes';
    const a = +age.value, d = +days.value;
    let cls = 'ok', st = 'Allowed', h = 'Nova loads normally', p = '';
    if (enrolled) {
      p = 'The user has a factor, so enforcement has nothing to ask for. They are challenged at login and carry on.';
    } else if (m === 'optional') {
      p = 'Nothing is required and nothing is shown. The security card is still available if they go looking.';
    } else if (m === 'encouraged') {
      cls = 'warn'; st = 'Prompted'; h = 'A dismissible prompt appears';
      p = 'They may silence it for enforcement.remind_every_days. It never blocks a request.';
    } else {
      if (gmode.value === 'days' && a < d) {
        cls = 'warn'; st = 'In grace'; h = `Enrollment page with a countdown — ${d - a} day${d - a === 1 ? '' : 's'} left`;
        p = 'They may choose “Set up later”, which dismisses it for the rest of the session only.';
      } else {
        cls = 'block'; st = 'Blocked'; h = 'Nova is unreachable until they enrol';
        p = gmode.value === 'date'
          ? 'grace_mode is date, so the shared deadline in enforced_from applies to everyone at once.'
          : 'The account is past its own grace window.';
      }
    }
    out.className = 'verdict ' + cls;
    out.innerHTML = `<div class="st">${st}</div><h4>${h}</h4><p>${p}</p>`;
    const lines = [`NOVA_TWO_FACTOR_MODE=${mode.value}`];
    if (mode.value === 'required') {
      lines.push(`NOVA_TWO_FACTOR_GRACE_MODE=${gmode.value}`);
      if (gmode.value === 'days') lines.push(`NOVA_TWO_FACTOR_GRACE_DAYS=${days.value}`);
      else lines.push(`NOVA_TWO_FACTOR_ENFORCED_FROM=2026-10-01`);
    }
    env.textContent = lines.join('\n');
  }
  [mode, gmode, days, age, has].forEach(el => el.addEventListener('input', calc));
  calc();
})();

/* ---------------- home: reveals, counters, marquee, OTP, tilt ---------------- */
(() => {
  const io = new IntersectionObserver(es => es.forEach(e => {
    if (e.isIntersecting) { e.target.classList.add('in'); io.unobserve(e.target); }
  }), {threshold: .12, rootMargin: '0px 0px -40px'});
  $$('.rv').forEach((el, i) => { el.style.transitionDelay = (i % 4 * 70) + 'ms'; io.observe(el); });

  const cio = new IntersectionObserver(es => es.forEach(e => {
    if (!e.isIntersecting) return;
    const el = e.target, to = +el.dataset.to; cio.unobserve(el);
    if (reduce || to === 0) { el.textContent = to; return; }
    const t0 = performance.now();
    const tick = t => {
      const p = Math.min(1, (t - t0) / 1100);
      el.textContent = Math.round(to * (1 - Math.pow(1 - p, 3)));
      if (p < 1) requestAnimationFrame(tick);
    };
    requestAnimationFrame(tick);
  }), {threshold: .5});
  $$('[data-to]').forEach(el => cio.observe(el));

  const items = ['Passkeys / WebAuthn','Authenticator apps','Email one-time codes','Recovery codes','Enforcement with grace',
    'Step-up re-auth','Trusted devices','Audit trail','Compliance reporting','Per-role targeting'];
  const mq = $('#mq'); if (mq) mq.innerHTML = [...items, ...items].map(s => `<span><b>◆</b> ${s}</span>`).join('');

  const demo = $('#demo');
  if (demo) {
    const cells = [...$('#cells').children], typed = $('#typed'), stat = $('#stat'), clock = $('#clock');
    let val = '', timer = null, auto = true;
    const paint = () => {
      cells.forEach((c, i) => {
        c.textContent = val[i] || '';
        c.classList.toggle('on', i < val.length);
        c.classList.toggle('cur', i === val.length && val.length < 6);
      });
      demo.classList.toggle('ok', val.length === 6);
      stat.textContent = val.length === 6 ? '✓ verified — session cleared'
        : val.length ? `${val.length} / 6 entered` : 'awaiting input';
    };
    demo.addEventListener('click', () => { auto = false; clearTimeout(timer); typed.focus(); });
    typed.addEventListener('input', () => { val = typed.value.replace(/\D/g, '').slice(0, 6); paint(); });
    typed.addEventListener('blur', () => cells.forEach(c => c.classList.remove('cur')));
    const run = () => {
      if (!auto) return;
      const code = String(Math.floor(100000 + Math.random() * 900000));
      val = ''; paint();
      let i = 0;
      const step = () => {
        if (!auto) return;
        val = code.slice(0, ++i); paint();
        timer = i < 6 ? setTimeout(step, 190)
          : setTimeout(() => { if (auto) { val = ''; paint(); timer = setTimeout(run, 900); } }, 2100);
      };
      timer = setTimeout(step, 700);
    };
    if (!reduce) run(); else { val = '402971'; paint(); }
    let s = 30;
    setInterval(() => { s = s <= 0 ? 30 : s - 1; clock.textContent = '00:' + String(s).padStart(2, '0'); }, 1000);
  }

  if (!reduce && matchMedia('(pointer:fine)').matches) {
    $$('.tilt').forEach(el => {
      el.addEventListener('pointermove', e => {
        const r = el.getBoundingClientRect();
        const x = (e.clientX - r.left) / r.width - .5, y = (e.clientY - r.top) / r.height - .5;
        el.style.transform = `rotateY(${x * 7}deg) rotateX(${-y * 7}deg) translateY(-5px) scale(1.015)`;
      });
      el.addEventListener('pointerleave', () => el.style.transform = '');
    });
  }
})();

/* ---------------- canvas verification field ---------------- */
function field(cv, {fixed = false} = {}) {
  if (!cv || reduce) return;
  const ctx = cv.getContext('2d');
  let w, h, dots = [], dpr = Math.min(devicePixelRatio || 1, 2), t0 = performance.now();
  const GAP = fixed ? 34 : 30;
  function build() {
    w = cv.clientWidth; h = cv.clientHeight;
    cv.width = w * dpr; cv.height = h * dpr; ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    dots = [];
    for (let y = GAP / 2; y < h; y += GAP) for (let x = GAP / 2; x < w; x += GAP) dots.push({x, y, p: Math.random() * 6.28});
  }
  build(); addEventListener('resize', build);
  let mx = -999, my = -999;
  const host = fixed ? window : cv.parentElement;
  host.addEventListener('pointermove', e => {
    if (fixed) { mx = e.clientX; my = e.clientY; }
    else { const r = cv.getBoundingClientRect(); mx = e.clientX - r.left; my = e.clientY - r.top; }
  }, {passive: true});
  (function frame(t) {
    const el = (t - t0) / 1000, st = getComputedStyle(document.documentElement);
    const base = st.getPropertyValue('--dot').trim(), acc = st.getPropertyValue('--ac').trim();
    ctx.clearRect(0, 0, w, h);
    const R = fixed ? (el * 210) % (Math.hypot(w, h) + 420) : (el * 190) % (w + 300);
    for (const d of dots) {
      const wave = fixed
        ? Math.max(0, 1 - Math.abs(Math.hypot(d.x - w * .5, d.y + 60) - R) / 115)
        : Math.max(0, 1 - Math.abs(d.x - R) / 90);
      const near = Math.max(0, 1 - Math.hypot(d.x - mx, d.y - my) / (fixed ? 150 : 130));
      const k = Math.min(1, wave * (fixed ? .9 : .75) + near);
      ctx.beginPath();
      ctx.arc(d.x, d.y, Math.max(.4, (fixed ? .9 : .85) + k * (fixed ? 2.1 : 1.9) + Math.sin(el * 1.6 + d.p) * .2), 0, 6.283);
      ctx.fillStyle = k > .02 ? acc : base;
      ctx.globalAlpha = k > .02 ? .24 + k * .58 : .28;
      ctx.fill();
    }
    ctx.globalAlpha = 1;
    requestAnimationFrame(frame);
  })(performance.now());
}
field($('#field'), {fixed: true});
$$('.band canvas').forEach(c => field(c));

go();
