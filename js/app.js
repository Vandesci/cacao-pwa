// ═══════════════════════════════════════════
// APP.JS — Cacao Data Collector PWA
// ═══════════════════════════════════════════
// Détecte automatiquement le chemin de base
function getBase() {
  const path = window.location.pathname;
  // Sur Railway: pas de sous-dossier, BASE = ''
  // Sur Laragon: sous-dossier cacao-pwa, BASE = '/cacao-pwa'
  const match = path.match(/^(.*\/cacao-pwa)/);
  return match ? match[1] : '';
}
const BASE = getBase();
const API  = BASE + '/api';

let USER = null;
let COOPS = [];
let PRODS = [];
let currentFicheTab = 'profilage';
let fpStep = 1;
let childCount = 0;

// ── INIT ─────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', async () => {
  await localDB.open();

  // Register SW
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register(BASE + '/sw.js')
      .then(r => console.log('SW registered'))
      .catch(e => console.log('SW error', e));
  }

  // Online/offline listeners
  window.addEventListener('online',  () => { updateOnline(true);  syncNow(); });
  window.addEventListener('offline', () => updateOnline(false));
  updateOnline(navigator.onLine);

  syncMgr.on('sync-done', d => {
    if (d.synced > 0) toast(`${d.synced} fiche(s) synchronisée(s) ✓`, 'success');
    updatePendingBadge();
  });

  // Splash then check session
  setTimeout(async () => {
    try {
      const session = await localDB.getSession();
      if (session) {
        USER = session;
        hideSplash();
        initApp();
      } else {
        hideSplash();
        showLogin();
      }
    } catch(e) {
      // En cas d'erreur, aller au login
      hideSplash();
      showLogin();
    }
  }, 1500);
});

function hideSplash() {
  const s = document.getElementById('splash');
  s.style.opacity = '0';
  setTimeout(() => s.style.display = 'none', 400);
}
function showLogin() {
  document.getElementById('loginScreen').classList.add('show');
}

// ── AUTH ─────────────────────────────────────────────────────
async function doLogin() {
  const email    = document.getElementById('loginEmail').value.trim();
  const password = document.getElementById('loginPassword').value;
  const errEl    = document.getElementById('loginErr');
  const errMsg   = document.getElementById('loginErrMsg');
  const btn      = document.getElementById('loginBtn');
  const btnTxt   = document.getElementById('loginBtnText');

  errEl.style.display = 'none';
  if (!email || !password) {
    errMsg.textContent = 'Veuillez remplir tous les champs';
    errEl.style.display = 'flex'; return;
  }

  btnTxt.innerHTML = '<span class="spinner"></span>';
  btn.disabled = true;

  try {
    const res  = await apiFetch('/auth/login', 'POST', { email, password });
    USER = res.user;
    await localDB.saveSession(USER);
    document.getElementById('loginScreen').classList.remove('show');
    initApp();
  } catch (e) {
    errMsg.textContent = e.message || 'Identifiants incorrects';
    errEl.style.display = 'flex';
  } finally {
    btnTxt.textContent = 'Se connecter';
    btn.disabled = false;
  }
}

async function doLogout() {
  if (!confirm('Se déconnecter?')) return;
  await localDB.clearSession();
  try { await apiFetch('/auth/logout', 'POST', {}); } catch {}
  location.reload();
}

// ── APP INIT ─────────────────────────────────────────────────
function initApp() {
  document.getElementById('app').classList.add('show');
  const av = (USER.prenom[0] || '') + (USER.nom[0] || '');
  document.getElementById('homeAvatar').textContent = av;
  document.getElementById('welcomeName').textContent = USER.prenom + ' ' + USER.nom;
  document.getElementById('profileAvatar').textContent = av;
  document.getElementById('profileName').textContent   = USER.prenom + ' ' + USER.nom;
  document.getElementById('profileRole').textContent   = USER.role === 'admin' ? '👑 Administrateur' : '🔍 Inspecteur';
  if (USER.code_inspecteur) document.getElementById('profileCode').textContent = USER.code_inspecteur;

  // Role-based UI
  if (USER.role === 'superadmin') {
    // Superadmin interface
    document.getElementById('adminStats').style.display   = 'none';
    document.getElementById('inspStats').style.display    = 'none';
    document.getElementById('nav-home').style.display     = 'none';
    document.getElementById('nav-collect').style.display  = 'none';
    document.getElementById('nav-manage').style.display   = 'none';
    document.getElementById('nav-fiches').style.display   = 'none';
    document.getElementById('nav-profile').style.display  = 'none';
    // Show superadmin nav buttons
    ['nav-sa-home','nav-sa-requests','nav-sa-coops'].forEach(id => {
      const el = document.getElementById(id);
      if (el) el.style.display = 'flex';
    });
    showPage('sa-home');
    loadSADashboard();
  } else if (USER.role === 'admin') {
    document.getElementById('adminStats').style.display       = 'block';
    document.getElementById('inspStats').style.display        = 'none';
    document.getElementById('nav-home').style.display         = 'none';
    document.getElementById('nav-collect').style.display      = 'none';
    document.getElementById('nav-manage').style.display       = 'flex';
    document.getElementById('nav-coop-dashboard').style.display = 'flex';
    document.getElementById('ficheAdminFilter').style.display = 'block';
    document.getElementById('fichesPageTitle').textContent    = 'Fiches de ma coopérative';
    showPage('coop-dashboard');
    loadCoopDashboard();
  } else {
    // Inspecteur
    document.getElementById('adminStats').style.display          = 'none';
    document.getElementById('inspStats').style.display           = 'block';
    document.getElementById('nav-home').style.display            = 'none';
    document.getElementById('nav-collect').style.display         = 'flex';
    document.getElementById('nav-manage').style.display          = 'none';
    document.getElementById('nav-insp-dashboard').style.display  = 'flex';
    document.getElementById('fichesPageTitle').textContent       = 'Mes fiches';
    showPage('insp-dashboard');
    loadInspDashboard();
  }

  document.getElementById('aiFab').style.display = 'flex';
  loadCoops();
  loadProds();
  loadNotifications();
  updatePendingBadge();
}

// ── NAVIGATION ────────────────────────────────────────────────
const PAGE_TITLES = {
  home: 'Cacao App', collect: 'Collecter', fiches: 'Fiches', manage: 'Gestion', profile: 'Profil'
};

function showPage(id) {
  document.querySelectorAll('.page').forEach(p => p.classList.remove('show'));
  document.querySelectorAll('.nav-btn').forEach(b => b.classList.remove('active'));
  const page = document.getElementById('page-' + id);
  const nav  = document.getElementById('nav-' + id);
  if (page) page.classList.add('show');
  if (nav)  nav.classList.add('active');
  document.getElementById('topbarTitle').textContent = PAGE_TITLES[id] || 'Cacao App';
  // Scroll to top
  document.getElementById('appBody').scrollTo(0, 0);
  // Load data
  if (id === 'sa-home')        { loadSADashboard(); }
  if (id === 'sa-requests')    { loadRequests(currentReqFilter, true); }
  if (id === 'sa-coops')       { loadSACoops(); }
  if (id === 'coop-dashboard') { loadCoopDashboard(); }
  if (id === 'insp-dashboard') { loadInspDashboard(); }
  if (id === 'home')           { USER.role==='admin' ? loadDashboard() : loadMyFiches(); }
  if (id === 'fiches')  { loadCurrentFiches(); }
  if (id === 'manage')  { /* loaded on demand */ }
  if (id === 'profile') { updatePendingBadge(); }
}

// ── ONLINE STATUS ─────────────────────────────────────────────
function updateOnline(online) {
  document.getElementById('offlineStrip').classList.toggle('show', !online);
  document.getElementById('offlineAlert').style.display = !online ? 'flex' : 'none';
  document.getElementById('syncDot').className = 'sync-dot' + (online ? '' : ' offline');
  document.getElementById('syncLabel').textContent = online ? 'En ligne' : 'Hors ligne';
}

async function updatePendingBadge() {
  const n = await localDB.getPendingCount();
  const badge = document.getElementById('navBadge');
  badge.style.display = n > 0 ? 'flex' : 'none';
  badge.textContent = n;
  document.getElementById('pendingCount').textContent = n;
  document.getElementById('profilePending').textContent = n > 0 ? `${n} fiche(s) en attente de sync` : 'Tout est synchronisé ✓';
}

async function syncNow() { await syncMgr.sync(); updatePendingBadge(); }
async function triggerSync() { if (navigator.onLine) { toast('Synchronisation...', 'info'); await syncNow(); } else { toast('Hors ligne', 'warning'); } }

// ── DASHBOARD ────────────────────────────────────────────────
async function loadDashboard() {
  if (USER.role !== 'admin') return;
  try {
    const res = await apiFetch('/stats');
    const s   = res.stats;
    document.getElementById('s-coops').textContent   = s.cooperatives;
    document.getElementById('s-insp').textContent    = s.inspecteurs;
    document.getElementById('s-prod').textContent    = s.producteurs;
    const totalPending = parseInt(s.fiches_profilage.soumis) + parseInt(s.fiches_arbres.soumis) + parseInt(s.fiches_engrais.soumis);
    document.getElementById('s-pending').textContent = totalPending;
    if (totalPending > 0) {
      document.getElementById('navBadge').style.display = 'flex';
      document.getElementById('navBadge').textContent   = totalPending;
    }
    // Load pending fiches list
    await loadPendingFiches();
  } catch (e) { console.error(e); }
}

async function loadPendingFiches() {
  const container = document.getElementById('pendingFichesList');
  try {
    const [p, a, e] = await Promise.all([
      apiFetch('/fiches-profilage?statut=soumis'),
      apiFetch('/fiches-arbres'),
      apiFetch('/fiches-engrais'),
    ]);
    const rows = [];
    (p.data||[]).filter(f=>f.statut==='soumis').forEach(f => rows.push({type:'Profilage',emoji:'👨‍👩‍👧',id:f.id,prod:f.prod_nom,insp:`${f.insp_prenom||''} ${f.insp_nom||''}`,date:f.date_profilage,t:'profilage'}));
    (a.data||[]).filter(f=>f.statut==='soumis').forEach(f => rows.push({type:'Arbres',emoji:'🌳',id:f.id,prod:f.prod_nom,insp:f.insp_nom,date:f.date_collecte,t:'arbres'}));
    (e.data||[]).filter(f=>f.statut==='soumis').forEach(f => rows.push({type:'Engrais',emoji:'🧪',id:f.id,prod:f.prod_nom,insp:f.insp_nom,date:f.date_collecte,t:'engrais'}));
    if (rows.length === 0) {
      container.innerHTML = `<div class="empty-state"><div class="es-icon">✅</div><div class="es-title">Aucune fiche en attente</div></div>`;
    } else {
      container.innerHTML = rows.map(r => `
        <div class="list-item" onclick="openValidSheet(${r.id},'${r.t}')">
          <div class="li-icon" style="background:var(--a100)">${r.emoji}</div>
          <div class="li-body">
            <div class="li-title">${r.prod||'Producteur'}</div>
            <div class="li-sub">${r.type} • ${r.insp||''} • ${r.date}</div>
          </div>
          <div class="li-right">
            <span class="badge badge-amber">En attente</span>
          </div>
        </div>`).join('');
    }
  } catch (e) { container.innerHTML = `<div class="empty-state"><div class="es-icon">⚠️</div><div class="es-title">Erreur de chargement</div></div>`; }
}

async function loadMyFiches() {
  const container = document.getElementById('myFichesList');
  try {
    const res = await apiFetch('/fiches-profilage');
    const data = (res.data||[]).slice(0,5);
    if (!data.length) {
      container.innerHTML = `<div class="empty-state"><div class="es-icon">📝</div><div class="es-title">Aucune fiche soumise</div><div class="es-sub">Commencez une nouvelle collecte</div></div>`;
    } else {
      container.innerHTML = data.map(f => `
        <div class="list-item">
          <div class="li-icon" style="background:var(--g100)">👨‍👩‍👧</div>
          <div class="li-body">
            <div class="li-title">${f.prod_nom||'Producteur'}</div>
            <div class="li-sub">${f.date_profilage} • ${f.nom_communaute||''}</div>
          </div>
          <div class="li-right">${statusBadge(f.statut)}</div>
        </div>`).join('');
    }
  } catch {}
}

// ── FICHES LIST ───────────────────────────────────────────────
function switchFicheTab(tab, btn) {
  currentFicheTab = tab;
  document.querySelectorAll('[id^="ftab-"]').forEach(b => {
    b.className = 'btn btn-sm btn-secondary';
  });
  btn.className = 'btn btn-sm btn-primary';
  loadCurrentFiches();
}

async function loadCurrentFiches() {
  const container = document.getElementById('fichesListContainer');
  const statut    = document.getElementById('ficheStatutFilter')?.value || '';
  const eps = { profilage: '/fiches-profilage', arbres: '/fiches-arbres', engrais: '/fiches-engrais' };
  const ep  = eps[currentFicheTab] + (statut ? `?statut=${statut}` : '');
  container.innerHTML = `<div class="empty-state"><div class="es-icon">⏳</div><div class="es-title">Chargement...</div></div>`;
  try {
    const res  = await apiFetch(ep);
    const data = res.data || [];
    if (!data.length) {
      container.innerHTML = `<div class="empty-state"><div class="es-icon">📄</div><div class="es-title">Aucune fiche</div></div>`;
      return;
    }
    const emojis = { profilage:'👨‍👩‍👧', arbres:'🌳', engrais:'🧪' };
    container.innerHTML = data.map(f => {
      const date = f.date_profilage || f.date_collecte || '';
      const insp = (f.insp_prenom ? f.insp_prenom + ' ' : '') + (f.insp_nom || '');
      const canValidate = USER.role === 'admin' && f.statut === 'soumis';
      return `<div class="list-item" ${canValidate ? `onclick="openValidSheet(${f.id},'${currentFicheTab}')"` : ''}>
        <div class="li-icon" style="background:var(--g50)">${emojis[currentFicheTab]}</div>
        <div class="li-body">
          <div class="li-title">${f.prod_nom||'Producteur'}</div>
          <div class="li-sub">${date}${insp?' • '+insp:''}</div>
        </div>
        <div class="li-right">${statusBadge(f.statut)}${canValidate?'<div class="text-xs" style="color:var(--b500);margin-top:2px">Appuyer pour valider</div>':''}</div>
      </div>`;
    }).join('');
  } catch (e) {
    container.innerHTML = `<div class="empty-state"><div class="es-icon">⚠️</div><div class="es-title">Erreur: ${e.message}</div></div>`;
  }
}

// ── SHEETS MANAGEMENT ────────────────────────────────────────
function openSheet(name) {
  document.getElementById(name + 'Overlay').classList.add('open');
  document.getElementById(name + 'Sheet').classList.add('open');
}
function closeSheet(name) {
  document.getElementById(name + 'Overlay').classList.remove('open');
  document.getElementById(name + 'Sheet').classList.remove('open');
}

// ── COOPS ─────────────────────────────────────────────────────
async function loadCoops() {
  try {
    const res = await apiFetch('/cooperatives');
    COOPS = res.data || [];
    await localDB.cacheCooperatives(COOPS);
    populateCoopSelects();
  } catch {
    COOPS = await localDB.getAll('cooperatives') || [];
    populateCoopSelects();
  }
}

function populateCoopSelects() {
  const opts = COOPS.map(c => `<option value="${c.id}">${c.nom}</option>`).join('');
  ['fp-coop','fa-coop','fe-coop','insp-coop','prod-coop'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.innerHTML = '<option value="">Sélectionner...</option>' + opts;
  });
}

function openCoopsSheet() {
  const body = document.getElementById('coopsSheetBody');
  if (!COOPS.length) {
    body.innerHTML = `<div class="empty-state"><div class="es-icon">🏠</div><div class="es-title">Aucune coopérative</div></div>`;
  } else {
    body.innerHTML = COOPS.map(c => `
      <div class="list-item">
        <div class="li-icon" style="background:var(--g100)">🏠</div>
        <div class="li-body">
          <div class="li-title">${c.nom}</div>
          <div class="li-sub">${c.localite||''} • ${c.code||''}</div>
        </div>
        <div class="li-right">
          <span class="text-xs text-muted">${c.nb_inspecteurs||0} insp.</span>
        </div>
      </div>`).join('');
  }
  openSheet('coops');
}

function showAddCoopForm() {
  const body = document.getElementById('coopsSheetBody');
  body.innerHTML = `
    <div class="form-group"><label class="form-label">Nom <span class="req">*</span></label>
      <input type="text" class="form-input" id="coopNom" placeholder="Nom de la coopérative"></div>
    <div class="form-group"><label class="form-label">Localité</label>
      <input type="text" class="form-input" id="coopLoc" placeholder="Ville / Village"></div>
    <button class="btn btn-primary btn-block mt-2" onclick="saveCoop()">Enregistrer</button>
    <button class="btn btn-ghost btn-block mt-2" onclick="openCoopsSheet()">Annuler</button>`;
}

async function saveCoop() {
  const nom = document.getElementById('coopNom').value.trim();
  if (!nom) return toast('Nom requis', 'error');
  try {
    await apiFetch('/cooperatives', 'POST', { nom, localite: document.getElementById('coopLoc').value });
    closeSheet('coops');
    await loadCoops();
    toast('Coopérative créée ✓', 'success');
  } catch (e) { toast(e.message, 'error'); }
}

// ── INSPECTEURS ───────────────────────────────────────────────
async function openInspeSheet() {
  const body = document.getElementById('inspeSheetBody');
  body.innerHTML = `<div class="empty-state"><div class="es-icon">⏳</div><div class="es-title">Chargement...</div></div>`;
  openSheet('inspe');
  try {
    const res  = await apiFetch('/users');
    const data = res.data || [];
    body.innerHTML = data.length ? data.map(u => `
      <div class="list-item">
        <div class="li-icon" style="background:var(--b100)">👤</div>
        <div class="li-body">
          <div class="li-title">${u.prenom} ${u.nom}</div>
          <div class="li-sub">${u.email} • ${u.coop_nom||''}</div>
        </div>
        <div class="li-right">
          ${u.actif ? '<span class="badge badge-green">Actif</span>' : '<span class="badge badge-red">Inactif</span>'}
          <span class="text-xs" style="color:var(--gray400);margin-top:2px">${u.code_inspecteur||''}</span>
        </div>
      </div>`).join('') : `<div class="empty-state"><div class="es-icon">👤</div><div class="es-title">Aucun inspecteur</div></div>`;
  } catch (e) { body.innerHTML = `<div class="empty-state"><div class="es-icon">⚠️</div><div class="es-title">${e.message}</div></div>`; }
}

function showAddInspeForm() {
  const body = document.getElementById('inspeSheetBody');
  const coopOpts = COOPS.map(c => `<option value="${c.id}">${c.nom}</option>`).join('');
  body.innerHTML = `
    <div class="form-row">
      <div class="form-group"><label class="form-label">Nom <span class="req">*</span></label><input type="text" class="form-input" id="iNom"></div>
      <div class="form-group"><label class="form-label">Prénom <span class="req">*</span></label><input type="text" class="form-input" id="iPrenom"></div>
    </div>
    <div class="form-group"><label class="form-label">Email <span class="req">*</span></label><input type="email" class="form-input" id="iEmail" inputmode="email"></div>
    <div class="form-group"><label class="form-label">Mot de passe <span class="req">*</span></label><input type="password" class="form-input" id="iPass" placeholder="Minimum 6 caractères"></div>
    <div class="form-group"><label class="form-label">Coopérative <span class="req">*</span></label>
      <select class="form-select" id="iCoop"><option value="">Sélectionner...</option>${coopOpts}</select></div>
    <button class="btn btn-primary btn-block mt-2" onclick="saveInsp()">Créer le compte</button>
    <button class="btn btn-ghost btn-block mt-2" onclick="openInspeSheet()">Annuler</button>`;
}

async function saveInsp() {
  const body = {
    nom: document.getElementById('iNom').value.trim(),
    prenom: document.getElementById('iPrenom').value.trim(),
    email: document.getElementById('iEmail').value.trim(),
    password: document.getElementById('iPass').value,
    cooperative_id: document.getElementById('iCoop').value,
  };
  if (!body.nom || !body.prenom || !body.email || !body.password || !body.cooperative_id)
    return toast('Tous les champs sont requis', 'error');
  try {
    const res = await apiFetch('/users', 'POST', body);
    closeSheet('inspe');
    toast(`Inspecteur créé! Code: ${res.code}`, 'success');
  } catch (e) { toast(e.message, 'error'); }
}

// ── PRODUCTEURS ───────────────────────────────────────────────
async function openProducSheet() {
  const body = document.getElementById('producSheetBody');
  body.innerHTML = `<div class="empty-state"><div class="es-icon">⏳</div><div class="es-title">Chargement...</div></div>`;
  openSheet('produc');
  try {
    const res  = await apiFetch('/producteurs');
    PRODS = res.data || [];
    await localDB.cacheProducteurs(PRODS);
    populateProdSelects();
    body.innerHTML = PRODS.length ? PRODS.map(p => `
      <div class="list-item">
        <div class="li-icon" style="background:var(--a100)">🌱</div>
        <div class="li-body">
          <div class="li-title">${p.nom} ${p.prenom||''}</div>
          <div class="li-sub">${p.code} • ${p.genre} • ${p.coop_nom||''}</div>
        </div>
        <div class="li-right"><span class="text-xs text-muted">${p.superficie_certifiee||0} ha</span></div>
      </div>`).join('') : `<div class="empty-state"><div class="es-icon">🌱</div><div class="es-title">Aucun producteur</div></div>`;
  } catch (e) { body.innerHTML = `<div class="empty-state"><div class="es-icon">⚠️</div><div class="es-title">${e.message}</div></div>`; }
}

async function loadProds() {
  try {
    const res = await apiFetch('/producteurs');
    PRODS = res.data || [];
    await localDB.cacheProducteurs(PRODS);
    populateProdSelects();
  } catch {
    PRODS = await localDB.getAll('producteurs') || [];
    populateProdSelects();
  }
}

function populateProdSelects() {
  const opts = PRODS.map(p => `<option value="${p.id}">${p.nom} ${p.prenom||''} (${p.code})</option>`).join('');
  ['fp-prod','fa-prod','fe-prod'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.innerHTML = '<option value="">Sélectionner...</option>' + opts;
  });
}

function showAddProducForm() {
  const body = document.getElementById('producSheetBody');
  const coopOpts = COOPS.map(c => `<option value="${c.id}">${c.nom}</option>`).join('');
  body.innerHTML = `
    <div class="form-row">
      <div class="form-group"><label class="form-label">Nom <span class="req">*</span></label><input type="text" class="form-input" id="pNom"></div>
      <div class="form-group"><label class="form-label">Prénom</label><input type="text" class="form-input" id="pPrenom"></div>
    </div>
    <div class="form-row">
      <div class="form-group"><label class="form-label">Genre <span class="req">*</span></label>
        <select class="form-select" id="pGenre"><option value="">...</option><option>Homme</option><option>Femme</option></select></div>
      <div class="form-group"><label class="form-label">Âge</label><input type="number" class="form-input" id="pAge" min="18" inputmode="numeric"></div>
    </div>
    <div class="form-group"><label class="form-label">Coopérative <span class="req">*</span></label>
      <select class="form-select" id="pCoop"><option value="">Sélectionner...</option>${coopOpts}</select></div>
    <div class="form-row">
      <div class="form-group"><label class="form-label">Section</label><input type="text" class="form-input" id="pSection"></div>
      <div class="form-group"><label class="form-label">Localité</label><input type="text" class="form-input" id="pLoc"></div>
    </div>
    <div class="form-row">
      <div class="form-group"><label class="form-label">Nb plantations</label><input type="number" class="form-input" id="pPlant" min="0" inputmode="numeric"></div>
      <div class="form-group"><label class="form-label">Superficie (ha)</label><input type="number" class="form-input" id="pSup" step="0.01" inputmode="decimal"></div>
    </div>
    <button class="btn btn-primary btn-block mt-2" onclick="saveProd()">Enregistrer</button>
    <button class="btn btn-ghost btn-block mt-2" onclick="openProducSheet()">Annuler</button>`;
}

async function saveProd() {
  const body = {
    nom: document.getElementById('pNom').value.trim(),
    prenom: document.getElementById('pPrenom').value.trim(),
    genre: document.getElementById('pGenre').value,
    age: document.getElementById('pAge').value || null,
    cooperative_id: document.getElementById('pCoop').value,
    section: document.getElementById('pSection').value,
    localite: document.getElementById('pLoc').value,
    nb_plantations: document.getElementById('pPlant').value || 0,
    superficie_certifiee: document.getElementById('pSup').value || 0,
  };
  if (!body.nom || !body.genre || !body.cooperative_id) return toast('Champs requis manquants', 'error');
  try {
    await apiFetch('/producteurs', 'POST', body);
    closeSheet('produc');
    await loadProds();
    toast('Producteur enregistré ✓', 'success');
  } catch (e) { toast(e.message, 'error'); }
}

// ── FICHE PROFILAGE ───────────────────────────────────────────
const TRAVAUX_LIST = [
  {k:'A',v:'Ramasser cabosses',d:false},
  {k:'B',v:'Trier/étaler fèves',d:false},
  {k:'C',v:'Remplir sachets pépinières',d:false},
  {k:'D',v:'Tenir sacs',d:false},
  {k:'E',v:'Déposer boutures',d:false},
  {k:'F',v:'Extraire fèves',d:false},
  {k:'G',v:'Arroser/désherber',d:false},
  {k:'H',v:'Défrichage',d:false},
  {k:'I',v:'⚠️ Écabossage machette',d:true},
  {k:'J',v:'⚠️ Récolte machette',d:true},
  {k:'K',v:'⚠️ Charges lourdes',d:true},
  {k:'L',v:'⚠️ Abattage arbres',d:true},
  {k:'M',v:'⚠️ Brûlage parcelles',d:true},
  {k:'N',v:'⚠️ Produits chimiques',d:true},
  {k:'O',v:'⚠️ Bûcheronnage',d:true},
  {k:'U',v:'⚠️ Travail de nuit',d:true},
];

function openProfilageSheet() {
  fpStep = 1;
  childCount = 0;
  renderFpStep();
  openSheet('profilage');
}

function renderFpStep() {
  const body   = document.getElementById('profilageSheetBody');
  const footer = document.getElementById('profilageSheetFooter');
  const steps  = `
    <div class="steps">
      <div class="step-item"><div class="step-circle ${fpStep>=1?(fpStep>1?'done':'active'):''}">1</div><span class="step-label ${fpStep===1?'active':''}">Général</span></div>
      <div class="step-line ${fpStep>1?'done':''}"></div>
      <div class="step-item"><div class="step-circle ${fpStep>=2?(fpStep>2?'done':'active'):''}">2</div><span class="step-label ${fpStep===2?'active':''}">Ménage</span></div>
      <div class="step-line ${fpStep>2?'done':''}"></div>
      <div class="step-item"><div class="step-circle ${fpStep>=3?'active':''}">3</div><span class="step-label ${fpStep===3?'active':''}">Enfants</span></div>
    </div>`;

  if (fpStep === 1) {
    const coopOpts = COOPS.map(c => `<option value="${c.id}">${c.nom}</option>`).join('');
    const prodOpts = PRODS.map(p => `<option value="${p.id}">${p.nom} ${p.prenom||''}</option>`).join('');
    body.innerHTML = steps + `
      <div class="form-group"><label class="form-label">Date du profilage <span class="req">*</span></label>
        <input type="date" class="form-input" id="fp-date" value="${today()}"></div>
      <div class="form-group"><label class="form-label">Producteur <span class="req">*</span></label>
        <select class="form-select" id="fp-prod"><option value="">Sélectionner...</option>${prodOpts}</select></div>
      <div class="form-group"><label class="form-label">Coopérative</label>
        <select class="form-select" id="fp-coop"><option value="">Sélectionner...</option>${coopOpts}</select></div>
      <div class="form-group"><label class="form-label">Communauté</label>
        <input type="text" class="form-input" id="fp-comm" placeholder="Nom du village / communauté"></div>`;
    footer.innerHTML = `
      <button class="btn btn-secondary w-full" onclick="closeSheet('profilage')">Annuler</button>
      <button class="btn btn-primary w-full" onclick="fpNext()">Suivant →</button>`;
  }

  else if (fpStep === 2) {
    body.innerHTML = steps + `
      <div style="font-size:14px;font-weight:700;color:var(--gray700);margin-bottom:12px">Membres du ménage</div>
      <div class="form-row-3">
        <div class="form-group"><label class="form-label">Hommes</label><input type="number" class="form-input" id="fp-mh" value="0" min="0" inputmode="numeric" oninput="calcT('fp-mh','fp-mf','fp-mt')"></div>
        <div class="form-group"><label class="form-label">Femmes</label><input type="number" class="form-input" id="fp-mf" value="0" min="0" inputmode="numeric" oninput="calcT('fp-mh','fp-mf','fp-mt')"></div>
        <div class="form-group"><label class="form-label">Total</label><input type="number" class="form-input" id="fp-mt" readonly style="background:var(--gray100)" value="0"></div>
      </div>
      <div style="font-size:14px;font-weight:700;color:var(--gray700);margin:16px 0 12px">Travailleurs embauchés</div>
      <div class="form-row-3">
        <div class="form-group"><label class="form-label">Hommes</label><input type="number" class="form-input" id="fp-th" value="0" min="0" inputmode="numeric" oninput="calcT('fp-th','fp-tf','fp-tt')"></div>
        <div class="form-group"><label class="form-label">Femmes</label><input type="number" class="form-input" id="fp-tf" value="0" min="0" inputmode="numeric" oninput="calcT('fp-th','fp-tf','fp-tt')"></div>
        <div class="form-group"><label class="form-label">Total</label><input type="number" class="form-input" id="fp-tt" readonly style="background:var(--gray100)" value="0"></div>
      </div>`;
    footer.innerHTML = `
      <button class="btn btn-secondary w-full" onclick="fpPrev()">← Retour</button>
      <button class="btn btn-primary w-full" onclick="fpNext()">Suivant →</button>`;
  }

  else if (fpStep === 3) {
    body.innerHTML = steps + `
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
        <div style="font-size:14px;font-weight:700;color:var(--gray700)">Enfants (0–17 ans)</div>
        <button class="btn btn-outline btn-sm" onclick="addChild()" ${childCount>=5?'disabled':''}>+ Ajouter</button>
      </div>
      <div id="childrenList"></div>
      ${childCount===0?'<div class="empty-state" style="padding:24px"><div class="es-icon">👶</div><div class="es-sub">Aucun enfant dans le ménage, ou cliquez + Ajouter</div></div>':''}`;
    footer.innerHTML = `
      <button class="btn btn-secondary w-full" onclick="fpPrev()">← Retour</button>
      <button class="btn btn-primary w-full" onclick="submitProfilage()">✓ Soumettre</button>`;
  }
}

function fpNext() {
  if (fpStep === 1) {
    if (!document.getElementById('fp-prod').value) return toast('Sélectionnez un producteur', 'error');
  }
  fpStep++;
  renderFpStep();
}
function fpPrev() { fpStep--; renderFpStep(); }
function calcT(a, b, t) {
  const va = parseInt(document.getElementById(a).value)||0;
  const vb = parseInt(document.getElementById(b).value)||0;
  document.getElementById(t).value = va + vb;
}

function addChild() {
  if (childCount >= 5) return;
  childCount++;
  const c   = childCount;
  const div = document.createElement('div');
  div.id    = `child-block-${c}`;
  div.className = 'child-block';
  div.innerHTML = `
    <div class="child-block-header">
      <div class="child-block-title">Enfant ${c}</div>
      <button class="btn btn-ghost btn-sm" onclick="removeChild(${c})" style="color:var(--r500)">✕</button>
    </div>
    <div class="form-row">
      <div class="form-group"><label class="form-label">Nom & Prénom <span class="req">*</span></label>
        <input type="text" class="form-input" id="c${c}-nom"></div>
      <div class="form-group"><label class="form-label">Âge <span class="req">*</span></label>
        <input type="number" class="form-input" id="c${c}-age" min="0" max="17" inputmode="numeric"></div>
    </div>
    <div class="form-row">
      <div class="form-group"><label class="form-label">Genre</label>
        <select class="form-select" id="c${c}-genre"><option value="Garçon">Garçon</option><option value="Fille">Fille</option></select></div>
      <div class="form-group"><label class="form-label">Lien</label>
        <select class="form-select" id="c${c}-lien"><option value="A">Fils/Fille</option><option value="B">Frère/Sœur</option><option value="C">Petit-fils/fille</option><option value="D">Nièce/Neveu</option><option value="E">Autre</option></select></div>
    </div>
    <div class="form-row">
      <div class="form-group"><label class="form-label">Extrait naissance</label>
        <select class="form-select" id="c${c}-extrait"><option value="Oui">Oui</option><option value="Non">Non</option></select></div>
      <div class="form-group"><label class="form-label">Scolarisation</label>
        <select class="form-select" id="c${c}-scol"><option value="Scolarisé">Scolarisé</option><option value="Déscolarisé">Déscolarisé</option><option value="Jamais scolarisé">Jamais</option></select></div>
    </div>
    <div class="form-group"><label class="form-label">Travaux effectués à la plantation</label>
      <div class="check-pills">${TRAVAUX_LIST.map(t => `
        <div class="check-pill"><input type="checkbox" id="c${c}-t${t.k}" value="${t.k}">
          <label for="c${c}-t${t.k}" ${t.d?'class="danger"':''}>${t.v}</label></div>`).join('')}
      </div>
    </div>
    <div class="form-group"><label class="form-label">Solution pour arrêter le travail</label>
      <textarea class="form-textarea" id="c${c}-sol" rows="2" placeholder="Qu'est-ce qui serait nécessaire..."></textarea></div>`;
  const list = document.getElementById('childrenList');
  const empty = list.parentElement.querySelector('.empty-state');
  if (empty) empty.remove();
  list.appendChild(div);
}

function removeChild(c) {
  const el = document.getElementById(`child-block-${c}`);
  if (el) { el.remove(); childCount = Math.max(0, childCount - 1); }
}

function getChildren() {
  const children = [];
  for (let i = 1; i <= 5; i++) {
    const nom = document.getElementById(`c${i}-nom`)?.value?.trim();
    if (!nom) continue;
    const travaux = TRAVAUX_LIST.map(t => document.getElementById(`c${i}-t${t.k}`)?.checked ? t.k : null).filter(Boolean);
    children.push({
      nom_prenom: nom,
      age: parseInt(document.getElementById(`c${i}-age`)?.value)||0,
      genre: document.getElementById(`c${i}-genre`)?.value,
      lien_parente: document.getElementById(`c${i}-lien`)?.value,
      extrait_naissance: document.getElementById(`c${i}-extrait`)?.value,
      etat_scolarisation: document.getElementById(`c${i}-scol`)?.value,
      travaux_effectues: travaux,
      solution_pour_arreter: document.getElementById(`c${i}-sol`)?.value,
    });
  }
  return children;
}

async function submitProfilage() {
  const body = {
    producteur_id: document.getElementById('fp-prod').value,
    date_profilage: document.getElementById('fp-date').value,
    cooperative_id: document.getElementById('fp-coop')?.value || USER.cooperative_id,
    nom_communaute: document.getElementById('fp-comm')?.value || '',
    nb_membres_hommes: document.getElementById('fp-mh')?.value || 0,
    nb_membres_femmes: document.getElementById('fp-mf')?.value || 0,
    nb_membres_total: document.getElementById('fp-mt')?.value || 0,
    nb_travailleurs_hommes: document.getElementById('fp-th')?.value || 0,
    nb_travailleurs_femmes: document.getElementById('fp-tf')?.value || 0,
    nb_travailleurs_total: document.getElementById('fp-tt')?.value || 0,
    statut: 'soumis',
    enfants: getChildren(),
  };
  if (!body.producteur_id) return toast('Producteur requis', 'error');
  await submitFiche('/fiches-profilage', body, 'ficheProfilage', 'profilage');
}

// ── FICHE ARBRES ──────────────────────────────────────────────
function openArbresSheet() {
  const prodOpts = PRODS.map(p => `<option value="${p.id}">${p.nom} ${p.prenom||''}</option>`).join('');
  document.getElementById('arbresSheetBody').innerHTML = `
    <div class="form-row">
      <div class="form-group"><label class="form-label">Date <span class="req">*</span></label>
        <input type="date" class="form-input" id="fa-date" value="${today()}"></div>
      <div class="form-group"><label class="form-label">Producteur <span class="req">*</span></label>
        <select class="form-select" id="fa-prod"><option value="">Sélectionner...</option>${prodOpts}</select></div>
    </div>
    <div class="form-row">
      <div class="form-group"><label class="form-label">Nb arbres ombrage</label>
        <input type="number" class="form-input" id="fa-nb" min="0" inputmode="numeric" oninput="calcDensite()"></div>
      <div class="form-group"><label class="form-label">Superficie (ha)</label>
        <input type="number" class="form-input" id="fa-sup" step="0.01" inputmode="decimal" oninput="calcDensite()"></div>
    </div>
    <div class="form-row">
      <div class="form-group"><label class="form-label">Densité/ha (auto)</label>
        <input type="number" class="form-input" id="fa-dens" readonly style="background:var(--gray100)"></div>
      <div class="form-group"><label class="form-label">Arbres déficitaires/ha</label>
        <input type="number" class="form-input" id="fa-def" step="0.01" inputmode="decimal"></div>
    </div>
    <div style="display:flex;justify-content:space-between;align-items:center;margin:16px 0 10px">
      <div style="font-size:14px;font-weight:700;color:var(--gray700)">Espèces d'arbres</div>
      <button class="btn btn-outline btn-sm" onclick="addEspece()">+ Ajouter espèce</button>
    </div>
    <div id="especesList"></div>`;
  openSheet('arbres');
}

function calcDensite() {
  const nb  = parseFloat(document.getElementById('fa-nb').value)||0;
  const sup = parseFloat(document.getElementById('fa-sup').value)||1;
  document.getElementById('fa-dens').value = sup > 0 ? (nb/sup).toFixed(2) : 0;
}

let especeIdx = 0;
function addEspece() {
  especeIdx++;
  const i = especeIdx;
  const div = document.createElement('div');
  div.className = 'child-block';
  div.innerHTML = `
    <div class="child-block-header"><div class="child-block-title">Espèce ${i}</div>
      <button class="btn btn-ghost btn-sm" onclick="this.closest('.child-block').remove()" style="color:var(--r500)">✕</button></div>
    <div class="form-row">
      <div class="form-group"><label class="form-label">Nom local</label><input type="text" class="form-input esp-local" placeholder="Nom local"></div>
      <div class="form-group"><label class="form-label">Nom botanique</label><input type="text" class="form-input esp-bota" placeholder="Nom scientifique"></div>
    </div>
    <div class="form-row">
      <div class="form-group"><label class="form-label">Origine</label>
        <select class="form-select esp-orig"><option value="1">Indigène</option><option value="2">Planté</option></select></div>
      <div class="form-group"><label class="form-label">Nombre total</label>
        <input type="number" class="form-input esp-nb" min="0" inputmode="numeric"></div>
    </div>
    <div class="form-group">
      <div class="form-check" style="display:flex;align-items:center;gap:8px">
        <input type="checkbox" class="esp-nonombr" id="eno-${i}" style="width:18px;height:18px;accent-color:var(--g600)">
        <label for="eno-${i}" style="font-size:13px;color:var(--gray700)">Arbre non ombrage (< 5m)</label>
      </div>
    </div>`;
  document.getElementById('especesList').appendChild(div);
}

async function submitArbres() {
  const prod = document.getElementById('fa-prod').value;
  if (!prod) return toast('Producteur requis', 'error');
  const especes = [...document.querySelectorAll('#especesList .child-block')].map(el => ({
    nom_local: el.querySelector('.esp-local')?.value||'',
    nom_botanique: el.querySelector('.esp-bota')?.value||'',
    origine: el.querySelector('.esp-orig')?.value||'1',
    nombre_total: parseInt(el.querySelector('.esp-nb')?.value)||0,
    non_ombrage: el.querySelector('.esp-nonombr')?.checked ? 1 : 0,
  }));
  const body = {
    producteur_id: prod,
    date_collecte: document.getElementById('fa-date').value,
    nb_arbres_ombrage: document.getElementById('fa-nb').value||0,
    densite_par_hectare: document.getElementById('fa-dens').value||0,
    nb_arbres_deficitaires: document.getElementById('fa-def').value||0,
    statut: 'soumis', especes,
  };
  await submitFiche('/fiches-arbres', body, 'ficheArbres', 'arbres');
}

// ── FICHE ENGRAIS ─────────────────────────────────────────────
function openEngraisSheet() {
  const prodOpts = PRODS.map(p => `<option value="${p.id}">${p.nom} ${p.prenom||''}</option>`).join('');
  document.getElementById('engraisSheetBody').innerHTML = `
    <div class="form-row">
      <div class="form-group"><label class="form-label">Date <span class="req">*</span></label>
        <input type="date" class="form-input" id="fe-date" value="${today()}"></div>
      <div class="form-group"><label class="form-label">Producteur <span class="req">*</span></label>
        <select class="form-select" id="fe-prod"><option value="">Sélectionner...</option>${prodOpts}</select></div>
    </div>
    <hr style="margin:16px 0">
    <!-- Engrais organiques -->
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
      <div style="font-size:14px;font-weight:700;color:var(--gray700)">🌿 Engrais organiques</div>
      <button class="btn btn-outline btn-sm" onclick="addOrganique()">+ Ajouter</button>
    </div>
    <div id="orgList"></div>
    <hr style="margin:16px 0">
    <!-- Engrais inorganiques -->
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
      <div style="font-size:14px;font-weight:700;color:var(--gray700)">🧪 Engrais inorganiques</div>
      <button class="btn btn-outline btn-sm" onclick="addInorganique()">+ Ajouter</button>
    </div>
    <div id="inorgList"></div>
    <hr style="margin:16px 0">
    <!-- Pesticides -->
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
      <div style="font-size:14px;font-weight:700;color:var(--gray700)">☠️ Pesticides</div>
      <button class="btn btn-outline btn-sm" onclick="addPesticide()">+ Ajouter</button>
    </div>
    <div id="pestList"></div>`;
  openSheet('engrais');
}

function addOrganique() {
  const div = document.createElement('div');
  div.className = 'child-block';
  div.innerHTML = `
    <div class="child-block-header"><div class="child-block-title">Engrais organique</div>
      <button class="btn btn-ghost btn-sm" onclick="this.closest('.child-block').remove()" style="color:var(--r500)">✕</button></div>
    <div class="form-row">
      <div class="form-group"><label class="form-label">Source</label>
        <select class="form-select org-src"><option value="VEGETALE">Végétale</option><option value="ANIMALE">Animale</option><option value="COMPOSTE">Composte</option><option value="COMMERCIAL">Commercial</option></select></div>
      <div class="form-group"><label class="form-label">Période</label><input type="text" class="form-input org-per" placeholder="ex: Mars-Avril"></div>
    </div>
    <div class="form-row">
      <div class="form-group"><label class="form-label">Fréq./an</label><input type="number" class="form-input org-freq" min="0" inputmode="numeric"></div>
      <div class="form-group"><label class="form-label">Quantité/ha (kg)</label><input type="number" class="form-input org-qha" step="0.01" inputmode="decimal"></div>
    </div>`;
  document.getElementById('orgList').appendChild(div);
}

function addInorganique() {
  const div = document.createElement('div');
  div.className = 'child-block';
  div.innerHTML = `
    <div class="child-block-header"><div class="child-block-title">Engrais inorganique</div>
      <button class="btn btn-ghost btn-sm" onclick="this.closest('.child-block').remove()" style="color:var(--r500)">✕</button></div>
    <div class="form-row">
      <div class="form-group"><label class="form-label">Nom commercial</label><input type="text" class="form-input ing-nom"></div>
      <div class="form-group"><label class="form-label">Formulation N-P-K</label><input type="text" class="form-input ing-npk" placeholder="ex: 15-15-15"></div>
    </div>
    <div class="form-row">
      <div class="form-group"><label class="form-label">Superficie (ha)</label><input type="number" class="form-input ing-sup" step="0.01" inputmode="decimal"></div>
      <div class="form-group"><label class="form-label">Nb sacs/période</label><input type="number" class="form-input ing-sacs" min="0" inputmode="numeric"></div>
    </div>`;
  document.getElementById('inorgList').appendChild(div);
}

function addPesticide() {
  const div = document.createElement('div');
  div.className = 'child-block';
  div.innerHTML = `
    <div class="child-block-header"><div class="child-block-title">Pesticide</div>
      <button class="btn btn-ghost btn-sm" onclick="this.closest('.child-block').remove()" style="color:var(--r500)">✕</button></div>
    <div class="form-row">
      <div class="form-group"><label class="form-label">Type</label>
        <select class="form-select pest-type"><option value="1">Insecticide</option><option value="2">Fongicide</option><option value="3">Herbicide</option><option value="4">Rien</option></select></div>
      <div class="form-group"><label class="form-label">Nom commercial</label><input type="text" class="form-input pest-nom"></div>
    </div>
    <div class="form-group"><label class="form-label">Ingrédients actifs</label><input type="text" class="form-input pest-ingr"></div>
    <div class="form-row">
      <div class="form-group"><label class="form-label">Superficie (ha)</label><input type="number" class="form-input pest-sup" step="0.01" inputmode="decimal"></div>
      <div class="form-group"><label class="form-label">Période</label><input type="text" class="form-input pest-per"></div>
    </div>`;
  document.getElementById('pestList').appendChild(div);
}

async function submitEngrais() {
  const prod = document.getElementById('fe-prod').value;
  if (!prod) return toast('Producteur requis', 'error');
  const organiques   = [...document.querySelectorAll('#orgList .child-block')].map(el => ({
    source: el.querySelector('.org-src')?.value,
    periode_application: el.querySelector('.org-per')?.value||'',
    frequence_an: el.querySelector('.org-freq')?.value||0,
    quantite_par_ha: el.querySelector('.org-qha')?.value||0,
  }));
  const inorganiques = [...document.querySelectorAll('#inorgList .child-block')].map(el => ({
    nom_commercial: el.querySelector('.ing-nom')?.value||'',
    formulation_npk: el.querySelector('.ing-npk')?.value||'',
    superficie_appliquee: el.querySelector('.ing-sup')?.value||0,
    nb_sacs_periode: el.querySelector('.ing-sacs')?.value||0,
  }));
  const pesticides = [...document.querySelectorAll('#pestList .child-block')].map(el => ({
    type_pesticide: el.querySelector('.pest-type')?.value||'4',
    nom_commercial: el.querySelector('.pest-nom')?.value||'',
    ingredients_actifs: el.querySelector('.pest-ingr')?.value||'',
    superficie_appliquee: el.querySelector('.pest-sup')?.value||0,
    periode_traitement: el.querySelector('.pest-per')?.value||'',
  }));
  const body = {
    producteur_id: prod,
    date_collecte: document.getElementById('fe-date').value,
    statut: 'soumis', organiques, inorganiques, pesticides,
  };
  await submitFiche('/fiches-engrais', body, 'ficheEngrais', 'engrais');
}

// ── SUBMIT HELPER ────────────────────────────────────────────
async function submitFiche(endpoint, body, storeKey, sheetName) {
  try {
    if (navigator.onLine) {
      await apiFetch(endpoint, 'POST', body);
      toast('Fiche soumise avec succès ✓', 'success');
    } else {
      await localDB['save' + storeKey.charAt(0).toUpperCase() + storeKey.slice(1)](body);
      toast('Sauvegardé hors ligne — sync automatique', 'warning');
    }
    closeSheet(sheetName);
    updatePendingBadge();
    if (USER.role !== 'admin') loadMyFiches();
  } catch (e) {
    // Fallback to offline
    try {
      const method = 'save' + storeKey.charAt(0).toUpperCase() + storeKey.slice(1);
      if (localDB[method]) await localDB[method](body);
    } catch {}
    toast('Sauvegardé hors ligne', 'warning');
    closeSheet(sheetName);
    updatePendingBadge();
  }
}

// ── VALIDATION ────────────────────────────────────────────────
function openValidSheet(id, type) {
  document.getElementById('validId').value   = id;
  document.getElementById('validType').value = type;
  document.getElementById('validComment').value = '';
  const labels = { profilage: 'Fiche Profilage', arbres: "Fiche Arbres d'Ombrage", engrais: 'Fiche Engrais' };
  document.getElementById('validSheetTitle').textContent = `Valider — ${labels[type]||type} #${id}`;
  openSheet('valid');
}

async function doValidate(statut) {
  const id      = document.getElementById('validId').value;
  const type    = document.getElementById('validType').value;
  const comment = document.getElementById('validComment').value;
  const eps     = { profilage: 'fiches-profilage', arbres: 'fiches-arbres', engrais: 'fiches-engrais' };
  try {
    await apiFetch(`/${eps[type]}/${id}`, 'PUT', { statut, commentaire_admin: comment });
    closeSheet('valid');
    toast(statut === 'valide' ? 'Fiche validée ✓' : 'Fiche rejetée', statut==='valide'?'success':'warning');
    loadDashboard();
    loadCurrentFiches();
  } catch (e) { toast(e.message, 'error'); }
}

// ── NOTIFICATIONS ─────────────────────────────────────────────
async function loadNotifications() {
  try {
    const res = await apiFetch('/notifications');
    if (res.unread > 0) document.getElementById('notifDot').style.display = 'block';
    else document.getElementById('notifDot').style.display = 'none';
  } catch {}
}

function openNotifs() {
  openSheet('notifs');
  loadNotifsSheet();
}

async function loadNotifsSheet() {
  const body = document.getElementById('notifsBody');
  try {
    const res  = await apiFetch('/notifications');
    const data = res.data || [];
    if (!data.length) {
      body.innerHTML = `<div class="empty-state"><div class="es-icon">🔔</div><div class="es-title">Aucune notification</div></div>`;
      return;
    }
    body.innerHTML = data.map(n => `
      <div style="padding:14px 0;border-bottom:1px solid var(--gray100);${n.lu?'':'background:var(--g50)'}">
        <div style="font-size:14px;color:var(--gray800)">${n.message}</div>
        <div style="font-size:11px;color:var(--gray400);margin-top:4px">${timeAgo(n.created_at)}</div>
      </div>`).join('');
    document.getElementById('notifDot').style.display = 'none';
  } catch { body.innerHTML = `<div class="empty-state"><div class="es-icon">⚠️</div><div class="es-title">Erreur</div></div>`; }
}

async function markNotifsRead() {
  try { await apiFetch('/notifications', 'PUT', {}); loadNotifsSheet(); } catch {}
}

// ── AI CHAT ───────────────────────────────────────────────────
let aiHistory = [];

function openAIChat() {
  openSheet('ai');
  // Afficher boutons admin
  if (USER?.role === 'admin') {
    const rb = document.getElementById('reportBtn');
    const ab = document.getElementById('anomalyBtn');
    if (rb) rb.style.display = 'flex';
    if (ab) ab.style.display = 'flex';
  }
}

function quickAsk(question) {
  document.getElementById('aiInput').value = question;
  sendAI();
}

function addAIMessage(content, role = 'bot') {
  const msgs = document.getElementById('aiMessages');
  const isUser = role === 'user';
  const div = document.createElement('div');
  div.style.cssText = `
    background:${isUser ? 'var(--g700)' : 'var(--gray100)'};
    color:${isUser ? 'var(--white)' : 'var(--gray800)'};
    border-radius:12px;
    border-bottom-${isUser ? 'right' : 'left'}-radius:4px;
    padding:12px 14px;font-size:14px;line-height:1.5;
    align-self:${isUser ? 'flex-end' : 'flex-start'};
    max-width:88%;white-space:pre-wrap;`;
  div.textContent = content;
  msgs.appendChild(div);
  msgs.scrollTop = msgs.scrollHeight;
  return div;
}

async function sendAI() {
  const input = document.getElementById('aiInput');
  const msg   = input.value.trim();
  if (!msg) return;
  input.value = '';
  input.disabled = true;

  addAIMessage(msg, 'user');
  aiHistory.push({ role: 'user', content: msg });

  const typingEl = addAIMessage('⏳ Analyse en cours...', 'bot');

  try {
    // Récupérer stats pour contexte
    let context = {};
    try {
      if (USER?.role === 'admin') {
        const stats = await apiFetch('/stats');
        context = { stats: stats.stats };
      }
    } catch {}

    const res = await apiFetch('/ai-chat', 'POST', {
      message: msg,
      history: aiHistory.slice(-6), // 3 derniers échanges
      context,
    });

    typingEl.textContent = res.reply;
    aiHistory.push({ role: 'assistant', content: res.reply });

    // Garder historique limité
    if (aiHistory.length > 20) aiHistory = aiHistory.slice(-20);

  } catch(e) {
    typingEl.textContent = '❌ ' + (e.message || 'Service indisponible');
    typingEl.style.color = 'var(--r500)';
  }
  input.disabled = false;
  input.focus();
  document.getElementById('aiMessages').scrollTop = 99999;
}

async function requestAIReport() {
  openAIChat();
  const typingEl = addAIMessage('⏳ Génération du rapport en cours...', 'bot');
  try {
    const res = await apiFetch('/ai-report', 'POST', { period: 'mensuel' });
    typingEl.textContent = res.report;
    aiHistory.push({ role: 'assistant', content: res.report });
  } catch(e) {
    typingEl.textContent = '❌ ' + e.message;
  }
}

async function checkAnomalies() {
  openAIChat();
  const typingEl = addAIMessage('🔍 Analyse des anomalies en cours...', 'bot');
  try {
    const res = await apiFetch('/ai-anomalies');
    let text = '';
    if (res.anomalies.length === 0) {
      text = '✅ Aucune anomalie détectée. Toutes les données sont conformes.';
    } else {
      text = '⚠️ Anomalies détectées:\n\n' + res.anomalies.join('\n') + '\n\n' + res.conseil;
    }
    typingEl.textContent = text;
  } catch(e) {
    typingEl.textContent = '❌ ' + e.message;
  }
}

// ── API HELPER ────────────────────────────────────────────────
async function apiFetch(endpoint, method = 'GET', body = null) {
  // Utilise le chemin direct dans l'URL - évite la traduction automatique du navigateur
  const path    = endpoint.startsWith('/') ? endpoint : '/' + endpoint;
  const baseUrl = BASE + '/api' + path;
  const opts    = {
    method,
    headers: { 'Content-Type': 'application/json' },
  };
  if (body && method !== 'GET') opts.body = JSON.stringify(body);

  // Timeout de 10 secondes
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), 10000);
  opts.signal = controller.signal;

  try {
    const res  = await fetch(baseUrl, opts);
    clearTimeout(timer);
    const text = await res.text();
    let data;
    try {
      data = JSON.parse(text);
    } catch(e) {
      throw new Error('Réponse invalide du serveur: ' + text.substring(0, 100));
    }
    if (!data.success && !data.offline) throw new Error(data.error || 'Erreur API');
    return data;
  } catch(e) {
    clearTimeout(timer);
    if (e.name === 'AbortError') throw new Error('Délai dépassé — vérifiez votre connexion');
    throw e;
  }
}

// ── REGISTER COOPERATIVE ─────────────────────────────────────────
function showRegisterSheet() {
  document.getElementById('registerErr').style.display = 'none';
  document.getElementById('registerForm').style.display = 'block';
  document.getElementById('registerSuccess').style.display = 'none';
  document.getElementById('registerFooter').style.display = 'flex';
  ['regNom','regEmail','regTel','regLoc','regPass','regPass2'].forEach(id => {
    const el = document.getElementById(id); if (el) el.value = '';
  });
  document.getElementById('registerOverlay').classList.add('open');
  document.getElementById('registerSheet').classList.add('open');
}
function closeRegisterSheet() {
  document.getElementById('registerOverlay').classList.remove('open');
  document.getElementById('registerSheet').classList.remove('open');
}
async function submitRegister() {
  const nom=document.getElementById('regNom').value.trim(),email=document.getElementById('regEmail').value.trim();
  const tel=document.getElementById('regTel').value.trim(),loc=document.getElementById('regLoc').value.trim();
  const pass=document.getElementById('regPass').value,pass2=document.getElementById('regPass2').value;
  const errEl=document.getElementById('registerErr');
  errEl.style.display='none';
  if(!nom||!email||!pass){errEl.textContent='Nom, email et mot de passe requis';errEl.style.display='flex';return;}
  if(pass!==pass2){errEl.textContent='Les mots de passe ne correspondent pas';errEl.style.display='flex';return;}
  if(pass.length<6){errEl.textContent='Mot de passe: minimum 6 caractères';errEl.style.display='flex';return;}
  try {
    await apiFetch('/cooperative-requests','POST',{nom,email,telephone:tel,localite:loc,password:pass});
    document.getElementById('registerForm').style.display='none';
    document.getElementById('registerSuccess').style.display='block';
    document.getElementById('registerFooter').style.display='none';
  } catch(e){errEl.textContent=e.message;errEl.style.display='flex';}
}

// ── SUPERADMIN ────────────────────────────────────────────────────
let currentReqFilter='en_attente',currentReqId=null;

async function loadSADashboard() {
  try {
    const res=await apiFetch('/sa-stats');const s=res.stats;
    document.getElementById('sa-pending').textContent=s.pending_requests;
    document.getElementById('sa-coops').textContent=s.total_coops;
    document.getElementById('sa-users').textContent=s.total_users;
    document.getElementById('sa-fiches').textContent=s.total_fiches;
    if(s.pending_requests>0){const b=document.getElementById('requestsBadge');b.style.display='flex';b.textContent=s.pending_requests;}
    await loadRequests('en_attente',false);
  } catch(e){console.error(e);}
}

async function loadRequests(statut='en_attente',updateContainer=true) {
  currentReqFilter=statut;
  const container=document.getElementById(updateContainer?'requestsList':'saLatestRequests');
  try {
    const url=statut?`/cooperative-requests?statut=${statut}`:'/cooperative-requests';
    const res=await apiFetch(url);const data=res.data||[];
    if(!data.length){container.innerHTML=`<div class="empty-state"><div class="es-icon">📋</div><div class="es-title">Aucune demande</div></div>`;return;}
    container.innerHTML=data.map(r=>`
      <div class="list-item" onclick="openRequestDetail(${r.id})">
        <div class="li-icon" style="background:${r.statut==='en_attente'?'var(--a100)':r.statut==='valide'?'var(--g100)':'var(--r100)'}">
          ${r.statut==='en_attente'?'⏳':r.statut==='valide'?'✅':'❌'}
        </div>
        <div class="li-body">
          <div class="li-title">${r.nom}</div>
          <div class="li-sub">${r.email} • ${r.localite||''} • ${timeAgo(r.created_at)}</div>
        </div>
        <div class="li-right">${saStatusBadge(r.statut)}</div>
      </div>`).join('');
  } catch(e){container.innerHTML=`<div class="empty-state"><div class="es-icon">⚠️</div><div class="es-title">${e.message}</div></div>`;}
}

async function filterRequests(statut,btn) {
  document.querySelectorAll('[id^="req-tab-"]').forEach(b=>b.className='btn btn-sm btn-secondary');
  btn.className='btn btn-sm btn-primary';
  await loadRequests(statut,true);
}

async function openRequestDetail(id) {
  currentReqId=id;
  try {
    const res=await apiFetch('/cooperative-requests');
    const r=(res.data||[]).find(x=>x.id==id);if(!r)return;
    document.getElementById('reqDetailTitle').textContent=r.nom;
    document.getElementById('reqDetailBody').innerHTML=`
      <div class="card" style="margin-bottom:16px"><div class="card-body">
        <div style="display:flex;flex-direction:column;gap:12px">
          <div><div style="font-size:11px;color:var(--gray400);font-weight:600;text-transform:uppercase;margin-bottom:2px">Coopérative</div><div style="font-weight:700">${r.nom}</div></div>
          <div><div style="font-size:11px;color:var(--gray400);font-weight:600;text-transform:uppercase;margin-bottom:2px">Email</div><div>${r.email}</div></div>
          <div><div style="font-size:11px;color:var(--gray400);font-weight:600;text-transform:uppercase;margin-bottom:2px">Téléphone</div><div>${r.telephone||'Non renseigné'}</div></div>
          <div><div style="font-size:11px;color:var(--gray400);font-weight:600;text-transform:uppercase;margin-bottom:2px">Localité</div><div>${r.localite||'Non renseignée'}</div></div>
          <div><div style="font-size:11px;color:var(--gray400);font-weight:600;text-transform:uppercase;margin-bottom:2px">Date</div><div>${new Date(r.created_at).toLocaleDateString('fr-FR')}</div></div>
          <div><div style="font-size:11px;color:var(--gray400);font-weight:600;text-transform:uppercase;margin-bottom:2px">Statut</div><div>${saStatusBadge(r.statut)}</div></div>
        </div>
      </div></div>
      ${r.statut==='en_attente'?'<p style="font-size:13px;color:var(--gray500);text-align:center">Valider créera automatiquement le compte admin de cette coopérative.</p>':''}`;
    document.getElementById('reqDetailFooter').style.display=r.statut==='en_attente'?'flex':'none';
    openSheet('reqDetail');
  } catch(e){toast(e.message,'error');}
}

async function validateRequest(statut) {
  if(!currentReqId)return;
  const msg=statut==='valide'?'Valider cette coopérative ? Un compte admin sera créé.':'Rejeter cette demande ?';
  if(!confirm(msg))return;
  try {
    await apiFetch(`/cooperative-requests/${currentReqId}`,'PUT',{statut});
    closeSheet('reqDetail');
    toast(statut==='valide'?'✅ Coopérative validée!':'❌ Demande rejetée',statut==='valide'?'success':'warning');
    loadSADashboard();loadRequests(currentReqFilter,true);
  } catch(e){toast(e.message,'error');}
}

async function loadSACoops() {
  const container=document.getElementById('saCoopsList');
  try {
    const res=await apiFetch('/cooperatives');const data=res.data||[];
    container.innerHTML=data.length?data.map(c=>`
      <div class="list-item">
        <div class="li-icon" style="background:var(--g100)">🏠</div>
        <div class="li-body"><div class="li-title">${c.nom}</div>
          <div class="li-sub">${c.email||c.localite||''} • ${c.nb_inspecteurs||0} insp.</div></div>
        <div class="li-right"><span class="badge badge-green">Active</span></div>
      </div>`).join(''):`<div class="empty-state"><div class="es-icon">🏠</div><div class="es-title">Aucune coopérative</div></div>`;
  } catch(e){container.innerHTML=`<div class="empty-state"><div class="es-icon">⚠️</div><div class="es-title">${e.message}</div></div>`;}
}

function saStatusBadge(s) {
  const map={en_attente:'badge-amber',valide:'badge-green',rejete:'badge-red'};
  const lbls={en_attente:'En attente',valide:'Validée',rejete:'Rejetée'};
  return `<span class="badge ${map[s]||'badge-gray'}">${lbls[s]||s}</span>`;
}

// ── UTILS ────────────────────────────────────────────────────
function today() { return new Date().toISOString().split('T')[0]; }

function statusBadge(s) {
  const map  = { soumis:'badge-amber', valide:'badge-green', rejete:'badge-red', brouillon:'badge-gray' };
  const lbls = { soumis:'En attente', valide:'Validé', rejete:'Rejeté', brouillon:'Brouillon' };
  return `<span class="badge ${map[s]||'badge-gray'}">${lbls[s]||s}</span>`;
}

function timeAgo(d) {
  const diff = Math.floor((Date.now() - new Date(d)) / 1000);
  if (diff < 60) return "À l'instant";
  if (diff < 3600) return `Il y a ${Math.floor(diff/60)} min`;
  if (diff < 86400) return `Il y a ${Math.floor(diff/3600)}h`;
  return new Date(d).toLocaleDateString('fr-FR');
}

function escHTML(s) {
  return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

function toast(msg, type = 'info') {
  const tc = document.getElementById('toasts');
  const el = document.createElement('div');
  const icons = { success:'✓', error:'✗', warning:'⚠', info:'ℹ' };
  el.className = `toast ${type}`;
  el.innerHTML = `<span>${icons[type]||'ℹ'}</span>${msg}`;
  tc.appendChild(el);
  setTimeout(() => { el.style.opacity = '0'; el.style.transform = 'translateY(-10px)'; el.style.transition = 'all .3s'; setTimeout(() => el.remove(), 300); }, 3500);
  el.onclick = () => el.remove();
}

// Handle enter key on login
document.addEventListener('keydown', e => {
  if (e.key === 'Enter') {
    const ls = document.getElementById('loginScreen');
    if (ls.classList.contains('show')) doLogin();
    if (document.getElementById('aiInput') === document.activeElement) sendAI();
  }
});

// ══════════════════════════════════════════════════════════════
// DASHBOARD ADMIN COOPÉRATIVE
// ══════════════════════════════════════════════════════════════
async function loadCoopDashboard() {
  try {
    const res = await apiFetch('/coop-stats');
    const s   = res.stats;

    document.getElementById('cs-inspecteurs').textContent = s.inspecteurs.length;
    document.getElementById('cs-producteurs').textContent = s.total_producteurs;
    document.getElementById('cs-valides').textContent     = parseInt(s.fiches_profilage.valide) + parseInt(s.fiches_arbres.valide) + parseInt(s.fiches_engrais.valide);
    document.getElementById('cs-enfants').textContent     = s.enfants_risque;

    // Activité par inspecteur
    const container = document.getElementById('inspecteurActivityList');
    if (!s.inspecteurs.length) {
      container.innerHTML = `<div class="empty-state"><div class="es-icon">👤</div><div class="es-title">Aucun inspecteur</div></div>`;
      return;
    }
    container.innerHTML = s.inspecteurs.map(insp => {
      const total = insp.fiches.profilage + insp.fiches.arbres + insp.fiches.engrais;
      const pct   = total > 0 ? Math.round((insp.fiches.valide / total) * 100) : 0;
      return `
        <div class="list-item" onclick="viewInspecteurDetail(${insp.id})">
          <div class="li-icon" style="background:var(--g100);font-weight:800;font-size:14px;color:var(--g800)">
            ${(insp.prenom[0]||'') + (insp.nom[0]||'')}
          </div>
          <div class="li-body">
            <div class="li-title">${insp.prenom} ${insp.nom}</div>
            <div class="li-sub">${insp.code_inspecteur||''} • ${total} fiche(s) • ${pct}% validées</div>
            <div style="background:var(--gray100);border-radius:20px;height:4px;margin-top:6px">
              <div style="background:var(--g500);height:4px;border-radius:20px;width:${pct}%;transition:width .5s"></div>
            </div>
          </div>
          <div class="chevron"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" width="16" height="16"><path d="M9 18l6-6-6-6"/></svg></div>
        </div>`;
    }).join('');
  } catch(e) { console.error(e); }
}

async function viewInspecteurDetail(inspId) {
  try {
    const res = await apiFetch(`/inspecteur-stats/${inspId}`);
    const s   = res.stats;
    const insp = await apiFetch(`/users/${inspId}`).catch(() => null);

    openSheet('ficheDetail');
    document.getElementById('ficheDetailTitle').textContent = 'Activité inspecteur';
    document.getElementById('ficheDetailBody').innerHTML = `
      <div class="stat-grid" style="margin-bottom:16px">
        <div class="stat-card"><div class="icon ic-green"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 11l3 3L22 4"/></svg></div><div class="val">${s.profilage.valide}</div><div class="lbl">Validées</div></div>
        <div class="stat-card"><div class="icon ic-amber"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12,6 12,12 16,14"/></svg></div><div class="val">${s.profilage.soumis}</div><div class="lbl">En attente</div></div>
        <div class="stat-card"><div class="icon ic-blue"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/></svg></div><div class="val">${s.total_all}</div><div class="lbl">Total</div></div>
        <div class="stat-card"><div class="icon ic-green"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></div><div class="val">${s.ce_mois}</div><div class="lbl">Ce mois</div></div>
      </div>
      <div class="card"><div class="card-body" style="display:flex;flex-direction:column;gap:12px">
        <div><div style="display:flex;justify-content:space-between;margin-bottom:4px"><span style="font-size:13px;font-weight:600">👨‍👩‍👧 Profilage</span><span style="font-size:13px;color:var(--gray500)">${s.profilage.total}</span></div>
          <div style="background:var(--gray100);border-radius:20px;height:6px"><div style="background:var(--g500);height:6px;border-radius:20px;width:${s.profilage.total>0?Math.round(s.profilage.valide/s.profilage.total*100):0}%"></div></div></div>
        <div><div style="display:flex;justify-content:space-between;margin-bottom:4px"><span style="font-size:13px;font-weight:600">🌳 Arbres</span><span style="font-size:13px;color:var(--gray500)">${s.arbres.total}</span></div>
          <div style="background:var(--gray100);border-radius:20px;height:6px"><div style="background:var(--a500);height:6px;border-radius:20px;width:${s.arbres.total>0?Math.round(s.arbres.valide/s.arbres.total*100):0}%"></div></div></div>
        <div><div style="display:flex;justify-content:space-between;margin-bottom:4px"><span style="font-size:13px;font-weight:600">🧪 Engrais</span><span style="font-size:13px;color:var(--gray500)">${s.engrais.total}</span></div>
          <div style="background:var(--gray100);border-radius:20px;height:6px"><div style="background:var(--b500);height:6px;border-radius:20px;width:${s.engrais.total>0?Math.round(s.engrais.valide/s.engrais.total*100):0}%"></div></div></div>
      </div></div>`;
    document.getElementById('ficheDetailFooter').innerHTML = '';
  } catch(e) { toast(e.message, 'error'); }
}

// ══════════════════════════════════════════════════════════════
// DASHBOARD INSPECTEUR
// ══════════════════════════════════════════════════════════════
async function loadInspDashboard() {
  try {
    const res = await apiFetch('/inspecteur-stats');
    const s   = res.stats;

    document.getElementById('is-valide').textContent = s.profilage.valide;
    document.getElementById('is-soumis').textContent = s.profilage.soumis;
    document.getElementById('is-rejete').textContent = s.profilage.rejete;
    document.getElementById('is-mois').textContent   = s.ce_mois;

    const maxP = Math.max(s.profilage.total, s.arbres.total, s.engrais.total, 1);
    document.getElementById('is-p-total').textContent = s.profilage.total;
    document.getElementById('is-a-total').textContent = s.arbres.total;
    document.getElementById('is-e-total').textContent = s.engrais.total;
    setTimeout(() => {
      document.getElementById('is-p-bar').style.width = (s.profilage.total/maxP*100)+'%';
      document.getElementById('is-a-bar').style.width = (s.arbres.total/maxP*100)+'%';
      document.getElementById('is-e-bar').style.width = (s.engrais.total/maxP*100)+'%';
    }, 100);

    // Fiches récentes
    const ficheRes = await apiFetch('/fiches-profilage');
    const fiches   = (ficheRes.data||[]).slice(0,5);
    const container = document.getElementById('inspRecentFiches');
    container.innerHTML = fiches.length ? fiches.map(f => `
      <div class="list-item" onclick="openFicheDetail('profilage',${f.id},'${f.statut}')">
        <div class="li-icon" style="background:var(--g50)">👨‍👩‍👧</div>
        <div class="li-body">
          <div class="li-title">${f.prod_nom||'Producteur'}</div>
          <div class="li-sub">${f.date_profilage} • ${f.nom_communaute||''}</div>
        </div>
        <div class="li-right">${statusBadge(f.statut)}</div>
      </div>`).join('') :
      `<div class="empty-state"><div class="es-icon">📝</div><div class="es-title">Aucune fiche</div></div>`;

    // Offline count
    const n = await localDB.getPendingCount();
    document.getElementById('offlineCount').textContent = `${n} fiche(s) en attente de synchronisation`;
    document.getElementById('syncBtn').style.display = n > 0 && navigator.onLine ? 'block' : 'none';
  } catch(e) { console.error(e); }
}

// ══════════════════════════════════════════════════════════════
// MODIFIER / SUPPRIMER FICHE
// ══════════════════════════════════════════════════════════════
async function openFicheDetail(type, id, statut) {
  const eps  = { profilage:'fiches-profilage', arbres:'fiches-arbres', engrais:'fiches-engrais' };
  const types = { profilage:'👨‍👩‍👧 Profilage', arbres:'🌳 Arbres', engrais:'🧪 Engrais' };

  try {
    const res   = await apiFetch(`/${eps[type]}`);
    const fiche = (res.data||[]).find(f => f.id == id);
    if (!fiche) return toast('Fiche non trouvée', 'error');

    document.getElementById('ficheDetailTitle').textContent = types[type] + ' #' + id;

    // Build detail view
    let html = `<div class="card" style="margin-bottom:12px"><div class="card-body" style="display:flex;flex-direction:column;gap:10px">`;
    html += `<div><span style="font-size:11px;font-weight:700;color:var(--gray400);text-transform:uppercase">Producteur</span><div style="font-weight:700;margin-top:2px">${fiche.prod_nom||'-'} ${fiche.prod_prenom||''}</div></div>`;
    html += `<div><span style="font-size:11px;font-weight:700;color:var(--gray400);text-transform:uppercase">Inspecteur</span><div style="margin-top:2px">${fiche.insp_prenom||''} ${fiche.insp_nom||'-'}</div></div>`;
    html += `<div><span style="font-size:11px;font-weight:700;color:var(--gray400);text-transform:uppercase">Date</span><div style="margin-top:2px">${fiche.date_profilage||fiche.date_collecte||'-'}</div></div>`;
    html += `<div><span style="font-size:11px;font-weight:700;color:var(--gray400);text-transform:uppercase">Statut</span><div style="margin-top:4px">${statusBadge(fiche.statut)}</div></div>`;

    if (type === 'profilage' && fiche.enfants?.length) {
      html += `<div><span style="font-size:11px;font-weight:700;color:var(--gray400);text-transform:uppercase">Enfants (${fiche.enfants.length})</span>`;
      fiche.enfants.forEach(e => {
        html += `<div style="margin-top:6px;padding:8px;background:var(--gray50);border-radius:8px;font-size:13px"><strong>${e.nom_prenom}</strong> • ${e.age} ans • ${e.genre}</div>`;
      });
      html += '</div>';
    }

    if (type === 'arbres') {
      html += `<div><span style="font-size:11px;font-weight:700;color:var(--gray400);text-transform:uppercase">Arbres d'ombrage</span><div style="margin-top:2px">${fiche.nb_arbres_ombrage} arbres • ${fiche.densite_par_hectare}/ha</div></div>`;
    }

    if (fiche.commentaire_admin) {
      html += `<div><span style="font-size:11px;font-weight:700;color:var(--r500);text-transform:uppercase">Commentaire admin</span><div style="margin-top:4px;font-size:13px;color:var(--gray600)">${fiche.commentaire_admin}</div></div>`;
    }
    html += '</div></div>';
    document.getElementById('ficheDetailBody').innerHTML = html;

    // Buttons based on role and status
    const canEdit = USER.role === 'admin' || USER.role === 'superadmin' ||
                    (fiche.inspecteur_id == USER.id && ['brouillon','rejete'].includes(fiche.statut));
    const canDel  = USER.role === 'admin' || USER.role === 'superadmin' ||
                    (fiche.inspecteur_id == USER.id && ['brouillon','rejete'].includes(fiche.statut));
    const canValid= USER.role === 'admin' && fiche.statut === 'soumis';

    let footer = '';
    if (canDel)   footer += `<button class="btn btn-danger" style="flex:1" onclick="deleteFiche('${type}',${id})">🗑️ Supprimer</button>`;
    if (canEdit)  footer += `<button class="btn btn-secondary" style="flex:1" onclick="editFiche('${type}',${id})">✏️ Modifier</button>`;
    if (canValid) footer += `<button class="btn btn-primary" style="flex:1" onclick="openValidSheet(${id},'${type}')">✓ Valider</button>`;

    document.getElementById('ficheDetailFooter').innerHTML = footer || '';
    openSheet('ficheDetail');
  } catch(e) { toast(e.message, 'error'); }
}

async function deleteFiche(type, id) {
  if (!confirm('Supprimer cette fiche définitivement ?')) return;
  const eps = { profilage:'fiches-profilage', arbres:'fiches-arbres', engrais:'fiches-engrais' };
  try {
    await apiFetch(`/${eps[type]}/${id}`, 'DELETE', {});
    closeSheet('ficheDetail');
    toast('Fiche supprimée ✓', 'success');
    loadCurrentFiches();
    if (USER.role !== 'admin') loadInspDashboard();
    else loadCoopDashboard();
  } catch(e) { toast(e.message, 'error'); }
}

async function editFiche(type, id) {
  // Open the appropriate fiche sheet pre-filled
  closeSheet('ficheDetail');
  toast('Fonction de modification disponible prochainement', 'info');
  // TODO: Open edit sheet with pre-filled data
}

// ══════════════════════════════════════════════════════════════
// EXPORT PDF
// ══════════════════════════════════════════════════════════════
async function exportPDF(type = 'all', ficheId = null) {
  toast('Génération du PDF en cours...', 'info');
  try {
    const url = ficheId
      ? `${BASE}/api/export.php?type=${type}&id=${ficheId}`
      : `${BASE}/api/export.php?type=${type}`;

    const res  = await fetch(url);
    const data = await res.json();
    if (!data.success) throw new Error(data.error || 'Erreur export');

    const { jsPDF }    = window.jspdf;
    const doc          = new jsPDF({ orientation: 'portrait', format: 'a4' });
    const pageW        = doc.internal.pageSize.getWidth();
    const today        = new Date().toLocaleDateString('fr-FR');
    let   y            = 20;

    // Header
    doc.setFillColor(45, 80, 22);
    doc.rect(0, 0, pageW, 18, 'F');
    doc.setTextColor(255, 255, 255);
    doc.setFontSize(14); doc.setFont('helvetica','bold');
    doc.text('IndicatorDATA — Cacao Collector', 10, 12);
    doc.setFontSize(9); doc.setFont('helvetica','normal');
    doc.text(`Généré le ${today} par ${data.rapport.generated_by}`, pageW - 10, 12, {align:'right'});

    y = 28;
    doc.setTextColor(0,0,0);
    doc.setFontSize(16); doc.setFont('helvetica','bold');
    doc.text(data.rapport.type || 'Rapport', 10, y);
    y += 10;

    // Process fiches
    const processFicheProfilage = (fiches) => {
      if (!fiches?.length) return;
      doc.setFontSize(13); doc.setFont('helvetica','bold'); doc.setTextColor(45,80,22);
      doc.text('FICHES DE PROFILAGE DES MÉNAGES', 10, y); y += 8;
      doc.setDrawColor(45,80,22); doc.setLineWidth(0.5); doc.line(10, y, pageW-10, y); y += 8;

      fiches.forEach((f, idx) => {
        if (y > 250) { doc.addPage(); y = 20; }
        doc.setTextColor(0,0,0);
        doc.setFontSize(11); doc.setFont('helvetica','bold');
        doc.text(`Fiche #${f.id} — ${f.prod_nom||''} ${f.prod_prenom||''}`, 10, y); y += 7;
        doc.setFontSize(9); doc.setFont('helvetica','normal');

        const details = [
          ['Producteur', `${f.prod_nom||''} ${f.prod_prenom||''} (${f.prod_code||''})`],
          ['Genre', f.prod_genre||'-'],
          ['Coopérative', f.coop_nom||'-'],
          ['Communauté', f.nom_communaute||'-'],
          ['Date profilage', f.date_profilage||'-'],
          ['Inspecteur', `${f.insp_prenom||''} ${f.insp_nom||''}`],
          ['Membres ménage', `H:${f.nb_membres_hommes||0} F:${f.nb_membres_femmes||0} T:${f.nb_membres_total||0}`],
          ['Travailleurs', `H:${f.nb_travailleurs_hommes||0} F:${f.nb_travailleurs_femmes||0} T:${f.nb_travailleurs_total||0}`],
          ['Superficie certifiée', `${f.superficie_certifiee||0} ha`],
        ];

        details.forEach(([label, val]) => {
          doc.setFont('helvetica','bold'); doc.text(label + ':', 12, y);
          doc.setFont('helvetica','normal'); doc.text(String(val), 60, y);
          y += 5;
        });

        // Enfants
        if (f.enfants?.length) {
          y += 3;
          doc.setFont('helvetica','bold'); doc.setFontSize(9);
          doc.text(`Enfants dans le ménage (${f.enfants.length}):`, 12, y); y += 5;
          const enfantData = f.enfants.map(e => [
            e.nom_prenom, e.age + ' ans', e.genre,
            e.etat_scolarisation||'-',
            e.extrait_naissance||'-',
            (JSON.parse(e.travaux_effectues||'[]').length > 0) ? '⚠️ Oui' : 'Non'
          ]);
          doc.autoTable({
            startY: y,
            head: [['Nom', 'Âge', 'Genre', 'Scolarisation', 'Extrait', 'Travaux']],
            body: enfantData,
            theme: 'striped',
            headStyles: { fillColor: [45,80,22], textColor: 255, fontSize: 8 },
            bodyStyles: { fontSize: 8 },
            margin: { left: 12, right: 10 },
          });
          y = doc.lastAutoTable.finalY + 8;
        }

        // Separator
        doc.setDrawColor(200,200,200); doc.setLineWidth(0.3);
        doc.line(10, y, pageW-10, y); y += 8;
      });
    };

    const processFicheArbres = (fiches) => {
      if (!fiches?.length) return;
      if (y > 230) { doc.addPage(); y = 20; }
      doc.setFontSize(13); doc.setFont('helvetica','bold'); doc.setTextColor(45,80,22);
      doc.text("FICHES ARBRES D'OMBRAGE", 10, y); y += 8;
      doc.setDrawColor(45,80,22); doc.setLineWidth(0.5); doc.line(10, y, pageW-10, y); y += 8;

      const tableData = fiches.map(f => [
        f.prod_nom||'-', f.insp_nom||'-', f.date_collecte||'-',
        f.nb_arbres_ombrage||0, (f.densite_par_hectare||0)+'/ha',
        (f.nb_arbres_deficitaires||0)+'/ha'
      ]);
      doc.setTextColor(0,0,0);
      doc.autoTable({
        startY: y,
        head: [['Producteur','Inspecteur','Date','Nb arbres','Densité/ha','Déficit/ha']],
        body: tableData,
        theme: 'striped',
        headStyles: { fillColor: [45,80,22], textColor: 255, fontSize: 9 },
        bodyStyles: { fontSize: 9 },
        margin: { left: 10, right: 10 },
      });
      y = doc.lastAutoTable.finalY + 10;
    };

    const processFicheEngrais = (fiches) => {
      if (!fiches?.length) return;
      if (y > 230) { doc.addPage(); y = 20; }
      doc.setFontSize(13); doc.setFont('helvetica','bold'); doc.setTextColor(45,80,22);
      doc.text('FICHES ENGRAIS & PESTICIDES', 10, y); y += 8;
      doc.setDrawColor(45,80,22); doc.setLineWidth(0.5); doc.line(10, y, pageW-10, y); y += 8;

      const tableData = fiches.map(f => [
        f.prod_nom||'-', f.insp_nom||'-', f.date_collecte||'-',
        f.organiques?.length||0, f.inorganiques?.length||0, f.pesticides?.length||0
      ]);
      doc.setTextColor(0,0,0);
      doc.autoTable({
        startY: y,
        head: [['Producteur','Inspecteur','Date','Organiques','Inorganiques','Pesticides']],
        body: tableData,
        theme: 'striped',
        headStyles: { fillColor: [45,80,22], textColor: 255, fontSize: 9 },
        bodyStyles: { fontSize: 9 },
        margin: { left: 10, right: 10 },
      });
      y = doc.lastAutoTable.finalY + 10;
    };

    // Render based on type
    if (type === 'all') {
      processFicheProfilage(data.rapport.profilage);
      processFicheArbres(data.rapport.arbres);
      processFicheEngrais(data.rapport.engrais);
    } else if (type === 'profilage') {
      processFicheProfilage(data.rapport.fiches);
    } else if (type === 'arbres') {
      processFicheArbres(data.rapport.fiches);
    } else if (type === 'engrais') {
      processFicheEngrais(data.rapport.fiches);
    }

    // Footer on all pages
    const totalPages = doc.internal.getNumberOfPages();
    for (let i = 1; i <= totalPages; i++) {
      doc.setPage(i);
      doc.setFontSize(8); doc.setTextColor(150,150,150);
      doc.text(`Page ${i}/${totalPages} — IndicatorDATA Cacao Collector`, pageW/2, 290, {align:'center'});
    }

    const filename = `cacao-rapport-${type}-${today.replace(/\//g,'-')}.pdf`;
    doc.save(filename);
    toast('PDF généré avec succès ✓', 'success');
  } catch(e) {
    console.error(e);
    toast('Erreur PDF: ' + e.message, 'error');
  }
}

async function exportRecap() {
  toast('Génération du récapitulatif...', 'info');
  try {
    const [statRes, coopRes] = await Promise.all([
      apiFetch('/coop-stats').catch(() => null),
      apiFetch('/cooperatives').catch(() => null)
    ]);

    const { jsPDF } = window.jspdf;
    const doc       = new jsPDF({ orientation: 'portrait', format: 'a4' });
    const pageW     = doc.internal.pageSize.getWidth();
    const today     = new Date().toLocaleDateString('fr-FR');
    let   y         = 20;

    // Header
    doc.setFillColor(45, 80, 22);
    doc.rect(0, 0, pageW, 18, 'F');
    doc.setTextColor(255,255,255);
    doc.setFontSize(14); doc.setFont('helvetica','bold');
    doc.text('IndicatorDATA — Rapport Récapitulatif', 10, 12);
    doc.setFontSize(9); doc.setFont('helvetica','normal');
    doc.text(today, pageW-10, 12, {align:'right'});

    y = 28;
    doc.setTextColor(0,0,0);
    doc.setFontSize(16); doc.setFont('helvetica','bold');
    doc.text('Rapport Récapitulatif d\'Activité', 10, y); y += 12;

    if (statRes?.stats) {
      const s = statRes.stats;

      // Summary table
      doc.autoTable({
        startY: y,
        head: [['Indicateur', 'Valeur']],
        body: [
          ['Nombre d\'inspecteurs actifs', s.inspecteurs?.length || 0],
          ['Nombre de producteurs', s.total_producteurs || 0],
          ['Fiches profilage — Total', s.fiches_profilage?.total || 0],
          ['Fiches profilage — Validées', s.fiches_profilage?.valide || 0],
          ['Fiches profilage — En attente', s.fiches_profilage?.soumis || 0],
          ['Fiches arbres — Total', s.fiches_arbres?.total || 0],
          ['Fiches arbres — Validées', s.fiches_arbres?.valide || 0],
          ['Fiches engrais — Total', s.fiches_engrais?.total || 0],
          ['Fiches engrais — Validées', s.fiches_engrais?.valide || 0],
          ['Enfants en activité agricole', s.enfants_risque || 0],
        ],
        theme: 'striped',
        headStyles: { fillColor: [45,80,22], textColor: 255 },
        margin: { left: 10, right: 10 },
      });
      y = doc.lastAutoTable.finalY + 15;

      // Inspecteurs table
      if (s.inspecteurs?.length) {
        doc.setFontSize(12); doc.setFont('helvetica','bold');
        doc.text('Activité par inspecteur', 10, y); y += 8;
        doc.autoTable({
          startY: y,
          head: [['Inspecteur','Code','Profilage','Arbres','Engrais','Validées']],
          body: s.inspecteurs.map(i => [
            `${i.prenom} ${i.nom}`, i.code_inspecteur||'-',
            i.fiches.profilage, i.fiches.arbres, i.fiches.engrais, i.fiches.valide
          ]),
          theme: 'striped',
          headStyles: { fillColor: [45,80,22], textColor: 255, fontSize: 9 },
          bodyStyles: { fontSize: 9 },
          margin: { left: 10, right: 10 },
        });
        y = doc.lastAutoTable.finalY + 10;
      }
    }

    // Footer
    doc.setFontSize(8); doc.setTextColor(150,150,150);
    doc.text(`IndicatorDATA Cacao Collector — ${today}`, pageW/2, 290, {align:'center'});

    doc.save(`cacao-recap-${today.replace(/\//g,'-')}.pdf`);
    toast('Récapitulatif PDF généré ✓', 'success');
  } catch(e) {
    toast('Erreur: ' + e.message, 'error');
  }
}

async function openExportSheet() {
  openSheet('export');
  await loadExportList();
}

async function loadExportList() {
  const type   = document.getElementById('exportType')?.value || 'profilage';
  const select = document.getElementById('exportFicheId');
  const eps    = { profilage:'fiches-profilage', arbres:'fiches-arbres', engrais:'fiches-engrais' };
  select.innerHTML = '<option value="">Chargement...</option>';
  try {
    const res  = await apiFetch(`/${eps[type]}?statut=valide`);
    const data = res.data || [];
    if (!data.length) { select.innerHTML = '<option value="">Aucune fiche validée</option>'; return; }
    select.innerHTML = '<option value="">Sélectionner une fiche...</option>' +
      data.map(f => `<option value="${f.id}">#${f.id} — ${f.prod_nom||''} (${f.date_profilage||f.date_collecte||''})</option>`).join('');
  } catch(e) { select.innerHTML = '<option value="">Erreur de chargement</option>'; }
}

async function exportSinglePDF() {
  const type = document.getElementById('exportType')?.value;
  const id   = document.getElementById('exportFicheId')?.value;
  if (!id) return toast('Sélectionnez une fiche', 'error');
  closeSheet('export');
  await exportPDF(type, id);
}

// ══════════════════════════════════════════════════════════════
// MODE HORS LIGNE COMPLET
// ══════════════════════════════════════════════════════════════
// Override apiFetch to save locally when offline (POST/PUT)
const _origApiFetch = apiFetch;
window.apiFetch = async function(endpoint, method = 'GET', body = null) {
  // For GET requests or when online, use normal fetch
  if (method === 'GET' || navigator.onLine) {
    return _origApiFetch(endpoint, method, body);
  }

  // Offline: queue mutations locally
  if (['POST','PUT','DELETE'].includes(method)) {
    // Only queue fiche submissions
    if (endpoint.includes('fiches-')) {
      const localId = await localDB.queueRequest(endpoint, method, body);
      updatePendingBadge();
      return { success: true, offline: true, localId, message: 'Sauvegardé hors ligne' };
    }
    // Other mutations fail gracefully offline
    throw new Error('Connexion requise pour cette action');
  }

  // Offline GET: try cache
  return _origApiFetch(endpoint, method, body);
};
