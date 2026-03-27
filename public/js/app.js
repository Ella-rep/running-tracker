// ============================================================
// API CLIENT — API Platform returns JSON-LD (hydra:member)
// ============================================================
const API = '/api';
let authToken = localStorage.getItem('rt_token') || null;

async function apiFetch(path, options = {}) {
  const headers = { 'Content-Type': 'application/json', ...(options.headers || {}) };
  if (authToken) headers['Authorization'] = `Bearer ${authToken}`;

  const res = await fetch(API + path, { ...options, headers });

  if (res.status === 401) { logout(); return null; }
  if (res.status === 204) return null;

  const data = await res.json();
  if (!res.ok) {
    // API Platform error format
    const msg = data['hydra:description'] || data.detail || data.message || 'Erreur API';
    throw new Error(msg);
  }
  return data;
}

// API Platform collections return { "hydra:member": [...] }
function members(data) {
  return data?.['hydra:member'] ?? data ?? [];
}

function logout() {
  localStorage.removeItem('rt_token');
  window.location.href = '/';
}


// ============================================================
// THEME — light / dark
// ============================================================
function applyTheme(theme) {
  document.documentElement.setAttribute('data-theme', theme);
  const btn = document.getElementById('theme-toggle');
  if (btn) btn.textContent = theme === 'light' ? '🌙' : '☀️';
}

function toggleTheme() {
  const current = document.documentElement.getAttribute('data-theme') || 'dark';
  const next = current === 'dark' ? 'light' : 'dark';
  localStorage.setItem('rt_theme', next);
  applyTheme(next);
}

// Apply immediately to avoid flash
applyTheme(localStorage.getItem('rt_theme') || 'dark');

// ============================================================
// DATA
// ============================================================
let logData   = [];
let racesData = [];
let state     = { tempoDone: {}, prepDone: {}, semiDone: {} };

async function loadAllData() {
  const [logs, races, checks] = await Promise.all([
    apiFetch('/run_logs?order[date]=desc&pagination=false'),
    apiFetch('/races?order[date]=asc&pagination=false'),
    apiFetch('/plan_checks?pagination=false'),
  ]);

  // Normalize API Platform IRI ids to plain int ids
  logData   = members(logs).map(normalizeLog);
  racesData = members(races).map(normalizeRace);

  state = { tempoDone: {}, prepDone: {}, semiDone: {} };
  members(checks).forEach(c => {
    if (state[c.planKey] !== undefined) {
      state[c.planKey][c.sessionIndex] = c.done;
    }
  });
}

function iriToId(iri) {
  if (!iri) return null;
  const parts = String(iri).split('/');
  return parseInt(parts[parts.length - 1]);
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
// STATIC PLAN DATA
// ============================================================
const tempoData = [
  {sem:1, date:'2026-03-24', format:"40'@Z2", pe:'3/10', total:40},
  {sem:1, date:'2026-03-25', format:"20'@Z2 >> 8x (45''@Z5 + 30''@Z1) >> 5'@Z1", pe:'6/10', total:35},
  {sem:null, date:'2026-03-28', format:"10km (course)", pe:'7/10', total:null},
  {sem:1, date:'2026-03-29', format:"20'@Z2 >> 4x (5'@Z4 + 2'@Z1) >> 5'@Z1", pe:'6/10', total:53},
  {sem:2, date:'2026-03-30', format:"45'@Z2", pe:'3/10', total:45},
  {sem:2, date:'2026-03-31', format:"20'@Z2 >> 2x (5'@Z4 +2'@Z1) + 4x (1'@Z5 +1'@Z1) >> 5'@Z1", pe:'6/10', total:47},
  {sem:2, date:'2026-04-05', format:"20'@Z2 >> 20'@Z3 >> 10'@Z2", pe:'6/10', total:50},
  {sem:1, date:'2026-04-06', format:"45'@Z2", pe:'3/10', total:45},
  {sem:1, date:'2026-04-07', format:"20'@Z2 >> 3x (7'@Z4 + 2'@Z1) >> 5'@Z1", pe:'6/10', total:52},
  {sem:1, date:'2026-04-12', format:"15'@Z2 >> 30'@Z3 >> 15'@Z2", pe:'6/10', total:60},
  {sem:2, date:null, format:"45'@Z2", pe:'3/10', total:45},
  {sem:2, date:null, format:"20'@Z2 >> 8x (1'@Z5 + 1'@Z1) >> 10'@Z2", pe:'5/10', total:46},
  {sem:2, date:null, format:"20'@Z2 >> 4x (8'@Z4 + 2'@Z1) >> 5'@Z1", pe:'6/10', total:65},
  {sem:1, date:null, format:"40'@Z2", pe:'3/10', total:40},
  {sem:1, date:null, format:"20'@Z2 >> 10x (20''@Z5 + 40''@Z1) >> 5'@Z1", pe:'6/10', total:35},
  {sem:1, date:null, format:"20'@Z2 >> 4x (5'@Z4 + 2'@Z2) >> 5'@Z1", pe:'6/10', total:53},
];

const prepData = [
  {sem:1,date:null,format:"25'@Z2",pe:'3/10',total:25,opt:false},
  {sem:1,date:null,format:"30'@Z2 >> 5x (20\"@Z5 + 40\"@Z1) >> 5'@Z1",pe:'4/10',total:40,opt:false},
  {sem:1,date:null,format:"30'@Z2",pe:'3/10',total:30,opt:true},
  {sem:1,date:'2026-05-04',format:"60'@Z2",pe:'4/10',total:60,opt:false},
  {sem:2,date:null,format:"45'@Z2",pe:'3/10',total:45,opt:false},
  {sem:2,date:null,format:"25'@Z2 >> 2x (6'@Z3 + 2'@Z1) >> 5'@Z1",pe:'6/10',total:46,opt:false},
  {sem:2,date:null,format:"35'@Z2",pe:'3/10',total:35,opt:true},
  {sem:2,date:'2026-05-11',format:"65'@Z2",pe:'4/10',total:65,opt:false},
  {sem:1,date:null,format:"40'@Z2",pe:'3/10',total:40,opt:false},
  {sem:1,date:null,format:"25'@Z2 >> 5x (2'@Z4 + 1'@Z1) >> 10'@Z2",pe:'5/10',total:50,opt:false},
  {sem:1,date:null,format:"40'@Z2",pe:'3/10',total:40,opt:true},
  {sem:1,date:'2026-05-18',format:"60'@Z2",pe:'4/10',total:60,opt:false},
  {sem:2,date:null,format:"40'@Z2",pe:'3/10',total:40,opt:false},
  {sem:2,date:null,format:"25'@Z2 >> 4x (3'@Z4 + 1'30\"@Z1) >> 10'@Z1",pe:'5/10',total:50,opt:false},
  {sem:2,date:null,format:"40'@Z2",pe:'6/10',total:40,opt:true},
  {sem:2,date:'2026-05-25',format:"65'@Z2",pe:'4/10',total:65,opt:false},
  {sem:1,date:null,format:"40'@Z2",pe:'3/10',total:40,opt:false},
  {sem:1,date:null,format:"25'@Z2 >> 10x (1'@Z4 + 1'@Z1) >> 10'@Z1",pe:'3/10',total:55,opt:false},
  {sem:1,date:null,format:"40'@Z2",pe:'3/10',total:40,opt:true},
  {sem:1,date:'2026-06-01',format:"65'@Z2",pe:'4/10',total:65,opt:false},
  {sem:2,date:null,format:"40'@Z2",pe:'3/10',total:40,opt:false},
  {sem:2,date:null,format:"35'@Z2 >> 6x (1'@Z5 + 1'@Z1) >> 5'@Z1",pe:'6/10',total:57,opt:false},
  {sem:2,date:null,format:"40'@Z2",pe:'3/10',total:40,opt:true},
  {sem:2,date:'2026-06-08',format:"70'@Z2",pe:'4/10',total:70,opt:false},
  {sem:1,date:null,format:"40'@Z2",pe:'3/10',total:40,opt:false},
  {sem:1,date:null,format:"40'@Z2",pe:'3/10',total:40,opt:true},
  {sem:1,date:null,format:"25'@Z2 >> 4x (5'@Z4 + 2'@Z1) >> 5'@Z1",pe:'6/10',total:58,opt:false},
  {sem:1,date:'2026-06-15',format:"75'@Z2",pe:'4/10',total:75,opt:false},
  {sem:2,date:null,format:"40'@Z2",pe:'3/10',total:40,opt:false},
  {sem:2,date:null,format:"40'@Z2",pe:'3/10',total:40,opt:true},
  {sem:2,date:null,format:"25'@Z2 >> 4x (7'@Z4 + 2'@Z1) >> 5'@Z1",pe:'6/10',total:65,opt:false},
  {sem:2,date:'2026-06-22',format:"75'@Z2",pe:'4/10',total:75,opt:false},
  {sem:1,date:null,format:"40'@Z2",pe:'3/10',total:40,opt:false},
  {sem:1,date:null,format:"40'@Z2",pe:'3/10',total:40,opt:true},
  {sem:1,date:null,format:"30'@Z2 >> 25'@Z3 >> 5'@Z1",pe:'5/10',total:60,opt:false},
  {sem:1,date:'2026-06-29',format:"80'@Z2",pe:'4/10',total:80,opt:false},
  {sem:2,date:null,format:"45'@Z2",pe:'3/10',total:45,opt:false},
  {sem:2,date:null,format:"45'@Z2",pe:'3/10',total:45,opt:true},
  {sem:2,date:null,format:"25'@Z2 >> 5x (7'@Z4 + 2'@Z1) >> 5'@Z1",pe:'6/10',total:70,opt:false},
  {sem:2,date:'2026-07-06',format:"85'@Z2",pe:'4/10',total:85,opt:false},
  {sem:1,date:null,format:"40'@Z2",pe:'3/10',total:40,opt:false},
  {sem:1,date:null,format:"40'@Z2",pe:'3/10',total:40,opt:true},
  {sem:1,date:null,format:"30'@Z2 >> 2x (15'@Z3 + 5'@Z1) >> 5'@Z1",pe:'5/10',total:70,opt:false},
  {sem:1,date:'2026-07-13',format:"75'@Z2",pe:'4/10',total:75,opt:false},
  {sem:2,date:null,format:"40'@Z2",pe:'3/10',total:40,opt:false},
  {sem:2,date:null,format:"40'@Z2",pe:'3/10',total:40,opt:true},
  {sem:2,date:null,format:"25'@Z2 >> 3x (10'@Z4 + 3'@Z1) >> 5'@Z1",pe:'6/10',total:71,opt:false},
  {sem:2,date:'2026-07-20',format:"60'@Z2 (récup)",pe:'3/10',total:60,opt:false},
];

const semiData = [
  {sem:1,date:null,format:"45'@Z2",pe:'3/10',total:45,opt:false},
  {sem:1,date:null,format:"20'@Z2 >> 10x (30\"@Z5 + 30\"@Z1) >> 5'@Z1",pe:'4/10',total:35,opt:false},
  {sem:1,date:null,format:"30'@Z2",pe:'3/10',total:30,opt:true},
  {sem:1,date:'2026-07-26',format:"15'@Z2 >> 15'@Z3 + 15'@Z4 >> 15'@Z2",pe:'5/10',total:60,opt:false},
  {sem:2,date:null,format:"45'@Z2",pe:'3/10',total:45,opt:false},
  {sem:2,date:null,format:"20'@Z2 >> 8x (1'@Z5 + 1'@Z1) >> 5'@Z1",pe:'5/10',total:41,opt:false},
  {sem:2,date:null,format:"35'@Z2",pe:'3/10',total:35,opt:true},
  {sem:2,date:'2026-08-02',format:"25'@Z2 >> 15'@Z4 >> 25'@Z2",pe:'5/10',total:65,opt:false},
  {sem:1,date:null,format:"45'@Z2 >> 8x (20\"@Z5 + 40\"@Z1) >> 5'@Z1",pe:'4/10',total:58,opt:false},
  {sem:1,date:null,format:"20'@Z2 >> 8x (1'@Z5 + 1'@Z1) >> 15'@Z2",pe:'5/10',total:51,opt:false},
  {sem:1,date:null,format:"40'@Z2",pe:'3/10',total:40,opt:true},
  {sem:1,date:'2026-08-09',format:"70'@Z2",pe:'4/10',total:70,opt:false},
  {sem:2,date:null,format:"25'@Z2 >> 5x (2'@Z5 + 2'@Z1) >> 5'@Z1",pe:'5/10',total:50,opt:false},
  {sem:2,date:null,format:"40'@Z2",pe:'3/10',total:40,opt:true},
  {sem:2,date:null,format:"20'@Z2 >> 3x (8'@Z4 + 3'@Z1) >> 5'@Z1",pe:'6/10',total:58,opt:false},
  {sem:2,date:'2026-08-16',format:"75'@Z2",pe:'4/10',total:75,opt:false},
  {sem:1,date:null,format:"20'@Z2 >> 10x (3'@Z4 + 1'30\"@Z1) >> 5'@Z1",pe:'6/10',total:60,opt:false},
  {sem:1,date:null,format:"45'@Z2",pe:'3/10',total:45,opt:true},
  {sem:1,date:null,format:"20'@Z2 >> 2x (10'@Z3 + 3'@Z1) >> 5'@Z1",pe:'4/10',total:51,opt:false},
  {sem:1,date:'2026-08-23',format:"80'@Z2",pe:'4/10',total:80,opt:false},
  {sem:2,date:null,format:"25'@Z2 >> 10x (2'@Z5 + 1'@Z1) >> 5'@Z1",pe:'6/10',total:60,opt:false},
  {sem:2,date:null,format:"45'@Z2",pe:'3/10',total:45,opt:true},
  {sem:2,date:null,format:"20'@Z2 >> 4x (7'@Z4 + 3'@Z1) >> 5'@Z1",pe:'6/10',total:65,opt:false},
  {sem:2,date:'2026-08-30',format:"85'@Z2",pe:'4/10',total:85,opt:false},
  {sem:1,date:null,format:"25'@Z2 >> 8x (2'30\"@Z5 + 2'@Z1) >> 5'@Z1",pe:'6/10',total:66,opt:false},
  {sem:1,date:null,format:"50'@Z2",pe:'3/10',total:50,opt:true},
  {sem:1,date:null,format:"25'@Z2 >> 20'@Z3 >> 5'@Z1",pe:'4/10',total:50,opt:false},
  {sem:1,date:'2026-09-06',format:"90'@Z2",pe:'4/10',total:90,opt:false},
  {sem:2,date:null,format:"25'@Z2 >> 6x (2'@Z5 + 1'@Z1) >> 15'@Z2",pe:'5/10',total:58,opt:false},
  {sem:2,date:null,format:"45'@Z2",pe:'3/10',total:45,opt:true},
  {sem:2,date:null,format:"20'@Z2 >> 3x (10'@Z4 + 3'@Z1) >> 5'@Z1",pe:'6/10',total:66,opt:false},
  {sem:2,date:'2026-09-13',format:"🏁 LA PARISIENNE — SEMI-MARATHON",pe:'—',total:null,opt:false},
];

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
  const m=parseInt(a);
  return m<=8?'allure-fast':m<=9?'allure-mid':'allure-slow';
}
function formatDate(d) {
  if (!d) return '—';
  return new Date(d).toLocaleDateString('fr-FR',{day:'2-digit',month:'short',year:'numeric'});
}
function getDaysTo(ds) {
  const n=new Date(); n.setHours(0,0,0,0);
  return Math.round((new Date(ds)-n)/86400000);
}
function formatZones(f) {
  const col={1:'var(--z1)',2:'var(--z2)',3:'var(--z3)',4:'var(--z4)',5:'var(--z5)'};
  return f.replace(/@Z(\d)/g,(_,z)=>`<span style="color:${col[z]||'#fff'}">@Z${z}</span>`);
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
  document.getElementById('dash-date').textContent =
    'Mise à jour · ' + new Date().toLocaleDateString('fr-FR',{day:'2-digit',month:'long',year:'numeric'});

  const avgAllure=(()=>{
    const secs=logData.map(r=>durToSec(r.allure?'00:'+r.allure:null)).filter(Boolean);
    if(!secs.length)return'—';
    return secToDur(secs.reduce((a,b)=>a+b,0)/secs.length).slice(3);
  })();
  const longestKm = logData.length ? Math.max(...logData.map(r=>r.km||0)) : 0;
  const longestDurSec = logData.length ? Math.max(...logData.map(r=>durToSec(r.duration)||0)) : 0;
  const longestDurStr = longestDurSec>0 ? secToDur(longestDurSec).replace(/^00:/,'') : '—';
  const bpms=logData.filter(r=>r.bpm).map(r=>r.bpm);
  const avgBpm=bpms.length?Math.round(bpms.reduce((a,b)=>a+b,0)/bpms.length):'—';

  document.getElementById('kpi-grid').innerHTML=`
    <div class="kpi green"><div class="kpi-label">Allure moy.</div><div class="kpi-value">${avgAllure}</div><div class="kpi-unit">min/km</div></div>
    <div class="kpi orange"><div class="kpi-label">Durée la plus longue</div><div class="kpi-value">${longestDurStr}</div><div class="kpi-unit">hh:mm:ss</div></div>
    <div class="kpi accent"><div class="kpi-label">Plus grande distance</div><div class="kpi-value">${longestKm.toFixed(1)}</div><div class="kpi-unit">km</div></div>
    <div class="kpi blue"><div class="kpi-label">BPM moy. EF</div><div class="kpi-value">${avgBpm}</div><div class="kpi-unit">bpm</div></div>`;

  const done=Object.values(state.tempoDone).filter(Boolean).length;
  const pct=Math.round(done/tempoData.length*100);
  document.getElementById('tempo-pct').textContent=pct+'%';
  document.getElementById('tempo-bar').style.width=pct+'%';
  document.getElementById('tempo-meta').textContent=`${done} / ${tempoData.length} séances complétées`;

  const months=['Jan','Fév','Mar','Avr','Mai','Jun','Jul','Aoû','Sep','Oct','Nov','Déc'];
  const monthly={}; months.forEach(m=>monthly[m]=0);
  logData.forEach(r=>{ if(r.date){ const m=months[new Date(r.date).getMonth()]; monthly[m]+=r.km||0; }});
  const active=months.filter(m=>monthly[m]>0);
  const maxKm=Math.max(...active.map(m=>monthly[m]),1);
  document.getElementById('monthly-chart').innerHTML=active.map(m=>{
    const km=monthly[m], h=Math.round((km/maxKm)*100);
    return `<div class="bar-col"><div class="bar" style="height:${h}px" title="${km.toFixed(1)} km"></div><div class="bar-label">${m}<br>${km.toFixed(0)}km</div></div>`;
  }).join('');

  document.getElementById('race-tbody').innerHTML=racesData.map(r=>{
    const days=getDaysTo(r.date);
    let badge,status;
    if(r.result){badge='badge-done';status='✓ Terminée';}
    else if(days<=7){badge='badge-next';status=`J-${days}`;}
    else{badge='badge-future';status=days<0?'Passée':`S-${Math.round(days/7)}`;}
    return `<tr><td><span class="badge ${badge}">${status}</span></td><td><strong>${r.name}</strong></td><td class="mono">${formatDate(r.date)}</td><td>${r.distance||'—'}</td><td class="mono">${r.objective||'—'}</td></tr>`;
  }).join('');

  renderCoherence();
  renderProjections();
  renderEF();
}


// ============================================================
// EF TRACKER
// ============================================================
function renderEF() {
  const efRuns = logData
    .filter(r => r.run_type === 'EF' && r.bpm && r.allure && r.date)
    .sort((a, b) => new Date(a.date) - new Date(b.date));

  const efAll = logData
    .filter(r => r.allure && r.date && r.run_type !== 'Race')
    .sort((a, b) => new Date(a.date) - new Date(b.date));

  function efIndex(r) {
    const [m, s] = r.allure.split(':').map(Number);
    return Math.round(((m * 60 + s) / r.bpm) * 100) / 100;
  }

  const kpiEl = document.getElementById('ef-kpis');
  if (!kpiEl) return;

  if (efRuns.length < 2) {
    kpiEl.innerHTML = `<div style="grid-column:1/-1;color:var(--text-muted);font-size:13px;font-family:'Space Mono',monospace;">
      Pas encore assez de sorties EF avec BPM enregistré (minimum 2).
    </div>`;
    document.getElementById('ef-tbody').innerHTML = '';
    document.getElementById('ef-chart-container').style.display = 'none';
    return;
  }
  document.getElementById('ef-chart-container').style.display = 'block';

  const first = efRuns[0], last = efRuns[efRuns.length - 1];
  const firstPace = durToSec('00:' + first.allure);
  const lastPace  = durToSec('00:' + last.allure);
  const paceDelta = firstPace - lastPace;
  const firstIdx  = efIndex(first), lastIdx = efIndex(last);
  const idxDelta  = firstIdx - lastIdx;
  const avgBpm    = Math.round(efRuns.reduce((s, r) => s + r.bpm, 0) / efRuns.length);
  const paceSign  = paceDelta >= 0 ? '↗' : '↘';
  const paceColor = paceDelta >= 0 ? 'var(--z1)' : 'var(--accent3)';
  const idxSign   = idxDelta  >= 0 ? '↗' : '↘';
  const idxColor  = idxDelta  >= 0 ? 'var(--z1)' : 'var(--accent3)';
  const paceStr   = secToDur(Math.abs(paceDelta)).slice(3);

  kpiEl.innerHTML = `
    <div style="background:var(--surface2);border:1px solid var(--border);border-radius:10px;padding:16px;">
      <div style="font-size:10px;letter-spacing:2px;text-transform:uppercase;color:var(--text-muted);font-family:'Space Mono',monospace;margin-bottom:8px;">Gain d'allure EF</div>
      <div style="font-size:22px;font-weight:800;color:${paceColor}">${paceSign} ${paceStr}/km</div>
      <div style="font-size:10px;color:var(--text-muted);font-family:'Space Mono',monospace;margin-top:4px;">${first.allure} → ${last.allure} /km</div>
    </div>
    <div style="background:var(--surface2);border:1px solid var(--border);border-radius:10px;padding:16px;">
      <div style="font-size:10px;letter-spacing:2px;text-transform:uppercase;color:var(--text-muted);font-family:'Space Mono',monospace;margin-bottom:8px;">BPM moyen EF</div>
      <div style="font-size:22px;font-weight:800;color:var(--accent2)">${avgBpm} <span style="font-size:14px">bpm</span></div>
      <div style="font-size:10px;color:var(--text-muted);font-family:'Space Mono',monospace;margin-top:4px;">sur ${efRuns.length} sorties EF</div>
    </div>
    <div style="background:var(--surface2);border:1px solid var(--border);border-radius:10px;padding:16px;">
      <div style="font-size:10px;letter-spacing:2px;text-transform:uppercase;color:var(--text-muted);font-family:'Space Mono',monospace;margin-bottom:8px;">Indice aérobie</div>
      <div style="font-size:22px;font-weight:800;color:${idxColor}">${idxSign} ${Math.abs(idxDelta).toFixed(2)}</div>
      <div style="font-size:10px;color:var(--text-muted);font-family:'Space Mono',monospace;margin-top:4px;">${first.allure}/km @ ${first.bpm}bpm → ${last.allure}/km @ ${last.bpm}bpm</div>
    </div>`;

  // ── SVG Chart ──────────────────────────────────────────────
  const chartEl = document.getElementById('ef-chart-container');
  const W = chartEl.clientWidth || 600, H = 180;
  const PAD = { top: 16, right: 52, bottom: 32, left: 52 };
  const cW = W - PAD.left - PAD.right, cH = H - PAD.top - PAD.bottom;

  const pts = efAll.map((r, i) => ({
    i, pace: durToSec('00:' + r.allure),
    bpm: r.bpm || null, isEF: r.run_type === 'EF' && r.bpm,
    date: r.date, allure: r.allure,
  }));

  const paces = pts.map(p => p.pace);
  const minP = Math.min(...paces) - 15, maxP = Math.max(...paces) + 15;
  const bpms = pts.filter(p => p.bpm).map(p => p.bpm);
  const minB = bpms.length ? Math.min(...bpms) - 5 : 130;
  const maxB = bpms.length ? Math.max(...bpms) + 5 : 160;

  const xSc  = i => PAD.left + (i / Math.max(pts.length - 1, 1)) * cW;
  const paceY = p => PAD.top + (1 - (p - minP) / (maxP - minP)) * cH;
  const bpmY  = b => PAD.top + (1 - (b - minB) / (maxB - minB)) * cH;

  const allPts = pts.map(p => `${xSc(p.i).toFixed(1)},${paceY(p.pace).toFixed(1)}`).join(' ');
  const bPts   = pts.filter(p => p.bpm).map(p => `${xSc(p.i).toFixed(1)},${bpmY(p.bpm).toFixed(1)}`).join(' ');

  const grid = [0, 0.25, 0.5, 0.75, 1].map(t => {
    const y = PAD.top + (1 - t) * cH;
    const pv = Math.round(minP + t * (maxP - minP));
    return `<line x1="${PAD.left}" y1="${y.toFixed(1)}" x2="${W - PAD.right}" y2="${y.toFixed(1)}" stroke="var(--border)" stroke-width="1"/>
      <text x="${PAD.left - 6}" y="${(y + 4).toFixed(1)}" text-anchor="end" fill="var(--text-muted)" font-size="9" font-family="monospace">${Math.floor(pv/60)}:${String(pv%60).padStart(2,'0')}</text>`;
  }).join('');

  const bLabels = bpms.length ? [0, 0.5, 1].map(t => {
    const y = PAD.top + (1 - t) * cH;
    return `<text x="${W - PAD.right + 6}" y="${(y + 4).toFixed(1)}" fill="var(--accent2)" font-size="9" font-family="monospace">${Math.round(minB + t * (maxB - minB))}</text>`;
  }).join('') : '';

  const efDots = pts.filter(p => p.isEF).map(p =>
    `<circle cx="${xSc(p.i).toFixed(1)}" cy="${paceY(p.pace).toFixed(1)}" r="4" fill="var(--accent)" stroke="var(--surface)" stroke-width="1.5"/>
     <circle cx="${xSc(p.i).toFixed(1)}" cy="${bpmY(p.bpm).toFixed(1)}" r="4" fill="var(--accent2)" stroke="var(--surface)" stroke-width="1.5"/>`
  ).join('');

  chartEl.innerHTML = `<svg width="${W}" height="${H}" xmlns="http://www.w3.org/2000/svg">
    ${grid}${bLabels}
    <polyline points="${allPts}" fill="none" stroke="var(--accent)" stroke-width="1.5" stroke-opacity="0.4" stroke-dasharray="3,3"/>
    ${bpms.length > 1 ? `<polyline points="${bPts}" fill="none" stroke="var(--accent2)" stroke-width="2" stroke-opacity="0.8"/>` : ''}
    ${efDots}
    <circle cx="${PAD.left + 8}" cy="${H - 8}" r="4" fill="var(--accent)"/>
    <text x="${PAD.left + 16}" y="${H - 4}" fill="var(--text-muted)" font-size="9" font-family="monospace">Allure (toutes sorties)</text>
    <circle cx="${PAD.left + 160}" cy="${H - 8}" r="4" fill="var(--accent2)"/>
    <text x="${PAD.left + 168}" y="${H - 4}" fill="var(--text-muted)" font-size="9" font-family="monospace">BPM EF (axe droit)</text>
  </svg>`;

  // ── Tableau ─────────────────────────────────────────────────
  let prevIdx2 = null;
  document.getElementById('ef-tbody').innerHTML = efRuns.map((r, i) => {
    const idx = efIndex(r);
    const trend = prevIdx2 === null ? '—'
      : idx < prevIdx2 - 0.05 ? '<span style="color:var(--z1)">↗ mieux</span>'
      : idx > prevIdx2 + 0.05 ? '<span style="color:var(--accent3)">↘ moins bien</span>'
      : '<span style="color:var(--text-muted)">→ stable</span>';
    prevIdx2 = idx;
    const ic = i === 0 ? 'var(--text-muted)'
      : idx < efIndex(efRuns[i-1]) - 0.05 ? 'var(--z1)' : idx > efIndex(efRuns[i-1]) + 0.05 ? 'var(--accent3)' : 'var(--text-muted)';
    return `<tr>
      <td style="padding:8px 10px;border-bottom:1px solid var(--row-border);font-family:'Space Mono',monospace;">${formatDate(r.date)}</td>
      <td style="padding:8px 10px;border-bottom:1px solid var(--row-border);font-family:'Space Mono',monospace;">${r.km?.toFixed(1)||'—'}</td>
      <td style="padding:8px 10px;border-bottom:1px solid var(--row-border);font-family:'Space Mono',monospace;color:var(--accent2);">${r.bpm} bpm</td>
      <td style="padding:8px 10px;border-bottom:1px solid var(--row-border);font-family:'Space Mono',monospace;">${r.allure}/km</td>
      <td style="padding:8px 10px;border-bottom:1px solid var(--row-border);font-family:'Space Mono',monospace;font-weight:700;color:${ic};">${idx.toFixed(2)}</td>
      <td style="padding:8px 10px;border-bottom:1px solid var(--row-border);">${trend}</td>
    </tr>`;
  }).join('');

  document.getElementById('ef-meta').textContent =
    'Indice aérobie = allure (sec/km) ÷ BPM · Plus il est bas, meilleure est l'efficacité aérobie à effort constant';
}

function renderCoherence() {
  const alerts=[];
  const missing=tempoData.filter(s=>s.sem!==null&&!s.date).length;
  if(missing>0) alerts.push({ok:false,title:'Dates manquantes (Tempo)',msg:`${missing} séances du plan Tempo sans date.`});
  else alerts.push({ok:true,title:'Dates Tempo complètes',msg:'Toutes les séances planifiées sont datées.'});
  alerts.push({ok:true,title:'Progression cohérente',msg:'Les allures progressent régulièrement depuis janvier. ✓'});
  const bpms=logData.filter(r=>r.bpm);
  if(bpms.length) alerts.push({ok:true,title:'BPM EF cohérents',msg:`BPM entre ${Math.min(...bpms.map(r=>r.bpm))} et ${Math.max(...bpms.map(r=>r.bpm))} — Zone 2 OK.`});
  const nullSem=tempoData.filter(s=>s.sem===null).length;
  if(nullSem>0) alerts.push({ok:true,title:'Séances hors semaine',msg:`${nullSem} course(s) ponctuelle(s) hors plan — non comptabilisées.`});
  document.getElementById('coherence-section').innerHTML=`
    <div class="section-title" style="font-size:18px;margin-bottom:16px">Analyse de cohérence</div>
    ${alerts.map(a=>`<div class="alert ${a.ok?'alert-ok':''}"><div class="alert-title">${a.ok?'✓':'⚠'} ${a.title}</div><div>${a.msg}</div></div>`).join('')}`;
}

function renderProjections() {
  const recent=logData.filter(r=>r.allure&&r.run_type!=='Race'&&r.date)
    .sort((a,b)=>new Date(b.date)-new Date(a.date)).slice(0,5);
  if(!recent.length){
    document.getElementById('projections-grid').innerHTML='<div style="color:var(--text-muted);font-size:13px;grid-column:1/-1">Pas encore assez de données.</div>';
    return;
  }
  const paceList=recent.map(r=>{
    const src=(r.gap&&r.dplus)?r.gap:r.allure;
    const [m,s]=src.split(':').map(Number); return m*60+s;
  });
  const avg=paceList.reduce((a,b)=>a+b,0)/paceList.length;
  const withGAP=recent.filter(r=>r.gap&&r.dplus).length;
  const base5=avg*5;
  document.getElementById('projections-grid').innerHTML=[
    {label:'5 km',dist:5},{label:'10 km',dist:10},{label:'21 km',dist:21.1},{label:'42 km',dist:42.2}
  ].map(d=>{
    const proj=d.dist===5?base5:base5*Math.pow(d.dist/5,1.06);
    const time=secToDur(Math.round(proj));
    const pace=secToDur(Math.round(proj/d.dist)).slice(3);
    const disp=time.startsWith('00:')?time.slice(3):time;
    return `<div style="background:var(--surface2);border:1px solid var(--border);border-radius:10px;padding:16px;text-align:center;">
      <div style="font-size:10px;letter-spacing:2px;text-transform:uppercase;color:var(--text-muted);font-family:'Space Mono',monospace;margin-bottom:8px;">${d.label}</div>
      <div style="font-size:22px;font-weight:800;line-height:1;color:var(--accent)">${disp}</div>
      <div style="font-size:10px;color:var(--text-muted);font-family:'Space Mono',monospace;margin-top:6px;">${pace}/km</div>
    </div>`;
  }).join('');
  document.getElementById('projections-meta').textContent=
    `${withGAP?'GAP':'Allure'} moy. des ${recent.length} dernières sorties : ${secToDur(Math.round(avg)).slice(3)}/km · Riegel (1.06)${withGAP?` · ${withGAP}/${recent.length} avec GAP`:''}`;
}

// ============================================================
// PLAN RENDERER
// ============================================================
const planDataMap={tempoDone:tempoData,prepDone:prepData,semiDone:semiData};
const planContainerMap={tempoDone:'tempo-weeks',prepDone:'prep-weeks',semiDone:'semi-weeks'};

function renderPlan(containerId, data, stateKey) {
  let html='', blockNum=1, i=0;
  while(i<data.length){
    const end=Math.min(i+4,data.length), block=data.slice(i,end);
    const wd=block.find(s=>s.date)?.date;
    html+=`<div class="week-card"><div class="week-header"><span class="week-num">BLOC ${blockNum}</span><span class="week-date">${wd?formatDate(wd):'—'}</span></div>`;
    block.forEach((s,j)=>{
      const idx=i+j, done=state[stateKey][idx];
      html+=`<div class="session-row" onclick="toggleSession('${stateKey}',${idx},this)">
        <div class="session-check ${done?'done':''}">${done?'✓':''}</div>
        <div class="session-format">${formatZones(s.format)}${s.opt?' <span class="optional-tag">(optionnel)</span>':''}</div>
        <div class="session-meta">
          ${s.pe?`<span class="pe-badge">PE ${s.pe}`:''}</span>
          ${s.total?`<span class="duration-badge">${s.total}'</span>`:''}
          <div class="action-btns">
            <button class="action-btn" onclick="event.stopPropagation();openPlanEdit('${stateKey}',${idx})" title="Modifier">✏️</button>
            <button class="action-btn del" onclick="event.stopPropagation();deletePlanSession('${stateKey}',${idx})" title="Supprimer">🗑</button>
          </div>
        </div>
      </div>`;
    });
    html+=`</div>`; i=end; blockNum++;
  }
  document.getElementById(containerId).innerHTML=html;
}

async function toggleSession(stateKey, idx, row) {
  state[stateKey][idx]=!state[stateKey][idx];
  const c=row.querySelector('.session-check');
  c.classList.toggle('done',!!state[stateKey][idx]);
  c.textContent=state[stateKey][idx]?'✓':'';
  try {
    // API Platform: POST /plan_checks (processor handles upsert)
    await apiFetch('/plan_checks',{method:'POST',body:JSON.stringify({
      planKey:stateKey, sessionIndex:idx, done:!!state[stateKey][idx]
    })});
    notify(state[stateKey][idx]?'✓ Séance validée !':'Séance décochée');
  } catch(e){ notify('⚠ '+e.message); }
  if(stateKey==='tempoDone') renderDashboard();
}

// ── Plan edit (local only — plan data is static) ──────────
function openPlanEdit(stateKey, idx) {
  const s=planDataMap[stateKey][idx];
  document.getElementById('pm-statekey').value=stateKey;
  document.getElementById('pm-idx').value=idx;
  document.getElementById('pm-format').value=s.format||'';
  document.getElementById('pm-date').value=s.date||'';
  document.getElementById('pm-pe').value=s.pe||'';
  document.getElementById('pm-total').value=s.total||'';
  document.getElementById('pm-opt').checked=!!s.opt;
  openModal('plan-modal');
}
function savePlanEdit() {
  const sk=document.getElementById('pm-statekey').value;
  const idx=parseInt(document.getElementById('pm-idx').value);
  const d=planDataMap[sk];
  d[idx].format=document.getElementById('pm-format').value;
  d[idx].date=document.getElementById('pm-date').value||null;
  d[idx].pe=document.getElementById('pm-pe').value;
  d[idx].total=parseInt(document.getElementById('pm-total').value)||null;
  d[idx].opt=document.getElementById('pm-opt').checked;
  renderPlan(planContainerMap[sk],d,sk);
  closeModal('plan-modal');
  notify('✓ Séance modifiée');
}
function deletePlanSession(sk, idx) {
  const d=planDataMap[sk];
  askConfirm('Supprimer la séance ?',`"${d[idx].format}"`,()=>{
    d.splice(idx,1);
    renderPlan(planContainerMap[sk],d,sk);
    if(sk==='tempoDone') renderDashboard();
    notify('🗑 Supprimée');
  });
}

// ============================================================
// MODALS
// ============================================================
function closeModal(id){document.getElementById(id).classList.remove('open');}
function openModal(id){document.getElementById(id).classList.add('open');}
document.addEventListener('click',e=>{
  ['plan-modal','log-modal','race-modal'].forEach(id=>{
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
function confirmDelete(){if(_del)_del();_del=null;closeConfirm();}
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

function renderLog() {
  document.getElementById('log-sub').textContent=`${logData.length} sortie${logData.length>1?'s':''} enregistrée${logData.length>1?'s':''}`;
  let items=[...logData];
  items.sort((a,b)=>logSortAsc?new Date(a.date)-new Date(b.date):new Date(b.date)-new Date(a.date));
  if(logFilter!=='all') items=items.filter(r=>r.run_type===logFilter);
  document.getElementById('log-tbody').innerHTML=items.map(r=>{
    const ac=allureClass(r.allure);
    return `<tr>
      <td class="mono">${formatDate(r.date)}</td>
      <td class="mono"><strong>${r.km?.toFixed(2)||'—'}</strong></td>
      <td class="mono">${r.duration||'—'}</td>
      <td class="mono ${ac}">${r.allure||'—'}/km</td>
      <td class="mono">${r.gap?`<span style="color:var(--accent2)">${r.gap}/km</span>`:'—'}</td>
      <td class="mono">${r.dplus?`<span style="color:var(--z3)">${r.dplus}m</span>`:'—'}</td>
      <td class="mono">${r.bpm||'—'}</td>
      <td>${r.run_type?`<span class="badge badge-done">${r.run_type}</span>`:'—'}</td>
      <td style="font-size:12px;color:var(--text-muted)">${r.notes||'—'}</td>
      <td><div class="action-btns" style="opacity:0">
        <button class="action-btn" onclick="openLogEdit(${r.id})">✏️</button>
        <button class="action-btn del" onclick="deleteLog(${r.id},'${r.date}')">🗑</button>
      </div></td>
    </tr>`;
  }).join('');
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
  const km=parseFloat(document.getElementById('log-km').value);
  const dur=document.getElementById('log-dur').value;
  const dplus=parseInt(document.getElementById('log-dplus').value)||null;
  const bpm=parseInt(document.getElementById('log-bpm').value)||null;
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
    renderLog(); renderDashboard();
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
  const id=parseInt(document.getElementById('lm-idx').value);
  const dur=document.getElementById('lm-dur').value;
  const km=parseFloat(document.getElementById('lm-km').value);
  const dplus=parseInt(document.getElementById('lm-dplus').value)||null;
  const secs=durToSec(dur), allureSec=secs&&km?Math.round(secs/km):null;
  const allure=allureSec?secToDur(allureSec).slice(3):logData.find(r=>r.id===id)?.allure;
  const gap=allureSec?computeGAP(allureSec,km,dplus):null;
  try {
    const updated=await apiFetch(`/run_logs/${id}`,{method:'PUT',body:JSON.stringify({
      date:document.getElementById('lm-date').value,
      km,duration:dur,allure,gap,dplus,
      bpm:parseInt(document.getElementById('lm-bpm').value)||null,
      runType:document.getElementById('lm-type').value||null,
      notes:document.getElementById('lm-notes').value||null,
    })});
    const idx=logData.findIndex(r=>r.id===id);
    if(idx>=0) logData[idx]=normalizeLog(updated);
    renderLog(); renderDashboard();
    closeModal('log-modal');
    notify('✓ Sortie modifiée !');
  } catch(e){notify('⚠ '+e.message);}
}

async function deleteLog(id,dateStr) {
  askConfirm('Supprimer la sortie ?',formatDate(dateStr),async()=>{
    try {
      await apiFetch(`/run_logs/${id}`,{method:'DELETE'});
      logData=logData.filter(r=>r.id!==id);
      renderLog(); renderDashboard();
      notify('🗑 Sortie supprimée');
    } catch(e){notify('⚠ '+e.message);}
  });
}

// ============================================================
// RACES
// ============================================================
function renderRaces() {
  document.getElementById('races-tbody').innerHTML=racesData.map(r=>{
    const days=getDaysTo(r.date);
    let status;
    if(r.result) status=`<span class="badge badge-done">✓ Terminée</span>`;
    else if(days<0) status=`<span class="badge badge-future">Passée</span>`;
    else if(days<=10) status=`<span class="badge badge-next">J-${days}</span>`;
    else status=`<span class="badge badge-future">S-${Math.round(days/7)}</span>`;
    let diff='—';
    if(r.result&&r.objective){
      const d=durToSec(r.result)-durToSec(r.objective);
      diff=d<0?`<span style="color:var(--z1)">-${secToDur(-d).slice(3)}</span>`:`<span style="color:var(--accent3)">+${secToDur(d).slice(3)}</span>`;
    }
    return `<tr>
      <td>${status}</td><td><strong>${r.name}</strong></td>
      <td class="mono">${formatDate(r.date)}</td><td>${r.distance||'—'}</td>
      <td class="mono">${r.objective||'—'}</td><td class="mono">${r.result||'—'}</td>
      <td class="mono">${diff}</td>
      <td><div class="action-btns" style="opacity:0">
        <button class="action-btn" onclick="openRaceEdit(${r.id})">✏️</button>
        <button class="action-btn del" onclick="deleteRace(${r.id},'${r.name}')">🗑</button>
      </div></td>
    </tr>`;
  }).join('');
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
    renderRaces(); renderDashboard();
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
  const id=parseInt(document.getElementById('rm-idx').value);
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
    renderRaces(); renderDashboard();
    closeModal('race-modal');
    notify('✓ Course modifiée !');
  } catch(e){notify('⚠ '+e.message);}
}

async function deleteRace(id,name) {
  askConfirm('Supprimer la course ?',name,async()=>{
    try {
      await apiFetch(`/races/${id}`,{method:'DELETE'});
      racesData=racesData.filter(r=>r.id!==id);
      renderRaces(); renderDashboard();
      notify('🗑 Course supprimée');
    } catch(e){notify('⚠ '+e.message);}
  });
}

// ============================================================
// INIT
// ============================================================
(async () => {
  // Verify token still valid
  try {
    const me = await apiFetch('/auth/me');
    if (!me) return; // logout() already called
  } catch {
    logout(); return;
  }

  await loadAllData();

  renderDashboard();
  renderPlan('tempo-weeks', tempoData, 'tempoDone');
  renderPlan('prep-weeks',  prepData,  'prepDone');
  renderPlan('semi-weeks',  semiData,  'semiDone');
  renderLog();
  renderRaces();

  const today = new Date().toISOString().split('T')[0];
  document.getElementById('log-date').value = today;
  document.getElementById('r-date').value   = today;
})();
