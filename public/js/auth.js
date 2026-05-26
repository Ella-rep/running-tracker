const AUTH_API_BASE = '/api';

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
  return localStorage.getItem('rt_token') || null;
}

function setToken(token) {
  if (typeof token === 'string' && token.trim() !== '') {
    const normalized = token.trim();
    localStorage.setItem('rt_token', normalized);
    writeAuthCookie(normalized);
  }
}

function clearToken() {
  localStorage.removeItem('rt_token');
  clearAuthCookie();
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
  const token = getToken();
  if (!token) {
    return null;
  }

  const response = await fetch(AUTH_API_BASE + '/auth/me', {
    headers: buildAuthHeaders(),
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
  buildAuthHeaders,
  fetchCurrentUser,
};

const existingToken = getToken();
if (existingToken) {
  writeAuthCookie(existingToken);
}
