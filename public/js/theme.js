function applyTheme(theme) {
  document.documentElement.dataset.theme = theme;
  const btn = document.getElementById('theme-toggle');
  const iconEl = document.getElementById('theme-toggle-icon');

  if (btn) {
    const isLight = theme === 'light';
    const nextModeLabel = isLight ? 'Passer en mode sombre' : 'Passer en mode clair';
    btn.setAttribute('aria-checked', String(isLight));
    btn.setAttribute('title', nextModeLabel);
    btn.setAttribute('aria-label', nextModeLabel);
    btn.classList.toggle('is-light', isLight);
    btn.classList.toggle('is-dark', !isLight);
  }

  if (iconEl) {
    iconEl.textContent = theme === 'light' ? '☀' : '☾';
  }
}

function toggleTheme() {
  const current = document.documentElement.dataset.theme || 'dark';
  const next = current === 'dark' ? 'light' : 'dark';
  localStorage.setItem('rt_theme', next);
  applyTheme(next);
}

globalThis.applyTheme = applyTheme;
globalThis.toggleTheme = toggleTheme;

// Apply immediately to avoid theme flash when opening a page.
applyTheme(localStorage.getItem('rt_theme') || 'dark');
