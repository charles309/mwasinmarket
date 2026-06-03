<?php
declare(strict_types=1);

/**
 * MwasinMarket — Admin SPA (vanilla PHP shell, vanilla JS).
 * Hash-routed (#dashboard, #markets, #market/:id, ...). All admin
 * functions documented in cURL_Reference.md are reachable from here.
 *
 * XSS hardening: no dynamic data ever goes through innerHTML. The
 * el() helper in this file sets text via textContent and unknown
 * attributes via setAttribute(), so nothing user-supplied is
 * interpreted as markup. A strict CSP is set in headers below.
 *
 * Admin login (create via SQL — password is bcrypt cost-12 of
 * "Mwangi254."; replace the hash below with your own if you regenerate):
 *
 *   INSERT INTO users (username, email, phone, full_name, password_hash, role, verified, email_verified, created_at, updated_at)
 *   VALUES ('mwangi', 'charlesdevmail@gmail.com', '+254700000001', 'Mwangi',
 *           '$2y$12$VWA/JvU7AbV8L6/McVT9W.6NKGF.GV.XicJ8h8.gvNhXSI8E/F6P.',
 *           'admin', 1, 1, NOW(), NOW());
 */

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'");
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>MwasinMarket — Admin</title>
<style>
  :root {
    /* Neutral foundation — what most of the UI is. */
    --bg:        #f6f7fb;
    --card:      #ffffff;
    --text:      #131720;
    --muted:     #6b7280;
    --border:    #e6e8ee;
    --soft:      #f1f3f7;

    /* Primary action — indigo. The workhorse colour. */
    --p:         #4f46e5;
    --p-dark:    #4338ca;
    --p-soft:    #eef2ff;

    /* Brand accent — your orange. Used sparingly: logo, the
       "primary CTA" surface highlight, and select badges. */
    --o:         #f97316;
    --o-dark:    #c2410c;
    --o-soft:    #fff4ec;

    /* Status colours. */
    --success:      #16a34a;
    --success-soft: #dcfce7;
    --success-dark: #15803d;
    --warning:      #f59e0b;
    --warning-soft: #fef3c7;
    --warning-dark: #b45309;
    --danger:       #ef4444;
    --danger-soft:  #fee2e2;
    --danger-dark:  #b91c1c;

    --radius: 14px;
    --shadow-sm: 0 1px 2px rgba(13, 18, 30, 0.04), 0 1px 3px rgba(13, 18, 30, 0.05);
    --shadow:    0 10px 30px rgba(13, 18, 30, 0.07);
  }

  * { box-sizing: border-box; }
  html, body { margin: 0; padding: 0; }
  body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Inter, system-ui, sans-serif;
    background: var(--bg);
    color: var(--text);
    font-size: 15px;
    line-height: 1.5;
    -webkit-font-smoothing: antialiased;
  }
  a { color: var(--p); text-decoration: none; }
  a:hover { text-decoration: underline; }

  /* Layout */
  .layout { display: flex; min-height: 100vh; }
  .sidebar {
    width: 240px;
    background: var(--card);
    border-right: 1px solid var(--border);
    padding: 18px 14px;
    position: fixed; top: 0; bottom: 0; left: 0;
    overflow-y: auto;
  }
  .main { flex: 1; margin-left: 240px; padding: 28px; }
  .main-inner { max-width: 1200px; margin: 0 auto; }
  @media (max-width: 800px) {
    .sidebar { position: static; width: 100%; height: auto; }
    .main { margin-left: 0; padding: 18px; }
  }

  /* Brand + nav */
  .brand {
    display: flex; align-items: center; gap: 10px;
    padding: 6px 8px 14px;
    border-bottom: 1px solid var(--border);
    margin-bottom: 8px;
  }
  .brand-mark {
    width: 34px; height: 34px;
    background: var(--o);
    color: white;
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-weight: 900; font-size: 18px;
    box-shadow: 0 3px 0 var(--o-dark);
  }
  .brand-name { font-weight: 800; font-size: 16px; letter-spacing: -0.01em; }
  .brand-name .accent { color: var(--o); }
  .nav-section {
    font-size: 11px;
    text-transform: uppercase;
    color: var(--muted);
    padding: 14px 10px 6px;
    letter-spacing: 0.06em;
    font-weight: 700;
  }
  .nav-link {
    display: flex; align-items: center; gap: 10px;
    padding: 9px 10px;
    border-radius: 10px;
    color: var(--text);
    text-decoration: none;
    font-weight: 600;
    font-size: 14px;
    transition: background 0.15s, color 0.15s;
  }
  .nav-link:hover { background: var(--soft); text-decoration: none; }
  .nav-link.active {
    background: var(--p-soft);
    color: var(--p);
  }
  .nav-link .ic { font-size: 16px; width: 20px; text-align: center; }

  .sidebar-footer {
    margin-top: 18px;
    padding: 12px 10px;
    border-top: 1px solid var(--border);
    font-size: 12px;
    color: var(--muted);
  }
  .sidebar-footer .who { font-weight: 700; color: var(--text); margin-bottom: 6px; }

  /* Cards */
  .card {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 20px;
    box-shadow: var(--shadow-sm);
    margin-bottom: 16px;
  }
  .card.thin { padding: 14px; }

  /* Headings */
  h1 { font-size: 24px; font-weight: 800; margin: 0 0 4px; letter-spacing: -0.01em; }
  h2 { font-size: 17px; font-weight: 700; margin: 0 0 12px; letter-spacing: -0.005em; }
  h3 { font-size: 14px; font-weight: 700; margin: 0 0 8px; color: var(--muted); text-transform: uppercase; letter-spacing: 0.05em; }
  .page-head { margin-bottom: 22px; }
  .page-sub { color: var(--muted); font-size: 14px; }

  /* Soft-3D buttons (Duolingo-style) */
  .btn {
    display: inline-flex; align-items: center; justify-content: center; gap: 6px;
    padding: 10px 18px;
    border: none; border-radius: 12px;
    font-weight: 700; font-size: 14px;
    cursor: pointer;
    text-decoration: none;
    transition: transform 0.05s ease, filter 0.15s ease;
    user-select: none;
  }
  .btn:hover { text-decoration: none; }
  .btn:active { transform: translateY(2px); }
  .btn:disabled, .btn[disabled] { opacity: 0.5; cursor: not-allowed; }

  .btn-primary {
    background: var(--o);
    color: white;
    box-shadow: 0 4px 0 var(--o-dark);
  }
  .btn-primary:hover { filter: brightness(1.04); }
  .btn-secondary {
    background: white;
    color: var(--text);
    border: 1px solid var(--border);
    box-shadow: 0 3px 0 var(--border);
  }
  .btn-secondary:hover { background: #fafbfc; }
  .btn-indigo {
    background: var(--p);
    color: white;
    box-shadow: 0 4px 0 var(--p-dark);
  }
  .btn-indigo:hover { filter: brightness(1.04); }
  .btn-success { background: var(--success); color: white; box-shadow: 0 4px 0 var(--success-dark); }
  .btn-success:hover { filter: brightness(1.04); }
  .btn-warning { background: var(--warning); color: white; box-shadow: 0 4px 0 var(--warning-dark); }
  .btn-warning:hover { filter: brightness(1.04); }
  .btn-danger  { background: var(--danger);  color: white; box-shadow: 0 4px 0 var(--danger-dark); }
  .btn-danger:hover { filter: brightness(1.04); }

  .btn-sm { padding: 6px 12px; font-size: 13px; border-radius: 10px; box-shadow-offset: 3px; }
  .btn-block { width: 100%; }
  .btn-icon { padding: 8px 10px; }

  /* Forms */
  .field { margin-bottom: 14px; }
  .field > label, label.label { display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px; color: var(--text); }
  .input, .select, .textarea {
    width: 100%;
    padding: 10px 12px;
    border: 1.5px solid var(--border);
    border-radius: 10px;
    font-size: 14px;
    font-family: inherit;
    background: white;
    color: var(--text);
    outline: none;
    transition: border-color 0.15s, box-shadow 0.15s;
  }
  .input:focus, .select:focus, .textarea:focus {
    border-color: var(--p);
    box-shadow: 0 0 0 3px var(--p-soft);
  }
  .textarea { min-height: 100px; resize: vertical; }
  .check { display: inline-flex; align-items: center; gap: 8px; font-size: 14px; cursor: pointer; }
  .check input { width: 16px; height: 16px; }

  /* Tables */
  .table { width: 100%; border-collapse: collapse; }
  .table th, .table td {
    text-align: left;
    padding: 12px 10px;
    border-bottom: 1px solid var(--border);
    font-size: 14px;
    vertical-align: middle;
  }
  .table th {
    font-weight: 700;
    color: var(--muted);
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    background: var(--soft);
  }
  .table tr:hover td { background: #fafbfc; }
  .table-wrap { overflow-x: auto; }

  /* Badges */
  .badge {
    display: inline-block;
    padding: 3px 9px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 700;
    line-height: 1.5;
    letter-spacing: 0.02em;
    text-transform: uppercase;
  }
  .badge-success { background: var(--success-soft); color: var(--success-dark); }
  .badge-warning { background: var(--warning-soft); color: var(--warning-dark); }
  .badge-danger  { background: var(--danger-soft);  color: var(--danger-dark); }
  .badge-primary { background: var(--p-soft);       color: var(--p); }
  .badge-accent  { background: var(--o-soft);       color: var(--o-dark); }
  .badge-muted   { background: var(--soft);         color: var(--muted); }

  /* Stats grid */
  .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; margin-bottom: 22px; }
  .stat-card {
    background: white;
    padding: 16px;
    border-radius: var(--radius);
    border: 1px solid var(--border);
    box-shadow: var(--shadow-sm);
  }
  .stat-label { font-size: 11px; color: var(--muted); text-transform: uppercase; letter-spacing: 0.06em; font-weight: 700; }
  .stat-value { font-size: 26px; font-weight: 800; margin-top: 4px; letter-spacing: -0.02em; }
  .stat-meta { font-size: 12px; color: var(--muted); margin-top: 4px; }
  .stat-card.accent { background: var(--o-soft); border-color: var(--o-soft); }
  .stat-card.accent .stat-value { color: var(--o-dark); }

  /* Toasts */
  #toasts {
    position: fixed; top: 18px; right: 18px;
    z-index: 9999;
    display: flex; flex-direction: column; gap: 8px;
    max-width: 360px;
  }
  .toast {
    background: white;
    border: 1px solid var(--border);
    padding: 12px 14px;
    border-radius: 12px;
    box-shadow: var(--shadow);
    font-size: 14px;
    animation: slidein 0.22s ease;
  }
  .toast.success { border-left: 4px solid var(--success); }
  .toast.error   { border-left: 4px solid var(--danger); }
  @keyframes slidein {
    from { transform: translateX(20px); opacity: 0; }
    to   { transform: translateX(0);    opacity: 1; }
  }

  /* Modals */
  .modal-bg {
    position: fixed; inset: 0;
    background: rgba(13, 18, 30, 0.55);
    z-index: 100;
    display: flex; align-items: center; justify-content: center;
    animation: fadein 0.15s ease;
  }
  @keyframes fadein { from { opacity: 0; } to { opacity: 1; } }
  .modal {
    background: white;
    padding: 22px;
    border-radius: 16px;
    max-width: 560px;
    width: 92%;
    max-height: 86vh;
    overflow-y: auto;
    box-shadow: 0 20px 60px rgba(13, 18, 30, 0.3);
  }
  .modal-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; }
  .modal-close {
    background: var(--soft); border: none;
    width: 32px; height: 32px; border-radius: 10px;
    cursor: pointer; font-size: 18px; color: var(--muted);
    display: flex; align-items: center; justify-content: center;
  }
  .modal-close:hover { background: var(--border); }

  /* Login */
  .login-wrap { display: flex; min-height: 100vh; align-items: center; justify-content: center; padding: 20px; background: var(--bg); }
  .login-card {
    width: 100%;
    max-width: 420px;
    padding: 32px;
    box-shadow: var(--shadow);
  }
  .login-brand {
    display: flex; align-items: center; justify-content: center;
    gap: 10px;
    margin-bottom: 18px;
  }
  .login-brand .brand-mark { width: 44px; height: 44px; font-size: 22px; }
  .login-card .sub { text-align: center; color: var(--muted); margin: 0 0 22px; font-size: 14px; }

  /* Misc helpers */
  .row { display: flex; gap: 12px; flex-wrap: wrap; }
  .row > * { margin: 0; }
  .col { flex: 1; min-width: 200px; }
  .mt-1 { margin-top: 4px; }
  .mt-2 { margin-top: 8px; }
  .mt-3 { margin-top: 12px; }
  .mt-4 { margin-top: 16px; }
  .mb-2 { margin-bottom: 8px; }
  .text-muted { color: var(--muted); }
  .text-success { color: var(--success); }
  .text-danger  { color: var(--danger); }
  .text-accent  { color: var(--o); }
  .text-right { text-align: right; }
  .flex-between { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
  .gap-2 { gap: 8px; }
  .hidden { display: none !important; }
  .mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 13px; }
  pre.json {
    background: #1a1d2b;
    color: #e4e6f0;
    padding: 12px;
    border-radius: 10px;
    overflow-x: auto;
    font-size: 12px;
    max-height: 200px;
    margin: 0;
  }
  .empty {
    padding: 28px;
    text-align: center;
    color: var(--muted);
  }
  .chat-list { max-height: 380px; overflow-y: auto; padding: 8px; background: var(--bg); border-radius: 12px; }
  .chat-msg { margin: 6px 0; max-width: 84%; padding: 9px 13px; border-radius: 14px; background: white; border: 1px solid var(--border); }
  .chat-msg .meta { font-size: 11px; color: var(--muted); margin-bottom: 3px; }
  .chat-msg.from-admin { margin-left: auto; background: var(--p-soft); border-color: var(--p-soft); }
  .sticker-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(110px, 1fr)); gap: 10px; margin-top: 12px; }
  .sticker-tile { border: 1px solid var(--border); padding: 10px; border-radius: 12px; text-align: center; background: white; }
  .sticker-tile img { width: 56px; height: 56px; object-fit: contain; }
  .sticker-tile .name { font-size: 12px; font-weight: 600; margin-top: 4px; word-break: break-word; }
</style>
</head>
<body>
<div id="root"></div>
<div id="toasts"></div>
<script>
(function () {
  'use strict';

  // ============================================================
  // CONFIG
  // ============================================================
  // admin.php sits beside api.php — relative URL keeps it portable.
  const API_BASE = 'api.php';
  const STORAGE_KEY = 'mwasin_admin_token';
  let TOKEN = localStorage.getItem(STORAGE_KEY) || '';
  let CURRENT_USER = JSON.parse(localStorage.getItem(STORAGE_KEY + ':user') || 'null');

  // ============================================================
  // SAFE DOM HELPERS — never use innerHTML for dynamic data.
  // ============================================================
  function el(tag, props) {
    const e = document.createElement(tag);
    if (props && typeof props === 'object') {
      for (const k in props) {
        const v = props[k];
        if (v === null || v === undefined || v === false) continue;
        if (k === 'class')          e.className = String(v);
        else if (k === 'text')      e.textContent = String(v);
        else if (k === 'style' && typeof v === 'object') Object.assign(e.style, v);
        else if (k === 'attrs' && typeof v === 'object') {
          for (const ak in v) e.setAttribute(ak, String(v[ak]));
        }
        else if (k.startsWith('on') && typeof v === 'function') {
          e.addEventListener(k.slice(2).toLowerCase(), v);
        }
        else if (k === 'checked' || k === 'disabled' || k === 'required' || k === 'autofocus') {
          if (v) e[k] = true;
        }
        else if (k === 'value')     e.value = String(v);
        else if (k === 'type')      e.type = String(v);
        else                        e.setAttribute(k, String(v));
      }
    }
    for (let i = 2; i < arguments.length; i++) {
      const c = arguments[i];
      if (c === null || c === undefined || c === false) continue;
      if (Array.isArray(c)) {
        for (const cc of c) {
          if (cc === null || cc === undefined || cc === false) continue;
          e.appendChild(cc instanceof Node ? cc : document.createTextNode(String(cc)));
        }
      } else if (c instanceof Node) {
        e.appendChild(c);
      } else {
        e.appendChild(document.createTextNode(String(c)));
      }
    }
    return e;
  }
  function clear(n) { while (n.firstChild) n.removeChild(n.firstChild); return n; }
  function $(sel) { return document.querySelector(sel); }

  // ============================================================
  // API CLIENT
  // ============================================================
  async function api(routeName, opts) {
    opts = opts || {};
    const url = API_BASE + '?route=' + encodeURIComponent(routeName) + (opts.query ? '&' + opts.query : '');
    const headers = {};
    if (TOKEN) headers['Authorization'] = 'Bearer ' + TOKEN;
    if (!opts.formData) headers['Content-Type'] = 'application/json';
    const init = { method: opts.method || 'GET', headers, credentials: 'omit' };
    if (opts.body)     init.body = JSON.stringify(opts.body);
    if (opts.formData) init.body = opts.formData;
    let resp, data;
    try {
      resp = await fetch(url, init);
      data = await resp.json();
    } catch (e) {
      throw new Error('Network error: ' + e.message);
    }
    if (!resp.ok || !data || data.success !== true) {
      const err = new Error((data && data.error) || ('HTTP ' + resp.status));
      err.status = resp.status;
      err.details = data && data.details;
      err.payload = data;
      throw err;
    }
    return data;
  }

  // ============================================================
  // UI HELPERS
  // ============================================================
  function toast(msg, type) {
    type = type || 'success';
    const t = el('div', { class: 'toast ' + type, text: msg });
    document.getElementById('toasts').appendChild(t);
    setTimeout(function () { t.remove(); }, 4200);
  }

  function modal(title, contentNode) {
    const bg = el('div', { class: 'modal-bg' });
    const close = function () { bg.remove(); };
    bg.addEventListener('click', function (e) { if (e.target === bg) close(); });
    document.addEventListener('keydown', function esc(e) {
      if (e.key === 'Escape') { close(); document.removeEventListener('keydown', esc); }
    });
    const m = el('div', { class: 'modal' },
      el('div', { class: 'modal-head' },
        el('h2', { text: title }),
        el('button', { class: 'modal-close', onclick: close, text: '×' })
      ),
      contentNode
    );
    bg.appendChild(m);
    document.body.appendChild(bg);
    return { close: close, root: bg };
  }

  function confirmDialog(msg, onYes, opts) {
    opts = opts || {};
    let m;
    const body = el('div', null,
      el('p', { text: msg, style: { marginTop: 0 } }),
      el('div', { class: 'row', style: { justifyContent: 'flex-end', marginTop: '16px' } },
        el('button', { class: 'btn btn-secondary', onclick: function () { m.close(); }, text: opts.cancelLabel || 'Cancel' }),
        el('button', { class: 'btn ' + (opts.danger ? 'btn-danger' : 'btn-primary'),
                       onclick: function () { m.close(); onYes(); }, text: opts.confirmLabel || 'Confirm' })
      )
    );
    m = modal(opts.title || 'Are you sure?', body);
  }

  function field(labelText, input) {
    return el('div', { class: 'field' }, el('label', { class: 'label', text: labelText }), input);
  }
  function selectFrom(name, options, value) {
    const s = el('select', { class: 'select', name: name || '' });
    for (const opt of options) {
      const o = el('option', { value: opt, text: opt });
      if (opt === value) o.selected = true;
      s.appendChild(o);
    }
    return s;
  }

  function statusBadge(status) {
    if (!status) return el('span', { class: 'badge badge-muted', text: '-' });
    const map = {
      open: 'success', paused: 'warning', closed: 'muted', resolved: 'primary', voided: 'danger',
      pending: 'warning', completed: 'success', failed: 'danger', rejected: 'danger',
      approved: 'success', answered: 'primary', sent: 'success', queued: 'muted',
      processing: 'warning', cancelled: 'muted'
    };
    return el('span', { class: 'badge badge-' + (map[status] || 'muted'), text: status });
  }

  function fmtKes(n) {
    const num = Number(n);
    if (!isFinite(num)) return 'KES 0.00';
    return 'KES ' + num.toLocaleString('en-KE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }
  function fmtDate(s) {
    if (!s) return '-';
    try { return new Date(String(s).replace(' ', 'T')).toLocaleString(); } catch (e) { return String(s); }
  }
  function fmtBool(b) { return b ? '✓' : '—'; }

  function tableFrom(rows, columns) {
    if (!rows || rows.length === 0) return el('div', { class: 'empty', text: 'Nothing here yet.' });
    const wrap = el('div', { class: 'table-wrap' });
    const tbl = el('table', { class: 'table' });
    const thead = el('thead', null,
      el('tr', null, columns.map(function (c) { return el('th', { text: c.label }); }))
    );
    const tbody = el('tbody');
    for (const r of rows) {
      const tr = el('tr');
      for (const c of columns) {
        const raw = r[c.k];
        const v = c.fmt ? c.fmt(raw, r) : raw;
        if (v instanceof Node) tr.appendChild(el('td', null, v));
        else tr.appendChild(el('td', { text: v === null || v === undefined || v === '' ? '-' : String(v) }));
      }
      tbody.appendChild(tr);
    }
    tbl.appendChild(thead); tbl.appendChild(tbody);
    wrap.appendChild(tbl);
    return wrap;
  }

  // ============================================================
  // ROUTING (hash-based SPA)
  // ============================================================
  const routes = {};
  function route(path, handler) { routes[path] = handler; }
  function go(hash) { window.location.hash = hash.charAt(0) === '#' ? hash : '#' + hash; }
  function currentHash() { return (window.location.hash || '#dashboard').replace(/^#/, ''); }

  async function render() {
    const root = $('#root');
    if (!TOKEN) { renderLogin(root); return; }
    const hash = currentHash();
    const parts = hash.split('/');
    const handler = routes[parts[0]] || routes['dashboard'];
    clear(root);
    root.appendChild(renderLayout());
    const main = $('.main-inner');
    if (!main) return;
    main.appendChild(el('div', { id: 'loader', class: 'text-muted', text: 'Loading…' }));
    try {
      await handler(main, parts.slice(1));
      const loader = $('#loader'); if (loader) loader.remove();
    } catch (e) {
      const loader = $('#loader'); if (loader) loader.remove();
      if (e.status === 401 || e.status === 403) {
        toast(e.message || 'Session expired', 'error');
        logout();
        return;
      }
      main.appendChild(el('div', { class: 'card' },
        el('h2', { text: 'Could not load this page' }),
        el('div', { class: 'text-danger', text: e.message || 'Unknown error' })
      ));
    }
  }

  window.addEventListener('hashchange', render);

  // ============================================================
  // LAYOUT
  // ============================================================
  function navItem(hash, label, icon) {
    const active = currentHash().split('/')[0] === hash;
    return el('a', { href: '#' + hash, class: 'nav-link' + (active ? ' active' : '') },
      el('span', { class: 'ic', text: icon }),
      el('span', { text: label })
    );
  }

  function renderLayout() {
    const userName = (CURRENT_USER && CURRENT_USER.username) || 'admin';
    const sidebar = el('aside', { class: 'sidebar' },
      el('div', { class: 'brand' },
        el('div', { class: 'brand-mark', text: 'M' }),
        el('div', { class: 'brand-name' },
          'Mwasin',
          el('span', { class: 'accent', text: 'Market' })
        )
      ),
      el('div', { class: 'nav-section', text: 'Overview' }),
      navItem('dashboard',     'Dashboard',         '📊'),
      navItem('notifications', 'Notifications',     '🔔'),

      el('div', { class: 'nav-section', text: 'Markets' }),
      navItem('markets',       'All markets',       '🎲'),
      navItem('create-market', 'Create market',     '➕'),

      el('div', { class: 'nav-section', text: 'Money' }),
      navItem('withdrawals',   'Withdrawals',       '💸'),
      navItem('claims',        'Manual claims',     '🧾'),
      navItem('payments',      'Payment report',    '💹'),

      el('div', { class: 'nav-section', text: 'Users' }),
      navItem('users',         'User actions',      '👥'),
      navItem('support',       'Support tickets',   '💬'),

      el('div', { class: 'nav-section', text: 'Content' }),
      navItem('stickers',      'Sticker packs',     '🎨'),
      navItem('email',         'Email & broadcast', '✉️'),

      el('div', { class: 'nav-section', text: 'System' }),
      navItem('settings',      'Settings',          '⚙️'),

      el('div', { class: 'sidebar-footer' },
        el('div', { class: 'who', text: userName }),
        el('button', { class: 'btn btn-secondary btn-sm', onclick: logout, text: 'Sign out' })
      )
    );
    const main = el('div', { class: 'main' }, el('div', { class: 'main-inner' }));
    return el('div', { class: 'layout' }, sidebar, main);
  }

  // ============================================================
  // LOGIN
  // ============================================================
  function renderLogin(root) {
    clear(root);
    const ident = el('input', { class: 'input', name: 'identifier', autocomplete: 'username', required: true, autofocus: true });
    const pass  = el('input', { class: 'input', type: 'password', name: 'password', autocomplete: 'current-password', required: true });
    const btn   = el('button', { class: 'btn btn-primary btn-block', type: 'submit', text: 'Sign in' });

    const card = el('form', { class: 'card login-card', autocomplete: 'on', onsubmit: async function (e) {
      e.preventDefault();
      btn.disabled = true; btn.textContent = 'Signing in…';
      try {
        const data = await api('admin_login', { method: 'POST', body: {
          identifier: ident.value.trim(),
          password:   pass.value
        }});
        TOKEN = data.data.token;
        CURRENT_USER = { user_id: data.data.user_id, username: data.data.username, role: data.data.role };
        localStorage.setItem(STORAGE_KEY, TOKEN);
        localStorage.setItem(STORAGE_KEY + ':user', JSON.stringify(CURRENT_USER));
        go('dashboard');
        render();
      } catch (err) {
        toast(err.message, 'error');
        btn.disabled = false; btn.textContent = 'Sign in';
      }
    }},
      el('div', { class: 'login-brand' },
        el('div', { class: 'brand-mark', text: 'M' }),
        el('div', { class: 'brand-name', style: { fontSize: '22px' } }, 'Mwasin', el('span', { class: 'accent', text: 'Market' }))
      ),
      el('p', { class: 'sub', text: 'Admin sign in' }),
      field('Email or username', ident),
      field('Password', pass),
      btn
    );
    root.appendChild(el('div', { class: 'login-wrap' }, card));
  }

  function logout() {
    localStorage.removeItem(STORAGE_KEY);
    localStorage.removeItem(STORAGE_KEY + ':user');
    TOKEN = ''; CURRENT_USER = null;
    if (currentHash() !== 'login') go('dashboard');
    render();
  }

  // ============================================================
  // PAGE: DASHBOARD
  // ============================================================
  route('dashboard', async function (main) {
    clear(main);
    main.appendChild(el('div', { class: 'page-head' },
      el('h1', { text: 'Dashboard' }),
      el('div', { class: 'page-sub', text: 'Live snapshot of users, markets, and money.' })
    ));

    const stats = await api('admin_stats');
    const d = stats.data;

    const sc = function (label, value, meta, accent) {
      return el('div', { class: 'stat-card' + (accent ? ' accent' : '') },
        el('div', { class: 'stat-label', text: label }),
        el('div', { class: 'stat-value', text: String(value) }),
        meta ? el('div', { class: 'stat-meta', text: meta }) : null
      );
    };

    const grid = el('div', { class: 'stats' },
      sc('Total users',      d.users.total,                       (d.users.active_today || 0) + ' active today'),
      sc('Open markets',     d.markets.open,                      d.markets.total + ' total'),
      sc('Total wagered',    fmtKes(d.financials.total_wagered),  d.financials.total_bets + ' bets'),
      sc('House profit',     fmtKes(d.financials.house_profit),   'Payouts: ' + fmtKes(d.financials.total_payouts), true),
      sc('Bets today',       d.financials.bets_today,             fmtKes(d.financials.wagered_today)),
      sc('Verified users',   d.users.verified,                    d.users.suspended + ' suspended')
    );
    main.appendChild(grid);

    // Live exposure across every open market (cash only).
    if (d.exposure) {
      const ex = d.exposure;
      const net = ex.net_if_worst_case;
      const netNode = el('span', { class: net >= 0 ? 'text-success' : 'text-danger',
                                    text: (net >= 0 ? '+' : '−') + fmtKes(Math.abs(net)) });
      const exGrid = el('div', { class: 'stats' },
        sc('Open cash stake',    fmtKes(ex.open_stake),          'Across ' + ex.markets_with_open_book + ' open markets'),
        sc('Max liability',      fmtKes(ex.max_liability_total), 'If every worst outcome wins'),
        el('div', { class: 'stat-card accent' },
          el('div', { class: 'stat-label', text: 'Worst-case house P/L' }),
          el('div', { class: 'stat-value' }, netNode),
          el('div', { class: 'stat-meta', text: 'Cash only · bonus excluded' })
        )
      );
      main.appendChild(el('div', { class: 'card' },
        el('h2', { text: 'Live exposure (open book)' }),
        exGrid
      ));
    }

    main.appendChild(el('div', { class: 'card' },
      el('h2', { text: 'Top bettors' }),
      tableFrom(d.top_bettors || [], [
        { k: 'username',      label: 'User' },
        { k: 'total_wagered', label: 'Wagered', fmt: fmtKes },
        { k: 'total_wins',    label: 'Won',     fmt: fmtKes },
        { k: 'bet_count',     label: 'Bets' }
      ])
    ));
  });

  // ============================================================
  // PAGE: MARKETS LIST
  // ============================================================
  route('markets', async function (main) {
    clear(main);
    main.appendChild(el('div', { class: 'page-head flex-between' },
      el('div', null,
        el('h1', { text: 'Markets' }),
        el('div', { class: 'page-sub', text: 'Pause, settle, void, feature, edit.' })
      ),
      el('a', { href: '#create-market', class: 'btn btn-primary', text: '+ Create market' })
    ));

    const statusSel = selectFrom('status', ['all','active','open','paused','closed','resolved','voided'], 'all');
    const search    = el('input', { class: 'input', placeholder: 'Search question / title…' });
    const refresh   = el('button', { class: 'btn btn-secondary', text: 'Refresh' });
    const filters = el('div', { class: 'card thin' },
      el('div', { class: 'row', style: { alignItems: 'flex-end' } },
        el('div', { style: { minWidth: '160px' } }, field('Status', statusSel)),
        el('div', { style: { flex: 1, minWidth: '220px' } }, field('Search', search)),
        el('div', null, refresh)
      )
    );
    main.appendChild(filters);

    const list = el('div', { class: 'card' });
    main.appendChild(list);

    async function load() {
      clear(list);
      list.appendChild(el('div', { class: 'text-muted', text: 'Loading…' }));
      const q = new URLSearchParams();
      q.set('status', statusSel.value);
      q.set('include_resolved', '1');
      q.set('include_archived', '1');
      q.set('limit', '50');
      if (search.value.trim()) q.set('q', search.value.trim());
      const data = await api('markets', { query: q.toString() });
      clear(list);
      const rows = data.data.map(function (m) {
        return {
          market_id: m.market_id,
          question:  m.question,
          category:  m.category,
          status:    statusBadge(m.status),
          feat:      m.is_featured ? el('span', { class: 'badge badge-accent', text: 'featured' }) : '-',
          close_time: fmtDate(m.close_time),
          wagered:   fmtKes(m.total_wagered),
          bets:      m.total_bets,
          actions:   el('a', { href: '#market/' + m.market_id, class: 'btn btn-secondary btn-sm', text: 'Manage' })
        };
      });
      list.appendChild(tableFrom(rows, [
        { k: 'market_id', label: 'ID' },
        { k: 'question',  label: 'Question' },
        { k: 'category',  label: 'Category' },
        { k: 'status',    label: 'Status' },
        { k: 'feat',      label: '' },
        { k: 'close_time', label: 'Closes' },
        { k: 'wagered',   label: 'Wagered' },
        { k: 'bets',      label: 'Bets' },
        { k: 'actions',   label: '' }
      ]));
    }
    statusSel.addEventListener('change', load);
    refresh.addEventListener('click', load);
    let t; search.addEventListener('input', function () { clearTimeout(t); t = setTimeout(load, 350); });
    await load();
  });

  // ============================================================
  // PAGE: CREATE MARKET
  // ============================================================
  route('create-market', async function (main) {
    clear(main);
    main.appendChild(el('div', { class: 'page-head' },
      el('h1', { text: 'Create market' }),
      el('div', { class: 'page-sub', text: 'Define the question, outcomes, pricing, limits.' })
    ));

    const outcomes = el('div');
    function addOutcomeRow(name, odds) {
      const n = el('input', { class: 'input', placeholder: 'Outcome name', value: name || '' });
      const o = el('input', { class: 'input', placeholder: '2.0', value: odds || '2.0' });
      const row = el('div', { class: 'row', style: { marginBottom: '8px' } },
        el('div', { style: { flex: 2, minWidth: '180px' } }, n),
        el('div', { style: { flex: '0 0 100px' } }, o),
        el('button', { type: 'button', class: 'btn btn-secondary btn-sm', text: 'Remove', onclick: function () { row.remove(); } })
      );
      row.dataset.name = 'outcome';
      row._name = n; row._odds = o;
      outcomes.appendChild(row);
    }
    addOutcomeRow('Yes', '2.0');
    addOutcomeRow('No',  '2.0');

    const q     = el('input', { class: 'input', required: true, placeholder: 'Will X happen by Y?' });
    const cat   = el('input', { class: 'input', required: true, placeholder: 'sports / politics / finance' });
    const src   = el('input', { class: 'input', placeholder: 'local' });
    const ttl   = el('input', { class: 'input', placeholder: 'Short headline' });
    const img   = el('input', { class: 'input', placeholder: 'https://...' });
    const mtype = selectFrom('market_type', ['binary', 'categorical'], 'binary');
    const omode = selectFrom('odds_mode',   ['lmsr', 'fixed'], 'lmsr');
    const ctime = el('input', { class: 'input', type: 'datetime-local' });
    const b     = el('input', { class: 'input', type: 'number', placeholder: '1000' });
    const maxo  = el('input', { class: 'input', type: 'number', step: '0.1', placeholder: '10.0' });
    const mins  = el('input', { class: 'input', type: 'number', placeholder: '10' });
    const maxs  = el('input', { class: 'input', type: 'number', placeholder: '100000' });
    const mtw   = el('input', { class: 'input', type: 'number', placeholder: '0 = unlimited' });

    const submit = el('button', { type: 'submit', class: 'btn btn-primary', text: 'Create market' });

    const form = el('form', { class: 'card', onsubmit: async function (e) {
      e.preventDefault();
      const outArr = [];
      for (const r of outcomes.querySelectorAll('[data-name=outcome]')) {
        const n = r._name.value.trim();
        const o = parseFloat(r._odds.value);
        if (!n || !isFinite(o)) continue;
        outArr.push({ name: n, odds: o });
      }
      if (outArr.length < 2) { toast('Add at least two outcomes.', 'error'); return; }
      const body = {
        question:    q.value.trim(),
        category:    cat.value.trim(),
        source:      src.value.trim() || 'local',
        market_type: mtype.value,
        odds_mode:   omode.value,
        outcomes:    outArr
      };
      if (ttl.value.trim())   body.title = ttl.value.trim();
      if (img.value.trim())   body.image_url = img.value.trim();
      if (ctime.value)        body.close_time = ctime.value.replace('T', ' ') + ':00';
      if (b.value)            body.b = parseFloat(b.value);
      if (maxo.value)         body.max_odds = parseFloat(maxo.value);
      if (mins.value)         body.min_stake = parseFloat(mins.value);
      if (maxs.value)         body.max_stake = parseFloat(maxs.value);
      if (mtw.value)          body.max_total_wagered = parseFloat(mtw.value);

      submit.disabled = true; submit.textContent = 'Creating…';
      try {
        const data = await api('admin_create_market', { method: 'POST', body: body });
        toast('Market created');
        go('market/' + data.data.market_id);
      } catch (err) {
        toast(err.message, 'error');
      } finally {
        submit.disabled = false; submit.textContent = 'Create market';
      }
    }},
      field('Question', q),
      el('div', { class: 'row' },
        el('div', { class: 'col' }, field('Category', cat)),
        el('div', { class: 'col' }, field('Source',   src))
      ),
      field('Title (optional)', ttl),
      field('Image URL (optional)', img),
      el('div', { class: 'row' },
        el('div', { class: 'col' }, field('Market type', mtype)),
        el('div', { class: 'col' }, field('Odds mode',   omode))
      ),
      el('div', { class: 'row' },
        el('div', { class: 'col' }, field('Close time', ctime)),
        el('div', { class: 'col' }, field('LMSR b (50–10000)', b)),
        el('div', { class: 'col' }, field('Max odds (LMSR)', maxo))
      ),
      el('div', { class: 'row' },
        el('div', { class: 'col' }, field('Min stake', mins)),
        el('div', { class: 'col' }, field('Max stake', maxs)),
        el('div', { class: 'col' }, field('Max total wagered', mtw))
      ),
      el('h2', { text: 'Outcomes' }),
      outcomes,
      el('button', { type: 'button', class: 'btn btn-secondary btn-sm', text: '+ Add outcome', onclick: function () { addOutcomeRow('', '2.0'); } }),
      el('div', { class: 'mt-4' }, submit)
    );
    main.appendChild(form);
  });

  // ============================================================
  // PAGE: MARKET DETAIL + ACTIONS
  // ============================================================
  route('market', async function (main, rest) {
    const marketId = rest[0];
    if (!marketId) { main.appendChild(el('p', { text: 'No market id' })); return; }

    const data = await api('admin_market_report', { query: 'market_id=' + encodeURIComponent(marketId) });
    const m  = data.data.market;
    const bs = data.data.bet_summary;

    clear(main);
    main.appendChild(el('div', { class: 'page-head flex-between' },
      el('div', null,
        el('h1', { text: m.question }),
        el('div', { class: 'page-sub' },
          statusBadge(m.status), ' · ', m.category, ' · ', m.market_type, ' · ', m.odds_mode
        )
      ),
      el('a', { href: '#markets', class: 'btn btn-secondary btn-sm', text: '← All markets' })
    ));

    const sc = function (l, v) { return el('div', { class: 'stat-card' },
      el('div', { class: 'stat-label', text: l }), el('div', { class: 'stat-value', text: String(v) })); };
    main.appendChild(el('div', { class: 'stats' },
      sc('Total bets',    bs.total_bets),
      sc('Open',          bs.open),
      sc('Total staked',  fmtKes(bs.total_staked)),
      sc('Max liability', fmtKes(bs.max_liability)),
      sc('Closes',        fmtDate(m.close_time)),
      sc('b / max odds',  m.b + ' / ' + (m.max_odds === null ? '—' : m.max_odds))
    ));

    main.appendChild(el('div', { class: 'card' },
      el('h2', { text: 'Live odds' }),
      tableFrom(data.data.live_odds, [
        { k: 'outcome_id', label: 'ID' },
        { k: 'name',       label: 'Outcome' },
        { k: 'probability', label: 'Prob' },
        { k: 'odds',       label: 'Odds' }
      ])
    ));

    // What-if house P/L: per-outcome projection, plus void / kept-all.
    const wi = data.data.what_if;
    if (wi) {
      const pl = function (n) {
        const s = fmtKes(Math.abs(n));
        return el('span', { class: n >= 0 ? 'text-success' : 'text-danger',
                            text: (n >= 0 ? '+' : '−') + s });
      };
      const rows = (wi.outcomes || []).map(function (o) {
        return {
          outcome: o.outcome_name + ' (id ' + o.outcome_id + ')',
          open_stake: fmtKes(o.open_stake_for),
          payout: fmtKes(o.open_payout_if_wins),
          house: pl(o.house_pl_if_wins)
        };
      });
      // Append the two whole-market scenarios as rows at the bottom.
      rows.push({
        outcome: el('span', { class: 'text-muted', text: 'Voided now (refund all)' }),
        open_stake: fmtKes(wi.total_open_stake),
        payout: fmtKes(wi.total_open_stake),
        house: pl(wi.house_pl_if_voided_now)
      });
      rows.push({
        outcome: el('span', { class: 'text-muted', text: 'Market never happened / keep all stakes' }),
        open_stake: fmtKes(wi.total_open_stake),
        payout: 'KES 0.00',
        house: pl(wi.house_pl_if_kept_all)
      });
      main.appendChild(el('div', { class: 'card' },
        el('h2', { text: 'House P/L — what-if I settle now' }),
        el('div', { class: 'page-sub', text: 'Cash bets only (bonus excluded). Open book: ' + fmtKes(wi.total_open_stake) + ' currently at risk on this market.' }),
        tableFrom(rows, [
          { k: 'outcome',    label: 'If this outcome wins' },
          { k: 'open_stake', label: 'Open stake on it' },
          { k: 'payout',     label: 'Payout to winners' },
          { k: 'house',      label: 'House P/L' }
        ])
      ));
    }

    const isTerminal = (m.status === 'resolved' || m.status === 'voided');
    function actBtn(show, cls, label, onclick) {
      if (!show) return null;
      return el('button', { class: 'btn ' + cls + ' btn-sm', onclick: onclick, text: label });
    }
    const reload = function () { render(); };
    function simple(routeName, body, msg) {
      confirmDialog(msg + ' — continue?', async function () {
        try { await api(routeName, { method: 'POST', body: body }); toast(msg); reload(); }
        catch (e) { toast(e.message, 'error'); }
      });
    }

    main.appendChild(el('div', { class: 'card' },
      el('h2', { text: 'Admin actions' }),
      el('div', { class: 'row' },
        actBtn(m.status === 'open',                                      'btn-warning',  'Pause',          function () { simple('admin_pause_market',       { market_id: marketId }, 'Paused'); }),
        actBtn(m.status === 'paused',                                    'btn-success',  'Resume',         function () { simple('admin_resume_market',      { market_id: marketId }, 'Resumed'); }),
        actBtn(!isTerminal && m.status !== 'closed',                     'btn-secondary','Force close',    function () { simple('admin_force_close_market', { market_id: marketId }, 'Closed'); }),
        actBtn(m.status === 'closed',                                    'btn-success',  'Reopen',         function () { simple('admin_reopen_market',      { market_id: marketId }, 'Reopened'); }),
        actBtn(!isTerminal,                                              'btn-indigo',   'Settle…',        function () { settleModal(marketId, data.data.live_odds); }),
        actBtn(!isTerminal,                                              'btn-danger',   'Void market…',   function () { voidMarketModal(marketId); }),
        actBtn(!isTerminal,                                              'btn-warning',  'Void by time…',  function () { voidByTimeModal(marketId); }),
        actBtn(!isTerminal,                                              'btn-secondary','Adjust limits…', function () { limitsModal(marketId, m); }),
        actBtn(!isTerminal,                                              'btn-secondary','Extend close…',  function () { extendModal(marketId); }),
        actBtn(!isTerminal && m.odds_mode === 'lmsr',                    'btn-secondary','Set liquidity…', function () { liquidityModal(marketId, m); }),
        actBtn(!isTerminal,                                              'btn-secondary','Reseed odds…',   function () { reseedModal(marketId, data.data.live_odds); }),
        actBtn(!isTerminal,                                              'btn-secondary','Switch odds mode', function () { oddsModeModal(marketId, m.odds_mode); }),
        actBtn(true,                                                     'btn-secondary','Edit metadata…', function () { editMetaModal(marketId, m); }),
        actBtn(true,                                                     m.is_featured ? 'btn-secondary' : 'btn-primary', m.is_featured ? 'Unfeature' : 'Feature',
                                                                                                            function () { simple('admin_feature_market',     { market_id: marketId, featured: !m.is_featured }, m.is_featured ? 'Unfeatured' : 'Featured'); }),
        actBtn(isTerminal,                                               'btn-secondary', m.is_archived ? 'Unarchive' : 'Archive',
                                                                                                            function () { simple('admin_archive_market',     { market_id: marketId, archive: !m.is_archived }, m.is_archived ? 'Unarchived' : 'Archived'); })
      )
    ));

    if (data.data.audit_log && data.data.audit_log.length) {
      main.appendChild(el('div', { class: 'card' },
        el('h2', { text: 'Audit log (latest 50)' }),
        tableFrom(data.data.audit_log.map(function (a) {
          return {
            action: a.action,
            admin:  a.admin || (Number(a.admin_id) === 0 ? 'system' : '#' + a.admin_id),
            at:     fmtDate(a.at),
            meta:   el('pre', { class: 'json', text: JSON.stringify(a.meta || {}, null, 0) })
          };
        }), [
          { k: 'action', label: 'Action' },
          { k: 'admin',  label: 'By' },
          { k: 'at',     label: 'At' },
          { k: 'meta',   label: 'Meta' }
        ])
      ));
    }
  });

  function settleModal(marketId, liveOdds) {
    const sel = el('select', { class: 'select' });
    for (const o of liveOdds) sel.appendChild(el('option', { value: String(o.outcome_id), text: o.name + ' (odds ' + o.odds + ')' }));
    let m;
    const body = el('div', null,
      el('p', { text: 'Pick the winning outcome. This is permanent — winners are paid out, losers settled.' }),
      field('Winning outcome', sel),
      el('div', { class: 'row', style: { justifyContent: 'flex-end' } },
        el('button', { class: 'btn btn-secondary', text: 'Cancel', onclick: function () { m.close(); } }),
        el('button', { class: 'btn btn-indigo', text: 'Settle market', onclick: async function () {
          try {
            await api('admin_settle_market', { method: 'POST', body: { market_id: marketId, winning_outcome_id: parseInt(sel.value, 10) }});
            toast('Market settled');
            m.close(); render();
          } catch (e) { toast(e.message, 'error'); }
        }})
      )
    );
    m = modal('Settle market', body);
  }

  function voidMarketModal(marketId) {
    const reason = el('textarea', { class: 'textarea', placeholder: 'Why is this being voided? (5–500 chars)' });
    let m;
    const body = el('div', null,
      el('p', { text: 'Refunds every open bet. Permanent.' }),
      field('Reason', reason),
      el('div', { class: 'row', style: { justifyContent: 'flex-end' } },
        el('button', { class: 'btn btn-secondary', text: 'Cancel', onclick: function () { m.close(); } }),
        el('button', { class: 'btn btn-danger', text: 'Void market', onclick: async function () {
          try {
            await api('admin_void_market', { method: 'POST', body: { market_id: marketId, reason: reason.value }});
            toast('Market voided'); m.close(); render();
          } catch (e) { toast(e.message, 'error'); }
        }})
      )
    );
    m = modal('Void market', body);
  }

  function voidByTimeModal(marketId) {
    const cut = el('input', { class: 'input', type: 'datetime-local' });
    const reason = el('textarea', { class: 'textarea', placeholder: 'Why (≥10 chars)' });
    let m;
    const body = el('div', null,
      el('p', { class: 'page-sub', text: 'Voids every open bet placed AFTER this cutoff. Works on any non-voided market.' }),
      field('Cutoff time (must be in the past)', cut),
      field('Reason', reason),
      el('div', { class: 'row', style: { justifyContent: 'flex-end' } },
        el('button', { class: 'btn btn-secondary', text: 'Cancel', onclick: function () { m.close(); } }),
        el('button', { class: 'btn btn-warning', text: 'Void those bets', onclick: async function () {
          try {
            const ct = cut.value.replace('T', ' ') + ':00';
            const data = await api('admin_void_bets_by_time', { method: 'POST', body: { market_id: marketId, cutoff_time: ct, reason: reason.value }});
            toast(data.data.bets_voided + ' bets voided');
            m.close(); render();
          } catch (e) { toast(e.message, 'error'); }
        }})
      )
    );
    m = modal('Void bets by time', body);
  }

  function limitsModal(marketId, mk) {
    const mins = el('input', { class: 'input', type: 'number', value: mk.min_stake });
    const maxs = el('input', { class: 'input', type: 'number', value: mk.max_stake });
    const mtw  = el('input', { class: 'input', type: 'number', value: mk.max_total_wagered });
    const mo   = el('input', { class: 'input', type: 'number', step: '0.1', value: mk.max_odds == null ? '' : mk.max_odds });
    let m;
    const body = el('div', null,
      field('Min stake',                 mins),
      field('Max stake',                 maxs),
      field('Max total wagered (0 = unlimited)', mtw),
      mk.odds_mode === 'lmsr' ? field('Max odds (LMSR cap)', mo) : null,
      el('div', { class: 'row', style: { justifyContent: 'flex-end' } },
        el('button', { class: 'btn btn-secondary', text: 'Cancel', onclick: function () { m.close(); } }),
        el('button', { class: 'btn btn-primary', text: 'Save', onclick: async function () {
          const body2 = { market_id: marketId,
            min_stake: parseFloat(mins.value),
            max_stake: parseFloat(maxs.value),
            max_total_wagered: parseFloat(mtw.value) };
          if (mk.odds_mode === 'lmsr' && mo.value) body2.max_odds = parseFloat(mo.value);
          try { await api('admin_adjust_limits', { method: 'POST', body: body2 }); toast('Limits updated'); m.close(); render(); }
          catch (e) { toast(e.message, 'error'); }
        }})
      )
    );
    m = modal('Adjust limits', body);
  }

  function extendModal(marketId) {
    const dt = el('input', { class: 'input', type: 'datetime-local' });
    let m;
    const body = el('div', null,
      field('New close time (must be future)', dt),
      el('div', { class: 'row', style: { justifyContent: 'flex-end' } },
        el('button', { class: 'btn btn-secondary', text: 'Cancel', onclick: function () { m.close(); } }),
        el('button', { class: 'btn btn-primary', text: 'Extend', onclick: async function () {
          try {
            await api('admin_extend_close_time', { method: 'POST', body: { market_id: marketId, close_time: dt.value.replace('T',' ') + ':00' }});
            toast('Close time updated'); m.close(); render();
          } catch (e) { toast(e.message, 'error'); }
        }})
      )
    );
    m = modal('Extend close time', body);
  }

  function liquidityModal(marketId, mk) {
    const b = el('input', { class: 'input', type: 'number', value: mk.b });
    let m;
    const body = el('div', null,
      el('p', { class: 'page-sub', text: 'Changing b shifts the LMSR price curve. Range 50–10000.' }),
      field('New b', b),
      el('div', { class: 'row', style: { justifyContent: 'flex-end' } },
        el('button', { class: 'btn btn-secondary', text: 'Cancel', onclick: function () { m.close(); } }),
        el('button', { class: 'btn btn-primary', text: 'Update', onclick: async function () {
          try {
            await api('admin_set_liquidity', { method: 'POST', body: { market_id: marketId, b: parseFloat(b.value) }});
            toast('Liquidity updated'); m.close(); render();
          } catch (e) { toast(e.message, 'error'); }
        }})
      )
    );
    m = modal('Set liquidity (b)', body);
  }

  function reseedModal(marketId, liveOdds) {
    const inputs = [];
    const fields = el('div');
    for (const o of liveOdds) {
      const inp = el('input', { class: 'input', type: 'number', step: '0.01', value: o.odds });
      inputs.push({ o: o, inp: inp });
      fields.appendChild(field(o.name + ' (id ' + o.outcome_id + ')', inp));
    }
    const force = el('input', { type: 'checkbox' });
    let m;
    const body = el('div', null,
      el('p', { class: 'page-sub', text: 'Provide new odds for every outcome. Force is required if open bets exist.' }),
      fields,
      el('label', { class: 'check' }, force, ' Force (override the open-bets guard)'),
      el('div', { class: 'row mt-2', style: { justifyContent: 'flex-end' } },
        el('button', { class: 'btn btn-secondary', text: 'Cancel', onclick: function () { m.close(); } }),
        el('button', { class: 'btn btn-primary', text: 'Reseed', onclick: async function () {
          try {
            const outcomes = inputs.map(function (x) { return { outcome_id: x.o.outcome_id, odds: parseFloat(x.inp.value) }; });
            await api('admin_reseed_odds', { method: 'POST', body: { market_id: marketId, outcomes: outcomes, force: force.checked }});
            toast('Odds reseeded'); m.close(); render();
          } catch (e) { toast(e.message, 'error'); }
        }})
      )
    );
    m = modal('Reseed odds', body);
  }

  function oddsModeModal(marketId, current) {
    const target = current === 'lmsr' ? 'fixed' : 'lmsr';
    const force = el('input', { type: 'checkbox' });
    let m;
    const body = el('div', null,
      el('p', { text: 'Switch from ' + current + ' to ' + target + '?' }),
      el('label', { class: 'check' }, force, ' Force (required if open bets exist)'),
      el('div', { class: 'row mt-2', style: { justifyContent: 'flex-end' } },
        el('button', { class: 'btn btn-secondary', text: 'Cancel', onclick: function () { m.close(); } }),
        el('button', { class: 'btn btn-primary', text: 'Switch', onclick: async function () {
          try {
            await api('admin_set_odds_mode', { method: 'POST', body: { market_id: marketId, odds_mode: target, force: force.checked }});
            toast('Mode switched'); m.close(); render();
          } catch (e) { toast(e.message, 'error'); }
        }})
      )
    );
    m = modal('Switch odds mode', body);
  }

  function editMetaModal(marketId, cur) {
    const q  = el('textarea', { class: 'textarea' }); q.value = cur.question || '';
    const c  = el('input', { class: 'input', value: cur.category || '' });
    const t  = el('input', { class: 'input', value: cur.title || '' });
    const im = el('input', { class: 'input', value: cur.image_url || '' });
    let m;
    const body = el('div', null,
      field('Question', q),
      field('Category', c),
      field('Title (optional)', t),
      field('Image URL (optional)', im),
      el('div', { class: 'row', style: { justifyContent: 'flex-end' } },
        el('button', { class: 'btn btn-secondary', text: 'Cancel', onclick: function () { m.close(); } }),
        el('button', { class: 'btn btn-primary', text: 'Save', onclick: async function () {
          try {
            const body2 = { market_id: marketId, question: q.value, category: c.value };
            if (t.value)  body2.title = t.value;
            if (im.value) body2.image_url = im.value;
            await api('admin_edit_market', { method: 'POST', body: body2 });
            toast('Updated'); m.close(); render();
          } catch (e) { toast(e.message, 'error'); }
        }})
      )
    );
    m = modal('Edit metadata', body);
  }

  // ============================================================
  // PAGE: WITHDRAWALS
  // ============================================================
  route('withdrawals', async function (main) {
    clear(main);
    main.appendChild(el('div', { class: 'page-head' },
      el('h1', { text: 'Pending withdrawals' }),
      el('div', { class: 'page-sub', text: 'Verify identity, send the M-Pesa, then record the receipt code here.' })
    ));
    const data = await api('admin_pending_withdrawals');
    if (!data.data || data.data.length === 0) {
      main.appendChild(el('div', { class: 'card empty', text: 'No pending withdrawals.' }));
      return;
    }
    for (const w of data.data) {
      const u = w.user || {};
      const sc = function (l, v) { return el('div', { class: 'stat-card' },
        el('div', { class: 'stat-label', text: l }),
        el('div', { class: 'stat-value', style: { fontSize: '16px' }, text: v })); };
      main.appendChild(el('div', { class: 'card' },
        el('div', { class: 'flex-between' },
          el('div', null,
            el('h2', { text: fmtKes(w.amount) + '  →  ' + (u.username || ('#' + (u.user_id || '?'))) }),
            el('div', { class: 'page-sub' },
              (u.email || ''), '  ·  ', (w.phone || ''), '  ·  ',
              'Requested ', fmtDate(w.requested_at)
            )
          ),
          el('div', { class: 'row' },
            el('button', { class: 'btn btn-success', text: 'Approve & record code', onclick: function () { approveWithdrawal(w.withdrawal_id); } }),
            el('button', { class: 'btn btn-danger',  text: 'Reject',                 onclick: function () { rejectWithdrawal(w.withdrawal_id); } })
          )
        ),
        el('div', { class: 'stats mt-3' },
          sc('Balance',          fmtKes(u.balance)),
          sc('Available',        fmtKes(u.available_balance)),
          sc('Total deposits',   fmtKes(u.deposit_stats && u.deposit_stats.total_completed || 0)),
          sc('Total withdrawn',  fmtKes(u.withdrawal_stats && u.withdrawal_stats.total_completed || 0)),
          sc('Net position',     fmtKes(u.net_position || 0)),
          sc('Bets',             String(u.bet_stats && u.bet_stats.total || 0))
        )
      ));
    }
  });

  function approveWithdrawal(wid) {
    const code = el('input', { class: 'input', placeholder: 'M-Pesa receipt e.g. SAE3YULR0Y', autocapitalize: 'characters' });
    const note = el('input', { class: 'input', placeholder: 'Optional internal note' });
    let m;
    const body = el('div', null,
      el('p', { class: 'page-sub', text: 'You\'ve sent the M-Pesa from your till. Paste the confirmation code here to finalise.' }),
      field('Transaction code', code),
      field('Note (optional)',  note),
      el('div', { class: 'row', style: { justifyContent: 'flex-end' } },
        el('button', { class: 'btn btn-secondary', text: 'Cancel', onclick: function () { m.close(); } }),
        el('button', { class: 'btn btn-success', text: 'Approve & mark paid', onclick: async function () {
          try {
            await api('admin_approve_withdrawal', { method: 'POST', body: { withdrawal_id: wid, transaction_code: code.value, note: note.value }});
            toast('Approved'); m.close(); render();
          } catch (e) { toast(e.message, 'error'); }
        }})
      )
    );
    m = modal('Approve withdrawal #' + wid, body);
  }

  function rejectWithdrawal(wid) {
    const reason = el('textarea', { class: 'textarea', placeholder: 'Reason shown to the user (≥3 chars)' });
    let m;
    const body = el('div', null,
      field('Reason', reason),
      el('div', { class: 'row', style: { justifyContent: 'flex-end' } },
        el('button', { class: 'btn btn-secondary', text: 'Cancel', onclick: function () { m.close(); } }),
        el('button', { class: 'btn btn-danger', text: 'Reject', onclick: async function () {
          try {
            await api('admin_reject_withdrawal', { method: 'POST', body: { withdrawal_id: wid, reason: reason.value }});
            toast('Rejected'); m.close(); render();
          } catch (e) { toast(e.message, 'error'); }
        }})
      )
    );
    m = modal('Reject withdrawal #' + wid, body);
  }

  // ============================================================
  // PAGE: MANUAL CLAIMS
  // ============================================================
  route('claims', async function (main) {
    clear(main);
    main.appendChild(el('div', { class: 'page-head' },
      el('h1', { text: 'Manual deposit claims' }),
      el('div', { class: 'page-sub', text: 'Users entered an M-Pesa code claiming they paid — verify against your statement.' })
    ));
    const sel = selectFrom('status', ['pending', 'approved', 'rejected', 'all'], 'pending');
    main.appendChild(el('div', { class: 'card thin' }, field('Status', sel)));

    const list = el('div'); main.appendChild(list);

    async function load() {
      clear(list);
      list.appendChild(el('div', { class: 'card text-muted', text: 'Loading…' }));
      const data = await api('admin_manual_claims', { query: 'status=' + sel.value });
      clear(list);
      if (!data.data || data.data.length === 0) {
        list.appendChild(el('div', { class: 'card empty', text: 'No claims here.' }));
        return;
      }
      for (const c of data.data) {
        const u = c.user || {};
        const card = el('div', { class: 'card' });
        card.appendChild(el('div', { class: 'flex-between' },
          el('div', null,
            el('h2', { text: fmtKes(c.amount) + '  ·  ' + c.transaction_code }),
            el('div', { class: 'page-sub' },
              (u.username || ''), '  ·  ', (u.email || ''), '  ·  ', (c.phone || ''),
              '  ·  Submitted ', fmtDate(c.created_at)
            )
          ),
          c.status === 'pending'
            ? el('div', { class: 'row' },
                el('button', { class: 'btn btn-success', text: 'Approve & credit', onclick: function () { approveClaim(c.claim_id); } }),
                el('button', { class: 'btn btn-danger',  text: 'Reject',           onclick: function () { rejectClaim(c.claim_id); } })
              )
            : statusBadge(c.status)
        ));
        if (c.note)        card.appendChild(el('div', { class: 'mt-2 text-muted', text: 'User note: ' + c.note }));
        if (c.review_note) card.appendChild(el('div', { class: 'mt-2 text-muted', text: 'Review: ' + c.review_note }));
        list.appendChild(card);
      }
    }
    sel.addEventListener('change', load);
    await load();
  });
  function approveClaim(id) {
    const note = el('input', { class: 'input', placeholder: 'Optional internal note' });
    let m;
    const body = el('div', null, field('Note', note),
      el('div', { class: 'row', style: { justifyContent: 'flex-end' } },
        el('button', { class: 'btn btn-secondary', text: 'Cancel', onclick: function () { m.close(); } }),
        el('button', { class: 'btn btn-success', text: 'Approve & credit', onclick: async function () {
          try { await api('admin_approve_manual_claim', { method: 'POST', body: { claim_id: id, note: note.value }});
            toast('Approved'); m.close(); render();
          } catch (e) { toast(e.message, 'error'); }
        }})
      )
    );
    m = modal('Approve claim #' + id, body);
  }
  function rejectClaim(id) {
    const reason = el('textarea', { class: 'textarea' });
    let m;
    const body = el('div', null, field('Reason', reason),
      el('div', { class: 'row', style: { justifyContent: 'flex-end' } },
        el('button', { class: 'btn btn-secondary', text: 'Cancel', onclick: function () { m.close(); } }),
        el('button', { class: 'btn btn-danger', text: 'Reject', onclick: async function () {
          try { await api('admin_reject_manual_claim', { method: 'POST', body: { claim_id: id, reason: reason.value }});
            toast('Rejected'); m.close(); render();
          } catch (e) { toast(e.message, 'error'); }
        }})
      )
    );
    m = modal('Reject claim #' + id, body);
  }

  // ============================================================
  // PAGE: PAYMENT REPORT
  // ============================================================
  route('payments', async function (main) {
    clear(main);
    main.appendChild(el('div', { class: 'page-head' }, el('h1', { text: 'Payment report' })));
    const data = await api('admin_payment_report');
    const d = data.data;

    const sc = function (l, v) { return el('div', { class: 'stat-card' },
      el('div', { class: 'stat-label', text: l }), el('div', { class: 'stat-value', text: String(v) })); };
    main.appendChild(el('div', { class: 'stats' },
      sc('Deposits total',     fmtKes(d.deposits.total_amount)),
      sc('Withdrawals total',  fmtKes(d.withdrawals.total_amount)),
      sc('Pending deposits',   d.deposits.pending_count),
      sc('Pending withdrawals',d.withdrawals.pending_count)
    ));
    main.appendChild(el('div', { class: 'card' },
      el('h2', { text: 'Top depositors (30d)' }),
      tableFrom(d.top_depositors_30d || [], [
        { k: 'username', label: 'User' },
        { k: 'total',    label: 'Total', fmt: fmtKes }
      ])
    ));
    main.appendChild(el('div', { class: 'card' },
      el('h2', { text: 'Daily deposits (30d)' }),
      tableFrom(d.daily_deposits_30d || [], [
        { k: 'day',      label: 'Day' },
        { k: 'deposits', label: 'Count' },
        { k: 'volume',   label: 'Volume', fmt: fmtKes }
      ])
    ));
  });

  // ============================================================
  // PAGE: USER ACTIONS
  // ============================================================
  route('users', async function (main) {
    clear(main);
    main.appendChild(el('div', { class: 'page-head' },
      el('h1', { text: 'User actions' }),
      el('div', { class: 'page-sub', text: 'Credit/debit, ban, restrict messaging — by user id.' })
    ));
    const uid = el('input', { class: 'input', type: 'number', placeholder: 'User ID' });
    main.appendChild(el('div', { class: 'card' },
      field('User ID', uid),
      el('div', { class: 'row' },
        el('button', { class: 'btn btn-primary',   text: 'Credit / debit',   onclick: function () { creditUser(uid.value); } }),
        el('button', { class: 'btn btn-danger',    text: 'Ban',              onclick: function () { banUser(uid.value); } }),
        el('button', { class: 'btn btn-success',   text: 'Unban',            onclick: function () { unbanUser(uid.value); } }),
        el('button', { class: 'btn btn-warning',   text: 'Restrict messaging', onclick: function () { restrictMsg(uid.value); } })
      )
    ));
  });
  function creditUser(userId) {
    if (!userId) return toast('Enter a user id first.', 'error');
    const amt  = el('input', { class: 'input', type: 'number', step: '0.01', placeholder: 'Positive = credit, negative = debit' });
    const type = selectFrom('type', ['deposit', 'bonus', 'withdrawal', 'adjustment'], 'adjustment');
    const note = el('input', { class: 'input' });
    let m;
    const body = el('div', null,
      field('Amount', amt), field('Type', type), field('Note', note),
      el('div', { class: 'row', style: { justifyContent: 'flex-end' } },
        el('button', { class: 'btn btn-secondary', text: 'Cancel', onclick: function () { m.close(); } }),
        el('button', { class: 'btn btn-primary', text: 'Apply', onclick: async function () {
          try {
            await api('admin_credit_user', { method: 'POST', body: { user_id: parseInt(userId, 10), amount: parseFloat(amt.value), type: type.value, note: note.value }});
            toast('Applied'); m.close();
          } catch (e) { toast(e.message, 'error'); }
        }})
      )
    );
    m = modal('Credit / debit user #' + userId, body);
  }
  function banUser(userId) {
    if (!userId) return toast('Enter a user id first.', 'error');
    const reason = el('textarea', { class: 'textarea', placeholder: 'Reason (≥5 chars)' });
    let m;
    const body = el('div', null, field('Reason', reason),
      el('div', { class: 'row', style: { justifyContent: 'flex-end' } },
        el('button', { class: 'btn btn-secondary', text: 'Cancel', onclick: function () { m.close(); } }),
        el('button', { class: 'btn btn-danger', text: 'Ban', onclick: async function () {
          try { await api('admin_ban_user', { method: 'POST', body: { user_id: parseInt(userId, 10), reason: reason.value }});
            toast('Banned'); m.close();
          } catch (e) { toast(e.message, 'error'); }
        }})
      )
    );
    m = modal('Ban user #' + userId, body);
  }
  function unbanUser(userId) {
    if (!userId) return toast('Enter a user id first.', 'error');
    const note = el('input', { class: 'input' });
    let m;
    const body = el('div', null, field('Lift note', note),
      el('div', { class: 'row', style: { justifyContent: 'flex-end' } },
        el('button', { class: 'btn btn-secondary', text: 'Cancel', onclick: function () { m.close(); } }),
        el('button', { class: 'btn btn-success', text: 'Unban', onclick: async function () {
          try { await api('admin_unban_user', { method: 'POST', body: { user_id: parseInt(userId, 10), note: note.value }});
            toast('Unbanned'); m.close();
          } catch (e) { toast(e.message, 'error'); }
        }})
      )
    );
    m = modal('Unban user #' + userId, body);
  }
  function restrictMsg(userId) {
    if (!userId) return toast('Enter a user id first.', 'error');
    const restricted = el('input', { type: 'checkbox', checked: true });
    const reason = el('input', { class: 'input' });
    let m;
    const body = el('div', null,
      el('label', { class: 'check' }, restricted, ' Apply restriction (uncheck to lift)'),
      field('Reason', reason),
      el('div', { class: 'row', style: { justifyContent: 'flex-end' } },
        el('button', { class: 'btn btn-secondary', text: 'Cancel', onclick: function () { m.close(); } }),
        el('button', { class: 'btn btn-warning', text: 'Apply', onclick: async function () {
          try {
            await api('admin_restrict_messaging', { method: 'POST', body: { user_id: parseInt(userId, 10), restricted: restricted.checked, reason: reason.value }});
            toast('Updated'); m.close();
          } catch (e) { toast(e.message, 'error'); }
        }})
      )
    );
    m = modal('Messaging restriction · user #' + userId, body);
  }

  // ============================================================
  // PAGE: SUPPORT TICKETS
  // ============================================================
  route('support', async function (main) {
    clear(main);
    main.appendChild(el('div', { class: 'page-head' }, el('h1', { text: 'Support tickets' })));
    const sel = selectFrom('status', ['all','open','answered','closed'], 'all');
    main.appendChild(el('div', { class: 'card thin' }, field('Status', sel)));
    const list = el('div'); main.appendChild(list);
    async function load() {
      clear(list);
      list.appendChild(el('div', { class: 'card text-muted', text: 'Loading…' }));
      const q = sel.value && sel.value !== 'all' ? 'status=' + sel.value : '';
      const data = await api('admin_support_tickets', { query: q });
      clear(list);
      if (!data.data || !data.data.length) {
        list.appendChild(el('div', { class: 'card empty', text: 'No tickets here.' }));
        return;
      }
      for (const t of data.data) {
        list.appendChild(el('div', { class: 'card' },
          el('div', { class: 'flex-between' },
            el('div', null,
              el('h2', { text: '#' + t.ticket_id + '  ·  ' + t.subject }),
              el('div', { class: 'page-sub' },
                ((t.user && t.user.username) || '?'), '  ·  ', ((t.user && t.user.email) || ''),
                '  ·  ', statusBadge(t.status),
                '  ·  ', el('span', { class: 'badge badge-muted', text: t.category }),
                t.admin_unread > 0 ? el('span', { class: 'badge badge-accent', text: t.admin_unread + ' new' }) : null
              )
            ),
            el('button', { class: 'btn btn-indigo btn-sm', text: 'Open', onclick: function () { openTicket(t.ticket_id); } })
          )
        ));
      }
    }
    sel.addEventListener('change', load);
    await load();
  });
  async function openTicket(id) {
    const data = await api('admin_support_thread', { query: 'ticket_id=' + id });
    const t = data.data;
    const msgs = el('div', { class: 'chat-list' });
    for (const msg of (t.messages || [])) {
      const isAdmin = msg.sender === 'admin';
      msgs.appendChild(el('div', { class: 'chat-msg' + (isAdmin ? ' from-admin' : '') },
        el('div', { class: 'meta', text: msg.username + '  ·  ' + fmtDate(msg.sent_at) }),
        el('div', { text: msg.body })
      ));
    }
    const reply  = el('textarea', { class: 'textarea', placeholder: 'Reply privately to this user…' });
    const notify = el('input', { type: 'checkbox', checked: true });
    let m;
    const body = el('div', null,
      el('div', { class: 'page-sub', text: 'From: ' + ((t.user && t.user.username) || '?') + '  ·  ' + ((t.user && t.user.email) || '') }),
      msgs,
      el('div', { class: 'mt-3' }, reply),
      el('label', { class: 'check mt-2' }, notify, ' Email the user that we replied'),
      el('div', { class: 'row mt-2', style: { justifyContent: 'space-between' } },
        t.status !== 'closed' ? el('button', { class: 'btn btn-secondary', text: 'Close ticket', onclick: async function () {
          try { await api('admin_support_close', { method: 'POST', body: { ticket_id: id }}); toast('Closed'); m.close(); render(); }
          catch (e) { toast(e.message, 'error'); }
        }}) : el('span'),
        el('button', { class: 'btn btn-primary', text: 'Send reply', onclick: async function () {
          try {
            await api('admin_support_reply', { method: 'POST', body: { ticket_id: id, body: reply.value, notify_email: notify.checked }});
            toast('Reply sent'); m.close(); render();
          } catch (e) { toast(e.message, 'error'); }
        }})
      )
    );
    m = modal('Ticket #' + id + ' · ' + t.subject, body);
  }

  // ============================================================
  // PAGE: STICKERS
  // ============================================================
  route('stickers', async function (main) {
    clear(main);
    main.appendChild(el('div', { class: 'page-head flex-between' },
      el('div', null,
        el('h1', { text: 'Sticker packs' }),
        el('div', { class: 'page-sub', text: 'Four default packs ship. Create your own — upload stickers into any pack.' })
      ),
      el('button', { class: 'btn btn-primary', text: '+ New pack', onclick: createPackModal })
    ));

    const data = await api('stickers');
    const packs = data.data.packs || [];
    for (const p of packs) {
      const head = el('div', { class: 'flex-between' },
        el('div', null,
          el('h2', null, p.name, ' ', p.is_default ? el('span', { class: 'badge badge-accent', text: 'default' }) : null),
          el('div', { class: 'page-sub', text: (p.description || '') + (p.description ? '  ·  ' : '') + p.sticker_count + ' stickers' })
        ),
        el('div', { class: 'row' },
          p.pack_id ? el('button', { class: 'btn btn-primary btn-sm', text: 'Upload sticker', onclick: function () { uploadStickerModal(p); } }) : null,
          p.pack_id ? el('button', { class: 'btn btn-secondary btn-sm', text: 'Edit', onclick: function () { editPackModal(p); } }) : null,
          (p.pack_id && !p.is_default) ? el('button', { class: 'btn btn-danger btn-sm', text: 'Deactivate', onclick: function () {
            confirmDialog('Deactivate the pack "' + p.name + '"?', async function () {
              try { await api('admin_delete_sticker_pack', { method: 'POST', body: { pack_id: p.pack_id }});
                toast('Pack deactivated'); render(); }
              catch (e) { toast(e.message, 'error'); }
            }, { danger: true, title: 'Deactivate pack' });
          }}) : null
        )
      );

      const card = el('div', { class: 'card' }, head);
      if (p.stickers && p.stickers.length) {
        const grid = el('div', { class: 'sticker-grid' });
        for (const s of p.stickers) {
          const tile = el('div', { class: 'sticker-tile' });
          if (s.url) tile.appendChild(el('img', { src: s.url, alt: s.name }));
          else       tile.appendChild(el('div', { class: 'text-muted', style: { padding: '14px 0' }, text: '(no image)' }));
          tile.appendChild(el('div', { class: 'name', text: s.name }));
          tile.appendChild(el('button', { class: 'btn btn-secondary btn-sm mt-2', text: 'Remove', onclick: function () {
            confirmDialog('Remove sticker "' + s.name + '"?', async function () {
              try { await api('admin_delete_sticker', { method: 'POST', body: { sticker_id: s.sticker_id }}); toast('Removed'); render(); }
              catch (e) { toast(e.message, 'error'); }
            }, { danger: true });
          }}));
          grid.appendChild(tile);
        }
        card.appendChild(grid);
      } else {
        card.appendChild(el('div', { class: 'empty', text: 'No stickers yet — click "Upload sticker".' }));
      }
      main.appendChild(card);
    }
  });
  function createPackModal() {
    const name = el('input', { class: 'input', placeholder: 'e.g. Music' });
    const slug = el('input', { class: 'input', placeholder: 'auto if blank' });
    const desc = el('textarea', { class: 'textarea', placeholder: 'Short description' });
    let m;
    const body = el('div', null,
      field('Name', name),
      field('Slug (optional)', slug),
      field('Description', desc),
      el('div', { class: 'row', style: { justifyContent: 'flex-end' } },
        el('button', { class: 'btn btn-secondary', text: 'Cancel', onclick: function () { m.close(); } }),
        el('button', { class: 'btn btn-primary', text: 'Create', onclick: async function () {
          const body2 = { name: name.value };
          if (slug.value) body2.slug = slug.value;
          if (desc.value) body2.description = desc.value;
          try { await api('admin_create_sticker_pack', { method: 'POST', body: body2 }); toast('Pack created'); m.close(); render(); }
          catch (e) { toast(e.message, 'error'); }
        }})
      )
    );
    m = modal('New sticker pack', body);
  }
  function editPackModal(p) {
    const name = el('input', { class: 'input', value: p.name });
    const desc = el('textarea', { class: 'textarea' }); desc.value = p.description || '';
    let m;
    const body = el('div', null, field('Name', name), field('Description', desc),
      el('div', { class: 'row', style: { justifyContent: 'flex-end' } },
        el('button', { class: 'btn btn-secondary', text: 'Cancel', onclick: function () { m.close(); } }),
        el('button', { class: 'btn btn-primary', text: 'Save', onclick: async function () {
          try { await api('admin_edit_sticker_pack', { method: 'POST', body: { pack_id: p.pack_id, name: name.value, description: desc.value }});
            toast('Updated'); m.close(); render(); }
          catch (e) { toast(e.message, 'error'); }
        }})
      )
    );
    m = modal('Edit pack', body);
  }
  function uploadStickerModal(p) {
    const file = el('input', { type: 'file', accept: 'image/png,image/gif,image/webp' });
    const name = el('input', { class: 'input', placeholder: 'Sticker name' });
    let m;
    const body = el('div', null,
      el('p', { class: 'page-sub', text: 'PNG, GIF, or WebP — max 512 KB and 512×512 px.' }),
      field('File', file),
      field('Name', name),
      el('div', { class: 'row', style: { justifyContent: 'flex-end' } },
        el('button', { class: 'btn btn-secondary', text: 'Cancel', onclick: function () { m.close(); } }),
        el('button', { class: 'btn btn-primary', text: 'Upload', onclick: async function () {
          if (!file.files[0] || !name.value.trim()) return toast('Pick a file and a name.', 'error');
          const fd = new FormData();
          fd.append('sticker',  file.files[0]);
          fd.append('name',     name.value);
          fd.append('pack_id',  String(p.pack_id));
          try { await api('admin_upload_sticker', { method: 'POST', formData: fd }); toast('Uploaded'); m.close(); render(); }
          catch (e) { toast(e.message, 'error'); }
        }})
      )
    );
    m = modal('Upload sticker → ' + p.name, body);
  }

  // ============================================================
  // PAGE: EMAIL & BROADCAST
  // ============================================================
  route('email', async function (main) {
    clear(main);
    main.appendChild(el('div', { class: 'page-head' }, el('h1', { text: 'Email & broadcast' })));
    const tabs = el('div', { class: 'row', style: { marginBottom: '14px' } });
    const body = el('div');
    const tab = function (label, fn) {
      const b = el('button', { class: 'btn btn-secondary btn-sm', text: label, onclick: function () { show(fn); } });
      tabs.appendChild(b);
    };
    function show(view) { clear(body); view(); }

    tab('Single user', function () {
      const uid     = el('input', { class: 'input', type: 'number', placeholder: 'User ID' });
      const subject = el('input', { class: 'input' });
      const msg     = el('textarea', { class: 'textarea' });
      body.appendChild(el('div', { class: 'card' },
        field('User ID', uid),
        field('Subject', subject),
        field('Body', msg),
        el('button', { class: 'btn btn-primary', text: 'Send', onclick: async function () {
          try {
            const data = await api('admin_send_email', { method: 'POST', body: {
              user_id: parseInt(uid.value, 10), subject: subject.value, body: msg.value
            }});
            toast('Sent: ' + data.data.sent + ', failed: ' + data.data.failed);
          } catch (e) { toast(e.message, 'error'); }
        }})
      ));
    });

    tab('Broadcast', function () {
      const subject = el('input', { class: 'input' });
      const msg     = el('textarea', { class: 'textarea' });
      const verified = el('input', { type: 'checkbox', checked: true });
      body.appendChild(el('div', { class: 'card' },
        el('p', { class: 'page-sub', text: 'Up to 500 recipients per call.' }),
        el('label', { class: 'check' }, verified, ' Verified email only'),
        field('Subject', subject),
        field('Body',    msg),
        el('button', { class: 'btn btn-primary', text: 'Broadcast', onclick: async function () {
          try {
            const data = await api('admin_send_email', { method: 'POST', body: {
              broadcast: true,
              filter: { role: 'user', email_verified_only: verified.checked },
              subject: subject.value, body: msg.value
            }});
            toast('Sent: ' + data.data.sent + ', failed: ' + data.data.failed);
          } catch (e) { toast(e.message, 'error'); }
        }})
      ));
    });

    tab('History', async function () {
      const data = await api('admin_email_history');
      body.appendChild(el('div', { class: 'card' },
        tableFrom((data.data || []).map(function (r) {
          return {
            subject: r.subject, to: r.email, user: r.username || '-',
            status: statusBadge(r.status), at: fmtDate(r.created_at), err: r.error || ''
          };
        }), [
          { k: 'subject', label: 'Subject' },
          { k: 'to',      label: 'Email' },
          { k: 'user',    label: 'User' },
          { k: 'status',  label: 'Status' },
          { k: 'at',      label: 'At' },
          { k: 'err',     label: 'Error' }
        ])
      ));
    });

    main.appendChild(tabs);
    main.appendChild(body);
    tabs.children[0].click();
  });

  // ============================================================
  // PAGE: NOTIFICATIONS
  // ============================================================
  route('notifications', async function (main) {
    clear(main);
    main.appendChild(el('div', { class: 'page-head flex-between' },
      el('h1', { text: 'System notifications' }),
      el('button', { class: 'btn btn-secondary btn-sm', text: 'Mark all read', onclick: async function () {
        try { await api('admin_mark_notifications_read', { method: 'POST', body: { all: true }}); toast('All marked read'); render(); }
        catch (e) { toast(e.message, 'error'); }
      }})
    ));
    const data = await api('admin_notifications', { query: 'unread=1' });
    if (!data.data || !data.data.length) {
      main.appendChild(el('div', { class: 'card empty', text: 'Nothing unread. ✨' }));
      return;
    }
    for (const n of data.data) {
      main.appendChild(el('div', { class: 'card' },
        el('div', { class: 'flex-between' },
          el('div', null,
            el('div', { style: { fontWeight: '700' }, text: n.type }),
            el('div', { text: n.message }),
            el('div', { class: 'page-sub', text: fmtDate(n.created_at) })
          ),
          el('button', { class: 'btn btn-secondary btn-sm', text: 'Mark read', onclick: async function () {
            try { await api('admin_mark_notifications_read', { method: 'POST', body: { notification_ids: [n.notification_id] }}); render(); }
            catch (e) { toast(e.message, 'error'); }
          }})
        )
      ));
    }
  });

  // ============================================================
  // PAGE: SETTINGS (maintenance + deposit pause + routes peek)
  // ============================================================
  route('settings', async function (main) {
    clear(main);
    main.appendChild(el('div', { class: 'page-head' }, el('h1', { text: 'Settings' })));

    // Load both independently: if one is 503 (maintenance kicked in) we
    // still want the page to render so the admin can disable it.
    const safe = async function (p) { try { return await p; } catch (e) {
      return { data: {}, _error: e.message }; } };
    const mt = await safe(api('admin_maintenance'));
    const dp = await safe(api('admin_deposit_pause'));

    const mtMsg = el('input', { class: 'input', value: (mt.data && mt.data.message) || '' });
    main.appendChild(el('div', { class: 'card' },
      el('h2', { text: 'Maintenance mode' }),
      el('p', { class: 'page-sub' },
        mt.data.enabled
          ? el('span', { class: 'badge badge-danger', text: 'ENABLED' })
          : el('span', { class: 'badge badge-success', text: 'disabled' }),
        mt.data.set_at ? '  ·  set ' + fmtDate(mt.data.set_at) : ''
      ),
      field('Message shown to users', mtMsg),
      el('div', { class: 'row' },
        el('button', { class: 'btn btn-danger', text: 'Enable maintenance', onclick: async function () {
          try { await api('admin_maintenance', { method: 'POST', body: { enabled: true, message: mtMsg.value }}); toast('Maintenance enabled'); render(); }
          catch (e) { toast(e.message, 'error'); }
        }}),
        el('button', { class: 'btn btn-success', text: 'Disable maintenance', onclick: async function () {
          try { await api('admin_maintenance', { method: 'POST', body: { enabled: false }}); toast('Disabled'); render(); }
          catch (e) { toast(e.message, 'error'); }
        }})
      )
    ));

    const dpMsg = el('input', { class: 'input', value: (dp.data && dp.data.message) || '' });
    main.appendChild(el('div', { class: 'card' },
      el('h2', { text: 'Deposit pause' }),
      el('p', { class: 'page-sub' },
        dp.data.deposits_paused
          ? el('span', { class: 'badge badge-warning', text: 'PAUSED' })
          : el('span', { class: 'badge badge-success', text: 'open' })
      ),
      field('Message shown to users', dpMsg),
      el('div', { class: 'row' },
        el('button', { class: 'btn btn-warning', text: 'Pause deposits', onclick: async function () {
          try { await api('admin_deposit_pause', { method: 'POST', body: { paused: true, message: dpMsg.value }}); toast('Deposits paused'); render(); }
          catch (e) { toast(e.message, 'error'); }
        }}),
        el('button', { class: 'btn btn-success', text: 'Resume deposits', onclick: async function () {
          try { await api('admin_deposit_pause', { method: 'POST', body: { paused: false }}); toast('Deposits resumed'); render(); }
          catch (e) { toast(e.message, 'error'); }
        }})
      )
    ));

    main.appendChild(el('div', { class: 'card' },
      el('h2', { text: 'Routes catalogue' }),
      el('p', { class: 'page-sub', text: 'Live list of every API route. Admin-only.' }),
      el('button', { class: 'btn btn-secondary btn-sm', text: 'Load catalogue', onclick: async function (e) {
        const target = e.currentTarget;
        target.disabled = true;
        try {
          const data = await api('routes');
          target.parentNode.appendChild(tableFrom(data.data.routes, [
            { k: 'route',  label: 'Route' },
            { k: 'method', label: 'Method' },
            { k: 'auth',   label: 'Auth' },
            { k: 'description', label: 'Description' }
          ]));
          target.remove();
        } catch (err) { toast(err.message, 'error'); target.disabled = false; }
      }})
    ));
  });

  // ============================================================
  // BOOT
  // ============================================================
  if (!window.location.hash) window.location.hash = '#dashboard';
  render();
})();
</script>
</body>
</html>
