// ============================================================
// API CLIENT — API Platform returns JSON-LD (hydra:member)
// ============================================================
const API = '/api';
let authToken = globalThis.rtAuth?.getToken?.() || null;

async function apiFetch(path, options = {}) {
  const method = String(options.method || 'GET').toUpperCase();
  const headers = {
    'Content-Type': method === 'PATCH' ? 'application/merge-patch+json' : 'application/json',
  };
  if (options.headers) Object.assign(headers, options.headers);
  if (globalThis.rtAuth?.buildAuthHeaders) {
    Object.assign(headers, globalThis.rtAuth.buildAuthHeaders());
  } else if (authToken) {
    headers['Authorization'] = `Bearer ${authToken}`;
  }

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

function formatDisplayName(value) {
  const text = String(value || '').trim();
  if (!text) return '';

  const normalized = text
    .replace(/[._]+/g, ' ')
    .replace(/\s+/g, ' ')
    .trim();

  return normalized
    .split(' ')
    .map((part) => {
      if (!part) return '';
      return part.charAt(0).toUpperCase() + part.slice(1);
    })
    .join(' ');
}

function applyDynamicTextContrast(textEl, bgEl = textEl, threshold = 0.62, darkClass = 'bar-value--dark') {
  if (!(textEl instanceof HTMLElement) || !(bgEl instanceof HTMLElement)) return;

  const rgb = globalThis.getComputedStyle(bgEl).backgroundColor.match(/\d+/g);
  if (!rgb || rgb.length < 3) {
    textEl.classList.remove(darkClass);
    return;
  }

  const [r, g, b] = rgb.slice(0, 3).map(Number);
  const luminance = (0.299 * r + 0.587 * g + 0.114 * b) / 255;
  textEl.classList.toggle(darkClass, luminance > threshold);
}

function logout() {
  if (globalThis.rtAuth?.clearToken) {
    globalThis.rtAuth.clearToken();
  } else {
    localStorage.removeItem('rt_token');
    sessionStorage.removeItem('rt_token');
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

function setupAnnouncementDismiss() {
  const announcement = document.querySelector('[data-announcement]');
  if (!(announcement instanceof HTMLElement)) {
    return;
  }

  const signature = String(announcement.dataset.announcementSignature || '').trim();
  if (signature !== '' && localStorage.getItem('announcement_dismissed_' + signature) === '1') {
    announcement.remove();
    return;
  }

  const dismissBtn = announcement.querySelector('[data-announcement-dismiss]');
  if (!(dismissBtn instanceof HTMLButtonElement)) {
    return;
  }

  dismissBtn.addEventListener('click', () => {
    if (signature !== '') {
      localStorage.setItem('announcement_dismissed_' + signature, '1');
    }
    announcement.remove();
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
const HOME_PLAN_MODULE_COLLAPSED_KEY = 'rt_home_plan_module_collapsed_v1';
const DASHBOARD_PLAN_TRACKING_OVERRIDE_KEY = 'rt_dashboard_plan_tracking_override_v1';
let dashboardWidgetPrefsHydrated = false;

function readPlanTrackingOverrides() {
  try {
    const raw = localStorage.getItem(DASHBOARD_PLAN_TRACKING_OVERRIDE_KEY);
    const parsed = raw ? JSON.parse(raw) : {};
    return parsed && typeof parsed === 'object' ? parsed : {};
  } catch {
    return {};
  }
}

function writePlanTrackingOverrides(overrides) {
  const safe = overrides && typeof overrides === 'object' ? overrides : {};
  localStorage.setItem(DASHBOARD_PLAN_TRACKING_OVERRIDE_KEY, JSON.stringify(safe));
}

function getPlanTrackingOverride(planId) {
  const key = String(Number(planId));
  if (!/^\d+$/.test(key)) return null;
  const overrides = readPlanTrackingOverrides();
  if (!Object.prototype.hasOwnProperty.call(overrides, key)) {
    return null;
  }
  return !!overrides[key];
}

function setPlanTrackingOverride(planId, tracked) {
  const key = String(Number(planId));
  if (!/^\d+$/.test(key)) return;
  const overrides = readPlanTrackingOverrides();
  overrides[key] = !!tracked;
  writePlanTrackingOverrides(overrides);
}

function prunePlanTrackingOverrides(validPlanIds) {
  const keep = new Set((Array.isArray(validPlanIds) ? validPlanIds : []).map((id) => String(Number(id))));
  const overrides = readPlanTrackingOverrides();
  const next = {};
  Object.entries(overrides).forEach(([key, tracked]) => {
    if (keep.has(String(Number(key)))) {
      next[String(Number(key))] = !!tracked;
    }
  });
  writePlanTrackingOverrides(next);
}

function applyPlanTrackedToLocal(planId, tracked) {
  const normalizedPlanId = Number(planId);
  const normalizedTracked = !!tracked;

  plansData = (Array.isArray(plansData) ? plansData : []).map((plan) => (
    Number(plan.id) === normalizedPlanId ? { ...plan, dashboardTracked: normalizedTracked } : plan
  ));

  state.extraPlans = (Array.isArray(state.extraPlans) ? state.extraPlans : []).map((plan) => (
    Number(plan.id) === normalizedPlanId ? { ...plan, dashboardTracked: normalizedTracked } : plan
  ));
}

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

function parseClockDurationToken(source, startIndex) {
  const match = /^(\d{1,3}):([0-5]?\d)(?::([0-5]?\d))?/.exec(source);
  if (!match) return null;

  const a = Number.parseInt(match[1], 10);
  const b = Number.parseInt(match[2], 10);
  const c = match[3] === undefined ? null : Number.parseInt(match[3], 10);
  const seconds = c === null ? (a * 60) + b : (a * 3600) + (b * 60) + c;
  return { seconds, nextIndex: startIndex + match[0].length };
}

function parseApostropheOrWordDuration(text, value, indexAfterNumber) {
  let i = indexAfterNumber;
  while (i < text.length && /\s/.test(text[i])) i += 1;

  if (text.startsWith("''", i)) return { seconds: value, nextIndex: i + 2 };
  if (text[i] === '"') return { seconds: value, nextIndex: i + 1 };
  if (text[i] === "'") return { seconds: value * 60, nextIndex: i + 1 };

  const secWord = /^(sec|secs|seconde|secondes|s)\b/.exec(text.slice(i));
  if (secWord) return { seconds: value, nextIndex: i + secWord[0].length };

  const minWord = /^(min|mins|minute|minutes|mn)\b/.exec(text.slice(i));
  if (minWord) return { seconds: value * 60, nextIndex: i + minWord[0].length };

  return null;
}

function parseHourDuration(text, value, indexAfterNumber) {
  let i = indexAfterNumber;
  while (i < text.length && /\s/.test(text[i])) i += 1;
  if (text[i] !== 'h') return null;

  i += 1;
  while (i < text.length && /\s/.test(text[i])) i += 1;

  let minutesPart = 0;
  const minuteDigits = /^(\d{1,2})/.exec(text.slice(i));
  if (minuteDigits) {
    minutesPart = Number.parseInt(minuteDigits[1], 10);
    i += minuteDigits[1].length;
    while (i < text.length && /\s/.test(text[i])) i += 1;
    const minuteSuffix = /^(min|mins|minute|minutes|mn)\b/.exec(text.slice(i));
    if (minuteSuffix) i += minuteSuffix[0].length;
  }

  return {
    seconds: (value * 3600) + (minutesPart * 60),
    nextIndex: i,
  };
}

function parseBareMinutesDuration(text, value, indexAfterNumber) {
  let i = indexAfterNumber;
  while (i < text.length && /\s/.test(text[i])) i += 1;

  if (text[i] === '@') {
    return { seconds: value * 60, nextIndex: i };
  }

  const endOrSeparator = i >= text.length || /[+\-/>|),]/.test(text[i]);
  if (!endOrSeparator) return null;
  return { seconds: value * 60, nextIndex: i };
}

function normalizeCalendarEvent(row) {
  const id = Number.parseInt(row?.id, 10);
  return {
    id,
    date: normalizeDateForStorage(row?.date),
    title: String(row?.title || '').trim(),
  };
}

async function loadCalendarEvents() {
  try {
    const data = await apiFetch('/calendar/events');
    const items = members(data?.items || data);
    calendarEventsData = items
      .map(normalizeCalendarEvent)
      .filter((e) => Number.isFinite(e.id) && e.date && e.title);
  } catch {
    calendarEventsData = [];
  }
}

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
  if (compact === 'SL' || compact.includes('SORTIE LONGUE')) return 'SL';
  if (compact === 'FL' || compact.includes('FRACTIONNE LONG')) return 'FL';
  if (compact === 'T' || compact.includes('TEMPO')) return 'T';
  if (compact === 'RACE' || compact.includes('COURSE')) return 'Race';

  const codePrefix = /^(EF|FC|SL|FL|T|RACE)\b/.exec(compact);
  if (codePrefix) return codePrefix[1] === 'RACE' ? 'Race' : codePrefix[1];

  // Keep non-empty unknown values instead of silently dropping them.
  return raw;
}

function sessionTypeDisplayLabel(value) {
  const normalized = normalizeSessionType(value);
  if (normalized === 'Race') return 'Course';
  return String(normalized || value || '').trim();
}

function sessionDateValue(session) {
  return session?.date ?? session?.sessionDate ?? session?.session_date ?? null;
}

function parseDurationCandidate(value) {
  const hms = /^(\d{1,3}):([0-5]\d):([0-5]\d)$/.exec(value);
  if (hms) {
    const hours = Number.parseInt(hms[1], 10);
    const minutes = Number.parseInt(hms[2], 10);
    const seconds = Number.parseInt(hms[3], 10);
    return {
      seconds: (hours * 3600) + (minutes * 60) + seconds,
      precision: 3,
    };
  }

  const ms = /^(\d{1,3}):([0-5]\d)$/.exec(value);
  if (ms) {
    const minutes = Number.parseInt(ms[1], 10);
    const seconds = Number.parseInt(ms[2], 10);
    return {
      seconds: (minutes * 60) + seconds,
      precision: 2,
    };
  }

  const minApostrophe = /^(\d+)['’]$/.exec(value);
  if (minApostrophe) {
    return {
      seconds: Number.parseInt(minApostrophe[1], 10) * 60,
      precision: 1,
    };
  }

  if (/^\d+$/.test(value)) {
    return {
      seconds: Number.parseInt(value, 10) * 60,
      precision: 1,
    };
  }

  return null;
}

function parseDurationToSeconds(raw) {
  if (raw === null || raw === undefined) return null;
  const text = String(raw).trim();
  if (!text) return null;

  const candidates = [text];
  if (text.includes('/')) {
    text.split('/').forEach((part) => {
      const trimmed = String(part || '').trim();
      if (trimmed) candidates.push(trimmed);
    });
  }

  let bestSeconds = null;
  let bestPrecision = -1;
  const uniqueCandidates = [...new Set(candidates)];

  uniqueCandidates.forEach((value) => {
    const parsed = parseDurationCandidate(value);
    if (!parsed || !Number.isFinite(parsed.seconds)) return;
    if (parsed.precision > bestPrecision) {
      bestSeconds = parsed.seconds;
      bestPrecision = parsed.precision;
    }
  });

  return bestSeconds;
}

function parseSessionTotalMinutes(raw) {
  const totalSeconds = parseDurationToSeconds(raw);
  if (!Number.isFinite(totalSeconds)) return null;
  return Math.round(totalSeconds / 60);
}

function normalizeFormatDurationText(raw) {
  return String(raw || '')
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .replaceAll('’', "'")
    .toLowerCase();
}

function findMatchingParenthesis(text, openIndex) {
  if (text[openIndex] !== '(') return -1;
  let depth = 0;
  for (let i = openIndex; i < text.length; i += 1) {
    if (text[i] === '(') depth += 1;
    if (text[i] === ')') {
      depth -= 1;
      if (depth === 0) return i;
    }
  }
  return -1;
}

function parsePlannedDurationToken(text, startIndex) {
  const source = text.slice(startIndex);
  const hms = /^(\d{1,3}):([0-5]?\d)(?::([0-5]?\d))?/.exec(source);
  if (hms) {
    const a = Number.parseInt(hms[1], 10);
    const b = Number.parseInt(hms[2], 10);
    const c = hms[3] === undefined ? null : Number.parseInt(hms[3], 10);
    const seconds = c === null ? (a * 60) + b : (a * 3600) + (b * 60) + c;
    return { seconds, nextIndex: startIndex + hms[0].length };
  }

  const number = /^(\d+)/.exec(source);
  if (!number) return null;

  const value = Number.parseInt(number[1], 10);
  let i = startIndex + number[1].length;
  while (i < text.length && /\s/.test(text[i])) i += 1;

  if (text.startsWith("''", i)) return { seconds: value, nextIndex: i + 2 };
  if (text[i] === '"') return { seconds: value, nextIndex: i + 1 };
  if (text[i] === "'") return { seconds: value * 60, nextIndex: i + 1 };

  const secWord = /^(sec|secs|seconde|secondes|s)\b/.exec(text.slice(i));
  if (secWord) return { seconds: value, nextIndex: i + secWord[0].length };

  const minWord = /^(min|mins|minute|minutes|mn)\b/.exec(text.slice(i));
  if (minWord) return { seconds: value * 60, nextIndex: i + minWord[0].length };

  if (text[i] === 'h') {
    i += 1;
    while (i < text.length && /\s/.test(text[i])) i += 1;

    let minutesPart = 0;
    const minuteDigits = /^(\d{1,2})/.exec(text.slice(i));
    if (minuteDigits) {
      minutesPart = Number.parseInt(minuteDigits[1], 10);
      i += minuteDigits[1].length;
      while (i < text.length && /\s/.test(text[i])) i += 1;
      const minuteSuffix = /^(min|mins|minute|minutes|mn)\b/.exec(text.slice(i));
      if (minuteSuffix) i += minuteSuffix[0].length;
    }

    return {
      seconds: (value * 3600) + (minutesPart * 60),
      nextIndex: i,
    };
  }

  if (text[i] === '@') {
    return { seconds: value * 60, nextIndex: i };
  }

  const endOrSeparator = i >= text.length || /[+\-/>|),]/.test(text[i]);
  if (endOrSeparator) {
    return { seconds: value * 60, nextIndex: i };
  }

  return null;
}

function parsePlannedRepeat(text, startIndex) {
  const repeat = /^(\d+)\s*x\b/.exec(text.slice(startIndex));
  if (!repeat) return null;

  const multiplier = Number.parseInt(repeat[1], 10);
  let i = startIndex + repeat[0].length;
  while (i < text.length && /\s/.test(text[i])) i += 1;

  if (text[i] === '(') {
    const closeIndex = findMatchingParenthesis(text, i);
    if (closeIndex < 0) return null;
    const innerText = text.slice(i + 1, closeIndex);
    const innerSeconds = parsePlannedDurationSecondsFromFormat(innerText);
    if (!Number.isFinite(innerSeconds)) return null;
    return {
      seconds: multiplier * innerSeconds,
      nextIndex: closeIndex + 1,
    };
  }

  const token = parsePlannedDurationToken(text, i);
  if (!token) return null;
  return {
    seconds: multiplier * token.seconds,
    nextIndex: token.nextIndex,
  };
}

function parsePlannedDurationSecondsFromFormat(rawFormat) {
  const text = normalizeFormatDurationText(rawFormat);
  if (!text.trim()) return null;

  let totalSeconds = 0;
  let found = false;
  let i = 0;

  while (i < text.length) {
    if (!/\d/.test(text[i])) {
      i += 1;
      continue;
    }
    if (i > 0 && /[a-z@]/.test(text[i - 1])) {
      i += 1;
      continue;
    }

    const repeated = parsePlannedRepeat(text, i);
    if (repeated) {
      totalSeconds += repeated.seconds;
      found = true;
      i = repeated.nextIndex;
      continue;
    }

    const token = parsePlannedDurationToken(text, i);
    if (token) {
      totalSeconds += token.seconds;
      found = true;
      i = token.nextIndex;
      continue;
    }

    i += 1;
  }

  return found ? totalSeconds : null;
}

function computePlannedTotalMinutesFromFormat(format) {
  const totalSeconds = parsePlannedDurationSecondsFromFormat(format);
  if (!Number.isFinite(totalSeconds) || totalSeconds <= 0) return null;
  return Math.round(totalSeconds / 60);
}

function syncPlanTotalFromFormat() {
  const formatInput = document.getElementById('pm-format');
  const totalInput = document.getElementById('pm-total');
  if (!(formatInput instanceof HTMLInputElement) || !(totalInput instanceof HTMLInputElement)) return;

  const computedMinutes = computePlannedTotalMinutesFromFormat(formatInput.value);
  if (computedMinutes === null) {
    totalInput.readOnly = false;
    totalInput.title = '';
  } else {
    totalInput.value = String(computedMinutes);
    totalInput.readOnly = true;
    totalInput.title = 'Calcule automatiquement depuis le format';
  }

  renderDurationDualHint('pm-total');
}

function setupPlanTotalAutoCompute() {
  const formatInput = document.getElementById('pm-format');
  if (!(formatInput instanceof HTMLInputElement)) return;

  if (formatInput.dataset.autoTotalBound === '1') {
    syncPlanTotalFromFormat();
    return;
  }

  formatInput.addEventListener('input', syncPlanTotalFromFormat);
  formatInput.addEventListener('change', syncPlanTotalFromFormat);
  formatInput.dataset.autoTotalBound = '1';
  syncPlanTotalFromFormat();
}

function formatHmsFromSeconds(totalSeconds) {
  const seconds = Number.parseInt(totalSeconds, 10);
  if (!Number.isFinite(seconds) || seconds < 0) return null;
  const hours = Math.floor(seconds / 3600);
  const mins = Math.floor((seconds % 3600) / 60);
  const secs = seconds % 60;
  return `${hours}:${String(mins).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;
}

function formatMinSecFromSeconds(totalSeconds) {
  const seconds = Number.parseInt(totalSeconds, 10);
  if (!Number.isFinite(seconds) || seconds < 0) return null;
  const mins = Math.floor(seconds / 60);
  const secs = seconds % 60;
  return `${mins}'${String(secs).padStart(2, '0')}''`;
}

function formatHmsFromMinutes(totalMinutes) {
  const minutes = Number.parseInt(totalMinutes, 10);
  if (!Number.isFinite(minutes) || minutes < 0) return null;
  return formatHmsFromSeconds(minutes * 60);
}

function formatDurationDualFromMinutes(totalMinutes) {
  const minutes = Number.parseInt(totalMinutes, 10);
  if (!Number.isFinite(minutes) || minutes < 0) return null;
  const hms = formatHmsFromMinutes(minutes);
  if (!hms) return null;
  return `${minutes}' / ${hms}`;
}

function formatDurationDualFromRaw(raw) {
  const totalSeconds = parseDurationToSeconds(raw);
  if (!Number.isFinite(totalSeconds)) {
    const fallback = String(raw || '').trim();
    return fallback || null;
  }
  const roundedMinutes = Math.round(totalSeconds / 60);
  const hms = formatHmsFromSeconds(totalSeconds);
  if (!hms) return null;
  if (totalSeconds % 60 !== 0) {
    const minSec = formatMinSecFromSeconds(totalSeconds);
    return minSec ? `${minSec} / ${hms}` : hms;
  }
  return `${roundedMinutes}' / ${hms}`;
}

function ensureDurationHintNode(inputId) {
  const input = document.getElementById(inputId);
  if (!(input instanceof HTMLInputElement)) return null;

  const hintId = `${inputId}-dual-hint`;
  let hint = document.getElementById(hintId);
  if (!(hint instanceof HTMLElement)) {
    hint = document.createElement('div');
    hint.id = hintId;
    hint.style.fontSize = '12px';
    hint.style.opacity = '0.8';
    hint.style.marginTop = '4px';
    hint.style.color = 'var(--text-muted)';
    input.after(hint);
  }

  return hint;
}

function renderDurationDualHint(inputId) {
  const input = document.getElementById(inputId);
  const hint = ensureDurationHintNode(inputId);
  if (!(input instanceof HTMLInputElement) || !(hint instanceof HTMLElement)) return;

  const dual = formatDurationDualFromRaw(input.value);
  hint.textContent = dual ? `Apercu: ${dual}` : '';
}

function setupDurationDualHints() {
  ['pm-total', 'log-dur', 'lm-dur'].forEach((inputId) => {
    const input = document.getElementById(inputId);
    if (!(input instanceof HTMLInputElement)) return;

    if (input.dataset.dualHintBound === '1') {
      renderDurationDualHint(inputId);
      return;
    }

    input.addEventListener('input', () => renderDurationDualHint(inputId));
    input.addEventListener('change', () => renderDurationDualHint(inputId));
    input.dataset.dualHintBound = '1';
    renderDurationDualHint(inputId);
  });
}

function sessionTotalMinutesValue(session) {
  const raw = session?.total ?? session?.totalMin ?? session?.total_min ?? null;
  return parseSessionTotalMinutes(raw);
}

function sessionOptionalValue(session) {
  return !!(session?.isOptional ?? session?.is_optional ?? session?.optional ?? session?.opt);
}

function computeSessionWeekNumber(sessions, isoDate, ignoreIndex = null) {
  const rows = Array.isArray(sessions) ? sessions : [];
  const datedRows = rows
    .map((row, idx) => ({ row, idx }))
    .filter(({ idx }) => ignoreIndex === null || idx !== ignoreIndex)
    .map(({ row }) => normalizeDateForStorage(sessionDateValue(row)))
    .filter(Boolean)
    .sort((a, b) => a.localeCompare(b));

  if (isoDate && datedRows.length) {
    const toMonday = (iso) => {
      const d = new Date(`${iso}T00:00:00Z`);
      const day = d.getUTCDay();
      const shift = day === 0 ? -6 : 1 - day;
      d.setUTCDate(d.getUTCDate() + shift);
      d.setUTCHours(0, 0, 0, 0);
      return d;
    };

    const startMonday = toMonday(datedRows[0]);
    const targetMonday = toMonday(isoDate);
    const diffDays = Math.round((targetMonday.getTime() - startMonday.getTime()) / 86400000);
    return Math.max(1, Math.floor(diffDays / 7) + 1);
  }

  const semValues = rows
    .map((row, idx) => ({ sem: Number(row?.sem), idx }))
    .filter(({ sem, idx }) => Number.isFinite(sem) && sem > 0 && (ignoreIndex === null || idx !== ignoreIndex))
    .map(({ sem }) => sem);

  return semValues.length ? Math.max(...semValues) : 1;
}

function normalizePlan(r) {
  return {
    id: iriToId(r['@id']) ?? r.id,
    name: r.name,
    dashboardTracked: r.dashboardTracked !== false,
  };
}

async function setPlanDashboardTracked(planId, tracked) {
  const normalizedPlanId = Number(planId);
  const normalizedTracked = !!tracked;

  setPlanTrackingOverride(normalizedPlanId, normalizedTracked);
  applyPlanTrackedToLocal(normalizedPlanId, normalizedTracked);

  let persistedTracked = null;
  try {
    const payload = await apiFetch(`/plans/${normalizedPlanId}`, {
      method: 'PATCH',
      body: JSON.stringify({ dashboardTracked: normalizedTracked }),
    });
    if (payload && typeof payload.dashboardTracked === 'boolean') {
      persistedTracked = !!payload.dashboardTracked;
    }
  } catch {
    // Fallback for environments where Plan API patch is restricted.
  }

  if (persistedTracked === null) {
    try {
      const payload = await apiFetch(`/plans/${normalizedPlanId}/tracking`, {
        method: 'PATCH',
        body: JSON.stringify({ tracked: normalizedTracked }),
      });
      if (payload && typeof payload.tracked === 'boolean') {
        persistedTracked = !!payload.tracked;
      }
    } catch {
      // Handled below via notification.
    }
  }

  if (persistedTracked === null) {
    notify('⚠ Synchronisation impossible (etat conserve localement)');
  } else {
    setPlanTrackingOverride(normalizedPlanId, persistedTracked);
    applyPlanTrackedToLocal(normalizedPlanId, persistedTracked);
  }

  await loadDashboardMetrics();
  renderDashboard();
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
  const normalizedPlanId = Number(planId);
  const hasPlan = (rows) => (Array.isArray(rows) ? rows : []).some((plan) => Number(plan?.id) === normalizedPlanId);

  try {
    await apiFetch(`/plans/${normalizedPlanId}/delete`, { method: 'DELETE' });
  } catch {
    await apiFetch(`/plans/${normalizedPlanId}`, { method: 'DELETE' });
  }

  const afterDelete = members(await apiFetch('/plans?pagination=false'));
  if (hasPlan(afterDelete)) {
    throw new Error('Suppression non persistée côté API');
  }

  const overrides = readPlanTrackingOverrides();
  delete overrides[String(normalizedPlanId)];
  writePlanTrackingOverrides(overrides);
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
    date: normalizeDateForStorage(sessionDateValue(session)) || null,
    format: session?.format || "45'@Z2",
    sessionType: normalizeSessionType(session?.sessionType ?? session?.session_type ?? session?.type),
    pe: session?.pe || null,
    total: sessionTotalMinutesValue(session),
    isOptional: sessionOptionalValue(session),
    optional: sessionOptionalValue(session),
    opt: sessionOptionalValue(session),
  }));

  await apiFetch(`/plans/${Number(planId)}/sessions`, {
    method: 'PATCH',
    body: JSON.stringify({ sessions: payloadSessions, doneMap: doneMap || {} }),
  });
}

function buildPlanSessionPayload(session) {
  return {
    format: session?.format || "45'@Z2",
    date: normalizeDateForStorage(sessionDateValue(session)) || null,
    sessionType: normalizeSessionType(session?.sessionType ?? session?.session_type ?? session?.type),
    pe: session?.pe || null,
    totalMin: sessionTotalMinutesValue(session),
    isOptional: sessionOptionalValue(session),
    optional: sessionOptionalValue(session),
    opt: sessionOptionalValue(session),
  };
}

async function createPlanSessionInDb(planId, session) {
  await apiFetch(`/plans/${Number(planId)}/sessions`, {
    method: 'POST',
    body: JSON.stringify(buildPlanSessionPayload(session)),
  });
}

async function updatePlanSessionInDb(planId, detailId, session) {
  const normalizedDetailId = Number(detailId);
  if (!Number.isFinite(normalizedDetailId)) {
    throw new TypeError('Seance invalide');
  }

  await apiFetch(`/plans/${Number(planId)}/sessions/${normalizedDetailId}`, {
    method: 'PATCH',
    body: JSON.stringify(buildPlanSessionPayload(session)),
  });
}

async function deletePlanSessionInDb(planId, detailId) {
  const normalizedDetailId = Number(detailId);
  if (!Number.isFinite(normalizedDetailId)) {
    throw new TypeError('Seance invalide');
  }

  await apiFetch(`/plans/${Number(planId)}/sessions/${normalizedDetailId}`, {
    method: 'DELETE',
  });
}

async function setPlanSessionDoneInDb(planId, detailId, done) {
  const normalizedDetailId = Number(detailId);
  if (!Number.isFinite(normalizedDetailId)) {
    throw new TypeError('Seance invalide');
  }

  await apiFetch(`/plans/${Number(planId)}/sessions/${normalizedDetailId}`, {
    method: 'PATCH',
    body: JSON.stringify({ done: !!done }),
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
      date: normalizeDateForStorage(row.sessionDate ?? row.session_date ?? row.date),
      format: row.format,
      sessionType: normalizeSessionType(row.sessionType ?? row.session_type ?? row.type),
      pe: row.pe,
      total: sessionTotalMinutesValue(row),
      isOptional: sessionOptionalValue(row),
      opt: sessionOptionalValue(row),
    };

    if (row.isDone) grouped[planId].done[idx] = true;
  });

  return Object.values(grouped).map(plan => ({
    ...plan,
    dashboardTracked: (plansData || []).find((row) => Number(row.id) === Number(plan.id))?.dashboardTracked !== false,
    sessions: plan.sessions.filter(Boolean),
  }));
}

async function loadPlansFromDb() {
  await loadPlansFromDbWithProgress();
}

function applyPlanProgressChecksToState(checksList) {
  state.doneByKey = {};

  (Array.isArray(checksList) ? checksList : []).forEach((c) => {
    const key = String(c.planKey || '').trim();
    if (!key) return;
    state.doneByKey[key] ??= {};
    state.doneByKey[key][c.sessionIndex] = !!c.done;

    const extra = (state.extraPlans || []).find((p) => (
      String(p.id) === key || `extra:${p.id}` === key
    ));
    if (!extra) return;
    extra.done[c.sessionIndex] = !!c.done;
  });
}

async function loadPlansFromDbWithProgress(checksList = null) {
  const [plansRes, sessionsRes] = await Promise.all([
    apiFetch('/plans?order[name]=asc&pagination=false'),
    apiFetch('/plan_details?order[position]=asc&pagination=false'),
  ]);
  plansData = members(plansRes).map(normalizePlan).map((plan) => {
    const override = getPlanTrackingOverride(plan.id);
    return override === null ? plan : { ...plan, dashboardTracked: override };
  });
  prunePlanTrackingOverrides(plansData.map((plan) => plan.id));
  const existingPlanIds = new Set((plansData || []).map((plan) => Number(plan.id)));
  const mapped = mapDbRowsToPlans(members(sessionsRes), plansData)
    .filter((plan) => existingPlanIds.has(Number(plan.id)));
  const byId = new Set(mapped.map((p) => Number(p.id)));

  plansData.forEach((plan) => {
    const planId = Number(plan.id);
    if (byId.has(planId)) return;
    mapped.push({
      id: plan.id,
      key: plan.name,
      title: isExamplePlanName(plan.name) ? 'Plan de depart (exemple)' : plan.name,
      sub: isExamplePlanName(plan.name) ? 'Plan fourni avec l\'application · blocs hebdomadaires' : '',
      dashboardTracked: plan.dashboardTracked !== false,
      sessions: [],
      done: {},
    });
  });

  state.extraPlans = mapped;

  const resolvedChecks = Array.isArray(checksList)
    ? checksList
    : members(await apiFetch('/plan_progresses?pagination=false'));
  applyPlanProgressChecksToState(resolvedChecks);
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
  fillLogCourseOptions();

  state = { doneByKey: {}, planMeta: {}, extraPlans: [] };

  const calendarEventsPromise = loadCalendarEvents();

  try {
    await loadPlansFromDbWithProgress(checksList);

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
    return { city: 'Paris', status: 'ok', message: 'Ville par défaut: Paris.' };
  }

  const city = String(item?.detectedCity || '').trim();
  const status = String(item?.detectedCityStatus || '').trim() || (city ? 'ok' : 'error');
  const defaultMessage = city ? `Ville par défaut: ${city}` : 'Ville par défaut: Paris.';
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
    return { city: 'Paris', status: 'ok', message: 'Ville par défaut: Paris.' };
  }
}

function setWeatherCitySuggestion(detectedCity, statusMessage = '', statusKind = '') {
  const suggestion = document.getElementById('weather-city-suggestion');
  const useDetectedBtn = document.getElementById('weather-city-use-detected');
  const detectStatusEl = document.getElementById('weather-city-detect-status');
  if (!suggestion || !useDetectedBtn) return;

  const city = String(detectedCity || '').trim();
  const message = String(statusMessage || '').trim();
  const kind = String(statusKind || '').trim();

  if (detectStatusEl) {
    detectStatusEl.textContent = message;
    detectStatusEl.classList.toggle('is-error', kind === 'error');
  }

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
    const recommendation = String(load.recommendation || '').trim();

    const levelByKey = {
      under: 'faible',
      under_watch: 'légèrement faible',
      balanced: 'équilibrée',
      watch: 'modérée',
      high: 'intense',
      initial: 'modérée',
    };
    const level = levelByKey[load.statusKey] || 'modérée';
    const titleByKey = {
      under: 'Charge faible',
      under_watch: 'Charge légèrement basse',
      balanced: 'Charge équilibrée',
      watch: 'Charge modérée',
      high: 'Charge intense',
      initial: 'Charge modérée',
    };
    const title = titleByKey[load.statusKey] || 'Charge modérée';

    const comparisonByKey = {
      under: 'Charge en baisse cette semaine.',
      under_watch: 'Charge légèrement en baisse cette semaine.',
      balanced: 'Semaine bien équilibrée.',
      watch: 'Charge en hausse cette semaine.',
      high: 'Semaine très chargée.',
      initial: 'Charge en cours de stabilisation.',
    };
    let comparisonText = comparisonByKey[load.statusKey] || 'Charge stable cette semaine.';

    if (load.statusKey === 'watch') {
      comparisonText += ' Garde une sortie très facile et privilégie une bonne nuit de sommeil.';
    } else if (load.statusKey === 'high') {
      comparisonText += ' Passe 24-48h en récupération: hydratation, sommeil et sortie très douce.';
    }

    const loadDetails = comparisonText;

    const toneByKey = {
      balanced: 'success',
      watch: 'warning',
      high: 'warning',
      under: 'info',
      under_watch: 'info',
      initial: 'encourage',
    };
    const iconByKey = {
      balanced: '✅',
      watch: '⚠️',
      high: '⛔',
      under: '📉',
      under_watch: '↘️',
      initial: '🧭',
    };

    // Si une carte race/tapering est déjà présente, ne pas afficher la recommendation
    // de charge (ex: "ajoute du volume" est contre-productif avant une course).
    const raceCardPresent = items.some((item) => {
      const badge = String(item?.badge || '').toLowerCase();
      const icon = String(item?.icon || '');
      return ['🏁', '😴', '🧘', '📉'].includes(icon) ||
        badge.includes('tapering') || badge.includes('demain') || badge.includes('course');
    });

    const chargeText = raceCardPresent
      ? comparisonText  // juste le constat, pas de conseil d'ajout de volume
      : (recommendation ? `${recommendation} ${loadDetails}` : loadDetails);

    items.unshift({
      tone: toneByKey[load.statusKey] || 'info',
      icon: iconByKey[load.statusKey] || '⚖️',
      title,
      text: chargeText,
      badge: `Charge ${level}`,
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
    const tempsEl = card.querySelector('.advice-temps');
    const titleEl = card.querySelector('.advice-title');
    const badgeEl = card.querySelector('.advice-badge');
    const textEl = card.querySelector('.advice-text');

    if (iconEl) iconEl.textContent = item?.icon || '💡';
    
    // Render temps if available (for weather items)
    if (tempsEl) {
      const tempMin = item?.tempMin;
      const tempMax = item?.tempMax;
      if (tempMin !== null && tempMin !== undefined && tempMax !== null && tempMax !== undefined) {
        tempsEl.textContent = `${Math.round(tempMin)}° / ${Math.round(tempMax)}°`;
        tempsEl.style.display = '';
      } else {
        tempsEl.style.display = 'none';
      }
    }
    
    if (titleEl) titleEl.textContent = item?.title || 'Conseil du jour';
    if (badgeEl) {
      const badge = String(item?.badge || '').trim();
      if (badge) {
        badgeEl.textContent = badge;
        badgeEl.style.display = '';

        const tone = String(item?.tone || 'info');
        const toneColor = tone === 'success'
          ? 'var(--z1)'
          : tone === 'warning'
            ? 'var(--z3)'
            : tone === 'encourage'
              ? 'var(--accent)'
              : 'var(--z3)';
        badgeEl.style.background = `color-mix(in srgb, ${toneColor} 24%, var(--surface2))`;
        badgeEl.style.borderColor = `color-mix(in srgb, ${toneColor} 45%, var(--border))`;
        applyDynamicTextContrast(badgeEl, badgeEl, 0.58, 'advice-badge--dark');
      } else {
        badgeEl.textContent = '';
        badgeEl.style.display = 'none';
        badgeEl.classList.remove('advice-badge--dark');
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
    const serverFallback = weatherBox.querySelector('[data-server-fallback="1"]');
    if (!serverFallback) {
      weatherBox.replaceChildren();
    }
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
      notify(city ? `✓ Ville météo appliquée: ${city}` : '✓ Ville par défaut: Paris');
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
        notify('⚠ Impossible de charger la météo par défaut.');
        return;
      }

      const feedback = getWeatherCityFeedback();
      notify(feedback?.message ? `✓ ${feedback.message}` : '✓ Ville par défaut: Paris');
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
  currentEl.textContent = city || 'Paris';
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
  const rawNotes = String(r.notes || '').trim() || null;
  const perceivedEffort = normalizePerceivedEffort(r.perceivedEffort ?? r.notes);
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
    courseName: String(r.courseName || '').trim() || null,
    perceivedEffort,
    notes:    rawNotes,
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
    dnfStatus:  r.dnfStatus ?? null,
    dnfComment: r.dnfComment ?? null,
    statusClass: r.statusClass,
    statusLabel: r.statusLabel,
    resultDelta: r.resultDelta,
  };
}

function normalizePerceivedEffort(value) {
  const raw = String(value || '').trim().toLowerCase();
  if (!raw) return null;
  if (raw === 'moderee' || raw === 'modérée') return 'moderee';
  if (raw === 'facile' || raw === 'difficile' || raw === 'maximum') return raw;
  return null;
}

function perceivedEffortLabel(value, fallback = null) {
  if (value === 'moderee') return 'Modérée';
  if (value === 'facile') return 'Facile';
  if (value === 'difficile') return 'Difficile';
  if (value === 'maximum') return 'Maximum';
  if (fallback) return fallback;
  return '—';
}

// ============================================================
// UTILS
// ============================================================
function allureClass(runType) {
  const normalizedType = normalizeSessionType(runType);
  if (normalizedType === 'EF' || normalizedType === 'SL') return 'allure-type-endurance';
  if (normalizedType === 'FC' || normalizedType === 'FL') return 'allure-type-fractionne';
  if (normalizedType === 'T') return 'allure-type-tempo';
  if (normalizedType === 'Race') return 'allure-type-race';
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

function findLatestLoggedDurationForSession(detailId) {
  const normalizedDetailId = Number(detailId);
  if (!Number.isFinite(normalizedDetailId)) return '';

  const matches = (Array.isArray(logData) ? logData : [])
    .filter((log) => Number(log?.plannedSessionId) === normalizedDetailId)
    .filter((log) => String(log?.duration || '').trim() !== '');

  if (!matches.length) return '';

  matches.sort((a, b) => {
    const da = String(a?.date || '');
    const db = String(b?.date || '');
    return db.localeCompare(da);
  });

  return String(matches[0]?.duration || '').trim();
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
  ensureTrainingLoadTooltipHandlers();

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
    const progress = metrics.planProgress || { title: '', focusTitle: '', done: 0, total: 0, pct: 0, plans: [] };
    const plans = (Array.isArray(progress.plans) ? progress.plans : []).map((plan) => {
      const override = getPlanTrackingOverride(plan?.id);
      if (override === null) {
        return plan;
      }
      return { ...plan, tracked: override };
    });
    const trackedPlans = plans.filter((plan) => plan?.tracked !== false);
    const hiddenPlans = plans.filter((plan) => plan?.tracked === false);

    const progressListEl = document.getElementById('plan-progress-list');
    if (progressListEl) {
      const nodes = trackedPlans.map((plan) => {
        const node = cloneTemplate('plan-progress-card-template') || document.createElement('article');
        const titleEl = node.querySelector('.plan-progress-card-title');
        const pctEl = node.querySelector('.plan-progress-card-pct');
        const fillEl = node.querySelector('.plan-progress-card-fill');
        const metaEl = node.querySelector('.plan-progress-card-meta');
        const actionEl = node.querySelector('.plan-progress-card-action');

        if (titleEl) titleEl.textContent = String(plan.title || 'Plan');
        if (pctEl) pctEl.textContent = `${Number(plan.pct || 0)}%`;
        if (fillEl) fillEl.style.width = `${Number(plan.pct || 0)}%`;
        if (metaEl) metaEl.textContent = `${Number(plan.done || 0)} / ${Number(plan.total || 0)} séances complétées`;
        if (actionEl instanceof HTMLButtonElement) {
          actionEl.textContent = 'Retirer';
          actionEl.addEventListener('click', () => {
            actionEl.disabled = true;
            void setPlanDashboardTracked(plan.id, false).finally(() => {
              actionEl.disabled = false;
            });
          });
        }

        return node;
      });

      progressListEl.replaceChildren(...nodes);
      progressListEl.style.display = nodes.length > 0 ? '' : 'none';
    }

    const progressHiddenEl = document.getElementById('plan-progress-hidden');
    if (progressHiddenEl) {
      const hiddenNodes = hiddenPlans.map((plan) => {
        const button = cloneTemplate('plan-progress-hidden-button-template') || document.createElement('button');
        if (button instanceof HTMLButtonElement) {
          button.type = 'button';
          button.classList.add('plan-progress-hidden-btn');
          button.textContent = `+ Suivre ${String(plan.title || 'Plan')}`;
          button.addEventListener('click', () => {
            button.disabled = true;
            void setPlanDashboardTracked(plan.id, true).finally(() => {
              button.disabled = false;
            });
          });
        }

        return button;
      });

      if (hiddenNodes.length > 0) {
        const title = document.createElement('div');
        title.className = 'plan-progress-hidden-title';
        title.textContent = 'Plans non suivis';
        progressHiddenEl.replaceChildren(title, ...hiddenNodes);
        progressHiddenEl.style.display = '';
      } else if (trackedPlans.length === 0) {
        const empty = document.createElement('div');
        empty.className = 'plan-progress-hidden-title';
        empty.textContent = 'Aucun plan suivi';
        progressHiddenEl.replaceChildren(empty);
        progressHiddenEl.style.display = '';
      } else {
        progressHiddenEl.replaceChildren();
        progressHiddenEl.style.display = 'none';
      }
    }
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
      const valueEl = node.querySelector('.bar-value');
      const labelEl = node.querySelector('.bar-label');
      if (barEl) {
        barEl.style.height = `${h}px`;
        barEl.title = `${km.toFixed(1)} km`;
        barEl.style.background = `var(--z${(index % 5) + 1})`;
        barEl.classList.toggle('bar--tiny', h < 28);
        barEl.setAttribute('role', 'img');
        barEl.setAttribute('aria-label', `${String(bar.label || 'Mois')}: ${km.toFixed(1)} kilometres`);
        if (valueEl) {
          valueEl.textContent = `${km.toFixed(0)}`;
          applyDynamicTextContrast(valueEl, barEl, 0.62, 'bar-value--dark');
        }
      }
      if (labelEl) {
        labelEl.textContent = String(bar.label || '—');
      }
      return node;
    });
    monthlyChart.replaceChildren(...barNodes);
  }

  const raceTbody = document.getElementById('race-tbody');
  if (raceTbody && isWidgetEnabled('races_table')) {
    const upcomingRows = (Array.isArray(metrics.racesTable) ? metrics.racesTable : []).filter((r) => {
      const statusClass = String(r?.statusClass || '').toLowerCase();
      const statusLabel = String(r?.statusLabel || '').toLowerCase();
      return statusClass !== 'badge-done' && !statusLabel.includes('termin');
    });

    const rows = upcomingRows.map((r) => {
      const row = cloneTemplate('dashboard-race-row-template') || document.createElement('tr');
      const nameEl = row.querySelector('.dashboard-race-name');
      const dateEl = row.querySelector('.dashboard-race-date');
      const distEl = row.querySelector('.dashboard-race-dist');
      const statusEl = row.querySelector('.dashboard-race-status');
      if (nameEl) nameEl.textContent = r.name || '—';
      if (dateEl) dateEl.textContent = formatDate(r.date);
      if (distEl) distEl.textContent = r.dist || '—';
      if (statusEl) {
        statusEl.classList.add(r.statusClass || 'badge-future');
        statusEl.textContent = r.statusLabel || '—';
        applyDynamicTextContrast(statusEl, statusEl, 0.58, 'badge--dark');
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

function setupHomePlanModuleAccordion() {
  const toggle = document.getElementById('home-plan-module-toggle');
  const content = document.getElementById('home-plan-module-content');
  const root = document.getElementById('home-plan-module');
  if (!(toggle instanceof HTMLButtonElement) || !(content instanceof HTMLElement) || !(root instanceof HTMLElement)) {
    return;
  }

  const applyState = (collapsed) => {
    const isCollapsed = !!collapsed;
    root.classList.toggle('is-collapsed', isCollapsed);
    content.hidden = isCollapsed;
    toggle.setAttribute('aria-expanded', isCollapsed ? 'false' : 'true');
  };

  applyState(localStorage.getItem(HOME_PLAN_MODULE_COLLAPSED_KEY) === '1');

  if (toggle.dataset.bound === '1') {
    return;
  }

  toggle.addEventListener('click', () => {
    const nextCollapsed = !root.classList.contains('is-collapsed');
    localStorage.setItem(HOME_PLAN_MODULE_COLLAPSED_KEY, nextCollapsed ? '1' : '0');
    applyState(nextCollapsed);
  });
  toggle.dataset.bound = '1';
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
  const humanEl = document.getElementById('training-load-human');
  const recoEl = document.getElementById('training-load-reco');

  setTrainingLoadStatusChip(statusEl, load);

  // Phrase lisible par tous — traduit le delta % en langage courant
  if (humanEl) {
    const delta = Number(load.deltaPct || 0);
    const absDelta = Math.abs(delta);
    let humanText = '';
    if (load.ratio === null) {
      humanText = 'Pas encore assez de données pour établir ta base de référence.';
    } else if (absDelta <= 10) {
      humanText = 'Tu es dans ta moyenne habituelle cette semaine. ✅';
    } else if (delta < -10 && delta >= -20) {
      humanText = `Tu cours un peu moins que d'habitude cette semaine (−${absDelta}%).`;
    } else if (delta < -20) {
      humanText = `Tu cours nettement moins que d'habitude cette semaine (−${absDelta}%).`;
    } else if (delta > 10 && delta <= 20) {
      humanText = `Tu cours un peu plus que d'habitude cette semaine (+${absDelta}%).`;
    } else {
      humanText = `Tu cours nettement plus que d'habitude cette semaine (+${absDelta}%).`;
    }
    humanEl.textContent = humanText;
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
  const rawTrend = Array.isArray(ef.efBpmTrend) ? ef.efBpmTrend : [];

  // Monthly aggregation:
  // group EF runs by YYYY-MM, then compute average BPM per month.
  const monthNames = ['Jan', 'Fev', 'Mar', 'Avr', 'Mai', 'Jun', 'Jul', 'Aou', 'Sep', 'Oct', 'Nov', 'Dec'];
  const monthlyBuckets = new Map();
  rawTrend.forEach((d) => {
    const bpm = Number(d?.bpm);
    if (!Number.isFinite(bpm)) return;
    const dateText = String(d?.date || '');
    const match = /^(\d{4})-(\d{2})/.exec(dateText);
    if (!match) return;

    const year = match[1];
    const month = Number.parseInt(match[2], 10);
    const key = `${year}-${match[2]}`;
    if (!monthlyBuckets.has(key)) {
      monthlyBuckets.set(key, {
        label: `${monthNames[month - 1] || match[2]} ${year.slice(-2)}`,
        values: [],
      });
    }
    monthlyBuckets.get(key).values.push(bpm);
  });

  // One chart point per month: rounded monthly mean BPM.
  const monthlyTrend = Array.from(monthlyBuckets.values()).map((bucket) => {
    const sum = bucket.values.reduce((acc, v) => acc + v, 0);
    const avg = Math.round(sum / Math.max(1, bucket.values.length));
    return { label: bucket.label, bpm: avg, avg3: null };
  });

  // 3-month moving average on monthly points.
  const trend = monthlyTrend.map((point, idx, arr) => {
    if (idx < 2) return point;
    const avg3 = Math.round((arr[idx - 2].bpm + arr[idx - 1].bpm + arr[idx].bpm) / 3);
    return { ...point, avg3 };
  });

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

  // X labels: monthly labels, reduce density only on very narrow screens.
  // Anchor: start for first, end for last, middle for others — avoids overflow.
  const labelStep = W < 420 && n > 5 ? 2 : 1;
  const drawnIndices = new Set();
  for (let i = 0; i < n; i += labelStep) {
    drawnIndices.add(i);
    const x = xSc(i);
    const anchor = i === 0 ? 'start' : (i === n - 1 ? 'end' : 'middle');
    svg.appendChild(createSvgEl('text', {
      x: x.toFixed(1), y: H - 4,
      'text-anchor': anchor, fill: 'var(--text-muted)', 'font-size': 8, 'font-family': 'monospace',
    }, String(trend[i].label || '—')));
  }
  // Always draw the last label if not already drawn
  if (!drawnIndices.has(n - 1)) {
    const x = xSc(n - 1);
    svg.appendChild(createSvgEl('text', {
      x: x.toFixed(1), y: H - 4,
      'text-anchor': 'end', fill: 'var(--text-muted)', 'font-size': 8, 'font-family': 'monospace',
    }, String(trend[n - 1].label || '—')));
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

  // BPM line — var(--z5) = couleur VO2max, visuellement distincte
  const bpmPts = trend.map((d, i) => `${xSc(i).toFixed(1)},${ySc(d.bpm).toFixed(1)}`).join(' ');
  svg.appendChild(createSvgEl('polyline', {
    points: bpmPts,
    fill: 'none', stroke: 'var(--z5)', 'stroke-width': 2, 'stroke-opacity': 0.85,
  }));

  // Dots + explicit BPM labels above each point for readability/accessibility.
  trend.forEach((d, i) => {
    const cx = xSc(i).toFixed(1);
    const cy = ySc(d.bpm).toFixed(1);
    svg.appendChild(createSvgEl('circle', {
      cx,
      cy,
      r: 3.5, fill: 'var(--z5)', stroke: 'var(--surface)', 'stroke-width': 1.5,
    }));
    svg.appendChild(createSvgEl('text', {
      x: cx,
      y: (Number(cy) - 8).toFixed(1),
      'text-anchor': 'middle',
      fill: 'var(--text)',
      'font-size': 9,
      'font-weight': 700,
      'font-family': 'monospace',
    }, String(d.bpm)));
  });

  // Legend: separate HTML legend above chart
  const legendContainer = document.createElement('div');
  legendContainer.className = 'ef-bpm-legend';

  const item1 = document.createElement('span');
  item1.className = 'ef-bpm-legend-item';
  const dot1 = document.createElement('span');
  dot1.className = 'ef-bpm-legend-dot';
  dot1.style.background = 'var(--z5)';
  item1.appendChild(dot1);
  item1.appendChild(document.createTextNode('BPM (mensuel)'));

  const item2 = document.createElement('span');
  item2.className = 'ef-bpm-legend-item';
  const line2 = document.createElement('span');
  line2.className = 'ef-bpm-legend-line';
  item2.appendChild(line2);
  item2.appendChild(document.createTextNode('Moyenne 3 mois'));

  legendContainer.appendChild(item1);
  legendContainer.appendChild(item2);

  container.replaceChildren(legendContainer, svg);
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
    const detailToggle = node.querySelector('.alert-detail-toggle');
    const detailBox = node.querySelector('.alert-details');
    if (t) t.textContent = `${a.ok ? '✓' : '⚠'} ${a.title}`;
    if (m) m.textContent = String(a.msg || 'Aucun detail disponible.');
    const details = Array.isArray(a.details) ? a.details.filter((item) => String(item || '').trim() !== '') : [];
    if (detailToggle instanceof HTMLButtonElement && detailBox instanceof HTMLElement && details.length > 0) {
      detailToggle.hidden = false;
      detailBox.hidden = true;
      detailToggle.textContent = 'Voir le detail';
      detailBox.replaceChildren(...details.map((item) => {
        const line = document.createElement('div');
        line.className = 'alert-detail-line';
        line.textContent = String(item);
        return line;
      }));
      detailToggle.addEventListener('click', () => {
        const isOpen = !detailBox.hidden;
        detailBox.hidden = isOpen;
        detailToggle.textContent = isOpen ? 'Voir le detail' : 'Masquer le detail';
      });
    }
    return node;
  });
  section.replaceChildren(title, ...nodes);
}

function renderProjections() {
  const metrics = dashboardMetrics || {};
  const projections = Array.isArray(metrics.projections) ? metrics.projections : [];
  const history = metrics.projectionsHistory && typeof metrics.projectionsHistory === 'object'
    ? metrics.projectionsHistory
    : { hasData: false, labels: [], series: [], meta: '', emptyMessage: '' };
  const gridEl = document.getElementById('projections-grid');
  if (!gridEl) return;
  if(!projections.length){
    const emptyNode = cloneTemplate('projection-empty-template') || document.createElement('div');
    gridEl.replaceChildren(emptyNode);
    renderProjectionsHistoryChart(history);
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

  // — Narrative (progression sur la période) —
  const narrativeEl = document.getElementById('projections-narrative');
  const narrative = metrics.projectionsNarrative;
  if (narrativeEl) {
    if (narrative && narrative.text) {
      const icon = narrative.improving ? '📈' : '📉';
      narrativeEl.textContent = `${icon} ${narrative.text}`;
      narrativeEl.removeAttribute('hidden');
    } else {
      narrativeEl.setAttribute('hidden', '');
    }
  }

  // — Projection course (prochaine course vs temps projeté) —
  const raceProjectionEl = document.getElementById('race-projection');
  const raceProj = metrics.raceProjection;
  if (raceProjectionEl) {
    if (raceProj && raceProj.projected) {
      const statusIcon = { ahead: '✅', on_track: '✅', behind: '⚠️' }[raceProj.status] || '';
      const objLine = raceProj.objective
        ? `<span class="race-proj-obj">Objectif : <strong>${raceProj.objective}</strong></span>`
        : '';
      const daysLine = raceProj.daysTo != null
        ? `<span class="race-proj-days">dans ${raceProj.daysTo} jour${raceProj.daysTo > 1 ? 's' : ''}</span>`
        : '';
      raceProjectionEl.innerHTML =
        `<span class="race-proj-icon">🏁</span>` +
        `<span class="race-proj-name">${raceProj.raceName}</span> ${daysLine}` +
        ` · ${objLine}` +
        ` <span class="race-proj-projected">Projeté : <strong>${raceProj.projected}</strong></span>` +
        ` <span class="race-proj-status">${statusIcon} ${raceProj.statusText}</span>`;
      raceProjectionEl.dataset.status = raceProj.status;
      raceProjectionEl.removeAttribute('hidden');
    } else {
      raceProjectionEl.setAttribute('hidden', '');
    }
  }

  renderProjectionsHistoryChart(history);
}

let metaInfoTooltipHandlersBound = false;
let trainingLoadTooltipHandlersBound = false;

function applyTooltipViewportClamp(button, maxWidth, widthVarName, shiftVarName) {
  if (!(button instanceof HTMLElement)) return;

  const rect = button.getBoundingClientRect();
  const viewportWidth = window.innerWidth || document.documentElement.clientWidth || 360;
  const tooltipWidth = Math.min(maxWidth, Math.max(180, viewportWidth - 24));
  const margin = 8;
  const centerX = rect.left + (rect.width / 2);
  const projectedLeft = centerX - (tooltipWidth / 2);
  const projectedRight = centerX + (tooltipWidth / 2);

  let shiftX = 0;
  if (projectedLeft < margin) {
    shiftX = margin - projectedLeft;
  } else if (projectedRight > (viewportWidth - margin)) {
    shiftX = (viewportWidth - margin) - projectedRight;
  }

  button.style.setProperty(widthVarName, `${tooltipWidth}px`);
  button.style.setProperty(shiftVarName, `${shiftX}px`);
}

function alignMetaInfoTooltip(button) {
  applyTooltipViewportClamp(button, 360, '--meta-tooltip-width', '--meta-tooltip-shift-x');
}

function alignTrainingLoadTooltip(button) {
  applyTooltipViewportClamp(button, 360, '--training-tooltip-width', '--training-tooltip-shift-x');
}

function ensureTrainingLoadTooltipHandlers() {
  if (trainingLoadTooltipHandlersBound) return;
  const button = document.querySelector('.training-load-info');
  if (!(button instanceof HTMLElement)) return;

  trainingLoadTooltipHandlersBound = true;
  const align = () => alignTrainingLoadTooltip(button);

  button.addEventListener('mouseenter', align);
  button.addEventListener('focus', align);
  button.addEventListener('click', (event) => {
    event.preventDefault();
    event.stopPropagation();
    align();
    button.classList.toggle('is-open');
  });

  document.addEventListener('click', (event) => {
    const target = event.target instanceof Element ? event.target : null;
    if (target?.closest('.training-load-info')) return;
    button.classList.remove('is-open');
  });

  document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    button.classList.remove('is-open');
  });

  align();
}

function alignAllMetaInfoTooltips() {
  document.querySelectorAll('.meta-info-btn').forEach((btn) => {
    alignMetaInfoTooltip(btn);
  });
}

function closeOpenMetaInfoTooltips(exceptBtn = null) {
  document.querySelectorAll('.meta-info-btn.is-open').forEach((btn) => {
    if (exceptBtn && btn === exceptBtn) return;
    btn.classList.remove('is-open');
  });
}

function ensureMetaInfoTooltipHandlers() {
  if (metaInfoTooltipHandlersBound) return;
  metaInfoTooltipHandlersBound = true;

  document.addEventListener('click', (event) => {
    const target = event.target instanceof Element ? event.target : null;
    if (target?.closest('.meta-info-btn')) return;
    closeOpenMetaInfoTooltips();
  });

  document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    closeOpenMetaInfoTooltips();
  });

  window.addEventListener('resize', () => {
    alignAllMetaInfoTooltips();
    if (trainingLoadTooltipHandlersBound) {
      const btn = document.querySelector('.training-load-info');
      if (btn instanceof HTMLElement) alignTrainingLoadTooltip(btn);
    }
  }, { passive: true });
}

function renderProjectionMetaInfo(container, shortLabel, tooltipText) {
  if (!(container instanceof HTMLElement)) return;
  const cleanTooltip = String(tooltipText || '').trim();
  if (cleanTooltip === '') {
    container.replaceChildren();
    return;
  }

  const wrap = document.createElement('span');
  wrap.className = 'meta-info-inline';

  const text = document.createElement('span');
  text.className = 'meta-info-label';
  text.textContent = shortLabel;

  const infoBtn = document.createElement('button');
  infoBtn.type = 'button';
  infoBtn.className = 'meta-info-btn';
  infoBtn.setAttribute('aria-label', `${shortLabel}: informations de calcul`);
  infoBtn.dataset.tooltip = cleanTooltip;
  infoBtn.innerHTML = '<svg class="meta-info-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="10" fill="none" stroke="currentColor" stroke-width="1.8"></circle><line x1="12" y1="10" x2="12" y2="16" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"></line><circle cx="12" cy="7" r="1.2" fill="currentColor"></circle></svg>';
  infoBtn.addEventListener('click', (event) => {
    event.preventDefault();
    event.stopPropagation();
    alignMetaInfoTooltip(infoBtn);
    const shouldOpen = !infoBtn.classList.contains('is-open');
    closeOpenMetaInfoTooltips(infoBtn);
    infoBtn.classList.toggle('is-open', shouldOpen);
  });
  infoBtn.addEventListener('mouseenter', () => alignMetaInfoTooltip(infoBtn));
  infoBtn.addEventListener('focus', () => alignMetaInfoTooltip(infoBtn));

  ensureMetaInfoTooltipHandlers();
  ensureTrainingLoadTooltipHandlers();
  alignMetaInfoTooltip(infoBtn);

  wrap.append(text, infoBtn);
  container.replaceChildren(wrap);
}

function collectPositiveSecondsFromProjectionSeries(seriesList) {
  return (Array.isArray(seriesList) ? seriesList : [])
    .flatMap((line) => (Array.isArray(line?.values) ? line.values : []))
    .reduce((acc, raw) => {
      const sec = Number(raw);
      if (Number.isFinite(sec) && sec > 0) {
        acc.push(sec);
      }
      return acc;
    }, []);
}

function renderProjectionHistoryControls(controlsEl, options, selectedOption, history) {
  if (!controlsEl) return;
  const buttons = options.map((option) => {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = `projections-history-btn${option === selectedOption ? ' is-active' : ''}`;
    btn.textContent = option === 'all' ? 'Toutes distances' : option;
    btn.addEventListener('click', () => {
      controlsEl.dataset.selectedProjectionDistance = option;
      renderProjectionsHistoryChart(history);
    });
    return btn;
  });
  controlsEl.replaceChildren(...buttons);
}

function renderProjectionHistoryLegend(legendEl, visibleSeries) {
  if (!(legendEl instanceof HTMLElement)) return;
  const chips = visibleSeries.map((line) => {
    const chip = document.createElement('span');
    chip.className = 'projections-history-legend-item';

    const dot = document.createElement('span');
    dot.className = 'projections-history-legend-dot';
    dot.style.background = typeof line?.color === 'string' && line.color.trim() !== '' ? line.color.trim() : 'var(--accent2)';

    const text = document.createElement('span');
    text.className = 'projections-history-legend-label';
    text.textContent = String(line?.label || 'Projection');

    chip.append(dot, text);
    return chip;
  });
  legendEl.replaceChildren(...chips);
}

function renderProjectionHistoryYAxis(container, minY, yRange, padTop, cH) {
  if (!(container instanceof HTMLElement)) return;
  const yAxis = document.createElement('div');
  yAxis.className = 'projections-history-yaxis';

  [0, 0.25, 0.5, 0.75, 1].forEach((t) => {
    const secVal = Math.round(minY + t * yRange);
    const y = padTop + ((1 - t) * cH);
    const row = document.createElement('span');
    row.className = 'projections-history-yaxis-label';
    row.style.top = `${y}px`;
    row.textContent = formatHmsFromSeconds(secVal) || String(secVal);
    yAxis.appendChild(row);
  });

  container.appendChild(yAxis);
}

function formatProjectionBarLabel(secondsRaw) {
  const totalSeconds = Math.max(0, Math.round(Number(secondsRaw) || 0));
  const hours = Math.floor(totalSeconds / 3600);
  const minutes = Math.floor((totalSeconds % 3600) / 60);
  const seconds = totalSeconds % 60;

  if (hours > 0) {
    return formatHmsFromSeconds(totalSeconds) || String(totalSeconds);
  }
  if (seconds === 0) {
    return `${minutes}'`;
  }
  return `${minutes}:${String(seconds).padStart(2, '0')}`;
}

function renderProjectionHistoryBars(svg, visibleSeries, monthCount, geometry, minY, yRange, cH) {
  const {
    padTop,
    padLeft,
    groupWidth,
    groupInnerPad,
    barGap,
    barWidth,
  } = geometry;

  const ySc = (seconds) => padTop + (1 - ((seconds - minY) / yRange)) * cH;

  visibleSeries.forEach((line, seriesIndex) => {
    const color = typeof line?.color === 'string' && line.color.trim() !== '' ? line.color.trim() : 'var(--accent2)';
    const values = Array.isArray(line?.values) ? line.values : [];
    values.forEach((v, i) => {
      const sec = Number(v);
      if (!Number.isFinite(sec) || sec <= 0 || i >= monthCount) return;

      const h = Math.max(4, cH - (ySc(sec) - padTop));
      const x = padLeft + (i * groupWidth) + groupInnerPad + (seriesIndex * (barWidth + barGap));
      const y = padTop + cH - h;
      const rounded = Math.min(4, h / 2);

      svg.appendChild(createSvgEl('rect', {
        x: x.toFixed(1),
        y: y.toFixed(1),
        width: barWidth.toFixed(1),
        height: h.toFixed(1),
        rx: rounded.toFixed(1),
        ry: rounded.toFixed(1),
        fill: color,
      }));

      const valueLabel = formatProjectionBarLabel(sec);
      const textY = Math.max(padTop + 10, y - 4);
      svg.appendChild(createSvgEl('text', {
        x: (x + (barWidth / 2)).toFixed(1),
        y: textY.toFixed(1),
        'text-anchor': 'middle',
        fill: 'var(--text)',
        'font-size': 10,
        'font-weight': 700,
        'font-family': 'monospace',
      }, valueLabel));
    });
  });
}

function renderProjectionsHistoryChart(history) {
  const container = document.getElementById('projections-history-chart-container');
  const controlsEl = document.getElementById('projections-history-controls');
  const legendEl = document.getElementById('projections-history-legend');
  if (!container) return;

  const labels = Array.isArray(history?.labels) ? history.labels : [];
  const series = Array.isArray(history?.series) ? history.series : [];

  const numericValues = collectPositiveSecondsFromProjectionSeries(series);

  const hasData = Boolean(history?.hasData) && labels.length > 0 && series.length > 0 && numericValues.length > 0;
  if (!hasData) {
    if (controlsEl) controlsEl.replaceChildren();
    if (legendEl) legendEl.replaceChildren();
    container.replaceChildren();
    if (metaEl) {
      const emptyText = String(history?.emptyMessage || '').trim();
      metaEl.textContent = emptyText;
    }
    return;
  }

  const seriesOptions = ['all', ...series.map((line) => String(line?.label || '').trim()).filter(Boolean)];
  const controlsState = controlsEl?.dataset.selectedProjectionDistance || 'all';
  const selectedDistance = seriesOptions.includes(controlsState) ? controlsState : 'all';
  const visibleSeries = selectedDistance === 'all'
    ? series
    : series.filter((line) => String(line?.label || '').trim() === selectedDistance);

  renderProjectionHistoryControls(controlsEl, seriesOptions, selectedDistance, history);
  renderProjectionHistoryLegend(legendEl, visibleSeries);

  const H = 232;
  const PAD = { top: 18, right: 14, bottom: 44, left: 54 };
  const minWidthPerMonth = selectedDistance === 'all' ? 136 : 104;
  const minChartWidth = (labels.length * minWidthPerMonth) + PAD.left + PAD.right;
  const W = Math.max(container.clientWidth || 760, minChartWidth);
  const cW = W - PAD.left - PAD.right;
  const cH = H - PAD.top - PAD.bottom;

  const visibleValues = collectPositiveSecondsFromProjectionSeries(visibleSeries);
  if (!visibleValues.length) {
    if (legendEl) legendEl.replaceChildren();
    container.replaceChildren();
    if (metaEl) {
      const emptyText = String(history?.emptyMessage || '').trim();
      metaEl.textContent = emptyText;
    }
    return;
  }

  const minRaw = Math.min(...visibleValues);
  const maxRaw = Math.max(...visibleValues);
  const yPad = Math.max(30, Math.round((maxRaw - minRaw) * 0.08));
  const minY = Math.max(0, minRaw - yPad);
  const maxY = maxRaw + yPad;
  const yRange = Math.max(1, maxY - minY);

  const monthCount = labels.length;
  const seriesCount = Math.max(1, visibleSeries.length);
  const groupWidth = cW / Math.max(1, monthCount);
  const groupInnerPad = 10;
  const barGap = 6;
  const usableGroupWidth = Math.max(16, groupWidth - (groupInnerPad * 2));
  const barWidth = Math.max(8, (usableGroupWidth - (barGap * (seriesCount - 1))) / seriesCount);

  const svg = createSvgEl('svg', { width: W, height: H, xmlns: 'http://www.w3.org/2000/svg' });

  [0, 0.25, 0.5, 0.75, 1].forEach((t) => {
    const y = PAD.top + (1 - t) * cH;
    svg.appendChild(createSvgEl('line', {
      x1: PAD.left,
      y1: y.toFixed(1),
      x2: (W - PAD.right).toFixed(1),
      y2: y.toFixed(1),
      stroke: 'var(--border)',
      'stroke-width': 0.7,
      'stroke-dasharray': '2,3',
    }));

  });

  labels.forEach((label, i) => {
    const x = PAD.left + (i + 0.5) * groupWidth;
    svg.appendChild(createSvgEl('text', {
      x: x.toFixed(1),
      y: (H - 24).toFixed(1),
      'text-anchor': 'middle',
      fill: 'var(--text-muted)',
      'font-size': 10,
      'font-family': 'monospace',
    }, String(label || '—')));
  });

  renderProjectionHistoryBars(svg, visibleSeries, monthCount, {
    padTop: PAD.top,
    padLeft: PAD.left,
    groupWidth,
    groupInnerPad,
    barGap,
    barWidth,
  }, minY, yRange, cH);

  container.replaceChildren(svg);
  renderProjectionHistoryYAxis(container, minY, yRange, PAD.top, cH);

  if (container.dataset.scrollTracked !== '1') {
    container.dataset.scrollTracked = '1';
    container.addEventListener('scroll', () => {
      container.dataset.userScrolled = '1';
    }, { passive: true });
  }

  if (container.dataset.userScrolled !== '1') {
    const defaultMonthIndex = Math.max(0, labels.length - 2);
    const monthCenterX = PAD.left + ((defaultMonthIndex + 0.5) * groupWidth);
    const targetScrollLeft = Math.max(0, monthCenterX - (container.clientWidth / 2));
    container.scrollLeft = targetScrollLeft;
  }

  // Meta info supprimée — titre explicite dans le HTML remplace la bulle d'info.
}

// ============================================================
// PLAN RENDERER
// ============================================================
let currentPlanId = null;
let plansHistoryListenerBound = false;

function parsePlanIdFromPathname(pathname) {
  let normalizedPath = String(pathname || '');
  while (normalizedPath.length > 1 && normalizedPath.endsWith('/')) {
    normalizedPath = normalizedPath.slice(0, -1);
  }
  if (normalizedPath === '') {
    normalizedPath = '/';
  }
  const match = /^\/plans\/(\d+)$/.exec(normalizedPath);
  if (!match) return null;
  const id = Number.parseInt(match[1], 10);
  return Number.isFinite(id) ? id : null;
}

function getInitialPlanIdFromUrlOrDom() {
  const fromPath = parsePlanIdFromPathname(globalThis.location?.pathname || '');
  if (fromPath !== null) return fromPath;

  const root = document.getElementById('plans');
  if (!root) return null;
  const raw = String(root.dataset.initialPlanId || '').trim();
  if (!raw) return null;
  const id = Number.parseInt(raw, 10);
  return Number.isFinite(id) ? id : null;
}

function updatePlansPath(planId, { pushHistory = false } = {}) {
  const currentPath = String(globalThis.location?.pathname || '');
  if (!currentPath.startsWith('/plans')) return;

  const targetPath = Number.isFinite(Number(planId)) ? `/plans/${Number(planId)}` : '/plans';
  if (currentPath === targetPath) return;

  const cleanUrl = new URL(globalThis.location.href);
  cleanUrl.pathname = targetPath;
  const next = `${cleanUrl.pathname}${cleanUrl.search}${cleanUrl.hash}`;
  if (pushHistory) {
    globalThis.history.pushState({}, '', next);
  } else {
    globalThis.history.replaceState({}, '', next);
  }
}

function syncPlansViewFromUrl() {
  const plansRoot = document.getElementById('plans');
  if (!plansRoot) return;

  const planId = parsePlanIdFromPathname(globalThis.location?.pathname || '');
  if (planId !== null) {
    const opened = openPlan(planId, { pushHistory: false });
    if (!opened) {
      backToPlansList({ pushHistory: false });
    }
    return;
  }

  if (currentPlanId !== null) {
    backToPlansList({ pushHistory: false });
  }
}

function bindPlansHistoryListener() {
  if (plansHistoryListenerBound) return;
  plansHistoryListenerBound = true;
  globalThis.addEventListener('popstate', syncPlansViewFromUrl);
}

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

function openPlan(planId, options = {}) {
  const pushHistory = options.pushHistory !== false;
  currentPlanId = planId;
  const extra = getExtraPlan(planId);
  if (!extra) return false;

  const plansList = document.getElementById('plans-list');
  if (plansList) plansList.style.display = 'none';
  const plansListHeader = document.getElementById('plans-list-header');
  if (plansListHeader) plansListHeader.style.display = 'none';
  const plansCreateForm = document.getElementById('plans-create-form');
  if (plansCreateForm) plansCreateForm.style.display = 'none';
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
  updatePlansPath(planId, { pushHistory });
  return true;
}

function backToPlansList(options = {}) {
  const pushHistory = options.pushHistory !== false;
  currentPlanId = null;
  const plansList = document.getElementById('plans-list');
  if (plansList) plansList.style.display = 'flex';
  const plansListHeader = document.getElementById('plans-list-header');
  if (plansListHeader) plansListHeader.style.display = '';
  const plansCreateForm = document.getElementById('plans-create-form');
  if (plansCreateForm) plansCreateForm.style.display = '';
  const plansCreateBtn = document.getElementById('plans-create-btn');
  if (plansCreateBtn) plansCreateBtn.style.display = '';
  const plansDetail = document.getElementById('plans-detail');
  if (plansDetail) plansDetail.style.display = 'none';
  const plansDetailWeeks = document.getElementById('plans-detail-weeks');
  if (plansDetailWeeks) plansDetailWeeks.replaceChildren();
  const crumbCurrent = document.getElementById('plans-crumb-current');
  if (crumbCurrent) crumbCurrent.textContent = '';
  renderPlansList();
  updatePlansPath(null, { pushHistory });
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

  const stateKey = `extra:${planId}`;
  document.getElementById('pm-statekey').value = stateKey;
  document.getElementById('pm-idx').value = '-1';
  document.getElementById('pm-format').value = '';
  document.getElementById('pm-type').value = '';
  document.getElementById('pm-date').value = '';
  document.getElementById('pm-pe').value = '';
  document.getElementById('pm-total').value = '';
  document.getElementById('pm-total').readOnly = false;
  document.getElementById('pm-total').title = '';
  renderDurationDualHint('pm-total');
  document.getElementById('pm-opt').checked = false;
  syncPlanTotalFromFormat();

  const titleEl = document.getElementById('plan-modal-title');
  if (titleEl) titleEl.textContent = 'Nouvelle séance';
  const saveBtn = document.getElementById('plan-modal-save-btn');
  if (saveBtn) saveBtn.textContent = 'Créer';

  openModal('plan-modal');
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

  const dateSortValue = (value) => {
    const iso = normalizeDateForStorage(value);
    return iso ? Date.parse(`${iso}T00:00:00Z`) : Number.POSITIVE_INFINITY;
  };

  const sortSessionsByDate = (sessions) => sessions.slice().sort((a, b) => {
    const dateDiff = dateSortValue(a?.date) - dateSortValue(b?.date);
    if (dateDiff !== 0) return dateDiff;
    return (a?.__idx ?? 0) - (b?.__idx ?? 0);
  });

  const firstSessionIdx = (block) => {
    const idx = sortSessionsByDate(block?.sessions || [])[0]?.__idx;
    return Number.isFinite(Number(idx)) ? Number(idx) : Number.POSITIVE_INFINITY;
  };

  const weekNodes = [];
  const blocks = [];
  const datedGroups = new Map();

  data.forEach((session, idx) => {
    const semValue = Number(session?.sem);
    if (!Number.isFinite(semValue)) return;
    if (!datedGroups.has(semValue)) {
      datedGroups.set(semValue, []);
    }
    datedGroups.get(semValue).push({
      ...session,
      __idx: idx,
    });
  });

  Array.from(datedGroups.entries())
    .sort((a, b) => a[0] - b[0])
    .forEach(([sem, sessions]) => {
      blocks.push({ sem, sessions });
    });

  const undated = data
    .map((session, idx) => ({ session, idx }))
    .filter(({ session }) => !Number.isFinite(Number(session?.sem)));

  for (let i = 0; i < undated.length; i += 4) {
    const chunk = undated.slice(i, i + 4).map(({ session, idx }) => ({
      ...session,
      __idx: idx,
    }));
    blocks.push({ sem: null, sessions: chunk });
  }

  const sortedBlocks = blocks.slice().sort((a, b) => {
    const aHasSem = Number.isFinite(Number(a.sem));
    const bHasSem = Number.isFinite(Number(b.sem));

    if (aHasSem && bHasSem) {
      const semDiff = Number(a.sem) - Number(b.sem);
      if (semDiff !== 0) return semDiff;
    } else if (aHasSem !== bHasSem) {
      // Always keep numbered training weeks before legacy/undated blocks.
      return aHasSem ? -1 : 1;
    }

    const aDate = sortSessionsByDate(a.sessions).map((s) => normalizeDateForStorage(sessionDateValue(s))).find(Boolean);
    const bDate = sortSessionsByDate(b.sessions).map((s) => normalizeDateForStorage(sessionDateValue(s))).find(Boolean);
    const aValue = aDate ? Date.parse(`${aDate}T00:00:00Z`) : Number.POSITIVE_INFINITY;
    const bValue = bDate ? Date.parse(`${bDate}T00:00:00Z`) : Number.POSITIVE_INFINITY;
    if (aValue !== bValue) return aValue - bValue;
    return firstSessionIdx(a) - firstSessionIdx(b);
  });

  sortedBlocks.forEach((block, blockIndex) => {
    const sortedSessions = sortSessionsByDate(block.sessions);
    const wd = sortedSessions.map((s) => normalizeDateForStorage(sessionDateValue(s))).find(Boolean);
    const week = cloneTemplate('plan-week-card-template') || document.createElement('div');
    const weekNumEl = week.querySelector('.week-num');
    const weekDateEl = week.querySelector('.week-date');
    const weekSessionsEl = week.querySelector('.week-sessions');
    if (weekNumEl) weekNumEl.textContent = `SEMAINE ${block.sem ?? (blockIndex + 1)}`;
    if (weekDateEl) weekDateEl.textContent = wd ? formatDate(wd) : '—';
    const sessionNodes = [];
    sortedSessions.forEach((s) => {
      const idx = s.__idx;
      const isOptional = sessionOptionalValue(s);
      const realDuration = findLatestLoggedDurationForSession(s?.detailId);
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
      if (isOptional && formatEl) {
        const optionalEl = document.createElement('span');
        optionalEl.className = 'session-format-optional';
        optionalEl.textContent = ' (optionnel)';
        formatEl.appendChild(optionalEl);
      }
      if (dateEl) {
        const sessionDate = normalizeDateForStorage(sessionDateValue(s));
        dateEl.hidden = false;
        dateEl.classList.toggle('session-meta-slot--empty', !sessionDate);
        dateEl.setAttribute('aria-hidden', sessionDate ? 'false' : 'true');
        dateEl.textContent = sessionDate ? formatDate(sessionDate) : '';
      }
      if (typeEl) {
        const sessionType = normalizeSessionType(s.sessionType ?? s.session_type ?? s.type) || '';
        typeEl.hidden = false;
        typeEl.classList.toggle('session-meta-slot--empty', !sessionType);
        typeEl.setAttribute('aria-hidden', sessionType ? 'false' : 'true');
        typeEl.textContent = sessionType;
        if (sessionType) {
          applyDynamicTextContrast(typeEl, typeEl, 0.58, 'session-type-badge--dark');
        } else {
          typeEl.classList.remove('session-type-badge--dark');
        }
      }
      if (peEl) {
        peEl.hidden = false;
        peEl.classList.toggle('session-meta-slot--empty', !s.pe);
        peEl.setAttribute('aria-hidden', s.pe ? 'false' : 'true');
        peEl.textContent = s.pe ? `PE ${s.pe}` : '';
      }
      if (durEl) {
        const totalMinutes = sessionTotalMinutesValue(s);
        durEl.hidden = false;
        durEl.classList.toggle('session-meta-slot--empty', !totalMinutes);
        durEl.setAttribute('aria-hidden', totalMinutes ? 'false' : 'true');
        durEl.textContent = totalMinutes ? (formatDurationDualFromMinutes(totalMinutes) || `${totalMinutes}'`) : '';
      }
      if (optEl) {
        optEl.hidden = false;
        optEl.classList.toggle('session-meta-slot--empty', !realDuration);
        optEl.classList.toggle('optional-tag--real', !!realDuration);
        optEl.setAttribute('aria-hidden', realDuration ? 'false' : 'true');
        optEl.textContent = realDuration ? `${formatDurationDualFromRaw(realDuration) || realDuration}` : '';
      }
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
    const previousDone = !!ep.done[idx];
    const nextDone = !previousDone;
    const detailId = ep.sessions?.[idx]?.detailId;
    const hasPersistedDetailId = Number.isFinite(Number(detailId));
    ep.done[idx] = nextDone;
    
    // Update UI immediately
    const c = row.querySelector('.session-check');
    c.classList.toggle('done', nextDone);
    c.textContent = nextDone ? '✓' : '';
    renderPlansList();
    notify(nextDone ? '✓ Séance validée !' : 'Séance décochée');
    
    // Save in background (non-blocking)
    const persistDone = hasPersistedDetailId
      ? setPlanSessionDoneInDb(ep.id, detailId, nextDone)
      : replacePlanSessionsInDb(ep.id, ep.sessions, ep.done);

    persistDone
      .then(async () => {
        if (!nextDone && hasPersistedDetailId) {
          await deleteLogsLinkedToSession(Number(detailId));
        }

        await loadPlansFromDb();

        if (String(currentPlanId) === String(ep.id)) {
          openPlan(ep.id, { pushHistory: false });
        } else {
          renderPlansList();
        }

        requestDashboardRefresh();
      })
      .catch(async (e) => {
        ep.done[idx] = previousDone;
        c.classList.toggle('done', previousDone);
        c.textContent = previousDone ? '✓' : '';
        await loadPlansFromDb();
        if (String(currentPlanId) === String(ep.id)) {
          openPlan(ep.id, { pushHistory: false });
        } else {
          renderPlansList();
        }
        notify('⚠ Erreur de sauvegarde: ' + e.message);
      });
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
  document.getElementById('pm-date').value = normalizeDateForStorage(sessionDateValue(s));
  document.getElementById('pm-pe').value = s.pe || '';
  document.getElementById('pm-total').value = sessionTotalMinutesValue(s) ?? '';
  document.getElementById('pm-total').readOnly = false;
  document.getElementById('pm-total').title = '';
  renderDurationDualHint('pm-total');
  document.getElementById('pm-opt').checked = sessionOptionalValue(s);
  syncPlanTotalFromFormat();

  const titleEl = document.getElementById('plan-modal-title');
  if (titleEl) titleEl.textContent = 'Modifier la séance';
  const saveBtn = document.getElementById('plan-modal-save-btn');
  if (saveBtn) saveBtn.textContent = 'Enregistrer';

  openModal('plan-modal');
}

function readPlanEditPayload() {
  const format = String(document.getElementById('pm-format').value || '').trim();
  if (!format) {
    notify('⚠ Le format est obligatoire');
    return null;
  }

  const dateInput = document.getElementById('pm-date').value;
  const isoDate = normalizeDateForStorage(dateInput);
  if (dateInput && !isoDate) {
    notify('⚠ Date invalide (format attendu: yyyy-mm-dd)');
    return null;
  }

  const computedFromFormat = computePlannedTotalMinutesFromFormat(format);
  const totalRaw = document.getElementById('pm-total').value;
  let totalMinutes = computedFromFormat;

  if (totalMinutes === null) {
    totalMinutes = parseSessionTotalMinutes(totalRaw);
    if (String(totalRaw || '').trim() !== '' && totalMinutes === null) {
      notify('⚠ Temps total invalide (attendu: minutes ou hh:mm:ss)');
      return null;
    }
  }

  return {
    format,
    sessionType: normalizeSessionType(document.getElementById('pm-type').value),
    date: isoDate || null,
    pe: String(document.getElementById('pm-pe').value || '').trim() || null,
    totalMin: totalMinutes,
    isOptional: document.getElementById('pm-opt').checked,
    optional: document.getElementById('pm-opt').checked,
    opt: document.getElementById('pm-opt').checked,
  };
}

async function saveExtraPlanEdit(planId, idx, isCreate, sessions, nextSession) {
  if (isCreate) {
    await createPlanSessionInDb(planId, nextSession);
    return;
  }

  if (Number.isFinite(Number(sessions[idx]?.detailId))) {
    await updatePlanSessionInDb(planId, sessions[idx].detailId, nextSession);
    return;
  }

  if (!sessions[idx]) {
    throw new TypeError('Seance introuvable');
  }

  sessions[idx] = {
    ...sessions[idx],
    ...nextSession,
    sem: computeSessionWeekNumber(sessions, nextSession.date, idx),
  };

  await replacePlanSessionsInDb(planId, sessions, getExtraPlan(planId)?.done || {});
}

function applyLocalPlanEdit(sessions, idx, isCreate, nextSession) {
  if (isCreate) {
    nextSession.sem = computeSessionWeekNumber(sessions, nextSession.date);
    sessions.push(nextSession);
    return true;
  }

  if (!sessions[idx]) {
    return false;
  }

  nextSession.sem = computeSessionWeekNumber(sessions, nextSession.date, idx);
  if (Number.isFinite(Number(sessions[idx]?.detailId))) {
    nextSession.detailId = sessions[idx].detailId;
  }
  sessions[idx] = { ...sessions[idx], ...nextSession };
  return true;
}

async function refreshPlanViewAfterSave(planId, stateKey, sessions) {
  await loadPlansFromDb();
  const reloadedPlan = getExtraPlan(planId);
  if (reloadedPlan) {
    renderPlan('plans-detail-weeks', reloadedPlan.sessions, stateKey);
  } else {
    renderPlan('plans-detail-weeks', sessions, stateKey);
  }
}

async function savePlanEdit() {
  const sk = document.getElementById('pm-statekey').value;
  const idx = Number.parseInt(document.getElementById('pm-idx').value, 10);
  const isCreate = !Number.isFinite(idx) || idx < 0;
  const isExtra = sk.startsWith('extra:');
  const planId = isExtra ? sk.slice(6) : null;
  const d = isExtra ? getExtraPlan(planId)?.sessions : [];
  if (!Array.isArray(d)) return;

  const nextSession = readPlanEditPayload();
  if (!nextSession) return;

  if (isExtra) {
    try {
      await saveExtraPlanEdit(planId, idx, isCreate, d, nextSession);
      await refreshPlanViewAfterSave(planId, sk, d);
    } catch (e) {
      notify(`⚠ ${e.message}`);
      return;
    }

    renderPlansList();
    requestDashboardRefresh();
    closeModal('plan-modal');
    notify(isCreate ? '✓ Séance créée' : '✓ Séance modifiée');
    return;
  }

  if (!applyLocalPlanEdit(d, idx, isCreate, nextSession)) {
    return;
  }

  renderPlan('plans-detail-weeks', d, sk);

  renderPlansList();
  requestDashboardRefresh();
  closeModal('plan-modal');
  notify(isCreate ? '✓ Séance créée' : '✓ Séance modifiée');
}

function deletePlanSession(sk, idx) {
  const isExtra = sk.startsWith('extra:');
  const planId = isExtra ? sk.slice(6) : null;
  const d = isExtra ? getExtraPlan(planId)?.sessions : [];
  if (!d?.[idx]) return;

  askConfirm('Supprimer la séance ?', `"${d[idx].format}"`, async () => {
    if (isExtra) {
      try {
        const detailId = d[idx]?.detailId;
        if (Number.isFinite(Number(detailId))) {
          await deletePlanSessionInDb(planId, detailId);
        } else {
          d.splice(idx, 1);
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

        await loadPlansFromDb();
        const reloadedPlan = getExtraPlan(planId);
        if (reloadedPlan) {
          renderPlan('plans-detail-weeks', reloadedPlan.sessions, sk);
        }
        renderPlansList();
        requestDashboardRefresh();
        notify('✓ Séance supprimée');
      } catch (e) {
        notify(`⚠ ${e.message}`);
      }
      return;
    }

    d.splice(idx, 1);
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
    const planId = await resolvePlanIdForCalendarItem(item, detailId);
    await setPlanSessionDoneInDb(planId, detailId, nextDone);

    // Si on dévalide, supprimer les logs liés à cette séance
    const deletedLogCount = nextDone ? 0 : await deleteLogsLinkedToSession(detailId);

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

async function resolvePlanIdForCalendarItem(item, detailId) {
  const planIdFromItem = Number.parseInt(item?.planId, 10);
  if (Number.isFinite(planIdFromItem)) return planIdFromItem;

  const current = await apiFetch(`/plan_details/${detailId}`);
  if (typeof current?.plan === 'string') {
    const planIdFromIri = iriToId(current.plan);
    if (Number.isFinite(planIdFromIri)) return planIdFromIri;
  }

  const nestedPlanId = iriToId(current?.plan?.['@id'] || current?.plan?.id);
  if (Number.isFinite(nestedPlanId)) return nestedPlanId;

  throw new Error('Plan introuvable');
}

async function deleteLogsLinkedToSession(detailId) {
  const linkedLogs = (Array.isArray(logData) ? logData : []).filter(
    (r) => Number(r.plannedSessionId) === detailId,
  );

  let deletedLogCount = 0;
  for (const log of linkedLogs) {
    try {
      await apiFetch(`/run_logs/${log.id}`, { method: 'DELETE' });
      deletedLogCount++;
    } catch (e) {
      console.warn('run_log delete failed', { id: log.id, error: e?.message || String(e) });
    }
  }

  if (deletedLogCount > 0) {
    logData = logData.filter((r) => Number(r.plannedSessionId) !== detailId);
  }

  return deletedLogCount;
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

function plannedSessionTypeTargetId(textInputId, hiddenInputId) {
  if (textInputId === 'log-planned-session-text' || hiddenInputId === 'log-planned-session-id') return 'log-type';
  if (textInputId === 'lm-planned-session-text' || hiddenInputId === 'lm-planned-session-id') return 'lm-type';
  return null;
}

function applyPlannedSessionTypeSelection(textInputId, hiddenInputId, session) {
  const targetId = plannedSessionTypeTargetId(textInputId, hiddenInputId);
  if (!targetId) return;

  const typeEl = document.getElementById(targetId);
  if (!(typeEl instanceof HTMLSelectElement)) return;

  const nextType = normalizeSessionType(session?.sessionType ?? session?.session_type ?? session?.type) || '';
  typeEl.value = nextType;
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
  applyPlannedSessionTypeSelection(textInputId, hiddenInputId, selected || null);
}

function setPlannedSessionSelection(textInputId, hiddenInputId, sessionId) {
  const textInput = document.getElementById(textInputId);
  const hiddenInput = document.getElementById(hiddenInputId);
  if (!(textInput instanceof HTMLInputElement) || !(hiddenInput instanceof HTMLInputElement)) return;

  const wantedId = Number.parseInt(sessionId, 10);
  if (!Number.isFinite(wantedId)) {
    textInput.value = '';
    hiddenInput.value = '';
    applyPlannedSessionTypeSelection(textInputId, hiddenInputId, null);
    return;
  }

  const selected = (Array.isArray(plannedSessionsForLogs) ? plannedSessionsForLogs : []).find(
    (item) => Number(item.id) === wantedId
  );
  if (!selected) {
    textInput.value = '';
    hiddenInput.value = '';
    applyPlannedSessionTypeSelection(textInputId, hiddenInputId, null);
    return;
  }

  textInput.value = plannedSessionLabel(selected);
  hiddenInput.value = String(selected.id);
  applyPlannedSessionTypeSelection(textInputId, hiddenInputId, selected);
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
  applyPlannedSessionTypeSelection(textInputId, hiddenInputId, candidate);
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

function fillLogCourseOptions() {
  const list = document.getElementById('log-course-options');
  if (!(list instanceof HTMLDataListElement)) return;

  const today = new Date().toISOString().slice(0, 10);
  const names = [];
  const seen = new Set();

  (Array.isArray(racesData) ? racesData : []).forEach((race) => {
    const hasResult = Boolean(String(race?.result || '').trim());
    const raceDate = normalizeDateForStorage(race?.date);
    const name = String(race?.name || '').trim();
    if (!name || hasResult) return;
    if (raceDate && raceDate < today) return;
    if (seen.has(name.toLowerCase())) return;
    seen.add(name.toLowerCase());
    names.push(name);
  });

  names.sort((a, b) => a.localeCompare(b, 'fr'));
  list.replaceChildren(
    ...names.map((name) => {
      const option = document.createElement('option');
      option.value = name;
      return option;
    })
  );
}

function bindLogFormSubmit() {
  const form = document.getElementById('log-create-form');
  if (!(form instanceof HTMLFormElement)) return;
  if (form.dataset.bound === '1') return;

  form.addEventListener('submit', (event) => {
    event.preventDefault();
    void addLog();
  });

  form.dataset.bound = '1';
}

function updateCourseFieldVisibility(typeSelectId, fieldWrapperId, inputId) {
  const typeEl = document.getElementById(typeSelectId);
  const fieldEl = document.getElementById(fieldWrapperId);
  const inputEl = document.getElementById(inputId);
  if (!(typeEl instanceof HTMLSelectElement) || !(fieldEl instanceof HTMLElement) || !(inputEl instanceof HTMLInputElement)) return;

  const isRace = normalizeSessionType(typeEl.value) === 'Race';
  fieldEl.hidden = !isRace;
  inputEl.disabled = !isRace;
  if (!isRace) inputEl.value = '';
}

function bindCourseFieldVisibility() {
  const bindings = [
    ['log-type', 'log-course-field', 'log-course-name'],
    ['lm-type', 'lm-course-field', 'lm-course-name'],
  ];

  bindings.forEach(([typeId, fieldId, inputId]) => {
    const typeEl = document.getElementById(typeId);
    if (!(typeEl instanceof HTMLSelectElement)) return;
    if (typeEl.dataset.courseVisibilityBound !== '1') {
      typeEl.addEventListener('change', () => updateCourseFieldVisibility(typeId, fieldId, inputId));
      typeEl.dataset.courseVisibilityBound = '1';
    }
    updateCourseFieldVisibility(typeId, fieldId, inputId);
  });
}

async function loadPlannedSessionsForLogs() {
  try {
    const data = await apiFetch('/plan_details?order[sessionDate]=asc&pagination=false');
    const items = members(data);
    const normalized = items
      .map((item) => {
        const id = iriToId(item?.['@id']) ?? Number.parseInt(item?.id, 10);
        if (!Number.isFinite(id)) return null;
        const planId = iriToId(item?.plan)
          ?? iriToId(item?.plan?.['@id'])
          ?? Number.parseInt(item?.plan?.id, 10)
          ?? null;
        return {
          id,
          planId,
          planName: item?.planName,
          position: item?.position,
          sem: Number.parseInt(item?.sem, 10),
          sessionDate: normalizeDateForStorage(item?.sessionDate),
          format: item?.format,
          sessionType: normalizeSessionType(item?.sessionType ?? item?.session_type ?? item?.type),
          isDone: !!item?.isDone,
        };
      })
      .filter(Boolean);

    const byPlan = new Map();
    normalized.forEach((item) => {
      const key = Number.isFinite(Number(item?.planId)) ? Number(item.planId) : 'na';
      if (!byPlan.has(key)) byPlan.set(key, []);
      byPlan.get(key).push(item);
    });

    byPlan.forEach((sessions) => {
      const weeks = new Map();
      const ordered = sessions.slice().sort((a, b) => {
        const aWeek = Number.parseInt(a?.sem, 10);
        const bWeek = Number.parseInt(b?.sem, 10);
        if (Number.isFinite(aWeek) && Number.isFinite(bWeek) && aWeek !== bWeek) return aWeek - bWeek;
        if (Number.isFinite(aWeek) && !Number.isFinite(bWeek)) return -1;
        if (!Number.isFinite(aWeek) && Number.isFinite(bWeek)) return 1;

        const aDate = normalizeDateForStorage(a?.sessionDate);
        const bDate = normalizeDateForStorage(b?.sessionDate);
        if (aDate && bDate && aDate !== bDate) return aDate.localeCompare(bDate);

        return Number(a?.position || 0) - Number(b?.position || 0);
      });

      ordered.forEach((item) => {
        let weekNumber = Number.parseInt(item?.sem, 10);
        if (!Number.isFinite(weekNumber) || weekNumber <= 0) {
          const dateKey = normalizeDateForStorage(item?.sessionDate);
          weekNumber = dateKey ? Number(dateKey.slice(8, 10)) : 0;
        }
        if (!Number.isFinite(weekNumber) || weekNumber <= 0) {
          weekNumber = 0;
        }

        const weekKey = String(weekNumber);
        if (!weeks.has(weekKey)) weeks.set(weekKey, []);
        weeks.get(weekKey).push(item);
      });

      weeks.forEach((weekSessions, weekKey) => {
        weekSessions
          .sort((a, b) => Number(a?.position || 0) - Number(b?.position || 0))
          .forEach((item, idx) => {
            item.weekNumber = Number.parseInt(weekKey, 10) || null;
            item.episodeInWeek = idx + 1;
          });
      });
    });

    plannedSessionsForLogs = normalized;
  } catch {
    plannedSessionsForLogs = [];
  }

  fillPlannedSessionDatalist();
  ensurePlannedSessionBindings();
  if (document.getElementById('log-tbody')) {
    renderLog();
  }
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
  const typeColorClass = allureClass(runType);
  if (typeColorClass) {
    badge.classList.add(typeColorClass);
  }
  badge.textContent = sessionTypeDisplayLabel(runType);
  cell.replaceChildren(badge);
}

function resolvePlannedSessionForLog(log) {
  const linkedSessionId = Number.parseInt(log?.plannedSessionId, 10);
  if (Number.isFinite(linkedSessionId)) {
    const byId = (Array.isArray(plannedSessionsForLogs) ? plannedSessionsForLogs : []).find(
      (item) => Number(item?.id) === linkedSessionId,
    );
    if (byId) return byId;
  }

  const legacyLabel = String(log?.plannedSessionLabel || '').trim();
  if (!legacyLabel) return null;

  const loweredLabel = legacyLabel.toLowerCase();
  if (!loweredLabel.startsWith('seance')) return null;

  let rest = legacyLabel.slice(6).trimStart();
  let cursor = 0;
  while (cursor < rest.length && rest[cursor] >= '0' && rest[cursor] <= '9') {
    cursor += 1;
  }
  if (cursor === 0) return null;

  const position = Number.parseInt(rest.slice(0, cursor), 10);
  rest = rest.slice(cursor).trimStart();
  if (!(rest.startsWith('-') || rest.startsWith('·'))) return null;
  const format = rest.slice(1).trimStart().trim();
  if (!Number.isFinite(position)) return null;

  return (Array.isArray(plannedSessionsForLogs) ? plannedSessionsForLogs : []).find((item) => {
    const samePosition = Number.parseInt(item?.position, 10) === position;
    if (!samePosition) return false;
    if (!format) return true;
    return String(item?.format || '').trim() === format;
  }) || null;
}

function logOutingLabel(log) {
  const isRace = normalizeSessionType(log?.run_type) === 'Race';
  const courseName = String(log?.courseName || '').trim();
  const plannedLabel = String(log?.plannedSessionLabel || '').trim();

  if (isRace && courseName) return { label: courseName, tooltip: '' };

  const linked = resolvePlannedSessionForLog(log);
  if (linked) {
    const week = Number.parseInt(linked?.weekNumber ?? linked?.sem, 10);
    const episode = Number.parseInt(linked?.episodeInWeek, 10);
    const format = String(linked?.format || '').trim();
    const label = Number.isFinite(week) && Number.isFinite(episode)
      ? `Semaine ${week} - Episode ${episode}`
      : (plannedLabel || 'Séance liée');
    return {
      label,
      tooltip: format ? `Format prévu: ${format}` : '',
    };
  }

  if (plannedLabel) return { label: plannedLabel, tooltip: '' };
  if (courseName) return { label: courseName, tooltip: '' };
  return { label: '—', tooltip: '' };
}

function buildLogRow(r) {
  const ac = allureClass(r.run_type);
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
  const effortEl = row.querySelector('.log-effort');
  const editBtn = row.querySelector('.log-edit');
  const delBtn = row.querySelector('.log-delete');

  if (dateEl) dateEl.textContent = formatDate(r.date);
  if (kmEl) kmEl.textContent = r.km?.toFixed(2) || '—';
  if (durEl) durEl.textContent = formatDurationDualFromRaw(r.duration) || '—';
  if (allureEl) {
    allureEl.classList.add(ac);
    allureEl.textContent = `${r.allure || '—'}/km`;
  }
  setLogMetricCell(gapEl, r.gap, 'metric-gap', '/km');
  setLogMetricCell(dplusEl, r.dplus, 'metric-dplus', 'm');
  if (bpmEl) bpmEl.textContent = r.bpm || '—';
  setLogTypeCell(typeEl, r.run_type);
  if (plannedEl) {
    const outing = logOutingLabel(r);
    plannedEl.textContent = outing.label;
    const tooltip = String(outing.tooltip || '').trim();
    if (tooltip) {
      plannedEl.dataset.plannedFormat = tooltip;
      plannedEl.setAttribute('title', tooltip);
    } else {
      delete plannedEl.dataset.plannedFormat;
      plannedEl.removeAttribute('title');
    }
  }
  if (effortEl) effortEl.textContent = perceivedEffortLabel(r.perceivedEffort, r.notes);
  if (editBtn) editBtn.addEventListener('click', () => openLogEdit(r.id));
  if (delBtn) delBtn.addEventListener('click', () => deleteLog(r.id, r.date));

  return row;
}

function applyOverflowTooltipToLogPlannedCells() {
  const plannedCells = document.querySelectorAll('#log-tbody .log-planned');
  plannedCells.forEach((cell) => {
    if (!(cell instanceof HTMLElement)) return;

    const text = String(cell.textContent || '').trim();
    const plannedFormat = String(cell.dataset.plannedFormat || '').trim();
    if (text === '' || text === '—') {
      delete cell.dataset.plannedFormat;
      cell.removeAttribute('title');
      return;
    }

    if (plannedFormat) {
      const tooltip = cell.scrollWidth > cell.clientWidth
        ? `${plannedFormat}\n${text}`
        : plannedFormat;
      cell.setAttribute('title', tooltip);
      return;
    }

    if (cell.scrollWidth > cell.clientWidth) {
      cell.setAttribute('title', text);
    } else {
      cell.removeAttribute('title');
    }
  });
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
  requestAnimationFrame(applyOverflowTooltipToLogPlannedCells);
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
  const isRaceType = normalizeSessionType(runType) === 'Race';
  const courseName=isRaceType ? (String(document.getElementById('log-course-name').value||'').trim()||null) : null;
  const perceivedEffort=normalizePerceivedEffort(document.getElementById('log-effort').value)||null;
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
      date,km,duration:dur,dplus,bpm,runType,courseName,perceivedEffort,plannedSession
    })});
    logData.unshift(normalizeLog(created));
    await syncPlannedSessionDate(plannedSessionId, date);
    renderLog(); requestDashboardRefresh();
    ['log-km','log-dur','log-dplus','log-bpm','log-course-name'].forEach(id=>document.getElementById(id).value='');
    renderDurationDualHint('log-dur');
    document.getElementById('log-type').value='';
    document.getElementById('log-effort').value='';
    document.getElementById('log-planned-session-text').value='';
    document.getElementById('log-planned-session-id').value='';
    notify('✓ Sortie enregistrée !');
  } catch(e){notify('⚠ '+e.message);}
}

function openLogEdit(id) {
  const r=logData.find(x=>x.id===id); if(!r)return;
  const idxEl = document.getElementById('lm-idx');
  const dateEl = document.getElementById('lm-date');
  const kmEl = document.getElementById('lm-km');
  const durEl = document.getElementById('lm-dur');
  const dplusEl = document.getElementById('lm-dplus');
  const bpmEl = document.getElementById('lm-bpm');
  const typeEl = document.getElementById('lm-type');
  const courseEl = document.getElementById('lm-course-name');
  const effortEl = document.getElementById('lm-effort');
  if (!idxEl || !dateEl || !kmEl || !durEl || !dplusEl || !bpmEl || !typeEl || !courseEl || !effortEl) {
    notify('⚠ Édition indisponible: modal log introuvable');
    return;
  }

  idxEl.value=id;
  dateEl.value=r.date||'';
  kmEl.value=r.km||'';
  durEl.value=r.duration||'';
  renderDurationDualHint('lm-dur');
  dplusEl.value=r.dplus||'';
  bpmEl.value=r.bpm||'';
  typeEl.value=r.run_type||'';
  courseEl.value=r.courseName||'';
  effortEl.value=r.perceivedEffort||'';
  updateCourseFieldVisibility('lm-type', 'lm-course-field', 'lm-course-name');
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
  const runType = document.getElementById('lm-type').value||null;
  const isRaceType = normalizeSessionType(runType) === 'Race';
  try {
    const updated=await apiFetch(`/run_logs/${id}`,{method:'PUT',body:JSON.stringify({
      date:document.getElementById('lm-date').value,
      km,duration:dur,dplus,
      bpm:Number.parseInt(document.getElementById('lm-bpm').value, 10)||null,
      runType,
      courseName:isRaceType ? (String(document.getElementById('lm-course-name').value||'').trim()||null) : null,
      perceivedEffort:normalizePerceivedEffort(document.getElementById('lm-effort').value)||null,
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
      const linkedDetailId = log ? Number(log.plannedSessionId) : Number.NaN;
      await apiFetch(`/run_logs/${id}`,{method:'DELETE'});
      logData=logData.filter(r=>r.id!==id);
      // Dévalider la séance planifiée liée via l'API sessions (synchronise aussi plan_progress)
      if (Number.isFinite(linkedDetailId)) {
        try {
          const linkedPlanId = await resolvePlanIdForCalendarItem({}, linkedDetailId);
          await setPlanSessionDoneInDb(linkedPlanId, linkedDetailId, false);
        } catch (e) {
          console.warn('linked session invalidate failed', { detailId: linkedDetailId, error: e?.message || String(e) });
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
  const statusText = String(r?.statusLabel || '—');
  let statusClass = String(r?.statusClass || 'badge-future');
  if (statusClass !== 'badge-done') {
    if (/^J-\d+$/i.test(statusText) || /^S-[12]$/i.test(statusText)) {
      statusClass = 'badge-next';
    }
  }
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
    applyDynamicTextContrast(badge, badge, 0.58, 'badge--dark');
    statusEl.replaceChildren(badge);
  }
  if (nameEl) nameEl.textContent = r.name || '—';
  if (dateEl) dateEl.textContent = formatDate(r.date);
  if (distEl) distEl.textContent = r.distance || '—';
  if (objEl) objEl.textContent = r.objective || '—';
  if (realEl) {
    if (r.dnfStatus === 'dns' || r.dnfStatus === 'dnf') {
      const span = document.createElement('span');
      span.className = 'dnf-label';
      span.textContent = r.dnfStatus.toUpperCase();
      if (r.dnfComment) {
        span.title = r.dnfComment;
      }
      realEl.replaceChildren(span);
    } else {
      realEl.textContent = r.result || '—';
    }
  }
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
    const hasResult = Boolean(String(r.result || '').trim()) || r.dnfStatus === 'dns' || r.dnfStatus === 'dnf';
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
  fillLogCourseOptions();
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
  ensureRaceModals();
  const r=racesData.find(x=>x.id===id); if(!r)return;
  const idxEl = document.getElementById('rm-idx');
  const nameEl = document.getElementById('rm-name');
  const dateEl = document.getElementById('rm-date');
  const distEl = document.getElementById('rm-dist');
  const objEl = document.getElementById('rm-obj');
  if (!(idxEl instanceof HTMLInputElement) || !(nameEl instanceof HTMLInputElement)
    || !(dateEl instanceof HTMLInputElement) || !(distEl instanceof HTMLInputElement)
    || !(objEl instanceof HTMLInputElement)) {
    notify('⚠ Edition indisponible pour le moment');
    return;
  }
  idxEl.value = id;
  nameEl.value = r.name || '';
  dateEl.value = r.date || '';
  distEl.value = r.distance || '';
  objEl.value = r.objective || '';
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
  ensureRaceModals();
  const r = racesData.find((x) => x.id === id);
  if (!r) return;
  const idxEl = document.getElementById('rr-idx');
  const resultEl = document.getElementById('rr-real');
  const statusEl = document.getElementById('rr-status');
  const commentEl = document.getElementById('rr-comment');
  const commentWrap = document.getElementById('rr-comment-wrap');
  const resultWrap = document.getElementById('rr-result-wrap');
  if (!(idxEl instanceof HTMLInputElement) || !(resultEl instanceof HTMLInputElement)) {
    notify('⚠ Saisie resultat indisponible pour le moment');
    return;
  }
  idxEl.value = id;
  resultEl.value = r.result || '';

  if (statusEl instanceof HTMLSelectElement) {
    statusEl.value = r.dnfStatus || '';
    // Trigger visibility update
    updateRaceResultModalVisibility();
  }
  if (commentEl instanceof HTMLInputElement) {
    commentEl.value = r.dnfComment || '';
  }
  openModal('race-result-modal');
}

function updateRaceResultModalVisibility() {
  const statusEl = document.getElementById('rr-status');
  const resultWrap = document.getElementById('rr-result-wrap');
  const commentWrap = document.getElementById('rr-comment-wrap');
  if (!(statusEl instanceof HTMLSelectElement)) return;
  const isDnx = statusEl.value === 'dns' || statusEl.value === 'dnf';
  if (resultWrap) resultWrap.style.display = isDnx ? 'none' : '';
  if (commentWrap) commentWrap.style.display = isDnx ? '' : 'none';
}

async function saveRaceResult() {
  const id = Number.parseInt(document.getElementById('rr-idx').value, 10);
  const current = racesData.find((r) => r.id === id);
  if (!current) {
    notify('⚠ Course introuvable');
    return;
  }

  const statusEl = document.getElementById('rr-status');
  const commentEl = document.getElementById('rr-comment');
  const dnfStatus = statusEl instanceof HTMLSelectElement ? statusEl.value : '';
  const dnfComment = commentEl instanceof HTMLInputElement ? commentEl.value.trim() : '';
  const isDnx = dnfStatus === 'dns' || dnfStatus === 'dnf';

  try {
    const updated = await apiFetch(`/races/${id}`, {
      method: 'PUT',
      body: JSON.stringify({
        name: current.name || '',
        date: current.date || '',
        distance: current.distance || null,
        objective: current.objective || null,
        result: isDnx ? null : (document.getElementById('rr-real').value.trim() || null),
        dnfStatus: isDnx ? dnfStatus : null,
        dnfComment: isDnx ? (dnfComment || null) : null,
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

function ensureRaceModals() {
  let raceModal = document.getElementById('race-modal');
  if (!raceModal) {
    raceModal = document.createElement('div');
    raceModal.id = 'race-modal';
    raceModal.className = 'modal-overlay';
    raceModal.innerHTML = `
      <div class="modal">
        <button class="modal-close" onclick="closeModal('race-modal')">x</button>
        <div class="modal-title">Modifier la course</div>
        <input type="hidden" id="rm-idx">
        <div class="form-grid">
          <div class="field"><label for="rm-name">Nom</label><input id="rm-name" type="text"></div>
          <div class="field"><label for="rm-date">Date</label><input id="rm-date" type="date"></div>
          <div class="field"><label for="rm-dist">Distance (km)</label><input id="rm-dist" type="text"></div>
          <div class="field"><label for="rm-obj">Objectif (hh:mm:ss)</label><input id="rm-obj" type="text"></div>
        </div>
        <div class="modal-actions">
          <button class="btn" onclick="saveRaceEdit()">Enregistrer</button>
          <button class="btn btn-ghost" onclick="closeModal('race-modal')">Annuler</button>
        </div>
      </div>
    `;
    document.body.appendChild(raceModal);
  }

  let raceResultModal = document.getElementById('race-result-modal');
  if (!raceResultModal) {
    raceResultModal = document.createElement('div');
    raceResultModal.id = 'race-result-modal';
    raceResultModal.className = 'modal-overlay';
    raceResultModal.innerHTML = `
      <div class="modal">
        <button class="modal-close" onclick="closeModal('race-result-modal')">×</button>
        <div class="modal-title">Saisir le résultat</div>
        <input type="hidden" id="rr-idx">
        <div class="form-grid">
          <div class="field">
            <label for="rr-status">Statut</label>
            <select id="rr-status" onchange="updateRaceResultModalVisibility()">
              <option value="">✓ Terminée</option>
              <option value="dns">DNS — Did Not Start</option>
              <option value="dnf">DNF — Did Not Finish</option>
            </select>
          </div>
          <div class="field" id="rr-result-wrap">
            <label for="rr-real">Temps (hh:mm:ss)</label>
            <input id="rr-real" type="text" placeholder="00:53:22">
          </div>
          <div class="field" id="rr-comment-wrap" style="display:none">
            <label for="rr-comment">Commentaire (optionnel)</label>
            <input id="rr-comment" type="text" placeholder="ex: chute au km 3">
          </div>
        </div>
        <div class="modal-actions">
          <button class="btn" onclick="saveRaceResult()">Enregistrer</button>
          <button class="btn btn-ghost" onclick="closeModal('race-result-modal')">Annuler</button>
        </div>
      </div>
    `;
    document.body.appendChild(raceResultModal);
  }
}

// ============================================================
// INIT
// ============================================================
async function initApp() {
  setupMobileHeaderNav();
  setupAnnouncementDismiss();

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
  safeRender(bindPlansHistoryListener, 'plans-history');
  safeRender(() => {
    const initialPlanId = getInitialPlanIdFromUrlOrDom();
    if (initialPlanId !== null) {
      const opened = openPlan(initialPlanId, { pushHistory: false });
      if (!opened) {
        backToPlansList({ pushHistory: false });
        notify('⚠ Plan introuvable');
      }
    }
  }, 'plan-url-open');
  safeRender(renderDashboard, 'dashboard');
  safeRender(setupHomePlanModuleAccordion, 'home-plan-module-accordion');
  safeRender(renderLog, 'log');
  safeRender(bindLogFormSubmit, 'log-submit-binding');
  safeRender(renderRaces, 'races');
  safeRender(consumeAdviceFocusFromUrl, 'advice-focus-url');
  safeRender(consumePlanEditIntentFromUrl, 'plan-edit-url');
  safeRender(consumeRaceEditIntentFromUrl, 'race-edit-url');
  safeRender(setupWeatherCityControls, 'weather-city-controls');
  safeRender(bindCourseFieldVisibility, 'course-field-visibility');

  const today = new Date().toISOString().split('T')[0];
  const logDateEl = document.getElementById('log-date');
  if (logDateEl) logDateEl.value = today;
  setLogEntryMode(plannedSessionId ? 'calendar' : 'manual', { clearCalendarSelection: false });
  const raceDateEl = document.getElementById('r-date');
  if (raceDateEl) raceDateEl.value = today;

  // Setup date input handlers for FR format (jj/mm/yyyy) conversion
  // NOTE: Do NOT call showPicker() on click — the browser already opens the native
  // date picker when the calendar icon is clicked. Adding showPicker() causes a
  // double-open flicker on Chrome/Edge (picker opens twice in quick succession).
  ['log-date', 'r-date', 'lm-date', 'rm-date', 'pm-date'].forEach(id => {
    const el = document.getElementById(id);
    if (el) {
      el.addEventListener('change', (e) => {
        const val = e.target.value;
        if (val && !val.includes('-')) {
          e.target.value = normalizeDateForStorage(val);
        }
      });
    }
  });

  setupDurationDualHints();
  setupPlanTotalAutoCompute();

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
