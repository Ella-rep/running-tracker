// ============================================================
// API CLIENT — API Platform returns JSON-LD (hydra:member)
// ============================================================
const API = '/api';
let authToken = globalThis.rtAuth?.getToken?.() || localStorage.getItem('rt_token') || null;

async function apiFetch(path, options = {}) {
  const method = String(options.method || 'GET').toUpperCase();
  const headers = {
    'Content-Type': method === 'PATCH' ? 'application/merge-patch+json' : 'application/json',
  };
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

function syncAdminNavVisibility(currentUser) {
  const adminLink = document.getElementById('admin-nav-link');
  if (!(adminLink instanceof HTMLElement)) {
    return;
  }

  const roles = Array.isArray(currentUser?.roles) ? currentUser.roles : [];
  const isAdmin = roles.includes('ROLE_ADMIN');

  if (isAdmin) {
    adminLink.removeAttribute('hidden');
  } else {
    adminLink.setAttribute('hidden', 'hidden');
  }
}

// Ensure inline onclick handlers can always resolve these functions
globalThis.logout = logout;

function setupMobileHeaderNav() {
  const header = document.querySelector('.app-header');
  const toggle = document.getElementById('mobile-nav-toggle');
  const nav = document.getElementById('app-nav');
  if (!(header instanceof HTMLElement) || !(toggle instanceof HTMLButtonElement) || !(nav instanceof HTMLElement)) {
    return;
  }

  const closeNav = () => {
    header.classList.remove('nav-open');
    toggle.setAttribute('aria-expanded', 'false');
    toggle.setAttribute('aria-label', 'Ouvrir le menu');
  };

  toggle.addEventListener('click', () => {
    const isOpen = header.classList.toggle('nav-open');
    toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    toggle.setAttribute('aria-label', isOpen ? 'Fermer le menu' : 'Ouvrir le menu');
  });

  nav.querySelectorAll('a').forEach((link) => {
    link.addEventListener('click', closeNav);
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      closeNav();
    }
  });

  globalThis.addEventListener('resize', () => {
    if (globalThis.innerWidth > 900) {
      closeNav();
    }
  });
}


// ============================================================
// DATA
// ============================================================
let logData   = [];
let racesData = [];
let plansData = [];
let dashboardAdvice = [];
let dashboardMetrics = null;
let calendarEventsData = [];
let state     = { doneByKey: {}, planMeta: {}, extraPlans: [] };
const WEATHER_CITY_STORAGE_KEY = 'rt_weather_city';
const DASHBOARD_ADVICE_CACHE_KEY = 'rt_dashboard_advice_cache_v1';
const DASHBOARD_ADVICE_CACHE_MAX_AGE_MS = 30 * 60 * 1000;
let weatherDetectedCity = '';
let weatherDetectedCityStatus = '';
let weatherDetectedCityMessage = '';
const DASHBOARD_WIDGET_PRESET_KEY = 'rt_dashboard_widgets_preset_v2';
let dashboardWidgetPrefsHydrated = false;

function setDashboardLoadingState(isLoading) {
  const on = !!isLoading;
  const kpiGrid = document.getElementById('kpi-grid');
  if (kpiGrid) {
    kpiGrid.classList.toggle('is-loading', on);
  }

  document.querySelectorAll('[data-widget]').forEach((el) => {
    el.classList.toggle('is-loading', on);
  });

  renderDashboardAdviceLoadingSkeleton(on);
}

function buildAdviceSkeletonCard() {
  const card = document.createElement('article');
  card.className = 'advice-card advice-skeleton';

  const icon = document.createElement('div');
  icon.className = 'advice-icon';

  const content = document.createElement('div');
  content.className = 'advice-content';

  const title = document.createElement('h4');
  title.className = 'advice-title';

  const meta = document.createElement('div');
  meta.className = 'advice-meta';

  const badge = document.createElement('span');
  badge.className = 'advice-badge';

  const text = document.createElement('p');
  text.className = 'advice-text';

  meta.appendChild(badge);
  content.appendChild(title);
  content.appendChild(meta);
  content.appendChild(text);
  card.appendChild(icon);
  card.appendChild(content);

  return card;
}

function renderDashboardAdviceLoadingSkeleton(isLoading) {
  const advice = document.getElementById('dashboard-advice');
  const weather = document.getElementById('dashboard-weather');

  if (!isLoading) {
    advice?.querySelectorAll('[data-advice-skeleton="1"]').forEach((node) => node.remove());
    weather?.querySelectorAll('[data-advice-skeleton="1"]').forEach((node) => node.remove());
    return;
  }

  if (advice?.childElementCount === 0 && !advice?.querySelector('[data-advice-skeleton="1"]')) {
    const stack = document.createElement('div');
    stack.className = 'advice-stack';
    stack.dataset.adviceSkeleton = '1';
    stack.appendChild(buildAdviceSkeletonCard());
    stack.appendChild(buildAdviceSkeletonCard());
    advice.appendChild(stack);
  }

  if (weather?.childElementCount === 0 && !weather?.querySelector('[data-advice-skeleton="1"]')) {
    const card = buildAdviceSkeletonCard();
    card.dataset.adviceSkeleton = '1';
    weather.appendChild(card);
  }
}

function isExamplePlanName(name) {
  const normalized = String(name || '').trim().toLowerCase();
  return normalized === 'starter';
}

function getDashboardAdviceCacheCityKey() {
  return String(localStorage.getItem(WEATHER_CITY_STORAGE_KEY) || '').trim().toLowerCase();
}

function hydrateDashboardAdviceFromCache() {
  try {
    const raw = localStorage.getItem(DASHBOARD_ADVICE_CACHE_KEY);
    if (!raw) return false;

    const parsed = JSON.parse(raw);
    if (!parsed || typeof parsed !== 'object') return false;

    const savedAt = Number(parsed.savedAt || 0);
    const ageMs = Date.now() - savedAt;
    if (!Number.isFinite(savedAt) || ageMs > DASHBOARD_ADVICE_CACHE_MAX_AGE_MS) {
      localStorage.removeItem(DASHBOARD_ADVICE_CACHE_KEY);
      return false;
    }

    const cityKey = String(parsed.cityKey || '').trim().toLowerCase();
    if (cityKey !== getDashboardAdviceCacheCityKey()) {
      return false;
    }

    const items = members(parsed.items || []);
    if (!items.length) return false;

    dashboardAdvice = items;
    return true;
  } catch {
    localStorage.removeItem(DASHBOARD_ADVICE_CACHE_KEY);
    return false;
  }
}

function persistDashboardAdviceCache(items) {
  try {
    localStorage.setItem(DASHBOARD_ADVICE_CACHE_KEY, JSON.stringify({
      savedAt: Date.now(),
      cityKey: getDashboardAdviceCacheCityKey(),
      items: Array.isArray(items) ? items : [],
    }));
  } catch {
    // Non-blocking optimization only.
  }
}


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

function normalizeCalendarEvent(row) {
  const id = Number.parseInt(row?.id, 10);
  return {
    id: Number.isFinite(id) ? id : null,
    date: normalizeDateForStorage(row?.date),
    title: String(row?.title || '').trim(),
  };
}

async function loadCalendarEvents() {
  try {
    const data = await apiFetch('/calendar/events');
    const items = members(data?.items || data);
    calendarEventsData = items.map(normalizeCalendarEvent).filter((e) => Number.isFinite(e.id) && e.date && e.title);
  } catch {
    calendarEventsData = [];
  }
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

const SESSION_TYPE_OPTIONS = [
  { value: 'EF', label: 'EF' },
  { value: 'FC', label: 'FC' },
  { value: 'FL', label: 'FL' },
  { value: 'T', label: 'T' },
  { value: 'Race', label: 'Race' },
];

function normalizeSessionType(value) {
  const raw = String(value || '').trim();
  if (!raw) return null;

  // Accept both short codes and legacy long labels (with or without accents).
  const compact = raw
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toUpperCase()
    .replace(/\s+/g, ' ')
    .trim();

  if (compact === 'EF' || compact.includes('ENDURANCE FONDAMENTALE')) return 'EF';
  if (compact === 'FC' || compact.includes('FRACTIONNE COURT')) return 'FC';
  if (compact === 'FL' || compact.includes('SORTIE LONGUE')) return 'FL';
  if (compact === 'T' || compact.includes('TEMPO')) return 'T';
  if (compact === 'RACE' || compact.includes('COURSE')) return 'Race';

  const codePrefix = /^(EF|FC|FL|T|RACE)\b/.exec(compact);
  if (codePrefix) return codePrefix[1] === 'RACE' ? 'Race' : codePrefix[1];

  // Keep non-empty unknown values instead of silently dropping them.
  return raw;
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
  const payloadSessions = (Array.isArray(sessions) ? sessions : []).map((session) => ({
    // Keep both keys for API compatibility across serializer/config variants.
    session_type: normalizeSessionType(session?.sessionType ?? session?.session_type ?? session?.type),
    sem: session?.sem ?? null,
    date: normalizeDateForStorage(session?.date) || null,
    format: session?.format || "45'@Z2",
    sessionType: normalizeSessionType(session?.sessionType ?? session?.session_type ?? session?.type),
    pe: session?.pe || null,
    total: session?.total ?? null,
    opt: !!session?.opt,
  }));

  await apiFetch(`/plans/${Number(planId)}/sessions`, {
    method: 'PATCH',
    body: JSON.stringify({ sessions: payloadSessions, doneMap: doneMap || {} }),
  });
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
        title: isExamplePlanName(planKey) ? 'Plan de depart (exemple)' : planKey,
        sub: isExamplePlanName(planKey) ? 'Plan fourni avec l\'application · blocs hebdomadaires' : '',
        sessions: [],
        done: {},
      };
    }

    const idx = Math.max(0, (row.position || 1) - 1);
    grouped[planId].sessions[idx] = {
      detailId: Number.parseInt(row.id, 10) || iriToId(row['@id']) || null,
      sem: row.sem,
      date: normalizeDateForStorage(row.sessionDate),
      format: row.format,
      sessionType: normalizeSessionType(row.sessionType ?? row.session_type ?? row.type),
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
      title: isExamplePlanName(plan.name) ? 'Plan de depart (exemple)' : plan.name,
      sub: isExamplePlanName(plan.name) ? 'Plan fourni avec l\'application · blocs hebdomadaires' : '',
      sessions: [],
      done: {},
    });
  });

  state.extraPlans = mapped;
}

async function initializeStarterPlan() {
  const examplePlanRef = (plansData || []).find(p => isExamplePlanName(p.name));
  if (!examplePlanRef) {
    // Creating the "starter" plan triggers backend starterSessions via PlanSessionService.
    await createPlanInDb('starter');
    await loadPlansFromDb();
  }
}

async function loadAllData(options = {}) {
  const includeDashboardMetrics = options.includeDashboardMetrics !== false;
  const [logs, races, checks] = await Promise.all([
    apiFetch('/run_logs?order[date]=desc&pagination=false'),
    apiFetch('/races?order[date]=asc&pagination=false'),
    apiFetch('/plan_progresses?pagination=false'),
  ]);
  const checksList = members(checks);

  // Normalize API Platform IRI ids to plain int ids
  logData   = members(logs).map(normalizeLog);
  racesData = members(races).map(normalizeRace);

  state = { doneByKey: {}, planMeta: {}, extraPlans: [] };
  checksList.forEach(c => {
    const key = String(c.planKey || '').trim();
    if (!key) return;
    state.doneByKey[key] ??= {};
    state.doneByKey[key][c.sessionIndex] = !!c.done;
  });

  const calendarEventsPromise = loadCalendarEvents();

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

    await initializeStarterPlan();
    if (includeDashboardMetrics) {
      await loadDashboardMetrics();
    }
  } catch {
    // Keep app usable even if plans endpoints are temporarily unavailable.
    dashboardMetrics = null;
  } finally {
    await calendarEventsPromise;
  }
}

async function loadDashboardAdvice() {
  const customCity = String(localStorage.getItem(WEATHER_CITY_STORAGE_KEY) || '').trim();
  const path = customCity ? `/dashboard/advice?city=${encodeURIComponent(customCity)}` : '/dashboard/advice';
  try {
    const data = await apiFetch(path);
    dashboardAdvice = members(data?.items || []);
    persistDashboardAdviceCache(dashboardAdvice);
    return true;
  } catch {
    if (!Array.isArray(dashboardAdvice) || !dashboardAdvice.length) {
      dashboardAdvice = [];
    }
    return false;
  }
}

function getWeatherAdviceItem() {
  const weatherItem = (Array.isArray(dashboardAdvice) ? dashboardAdvice : []).find((item) =>
    String(item?.title || '').toLowerCase().includes('meteo')
  );
  return weatherItem || null;
}

function getWeatherCityFeedback() {
  const item = getWeatherAdviceItem();
  if (!item) return null;

  const message = String(item?.cityMessage || '').trim();
  const status = String(item?.cityStatus || '').trim();
  if (!message) return null;

  return { message, status };
}

function extractWeatherItemFromItems(items) {
  if (!Array.isArray(items)) return null;
  const weatherItem = items.find((item) => String(item?.title || '').toLowerCase().includes('meteo'));
  return weatherItem || null;
}

function getAppliedCityFromWeatherItem(item) {
  if (!item) return '';
  const applied = String(item?.appliedCity || item?.badge || '').trim();
  return applied;
}

function getDetectedCityDetailsFromWeatherItem(item) {
  if (!item) {
    return { city: '', status: 'error', message: 'Echec localisation,  saisissez une ville.' };
  }

  const city = String(item?.detectedCity || '').trim();
  const status = String(item?.detectedCityStatus || '').trim() || (city ? 'ok' : 'error');
  const defaultMessage = city ? `Ville detectee: ${city}` : 'Echec localisation,  saisissez une ville.';
  const message = String(item?.detectedCityMessage || '').trim() || defaultMessage;

  return { city, status, message };
}

async function fetchDetectedWeatherCityFromApi() {
  try {
    const data = await apiFetch('/dashboard/advice');
    const items = members(data?.items || []);
    const weatherItem = extractWeatherItemFromItems(items);
    return getDetectedCityDetailsFromWeatherItem(weatherItem);
  } catch {
    return { city: '', status: 'error', message: 'Echec localisation,  saisissez une ville.' };
  }
}

function setWeatherCitySuggestion(detectedCity, statusMessage = '', statusKind = '') {
  const suggestion = document.getElementById('weather-city-suggestion');
  const useDetectedBtn = document.getElementById('weather-city-use-detected');
  const detectStatusEl = document.getElementById('weather-city-detect-status');
  if (!suggestion || !useDetectedBtn || !detectStatusEl) return;

  const city = String(detectedCity || '').trim();
  const message = String(statusMessage || '').trim();
  const kind = String(statusKind || '').trim();

  detectStatusEl.textContent = message;
  detectStatusEl.classList.toggle('is-error', kind === 'error');

  if (!city) {
    suggestion.hidden = true;
    useDetectedBtn.textContent = '';
    return;
  }

  useDetectedBtn.textContent = `Utiliser ${city}`;
  suggestion.hidden = false;
}

async function refreshWeatherCitySuggestion() {
  if (!weatherDetectedCity) {
    const detected = await fetchDetectedWeatherCityFromApi();
    weatherDetectedCity = detected.city;
    weatherDetectedCityStatus = detected.status;
    weatherDetectedCityMessage = detected.message;
  }

  setWeatherCitySuggestion(weatherDetectedCity, weatherDetectedCityMessage, weatherDetectedCityStatus);
}

function updateWeatherCityModalCurrentApplied() {
  const currentAppliedEl = document.getElementById('weather-city-current-applied');
  if (!currentAppliedEl) return;

  const item = getWeatherAdviceItem();
  const city = getAppliedCityFromWeatherItem(item);
  currentAppliedEl.textContent = `Actuellement: ${city || 'Auto'}`;
}

async function loadDashboardMetrics() {
  try {
    dashboardMetrics = await apiFetch('/dashboard/metrics');
  } catch {
    dashboardMetrics = null;
  }
}
function isWidgetEnabled(key) {
  const input = document.querySelector(`[data-widget-toggle="${String(key)}"]`);
  if (input) return !!input.checked;
  return true;
}

function getWidgetPreferencePayload() {
  const widgets = {};
  document.querySelectorAll('[data-widget-toggle]').forEach((input) => {
    widgets[input.dataset.widgetToggle] = !!input.checked;
  });
  return widgets;
}

function syncWidgetToggleState(key, visible) {
  const input = document.querySelector(`[data-widget-toggle="${String(key)}"]`);
  if (input) input.checked = !!visible;

  const pickCard = document.querySelector(`[data-widget-pick="${String(key)}"]`);
  if (pickCard) {
    pickCard.classList.toggle('widget-pick-card--hidden', !!visible);
  }
}

async function hydrateWidgetPreferencesFromApi() {
  const toggles = document.querySelectorAll('[data-widget-toggle]');
  if (!toggles.length) return false;

  try {
    const data = await apiFetch('/user/preferences/dashboard-widgets');
    const widgets = data?.widgets;
    if (!widgets || typeof widgets !== 'object') return false;

    Object.entries(widgets).forEach(([key, visible]) => {
      syncWidgetToggleState(key, !!visible);
    });

    dashboardWidgetPrefsHydrated = true;
    localStorage.setItem(DASHBOARD_WIDGET_PRESET_KEY, '1');
    return true;
  } catch {
    return false;
  }
}

async function toggleWidget(key, visible) {
  syncWidgetToggleState(key, visible);
  renderDashboard();

  try {
    await apiFetch('/user/preferences/dashboard-widgets', {
      method: 'PATCH',
      body: JSON.stringify({ widgets: { [key]: !!visible } }),
    });
  } catch {
    // Keep UI state responsive even if persistence fails temporarily.
    notify('⚠ Préférence widget non sauvegardée pour le moment.');
  }
}

function ensureWidgetPickerBindings() {
  const wrap = document.getElementById('widget-picker-wrap');
  const toggle = document.getElementById('widget-picker-toggle');
  if (wrap && toggle && toggle.dataset.bound !== '1') {
    toggle.addEventListener('click', () => {
      const willExpand = wrap.classList.contains('is-collapsed');
      wrap.classList.toggle('is-collapsed', !willExpand);
      toggle.setAttribute('aria-expanded', willExpand ? 'true' : 'false');
    });
    toggle.dataset.bound = '1';
  }

  const picker = document.getElementById('widget-picker');
  if (picker && picker.dataset.bound !== '1') {
    picker.addEventListener('click', (event) => {
      const button = event.target.closest('[data-widget-pick]');
      if (!button || !picker.contains(button)) return;
      event.preventDefault();

      const key = String(button.dataset.widgetPick || '').trim();
      if (!key || isWidgetEnabled(key)) return;
      button.disabled = true;
      void toggleWidget(key, true).finally(() => {
        button.disabled = false;
      });
    });
    picker.dataset.bound = '1';
  }

  document.querySelectorAll('[data-widget]').forEach((panel) => {
    const key = String(panel.dataset.widget || '').trim();
    if (!key || key === 'monthly_load') return;
    panel.classList.add('widget-trackable');
    if (panel.querySelector('[data-widget-unfollow]')) return;

    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'widget-unfollow-btn';
    button.dataset.widgetUnfollow = key;
    button.title = 'Ne plus suivre';
    button.setAttribute('aria-label', 'Ne plus suivre');
    button.textContent = 'Retirer';
    button.addEventListener('click', () => {
      void toggleWidget(key, false);
    });
    panel.prepend(button);
  });
}

function refreshWidgetPickerUI() {
  let availableCount = 0;
  document.querySelectorAll('[data-widget-pick]').forEach((button) => {
    const key = String(button.dataset.widgetPick || '').trim();
    const visible = isWidgetEnabled(key);
    button.classList.toggle('widget-pick-card--hidden', visible);
    if (!visible) availableCount += 1;
  });

  document.querySelectorAll('[data-widget-unfollow]').forEach((button) => {
    const key = String(button.dataset.widgetUnfollow || '').trim();
    button.style.display = isWidgetEnabled(key) ? '' : 'none';
  });

  const wrap = document.getElementById('widget-picker-wrap');
  const toggle = document.getElementById('widget-picker-toggle');
  if (!wrap || !toggle) return;

  const hasAvailable = availableCount > 0;
  wrap.classList.toggle('widget-picker-wrap--empty', !hasAvailable);
  if (!hasAvailable) {
    wrap.classList.add('is-collapsed');
    toggle.textContent = 'Tous les widgets sont affichés';
    toggle.disabled = true;
    toggle.setAttribute('aria-expanded', 'false');
    return;
  }

  toggle.disabled = false;
  toggle.textContent = '+ Ajouter des widgets';
}

async function applyDefaultWidgetPresetOnce() {
  if (dashboardWidgetPrefsHydrated) return;
  if (localStorage.getItem(DASHBOARD_WIDGET_PRESET_KEY) === '1') return;

  const toggles = Array.from(document.querySelectorAll('[data-widget-toggle]'));
  if (!toggles.length) return;

  const widgets = {};
  let changed = false;

  toggles.forEach((input) => {
    const key = String(input.dataset.widgetToggle || '').trim();
    if (!key) return;
    const target = key === 'monthly_load';
    widgets[key] = target;
    if (!!input.checked !== target) {
      input.checked = target;
      changed = true;
    }
  });

  localStorage.setItem(DASHBOARD_WIDGET_PRESET_KEY, '1');

  if (!changed) return;

  try {
    await apiFetch('/user/preferences/dashboard-widgets', {
      method: 'PATCH',
      body: JSON.stringify({ widgets }),
    });
  } catch {
    // Keep client-side preset even if persistence fails temporarily.
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
  const weatherBox = document.getElementById('dashboard-weather');
  if (!box || !weatherBox) return;
  const sourceItems = Array.isArray(dashboardAdvice) ? [...dashboardAdvice] : [];
  const weatherItem = sourceItems.find((item) => String(item?.title || '').toLowerCase().includes('meteo')) || null;
  const items = sourceItems.filter((item) => item !== weatherItem);

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
      under: '📉',
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

  const createAdviceCard = (item) => {
    const card = itemTpl.content.firstElementChild.cloneNode(true);
    card.classList.add(clsForTone(item?.tone));

    const iconEl = card.querySelector('.advice-icon');
    const titleEl = card.querySelector('.advice-title');
    const badgeEl = card.querySelector('.advice-badge');
    const textEl = card.querySelector('.advice-text');

    if (iconEl) iconEl.textContent = item?.icon || '💡';
    if (titleEl) titleEl.textContent = item?.title || 'Conseil du jour';
    if (badgeEl) {
      const badge = String(item?.badge || '').trim();
      if (badge) {
        badgeEl.textContent = badge;
        badgeEl.style.display = '';
      } else {
        badgeEl.textContent = '';
        badgeEl.style.display = 'none';
      }
    }
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
  };

  const stack = stackTpl.content.firstElementChild.cloneNode(true);
  const nodes = items.map(createAdviceCard);

  if (weatherItem) {
    const weatherCard = createAdviceCard(weatherItem);
    const weatherBadge = weatherCard.querySelector('.advice-badge');
    if (weatherBadge) {
      weatherBadge.classList.add('advice-badge-action');
      weatherBadge.dataset.tooltip = 'Changer de ville';
      weatherBadge.setAttribute('role', 'button');
      weatherBadge.setAttribute('tabindex', '0');
      weatherBadge.setAttribute('aria-label', 'Changer de ville');
      weatherBadge.addEventListener('click', () => {
        openWeatherCityModal();
      });
      weatherBadge.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' || event.key === ' ') {
          event.preventDefault();
          openWeatherCityModal();
        }
      });
    }
    weatherBox.replaceChildren(weatherCard);
  } else {
    weatherBox.replaceChildren();
  }

  stack.replaceChildren(...nodes);
  box.replaceChildren(stack);

  updateWeatherCitySummary();
}

function setupWeatherCityControls() {
  const input = document.getElementById('weather-city-input');
  const applyBtn = document.getElementById('weather-city-apply');
  const resetBtn = document.getElementById('weather-city-reset');
  const useDetectedBtn = document.getElementById('weather-city-use-detected');
  const openBtn = document.getElementById('weather-city-open');
  const closeBtn = document.getElementById('weather-city-close');
  const modal = document.getElementById('weather-city-modal');
  if (!input || !applyBtn || !resetBtn || !closeBtn || !modal) return;

  const saved = String(localStorage.getItem(WEATHER_CITY_STORAGE_KEY) || '').trim();
  input.value = saved;
  updateWeatherCitySummary();
  setWeatherCitySuggestion('', '', '');

  const apply = async () => {
    const city = String(input.value || '').trim();
    if (city) {
      localStorage.setItem(WEATHER_CITY_STORAGE_KEY, city);
    } else {
      localStorage.removeItem(WEATHER_CITY_STORAGE_KEY);
    }

    const loaded = await loadDashboardAdvice();
    renderDashboardAdvice(dashboardMetrics || {});

    if (!loaded) {
      notify('⚠ Impossible de charger la meteo pour cette ville.');
      return;
    }

    const feedback = getWeatherCityFeedback();
    if (!feedback) {
      notify(city ? `✓ Ville meteo appliquee: ${city}` : '✓ Ville meteo automatique');
      return;
    }

    if (feedback.status === 'error') {
      notify(`⚠ ${feedback.message}`);
      return;
    }

    notify(`✓ ${feedback.message}`);
    closeWeatherCityModal();
  };

  applyBtn.addEventListener('click', () => {
    void apply();
  });

  if (useDetectedBtn) {
    useDetectedBtn.addEventListener('click', () => {
      if (!weatherDetectedCity) return;
      input.value = weatherDetectedCity;
      void apply();
    });
  }

  resetBtn.addEventListener('click', () => {
    input.value = '';
    localStorage.removeItem(WEATHER_CITY_STORAGE_KEY);
    void loadDashboardAdvice().then((loaded) => {
      renderDashboardAdvice(dashboardMetrics || {});
      if (!loaded) {
        notify('⚠ Impossible de charger la meteo automatique.');
        return;
      }

      const feedback = getWeatherCityFeedback();
      notify(feedback?.message ? `✓ ${feedback.message}` : '✓ Ville meteo automatique');
      closeWeatherCityModal();
    });
  });

  if (openBtn) {
    openBtn.addEventListener('click', () => {
      openWeatherCityModal();
    });
  }

  closeBtn.addEventListener('click', () => {
    closeWeatherCityModal();
  });

  modal.addEventListener('click', (event) => {
    if (event.target === modal) {
      closeWeatherCityModal();
    }
  });

  input.addEventListener('keydown', (event) => {
    if (event.key !== 'Enter') return;
    event.preventDefault();
    void apply();
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && modal.classList.contains('open')) {
      closeWeatherCityModal();
    }
  });
}

function openWeatherCityModal() {
  const modal = document.getElementById('weather-city-modal');
  const input = document.getElementById('weather-city-input');
  if (!modal || !input) return;

  modal.classList.add('open');
  modal.setAttribute('aria-hidden', 'false');
  const saved = String(localStorage.getItem(WEATHER_CITY_STORAGE_KEY) || '').trim();
  updateWeatherCityModalCurrentApplied();
  void refreshWeatherCitySuggestion().then(() => {
    const fallbackValue = weatherDetectedCity || '';
    if (saved) {
      input.value = saved;
      return;
    }

    const currentValue = String(input.value || '').trim();
    if (currentValue === '') {
      input.value = fallbackValue;
    }
  });
  input.focus();
  input.select();
}

function closeWeatherCityModal() {
  const modal = document.getElementById('weather-city-modal');
  if (!modal) return;
  modal.classList.remove('open');
  modal.setAttribute('aria-hidden', 'true');
}

function updateWeatherCitySummary() {
  const currentEl = document.getElementById('weather-city-current');
  if (!currentEl) return;

  const item = getWeatherAdviceItem();
  const city = String(item?.appliedCity || item?.badge || '').trim();
  currentEl.textContent = city || 'Auto';
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

function consumePlanEditIntentFromUrl() {
  const plansRoot = document.getElementById('plans-detail-weeks');
  const planModal = document.getElementById('plan-modal');
  if (!plansRoot || !planModal) return;

  const params = new URLSearchParams(globalThis.location.search || '');
  const rawDetailId = params.get('editSessionDetailId');
  const rawPlanId = params.get('editPlanId');
  const rawSessionIndex = params.get('editSessionIndex');
  const rawSessionDate = normalizeDateForStorage(params.get('editSessionDate') || '');
  const rawSessionFormat = String(params.get('editSessionFormat') || '').trim();

  if (!rawDetailId && !rawPlanId && !rawSessionIndex && !rawSessionDate) return;

  const detailId = Number(rawDetailId);
  const hasDetailId = Number.isFinite(detailId);
  const planId = Number(rawPlanId);
  const hasPlanId = Number.isFinite(planId);
  const requestedIndex = Number(rawSessionIndex);
  const hasRequestedIndex = Number.isFinite(requestedIndex) && requestedIndex >= 0;

  let targetPlan = null;
  if (hasPlanId) {
    targetPlan = getExtraPlan(planId);
  }
  if (!targetPlan && hasDetailId) {
    targetPlan = (state.extraPlans || []).find((plan) =>
      (Array.isArray(plan.sessions) ? plan.sessions : []).some((s) => Number(s?.detailId) === detailId)
    ) || null;
  }
  if (!targetPlan && rawSessionDate) {
    targetPlan = (state.extraPlans || []).find((plan) =>
      (Array.isArray(plan.sessions) ? plan.sessions : []).some((s) => normalizeDateForStorage(s?.date) === rawSessionDate)
    ) || null;
  }
  if (!targetPlan) return;

  let idx = -1;
  const sessions = Array.isArray(targetPlan.sessions) ? targetPlan.sessions : [];
  if (hasDetailId) {
    idx = sessions.findIndex((s) => Number(s?.detailId) === detailId);
  }
  if (idx < 0 && hasRequestedIndex && requestedIndex < sessions.length) {
    idx = requestedIndex;
  }
  if (idx < 0 && rawSessionDate && rawSessionFormat) {
    idx = sessions.findIndex((s) => normalizeDateForStorage(s?.date) === rawSessionDate && String(s?.format || '').trim() === rawSessionFormat);
  }
  if (idx < 0 && rawSessionDate) {
    idx = sessions.findIndex((s) => normalizeDateForStorage(s?.date) === rawSessionDate);
  }

  openPlan(targetPlan.id);
  if (idx >= 0) {
    openPlanEdit(`extra:${targetPlan.id}`, idx);
  }

  const cleanUrl = new URL(globalThis.location.href);
  cleanUrl.searchParams.delete('editSessionDetailId');
  cleanUrl.searchParams.delete('editPlanId');
  cleanUrl.searchParams.delete('editSessionIndex');
  cleanUrl.searchParams.delete('editSessionDate');
  cleanUrl.searchParams.delete('editSessionFormat');
  globalThis.history.replaceState({}, '', `${cleanUrl.pathname}${cleanUrl.search}${cleanUrl.hash}`);
}

function consumeRaceEditIntentFromUrl() {
  const raceModal = document.getElementById('race-modal');
  if (!raceModal) return;

  const params = new URLSearchParams(globalThis.location.search || '');
  const rawRaceId = params.get('editRaceId');
  if (!rawRaceId) return;

  const raceId = Number(rawRaceId);
  if (!Number.isFinite(raceId)) return;
  if (!(Array.isArray(racesData) && racesData.some((r) => Number(r.id) === raceId))) return;

  openRaceEdit(raceId);

  const cleanUrl = new URL(globalThis.location.href);
  cleanUrl.searchParams.delete('editRaceId');
  globalThis.history.replaceState({}, '', `${cleanUrl.pathname}${cleanUrl.search}${cleanUrl.hash}`);
}

function iriToId(iri) {
  if (!iri) return null;
  const parts = String(iri).split('/');
  return Number.parseInt(parts.at(-1), 10);
}

function normalizeLog(r) {
  const plannedSessionIri = typeof r.plannedSession === 'string'
    ? r.plannedSession
    : (r.plannedSession?.['@id'] || r.plannedSession?.id || null);
  const plannedSessionId = Number.parseInt(r.plannedSessionId ?? iriToId(plannedSessionIri), 10);
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
    plannedSessionId: Number.isFinite(plannedSessionId) ? plannedSessionId : null,
    plannedSessionLabel: String(r.plannedSessionLabel || '').trim() || null,
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
    statusClass: r.statusClass,
    statusLabel: r.statusLabel,
    resultDelta: r.resultDelta,
  };
}

// ============================================================
// UTILS
// ============================================================
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
  const text = String(msg || '');
  let kind = 'info';
  if (text.startsWith('⚠')) {
    kind = 'error';
  } else if (text.startsWith('✓')) {
    kind = 'success';
  }
  n.textContent = text;
  n.className = `notif notif-${kind} show`;
  setTimeout(()=>n.classList.remove('show'),2500);
}

const plannedSessionPickerState = {
  dateInputId: 'log-date',
  textInputId: 'log-planned-session-text',
  hiddenInputId: 'log-planned-session-id',
  monthStartKey: '',
  selectedDateKey: '',
  selectedSessionId: null,
};

function pickerMonthStartKey(dateKey) {
  const d = dateKey ? new Date(`${dateKey}T00:00:00`) : new Date();
  const year = d.getFullYear();
  const month = String(d.getMonth() + 1).padStart(2, '0');
  return `${year}-${month}-01`;
}

function shiftPickerMonth(monthStartKey, delta) {
  const base = new Date(`${monthStartKey}T00:00:00`);
  base.setMonth(base.getMonth() + delta);
  const year = base.getFullYear();
  const month = String(base.getMonth() + 1).padStart(2, '0');
  return `${year}-${month}-01`;
}

function ensurePlannedSessionPickerModal() {
  let overlay = document.getElementById('planned-session-picker-modal');
  if (!overlay) {
    overlay = document.createElement('div');
    overlay.id = 'planned-session-picker-modal';
    overlay.className = 'modal-overlay';
    overlay.innerHTML = `
      <div class="modal planned-picker-modal">
        <div class="modal-title">Choisir une séance prévue</div>
        <div class="planned-picker-toolbar">
          <button type="button" class="btn btn-ghost" id="planned-picker-prev">◀</button>
          <div class="planned-picker-month" id="planned-picker-month"></div>
          <button type="button" class="btn btn-ghost" id="planned-picker-next">▶</button>
        </div>
        <div class="planned-picker-grid" id="planned-picker-grid"></div>
        <div class="planned-picker-list" id="planned-picker-list"></div>
        <div class="modal-actions">
          <button type="button" class="btn" id="planned-picker-apply" disabled>Valider</button>
          <button type="button" class="btn btn-ghost" id="planned-picker-close">Fermer</button>
        </div>
      </div>`;
    document.body.appendChild(overlay);

    document.getElementById('planned-picker-prev')?.addEventListener('click', () => {
      plannedSessionPickerState.monthStartKey = shiftPickerMonth(plannedSessionPickerState.monthStartKey, -1);
      renderPlannedSessionPicker();
    });
    document.getElementById('planned-picker-next')?.addEventListener('click', () => {
      plannedSessionPickerState.monthStartKey = shiftPickerMonth(plannedSessionPickerState.monthStartKey, 1);
      renderPlannedSessionPicker();
    });
    document.getElementById('planned-picker-apply')?.addEventListener('click', () => {
      const allSessions = Array.isArray(plannedSessionsForLogs) ? plannedSessionsForLogs : [];
      let sessionId = Number.parseInt(plannedSessionPickerState.selectedSessionId, 10);

      if (!Number.isFinite(sessionId) && plannedSessionPickerState.selectedDateKey) {
        const sameDaySessions = allSessions.filter(
          (item) => normalizeDateForStorage(item.sessionDate) === plannedSessionPickerState.selectedDateKey
        );
        if (sameDaySessions.length > 0) {
          sessionId = Number.parseInt(sameDaySessions[0]?.id, 10);
          plannedSessionPickerState.selectedSessionId = sessionId;
        }
      }

      if (!Number.isFinite(sessionId)) {
        notify('⚠ Sélectionne une séance puis valide');
        return;
      }

      const session = allSessions.find((item) => Number(item.id) === sessionId);
      if (!session) {
        notify('⚠ Séance introuvable');
        return;
      }

      setPlannedSessionSelection(
        plannedSessionPickerState.textInputId,
        plannedSessionPickerState.hiddenInputId,
        session.id,
      );

      const dateInput = document.getElementById(plannedSessionPickerState.dateInputId);
      const plannedDateStr = session.sessionDate ? normalizeDateForStorage(session.sessionDate) : null;
      let successMessage = '✓ Séance prévue liée au log';

      if (dateInput instanceof HTMLInputElement) {
        const logDateStr = dateInput.value;
        if (plannedDateStr) {
          // Toujours synchroniser la date du log avec la séance choisie.
          dateInput.value = plannedDateStr;
          dateInput.dispatchEvent(new Event('change', { bubbles: true }));
        }
        if (plannedDateStr && logDateStr && logDateStr !== plannedDateStr) {
          const plannedFormatted = formatDate(plannedDateStr);
          successMessage = `✓ Séance liée — date du log synchronisée au ${plannedFormatted}`;
        }
      }

      closeModal('planned-session-picker-modal');
      notify(successMessage);
    });
    document.getElementById('planned-picker-close')?.addEventListener('click', () => closeModal('planned-session-picker-modal'));
    overlay.addEventListener('click', (event) => {
      if (event.target === overlay) closeModal('planned-session-picker-modal');
    });
  }

  return {
    monthEl: document.getElementById('planned-picker-month'),
    gridEl: document.getElementById('planned-picker-grid'),
    listEl: document.getElementById('planned-picker-list'),
  };
}

function renderPlannedSessionPickerList(dateKey) {
  const listEl = document.getElementById('planned-picker-list');
  const applyBtn = document.getElementById('planned-picker-apply');
  if (!listEl) return;

  const selectedId = Number.parseInt(plannedSessionPickerState.selectedSessionId, 10);
  const sessions = (Array.isArray(plannedSessionsForLogs) ? plannedSessionsForLogs : [])
    .filter((item) => normalizeDateForStorage(item.sessionDate) === dateKey)
    .sort((a, b) => {
      const aId = Number.parseInt(a?.id, 10);
      const bId = Number.parseInt(b?.id, 10);
      const aSelected = Number.isFinite(selectedId) && aId === selectedId;
      const bSelected = Number.isFinite(selectedId) && bId === selectedId;
      if (aSelected && !bSelected) return -1;
      if (!aSelected && bSelected) return 1;

      const aPos = Number.parseInt(a?.position, 10);
      const bPos = Number.parseInt(b?.position, 10);
      if (Number.isFinite(aPos) && Number.isFinite(bPos) && aPos !== bPos) return aPos - bPos;
      if (Number.isFinite(aPos) && !Number.isFinite(bPos)) return -1;
      if (!Number.isFinite(aPos) && Number.isFinite(bPos)) return 1;

      return String(a?.format || '').localeCompare(String(b?.format || ''), 'fr', { sensitivity: 'base' });
    });

  if (sessions.length > 0 && !Number.isFinite(Number.parseInt(plannedSessionPickerState.selectedSessionId, 10))) {
    plannedSessionPickerState.selectedSessionId = sessions[0].id;
  }

  const hasSelectedInList = sessions.some((item) => Number(item.id) === Number(plannedSessionPickerState.selectedSessionId));
  if (!hasSelectedInList) {
    plannedSessionPickerState.selectedSessionId = null;
  }
  if (applyBtn) applyBtn.disabled = !hasSelectedInList;

  if (!dateKey || sessions.length === 0) {
    listEl.textContent = 'Aucune séance prévue pour ce jour.';
    if (applyBtn) applyBtn.disabled = true;
    return;
  }

  const title = document.createElement('div');
  title.className = 'planned-picker-list-title';
  title.textContent = `Séances du ${formatDate(dateKey)}`;

  const items = sessions.map((session) => {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'planned-picker-session-btn';
    if (Number(plannedSessionPickerState.selectedSessionId) === Number(session.id)) {
      btn.classList.add('is-selected');
    }
    btn.textContent = plannedSessionLabel(session);
    btn.addEventListener('click', () => {
      plannedSessionPickerState.selectedSessionId = session.id;
      renderPlannedSessionPickerList(dateKey);
    });
    return btn;
  });

  listEl.replaceChildren(title, ...items);
}

function renderPlannedSessionPicker() {
  const { monthEl, gridEl } = ensurePlannedSessionPickerModal();
  if (!monthEl || !gridEl) return;

  const monthStart = new Date(`${plannedSessionPickerState.monthStartKey}T00:00:00`);
  monthEl.textContent = monthStart.toLocaleDateString('fr-FR', { month: 'long', year: 'numeric' });

  const firstOfMonth = new Date(monthStart.getFullYear(), monthStart.getMonth(), 1);
  const startOffset = (firstOfMonth.getDay() + 6) % 7;
  const gridStart = new Date(firstOfMonth);
  gridStart.setDate(firstOfMonth.getDate() - startOffset);

  const sessionsByDate = new Map();
  (Array.isArray(plannedSessionsForLogs) ? plannedSessionsForLogs : []).forEach((item) => {
    const dayKey = normalizeDateForStorage(item.sessionDate);
    if (!dayKey) return;
    const current = sessionsByDate.get(dayKey) || [];
    current.push(item);
    sessionsByDate.set(dayKey, current);
  });

  const nodes = [];
  for (let i = 0; i < 42; i += 1) {
    const date = new Date(gridStart);
    date.setDate(gridStart.getDate() + i);
    const dateKey = normalizeDateForStorage(date);
    const sessions = sessionsByDate.get(dateKey) || [];
    const count = sessions.length;

    const dayBtn = document.createElement('button');
    dayBtn.type = 'button';
    dayBtn.className = 'planned-picker-day';
    if (date.getMonth() !== monthStart.getMonth()) dayBtn.classList.add('is-outside');
    if (count > 0) dayBtn.classList.add('has-sessions');
    if (plannedSessionPickerState.selectedDateKey === dateKey) dayBtn.classList.add('is-selected');
    const dayNum = document.createElement('span');
    dayNum.className = 'planned-picker-day-num';
    dayNum.textContent = String(date.getDate());
    dayBtn.appendChild(dayNum);

    if (count > 0) {
      const countEl = document.createElement('small');
      countEl.className = 'planned-picker-day-count';
      countEl.textContent = String(count);
      dayBtn.appendChild(countEl);

      const preview = document.createElement('div');
      preview.className = 'planned-picker-day-preview';
      sessions.slice(0, 2).forEach((session) => {
        const line = document.createElement('small');
        line.className = 'planned-picker-day-session';
        const pos = Number.parseInt(session?.position, 10);
        const sessionPrefix = Number.isFinite(pos) ? `S${pos}` : 'Séance';
        const sessionType = normalizeSessionType(session?.sessionType ?? session?.session_type ?? session?.type) || '—';
        line.textContent = `${sessionPrefix} · ${sessionType}`;
        preview.appendChild(line);
      });
      dayBtn.appendChild(preview);
    }

    dayBtn.addEventListener('click', () => {
      plannedSessionPickerState.selectedDateKey = dateKey;
      plannedSessionPickerState.selectedSessionId = count > 0 ? sessions[0].id : null;
      renderPlannedSessionPicker();
      renderPlannedSessionPickerList(dateKey);
    });
    nodes.push(dayBtn);
  }

  gridEl.replaceChildren(...nodes);
  renderPlannedSessionPickerList(plannedSessionPickerState.selectedDateKey);
}

function openPlannedSessionCalendarPicker(dateInputId, textInputId, hiddenInputId) {
  plannedSessionPickerState.dateInputId = dateInputId || 'log-date';
  plannedSessionPickerState.textInputId = textInputId || 'log-planned-session-text';
  plannedSessionPickerState.hiddenInputId = hiddenInputId || 'log-planned-session-id';

  if (plannedSessionPickerState.hiddenInputId === 'log-planned-session-id') {
    setLogEntryMode('calendar', { clearCalendarSelection: false });
  }

  const dateInput = document.getElementById(plannedSessionPickerState.dateInputId);
  const hiddenInput = document.getElementById(plannedSessionPickerState.hiddenInputId);
  const initialDate = dateInput instanceof HTMLInputElement ? normalizeDateForStorage(dateInput.value) : '';
  const initialSessionId = Number.parseInt(hiddenInput instanceof HTMLInputElement ? hiddenInput.value : '', 10);
  plannedSessionPickerState.selectedDateKey = initialDate;
  plannedSessionPickerState.monthStartKey = pickerMonthStartKey(initialDate);
  plannedSessionPickerState.selectedSessionId = Number.isFinite(initialSessionId) ? initialSessionId : null;

  renderPlannedSessionPicker();
  openModal('planned-session-picker-modal');
}
globalThis.openPlannedSessionCalendarPicker = openPlannedSessionCalendarPicker;
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
  ensureWidgetPickerBindings();
  refreshWidgetPickerUI();
  const kpisData = metrics.kpis || {};

  // Helper: show/hide a widget wrapper by data-widget attribute
  document.querySelectorAll('[data-widget]').forEach((el) => {
    const key = el.dataset.widget;
    el.style.display = isWidgetEnabled(key) ? '' : 'none';
  });

  const kpiGrid = document.getElementById('kpi-grid');
  if (kpiGrid && isWidgetEnabled('kpis')) {
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

  if (isWidgetEnabled('plan_progress')) {
    const progress = metrics.planProgress || { title: '', done: 0, total: 0, pct: 0 };
    const labelEl = document.getElementById('progress-plan-label');
    if (labelEl) labelEl.textContent = progress.title;
    const tempoPctEl = document.getElementById('tempo-pct');
    if (tempoPctEl) tempoPctEl.textContent = progress.pct + '%';
    const tempoBarEl = document.getElementById('tempo-bar');
    if (tempoBarEl) tempoBarEl.style.width = progress.pct + '%';
    const tempoMetaEl = document.getElementById('tempo-meta');
    if (tempoMetaEl) tempoMetaEl.textContent = `${progress.done} / ${progress.total} séances complétées`;
  }

  if (isWidgetEnabled('plan_calendar')) renderPlanCalendar(metrics.planCalendar || null);

  const barsSource = Array.isArray(metrics.monthlyBars) ? metrics.monthlyBars : [];
  const monthlyChart = document.getElementById('monthly-chart');
  if (monthlyChart && isWidgetEnabled('monthly_load')) {
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
  if (raceTbody && isWidgetEnabled('races_table')) {
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

function buildPlanCalendarDayNodes(days, racesByDate, personalByDate) {
  const todayKey = normalizeDateForStorage(new Date().toISOString().slice(0, 10));
  return days.map((day) => {
    const dayKey = normalizeDateForStorage(day?.date);
    const sessionItems = (Array.isArray(day?.items) ? day.items : []).map((sessionItem) => {
      const normalizedLabel = String(sessionItem?.label || '');
      const sessionMatch = /seance\s+(\d+)/i.exec(normalizedLabel);
      const parsedSessionNumber = Number.parseInt(sessionMatch?.[1] || '', 10);
      return {
        ...sessionItem,
        kind: sessionItem?.kind || 'session',
        date: dayKey,
        sessionIndex: Number.isFinite(parsedSessionNumber) ? Math.max(0, parsedSessionNumber - 1) : null,
      };
    });
    const raceItems = racesByDate.get(dayKey) || [];
    const personalItems = personalByDate.get(dayKey) || [];
    const items = [...sessionItems, ...raceItems, ...personalItems];

    const cell = document.createElement('article');
    cell.className = 'plan-calendar-day';
    if (!day?.inMonth) cell.classList.add('is-outside');
    if (day?.isToday) cell.classList.add('is-today');
    if (items.length > 0) cell.classList.add('has-items');

    const head = document.createElement('div');
    head.className = 'plan-calendar-day-head';
    const num = document.createElement('span');
    num.className = 'plan-calendar-day-num';
    num.textContent = String(day?.day ?? '');
    head.appendChild(num);

    if (day?.inMonth && dayKey) {
      const addBtn = document.createElement('button');
      addBtn.type = 'button';
      addBtn.className = 'plan-calendar-day-add';
      addBtn.textContent = '+';
      addBtn.title = 'Ajouter un evenement perso';
      addBtn.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        openCalendarActionModal({ kind: 'personal', date: dayKey, title: '' });
      });
      head.appendChild(addBtn);
    }

    const list = document.createElement('div');
    list.className = 'plan-calendar-items';

    items.forEach((item) => {
      const itemKind = item?.kind === 'race' ? 'race' : 'session';
      const normalizedKind = item?.kind === 'personal' ? 'personal' : itemKind;
      const entry = document.createElement('div');
      entry.className = 'plan-calendar-item';
      if (normalizedKind === 'race') entry.classList.add('is-race');
      if (normalizedKind === 'personal') entry.classList.add('is-personal');
      if (item?.isDone) entry.classList.add('is-done');
      if (item?.isOptional) entry.classList.add('is-optional');
      if (!item?.isDone && normalizedKind === 'session') {
        if (dayKey && todayKey && dayKey < todayKey) entry.classList.add('is-past');
        else if (dayKey && todayKey && dayKey > todayKey) entry.classList.add('is-future');
      }
      entry.title = [item?.label, item?.format, item?.pe].filter(Boolean).join(' · ');

      const hasRaceRef = normalizedKind === 'race' && Number.isFinite(Number(item?.raceId));
      const hasSessionRef = normalizedKind === 'session' && Number.isFinite(Number(item?.detailId));
      if (hasRaceRef || normalizedKind === 'session' || normalizedKind === 'personal') {
        entry.classList.add('is-actionable');
        entry.tabIndex = 0;
        entry.setAttribute('role', 'button');
        const actionPayload = {
          ...item,
          kind: normalizedKind,
          date: dayKey,
          hasSessionRef,
        };
        entry.addEventListener('click', (event) => {
          event.preventDefault();
          event.stopPropagation();
          openCalendarActionModal(actionPayload);
        });
        entry.addEventListener('keydown', (event) => {
          if (event.key !== 'Enter' && event.key !== ' ') return;
          event.preventDefault();
          openCalendarActionModal(actionPayload);
        });
      }

      const label = document.createElement('div');
      label.className = 'plan-calendar-item-label';
      let itemLabel = item?.label || 'Séance';
      const itemSessionType = normalizeSessionType(item?.sessionType ?? item?.session_type ?? item?.type);
      if (normalizedKind === 'session' && itemSessionType) {
        itemLabel = `${itemLabel} · ${itemSessionType}`;
      }
      if (normalizedKind === 'race') itemLabel = 'Course';
      if (normalizedKind === 'personal') itemLabel = 'Perso';
      label.textContent = itemLabel;
      const format = document.createElement('div');
      format.className = 'plan-calendar-item-format';
      const suffix = item?.pe ? ` · ${item.pe}` : '';
      format.textContent = `${item?.format || '—'}${suffix}`;

      entry.append(label, format);

      // Badge "sans log" : séance marquée done mais sans sortie enregistrée
      if (item?.isDone && item?.hasLog === false) {
        const badge = document.createElement('div');
        badge.className = 'plan-calendar-item-no-log';
        badge.title = 'Séance validée sans sortie enregistrée dans les logs';
        badge.textContent = '! log';
        entry.appendChild(badge);
      }

      list.appendChild(entry);
    });

    cell.append(head, list);
    return cell;
  });
}

const PLAN_CALENDAR_MONTH_NAMES = ['janvier', 'fevrier', 'mars', 'avril', 'mai', 'juin', 'juillet', 'aout', 'septembre', 'octobre', 'novembre', 'decembre'];

function isPlanCalendarMonthKey(value) {
  return /^\d{4}-\d{2}$/.test(String(value || ''));
}

function formatPlanCalendarMonthLabel(monthKey, fallback) {
  const m = /^(\d{4})-(\d{2})$/.exec(monthKey);
  if (!m) return fallback || '—';
  const year = Number.parseInt(m[1], 10);
  const month = Number.parseInt(m[2], 10);
  const monthName = PLAN_CALENDAR_MONTH_NAMES[month - 1] || m[2];
  return `${monthName.charAt(0).toUpperCase()}${monthName.slice(1)} ${year}`;
}

function stepPlanCalendarMonthKey(monthKey, delta) {
  const m = /^(\d{4})-(\d{2})$/.exec(monthKey);
  if (!m) return monthKey;
  const base = new Date(Number.parseInt(m[1], 10), Number.parseInt(m[2], 10) - 1, 1);
  base.setMonth(base.getMonth() + delta);
  const y = base.getFullYear();
  const mo = String(base.getMonth() + 1).padStart(2, '0');
  return `${y}-${mo}`;
}

function buildPlanCalendarDaysForMonth(monthKey, apiDays, baseMonthKey, itemsByDate, fallbackDaysBuilder) {
  if (Array.isArray(apiDays) && apiDays.length && monthKey === baseMonthKey) {
    return apiDays;
  }

  const m = /^(\d{4})-(\d{2})$/.exec(monthKey);
  if (!m) return fallbackDaysBuilder();

  const year = Number.parseInt(m[1], 10);
  const monthIndex = Number.parseInt(m[2], 10) - 1;
  const firstOfMonth = new Date(year, monthIndex, 1);
  const startOffset = (firstOfMonth.getDay() + 6) % 7;
  const gridStart = new Date(year, monthIndex, 1 - startOffset);
  const todayKey = normalizeDateForStorage(new Date());
  const days = [];

  for (let i = 0; i < 42; i += 1) {
    const d = new Date(gridStart);
    d.setDate(gridStart.getDate() + i);
    const dateKey = normalizeDateForStorage(d);
    days.push({
      date: dateKey,
      day: d.getDate(),
      inMonth: d.getMonth() === monthIndex,
      isToday: dateKey === todayKey,
      items: Array.isArray(itemsByDate[dateKey]) ? itemsByDate[dateKey] : [],
    });
  }

  return days;
}

function renderPlanCalendar(calendar) {
  const wrap = document.getElementById('plan-calendar-wrap');
  const grid = document.getElementById('plan-calendar-grid');
  const titleEl = document.getElementById('plan-calendar-title');
  const monthEl = document.getElementById('plan-calendar-month');
  const summaryEl = document.getElementById('plan-calendar-summary');
  const emptyEl = document.getElementById('plan-calendar-empty');
  const prevBtn = document.getElementById('plan-calendar-prev');
  const nextBtn = document.getElementById('plan-calendar-next');
  if (!wrap || !grid || !titleEl || !monthEl || !summaryEl || !emptyEl) return;

  const safeCalendar = calendar && typeof calendar === 'object' ? calendar : {
    title: 'Calendrier des séances prévues',
    monthKey: normalizeDateForStorage(new Date()).slice(0, 7),
    monthLabel: new Date().toLocaleDateString('fr-FR', { month: 'long', year: 'numeric' }),
    summary: 'Aucune séance programmée ce mois-ci',
    emptyMessage: 'Aucune séance planifiée. Utilise + pour ajouter un événement perso.',
    itemsByDate: {},
    days: [],
  };

  const buildFallbackCalendarDays = () => {
    const now = new Date();
    const year = now.getFullYear();
    const month = now.getMonth();
    const firstOfMonth = new Date(year, month, 1);
    const startOffset = (firstOfMonth.getDay() + 6) % 7;
    const gridStart = new Date(year, month, 1 - startOffset);
    const todayKey = normalizeDateForStorage(now);
    const days = [];

    for (let i = 0; i < 42; i += 1) {
      const date = new Date(gridStart);
      date.setDate(gridStart.getDate() + i);
      const dateKey = normalizeDateForStorage(date);
      days.push({
        date: dateKey,
        day: date.getDate(),
        inMonth: date.getMonth() === month,
        isToday: dateKey === todayKey,
        items: [],
      });
    }

    return days;
  };

  titleEl.textContent = safeCalendar.title || 'Calendrier des séances prévues';
  emptyEl.textContent = safeCalendar.emptyMessage || '';
  emptyEl.style.display = safeCalendar.emptyMessage ? 'block' : 'none';


  const racesByDate = new Map();
  (Array.isArray(racesData) ? racesData : []).forEach((race) => {
    const dateKey = normalizeDateForStorage(race?.date);
    if (!dateKey) return;
    const raceResult = String(race?.result || '').trim();
    if (!racesByDate.has(dateKey)) racesByDate.set(dateKey, []);
    racesByDate.get(dateKey).push({
      kind: 'race',
      raceId: Number.parseInt(race?.id, 10),
      label: race?.name || 'Course',
      format: race?.distance ? String(race.distance) : 'Course',
      pe: race?.objective ? `Obj ${race.objective}` : null,
      result: raceResult,
      isDone: raceResult.length > 0,
      isOptional: false,
    });
  });

  const personalByDate = new Map();
  (Array.isArray(calendarEventsData) ? calendarEventsData : []).forEach((evt) => {
    if (!evt?.date) return;
    if (!personalByDate.has(evt.date)) personalByDate.set(evt.date, []);
    personalByDate.get(evt.date).push({
      kind: 'personal',
      personalId: evt.id,
      date: evt.date,
      label: 'Perso',
      format: evt.title,
      title: evt.title,
      isDone: false,
      isOptional: false,
    });
  });

  const itemsByDate = safeCalendar.itemsByDate && typeof safeCalendar.itemsByDate === 'object'
    ? safeCalendar.itemsByDate
    : {};
  const apiDays = Array.isArray(safeCalendar.days) ? safeCalendar.days : [];
  const baseMonthKey = isPlanCalendarMonthKey(safeCalendar.monthKey)
    ? String(safeCalendar.monthKey)
    : (() => {
        const inMonthDay = apiDays.find((d) => d?.inMonth && /^\d{4}-\d{2}-\d{2}$/.test(String(d?.date || '')));
        return inMonthDay ? String(inMonthDay.date).slice(0, 7) : normalizeDateForStorage(new Date()).slice(0, 7);
      })();

  const signature = `${baseMonthKey}|${Object.keys(itemsByDate).length}`;
  if (wrap.dataset.planCalSig !== signature || !isPlanCalendarMonthKey(wrap.dataset.planCalMonth)) {
    wrap.dataset.planCalSig = signature;
    wrap.dataset.planCalMonth = baseMonthKey;
  }

  const renderMonth = (monthKey) => {
    const days = buildPlanCalendarDaysForMonth(monthKey, apiDays, baseMonthKey, itemsByDate, buildFallbackCalendarDays);
    monthEl.textContent = formatPlanCalendarMonthLabel(monthKey, safeCalendar.monthLabel || '—');

    const plannedCount = days
      .filter((d) => d?.inMonth)
      .reduce((acc, d) => acc + ((Array.isArray(d?.items) ? d.items : []).filter((it) => (it?.kind || 'session') === 'session').length), 0);
    if (plannedCount > 0) {
      const suffix = plannedCount > 1 ? 's' : '';
      summaryEl.textContent = `${plannedCount} seance${suffix} programmee${suffix}`;
    } else {
      summaryEl.textContent = safeCalendar.summary || 'Aucune seance programmee ce mois-ci';
    }

    const nodes = buildPlanCalendarDayNodes(days, racesByDate, personalByDate);

    grid.replaceChildren(...nodes);
  };

  if (prevBtn) {
    prevBtn.onclick = () => {
      const current = String(wrap.dataset.planCalMonth || baseMonthKey);
      const next = stepPlanCalendarMonthKey(current, -1);
      wrap.dataset.planCalMonth = next;
      renderMonth(next);
    };
  }
  if (nextBtn) {
    nextBtn.onclick = () => {
      const current = String(wrap.dataset.planCalMonth || baseMonthKey);
      const next = stepPlanCalendarMonthKey(current, 1);
      wrap.dataset.planCalMonth = next;
      renderMonth(next);
    };
  }

  renderMonth(String(wrap.dataset.planCalMonth || baseMonthKey));
}

function renderTrainingLoad() {
  const wrap = document.getElementById('training-load-wrap');
  if (!wrap) return;

  if (!isWidgetEnabled('training_load')) {
    wrap.style.display = 'none';
    return;
  }

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
  const wrap = document.getElementById('ef-bpm-wrap');

  if (!isWidgetEnabled('ef_bpm')) {
    container.style.display = 'none';
    if (wrap) wrap.style.display = 'none';
    return;
  }

  const metrics = dashboardMetrics || {};
  const ef = metrics.ef || {};
  const trend = Array.isArray(ef.efBpmTrend) ? ef.efBpmTrend : [];

  if (trend.length < 2) {
    container.style.display = 'none';
    if (wrap) wrap.style.display = 'none';
    return;
  }

  container.style.display = '';
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
    const m = node.querySelector('.alert-msg') || node.querySelector('.alert-body');
    if (t) t.textContent = `${a.ok ? '✓' : '⚠'} ${a.title}`;
    if (m) m.textContent = String(a.msg || 'Aucun detail disponible.');
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
    if (card && typeof d?.color === 'string' && d.color.trim()) {
      card.style.setProperty('--proj-tint', d.color.trim());
    }
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
  ep.sessions.push({ sem: 1, date: null, format: "45'@Z2", sessionType: 'EF', pe: '3/10', total: 45, opt: false });
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
        : !!state.doneByKey?.[stateKey]?.[idx];
      const row = cloneTemplate('plan-session-row-template') || document.createElement('div');
      row.dataset.sessionIndex = String(idx);
      const checkEl = row.querySelector('.session-check');
      const formatEl = row.querySelector('.session-format');
      const dateEl = row.querySelector('.session-date-badge');
      const typeEl = row.querySelector('.session-type-badge');
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
      if (typeEl) {
        const sessionType = normalizeSessionType(s.sessionType ?? s.session_type ?? s.type) || '';
        typeEl.hidden = !sessionType;
        typeEl.textContent = sessionType;
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

  state.doneByKey[stateKey] ??= {};
  state.doneByKey[stateKey][idx] = !state.doneByKey[stateKey][idx];
  const c = row.querySelector('.session-check');
  c.classList.toggle('done', !!state.doneByKey[stateKey][idx]);
  c.textContent = state.doneByKey[stateKey][idx] ? '✓' : '';
  
  // Update UI and lists immediately
  renderPlansList();
  notify(state.doneByKey[stateKey][idx] ? '✓ Séance validée !' : 'Séance décochée');
  
  // Save in background (non-blocking)
  savePlanProgress(stateKey, idx, state.doneByKey[stateKey][idx])
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
  document.getElementById('pm-type').value = normalizeSessionType(s.sessionType ?? s.session_type ?? s.type) || '';
  document.getElementById('pm-date').value = normalizeDateForStorage(s.date);
  document.getElementById('pm-pe').value = s.pe || '';
  document.getElementById('pm-total').value = s.total || '';
  document.getElementById('pm-opt').checked = !!s.opt;
  openModal('plan-modal');
}

async function savePlanEdit() {
  const sk = document.getElementById('pm-statekey').value;
  const idx = Number.parseInt(document.getElementById('pm-idx').value, 10);
  const isExtra = sk.startsWith('extra:');
  const planId = isExtra ? sk.slice(6) : null;
  const d = isExtra ? getExtraPlan(planId)?.sessions : [];
  if (!d?.[idx]) return;

  d[idx].format = document.getElementById('pm-format').value;
  d[idx].sessionType = normalizeSessionType(document.getElementById('pm-type').value);
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
    try {
      await replacePlanSessionsInDb(planId, d, getExtraPlan(planId)?.done || {});
      await loadPlansFromDb();
      const reloadedPlan = getExtraPlan(planId);
      if (reloadedPlan) {
        renderPlan('plans-detail-weeks', reloadedPlan.sessions, sk);
      } else {
        renderPlan('plans-detail-weeks', d, sk);
      }
    } catch (e) {
      notify(`⚠ ${e.message}`);
      return;
    }
  } else {
    renderPlan('plans-detail-weeks', d, sk);
  }

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
  ['plan-modal','log-modal','race-modal','race-result-modal','newplan-modal','meta-modal'].forEach(id=>{
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

function ensureCalendarActionModal() {
  let overlay = document.getElementById('calendar-action-modal');
  if (overlay) {
    return {
      overlay,
      title: document.getElementById('calendar-action-title'),
      subtitle: document.getElementById('calendar-action-subtitle'),
      inputWrap: document.getElementById('calendar-action-input-wrap'),
      inputLabel: document.querySelector('label[for="calendar-race-result-input"]'),
      input: document.getElementById('calendar-race-result-input'),
      buttons: document.getElementById('calendar-action-buttons'),
    };
  }

  overlay = document.createElement('div');
  overlay.id = 'calendar-action-modal';
  overlay.className = 'modal-overlay';
  overlay.innerHTML = `
    <div class="modal">
      <button class="modal-close" aria-label="Fermer">x</button>
      <div class="modal-title" id="calendar-action-title">Actions calendrier</div>
      <div class="calendar-action-sub" id="calendar-action-subtitle"></div>
      <div class="field calendar-action-input" id="calendar-action-input-wrap" style="display:none">
        <label for="calendar-race-result-input">Resultat (hh:mm:ss)</label>
        <input type="text" id="calendar-race-result-input" placeholder="00:53:22">
      </div>
      <div class="modal-actions" id="calendar-action-buttons"></div>
    </div>
  `;
  document.body.appendChild(overlay);

  const closeBtn = overlay.querySelector('.modal-close');
  if (closeBtn) {
    closeBtn.addEventListener('click', () => closeModal('calendar-action-modal'));
  }
  overlay.addEventListener('click', (event) => {
    if (event.target === overlay) closeModal('calendar-action-modal');
  });

  return {
    overlay,
    title: document.getElementById('calendar-action-title'),
    subtitle: document.getElementById('calendar-action-subtitle'),
    inputWrap: document.getElementById('calendar-action-input-wrap'),
    inputLabel: document.querySelector('label[for="calendar-race-result-input"]'),
    input: document.getElementById('calendar-race-result-input'),
    buttons: document.getElementById('calendar-action-buttons'),
  };
}

function calendarActionButton(label, classes, onClick) {
  const button = document.createElement('button');
  button.type = 'button';
  button.className = classes;
  button.textContent = label;
  button.addEventListener('click', onClick);
  return button;
}

function openCalendarActionModal(item) {
  const modal = ensureCalendarActionModal();
  if (!modal.overlay || !modal.buttons || !modal.title || !modal.subtitle || !modal.inputWrap || !modal.input || !modal.inputLabel) return;

  modal.buttons.replaceChildren();
  let modalTitle = 'Actions seance';
  if (item?.kind === 'race') modalTitle = 'Actions course';
  if (item?.kind === 'personal') modalTitle = 'Evenement perso';
  modal.title.textContent = modalTitle;
  modal.subtitle.textContent = [item?.label, item?.format, item?.pe].filter(Boolean).join(' · ');

  if (item?.kind === 'race') {
    modal.inputWrap.style.display = 'block';
    modal.inputLabel.textContent = 'Resultat (hh:mm:ss)';
    modal.input.placeholder = '00:53:22';
    modal.input.value = String(item?.result || '');
    modal.buttons.appendChild(calendarActionButton('Enregistrer resultat', 'btn', () => {
      void saveRaceResultFromCalendar(item, modal.input.value);
    }));
    modal.buttons.appendChild(calendarActionButton('Modifier la course', 'btn btn-ghost', () => {
      const target = new URL('/courses', globalThis.location.origin);
      if (Number.isFinite(Number(item?.raceId))) {
        target.searchParams.set('editRaceId', String(Number(item.raceId)));
      }
      globalThis.location.href = target.toString();
    }));
  } else if (item?.kind === 'personal') {
    modal.inputWrap.style.display = 'block';
    modal.inputLabel.textContent = 'Titre';
    modal.input.placeholder = 'Ex: Renfo, RDV kine, Repos';
    modal.input.value = String(item?.title || item?.format || '');
    modal.buttons.appendChild(calendarActionButton('Enregistrer', 'btn', () => {
      void savePersonalEventFromCalendar(item, modal.input.value);
    }));
    if (String(item?.personalId || '').trim()) {
      modal.buttons.appendChild(calendarActionButton('Supprimer', 'btn btn-ghost', () => {
        void deletePersonalEventFromCalendar(item);
      }));
    }
  } else {
    modal.inputWrap.style.display = 'none';
    modal.input.value = '';
    if (item?.hasSessionRef) {
      // Bouton pour sélectionner la séance prévue dans le formulaire de log
      modal.buttons.appendChild(calendarActionButton('Sélectionner pour log', 'btn', () => {
        // Redirige vers la page des logs avec les bons paramètres
        const params = new URLSearchParams();
        if (item.detailId) params.set('plannedSessionId', item.detailId);
        if (item.date) params.set('date', item.date);
        const sessionTypeForLog = normalizeSessionType(item?.sessionType ?? item?.session_type ?? item?.type);
        if (sessionTypeForLog) params.set('sessionType', sessionTypeForLog);
        globalThis.location.href = '/log?' + params.toString();
      }));

      const nextDone = !item?.isDone;
      modal.buttons.appendChild(calendarActionButton(nextDone ? 'Valider la seance' : 'Retirer la validation', 'btn', () => {
        void toggleSessionDoneFromCalendar(item, nextDone);
      }));
    }
    modal.buttons.appendChild(calendarActionButton('Modifier la seance', 'btn btn-ghost', () => {
      const target = new URL('/plans', globalThis.location.origin);
      if (Number.isFinite(Number(item?.detailId))) {
        target.searchParams.set('editSessionDetailId', String(Number(item.detailId)));
      }
      if (Number.isFinite(Number(item?.planId))) {
        target.searchParams.set('editPlanId', String(Number(item.planId)));
      }
      if (Number.isFinite(Number(item?.sessionIndex))) {
        target.searchParams.set('editSessionIndex', String(Number(item.sessionIndex)));
      }
      if (item?.date) {
        target.searchParams.set('editSessionDate', String(item.date));
      }
      if (item?.format) {
        target.searchParams.set('editSessionFormat', String(item.format));
      }
      globalThis.location.href = target.toString();
    }));
  }

  modal.buttons.appendChild(calendarActionButton('Annuler', 'btn btn-ghost', () => closeModal('calendar-action-modal')));
  openModal('calendar-action-modal');
}

async function savePersonalEventFromCalendar(item, rawTitle) {
  const title = String(rawTitle || '').trim();
  const date = normalizeDateForStorage(item?.date);
  if (!date) {
    notify('⚠ Date invalide pour cet evenement');
    return;
  }
  if (!title) {
    notify('⚠ Le titre est obligatoire');
    return;
  }

  const personalId = Number.parseInt(item?.personalId, 10);
  try {
    if (Number.isFinite(personalId)) {
      await apiFetch(`/calendar/events/${personalId}`, {
        method: 'PUT',
        body: JSON.stringify({ date, title }),
      });
    } else {
      await apiFetch('/calendar/events', {
        method: 'POST',
        body: JSON.stringify({ date, title }),
      });
    }

    await loadCalendarEvents();
    closeModal('calendar-action-modal');
    notify('✓ Evenement perso enregistre');
    renderDashboard();
  } catch (e) {
    notify(`⚠ ${e?.message || 'Impossible d\'enregistrer l\'evenement'}`);
  }
}

async function deletePersonalEventFromCalendar(item) {
  const personalId = Number.parseInt(item?.personalId, 10);
  if (!Number.isFinite(personalId)) {
    notify('⚠ Evenement introuvable');
    return;
  }

  try {
    await apiFetch(`/calendar/events/${personalId}`, { method: 'DELETE' });
    await loadCalendarEvents();
    closeModal('calendar-action-modal');
    notify('✓ Evenement perso supprime');
    renderDashboard();
  } catch (e) {
    notify(`⚠ ${e?.message || 'Impossible de supprimer l\'evenement'}`);
  }
}

async function toggleSessionDoneFromCalendar(item, nextDone) {
  const detailId = Number.parseInt(item?.detailId, 10);
  if (!Number.isFinite(detailId)) {
    notify('⚠ Seance non modifiable depuis ce calendrier');
    return;
  }

  try {
    const current = await apiFetch(`/plan_details/${detailId}`);
    let planRef = null;
    if (typeof current?.plan === 'string') {
      planRef = current.plan;
    } else {
      const rawPlanId = iriToId(current?.plan?.['@id'] || current?.plan?.id);
      if (Number.isFinite(rawPlanId)) {
        planRef = `/api/plans/${rawPlanId}`;
      }
    }
    if (!planRef) throw new Error('Plan introuvable');

    const payload = {
      plan: planRef,
      position: Number(current?.position || 1),
      sem: current?.sem ?? null,
      sessionDate: normalizeDateForStorage(current?.sessionDate) || null,
      format: current?.format || "45'@Z2",
      sessionType: normalizeSessionType(current?.sessionType ?? current?.session_type ?? current?.type),
      pe: current?.pe || null,
      totalMin: current?.totalMin ?? null,
      isOptional: !!current?.isOptional,
      isDone: !!nextDone,
    };

    await apiFetch(`/plan_details/${detailId}`, {
      method: 'PUT',
      body: JSON.stringify(payload),
    });

    // Si on dévalide, supprimer les logs liés à cette séance
    let deletedLogCount = 0;
    if (!nextDone) {
      const linkedLogs = (Array.isArray(logData) ? logData : []).filter(
        (r) => Number(r.plannedSessionId) === detailId,
      );
      for (const log of linkedLogs) {
        try {
          await apiFetch(`/run_logs/${log.id}`, { method: 'DELETE' });
          deletedLogCount++;
        } catch {
          // Suppression silencieuse en cas d'erreur individuelle
        }
      }
      if (deletedLogCount > 0) {
        logData = logData.filter((r) => Number(r.plannedSessionId) !== detailId);
      }
    }

    closeModal('calendar-action-modal');
    if (nextDone) {
      notify('✓ Seance validee');
    } else if (deletedLogCount > 0) {
      notify(`✓ Validation retiree — ${deletedLogCount} log(s) supprimé(s)`);
    } else {
      notify('✓ Validation retiree');
    }
    requestDashboardRefresh();
  } catch (e) {
    notify('⚠ ' + e.message);
  }
}

async function saveRaceResultFromCalendar(item, rawResult) {
  const raceId = Number.parseInt(item?.raceId, 10);
  if (!Number.isFinite(raceId)) {
    notify('⚠ Course introuvable');
    return;
  }

  const current = racesData.find((race) => race.id === raceId);
  if (!current) {
    notify('⚠ Course introuvable');
    return;
  }

  try {
    const updated = await apiFetch(`/races/${raceId}`, {
      method: 'PUT',
      body: JSON.stringify({
        name: current.name || '',
        date: current.date || '',
        distance: current.distance || null,
        objective: current.objective || null,
        result: String(rawResult || '').trim() || null,
      }),
    });

    const idx = racesData.findIndex((race) => race.id === raceId);
    if (idx >= 0) racesData[idx] = normalizeRace(updated);

    renderRaces();
    closeModal('calendar-action-modal');
    notify('✓ Resultat enregistre');
    requestDashboardRefresh();
  } catch (e) {
    notify('⚠ ' + e.message);
  }
}

// ============================================================
// LOG
// ============================================================
let logFilter='all', logSortAsc=false;
let logEntryMode='manual';
let plannedSessionsForLogs = [];

function plannedSessionLabel(item) {
  const date = item?.sessionDate ? formatDate(item.sessionDate) : 'Sans date';
  const planName = String(item?.planName || item?.plan?.name || '').trim() || 'Plan';
  const pos = Number.parseInt(item?.position, 10);
  const sessionLabel = Number.isFinite(pos) ? `Séance ${pos}` : 'Séance';
  const format = String(item?.format || '').trim();
  const suffix = format ? ` · ${format}` : '';
  return `${date} · ${planName} · ${sessionLabel}${suffix}`;
}

function setLogEntryMode(mode, options = {}) {
  const nextMode = mode === 'calendar' ? 'calendar' : 'manual';
  const shouldClearCalendarSelection = options.clearCalendarSelection !== false;
  const shouldOpenCalendarPicker = options.openCalendarPicker === true;
  logEntryMode = nextMode;

  const manualBtn = document.getElementById('log-entry-mode-manual');
  const calendarBtn = document.getElementById('log-entry-mode-calendar');
  const calendarChoice = document.getElementById('log-calendar-choice');

  if (manualBtn) {
    manualBtn.classList.toggle('active', nextMode === 'manual');
    manualBtn.setAttribute('aria-pressed', nextMode === 'manual' ? 'true' : 'false');
  }
  if (calendarBtn) {
    calendarBtn.classList.toggle('active', nextMode === 'calendar');
    calendarBtn.setAttribute('aria-pressed', nextMode === 'calendar' ? 'true' : 'false');
  }
  if (calendarChoice) {
    calendarChoice.hidden = nextMode !== 'calendar';
  }

  if (nextMode === 'manual' && shouldClearCalendarSelection) {
    setPlannedSessionSelection('log-planned-session-text', 'log-planned-session-id', null);
  }

  if (nextMode === 'calendar') {
    suggestPlannedSessionByDate('log-date', 'log-planned-session-text', 'log-planned-session-id');
    if (shouldOpenCalendarPicker) {
      openPlannedSessionCalendarPicker('log-date', 'log-planned-session-text', 'log-planned-session-id');
    }
  }
}
globalThis.setLogEntryMode = setLogEntryMode;

function fillPlannedSessionDatalist() {
  const datalist = document.getElementById('planned-session-list');
  if (!(datalist instanceof HTMLDataListElement)) return;

  const options = (Array.isArray(plannedSessionsForLogs) ? plannedSessionsForLogs : []).map((item) => {
    const option = document.createElement('option');
    option.value = plannedSessionLabel(item);
    option.dataset.sessionId = String(item.id);
    return option;
  });
  datalist.replaceChildren(...options);
}

function syncPlannedSessionHiddenId(textInputId, hiddenInputId) {
  const textInput = document.getElementById(textInputId);
  const hiddenInput = document.getElementById(hiddenInputId);
  if (!(textInput instanceof HTMLInputElement) || !(hiddenInput instanceof HTMLInputElement)) return;

  const selected = (Array.isArray(plannedSessionsForLogs) ? plannedSessionsForLogs : []).find(
    (item) => plannedSessionLabel(item) === String(textInput.value || '').trim()
  );
  hiddenInput.value = selected ? String(selected.id) : '';
}

function setPlannedSessionSelection(textInputId, hiddenInputId, sessionId) {
  const textInput = document.getElementById(textInputId);
  const hiddenInput = document.getElementById(hiddenInputId);
  if (!(textInput instanceof HTMLInputElement) || !(hiddenInput instanceof HTMLInputElement)) return;

  const wantedId = Number.parseInt(sessionId, 10);
  if (!Number.isFinite(wantedId)) {
    textInput.value = '';
    hiddenInput.value = '';
    return;
  }

  const selected = (Array.isArray(plannedSessionsForLogs) ? plannedSessionsForLogs : []).find(
    (item) => Number(item.id) === wantedId
  );
  if (!selected) {
    textInput.value = '';
    hiddenInput.value = '';
    return;
  }

  textInput.value = plannedSessionLabel(selected);
  hiddenInput.value = String(selected.id);
}

function suggestPlannedSessionByDate(dateInputId, textInputId, hiddenInputId) {
  if (hiddenInputId === 'log-planned-session-id' && logEntryMode !== 'calendar') return;

  const dateInput = document.getElementById(dateInputId);
  const textInput = document.getElementById(textInputId);
  const hiddenInput = document.getElementById(hiddenInputId);
  if (!(dateInput instanceof HTMLInputElement) || !(textInput instanceof HTMLInputElement) || !(hiddenInput instanceof HTMLInputElement)) return;

  const dateKey = normalizeDateForStorage(dateInput.value);
  if (!dateKey || hiddenInput.value) return;

  const candidate = (Array.isArray(plannedSessionsForLogs) ? plannedSessionsForLogs : []).find(
    (item) => normalizeDateForStorage(item.sessionDate) === dateKey
  );
  if (!candidate) return;

  textInput.value = plannedSessionLabel(candidate);
  hiddenInput.value = String(candidate.id);
}

function ensurePlannedSessionBindings() {
  const pairs = [
    ['log-planned-session-text', 'log-planned-session-id', 'log-date'],
    ['lm-planned-session-text', 'lm-planned-session-id', 'lm-date'],
  ];

  pairs.forEach(([textId, hiddenId, dateId]) => {
    const textInput = document.getElementById(textId);
    const hiddenInput = document.getElementById(hiddenId);
    const dateInput = document.getElementById(dateId);
    if (!(textInput instanceof HTMLInputElement) || !(hiddenInput instanceof HTMLInputElement) || !(dateInput instanceof HTMLInputElement)) return;
    if (textInput.dataset.bound === '1') return;

    textInput.addEventListener('input', () => syncPlannedSessionHiddenId(textId, hiddenId));
    textInput.addEventListener('blur', () => syncPlannedSessionHiddenId(textId, hiddenId));
    dateInput.addEventListener('change', () => suggestPlannedSessionByDate(dateId, textId, hiddenId));
    textInput.dataset.bound = '1';
  });
}

async function loadPlannedSessionsForLogs() {
  try {
    const data = await apiFetch('/plan_details?order[sessionDate]=asc&pagination=false');
    const items = members(data);
    plannedSessionsForLogs = items
      .map((item) => {
        const id = iriToId(item?.['@id']) ?? Number.parseInt(item?.id, 10);
        if (!Number.isFinite(id)) return null;
        return {
          id,
          planName: item?.planName,
          position: item?.position,
          sessionDate: normalizeDateForStorage(item?.sessionDate),
          format: item?.format,
          sessionType: normalizeSessionType(item?.sessionType ?? item?.session_type ?? item?.type),
          isDone: !!item?.isDone,
        };
      })
      .filter(Boolean);
  } catch {
    plannedSessionsForLogs = [];
  }

  fillPlannedSessionDatalist();
  ensurePlannedSessionBindings();
}

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
  const plannedEl = row.querySelector('.log-planned');
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
  if (plannedEl) plannedEl.textContent = r.plannedSessionLabel || '—';
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

async function syncPlannedSessionDate(plannedSessionId, logDate) {
  if (!Number.isFinite(plannedSessionId) || !logDate) return;
  const sessions = Array.isArray(plannedSessionsForLogs) ? plannedSessionsForLogs : [];
  const session = sessions.find((s) => Number(s.id) === plannedSessionId);
  const plannedDate = session?.sessionDate ? normalizeDateForStorage(session.sessionDate) : null;
  if (plannedDate && plannedDate !== logDate) {
    try {
      await apiFetch(`/plan_details/${plannedSessionId}`, {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/merge-patch+json' },
        body: JSON.stringify({ sessionDate: logDate }),
      });
      // Met à jour le cache local pour éviter un rechargement complet
      if (session) session.sessionDate = logDate;
    } catch (e) {
      // Echec silencieux — non bloquant
    }
  }
}

async function addLog() {
  const date=document.getElementById('log-date').value;
  const km=Number.parseFloat(document.getElementById('log-km').value);
  const dur=document.getElementById('log-dur').value;
  const dplus=Number.parseInt(document.getElementById('log-dplus').value, 10)||null;
  const bpm=Number.parseInt(document.getElementById('log-bpm').value, 10)||null;
  const runType=document.getElementById('log-type').value||null;
  const notes=document.getElementById('log-notes').value||null;
  const plannedSessionIdRaw = document.getElementById('log-planned-session-id').value;
  const plannedSessionId = Number.parseInt(plannedSessionIdRaw, 10);
  const useCalendarSession = logEntryMode === 'calendar';
  if (useCalendarSession && !Number.isFinite(plannedSessionId)) {
    notify('⚠ Choisis une séance dans le calendrier ou repasse en mode manuel');
    return;
  }
  const plannedSession = useCalendarSession && Number.isFinite(plannedSessionId) ? `/api/plan_details/${plannedSessionId}` : null;
  if(!date||!km||!dur){notify('⚠ Date, km et durée requis');return;}
  try {
    const created=await apiFetch('/run_logs',{method:'POST',body:JSON.stringify({
      date,km,duration:dur,dplus,bpm,runType,notes,plannedSession
    })});
    logData.unshift(normalizeLog(created));
    await syncPlannedSessionDate(plannedSessionId, date);
    renderLog(); requestDashboardRefresh();
    ['log-km','log-dur','log-dplus','log-bpm','log-notes'].forEach(id=>document.getElementById(id).value='');
    document.getElementById('log-type').value='';
    document.getElementById('log-planned-session-text').value='';
    document.getElementById('log-planned-session-id').value='';
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
  setPlannedSessionSelection('lm-planned-session-text', 'lm-planned-session-id', r.plannedSessionId);
  openModal('log-modal');
}

async function saveLogEdit() {
  const id=Number.parseInt(document.getElementById('lm-idx').value, 10);
  const dur=document.getElementById('lm-dur').value;
  const km=Number.parseFloat(document.getElementById('lm-km').value);
  const dplus=Number.parseInt(document.getElementById('lm-dplus').value, 10)||null;
  const plannedSessionIdRaw = document.getElementById('lm-planned-session-id').value;
  const plannedSessionId = Number.parseInt(plannedSessionIdRaw, 10);
  const plannedSession = Number.isFinite(plannedSessionId) ? `/api/plan_details/${plannedSessionId}` : null;
  try {
    const updated=await apiFetch(`/run_logs/${id}`,{method:'PUT',body:JSON.stringify({
      date:document.getElementById('lm-date').value,
      km,duration:dur,dplus,
      bpm:Number.parseInt(document.getElementById('lm-bpm').value, 10)||null,
      runType:document.getElementById('lm-type').value||null,
      notes:document.getElementById('lm-notes').value||null,
      plannedSession,
    })});
    const idx=logData.findIndex(r=>r.id===id);
    if(idx>=0) logData[idx]=normalizeLog(updated);
    await syncPlannedSessionDate(plannedSessionId, document.getElementById('lm-date').value);
    renderLog(); requestDashboardRefresh();
    closeModal('log-modal');
    notify('✓ Sortie modifiée !');
  } catch(e){notify('⚠ '+e.message);}
}

async function deleteLog(id,dateStr) {
  askConfirm('Supprimer la sortie ?',formatDate(dateStr),async()=>{
    try {
      const log = logData.find(r => r.id === id);
      const linkedDetailId = log ? Number(log.plannedSessionId) : NaN;
      await apiFetch(`/run_logs/${id}`,{method:'DELETE'});
      logData=logData.filter(r=>r.id!==id);
      // Dévalider la séance planifiée liée si elle existe
      if (Number.isFinite(linkedDetailId)) {
        try {
          await apiFetch(`/plan_details/${linkedDetailId}`, {
            method: 'PATCH',
            headers: { 'Content-Type': 'application/merge-patch+json' },
            body: JSON.stringify({ isDone: false }),
          });
        } catch {
          // Non bloquant
        }
      }
      renderLog(); requestDashboardRefresh();
      notify('🗑 Sortie supprimée' + (Number.isFinite(linkedDetailId) ? ' — séance dévalidée' : ''));
    } catch(e){notify('⚠ '+e.message);}
  });
}

// ============================================================
// RACES
// ============================================================
function buildRaceRow(r) {
  const statusClass = String(r?.statusClass || 'badge-future');
  const statusText = String(r?.statusLabel || '—');
  const diff = String(r?.resultDelta || '—');
  const row = cloneTemplate('races-row-template') || document.createElement('tr');
  const statusEl = row.querySelector('.races-status');
  const nameEl = row.querySelector('.races-name');
  const dateEl = row.querySelector('.races-date');
  const distEl = row.querySelector('.races-dist');
  const objEl = row.querySelector('.races-obj');
  const realEl = row.querySelector('.races-real');
  const diffEl = row.querySelector('.races-diff');
  const resultBtn = row.querySelector('.races-result');
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
  if (resultBtn) {
    const hasResult = Boolean(String(r.result || '').trim());
    resultBtn.title = hasResult ? 'Modifier résultat' : 'Saisir résultat';
    resultBtn.setAttribute('aria-label', resultBtn.title);
    resultBtn.classList.toggle('has-value', hasResult);
    resultBtn.addEventListener('click', () => openRaceResult(r.id));
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
  openModal('race-modal');
}

async function saveRaceEdit() {
  const id=Number.parseInt(document.getElementById('rm-idx').value, 10);
  const current = racesData.find((r) => r.id === id);
  try {
    const updated=await apiFetch(`/races/${id}`,{method:'PUT',body:JSON.stringify({
      name:document.getElementById('rm-name').value,
      date:document.getElementById('rm-date').value,
      distance:document.getElementById('rm-dist').value||null,
      objective:document.getElementById('rm-obj').value||null,
      result:current?.result||null,
    })});
    const idx=racesData.findIndex(r=>r.id===id);
    if(idx>=0) racesData[idx]=normalizeRace(updated);
    racesData.sort((a,b)=>new Date(a.date)-new Date(b.date));
    renderRaces(); requestDashboardRefresh();
    closeModal('race-modal');
    notify('✓ Course modifiée !');
  } catch(e){notify('⚠ '+e.message);}
}

function openRaceResult(id) {
  const r = racesData.find((x) => x.id === id);
  if (!r) return;
  document.getElementById('rr-idx').value = id;
  document.getElementById('rr-real').value = r.result || '';
  openModal('race-result-modal');
}

async function saveRaceResult() {
  const id = Number.parseInt(document.getElementById('rr-idx').value, 10);
  const current = racesData.find((r) => r.id === id);
  if (!current) {
    notify('⚠ Course introuvable');
    return;
  }

  try {
    const updated = await apiFetch(`/races/${id}`, {
      method: 'PUT',
      body: JSON.stringify({
        name: current.name || '',
        date: current.date || '',
        distance: current.distance || null,
        objective: current.objective || null,
        result: document.getElementById('rr-real').value.trim() || null,
      }),
    });
    const idx = racesData.findIndex((r) => r.id === id);
    if (idx >= 0) racesData[idx] = normalizeRace(updated);
    racesData.sort((a,b)=>new Date(a.date)-new Date(b.date));
    renderRaces(); requestDashboardRefresh();
    closeModal('race-result-modal');
    notify('✓ Résultat enregistré !');
  } catch (e) {
    notify('⚠ ' + e.message);
  }
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
  setupMobileHeaderNav();

    // Pré-remplissage du formulaire de log si paramètres dans l’URL
    const urlParams = new URLSearchParams(globalThis.location.search);
    const plannedSessionId = urlParams.get('plannedSessionId');
    const plannedDate = urlParams.get('date');
    const plannedType = normalizeSessionType(urlParams.get('sessionType'));
    if (plannedSessionId) {
      setLogEntryMode('calendar', { clearCalendarSelection: false });
      setTimeout(() => {
        setPlannedSessionSelection('log-planned-session-text', 'log-planned-session-id', plannedSessionId);
        const logInput = document.getElementById('log-planned-session-text');
        if (logInput) {
          logInput.focus();
          logInput.classList.add('calendar-flash');
          setTimeout(() => logInput.classList.remove('calendar-flash'), 800);
        }
      }, 400);
    }
    if (plannedDate) {
      setTimeout(() => {
        const logDateEl = document.getElementById('log-date');
        if (logDateEl) logDateEl.value = plannedDate;
      }, 400);
    }
    if (plannedType) {
      setTimeout(() => {
        const logTypeEl = document.getElementById('log-type');
        if (logTypeEl instanceof HTMLSelectElement) logTypeEl.value = plannedType;
      }, 400);
    }
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

  syncAdminNavVisibility(me);

  // Phase 1: fast first paint (critical data + widget prefs in parallel)
  await Promise.all([
    hydrateWidgetPreferencesFromApi(),
    loadAllData({ includeDashboardMetrics: false }),
  ]);

  // Render advice instantly when a fresh cache is available.
  hydrateDashboardAdviceFromCache();

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
  safeRender(consumePlanEditIntentFromUrl, 'plan-edit-url');
  safeRender(consumeRaceEditIntentFromUrl, 'race-edit-url');
  safeRender(setupWeatherCityControls, 'weather-city-controls');

  const today = new Date().toISOString().split('T')[0];
  const logDateEl = document.getElementById('log-date');
  if (logDateEl) logDateEl.value = today;
  setLogEntryMode(plannedSessionId ? 'calendar' : 'manual', { clearCalendarSelection: false });
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

  // Phase 2: deferred loads (non-critical for first paint)
  setDashboardLoadingState(true);
  void Promise.allSettled([
    loadDashboardMetrics().then(() => {
      safeRender(renderDashboard, 'dashboard-deferred-metrics');
    }),
    loadDashboardAdvice().then(() => {
      safeRender(() => renderDashboardAdvice(dashboardMetrics || {}), 'dashboard-deferred-advice');
    }),
    loadPlannedSessionsForLogs(),
    applyDefaultWidgetPresetOnce().then(() => {
      safeRender(renderDashboard, 'dashboard-deferred-preset');
    }),
  ]).finally(() => {
    setDashboardLoadingState(false);
    safeRender(renderDashboard, 'dashboard-deferred-final');
  });
}

initApp();
