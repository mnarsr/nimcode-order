(() => {
	'use strict';
	const root = document.querySelector('.nc-app');
	const dataEl = document.getElementById('nc-data');
	if (!root || !dataEl) return;

	const D = JSON.parse(dataEl.textContent);
	const fmt = new Intl.NumberFormat('de-DE', { style: 'currency', currency: D.cur });
	const money = (n) => fmt.format(n);

	// Tiny DOM helper. Text is always inserted as text nodes (no innerHTML = no XSS).
	const el = (tag, props = {}, ...kids) => {
		const e = document.createElement(tag);
		for (const [k, v] of Object.entries(props)) {
			if (k === 'class') e.className = v;
			else if (k.startsWith('on')) e.addEventListener(k.slice(2), v);
			else if (v !== false && v != null) e.setAttribute(k, v === true ? '' : v);
		}
		kids.flat().filter((c) => c != null && c !== false).forEach((c) => e.append(c));
		return e;
	};

	async function api(path, body) {
		const h = { 'Content-Type': 'application/json' };
		if (D.nonce) h['X-WP-Nonce'] = D.nonce; // logged-in users: REST must see the same user as the page
		const res = await fetch(D.rest + path, body
			? { method: 'POST', headers: h, body: JSON.stringify(body), credentials: 'same-origin', keepalive: true }
			: { credentials: 'same-origin', headers: D.nonce ? { 'X-WP-Nonce': D.nonce } : {} });
		const j = await res.json().catch(() => ({}));
		if (!res.ok) throw new Error(j.message || 'Fehler. Bitte erneut versuchen.');
		return j;
	}

	/* ---------- Toast ---------- */
	const toastEl = el('div', { class: 'nc-toast', role: 'status' });
	let toastTimer;
	function toast(msg) {
		toastEl.textContent = msg;
		toastEl.classList.add('on');
		clearTimeout(toastTimer);
		toastTimer = setTimeout(() => toastEl.classList.remove('on'), 2400);
	}

	// Ask WooCommerce to refresh theme mini-carts (works if the theme loads cart-fragments).
	const syncTheme = () => { if (window.jQuery) window.jQuery(document.body).trigger('wc_fragment_refresh'); };

	/* ---------- State ---------- */
	let cart = { items: [], count: 0, total: 0, ship: { type: '', plz: '', city: '', cities: [], zone: false, fee: 0, min: 0, short: 0, ok: false, msg: '' } };

	const optText = (o) => o.n + (o.p > 0 ? ' (+' + money(o.p) + ')' : '');
	// Which price applies: Lieferung = 'p', Abholung = 'pp' (the server decides the real price).
	function pk() { return cart.ship.type === 'pickup' ? 'pp' : 'p'; }

	/* ---------- Delivery / pickup ---------- */
	async function setMode(type, plz, city) {
		try { cart = await api('cart/mode', { type, plz, city: city || '' }); }
		catch (e) { toast(e.message); }
		renderCart();
		syncTheme();
	}

	function modeUI() {
		const s = cart.ship;
		const btn = (type, label) => el('button', {
			type: 'button', class: s.type === type ? 'on' : '',
			onclick: () => setMode(type, type === 'delivery' ? s.plz : '', type === 'delivery' ? s.city : ''),
		}, label);
		const kids = [el('div', { class: 'nc-seg' }, btn('delivery', 'Lieferung'), btn('pickup', 'Abholung'))];
		if (!s.type) kids.push(el('p', { class: 'nc-hint' }, 'Bitte Lieferung oder Abholung wählen: Preise und Gebühren hängen davon ab.'));
		const r = D.restaurant;
		if (s.type === 'pickup' && r && (r.street || r.city)) {
			kids.push(el('p', { class: 'nc-hint' }, 'Abholung bei ' + [r.name, r.street, ((r.zip || '') + ' ' + (r.city || '')).trim()].filter(Boolean).join(', ')));
		}

		if (s.type === 'delivery') {
			const plz = el('input', { type: 'text', inputmode: 'numeric', autocomplete: 'postal-code', maxlength: '5', class: 'nc-plz', placeholder: 'Postleitzahl', value: s.plz });
			plz.addEventListener('input', () => {
				plz.value = plz.value.replace(/\D/g, '');
				if (plz.value.length === 5) setMode('delivery', plz.value, '');
			});
			kids.push(plz);
			const many = s.cities && s.cities.length > 1;
			if (many) {
				// Mehrere Orte mit dieser PLZ: Kunde wählt seinen Ort
				const sel = el('select', { class: 'nc-plz', 'aria-label': 'Ort wählen' },
					el('option', { value: '' }, 'Ort wählen …'),
					s.cities.map((c) => el('option', { value: c, selected: c === s.city }, c)));
				sel.addEventListener('change', () => setMode('delivery', s.plz, sel.value));
				kids.push(sel);
			} else if (s.city) {
				kids.push(el('p', { class: 'nc-hint' }, 'Lieferung nach ' + s.city));
			}
			if (s.msg && s.plz.length === 5 && !(many && !s.city)) kids.push(el('p', { class: 'nc-msg' }, s.msg));
			else if (s.zone && s.min > 0) kids.push(el('p', { class: 'nc-hint' }, 'Mindestbestellwert ' + money(s.min)));
		}
		return el('div', { class: 'nc-mode' }, kids);
	}

	/* ---------- Cart drawer ---------- */
	const top = el('div', { class: 'nc-top' });
	root.prepend(top);

	const bar = el('button', { type: 'button', class: 'nc-cartbar', hidden: true, onclick: () => drawer.showModal() });
	const modeBox = el('div');
	const list = el('div', { class: 'nc-lines' });
	const sumBox = el('div', { class: 'nc-sums' });
	const ctaBox = el('div');
	const drawer = el('dialog', { class: 'nc-drawer' },
		el('header', {}, el('h2', {}, 'Warenkorb'), el('button', { type: 'button', class: 'nc-x', 'aria-label': 'Schließen', onclick: () => drawer.close() }, '×')),
		el('div', { class: 'nc-dbody' }, modeBox, list),
		el('footer', {}, sumBox, ctaBox));
	drawer.addEventListener('click', (e) => { if (e.target === drawer) drawer.close(); });
	drawer.addEventListener('focusin', (e) => {
		if (!e.target.matches('input,textarea')) return;
		drawer.classList.add('nc-kb');
		setTimeout(() => e.target.scrollIntoView({ block: 'center', behavior: 'smooth' }), 300);
	});
	drawer.addEventListener('focusout', () => drawer.classList.remove('nc-kb'));

	const row = (label, value, big) => el('div', { class: 'nc-row' + (big ? ' big' : '') }, el('span', {}, label), el('span', {}, value));

	function cta() {
		const s = cart.ship;
		let hint = '';
		if (!s.type) hint = 'Bitte Lieferart wählen';
		else if (!s.ok) hint = s.msg || ('Noch ' + money(s.short) + ' bis zum Mindestbestellwert');
		return hint ? el('span', { class: 'nc-btn nc-off' }, hint) : el('a', { class: 'nc-btn', href: D.checkout }, 'Zur Kasse');
	}

	function lineEl(it) {
		const note = el('input', { type: 'text', class: 'nc-noteinp', maxlength: '120', placeholder: 'Anmerkung zum Artikel', value: it.note || '' });
		let timer;
		const save = () => { clearTimeout(timer); saveNote(it, note.value); };
		note.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(save, 700); });
		note.addEventListener('change', save);
		// Notes stay collapsed (less clutter) until the customer wants to write one
		const noteBox = it.note ? note : el('button', { type: 'button', class: 'nc-addnote', onclick: (e) => { e.currentTarget.replaceWith(note); note.focus(); } }, '+ Anmerkung hinzufügen');
		return el('div', { class: 'nc-line' },
			el('div', { class: 'nc-line-info' },
				el('b', {}, it.name),
				it.opts.length ? el('small', {}, it.opts.map(optText).join(', ')) : null,
				noteBox),
			el('div', { class: 'nc-line-side' },
				el('span', {}, money(it.total)),
				stepper(it.qty, (q) => setQty(it.key, q))));
	}

	function renderCart() {
		const s = cart.ship;
		bar.hidden = cart.count < 1;
		bar.replaceChildren(el('span', {}, 'Warenkorb · ' + cart.count), el('strong', {}, money(cart.total)));
		top.replaceChildren(modeUI());
		modeBox.replaceChildren(modeUI());
		list.replaceChildren(...(cart.items.length ? cart.items.map(lineEl) : [el('p', { class: 'nc-empty' }, 'Der Warenkorb ist leer.')]));
		sumBox.replaceChildren(...[
			row('Zwischensumme', money(cart.total)),
			s.type === 'delivery' && s.zone ? row('Liefergebühr', s.fee > 0 ? money(s.fee) : 'Gratis') : null,
			row('Gesamt', money(cart.total + (s.zone ? s.fee : 0)), true),
		].filter(Boolean));
		ctaBox.replaceChildren(cta());
		refreshPrices();
		if (cart.count < 1 && drawer.open) drawer.close();
		// Theme integration: header cart badge
		document.dispatchEvent(new CustomEvent('nimcode:cart', { detail: { count: cart.count, total: cart.total } }));
	}

	// Show the price of the chosen mode on every menu card (plus the other price as a hint).
	function refreshPrices() {
		const pickup = cart.ship.type === 'pickup';
		root.querySelectorAll('.nc-price').forEach((e) => {
			const p = D.products[e.dataset.id];
			if (!p) return;
			e.textContent = (p.v ? 'ab ' : '') + money(pickup ? p.pp : p.p);
			const alt = e.nextElementSibling;
			if (!alt || !alt.classList.contains('nc-alt')) return;
			alt.replaceChildren();
			if (p.pp < p.p - 0.004) {
				if (pickup) alt.append(el('s', {}, money(p.p)));
				else alt.append('Abholung ' + money(p.pp));
			}
		});
	}

	function stepper(qty, onChange) {
		return el('div', { class: 'nc-step' },
			el('button', { type: 'button', 'aria-label': 'Weniger', onclick: () => onChange(qty - 1) }, '−'),
			el('span', {}, String(qty)),
			el('button', { type: 'button', 'aria-label': 'Mehr', onclick: () => onChange(qty + 1) }, '+'));
	}

	async function saveNote(it, val) {
		it.note = val.trim();
		try { await api('cart/note', { key: it.key, note: it.note }); }
		catch (e) { toast(e.message); }
	}

	async function setQty(key, qty) {
		// Optimistic update: the UI reacts instantly, the server confirms afterwards.
		const it = cart.items.find((i) => i.key === key);
		if (it) {
			const unit = it.total / it.qty;
			it.qty = Math.max(0, qty);
			it.total = unit * it.qty;
			cart.items = cart.items.filter((i) => i.qty > 0);
			cart.count = cart.items.reduce((s, i) => s + i.qty, 0);
			cart.total = cart.items.reduce((s, i) => s + i.total, 0);
			renderCart();
		}
		try { cart = await api('cart/qty', { key, qty }); }
		catch (e) { toast(e.message); try { cart = await api('cart'); } catch (_) {} }
		renderCart();
		syncTheme();
	}

	async function addToCart(payload) {
		cart = await api('cart/add', payload);
		renderCart();
		syncTheme();
		toast('Hinzugefügt');
	}

	/* ---------- Product modal (variants + extras + note) ---------- */
	function openProduct(id) {
		const p = D.products[id];
		if (!p) return;
		let qty = 1;
		let vid = p.v ? p.v[0].id : 0;
		let groups = [];
		const fieldsBox = el('div');

		const priceBtn = el('button', { type: 'button', class: 'nc-btn' });
		const qtyBox = el('div');

		const varSel = p.v ? el('select', { id: 'nc-var', name: 'nc-var', class: 'nc-varsel' },
			p.v.map((v) => el('option', { value: String(v.id) }, v.n + ' · ' + money(v[pk()])))) : null;
		const varField = p.v ? el('div', { class: 'nc-varrow' }, el('label', { for: 'nc-var' }, p.vl || 'Variante'), varSel) : null;

		const buildFields = () => {
			groups = (p.v ? p.v.find((v) => v.id === vid).g : p.g)
				.map((g) => ({ g, inputs: [], sub: el('small', { class: 'nc-acc-sub' }) }));
			fieldsBox.replaceChildren(...groups.map(({ g, inputs, sub }) => {
				const single = g.type === 'single';
				const name = 'g' + g.id;
				const opts = [];
				if (single && !g.req) {
					opts.push(el('label', { class: 'nc-opt' },
						el('input', { type: 'radio', name, value: '', checked: true }), el('span', {}, 'Ohne')));
				}
				g.o.forEach((o, i) => {
					const input = el('input', {
						type: single ? 'radio' : 'checkbox', name, value: String(i),
						checked: single && g.req && i === 0,
					});
					inputs.push(input);
					opts.push(el('label', { class: 'nc-opt' }, input, el('span', {}, o.n),
						el('em', {}, o.p > 0 ? '+' + money(o.p) : '')));
				});
				return el('details', { class: 'nc-acc', open: !!g.req },
					el('summary', {}, el('span', { class: 'nc-acc-t' }, 'Auswählen ' + g.t),
						g.req ? el('em', { class: 'nc-req' }, 'Pflicht') : null, sub),
					el('div', { class: 'nc-acc-body' }, g.max ? el('small', { class: 'nc-hint' }, 'max. ' + g.max) : null, opts));
			}));
		};

		const note = el('textarea', { maxlength: '120', rows: '2', placeholder: 'Zusätzliche Kommentare … (z. B. ohne Zwiebeln)' });

		function selection() {
			const sel = {};
			groups.forEach(({ g, inputs }) => {
				sel[g.id] = inputs.map((inp, i) => (inp.checked ? i : -1)).filter((i) => i >= 0);
			});
			return sel;
		}

		function refresh() {
			let extra = 0;
			groups.forEach(({ g, inputs, sub }) => {
				const picked = inputs.filter((i) => i.checked);
				picked.forEach((inp) => { extra += g.o[inputs.indexOf(inp)].p; });
				sub.textContent = picked.map((inp) => g.o[inputs.indexOf(inp)].n).join(', ');
				if (g.type === 'multi' && g.max > 0) {
					inputs.forEach((inp) => { inp.disabled = !inp.checked && picked.length >= g.max; });
				}
			});
			const base = p.v ? p.v.find((v) => v.id === vid)[pk()] : p[pk()];
			priceBtn.textContent = 'In den Warenkorb · ' + money((base + extra) * qty);
			qtyBox.replaceChildren(stepper(qty, (q) => { qty = Math.min(20, Math.max(1, q)); refresh(); }));
		}

		priceBtn.addEventListener('click', async () => {
			const sel = selection();
			const missing = groups.find(({ g }) => g.req && !sel[g.id].length);
			if (missing) return toast('Bitte wählen: ' + missing.g.t);
			priceBtn.disabled = true;
			try {
				await addToCart({ product_id: Number(id), variation_id: vid, qty, opts: sel, note: note.value.trim() });
				dlg.close();
			} catch (e) { toast(e.message); priceBtn.disabled = false; }
		});

		const dlg = el('dialog', { class: 'nc-modal' },
			el('header', {}, el('h2', {}, p.n), el('button', { type: 'button', class: 'nc-x', 'aria-label': 'Schließen', onclick: () => dlg.close() }, '×')),
			el('div', { class: 'nc-body' }, varField, fieldsBox, note),
			el('footer', {}, qtyBox, priceBtn));
		dlg.addEventListener('click', (e) => { if (e.target === dlg) dlg.close(); });
		dlg.addEventListener('close', () => dlg.remove());
		dlg.addEventListener('change', (e) => {
			if (e.target.name === 'nc-var') { vid = Number(e.target.value); buildFields(); }
			refresh();
		});

		document.body.append(dlg);
		buildFields();
		refresh();
		dlg.showModal();
	}

	/* ---------- Wire up ---------- */
	root.addEventListener('click', async (e) => {
		const btn = e.target.closest('.nc-add');
		if (!btn) return;
		if (D.paused) return toast(D.paused);
		if (btn.closest('.nc-na')) return toast(btn.closest('.nc-card').querySelector('.nc-avail')?.textContent || 'Aktuell nicht verfügbar.');
		const id = btn.dataset.id;
		const p = D.products[id];
		if (p && (p.g.length || p.v)) return openProduct(id);
		btn.disabled = true;
		try { await addToCart({ product_id: Number(id), variation_id: 0, qty: 1, opts: {}, note: '' }); }
		catch (err) { toast(err.message); }
		btn.disabled = false;
	});

	document.body.append(bar, drawer, toastEl);
	document.addEventListener('nimcode:open-cart', () => { if (cart.count > 0) drawer.showModal(); });
	renderCart();
	api('cart').then((c) => {
		cart = c;
		renderCart();
		if (location.hash === '#cart' && cart.count > 0) drawer.showModal();
	}).catch(() => {});

	/* ---------- Category navigation: highlight the active category ---------- */
	const nav = root.querySelector('.nc-nav');
	const navLinks = nav ? [...nav.querySelectorAll('a')] : [];
	if (navLinks.length && 'IntersectionObserver' in window) {
		const byId = new Map(navLinks.map((a) => [a.getAttribute('href').slice(1), a]));
		const io = new IntersectionObserver((entries) => {
			entries.forEach((en) => {
				if (!en.isIntersecting) return;
				const a = byId.get(en.target.id);
				if (!a) return;
				navLinks.forEach((x) => x.classList.remove('on'));
				a.classList.add('on');
				if (nav.scrollWidth > nav.clientWidth && getComputedStyle(nav).flexDirection === 'row') {
					nav.scrollTo({ left: a.offsetLeft - (nav.clientWidth - a.offsetWidth) / 2, behavior: 'smooth' });
				}
			});
		}, { rootMargin: '-10% 0px -60% 0px' });
		root.querySelectorAll('.nc-cat').forEach((sec) => io.observe(sec));
	}

	/* ---------- Category row: arrow buttons + mouse drag (swipe works natively on phones) ---------- */
	const wrap = root.querySelector('.nc-navwrap');
	if (nav && wrap) {
		const [prev, next] = wrap.querySelectorAll('.nc-navbtn');
		const update = () => {
			const max = nav.scrollWidth - nav.clientWidth;
			prev.hidden = nav.scrollLeft < 4;
			next.hidden = nav.scrollLeft > max - 4;
			nav.classList.toggle('fade-l', !prev.hidden);
			nav.classList.toggle('fade-r', !next.hidden);
		};
		[prev, next].forEach((b) => b.addEventListener('click', () => {
			nav.scrollBy({ left: Number(b.dataset.dir) * nav.clientWidth * 0.7, behavior: 'smooth' });
		}));
		nav.addEventListener('scroll', update, { passive: true });
		window.addEventListener('resize', update);
		let down = false, sx = 0, sl = 0, moved = false;
		nav.addEventListener('pointerdown', (e) => { if (e.pointerType === 'mouse') { down = true; moved = false; sx = e.clientX; sl = nav.scrollLeft; } });
		window.addEventListener('pointermove', (e) => {
			if (!down) return;
			const dx = e.clientX - sx;
			if (Math.abs(dx) > 5) moved = true;
			nav.scrollLeft = sl - dx;
		});
		window.addEventListener('pointerup', () => { down = false; });
		nav.addEventListener('dragstart', (e) => e.preventDefault());
		nav.addEventListener('click', (e) => { if (moved) { e.preventDefault(); moved = false; } }, true);
		update();
		setTimeout(update, 300);
	}
})();
