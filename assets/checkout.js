(() => {
	'use strict';
	const box = document.getElementById('nc-co');
	const cfgEl = document.getElementById('nc-co-data');
	if (!box || !cfgEl) return;

	const C = JSON.parse(cfgEl.textContent);
	const fmt = new Intl.NumberFormat('de-DE', { style: 'currency', currency: C.cur });
	const money = (n) => fmt.format(n);

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
		if (C.nonce) h['X-WP-Nonce'] = C.nonce; // logged-in users: REST must see the same user as the page (otherwise the cart looks empty)
		const res = await fetch(C.rest + path, body
			? { method: 'POST', headers: h, body: JSON.stringify(body), credentials: 'same-origin' }
			: { credentials: 'same-origin', headers: C.nonce ? { 'X-WP-Nonce': C.nonce } : {} });
		const j = await res.json().catch(() => ({}));
		if (!res.ok) throw new Error(j.message || 'Fehler. Bitte erneut versuchen.');
		return j;
	}

	// Remember the customer's details in their own browser for the next order.
	const mem = {
		get() { try { return JSON.parse(localStorage.getItem('nc_customer') || '{}'); } catch (_) { return {}; } },
		set(o) { try { localStorage.setItem('nc_customer', JSON.stringify(o)); } catch (_) {} },
	};

	let draft = {}; // what the customer already typed (kept when the page re-renders)

	const backLink = () => el('a', { class: 'nc-link', href: C.menu }, '← Zurück zur Speisekarte');

	function message(text) {
		box.replaceChildren(el('div', { class: 'nc-co-box' }, el('p', {}, text), el('a', { class: 'nc-btn', href: C.menu }, 'Zur Speisekarte')));
	}

	async function load() {
		try { render(await api('checkout/info')); }
		catch (e) { message(e.message); }
	}

	async function setQty(key, qty) {
		try {
			const c = await api('cart/qty', { key, qty: Math.max(0, Math.min(20, qty)) });
			document.dispatchEvent(new CustomEvent('nimcode:cart', { detail: { count: c.count } }));
		} catch (_) {}
		load();
	}

	async function setMode(type, plz, city) {
		try { await api('cart/mode', { type, plz, city: city || '' }); } catch (_) {}
		load();
	}

	function modeUI(s) {
		const btn = (type, label) => el('button', {
			type: 'button', class: s.type === type ? 'on' : '',
			onclick: () => setMode(type, type === 'delivery' ? s.plz : '', type === 'delivery' ? s.city : ''),
		}, label);
		const kids = [el('div', { class: 'nc-seg' }, btn('delivery', 'Lieferung'), btn('pickup', 'Abholung'))];
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

	let rest = null; // restaurant address (from the server)
	function addrCard() {
		if (!rest || !(rest.street || rest.city)) return null;
		return el('div', { class: 'nc-addr' },
			el('strong', {}, 'Abholung bei'),
			el('p', {}, rest.name || null, rest.name ? el('br') : null, rest.street || null, rest.street ? el('br') : null,
				((rest.zip || '') + ' ' + (rest.city || '')).trim() || null, rest.phone ? el('br') : null, rest.phone || null),
			rest.maps ? el('a', { class: 'nc-link', href: rest.maps, target: '_blank', rel: 'noopener' }, 'In Google Maps öffnen') : null);
	}

	function success(r) {
		const when = r.time === 'Sofort' ? 'so schnell wie möglich' : 'um ' + r.time + ' Uhr';
		draft = {};
		box.replaceChildren(el('div', { class: 'nc-co-box' },
			el('h2', {}, 'Vielen Dank!'),
			el('p', {}, 'Deine Bestellung Nr. ' + r.number + ' ist bei uns eingegangen.'),
			el('p', {}, (r.type === 'delivery' ? 'Lieferung ' : 'Abholung ') + when + ' · ' + money(r.total)),
			r.type === 'pickup' ? addrCard() : null,
			el('a', { class: 'nc-btn', href: C.menu }, 'Neue Bestellung')));
		window.scrollTo({ top: 0 });
		document.dispatchEvent(new CustomEvent('nimcode:cart', { detail: { count: 0 } }));
		if (window.jQuery) window.jQuery(document.body).trigger('wc_fragment_refresh');
	}

	function render(info) {
		const cart = info.cart;
		const s = cart.ship;
		if (cart.count < 1) return message('Dein Warenkorb ist leer.');

		const delivery = s.type === 'delivery';
		const fee = delivery && s.zone ? s.fee : 0;
		const me = mem.get();
		const val = (n) => (draft[n] != null ? draft[n] : (me[n] || ''));

		const summary = el('div', { class: 'nc-co-sum' },
			cart.items.map((it) => el('div', { class: 'nc-co-line' },
				el('span', { class: 'nc-co-name' }, it.name,
					it.opts.length ? el('small', {}, it.opts.map((o) => o.n + (o.p > 0 ? ' (+' + money(o.p) + ')' : '')).join(', ')) : null,
					it.note ? el('small', { class: 'nc-note' }, '„' + it.note + '“') : null,
					el('span', { class: 'nc-step' },
						el('button', { type: 'button', 'aria-label': 'Weniger', onclick: () => setQty(it.key, it.qty - 1) }, '−'),
						el('span', {}, String(it.qty)),
						el('button', { type: 'button', 'aria-label': 'Mehr', onclick: () => setQty(it.key, it.qty + 1) }, '+'),
						el('button', { type: 'button', class: 'nc-del', 'aria-label': 'Entfernen', onclick: () => setQty(it.key, 0) }, 'Entfernen'))),
				el('span', { class: 'nc-co-price' }, money(it.total)))),
			el('div', { class: 'nc-row' }, el('span', {}, 'Zwischensumme'), el('span', {}, money(cart.total))),
			delivery && s.zone ? el('div', { class: 'nc-row' }, el('span', {}, 'Liefergebühr'), el('span', {}, fee > 0 ? money(fee) : 'Gratis')) : null,
			el('div', { class: 'nc-row big' }, el('span', {}, 'Gesamt'), el('span', {}, money(cart.total + fee))));

		rest = info.restaurant;
		const head = [el('h2', {}, 'Lieferung oder Abholung?'), modeUI(s)];
		// Two columns on large screens: form on the left, order summary on the right
		const layout = (...main) => box.replaceChildren(backLink(), el('div', { class: 'nc-co-grid' },
			el('aside', { class: 'nc-co-side' }, el('h2', {}, 'Deine Bestellung'), summary),
			el('div', { class: 'nc-co-main' }, ...main)));
		if (s.type === 'pickup') { const a = addrCard(); if (a) head.push(a); }

		// Step 1: the customer must choose delivery/pickup (and a valid PLZ / minimum order) first.
		if (!s.type || !s.ok) {
			const hint = !s.type ? 'Bitte wähle Lieferung oder Abholung.'
				: (s.msg || 'Mindestbestellwert noch nicht erreicht (noch ' + money(s.short) + ').');
			return layout(...head, el('p', { class: 'nc-hint2' }, hint));
		}

		/* Step 2: details */
		const field = (label, name, props = {}) => el('label', { class: 'nc-f' }, el('span', {}, label),
			el('input', Object.assign({ name, value: val(name) }, props)));

		const times = [];
		if (info.open_now) times.push(['asap', 'So schnell wie möglich (ca. ' + info.prep + ' Min.)']);
		info.slots.forEach((t) => times.push([t, t + ' Uhr']));
		const closed = !times.length;
		const timeSel = el('select', { name: 'time' }, times.map(([v, l]) => el('option', { value: v }, l)));
		if (draft.time && times.some((t) => t[0] === draft.time)) timeSel.value = draft.time;

		const where = delivery ? 'bei Lieferung' : 'bei Abholung';
		const pays = [['cash', 'Barzahlung ' + where]];
		if (info.card) pays.push(['card', 'Kartenzahlung ' + where]);
		const payNow = pays.some((p) => p[0] === draft.pay) ? draft.pay : pays[0][0];

		const errEl = el('p', { class: 'nc-err', role: 'alert' });
		const submit = el('button', { type: 'submit', class: 'nc-btn', disabled: closed }, 'Jetzt bestellen · ' + money(cart.total + fee));

		const form = el('form', { novalidate: true },
			el('h2', {}, 'Deine Daten'),
			field('Name', 'name', { required: true, autocomplete: 'name' }),
			field('Telefon', 'phone', { required: true, type: 'tel', autocomplete: 'tel', inputmode: 'tel' }),
			field('E-Mail (optional)', 'email', { type: 'email', autocomplete: 'email' }),
			delivery ? field('Straße und Hausnummer', 'street', { required: true, autocomplete: 'street-address' }) : null,
			delivery ? (s.city
				? el('p', { class: 'nc-hint' }, 'Lieferung nach ' + s.plz + ' ' + s.city)
				: field('Ort', 'city', { required: true, autocomplete: 'address-level2', value: val('city') || info.city || '' })) : null,

			el('h2', {}, delivery ? 'Lieferzeit' : 'Abholzeit'),
			closed
				? el('div', { class: 'nc-closed' }, (info.paused
					? (info.paused_msg || 'Bestellungen sind vorübergehend nicht möglich.')
					: 'Heute können wir keine Bestellungen mehr annehmen.') + (info.hours.length && !info.paused ? ' Öffnungszeiten: ' + info.hours.join(' · ') : ''))
				: el('label', { class: 'nc-f' }, timeSel),

			el('h2', {}, 'Zahlung'),
			pays.map(([v, l]) => el('label', { class: 'nc-opt' }, el('input', { type: 'radio', name: 'pay', value: v, checked: v === payNow }), el('span', {}, l))),

			el('label', { class: 'nc-f' }, el('span', {}, 'Anmerkung zur Bestellung (optional)'),
				el('textarea', { name: 'note', rows: '2', maxlength: '300' }, draft.note || '')),
			el('div', { class: 'nc-hp', 'aria-hidden': 'true' }, el('input', { name: 'website', tabindex: '-1', autocomplete: 'off' })),
			errEl, submit);

		form.addEventListener('input', (e) => { if (e.target.name) draft[e.target.name] = e.target.value; });
		form.addEventListener('submit', async (e) => {
			e.preventDefault();
			const fd = Object.fromEntries(new FormData(form));
			errEl.textContent = '';
			submit.disabled = true;
			try {
				const r = await api('checkout', fd);
				mem.set({ name: fd.name, phone: fd.phone, email: fd.email, street: fd.street, city: fd.city });
				success(r);
			} catch (err) {
				errEl.textContent = err.message;
				submit.disabled = false;
			}
		});

		layout(...head, form);
	}

	box.replaceChildren(el('p', { class: 'nc-co-box' }, 'Lädt …'));
	load();
})();
