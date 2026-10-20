/* ==========================================================================
   PANNACOTTACLUB — amorçage & navigation
   Une seule page pour trois usages : client (/), livreur (/livreur), admin (/admin).
   ========================================================================== */
import { api, store, ui, ic, esc, live, connection, LS, K, navigate, toast, BASE } from './core.js?v=20261019i';
import { renderHome, renderMenu, renderTrack, openCart, syncCartBar, stopLiveMap } from './views/client.js?v=20261019k';
import { configureMap } from './map.js?v=20261019k';
import { renderCourier, stopCourierLive } from './views/courier.js?v=20261019i';
import { renderAdmin, stopAdminLive } from './views/admin.js?v=20261019k';

const view = document.getElementById('view');
let current = '';
let menuStop = null;

/* ------------------------------------------------------------- les routes */
const ROUTES = {
  '/': renderHome,
  '/commander': renderMenu,
  '/suivi': renderTrack,
  '/livreur': renderCourier,
  '/admin': renderAdmin,
  '/equipe': renderCourier,
};

function params() { return new URLSearchParams(location.search); }

/* ------------------------------------------------------------ panneau QR */
/* Chaque escalier de la digue a son panneau : il ouvre le site avec ?ac=N.
   Le site mémorise l'escalier (plus besoin de GPS) puis nettoie l'URL. */
(() => {
  try {
    const u = new URL(location.href);
    const ac = parseInt(u.searchParams.get('ac'), 10);
    if (ac >= 1 && ac <= 11) {
      // Le panneau peut aussi transporter la position exacte de l'escalier
      // (saisie dans l'admin, gravée dans le QR) : ?ac=5&la=…&lo=…
      const la = parseFloat(u.searchParams.get('la'));
      const lo = parseFloat(u.searchParams.get('lo'));
      LS.set(K.acces, {
        n: ac, at: Date.now(),
        ...(Number.isFinite(la) && Number.isFinite(lo) ? { lat: la, lng: lo } : {}),
      });
      u.searchParams.delete('ac');
      u.searchParams.delete('la');
      u.searchParams.delete('lo');
      history.replaceState({}, '', u);
      sessionStorage.removeItem('pc-acces-salue');
    }
  } catch { /* URL exotique : on ignore */ }
})();

/** Chemin de la page, sans le préfixe du sous-dossier éventuel. */
function currentPath() {
  const full = location.pathname;
  const prefix = BASE.replace(/\/$/, '');
  const rel = prefix && full.startsWith(prefix) ? full.slice(prefix.length) : full;
  return (rel.replace(/\/+$/, '') || '/');
}

/** Nettoie ce qui doit l'être avant de changer d'écran. */
function teardown() {
  stopLiveMap();          // carte du livreur de la page d'accueil
  stopCourierLive();
  stopAdminLive();
  menuStop?.(); menuStop = null;
  document.querySelectorAll('.toolbar').forEach((t) => t.remove());
  syncCartBar();
}

async function route() {
  const path = currentPath();
  const handler = ROUTES[path];
  const key = path + location.search;
  if (key === current) return;
  current = key;
  teardown();

  // Espaces sécurisés : on masque le panier et la navigation grand public
  const isStaff = path === '/livreur' || path === '/admin' || path === '/equipe';
  paintHeader(path, isStaff);

  view.innerHTML = '';
  if (handler) {
    try {
      await handler(view, params());
    } catch (err) {
      console.error(err);
      view.innerHTML = `<section class="wrap" style="padding:40px 18px">${ui.banner('error', `Une erreur est survenue : ${esc(err.message)}`, 'alert')}</section>`;
    }
  } else {
    view.innerHTML = `
      <section class="wrap center" style="padding:60px 18px">
        <h1>Oups…</h1>
        <p class="muted">Cette page n'existe pas (ou plus).</p>
        <a class="btn" href="./" data-link style="max-width:320px;margin:0 auto">RETOUR À L'ACCUEIL</a>
      </section>`;
  }
  window.scrollTo(0, 0);

  // Le catalogue vivant reste synchronisé sur les écrans publics.
  if (path === '/' || path === '/commander') {
    menuStop = live('/api/events', {
      'menu:update': () => { /* géré par la vue commander */ },
      'settings:update': () => {},
    });
  }
}

/* ------------------------------------------------------------- en-tête --- */
function paintHeader(path, isStaff) {
  const header = document.getElementById('header');
  const footer = document.getElementById('footer');
  header.hidden = false;
  footer.hidden = isStaff;

  const nav = document.getElementById('nav');
  const actions = document.getElementById('headerActions');

  if (isStaff) {
    nav.innerHTML = `<a href="./" data-link>Site client</a>`;
    actions.innerHTML = '';
    return;
  }

  nav.innerHTML = `
    <a href="suivi" data-link class="${path === '/suivi' ? 'active' : ''}">Suivre ma commande</a>
    ${store.session?.role === 'courier' ? '<a href="livreur" data-link>Espace livreur</a>' : ''}`;

  actions.innerHTML = `
    <a class="icon-btn" href="suivi" data-link aria-label="Suivre ma commande" title="Suivre ma commande">${ic('route')}</a>
    <button class="icon-btn" id="headerCart" aria-label="Panier">
      ${ic('cart')}<span class="badge" id="headerCartCount" hidden>0</span>
    </button>`;

  actions.querySelector('#headerCart').addEventListener('click', openCart);
  updateHeaderCart();
}

function updateHeaderCart() {
  const badge = document.getElementById('headerCartCount');
  if (!badge) return;
  const n = store.cartCount();
  badge.hidden = n === 0;
  badge.textContent = String(n);
}

/* --------------------------------------------------------- liens & boutons */
document.addEventListener('click', (e) => {
  const link = e.target.closest('a[data-link]');
  if (link && link.origin === location.origin && !link.target) {
    e.preventDefault();
    const next = link.getAttribute('href') || './';
    if (next.replace(/^#/, '') !== '' ) navigate(next);
  }
  const opener = e.target.closest('[data-open-cart]');
  if (opener) { e.preventDefault(); openCart(); }
});

document.getElementById('cartOpen')?.addEventListener('click', openCart);
window.addEventListener('popstate', () => { current = ''; route(); });

/* ------------------------------------------------------------ état & temps */
store.on((type) => { if (type === 'cart') { syncCartBar(); updateHeaderCart(); } });

connection.onChange((online) => {
  if (online) toast('Connexion rétablie.', 'ok');
});

/* --------------------------------------------------------------- démarrage */
async function boot() {
  ui.sheet.init();

  try {
    store.config = await api.get('/api/config');
    // Le plan de la plage et vos repères de vente viennent de config.php
    configureMap({ zone: store.config.settings?.zone, map: store.config.map });
    document.getElementById('footerInfo').textContent =
      `${store.config.brand.tagline} Zone : ${store.config.settings.zone.label}. Paiement à la livraison uniquement.`;
  } catch {
    store.config = null;
  }

  // Récupère la session éventuelle (livreur/admin restent connectés).
  // Toujours interroger le serveur : le cookie de session est la référence,
  // même si ce navigateur n'a plus de jeton en mémoire.
  const tokens = LS.get(K.tokens, {});
  const jeton = tokens.courier || tokens.admin;
  if (jeton) api.token = jeton;
  try {
    const me = await api.get('/api/auth/me');
    store.session = me.user;
    if (store.session) LS.set(K.session, store.session);
  } catch (err) {
    // 401 confirmé par le serveur : on oublie le jeton mort. Erreur réseau :
    // on le conserve, ce n'est pas une déconnexion.
    if (err.status === 401) { api.token = null; LS.del(K.session); }
  }

  await route();
  syncCartBar();
  updateHeaderCart();

  setTimeout(() => document.getElementById('splash')?.classList.add('gone'), 550);

  // Actualise les positions GPS lors du retour sur l'onglet
  document.addEventListener('visibilitychange', () => {
    if (!document.hidden) updateHeaderCart();
  });
}

// Sert de point d'entrée aux scripts externes (boutons « panier » dynamiques)
window.PC = { openCart, navigate, store };

boot();

/* Service worker (installation sur l'écran d'accueil + secours hors ligne) */
if ('serviceWorker' in navigator && location.protocol === 'https:') {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('sw.js').catch(() => {});   // chemin relatif : fonctionne à la racine comme dans un sous-dossier
  });
}
