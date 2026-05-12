const AUTH_API_BASE = '/api';

function getToken() {
  return localStorage.getItem('rt_token') || null;
}

function setToken(token) {
  if (typeof token === 'string' && token.trim() !== '') {
    localStorage.setItem('rt_token', token);
  }
}

function clearToken() {
  localStorage.removeItem('rt_token');
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
