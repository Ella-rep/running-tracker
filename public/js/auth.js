const AUTH_API_BASE = '/api';
const AUTH_PERSIST_KEY = 'rt_auth_persist';
let inMemoryToken = null;
let inMemoryPersist = true;

function storageGet(storage, key) {
  try {
    return storage.getItem(key);
  } catch {
    return null;
  }
}

function storageSet(storage, key, value) {
  try {
    storage.setItem(key, value);
    return true;
  } catch {
    return false;
  }
}

function storageRemove(storage, key) {
  try {
    storage.removeItem(key);
  } catch {
    // Ignore unavailable storage on some mobile/private contexts.
  }
}

function getCookieToken() {
  const cookies = String(document.cookie || '').split(';');
  for (const cookie of cookies) {
    const [name, ...parts] = cookie.trim().split('=');
    if (name === 'BEARER') {
      return decodeURIComponent(parts.join('='));
    }
  }

  return null;
}

function authCookieSuffix() {
  const parts = ['Path=/', 'SameSite=Lax'];
  if (globalThis.location?.protocol === 'https:') {
    parts.push('Secure');
  }
  return '; ' + parts.join('; ');
}

function writeAuthCookie(token) {
  const value = encodeURIComponent(String(token || '').trim());
  if (value) {
    document.cookie = 'BEARER=' + value + authCookieSuffix();
  }
}

function clearAuthCookie() {
  document.cookie = 'BEARER=; Max-Age=0' + authCookieSuffix();
}

function getToken() {
  return storageGet(localStorage, 'rt_token')
    || storageGet(sessionStorage, 'rt_token')
    || inMemoryToken
    || getCookieToken()
    || null;
}

function setToken(token, persist = true) {
  if (typeof token === 'string' && token.trim() !== '') {
    const normalized = token.trim();
    if (persist) {
      const persisted = storageSet(localStorage, 'rt_token', normalized);
      storageRemove(sessionStorage, 'rt_token');
      if (!persisted) {
        inMemoryToken = normalized;
      }
      storageSet(localStorage, AUTH_PERSIST_KEY, '1');
      inMemoryPersist = true;
    } else {
      const persisted = storageSet(sessionStorage, 'rt_token', normalized);
      storageRemove(localStorage, 'rt_token');
      if (!persisted) {
        inMemoryToken = normalized;
      }
      storageSet(localStorage, AUTH_PERSIST_KEY, '0');
      inMemoryPersist = false;
    }
    writeAuthCookie(normalized);
  }
}

function clearToken() {
  storageRemove(localStorage, 'rt_token');
  storageRemove(sessionStorage, 'rt_token');
  inMemoryToken = null;
  clearAuthCookie();
}

function getRememberPreference() {
  const value = storageGet(localStorage, AUTH_PERSIST_KEY);
  if (value === null) {
    return inMemoryPersist;
  }

  return value !== '0';
}

function setRememberPreference(persist) {
  inMemoryPersist = !!persist;
  storageSet(localStorage, AUTH_PERSIST_KEY, persist ? '1' : '0');
}

function buildAuthHeaders(extraHeaders = {}) {
  const headers = { ...extraHeaders };
  const token = getToken();
  if (token) {
    headers.Authorization = 'Bearer ' + token;
  }
  return headers;
}

async function fetchCurrentUser() {
  const response = await fetch(AUTH_API_BASE + '/auth/me', {
    headers: buildAuthHeaders(),
    credentials: 'same-origin',
  });

  if (response.status === 401) {
    clearToken();
    return null;
  }

  if (!response.ok) {
    const raw = await response.text();
    throw new Error(raw || 'Impossible de verifier la session.');
  }

  return response.json();
}

globalThis.rtAuth = {
  getToken,
  setToken,
  clearToken,
  getRememberPreference,
  setRememberPreference,
  buildAuthHeaders,
  fetchCurrentUser,
};

const existingToken = getToken();
if (existingToken) {
  writeAuthCookie(existingToken);
}
