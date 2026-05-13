const API = '/api';
let mode = 'login';

function getAuthHelper() {
  return globalThis.rtAuth || null;
}

function renderAuthMode() {
  const isLogin = mode === 'login';
  document.getElementById('login-mode-label').textContent = isLogin ? 'Connexion' : 'Créer un compte';
  document.getElementById('auth-btn').textContent = isLogin ? 'Se connecter' : 'Créer le compte';
  document.getElementById('toggle-mode').textContent = isLogin ? 'Créer un compte' : 'Se connecter';
  document.getElementById('toggle-prefix').textContent = isLogin ? 'Pas encore de compte ? ' : 'Déjà un compte ? ';
  document.getElementById('auth-error').textContent = '';
}

function toggleAuthMode() {
  mode = mode === 'login' ? 'register' : 'login';
  renderAuthMode();
}

async function submitAuth() {
  const username = document.getElementById('auth-username').value.trim();
  const password = document.getElementById('auth-password').value;
  const btn = document.getElementById('auth-btn');
  const errEl = document.getElementById('auth-error');

  if (!username || !password) {
    errEl.textContent = 'Remplis les deux champs.';
    return;
  }

  btn.textContent = '…';
  errEl.textContent = '';

  try {
    if (mode === 'register') {
      const registerResponse = await fetch(API + '/auth/register', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ username, plainPassword: password }),
      });

      if (!registerResponse.ok) {
        const errorData = await registerResponse.json();
        throw new Error(errorData['hydra:description'] || errorData.detail || 'Erreur inscription');
      }
    }

    const loginResponse = await fetch(API + '/auth/login', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ username, password }),
    });

    if (!loginResponse.ok) {
      throw new Error('Identifiants incorrects');
    }

    const { token } = await loginResponse.json();
    const auth = getAuthHelper();
    if (auth?.setToken) {
      auth.setToken(token);
    } else {
      localStorage.setItem('rt_token', token);
    }
    globalThis.location.href = '/dashboard';
  } catch (error) {
    errEl.textContent = error.message;
    btn.textContent = mode === 'login' ? 'Se connecter' : 'Créer le compte';
  }
}

function bindLoginEvents() {
  const toggleThemeBtn = document.getElementById('theme-toggle');
  if (toggleThemeBtn && typeof globalThis.toggleTheme === 'function') {
    toggleThemeBtn.addEventListener('click', globalThis.toggleTheme);
  }

  const authBtn = document.getElementById('auth-btn');
  if (authBtn) {
    authBtn.addEventListener('click', submitAuth);
  }

  const toggleModeBtn = document.getElementById('toggle-mode');
  if (toggleModeBtn) {
    toggleModeBtn.addEventListener('click', toggleAuthMode);
  }

  const passwordInput = document.getElementById('auth-password');
  if (passwordInput) {
    passwordInput.addEventListener('keydown', (event) => {
      if (event.key === 'Enter') {
        submitAuth();
      }
    });
  }
}

async function redirectIfAlreadyLoggedIn() {
  const auth = getAuthHelper();
  try {
    if (auth?.fetchCurrentUser) {
      const me = await auth.fetchCurrentUser();
      if (me) {
        globalThis.location.href = '/dashboard';
      }
      return;
    }

    const token = localStorage.getItem('rt_token');
    if (!token) {
      return;
    }
    const response = await fetch(API + '/auth/me', {
      headers: { Authorization: 'Bearer ' + token },
    });
    if (response.ok) {
      globalThis.location.href = '/dashboard';
    }
  } catch {
    // Ignore network/transient errors and keep user on login page.
  }
}

function initLoginPage() {
  if (typeof globalThis.applyTheme === 'function') {
    globalThis.applyTheme(localStorage.getItem('rt_theme') || 'dark');
  }
  bindLoginEvents();
  renderAuthMode();
  redirectIfAlreadyLoggedIn();
}

document.addEventListener('DOMContentLoaded', initLoginPage);
