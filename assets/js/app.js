document.addEventListener('DOMContentLoaded', () => {
  const price = document.querySelector('input[name="unit_price"]');
  const quantity = document.querySelector('input[name="quantity"]');
  const lineTotal = document.getElementById('lineTotal');
  const formatNpr = (n) => 'रु ' + (Number.isFinite(n) ? n : 0).toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2});
  function updateTotal() {
    if (!price || !quantity || !lineTotal) return;
    lineTotal.textContent = formatNpr((Number(price.value) || 0) * (Number(quantity.value) || 0));
  }
  price?.addEventListener('input', updateTotal);
  quantity?.addEventListener('input', updateTotal);
  updateTotal();
});


// Slide-out navigation menu on the dashboard.
document.addEventListener('DOMContentLoaded', () => {
  const toggle = document.getElementById('menuToggle');
  const menu = document.getElementById('sideMenu');
  const scrim = document.getElementById('sidebarScrim');
  const close = document.getElementById('menuClose');
  if (!toggle || !menu || !scrim) return;
  const setOpen = (open) => {
    menu.classList.toggle('open', open);
    scrim.classList.toggle('open', open);
    toggle.setAttribute('aria-expanded', String(open));
    document.body.style.overflow = open ? 'hidden' : '';
  };
  toggle.addEventListener('click', () => setOpen(!menu.classList.contains('open')));
  close?.addEventListener('click', () => setOpen(false));
  scrim.addEventListener('click', () => setOpen(false));
  document.addEventListener('keydown', e => { if (e.key === 'Escape') setOpen(false); });
});
