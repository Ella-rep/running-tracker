const API = '/api';
let mode = 'login';
let resetTokenFromUrl = null;

function getPasswordAutocomplete(currentMode) {
  return currentMode === 'login' ? ['current', 'password'].join('-') : ['new', 'password'].join('-');
}

function getModeConfig(currentMode) {
  if (currentMode === 'register') {
    return {
      label: 'Créer un compte',
      button: 'Créer le compte',
      toggle: 'Se connecter',
      prefix: 'Déjà un compte ? ',
      showUsername: true,
      showEmail: true,
      showPassword: true,
      showForgot: false,
      showBack: false,
      passwordLabel: 'Mot de passe',
    };
  }

  if (currentMode === 'forgot') {
    return {
      label: 'Recevoir un lien de réinitialisation',
      button: 'Envoyer le lien',
      toggle: 'Créer un compte',
      prefix: 'Pas encore de compte ? ',
      showUsername: true,
      showEmail: true,
      showPassword: false,
      showForgot: false,
      showBack: true,
      passwordLabel: 'Mot de passe',
    };
  }

  if (currentMode === 'reset') {
    return {
      label: 'Choisir un nouveau mot de passe',
      button: 'Valider le nouveau mot de passe',
      toggle: 'Créer un compte',
      prefix: 'Pas encore de compte ? ',
      showUsername: false,
      showEmail: false,
      showPassword: true,
      showForgot: false,
      showBack: true,
      passwordLabel: 'Nouveau mot de passe',
    };
  }

  return {
    label: 'Connexion',
    button: 'Se connecter',
    toggle: 'Créer un compte',
    prefix: 'Pas encore de compte ? ',
    showUsername: false,
    showEmail: true,
    showPassword: true,
    showForgot: true,
    showBack: false,
    passwordLabel: 'Mot de passe',
  };
}

function setFeedback(errorMessage = '', infoMessage = '') {
  const errEl = document.getElementById('auth-error');
  const infoEl = document.getElementById('auth-info');
  if (errEl) errEl.textContent = errorMessage;
  if (infoEl) infoEl.textContent = infoMessage;
}

function getAuthHelper() {
  return globalThis.rtAuth || null;
}

function renderAuthMode() {
  const config = getModeConfig(mode);
  const usernameField = document.getElementById('auth-username-field');
  const emailField = document.getElementById('auth-email-field');
  const passwordField = document.getElementById('auth-password-field');
  const passwordInput = document.getElementById('auth-password');
  const passwordLabel = document.querySelector('label[for="auth-password"]');
  const forgotBtn = document.getElementById('forgot-password-btn');
  const backBtn = document.getElementById('back-to-login-btn');

  document.getElementById('login-mode-label').textContent = config.label;
  document.getElementById('auth-btn').textContent = config.button;
  document.getElementById('toggle-mode').textContent = config.toggle;
  document.getElementById('toggle-prefix').textContent = config.prefix;
  if (usernameField) usernameField.hidden = !config.showUsername;
  if (emailField) emailField.hidden = !config.showEmail;
  if (passwordField) passwordField.hidden = !config.showPassword;
  if (forgotBtn) forgotBtn.hidden = !config.showForgot;
  if (backBtn) backBtn.hidden = !config.showBack;
  if (passwordLabel) passwordLabel.textContent = config.passwordLabel;
  if (passwordInput) passwordInput.autocomplete = getPasswordAutocomplete(mode);
  setFeedback('', '');
}

function toggleAuthMode() {
  mode = mode === 'register' ? 'login' : 'register';
  renderAuthMode();
}

function openForgotPasswordMode() {
  mode = 'forgot';
  renderAuthMode();
}

function backToLoginMode() {
  if (mode === 'reset') {
    clearResetTokenFromUrl();
    resetTokenFromUrl = null;
  }
  mode = 'login';
  renderAuthMode();
}

function clearResetTokenFromUrl() {
  const url = new URL(globalThis.location.href);
  url.searchParams.delete('resetToken');
  globalThis.history.replaceState({}, document.title, url.pathname + url.search);
}

async function parseApiError(response, fallbackMessage) {
  let data = null;
  try {
    data = await response.json();
  } catch {
    data = null;
  }

  return data?.message || data?.['hydra:description'] || data?.detail || fallbackMessage;
}

function hasRequiredFields(modeName, username, email, password) {
  const requiresUsername = modeName === 'register' || modeName === 'forgot';
  const requiresEmail = modeName === 'register' || modeName === 'forgot';
  const requiresPassword = modeName === 'login' || modeName === 'register' || modeName === 'reset';

  const loginRequiresEmail = modeName === 'login';

  return (!requiresUsername || !!username)
    && (!requiresEmail || !!email)
    && (!requiresPassword || !!password)
    && (!loginRequiresEmail || !!email);
}

async function handleRegister(username, email, password) {
  const registerResponse = await fetch(API + '/auth/register', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ username, email, plainPassword: password }),
  });

  if (!registerResponse.ok) {
    throw new Error(await parseApiError(registerResponse, 'Erreur inscription'));
  }
}

async function handleForgot(username, email) {
  const resetResponse = await fetch(API + '/auth/reset-password/request', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ username, email }),
  });

  if (!resetResponse.ok) {
    throw new Error(await parseApiError(resetResponse, 'Erreur réinitialisation'));
  }

  const resetData = await resetResponse.json();
  setFeedback('', resetData.message || 'Si les informations sont correctes, un email a été envoyé.');
  backToLoginMode();
}

async function handleReset(password) {
  const confirmResponse = await fetch(API + '/auth/reset-password/confirm', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ token: resetTokenFromUrl, plainPassword: password }),
  });

  if (!confirmResponse.ok) {
    throw new Error(await parseApiError(confirmResponse, 'Erreur réinitialisation'));
  }

  const confirmData = await confirmResponse.json();
  setFeedback('', confirmData.message || 'Mot de passe réinitialisé. Tu peux te connecter.');
  clearResetTokenFromUrl();
  resetTokenFromUrl = null;
  mode = 'login';
  renderAuthMode();
}

async function handleLogin(email, password) {
  const loginResponse = await fetch(API + '/auth/login', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ email, password }),
  });

  if (!loginResponse.ok) {
    throw new Error(await parseApiError(loginResponse, 'Erreur de connexion'));
  }

  const { token } = await loginResponse.json();
  const auth = getAuthHelper();
  if (auth?.setToken) {
    auth.setToken(token);
  } else {
    localStorage.setItem('rt_token', token);
  }
  globalThis.location.href = '/';
}

async function submitAuth() {
  const username = document.getElementById('auth-username').value.trim();
  const email = document.getElementById('auth-email')?.value.trim() || '';
  const password = document.getElementById('auth-password').value;
  const btn = document.getElementById('auth-btn');

  if (!hasRequiredFields(mode, username, email, password)) {
    setFeedback('Remplis tous les champs requis.', '');
    return;
  }

  btn.textContent = '…';
  setFeedback('', '');

  try {
    if (mode === 'register') {
      await handleRegister(username, email, password);
    }

    if (mode === 'forgot') {
      await handleForgot(username, email);
      return;
    }

    if (mode === 'reset') {
      await handleReset(password);
      return;
    }

    await handleLogin(email, password);
  } catch (error) {
    setFeedback(error.message, '');
    btn.textContent = getModeConfig(mode).button;
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

  const forgotBtn = document.getElementById('forgot-password-btn');
  if (forgotBtn) {
    forgotBtn.addEventListener('click', openForgotPasswordMode);
  }

  const backBtn = document.getElementById('back-to-login-btn');
  if (backBtn) {
    backBtn.addEventListener('click', backToLoginMode);
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
        globalThis.location.href = '/';
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
      globalThis.location.href = '/';
    }
  } catch {
    // Ignore network/transient errors and keep user on login page.
  }
}

function initLoginPage() {
  const params = new URLSearchParams(globalThis.location.search);
  const token = params.get('resetToken');
  if (token) {
    resetTokenFromUrl = token;
    mode = 'reset';
  }

  if (typeof globalThis.applyTheme === 'function') {
    globalThis.applyTheme(localStorage.getItem('rt_theme') || 'dark');
  }
  bindLoginEvents();
  renderAuthMode();
  redirectIfAlreadyLoggedIn();
}

document.addEventListener('DOMContentLoaded', initLoginPage);
