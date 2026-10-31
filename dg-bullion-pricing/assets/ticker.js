/* DG Bullion ticker */
(function () {
	'use strict';
	var cfg = window.DGBPTicker || {};
	var bar, itemsEl, trackEl, timeEl, last = {}, timer;

	function money(n) {
		return '$' + Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
	}

	function esc(s) {
		return String(s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	function itemHTML(metal, p) {
		var price = cfg.show === 'both' ? money(p.bid) + ' / ' + money(p.ask) : money(cfg.show === 'bid' ? p.bid : p.ask);
		var h = '<span class="dgbp-item" data-metal="' + esc(metal) + '">' +
			'<span class="dgbp-name">' + esc((cfg.names || {})[metal] || metal) + '</span>' +
			'<span class="dgbp-price">' + esc(price) + '</span>';
		if (cfg.change) {
			var c = Number(p.change) || 0;
			var cls = c > 0 ? 'dgbp-up' : (c < 0 ? 'dgbp-down' : 'dgbp-flat');
			var arrow = c > 0 ? '▲' : (c < 0 ? '▼' : '•');
			h += '<span class="dgbp-chg ' + cls + '">' + arrow + ' ' +
				Math.abs(c).toFixed(2) + ' (' + Math.abs(Number(p.change_pct) || 0).toFixed(2) + '%)</span>';
		}
		return h + '</span>';
	}

	function setTime(ts) {
		if (!timeEl || !ts) { return; }
		var d = new Date(ts * 1000);
		timeEl.textContent = 'As of ' + d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
	}

	function layout() {
		// Remove previous duplicate used for seamless scrolling.
		var dup = itemsEl.querySelectorAll('[data-dup]');
		for (var i = 0; i < dup.length; i++) { dup[i].parentNode.removeChild(dup[i]); }
		bar.classList.remove('dgbp-scrolling');

		var needScroll = bar.classList.contains('dgbp-mode-scroll') || itemsEl.offsetWidth > trackEl.clientWidth + 2;
		if (!needScroll) { return; }

		var originals = Array.prototype.slice.call(itemsEl.children);
		originals.forEach(function (n) {
			var c = n.cloneNode(true);
			c.setAttribute('data-dup', '1');
			c.setAttribute('aria-hidden', 'true');
			itemsEl.appendChild(c);
		});
		bar.classList.add('dgbp-scrolling');
		// ~45px per second regardless of content width.
		var seconds = Math.max(15, Math.round((itemsEl.offsetWidth / 2) / 45));
		bar.style.setProperty('--dgbp-speed', seconds + 's');
	}

	function render(data) {
		var prices = data.prices || {}, html = '', flashes = {};
		Object.keys(prices).forEach(function (m) {
			html += itemHTML(m, prices[m]);
			if (last[m] !== undefined && last[m] !== prices[m].ask) {
				flashes[m] = prices[m].ask > last[m] ? 'dgbp-flash-up' : 'dgbp-flash-down';
			}
			last[m] = prices[m].ask;
		});
		if (!html) { return; }
		itemsEl.innerHTML = html;
		bar.classList.toggle('dgbp-stale', !!data.stale);
		setTime(data.updated);
		layout();
		Object.keys(flashes).forEach(function (m) {
			var els = itemsEl.querySelectorAll('[data-metal="' + m + '"]');
			for (var i = 0; i < els.length; i++) { els[i].classList.add(flashes[m]); }
		});
		setTimeout(function () {
			var f = itemsEl.querySelectorAll('.dgbp-flash-up,.dgbp-flash-down');
			for (var i = 0; i < f.length; i++) { f[i].classList.remove('dgbp-flash-up', 'dgbp-flash-down'); }
		}, 1500);
	}

	function refresh() {
		if (document.hidden || !cfg.endpoint) { return; }
		fetch(cfg.endpoint, { credentials: 'omit', cache: 'no-store' })
			.then(function (r) { return r.ok ? r.json() : null; })
			.then(function (d) { if (d) { render(d); } })
			.catch(function () { bar.classList.add('dgbp-stale'); });
	}

	function place() {
		if (!bar.classList.contains('dgbp-pending')) { return; }
		var target = null;
		(cfg.selector || '').split(',').some(function (sel) {
			sel = sel.trim();
			if (!sel) { return false; }
			try { target = document.querySelector(sel); } catch (e) { target = null; }
			return !!target;
		});
		if (target && target.parentNode && !target.contains(bar)) {
			target.parentNode.insertBefore(bar, target.nextSibling);
		} else if (!target) {
			document.body.insertBefore(bar, document.body.firstChild);
		}
		bar.classList.remove('dgbp-pending');
	}

	function start() {
		bar = document.getElementById('dgbp-ticker');
		if (!bar) { return; }
		itemsEl = bar.querySelector('.dgbp-items');
		trackEl = bar.querySelector('.dgbp-track');
		timeEl = bar.querySelector('.dgbp-time');

		place();
		setTime(Number(bar.getAttribute('data-updated')));
		layout();

		// Prices in the cached page HTML may be old: refresh straight away.
		refresh();
		timer = setInterval(refresh, Math.max(10, cfg.refresh || 30) * 1000);
		document.addEventListener('visibilitychange', function () { if (!document.hidden) { refresh(); } });

		var rt;
		window.addEventListener('resize', function () { clearTimeout(rt); rt = setTimeout(layout, 200); });
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', start);
	} else {
		start();
	}
})();
