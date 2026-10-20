(function () {
	'use strict';

	var money = new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' });
	var num = new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
	var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

	function el(tag, cls, text) {
		var e = document.createElement(tag);
		if (cls) e.className = cls;
		if (text != null) e.textContent = text;
		return e;
	}

	function fmt(v) {
		return v == null ? '—' : money.format(v);
	}

	function itemNode(it, mode, showChange) {
		var li = el('li', 'spt-item');
		li.appendChild(el('span', 'spt-label', it.label));

		if (mode === 'both') {
			li.appendChild(el('span', 'spt-price', 'Bid ' + fmt(it.bid) + ' / Ask ' + fmt(it.ask)));
		} else {
			var v = mode === 'bid' ? it.bid : it.ask;
			if (v == null) v = mode === 'bid' ? it.ask : it.bid;
			li.appendChild(el('span', 'spt-price', fmt(v)));
		}

		if (showChange && it.change != null) {
			var up = it.change >= 0;
			var txt = (up ? '▲ ' : '▼ ') + num.format(Math.abs(it.change));
			if (it.change_pct != null) txt += ' (' + num.format(Math.abs(it.change_pct)) + '%)';
			var c = el('span', 'spt-change', txt);
			c.setAttribute('aria-label', (up ? 'up ' : 'down ') + txt.slice(2));
			li.classList.add(up ? 'spt-up' : 'spt-down');
			li.appendChild(c);
		}
		return li;
	}

	// Scroll only when the prices don't fit; otherwise they sit still and centered.
	function fitToWidth(bar) {
		var viewport = bar.querySelector('.spt-viewport');
		var track = bar.querySelector('.spt-track');
		Array.prototype.forEach.call(track.querySelectorAll('[data-spt-clone]'), function (n) {
			n.parentNode.removeChild(n);
		});
		bar.classList.remove('spt-scrolling');
		if (reduceMotion || track.scrollWidth <= viewport.clientWidth) return;

		Array.prototype.slice.call(track.children).forEach(function (n) {
			var copy = n.cloneNode(true);
			copy.setAttribute('data-spt-clone', '');
			copy.setAttribute('aria-hidden', 'true');
			track.appendChild(copy);
		});
		track.style.setProperty('--spt-duration', Math.max(15, track.scrollWidth / 2 / 40) + 's');
		bar.classList.add('spt-scrolling');
	}

	function render(bar, data) {
		var track = bar.querySelector('.spt-track');
		var time = bar.querySelector('.spt-time');
		var mode = bar.getAttribute('data-price');
		var showChange = bar.getAttribute('data-change') === '1';

		if (!data || !data.items || !data.items.length) {
			bar.hidden = true;
			return;
		}
		bar.hidden = false;
		track.textContent = '';
		data.items.forEach(function (it) {
			track.appendChild(itemNode(it, mode, showChange));
		});
		if (time && data.updated) {
			var at = new Date(data.updated * 1000).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
			time.textContent = (data.stale ? 'Delayed · ' : 'Spot · ') + at;
		}
		fitToWidth(bar);
	}

	function load(bar) {
		fetch(bar.getAttribute('data-endpoint'), { cache: 'no-store', credentials: 'omit' })
			.then(function (r) {
				if (!r.ok) throw new Error('HTTP ' + r.status);
				return r.json();
			})
			.then(function (data) {
				render(bar, data);
			})
			.catch(function () {
				// Keep whatever is already showing; the next refresh will try again.
			});
	}

	function init(bar) {
		if (bar.hasAttribute('data-spt-ready')) return;
		bar.setAttribute('data-spt-ready', '');
		load(bar);

		var every = Math.max(15, parseInt(bar.getAttribute('data-refresh'), 10) || 60) * 1000;
		setInterval(function () {
			if (!document.hidden) load(bar);
		}, every);

		var t;
		window.addEventListener('resize', function () {
			clearTimeout(t);
			t = setTimeout(function () { fitToWidth(bar); }, 200);
		});
	}

	function initAll(root) {
		Array.prototype.forEach.call((root || document).querySelectorAll('.spt-bar'), init);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function () { initAll(); });
	} else {
		initAll();
	}

	// Elementor editor: the shortcode widget is re-rendered without a page load.
	function hookElementor() {
		window.elementorFrontend.hooks.addAction('frontend/element_ready/shortcode.default', function ($scope) {
			initAll($scope[0]);
		});
	}
	if (window.elementorFrontend && window.elementorFrontend.hooks) {
		hookElementor();
	} else if (window.jQuery) {
		window.jQuery(window).on('elementor/frontend/init', hookElementor);
	}
})();
