// ============================================================
// API CLIENT — API Platform returns JSON-LD (hydra:member)
// ============================================================
const API = '/api';
let authToken = globalThis.rtAuth?.getToken?.() || localStorage.getItem('rt_token') || null;

async function apiFetch(path, options = {}) {
  const headers = { 'Content-Type': 'application/json' };
  if (options.headers) Object.assign(headers, options.headers);
  if (authToken) headers['Authorization'] = `Bearer ${authToken}`;

  const res = await fetch(API + path, { ...options, headers });

  if (res.status === 401) { logout(); return null; }
  if (res.status === 204) return null;

  const raw = await res.text();
  let data = null;
  try {
    data = raw ? JSON.parse(raw) : null;
  } catch {
    data = null;
  }

  if (!res.ok) {
    // API Platform error format
    const msg = data?.['hydra:description'] || data?.detail || data?.message || `Erreur API (${res.status})`;
    throw new Error(msg);
  }
  return data;
}

// API Platform collections return { "hydra:member": [...] }
function members(data) {
  return data?.['hydra:member'] ?? data ?? [];
}

function logout() {
  if (globalThis.rtAuth?.clearToken) {
    globalThis.rtAuth.clearToken();
  } else {
    localStorage.removeItem('rt_token');
  }
  authToken = null;
  globalThis.location.href = '/login';
}

// Ensure inline onclick handlers can always resolve these functions
globalThis.logout = logout;


// ============================================================
// DATA
// ============================================================
let logData   = [];
let racesData = [];
let plansData = [];
let dashboardAdvice = [];
let dashboardMetrics = null;
let state     = { semiDone: {}, planMeta: {}, extraPlans: [] };


// Plans data always loaded from API—no localStorage caching
function normalizeDateForStorage(value) {
  if (!value) return '';
  const s = String(value).trim();
  if (!s) return '';
  if (/^\d{4}-\d{2}-\d{2}$/.test(s)) return s;
  const fr = /^(\d{2})\/(\d{2})\/(\d{4})$/.exec(s);
  if (fr) return `${fr[3]}-${fr[2]}-${fr[1]}`;
  const isoPrefix = /^(\d{4}-\d{2}-\d{2})T/.exec(s);
  if (isoPrefix) return isoPrefix[1];
  const d = new Date(s);
  if (Number.isNaN(d.getTime())) return '';
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, '0');
  const day = String(d.getDate()).padStart(2, '0');
  return `${y}-${m}-${day}`;
}

function formatDateForInput(value) {
  const iso = normalizeDateForStorage(value);
  if (!iso) return '';
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso);
  if (!m) return '';
  return `${m[3]}/${m[2]}/${m[1]}`;
}

function buildUniquePlanName(baseName) {
  const base = String(baseName || '').trim();
  if (!base) return '';
  const existing = new Set((state.extraPlans || []).map(p => p.key || p.title || p.id));
  if (!existing.has(base)) return base;
  let i = 2;
  while (existing.has(`${base} (${i})`)) i += 1;
  return `${base} (${i})`;
}

function formatDisplayName(value) {
  const raw = String(value || '').trim();
  if (!raw) return 'Inconnu';
  return raw.charAt(0).toUpperCase() + raw.slice(1);
}

function normalizePlan(r) {
  return {
    id: iriToId(r['@id']) ?? r.id,
    name: r.name,
  };
}

async function createPlanInDb(name) {
  const data = await apiFetch('/plans', {
    method: 'POST',
    body: JSON.stringify({ name }),
  });
  return normalizePlan(data);
}

async function renamePlanInDb(planId, name) {
  const data = await apiFetch(`/plans/${planId}`, {
    method: 'PUT',
    body: JSON.stringify({ name }),
  });
  return normalizePlan(data);
}

async function deletePlanInDb(planId) {
  await apiFetch(`/plans/${planId}`, { method: 'DELETE' });
}

function planDetailApiPath(row) {
  if (!row || typeof row !== 'object') return null;
  if (typeof row['@id'] === 'string' && row['@id'].startsWith('/api/')) return row['@id'];
  const id = Number.parseInt(row.id, 10);
  if (Number.isFinite(id)) return `/plan_details/${id}`;
  return null;
}

async function fetchPlanSessionsByPlanId(planId) {
  const planIri = encodeURIComponent(`/api/plans/${Number(planId)}`);
  const data = await apiFetch(`/plan_details?plan=${planIri}&order[position]=asc&pagination=false`);
  return members(data).sort((a, b) => (a.position || 0) - (b.position || 0));
}

async function replacePlanSessionsInDb(planId, sessions, doneMap = {}) {
  const existing = await fetchPlanSessionsByPlanId(planId);
  const sharedCount = Math.min(existing.length, sessions.length);

  const buildPayload = (session, idx) => ({
    plan: `/api/plans/${planId}`,
    position: idx + 1,
    sem: session.sem ?? null,
    sessionDate: normalizeDateForStorage(session.date) || null,
    format: session.format || "45'@Z2",
    pe: session.pe || null,
    totalMin: session.total ?? null,
    isOptional: !!session.opt,
    isDone: !!doneMap[idx],
  });

  for (let i = 0; i < sharedCount; i += 1) {
    const path = planDetailApiPath(existing[i]);
    if (!path) continue;
    await apiFetch(path, {
      method: 'PUT',
      body: JSON.stringify(buildPayload(sessions[i], i)),
    });
  }

  for (let i = sessions.length; i < existing.length; i += 1) {
    const path = planDetailApiPath(existing[i]);
    if (!path) continue;
    await apiFetch(path, { method: 'DELETE' });
  }

  for (let i = sharedCount; i < sessions.length; i += 1) {
    const payload = {
      plan: `/api/plans/${planId}`,
      position: i + 1,
      sem: sessions[i].sem ?? null,
      sessionDate: normalizeDateForStorage(sessions[i].date) || null,
      format: sessions[i].format || "45'@Z2",
      pe: sessions[i].pe || null,
      totalMin: sessions[i].total ?? null,
      isOptional: !!sessions[i].opt,
      isDone: !!doneMap[i],
    };

    let retries = 2;
    while (retries > 0) {
      try {
        await apiFetch('/plan_details', { method: 'POST', body: JSON.stringify(payload) });
        break;
      } catch (e) {
        if (!String(e.message || '').includes('uniq_plan_details_user_plan_pos')) throw e;
        retries--;
        if (retries === 0) throw e;
        const refreshed = await fetchPlanSessionsByPlanId(planId);
        const rowAtPos = refreshed.find(r => Number(r.position) === i + 1);
        if (rowAtPos) {
          const path = planDetailApiPath(rowAtPos);
          if (!path) throw e;
          await apiFetch(path, {
            method: 'PUT',
            body: JSON.stringify(payload),
          });
          break;
        }
      }
    }
  }
}

function mapDbRowsToPlans(rows, plans) {
  const grouped = {};
  const plansById = new Map((plans || []).map(p => [Number(p.id), p]));

  const getPlanIdFromRef = (ref) => {
    if (typeof ref === 'number') return ref;
    if (typeof ref === 'string') return iriToId(ref);
    if (ref && typeof ref === 'object') return iriToId(ref['@id'] || ref.id);
    return null;
  };

  rows.forEach(row => {
    const planId = getPlanIdFromRef(row.plan);
    if (!planId) return;
    const planObj = plansById.get(Number(planId));
    const planKey = planObj?.name || row.planName || String(planId);
    if (!grouped[planId]) {
      grouped[planId] = {
        id: planId,
        key: planKey,
        title: planKey === 'semi' ? 'Plan Semi (exemple)' : planKey,
        sub: planKey === 'semi' ? 'Plan fourni avec l\'application · blocs hebdomadaires' : '',
        sessions: [],
        done: {},
      };
    }

    const idx = Math.max(0, (row.position || 1) - 1);
    grouped[planId].sessions[idx] = {
      sem: row.sem,
      date: normalizeDateForStorage(row.sessionDate),
      format: row.format,
      pe: row.pe,
      total: row.totalMin,
      opt: !!row.isOptional,
    };

    if (row.isDone) grouped[planId].done[idx] = true;
  });

  return Object.values(grouped).map(plan => ({
    ...plan,
    sessions: plan.sessions.filter(Boolean),
  }));
}

async function loadPlansFromDb() {
  const [plansRes, sessionsRes] = await Promise.all([
    apiFetch('/plans?order[name]=asc&pagination=false'),
    apiFetch('/plan_details?order[position]=asc&pagination=false'),
  ]);
  plansData = members(plansRes).map(normalizePlan);
  const mapped = mapDbRowsToPlans(members(sessionsRes), plansData);
  const byId = new Set(mapped.map((p) => Number(p.id)));

  plansData.forEach((plan) => {
    const planId = Number(plan.id);
    if (byId.has(planId)) return;
    mapped.push({
      id: plan.id,
      key: plan.name,
      title: plan.name === 'semi' ? 'Plan Semi (exemple)' : plan.name,
      sub: plan.name === 'semi' ? 'Plan fourni avec l\'application · blocs hebdomadaires' : '',
      sessions: [],
      done: {},
    });
  });

  state.extraPlans = mapped;
}

async function initializeSemiPlan() {
  const semiPlanRef = (plansData || []).find(p => p.name === 'semi');
  if (!semiPlanRef) {
    await createPlanInDb('semi');
    await loadPlansFromDb();
  }
}

async function loadAllData() {
  const [logs, races, checks] = await Promise.all([
    apiFetch('/run_logs?order[date]=desc&pagination=false'),
    apiFetch('/races?order[date]=asc&pagination=false'),
    apiFetch('/plan_progresses?pagination=false'),
  ]);
  const checksList = members(checks);

  // Normalize API Platform IRI ids to plain int ids
  logData   = members(logs).map(normalizeLog);
  racesData = members(races).map(normalizeRace);

  state = { semiDone: {}, planMeta: {}, extraPlans: [] };
  checksList.forEach(c => {
    if (state[c.planKey] !== undefined) {
      state[c.planKey][c.sessionIndex] = c.done;
    }
  });

  try {
    await loadPlansFromDb();

    // Apply saved progress to extra plans (supports both new numeric keys and legacy extra:<id> keys)
    checksList.forEach((c) => {
      const extra = (state.extraPlans || []).find((p) => (
        String(p.id) === String(c.planKey) || `extra:${p.id}` === String(c.planKey)
      ));
      if (!extra) return;
      extra.done[c.sessionIndex] = !!c.done;
    });

    await initializeSemiPlan();
    await loadDashboardMetrics();
  } catch {
    // Keep app usable even if plans endpoints are temporarily unavailable.
    dashboardMetrics = null;
  }
}

async function loadDashboardAdvice() {
  try {
    const data = await apiFetch('/dashboard/advice');
    dashboardAdvice = members(data?.items || []);
  } catch {
    dashboardAdvice = [];
  }
}

async function loadDashboardMetrics() {
  try {
    dashboardMetrics = await apiFetch('/dashboard/metrics');
  } catch {
    dashboardMetrics = null;
  }
}

function requestDashboardRefresh() {
  void loadDashboardMetrics().finally(() => {
    renderDashboard();
  });
}

async function savePlanProgress(planKey, sessionIndex, done) {
  await apiFetch('/plan_progresses', {
    method: 'POST',
    body: JSON.stringify({ planKey, sessionIndex, done: !!done }),
  });
}

function renderDashboardAdvice(metrics = {}) {
  const box = document.getElementById('dashboard-advice');
  if (!box) return;
  const items = Array.isArray(dashboardAdvice) ? [...dashboardAdvice] : [];

  const load = metrics?.trainingLoad;
  if (load?.hasData) {
    const toneByKey = {
      balanced: 'success',
      watch: 'warning',
      high: 'warning',
      under: 'info',
      initial: 'encourage',
    };
    const iconByKey = {
      balanced: '✅',
      watch: '⚠️',
      high: '⛔',
      under: '🟦',
      initial: '🧭',
    };

    items.unshift({
      tone: toneByKey[load.statusKey] || 'info',
      icon: iconByKey[load.statusKey] || '⚖️',
      title: `Charge d'entrainement · ${load.statusLabel || 'Statut'}`,
      text: `${load.recommendation || ''} (7j: ${Number(load.acute || 0).toFixed(0)} · base: ${Number(load.chronic || 0).toFixed(0)})`,
    });
  }

  if (!items.length) {
    box.replaceChildren();
    return;
  }

  const clsForTone = (tone) => {
    if (tone === 'success') return 'advice-success';
    if (tone === 'warning') return 'advice-warning';
    if (tone === 'encourage') return 'advice-encourage';
    return 'advice-info';
  };

  const stackTpl = document.getElementById('dashboard-advice-stack-template');
  const itemTpl = document.getElementById('dashboard-advice-item-template');
  if (!(stackTpl instanceof HTMLTemplateElement) || !(itemTpl instanceof HTMLTemplateElement)) {
    box.replaceChildren();
    return;
  }

  const stack = stackTpl.content.firstElementChild.cloneNode(true);
  const nodes = items.map((item) => {
    const card = itemTpl.content.firstElementChild.cloneNode(true);
    card.classList.add(clsForTone(item?.tone));

    const iconEl = card.querySelector('.advice-icon');
    const titleEl = card.querySelector('.advice-title');
    const textEl = card.querySelector('.advice-text');

    if (iconEl) iconEl.textContent = item?.icon || '💡';
    if (titleEl) titleEl.textContent = item?.title || 'Conseil du jour';
    if (textEl) textEl.textContent = item?.text || '';

    if (item?.actionType === 'openPlanSession' && Number.isFinite(Number(item?.actionPlanId))) {
      const actionBtn = document.createElement('button');
      actionBtn.type = 'button';
      actionBtn.className = 'advice-action-btn';
      actionBtn.textContent = item?.actionLabel || 'Aller au plan';
      actionBtn.addEventListener('click', () => {
        focusPlannedSessionFromAdvice(Number(item.actionPlanId), Number(item.actionSessionIndex || 0));
      });
      card.querySelector('.advice-content')?.appendChild(actionBtn);
    }

    return card;
  });

  stack.replaceChildren(...nodes);
  box.replaceChildren(stack);
}

function activatePlansSection() {
  const plansBtn = Array.from(document.querySelectorAll('nav button')).find((btn) =>
    String(btn.getAttribute('onclick') || '').includes("showSection('plans'")
  );

  if (plansBtn) {
    showSection('plans', plansBtn);
    return;
  }

  const plansSection = document.getElementById('plans');
  if (!plansSection) return;
  document.querySelectorAll('section').forEach((s) => s.classList.remove('visible'));
  plansSection.classList.add('visible');
}

function highlightPlannedSession(sessionIndex) {
  const row = document.querySelector(`#plans-detail-weeks .session-row[data-session-index="${sessionIndex}"]`);
  if (!row) return false;

  const check = row.querySelector('.session-check');
  row.scrollIntoView({ behavior: 'smooth', block: 'center' });
  row.classList.add('session-row-highlight');
  check?.classList.add('session-check-pulse');
  globalThis.setTimeout(() => row.classList.remove('session-row-highlight'), 1400);
  globalThis.setTimeout(() => check?.classList.remove('session-check-pulse'), 1600);
  return true;
}

function focusPlannedSessionFromAdvice(planId, sessionIndex) {
  if (!Number.isFinite(planId)) return;

  const plansDetailRoot = document.getElementById('plans-detail-weeks');
  const plansSection = document.getElementById('plans');

  // On dashboard-only pages, navigate to /plans and carry focus info.
  if (!plansDetailRoot || !plansSection) {
    const target = new URL('/plans', globalThis.location.origin);
    target.searchParams.set('focusPlanId', String(planId));
    target.searchParams.set('focusSessionIndex', String(Number(sessionIndex) || 0));
    globalThis.location.href = target.toString();
    return;
  }

  activatePlansSection();
  openPlan(planId);

  globalThis.setTimeout(() => {
    highlightPlannedSession(Number(sessionIndex) || 0);
  }, 120);
}

function consumeAdviceFocusFromUrl() {
  const params = new URLSearchParams(globalThis.location.search || '');
  const rawPlanId = params.get('focusPlanId');
  if (!rawPlanId) return;

  const planId = Number(rawPlanId);
  if (!Number.isFinite(planId)) return;

  const sessionIndex = Number(params.get('focusSessionIndex') || 0);
  openPlan(planId);

  // Try multiple times while the plan detail DOM settles.
  let attempts = 0;
  const maxAttempts = 10;
  const tick = () => {
    attempts += 1;
    if (highlightPlannedSession(sessionIndex)) {
      const cleanUrl = new URL(globalThis.location.href);
      cleanUrl.searchParams.delete('focusPlanId');
      cleanUrl.searchParams.delete('focusSessionIndex');
      globalThis.history.replaceState({}, '', `${cleanUrl.pathname}${cleanUrl.search}${cleanUrl.hash}`);
      return;
    }
    if (attempts < maxAttempts) {
      globalThis.setTimeout(tick, 120);
    }
  };
  globalThis.setTimeout(tick, 80);
}

function iriToId(iri) {
  if (!iri) return null;
  const parts = String(iri).split('/');
  return Number.parseInt(parts.at(-1), 10);
}

function normalizeLog(r) {
  return {
    id:       iriToId(r['@id']) ?? r.id,
    date:     r.date,
    km:       r.km,
    duration: r.duration,
    allure:   r.allure,
    gap:      r.gap,
    dplus:    r.dplus,
    bpm:      r.bpm,
    run_type: r.runType ?? r.run_type,
    notes:    r.notes,
  };
}

function normalizeRace(r) {
  return {
    id:        iriToId(r['@id']) ?? r.id,
    name:      r.name,
    date:      r.date,
    distance:  r.distance,
    objective: r.objective,
    result:    r.result,
  };
}

// ============================================================
// UTILS
// ============================================================
function durToSec(dur) {
  if (!dur) return null;
  const p = dur.split(':').map(Number);
  return p.length === 3 ? p[0]*3600+p[1]*60+p[2] : p[0]*60+p[1];
}
function secToDur(s) {
  const h=Math.floor(s/3600), m=Math.floor((s%3600)/60), sec=Math.floor(s%60);
  return [h,m,sec].map(x=>String(x).padStart(2,'0')).join(':');
}
function allureClass(a) {
  if (!a) return '';
  const m = Number.parseInt(a, 10);
  if (m <= 8) return 'allure-fast';
  if (m <= 9) return 'allure-mid';
  return 'allure-slow';
}
function formatDate(d) {
  if (!d) return '—';
  return new Date(d).toLocaleDateString('fr-FR',{day:'2-digit',month:'short',year:'numeric'});
}
function getDaysTo(ds) {
  const n=new Date(); n.setHours(0,0,0,0);
  return Math.round((new Date(ds)-n)/86400000);
}
function cloneTemplate(id) {
  const tpl = document.getElementById(id);
  if (!(tpl instanceof HTMLTemplateElement)) return null;
  const node = tpl.content.firstElementChild;
  return node ? node.cloneNode(true) : null;
}
function appendFormattedZones(target, format) {
  if (!target) return;
  const source = String(format || '');
  const frag = document.createDocumentFragment();
  const re = /@Z(\d)/g;
  let last = 0;
  let m = re.exec(source);
  while (m) {
    if (m.index > last) {
      frag.appendChild(document.createTextNode(source.slice(last, m.index)));
    }
    const span = document.createElement('span');
    span.className = `zone-inline zone-z${m[1]}`;
    span.textContent = `@Z${m[1]}`;
    frag.appendChild(span);
    last = re.lastIndex;
    m = re.exec(source);
  }
  if (last < source.length) {
    frag.appendChild(document.createTextNode(source.slice(last)));
  }
  target.replaceChildren(frag);
}
function createSvgEl(tag, attrs = {}, text = null) {
  const el = document.createElementNS('http://www.w3.org/2000/svg', tag);
  Object.entries(attrs).forEach(([k, v]) => {
    if (v !== null && v !== undefined) el.setAttribute(k, String(v));
  });
  if (text !== null) el.textContent = text;
  return el;
}
function notify(msg) {
  const n=document.getElementById('notif');
  n.textContent=msg; n.classList.add('show');
  setTimeout(()=>n.classList.remove('show'),2500);
}
function computeGAP(allureSec, km, dplus) {
  if (!dplus||dplus<=0||!km) return null;
  const g=dplus/(km*1000);
  const gapSec=Math.round(allureSec-(g*7.5*allureSec));
  return gapSec>0?secToDur(gapSec).slice(3):null;
}
function showSection(id, btn) {
  if (id === 'plans' && currentPlanId) {
    backToPlansList();
  }
  document.querySelectorAll('section').forEach(s=>s.classList.remove('visible'));
  document.querySelectorAll('nav button').forEach(b=>b.classList.remove('active'));
  document.getElementById(id).classList.add('visible');
  btn.classList.add('active');
}
function addHoverListeners(tbodyId) {
  document.querySelectorAll('#'+tbodyId+' tr').forEach(tr=>{
    const b=tr.querySelector('.action-btns');
    if(!b)return;
    tr.addEventListener('mouseenter',()=>b.style.opacity='1');
    tr.addEventListener('mouseleave',()=>b.style.opacity='0');
  });
}

// ============================================================
// DASHBOARD
// ============================================================
function renderDashboard() {
  const metrics = dashboardMetrics || {};
  const dashDateEl = document.getElementById('dash-date');
  if (!dashDateEl) return;
  dashDateEl.textContent =
    'Mise à jour · ' + new Date().toLocaleDateString('fr-FR',{day:'2-digit',month:'long',year:'numeric'});
  renderDashboardAdvice(metrics);
  const kpisData = metrics.kpis || {};

  const kpiGrid = document.getElementById('kpi-grid');
  if (kpiGrid) {
    const kpis = [
      { tone: 'green', label: 'Allure moy.', value: kpisData.avgAllure || '—', unit: 'min/km' },
      { tone: 'orange', label: 'Durée la plus longue', value: kpisData.longestDuration || '—', unit: 'hh:mm:ss' },
      { tone: 'accent', label: 'Plus grande distance', value: Number(kpisData.longestDistance || 0).toFixed(1), unit: 'km' },
      { tone: 'blue', label: 'BPM moy. EF', value: String(kpisData.avgBpm ?? '—'), unit: 'bpm' },
    ];
    const kpiNodes = kpis.map((kpi) => {
      const node = cloneTemplate('dashboard-kpi-template') || document.createElement('article');
      node.classList.add(kpi.tone);
      const labelEl = node.querySelector('.kpi-label');
      const valueEl = node.querySelector('.kpi-value');
      const unitEl = node.querySelector('.kpi-unit');
      if (labelEl) labelEl.textContent = kpi.label;
      if (valueEl) valueEl.textContent = kpi.value;
      if (unitEl) unitEl.textContent = kpi.unit;
      return node;
    });
    kpiGrid.replaceChildren(...kpiNodes);
  }

  const progress = metrics.planProgress || { title: '', done: 0, total: 0, pct: 0 };
  const labelEl = document.getElementById('progress-plan-label');
  if (labelEl) labelEl.textContent = progress.title;
  document.getElementById('tempo-pct').textContent=progress.pct+'%';
  document.getElementById('tempo-bar').style.width=progress.pct+'%';
  document.getElementById('tempo-meta').textContent=`${progress.done} / ${progress.total} séances complétées`;

  const barsSource = Array.isArray(metrics.monthlyBars) ? metrics.monthlyBars : [];
  const monthlyChart = document.getElementById('monthly-chart');
  if (monthlyChart) {
    const barNodes = barsSource.map((bar, index) => {
      const km = Number(bar.km || 0);
      const h = Number(bar.height || 0);
      const node = cloneTemplate('monthly-bar-template') || document.createElement('article');
      const barEl = node.querySelector('.bar');
      const labelEl = node.querySelector('.bar-label');
      if (barEl) {
        barEl.style.height = `${h}px`;
        barEl.title = `${km.toFixed(1)} km`;
        barEl.style.background = `var(--z${(index % 5) + 1})`;
      }
      if (labelEl) {
        labelEl.replaceChildren(
          document.createTextNode(String(bar.label || '—')),
          document.createElement('br'),
          document.createTextNode(`${km.toFixed(0)}km`)
        );
      }
      return node;
    });
    monthlyChart.replaceChildren(...barNodes);
  }

  const raceTbody = document.getElementById('race-tbody');
  if (raceTbody) {
    const rows = (Array.isArray(metrics.racesTable) ? metrics.racesTable : []).map((r) => {
      const row = cloneTemplate('dashboard-race-row-template') || document.createElement('tr');
      const nameEl = row.querySelector('.dashboard-race-name');
      const dateEl = row.querySelector('.dashboard-race-date');
      const distEl = row.querySelector('.dashboard-race-dist');
      const objEl = row.querySelector('.dashboard-race-obj');
      const statusEl = row.querySelector('.dashboard-race-status');
      if (nameEl) nameEl.textContent = r.name || '—';
      if (dateEl) dateEl.textContent = formatDate(r.date);
      if (distEl) distEl.textContent = r.dist || '—';
      if (objEl) objEl.textContent = r.obj || '—';
      if (statusEl) {
        statusEl.classList.add(r.statusClass || 'badge-future');
        statusEl.textContent = r.statusLabel || '—';
      }
      return row;
    });
    raceTbody.replaceChildren(...rows);
  }

  renderCoherence();
  renderProjections();
  renderTrainingLoad();
  renderEF();
  renderEfBpmChart();
}

function renderTrainingLoad() {
  const wrap = document.getElementById('training-load-wrap');
  if (!wrap) return;

  const load = dashboardMetrics?.trainingLoad || {};
  if (!load.hasData) {
    wrap.style.display = 'none';
    return;
  }
  wrap.style.display = 'block';

  const statusEl = document.getElementById('training-load-status');
  const ratioEl = document.getElementById('training-load-ratio');
  const acuteEl = document.getElementById('training-load-acute');
  const chronicEl = document.getElementById('training-load-chronic');
  const deltaEl = document.getElementById('training-load-delta');
  const recoEl = document.getElementById('training-load-reco');

  setTrainingLoadStatusChip(statusEl, load);

  if (ratioEl) ratioEl.textContent = load.ratio === null ? 'Ratio —' : `Ratio ${Number(load.ratio).toFixed(2)}`;
  if (acuteEl) acuteEl.textContent = Number(load.acute || 0).toFixed(1);
  if (chronicEl) chronicEl.textContent = Number(load.chronic || 0).toFixed(1);
  if (deltaEl) {
    const delta = Number(load.deltaPct || 0);
    deltaEl.textContent = `${delta >= 0 ? '+' : ''}${delta}%`;
    deltaEl.style.color = getTrainingLoadDeltaColor(delta);
  }
  if (recoEl) recoEl.textContent = load.recommendation || '';

  renderTrainingLoadChart(Array.isArray(load.weekly) ? load.weekly : []);
}

function setTrainingLoadStatusChip(statusEl, load) {
  if (!statusEl) return;
  const statusColor = load.statusColor || 'var(--text)';
  statusEl.textContent = load.statusLabel || 'Initialisation';
  statusEl.style.color = statusColor;
  statusEl.style.borderColor = `color-mix(in srgb, ${statusColor} 65%, var(--border))`;
  statusEl.style.background = `color-mix(in srgb, ${statusColor} 16%, var(--surface2))`;
}

function getTrainingLoadDeltaColor(delta) {
  if (delta > 15) return 'var(--accent3)';
  if (delta < -15) return 'var(--z2)';
  return 'var(--z1)';
}

function renderTrainingLoadChart(weeklyData) {
  const container = document.getElementById('training-load-chart');
  if (!container) return;

  if (!Array.isArray(weeklyData) || weeklyData.length === 0) {
    container.replaceChildren();
    return;
  }

  const W = container.clientWidth || 600;
  const H = 150;
  const PAD = { top: 12, right: 12, bottom: 34, left: 28 };
  const cW = W - PAD.left - PAD.right;
  const cH = H - PAD.top - PAD.bottom;
  const maxVal = Math.max(1, ...weeklyData.map((w) => Number(w.load || 0)));
  const barW = cW / weeklyData.length;

  const svg = createSvgEl('svg', { width: W, height: H, xmlns: 'http://www.w3.org/2000/svg' });

  const pts = [];
  weeklyData.forEach((w, i) => {
    const val = Number(w.load || 0);
    const x = PAD.left + i * barW + (barW / 2);
    const h = (val / maxVal) * cH;
    const y = PAD.top + (cH - h);
    const bw = Math.max(8, barW - 8);

    svg.appendChild(createSvgEl('rect', {
      x: (x - bw / 2).toFixed(1),
      y: y.toFixed(1),
      width: bw.toFixed(1),
      height: h.toFixed(1),
      rx: 3,
      fill: 'color-mix(in srgb, var(--accent2) 72%, var(--surface2))',
      opacity: 0.6,
    }));

    pts.push(`${x.toFixed(1)},${y.toFixed(1)}`);

    svg.appendChild(createSvgEl('text', {
      x: x.toFixed(1),
      y: (H - 4).toFixed(1),
      'text-anchor': 'middle',
      fill: 'var(--text-muted)',
      'font-size': 8,
      'font-family': 'monospace',
    }, String(w.label || '—')));
  });

  svg.appendChild(createSvgEl('polyline', {
    points: pts.join(' '),
    fill: 'none',
    stroke: 'var(--z1)',
    'stroke-width': 1.8,
    'stroke-opacity': 0.9,
  }));

  pts.forEach((p) => {
    const [cx, cy] = p.split(',');
    svg.appendChild(createSvgEl('circle', {
      cx,
      cy,
      r: 2.6,
      fill: 'var(--z1)',
      stroke: 'var(--surface)',
      'stroke-width': 1,
    }));
  });

  container.replaceChildren(svg);
}


// ============================================================
// EF TRACKER
// ============================================================
function renderEF() {
  const metrics = dashboardMetrics || {};
  const efKpis = metrics.efKpis || { items: [], emptyMessage: '' };
  const ef = metrics.ef || { hasData: false, emptyMessage: '', chart: { paceTicks: [], bpmTicks: [], pacePoints: [], bpmPoints: [], efDots: [] }, tableRows: [], meta: '' };

  const kpiEl = document.getElementById('ef-kpis');
  if (!kpiEl) return;
  const efTbody = document.getElementById('ef-tbody');
  const chartEl = document.getElementById('ef-chart-container');
  if (!efTbody || !chartEl) return;

  if (!ef.hasData) {
    const emptyNode = cloneTemplate('ef-empty-template') || document.createElement('div');
    if (emptyNode) emptyNode.textContent = ef.emptyMessage || efKpis.emptyMessage || 'Pas encore assez de sorties EF avec BPM enregistre (minimum 2).';
    kpiEl.replaceChildren(emptyNode);
    efTbody.replaceChildren();
    chartEl.style.display = 'none';
    return;
  }
  chartEl.style.display = 'block';

  const kpiCards = (Array.isArray(efKpis.items) ? efKpis.items : []).map((item) => {
    const card = cloneTemplate('ef-kpi-card-template') || document.createElement('div');
    const labelEl = card.querySelector('.ef-kpi-label');
    const valueEl = card.querySelector('.ef-kpi-value');
    const subEl = card.querySelector('.ef-kpi-sub');
    if (labelEl) labelEl.textContent = item.label;
    if (valueEl) {
      valueEl.style.color = item.valueColor || '';
      valueEl.textContent = item.value;
    }
    if (subEl) subEl.textContent = item.meta;
    return card;
  });
  kpiEl.replaceChildren(...kpiCards);

  // ── SVG Chart ──────────────────────────────────────────────
  const W = chartEl.clientWidth || 600, H = 180;
  const PAD = { top: 16, right: 52, bottom: 32, left: 52 };
  const cW = W - PAD.left - PAD.right, cH = H - PAD.top - PAD.bottom;

  const chart = ef.chart || { paceTicks: [], bpmTicks: [], pacePoints: [], bpmPoints: [], efDots: [] };
  const xSc  = x => PAD.left + x * cW;
  const ySc = y => PAD.top + y * cH;

  const allPts = (chart.pacePoints || []).map(p => `${xSc(Number(p.x || 0)).toFixed(1)},${ySc(Number(p.y || 0)).toFixed(1)}`).join(' ');
  const bPts = (chart.bpmPoints || []).map(p => `${xSc(Number(p.x || 0)).toFixed(1)},${ySc(Number(p.y || 0)).toFixed(1)}`).join(' ');
  const svg = createSvgEl('svg', { width: W, height: H, xmlns: 'http://www.w3.org/2000/svg' });

  (chart.paceTicks || []).forEach((tick) => {
    const t = Number(tick.t || 0);
    const y = PAD.top + (1 - t) * cH;
    svg.appendChild(createSvgEl('line', {
      x1: PAD.left,
      y1: y.toFixed(1),
      x2: W - PAD.right,
      y2: y.toFixed(1),
      stroke: 'var(--border)',
      'stroke-width': 1,
    }));
    svg.appendChild(createSvgEl('text', {
      x: PAD.left - 6,
      y: (y + 4).toFixed(1),
      'text-anchor': 'end',
      fill: 'var(--text-muted)',
      'font-size': 9,
      'font-family': 'monospace',
    }, String(tick.label || '')));
  });

  (chart.bpmTicks || []).forEach((tick) => {
    const t = Number(tick.t || 0);
    const y = PAD.top + (1 - t) * cH;
    svg.appendChild(createSvgEl('text', {
      x: W - PAD.right + 6,
      y: (y + 4).toFixed(1),
      fill: 'var(--accent2)',
      'font-size': 9,
      'font-family': 'monospace',
    }, String(tick.label || '')));
  });

  svg.appendChild(createSvgEl('polyline', {
    points: allPts,
    fill: 'none',
    stroke: 'var(--accent)',
    'stroke-width': 1.5,
    'stroke-opacity': 0.4,
    'stroke-dasharray': '3,3',
  }));
  if ((chart.bpmPoints || []).length > 1) {
    svg.appendChild(createSvgEl('polyline', {
      points: bPts,
      fill: 'none',
      stroke: 'var(--accent2)',
      'stroke-width': 2,
      'stroke-opacity': 0.8,
    }));
  }
  (chart.efDots || []).forEach((p) => {
    svg.appendChild(createSvgEl('circle', {
      cx: xSc(Number(p.x || 0)).toFixed(1),
      cy: ySc(Number(p.paceY || 0)).toFixed(1),
      r: 4,
      fill: 'var(--accent)',
      stroke: 'var(--surface)',
      'stroke-width': 1.5,
    }));
    svg.appendChild(createSvgEl('circle', {
      cx: xSc(Number(p.x || 0)).toFixed(1),
      cy: ySc(Number(p.bpmY || 0)).toFixed(1),
      r: 4,
      fill: 'var(--accent2)',
      stroke: 'var(--surface)',
      'stroke-width': 1.5,
    }));
  });

  svg.appendChild(createSvgEl('circle', { cx: PAD.left + 8, cy: H - 8, r: 4, fill: 'var(--accent)' }));
  svg.appendChild(createSvgEl('text', {
    x: PAD.left + 16,
    y: H - 4,
    fill: 'var(--text-muted)',
    'font-size': 9,
    'font-family': 'monospace',
  }, 'Allure (toutes sorties)'));
  svg.appendChild(createSvgEl('circle', { cx: PAD.left + 160, cy: H - 8, r: 4, fill: 'var(--accent2)' }));
  svg.appendChild(createSvgEl('text', {
    x: PAD.left + 168,
    y: H - 4,
    fill: 'var(--text-muted)',
    'font-size': 9,
    'font-family': 'monospace',
  }, 'BPM EF (axe droit)'));
  chartEl.replaceChildren(svg);

  // ── Tableau ─────────────────────────────────────────────────
  const rows = (ef.tableRows || []).map((r) => {
    const row = cloneTemplate('ef-row-template') || document.createElement('tr');
    const dateEl = row.querySelector('.ef-date');
    const kmEl = row.querySelector('.ef-km');
    const bpmEl = row.querySelector('.ef-bpm');
    const allureEl = row.querySelector('.ef-allure');
    const idxEl = row.querySelector('.ef-index');
    const trendEl = row.querySelector('.ef-trend');
    if (dateEl) dateEl.textContent = formatDate(r.date);
    if (kmEl) kmEl.textContent = r.km || '—';
    if (bpmEl) bpmEl.textContent = r.bpm || '—';
    if (allureEl) allureEl.textContent = r.allure || '—';
    if (idxEl) {
      idxEl.style.color = r.idxColor || '';
      idxEl.textContent = r.idx || '—';
    }
    if (trendEl) {
      const span = document.createElement('span');
      span.style.color = r.trendColor || '';
      span.textContent = r.trendLabel || '—';
      trendEl.replaceChildren(span);
    }
    return row;
  });
  efTbody.replaceChildren(...rows);

  document.getElementById('ef-meta').textContent = ef.meta || '';

  renderEfBpmChart();
}

// ============================================================
// EF BPM TREND CHART
// ============================================================
function renderEfBpmChart() {
  const container = document.getElementById('ef-bpm-chart-container');
  if (!container) return;

  const metrics = dashboardMetrics || {};
  const ef = metrics.ef || {};
  const trend = Array.isArray(ef.efBpmTrend) ? ef.efBpmTrend : [];

  if (trend.length < 2) {
    container.style.display = 'none';
    const wrap = document.getElementById('ef-bpm-wrap');
    if (wrap) wrap.style.display = 'none';
    return;
  }

  container.style.display = '';
  const wrap = document.getElementById('ef-bpm-wrap');
  if (wrap) wrap.style.display = '';

  const W = container.clientWidth || 600;
  const H = 160;
  const PAD = { top: 16, right: 32, bottom: 36, left: 42 };
  const cW = W - PAD.left - PAD.right;
  const cH = H - PAD.top - PAD.bottom;
  const n = trend.length;

  const bpms = trend.map((d) => d.bpm);
  const minB = Math.min(...bpms) - 4;
  const maxB = Math.max(...bpms) + 4;
  const bpmRange = Math.max(1, maxB - minB);

  const xSc = (i) => PAD.left + (i / Math.max(1, n - 1)) * cW;
  const ySc = (v) => PAD.top + (1 - (v - minB) / bpmRange) * cH;

  const svg = createSvgEl('svg', { width: W, height: H, xmlns: 'http://www.w3.org/2000/svg' });

  // Grid lines + Y labels
  const yTicks = [0, 0.25, 0.5, 0.75, 1];
  yTicks.forEach((t) => {
    const bVal = Math.round(minB + t * bpmRange);
    const y = PAD.top + (1 - t) * cH;
    svg.appendChild(createSvgEl('line', {
      x1: PAD.left, y1: y.toFixed(1),
      x2: W - PAD.right, y2: y.toFixed(1),
      stroke: 'var(--border)', 'stroke-width': 0.7, 'stroke-dasharray': '2,3',
    }));
    svg.appendChild(createSvgEl('text', {
      x: PAD.left - 5, y: (y + 4).toFixed(1),
      'text-anchor': 'end', fill: 'var(--text-muted)', 'font-size': 9, 'font-family': 'monospace',
    }, String(bVal)));
  });

  // X labels: show at most 6 evenly-spaced dates
  const maxLabels = Math.min(n, 6);
  const step = Math.max(1, Math.floor((n - 1) / (maxLabels - 1)));
  for (let i = 0; i < n; i += step) {
    const x = xSc(i);
    const label = formatDate(trend[i].date);
    svg.appendChild(createSvgEl('text', {
      x: x.toFixed(1), y: H - 4,
      'text-anchor': 'middle', fill: 'var(--text-muted)', 'font-size': 8, 'font-family': 'monospace',
    }, label));
  }

  // Moving avg line (dashed, muted)
  const avg3Pts = trend
    .map((d, i) => d.avg3 === null ? null : `${xSc(i).toFixed(1)},${ySc(d.avg3).toFixed(1)}`)
    .filter(Boolean);
  if (avg3Pts.length > 1) {
    svg.appendChild(createSvgEl('polyline', {
      points: avg3Pts.join(' '),
      fill: 'none', stroke: 'var(--accent2)', 'stroke-width': 1.5,
      'stroke-opacity': 0.35, 'stroke-dasharray': '4,3',
    }));
  }

  // BPM line
  const bpmPts = trend.map((d, i) => `${xSc(i).toFixed(1)},${ySc(d.bpm).toFixed(1)}`).join(' ');
  svg.appendChild(createSvgEl('polyline', {
    points: bpmPts,
    fill: 'none', stroke: 'var(--accent2)', 'stroke-width': 2, 'stroke-opacity': 0.85,
  }));

  // Dots
  trend.forEach((d, i) => {
    svg.appendChild(createSvgEl('circle', {
      cx: xSc(i).toFixed(1), cy: ySc(d.bpm).toFixed(1),
      r: 3.5, fill: 'var(--accent2)', stroke: 'var(--surface)', 'stroke-width': 1.5,
    }));
  });

  // Legend
  svg.appendChild(createSvgEl('circle', { cx: PAD.left + 8, cy: H - 20, r: 3.5, fill: 'var(--accent2)' }));
  svg.appendChild(createSvgEl('text', {
    x: PAD.left + 16, y: H - 16,
    fill: 'var(--text-muted)', 'font-size': 9, 'font-family': 'monospace',
  }, 'BPM EF'));
  svg.appendChild(createSvgEl('line', {
    x1: PAD.left + 70, y1: H - 20, x2: PAD.left + 82, y2: H - 20,
    stroke: 'var(--accent2)', 'stroke-width': 1.5, 'stroke-dasharray': '4,3', 'stroke-opacity': 0.45,
  }));
  svg.appendChild(createSvgEl('text', {
    x: PAD.left + 86, y: H - 16,
    fill: 'var(--text-muted)', 'font-size': 9, 'font-family': 'monospace',
  }, 'moy. mobile (3)'));

  container.replaceChildren(svg);
}

function renderCoherence() {
  const metrics = dashboardMetrics || {};
  const alerts = Array.isArray(metrics.coherenceAlerts)
    ? metrics.coherenceAlerts
    : [{ok:true,title:'Analyse indisponible',msg:'Pas assez de données pour établir des indicateurs de cohérence.'}];
  const section = document.getElementById('coherence-section');
  if (!section) return;
  const title = document.createElement('div');
  title.className = 'section-title coherence-title';
  title.textContent = 'Analyse de cohérence';
  const nodes = alerts.map((a) => {
    const node = cloneTemplate('coherence-alert-template') || document.createElement('div');
    if (a.ok) node.classList.add('alert-ok');
    const t = node.querySelector('.alert-title');
    const m = node.querySelector('.alert-msg');
    if (t) t.textContent = `${a.ok ? '✓' : '⚠'} ${a.title}`;
    if (m) m.textContent = a.msg;
    return node;
  });
  section.replaceChildren(title, ...nodes);
}

function renderProjections() {
  const metrics = dashboardMetrics || {};
  const projections = Array.isArray(metrics.projections) ? metrics.projections : [];
  const gridEl = document.getElementById('projections-grid');
  if (!gridEl) return;
  if(!projections.length){
    const emptyNode = cloneTemplate('projection-empty-template') || document.createElement('div');
    gridEl.replaceChildren(emptyNode);
    return;
  }
  const cards = projections.map((d)=>{
    const card = cloneTemplate('projection-card-template') || document.createElement('article');
    const labelEl = card.querySelector('.proj-label');
    const timeEl = card.querySelector('.proj-time');
    const paceEl = card.querySelector('.proj-pace');
    if (labelEl) labelEl.textContent = d.label;
    if (timeEl) timeEl.textContent = d.time || '—';
    if (paceEl) paceEl.textContent = `${d.pace || '—'}/km`;
    return card;
  });
  gridEl.replaceChildren(...cards);
  document.getElementById('projections-meta').textContent = metrics.projectionsMeta || '';
}

// ============================================================
// PLAN RENDERER
// ============================================================
let currentPlanId = null;

function getExtraPlan(planId) {
  return (state.extraPlans || []).find(p => String(p.id) === String(planId)) || null;
}

function planCard(id, title, sub, totalSessions, doneCount, isExtra) {
  const pct = totalSessions > 0 ? Math.round((doneCount / totalSessions) * 100) : 0;
  const card = cloneTemplate('plan-card-template') || document.createElement('article');
  const titleEl = card.querySelector('.plan-card-title');
  const subEl = card.querySelector('.plan-card-sub');
  const pctEl = card.querySelector('.plan-card-pct');
  const countEl = card.querySelector('.plan-card-count');
  const barEl = card.querySelector('.plan-card-bar');
  const deleteBtn = card.querySelector('.plan-card-delete');
  if (titleEl) titleEl.textContent = title;
  if (subEl) subEl.textContent = sub || '';
  if (pctEl) pctEl.textContent = `${pct}%`;
  if (countEl) countEl.textContent = `${doneCount}/${totalSessions} séances`;
  if (barEl) barEl.style.width = `${pct}%`;
  const open = () => openPlan(id);
  card.addEventListener('click', open);
  if (deleteBtn) {
    deleteBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      deletePlan(id);
    });
  }
  return card;
}

function renderPlansList() {
  const list = document.getElementById('plans-list');
  if (!list) return;

  const nodes = (state.extraPlans || []).map(ep => {
    const sessions = Array.isArray(ep.sessions) ? ep.sessions : [];
    const done = Object.values(ep.done || {}).filter(Boolean).length;
    return planCard(ep.id, ep.title, ep.sub, sessions.length, done, true);
  });
  list.replaceChildren(...nodes);
}

function openPlan(planId) {
  currentPlanId = planId;
  const extra = getExtraPlan(planId);
  if (!extra) return;

  const plansList = document.getElementById('plans-list');
  if (plansList) plansList.style.display = 'none';
  const plansListHeader = document.getElementById('plans-list-header');
  if (plansListHeader) plansListHeader.style.display = 'none';
  const plansCreateBtn = document.getElementById('plans-create-btn');
  if (plansCreateBtn) plansCreateBtn.style.display = 'none';
  const plansZoneLegend = document.getElementById('plans-zone-legend');
  if (plansZoneLegend) plansZoneLegend.style.display = 'none';
  const plansDetail = document.getElementById('plans-detail');
  if (plansDetail) plansDetail.style.display = 'block';

  const deleteBtn = document.getElementById('delete-extra-btn');
  const editMetaBtn = document.getElementById('plans-edit-meta-btn');
  if (deleteBtn) deleteBtn.style.display = '';
  if (editMetaBtn) editMetaBtn.style.display = '';

  const meta = { title: extra.title, sub: extra.sub || '' };
  const detailTitle = document.getElementById('plans-detail-title');
  if (detailTitle) detailTitle.textContent = meta.title;
  const detailSub = document.getElementById('plans-detail-sub');
  if (detailSub) detailSub.textContent = meta.sub;
  const crumbCurrent = document.getElementById('plans-crumb-current');
  if (crumbCurrent) crumbCurrent.textContent = meta.title;

  renderPlan('plans-detail-weeks', extra.sessions, `extra:${planId}`);
}

function backToPlansList() {
  currentPlanId = null;
  const plansList = document.getElementById('plans-list');
  if (plansList) plansList.style.display = 'flex';
  const plansListHeader = document.getElementById('plans-list-header');
  if (plansListHeader) plansListHeader.style.display = '';
  const plansCreateBtn = document.getElementById('plans-create-btn');
  if (plansCreateBtn) plansCreateBtn.style.display = '';
  const plansZoneLegend = document.getElementById('plans-zone-legend');
  if (plansZoneLegend) plansZoneLegend.style.display = '';
  const plansDetail = document.getElementById('plans-detail');
  if (plansDetail) plansDetail.style.display = 'none';
  const plansDetailWeeks = document.getElementById('plans-detail-weeks');
  if (plansDetailWeeks) plansDetailWeeks.replaceChildren();
  const crumbCurrent = document.getElementById('plans-crumb-current');
  if (crumbCurrent) crumbCurrent.textContent = '';
  renderPlansList();
}

function createNewPlanFromHub() {
  openModal('newplan-modal');
  const input = document.getElementById('np-title');
  if (!input) return;
  input.value = '';
  input.focus();
}

async function createPlanFromTitle(rawTitle) {
  const title = String(rawTitle || '').trim();
  if (!title) {
    notify('⚠ Saisis un nom de plan');
    return;
  }

  const planName = buildUniquePlanName(title);
  if (!planName) {
    notify('⚠ Saisis un nom de plan valide');
    return;
  }

  let createdPlanRef;
  try {
    createdPlanRef = await createPlanInDb(planName);
  } catch (e) {
    notify(`⚠ ${e.message}`);
    return;
  }

  try {
    await loadPlansFromDb();
  } catch (e) {
    notify(`⚠ ${e.message}`);
    return;
  }

  const planId = createdPlanRef.id;
  closeModal('newplan-modal');
  renderPlansList();
  requestDashboardRefresh();
  openPlan(planId);
  notify('✓ Plan créé');
}

async function confirmCreatePlan() {
  const titleInput = document.getElementById('np-title');
  await createPlanFromTitle(titleInput?.value || '');
}

function editPlanMeta(planId) {
  const ep = getExtraPlan(planId);
  if (!ep) return;
  const input = document.getElementById('meta-title');
  const saveBtn = document.getElementById('meta-save-btn');
  if (input) {
    input.value = ep.title || '';
  }
  if (saveBtn) {
    saveBtn.onclick = () => savePlanMeta(planId);
  }
  openModal('meta-modal');
}

async function savePlanMeta(planId) {
  const ep = getExtraPlan(planId);
  if (!ep) return;
  const title = String(document.getElementById('meta-title').value || '').trim();
  if (!title) {
    notify('⚠ Le nom du plan est obligatoire');
    return;
  }

  if (title !== ep.key && (state.extraPlans || []).some(p => p.key === title)) {
    notify('⚠ Un plan avec ce nom existe déjà');
    return;
  }

  if (title !== ep.key) {
    try {
      const renamed = await renamePlanInDb(ep.id, title);
      ep.id = renamed.id;
      plansData = (plansData || []).map(p => (String(p.id) === String(renamed.id) ? renamed : p));
    } catch (e) {
      notify(`⚠ ${e.message}`);
      return;
    }
    if (currentPlanId === planId) currentPlanId = ep.id;
  }

  ep.key = title;
  ep.title = title;
  ep.sub = '';
  renderPlansList();
  if (String(currentPlanId) === String(ep.id)) {
    const detailTitle = document.getElementById('plans-detail-title');
    if (detailTitle) detailTitle.textContent = title;
    const detailSub = document.getElementById('plans-detail-sub');
    if (detailSub) detailSub.textContent = '';
    const crumbCurrent = document.getElementById('plans-crumb-current');
    if (crumbCurrent) crumbCurrent.textContent = title;
    renderPlan('plans-detail-weeks', ep.sessions, `extra:${ep.id}`);
  }
  closeModal('meta-modal');
}

function addPlanSession(planId) {
  const ep = getExtraPlan(planId);
  if (!ep) {
    notify('⚠ Plan non trouvé');
    return;
  }
  ep.sessions.push({ sem: 1, date: null, format: "45'@Z2", pe: '3/10', total: 45, opt: false });
  replacePlanSessionsInDb(planId, ep.sessions, ep.done)
    .then(async () => {
      // Reload plans from DB to ensure sync
      await loadPlansFromDb();
      const reloadedPlan = getExtraPlan(planId);
      if (reloadedPlan) {
        renderPlan('plans-detail-weeks', reloadedPlan.sessions, `extra:${planId}`);
      }
      renderPlansList();
      requestDashboardRefresh();
      notify('✓ Séance ajoutée');
    })
    .catch((e) => notify(`⚠ ${e.message}`));
}

function deletePlan(planId) {
  askConfirm('Supprimer le plan ?', 'Cette action est irréversible.', async () => {
    try {
      await deletePlanInDb(planId);
      await loadPlansFromDb();
      renderPlansList();
      requestDashboardRefresh();
      notify('🗑 Plan supprimé');
    } catch (e) {
      notify(`⚠ ${e.message}`);
    }
  });
}

function deleteExtraPlan(planId) {
  askConfirm('Supprimer le plan ?', 'Cette action est irréversible.', async () => {
    try {
      await deletePlanInDb(planId);
      await loadPlansFromDb();
      backToPlansList();
      requestDashboardRefresh();
      notify('🗑 Plan supprimé');
    } catch (e) {
      notify(`⚠ ${e.message}`);
    }
  });
}

function renderPlan(containerId, data, stateKey) {
  const container = document.getElementById(containerId);
  if (!container) return;
  const weekNodes = [];
  const blocks = [];
  let i = 0;

  while (i < data.length) {
    const sem = Number.isFinite(Number(data[i]?.sem)) ? Number(data[i].sem) : null;

    // Fallback for undated/legacy rows without sem: preserve previous chunking by 4.
    if (sem === null) {
      const chunk = data.slice(i, Math.min(i + 4, data.length)).map((s, offset) => ({
        ...s,
        __idx: i + offset,
      }));
      blocks.push({ sem: null, sessions: chunk });
      i += 4;
      continue;
    }

    const start = i;
    while (i < data.length && Number(data[i]?.sem) === sem) {
      i += 1;
    }

    blocks.push({
      sem,
      sessions: data.slice(start, i).map((s, offset) => ({
        ...s,
        __idx: start + offset,
      })),
    });
  }

  blocks.forEach((block, blockIndex) => {
    const wd = block.sessions.find((s) => s.date)?.date;
    const week = cloneTemplate('plan-week-card-template') || document.createElement('div');
    const weekNumEl = week.querySelector('.week-num');
    const weekDateEl = week.querySelector('.week-date');
    const weekSessionsEl = week.querySelector('.week-sessions');
    if (weekNumEl) weekNumEl.textContent = `BLOC ${block.sem ?? (blockIndex + 1)}`;
    if (weekDateEl) weekDateEl.textContent = wd ? formatDate(wd) : '—';
    const sessionNodes = [];
    block.sessions.forEach((s) => {
      const idx = s.__idx;
      const done = stateKey.startsWith('extra:')
        ? !!(getExtraPlan(stateKey.slice(6))?.done?.[idx])
        : !!state[stateKey]?.[idx];
      const row = cloneTemplate('plan-session-row-template') || document.createElement('div');
      row.dataset.sessionIndex = String(idx);
      const checkEl = row.querySelector('.session-check');
      const formatEl = row.querySelector('.session-format');
      const dateEl = row.querySelector('.session-date-badge');
      const peEl = row.querySelector('.pe-badge');
      const durEl = row.querySelector('.duration-badge');
      const optEl = row.querySelector('.optional-tag');
      const editBtn = row.querySelector('.session-edit');
      const delBtn = row.querySelector('.session-delete');
      if (checkEl) {
        checkEl.classList.toggle('done', done);
        checkEl.textContent = done ? '✓' : '';
      }
      appendFormattedZones(formatEl, s.format || '');
      if (dateEl) {
        dateEl.hidden = !s.date;
        dateEl.textContent = s.date ? formatDate(s.date) : '';
      }
      if (peEl) {
        peEl.hidden = !s.pe;
        peEl.textContent = s.pe ? `PE ${s.pe}` : '';
      }
      if (durEl) {
        durEl.hidden = !s.total;
        durEl.textContent = s.total ? `${s.total}'` : '';
      }
      if (optEl) optEl.hidden = !s.opt;
      row.addEventListener('click', () => toggleSession(stateKey, idx, row));
      if (editBtn) {
        editBtn.addEventListener('click', (e) => {
          e.stopPropagation();
          openPlanEdit(stateKey, idx);
        });
      }
      if (delBtn) {
        delBtn.addEventListener('click', (e) => {
          e.stopPropagation();
          deletePlanSession(stateKey, idx);
        });
      }
      sessionNodes.push(row);
    });
    if (weekSessionsEl) weekSessionsEl.replaceChildren(...sessionNodes);
    weekNodes.push(week);
  });
  container.replaceChildren(...weekNodes);
}

async function toggleSession(stateKey, idx, row) {
  if (stateKey.startsWith('extra:')) {
    const ep = getExtraPlan(stateKey.slice(6));
    if (!ep) return;
    ep.done[idx] = !ep.done[idx];
    
    // Update UI immediately
    const c = row.querySelector('.session-check');
    c.classList.toggle('done', !!ep.done[idx]);
    c.textContent = ep.done[idx] ? '✓' : '';
    renderPlansList();
    notify(ep.done[idx] ? '✓ Séance validée !' : 'Séance décochée');
    
    // Save in background (non-blocking)
    replacePlanSessionsInDb(ep.id, ep.sessions, ep.done)
      .then(() => savePlanProgress(String(ep.id), idx, ep.done[idx]))
      .then(() => requestDashboardRefresh())
      .catch(e => notify('⚠ Erreur de sauvegarde: ' + e.message));
    return;
  }

  state[stateKey][idx] = !state[stateKey][idx];
  const c = row.querySelector('.session-check');
  c.classList.toggle('done', !!state[stateKey][idx]);
  c.textContent = state[stateKey][idx] ? '✓' : '';
  
  // Update UI and lists immediately
  renderPlansList();
  notify(state[stateKey][idx] ? '✓ Séance validée !' : 'Séance décochée');
  
  // Save in background (non-blocking)
  savePlanProgress(stateKey, idx, state[stateKey][idx])
    .then(() => requestDashboardRefresh())
    .catch(e => notify('⚠ Erreur de sauvegarde: ' + e.message));
}

function openPlanEdit(stateKey, idx) {
  const isExtra = stateKey.startsWith('extra:');
  const planId = isExtra ? stateKey.slice(6) : null;
  const data = isExtra ? getExtraPlan(planId)?.sessions : [];
  const s = data?.[idx];
  if (!s) return;

  document.getElementById('pm-statekey').value = stateKey;
  document.getElementById('pm-idx').value = idx;
  document.getElementById('pm-format').value = s.format || '';
  document.getElementById('pm-date').value = normalizeDateForStorage(s.date);
  document.getElementById('pm-pe').value = s.pe || '';
  document.getElementById('pm-total').value = s.total || '';
  document.getElementById('pm-opt').checked = !!s.opt;
  openModal('plan-modal');
}

function savePlanEdit() {
  const sk = document.getElementById('pm-statekey').value;
  const idx = Number.parseInt(document.getElementById('pm-idx').value, 10);
  const isExtra = sk.startsWith('extra:');
  const planId = isExtra ? sk.slice(6) : null;
  const d = isExtra ? getExtraPlan(planId)?.sessions : [];
  if (!d?.[idx]) return;

  d[idx].format = document.getElementById('pm-format').value;
  const dateInput = document.getElementById('pm-date').value;
  const isoDate = normalizeDateForStorage(dateInput);
  if (dateInput && !isoDate) {
    notify('⚠ Date invalide (format attendu: dd/mm/yyyy)');
    return;
  }
  d[idx].date = isoDate || null;
  d[idx].pe = document.getElementById('pm-pe').value;
  d[idx].total = Number.parseInt(document.getElementById('pm-total').value, 10) || null;
  d[idx].opt = document.getElementById('pm-opt').checked;

  if (isExtra) {
    replacePlanSessionsInDb(planId, d, getExtraPlan(planId)?.done || {})
      .catch((e) => notify(`⚠ ${e.message}`));
  }

  renderPlan('plans-detail-weeks', d, sk);
  renderPlansList();
  requestDashboardRefresh();
  closeModal('plan-modal');
  notify('✓ Séance modifiée');
}

function deletePlanSession(sk, idx) {
  const isExtra = sk.startsWith('extra:');
  const planId = isExtra ? sk.slice(6) : null;
  const d = isExtra ? getExtraPlan(planId)?.sessions : [];
  if (!d?.[idx]) return;

  askConfirm('Supprimer la séance ?', `"${d[idx].format}"`, async () => {
    d.splice(idx, 1);

    if (isExtra) {
      const ep = getExtraPlan(planId);
      const nextDone = {};
      Object.entries(ep.done || {}).forEach(([k, v]) => {
        const ki = Number.parseInt(k, 10);
        if (ki < idx) nextDone[ki] = v;
        if (ki > idx) nextDone[ki - 1] = v;
      });
      ep.done = nextDone;
      await replacePlanSessionsInDb(planId, d, ep.done);
    }

    renderPlan('plans-detail-weeks', d, sk);
    renderPlansList();
    requestDashboardRefresh();
    notify('✓ Séance supprimée');
  });
}

// ============================================================
// MODALS
// ============================================================
function closeModal(id){
  const modal = document.getElementById(id);
  if (modal) modal.classList.remove('open');
}
function openModal(id){
  const modal = document.getElementById(id);
  if (modal) {
    modal.classList.add('open');
    return true;
  }
  return false;
}
document.addEventListener('click',e=>{
  ['plan-modal','log-modal','race-modal','newplan-modal','meta-modal'].forEach(id=>{
    const el=document.getElementById(id);
    if(e.target===el) el.classList.remove('open');
  });
});
let _del=null;
function askConfirm(title,msg,fn){
  _del=fn;
  document.getElementById('confirm-title').textContent=title;
  document.getElementById('confirm-msg').textContent=msg;
  document.getElementById('confirm-overlay').classList.add('open');
}
function confirmDelete() {
  if (_del) _del();
  _del = null;
  closeConfirm();
}
function closeConfirm(){document.getElementById('confirm-overlay').classList.remove('open');}

// ============================================================
// LOG
// ============================================================
let logFilter='all', logSortAsc=false;

function toggleSort(){
  logSortAsc=!logSortAsc;
  document.getElementById('sort-icon').textContent=logSortAsc?'↑':'↓';
  renderLog();
}

function buildLogMetricSpan(className, text) {
  const span = document.createElement('span');
  span.className = className;
  span.textContent = text;
  return span;
}

function setLogMetricCell(cell, value, className, suffix = '') {
  if (!cell) return;
  if (!value) {
    cell.textContent = '—';
    return;
  }
  cell.replaceChildren(buildLogMetricSpan(className, `${value}${suffix}`));
}

function setLogTypeCell(cell, runType) {
  if (!cell) return;
  if (!runType) {
    cell.textContent = '—';
    return;
  }
  const badge = cloneTemplate('log-type-badge-template') || document.createElement('span');
  badge.textContent = runType;
  cell.replaceChildren(badge);
}

function buildLogRow(r) {
  const ac = allureClass(r.allure);
  const row = cloneTemplate('log-row-template') || document.createElement('tr');
  const dateEl = row.querySelector('.log-date');
  const kmEl = row.querySelector('.log-km');
  const durEl = row.querySelector('.log-dur');
  const allureEl = row.querySelector('.log-allure');
  const gapEl = row.querySelector('.log-gap');
  const dplusEl = row.querySelector('.log-dplus');
  const bpmEl = row.querySelector('.log-bpm');
  const typeEl = row.querySelector('.log-type');
  const notesEl = row.querySelector('.log-notes');
  const editBtn = row.querySelector('.log-edit');
  const delBtn = row.querySelector('.log-delete');

  if (dateEl) dateEl.textContent = formatDate(r.date);
  if (kmEl) kmEl.textContent = r.km?.toFixed(2) || '—';
  if (durEl) durEl.textContent = r.duration || '—';
  if (allureEl) {
    allureEl.classList.add(ac);
    allureEl.textContent = `${r.allure || '—'}/km`;
  }
  setLogMetricCell(gapEl, r.gap, 'metric-gap', '/km');
  setLogMetricCell(dplusEl, r.dplus, 'metric-dplus', 'm');
  if (bpmEl) bpmEl.textContent = r.bpm || '—';
  setLogTypeCell(typeEl, r.run_type);
  if (notesEl) notesEl.textContent = r.notes || '—';
  if (editBtn) editBtn.addEventListener('click', () => openLogEdit(r.id));
  if (delBtn) delBtn.addEventListener('click', () => deleteLog(r.id, r.date));

  return row;
}

function renderLog() {
  const logSub = document.getElementById('log-sub');
  if (!logSub) return;
  logSub.textContent=`${logData.length} sortie${logData.length>1?'s':''} enregistrée${logData.length>1?'s':''}`;
  let items=[...logData];
  items.sort((a,b)=>logSortAsc?new Date(a.date)-new Date(b.date):new Date(b.date)-new Date(a.date));
  if(logFilter!=='all') items=items.filter(r=>r.run_type===logFilter);
  const tbody = document.getElementById('log-tbody');
  if (!tbody) return;
  const rows = items.map(buildLogRow);
  tbody.replaceChildren(...rows);
  addHoverListeners('log-tbody');
}

function filterLog(type,btn){
  logFilter=type;
  document.querySelectorAll('.filter-btn').forEach(b=>b.classList.remove('active'));
  btn.classList.add('active');
  renderLog();
}

async function addLog() {
  const date=document.getElementById('log-date').value;
  const km=Number.parseFloat(document.getElementById('log-km').value);
  const dur=document.getElementById('log-dur').value;
  const dplus=Number.parseInt(document.getElementById('log-dplus').value, 10)||null;
  const bpm=Number.parseInt(document.getElementById('log-bpm').value, 10)||null;
  const runType=document.getElementById('log-type').value||null;
  const notes=document.getElementById('log-notes').value||null;
  if(!date||!km||!dur){notify('⚠ Date, km et durée requis');return;}
  const secs=durToSec(dur), allureSec=Math.round(secs/km);
  const allure=secToDur(allureSec).slice(3);
  const gap=computeGAP(allureSec,km,dplus);
  try {
    const created=await apiFetch('/run_logs',{method:'POST',body:JSON.stringify({
      date,km,duration:dur,allure,gap,dplus,bpm,runType,notes
    })});
    logData.unshift(normalizeLog(created));
    renderLog(); requestDashboardRefresh();
    ['log-km','log-dur','log-dplus','log-bpm','log-notes'].forEach(id=>document.getElementById(id).value='');
    document.getElementById('log-type').value='';
    notify('✓ Sortie enregistrée !');
  } catch(e){notify('⚠ '+e.message);}
}

function openLogEdit(id) {
  const r=logData.find(x=>x.id===id); if(!r)return;
  document.getElementById('lm-idx').value=id;
  document.getElementById('lm-date').value=r.date||'';
  document.getElementById('lm-km').value=r.km||'';
  document.getElementById('lm-dur').value=r.duration||'';
  document.getElementById('lm-dplus').value=r.dplus||'';
  document.getElementById('lm-bpm').value=r.bpm||'';
  document.getElementById('lm-type').value=r.run_type||'';
  document.getElementById('lm-notes').value=r.notes||'';
  openModal('log-modal');
}

async function saveLogEdit() {
  const id=Number.parseInt(document.getElementById('lm-idx').value, 10);
  const dur=document.getElementById('lm-dur').value;
  const km=Number.parseFloat(document.getElementById('lm-km').value);
  const dplus=Number.parseInt(document.getElementById('lm-dplus').value, 10)||null;
  const secs=durToSec(dur), allureSec=secs&&km?Math.round(secs/km):null;
  const allure=allureSec?secToDur(allureSec).slice(3):logData.find(r=>r.id===id)?.allure;
  const gap=allureSec?computeGAP(allureSec,km,dplus):null;
  try {
    const updated=await apiFetch(`/run_logs/${id}`,{method:'PUT',body:JSON.stringify({
      date:document.getElementById('lm-date').value,
      km,duration:dur,allure,gap,dplus,
      bpm:Number.parseInt(document.getElementById('lm-bpm').value, 10)||null,
      runType:document.getElementById('lm-type').value||null,
      notes:document.getElementById('lm-notes').value||null,
    })});
    const idx=logData.findIndex(r=>r.id===id);
    if(idx>=0) logData[idx]=normalizeLog(updated);
    renderLog(); requestDashboardRefresh();
    closeModal('log-modal');
    notify('✓ Sortie modifiée !');
  } catch(e){notify('⚠ '+e.message);}
}

async function deleteLog(id,dateStr) {
  askConfirm('Supprimer la sortie ?',formatDate(dateStr),async()=>{
    try {
      await apiFetch(`/run_logs/${id}`,{method:'DELETE'});
      logData=logData.filter(r=>r.id!==id);
      renderLog(); requestDashboardRefresh();
      notify('🗑 Sortie supprimée');
    } catch(e){notify('⚠ '+e.message);}
  });
}

// ============================================================
// RACES
// ============================================================
function getRaceStatus(r) {
  const days = getDaysTo(r.date);
  if (r.result) return { statusClass: 'badge-done', statusText: '✓ Terminée' };
  if (days < 0) return { statusClass: 'badge-future', statusText: 'Passée' };
  if (days <= 10) return { statusClass: 'badge-next', statusText: `J-${days}` };
  return { statusClass: 'badge-future', statusText: `S-${Math.round(days / 7)}` };
}

function getRaceDiff(r) {
  if (!r.result || !r.objective) return '—';
  const delta = durToSec(r.result) - durToSec(r.objective);
  if (delta < 0) return `-${secToDur(-delta).slice(3)}`;
  return `+${secToDur(delta).slice(3)}`;
}

function buildRaceRow(r) {
  const { statusClass, statusText } = getRaceStatus(r);
  const diff = getRaceDiff(r);
  const row = cloneTemplate('races-row-template') || document.createElement('tr');
  const statusEl = row.querySelector('.races-status');
  const nameEl = row.querySelector('.races-name');
  const dateEl = row.querySelector('.races-date');
  const distEl = row.querySelector('.races-dist');
  const objEl = row.querySelector('.races-obj');
  const realEl = row.querySelector('.races-real');
  const diffEl = row.querySelector('.races-diff');
  const editBtn = row.querySelector('.races-edit');
  const delBtn = row.querySelector('.races-delete');

  if (statusEl) {
    const badge = cloneTemplate('races-status-badge-template') || document.createElement('span');
    badge.classList.add(statusClass);
    badge.textContent = statusText;
    statusEl.replaceChildren(badge);
  }
  if (nameEl) nameEl.textContent = r.name || '—';
  if (dateEl) dateEl.textContent = formatDate(r.date);
  if (distEl) distEl.textContent = r.distance || '—';
  if (objEl) objEl.textContent = r.objective || '—';
  if (realEl) realEl.textContent = r.result || '—';
  if (diffEl) {
    if (diff === '—') {
      diffEl.textContent = diff;
    } else {
      const span = document.createElement('span');
      span.className = diff.startsWith('-') ? 'diff-good' : 'diff-bad';
      span.textContent = diff;
      diffEl.replaceChildren(span);
    }
  }
  if (editBtn) editBtn.addEventListener('click', () => openRaceEdit(r.id));
  if (delBtn) delBtn.addEventListener('click', () => deleteRace(r.id, r.name));

  return row;
}

function renderRaces() {
  const tbody = document.getElementById('races-tbody');
  if (!tbody) return;
  const rows = racesData.map(buildRaceRow);
  tbody.replaceChildren(...rows);
  addHoverListeners('races-tbody');
}

async function addRace() {
  const name=document.getElementById('r-name').value.trim();
  const date=document.getElementById('r-date').value;
  if(!name||!date){notify('⚠ Nom et date requis');return;}
  try {
    const created=await apiFetch('/races',{method:'POST',body:JSON.stringify({
      name,date,
      distance:document.getElementById('r-dist').value||null,
      objective:document.getElementById('r-obj').value||null,
      result:document.getElementById('r-real').value||null,
    })});
    racesData.push(normalizeRace(created));
    racesData.sort((a,b)=>new Date(a.date)-new Date(b.date));
    renderRaces(); requestDashboardRefresh();
    ['r-name','r-date','r-dist','r-obj','r-real'].forEach(id=>document.getElementById(id).value='');
    notify('✓ Course ajoutée !');
  } catch(e){notify('⚠ '+e.message);}
}

function openRaceEdit(id) {
  const r=racesData.find(x=>x.id===id); if(!r)return;
  document.getElementById('rm-idx').value=id;
  document.getElementById('rm-name').value=r.name||'';
  document.getElementById('rm-date').value=r.date||'';
  document.getElementById('rm-dist').value=r.distance||'';
  document.getElementById('rm-obj').value=r.objective||'';
  document.getElementById('rm-real').value=r.result||'';
  openModal('race-modal');
}

async function saveRaceEdit() {
  const id=Number.parseInt(document.getElementById('rm-idx').value, 10);
  try {
    const updated=await apiFetch(`/races/${id}`,{method:'PUT',body:JSON.stringify({
      name:document.getElementById('rm-name').value,
      date:document.getElementById('rm-date').value,
      distance:document.getElementById('rm-dist').value||null,
      objective:document.getElementById('rm-obj').value||null,
      result:document.getElementById('rm-real').value||null,
    })});
    const idx=racesData.findIndex(r=>r.id===id);
    if(idx>=0) racesData[idx]=normalizeRace(updated);
    racesData.sort((a,b)=>new Date(a.date)-new Date(b.date));
    renderRaces(); requestDashboardRefresh();
    closeModal('race-modal');
    notify('✓ Course modifiée !');
  } catch(e){notify('⚠ '+e.message);}
}

async function deleteRace(id,name) {
  askConfirm('Supprimer la course ?',name,async()=>{
    try {
      await apiFetch(`/races/${id}`,{method:'DELETE'});
      racesData=racesData.filter(r=>r.id!==id);
      renderRaces(); requestDashboardRefresh();
      notify('🗑 Course supprimée');
    } catch(e){notify('⚠ '+e.message);}
  });
}

// ============================================================
// INIT
// ============================================================
async function initApp() {
  if (!authToken) {
    globalThis.location.href = '/login';
    return;
  }

  // Verify token still valid
  let me = null;
  try {
    if (globalThis.rtAuth?.fetchCurrentUser) {
      me = await globalThis.rtAuth.fetchCurrentUser();
    } else {
      me = await apiFetch('/auth/me');
    }
    if (!me) {
      logout();
      return;
    }
  } catch {
    logout(); return;
  }

  const rawUsername = me?.username || me?.userIdentifier || me?.email;
  const normalizedUsername = String(rawUsername || '').trim();
  const invalidUsernames = new Set(['', 'inconnu', 'unknown', 'utilisateur', 'user', 'null', 'undefined']);

  // If authenticated payload has no usable identity, force re-login
  if (invalidUsernames.has(normalizedUsername.toLowerCase())) {
    globalThis.location.href = '/login';
    return;
  }

  const usernameEl = document.getElementById('current-username');
  if (usernameEl) {
    usernameEl.textContent = formatDisplayName(normalizedUsername);
  }

  await loadAllData();
  await loadDashboardAdvice();

  const safeRender = (fn, name) => {
    try {
      fn();
    } catch (e) {
      console.error(`[render:${name}]`, e);
    }
  };

  // Keep plans independent from dashboard errors.
  safeRender(renderPlansList, 'plans');
  safeRender(renderDashboard, 'dashboard');
  safeRender(renderLog, 'log');
  safeRender(renderRaces, 'races');
  safeRender(consumeAdviceFocusFromUrl, 'advice-focus-url');

  const today = new Date().toISOString().split('T')[0];
  const logDateEl = document.getElementById('log-date');
  if (logDateEl) logDateEl.value = today;
  const raceDateEl = document.getElementById('r-date');
  if (raceDateEl) raceDateEl.value = today;

  // Setup date input handlers for FR format (jj/mm/yyyy) conversion
  ['log-date', 'r-date', 'lm-date', 'rm-date', 'pm-date'].forEach(id => {
    const el = document.getElementById(id);
    if (el) {
      const tryOpenPicker = () => {
        if (typeof el.showPicker === 'function') {
          try { el.showPicker(); } catch {}
        }
      };
      el.addEventListener('click', tryOpenPicker);
      el.addEventListener('change', (e) => {
        const val = e.target.value;
        if (val && !val.includes('-')) {
          e.target.value = normalizeDateForStorage(val);
        }
      });
    }
  });
}

initApp();
