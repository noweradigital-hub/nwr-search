/**
 * Nowera Search — instant product search panel.
 * No dependencies and no build step; works with any theme or page builder.
 *
 * Every element with [data-nwr-search] is enhanced. Call window.nwrSearch.init()
 * after injecting markup dynamically (popups, off-canvas menus, AJAX sections).
 * The panel is moved to <body> and positioned against the field, so header
 * wrappers with overflow or transforms cannot clip it.
 */
(() => {
	'use strict';

	const config = window.nwrSearchConfig;
	if (!config || !config.host || !config.collection || !config.key) {
		return;
	}

	const MIN_CHARS = 2;
	const DEBOUNCE_MS = 120;
	// The server lists only the fields the live collection has.
	const QUERY_BY = config.queryBy || 'title,sku,skus,gtins,brands,categories,short_description';
	const QUERY_BY_WEIGHTS = config.queryWeights || '12,10,10,10,4,6,1';
	const FIELDS = 'id,title,url,image,price,price_max,regular_price,on_sale,in_stock,related_ids,post_type,excerpt,file_url,file_type,file_size';
	// Manual boost first, popularity (and freshness) as tie-breakers; missing values sort last.
	// A collection indexed by an older plugin version has no boost field yet, and
	// sorting by a missing field is an error — so the server only adds it once it exists.
	const RANK = config.rank || (config.hasBoost ? 'boost(missing_values: last):desc,popularity:desc' : 'popularity:desc');
	// While typing, relevance leads. With buckets, hits of similar relevance form one
	// group and the rank orders inside it — that is what makes a boost visible at all.
	// Typesense takes at most three sort fields, so only two rank fields follow the text match.
	const SEARCH_RANK = RANK.split(',').slice(0, 2).join(',');
	const SEARCH_SORT = config.boostBuckets
		? `_text_match(buckets: ${Number(config.boostBuckets)}):desc,${SEARCH_RANK}`
		: `_text_match:desc,${SEARCH_RANK}`;
	// Universal mode: pages, posts, custom post types and files, grouped by type.
	const UNIVERSAL = config.mode === 'universal';
	const TYPES = Array.isArray(config.types) ? config.types : [];
	const TYPE_BY_ID = Object.fromEntries(TYPES.map((type) => [type.id, type]));
	const ICONS = {
		file: '<svg aria-hidden="true" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/><path d="M9 13h6M9 17h4"/></svg>',
		page: '<svg aria-hidden="true" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 8h8M8 12h8M8 16h5"/></svg>',
	};
	const mobile = window.matchMedia('(max-width: 680px)');
	const i18n = config.i18n || {};
	const sections = Object.assign(
		{
			browse: [{ id: 'popular', limit: 6 }],
			search: [{ id: 'products', limit: 6 }],
			categories: { browse: true, search: true, limit: 8, mobileLimit: 6 },
		},
		config.sections || {}
	);

	/* ------------------------------------------------------------------ helpers */

	const escapeHtml = (value) =>
		String(value ?? '').replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[char]);

	// Typesense wraps matched words in <mark>; everything else is escaped.
	const titleHtml = (hit, highlight) => {
		const snippet = highlight && hit.highlight && hit.highlight.title && hit.highlight.title.snippet;
		return snippet ? escapeHtml(snippet).replace(/&lt;(\/?)mark&gt;/g, '<$1mark>') : escapeHtml(hit.document.title);
	};

	const formatPrice = (amount) => {
		const currency = config.currency;
		if (!currency) {
			return String(amount);
		}
		const [whole, fraction] = Number(amount).toFixed(currency.decimals).split('.');
		const grouped = whole.replace(/\B(?=(\d{3})+(?!\d))/g, currency.thousandSeparator);
		const number = fraction ? grouped + currency.decimalSeparator + fraction : grouped;
		switch (currency.position) {
			case 'left':
				return currency.symbol + number;
			case 'left_space':
				return `${currency.symbol} ${number}`;
			case 'right':
				return number + currency.symbol;
			default:
				return `${number} ${currency.symbol}`;
		}
	};

	const priceHtml = (doc) => {
		if (typeof doc.price !== 'number') {
			return '';
		}
		const from = doc.price_max > doc.price ? `<span class="nwr-search__from">${escapeHtml(i18n.from)}</span> ` : '';
		const regular = doc.on_sale && doc.regular_price > doc.price ? `<del>${formatPrice(doc.regular_price)}</del> ` : '';
		return `<span class="nwr-search__price">${regular}${from}<ins>${formatPrice(doc.price)}</ins></span>`;
	};

	const numberFormat = new Intl.NumberFormat(document.documentElement.lang || 'sk', { maximumFractionDigits: 1 });

	const formatSize = (bytes) => {
		const size = Number(bytes) || 0;
		if (!size) {
			return '';
		}
		if (size < 1024 * 1024) {
			return `${numberFormat.format(Math.max(1, Math.round(size / 1024)))} KB`;
		}
		return `${numberFormat.format(size / (1024 * 1024))} MB`;
	};

	/** Where a hit leads: the file itself for document-like types, otherwise its page. */
	const hitLink = (doc) => {
		const type = TYPE_BY_ID[doc.post_type];
		const file = type && type.link === 'file' && doc.file_url;
		return { href: file ? doc.file_url : doc.url, file: Boolean(file), type };
	};

	/** "PDF · 104 KB" */
	const fileMeta = (doc) => [doc.file_type, formatSize(doc.file_size)].filter(Boolean).join(' · ');

	/** WooCommerce products (and documents of product-only collections) show prices. */
	const isProductHit = (doc) => !doc.post_type || Boolean(TYPE_BY_ID[doc.post_type] && TYPE_BY_ID[doc.post_type].product);

	const searchUrl = (query) => {
		const url = new URL(config.searchUrl, window.location.href);
		const param = config.searchParam || 's';
		url.searchParams.set(param, query);
		// WooCommerce needs the post type; the results page has its own parameter instead.
		if (param === 's' && !UNIVERSAL) {
			url.searchParams.set('post_type', 'product');
		}
		return url.toString();
	};

	/** Results page limited to one content type. */
	const typeUrl = (query, type) => {
		const url = new URL(searchUrl(query));
		url.searchParams.set('typ', type);
		return url.toString();
	};

	/**
	 * Where a panel category leads: its archive, or (when the site opts in) the results page
	 * with the category checked — on the tab of the one type that owns the taxonomy, else on "All".
	 * Only filters the results page really offers are used; without one the archive stays.
	 */
	const categoryUrl = (category, query) => {
		if (config.categoryLinks !== 'results' || config.searchParam !== 'q') {
			return category.url;
		}
		const results = config.results || {};
		const offers = (filters, id) => Array.isArray(filters) && filters.some((filter) => filter.id === id);
		const url = new URL(config.searchUrl, window.location.href);
		if (query) {
			url.searchParams.set('q', query);
		}

		const owners = TYPES.filter((type) => type.taxonomy && type.taxonomy === category.taxonomy);
		if (UNIVERSAL && TYPES.length > 1 && owners.length === 1) {
			const filters = (results.typeFilters || {})[owners[0].id];
			// Term names are the facet values; "|" separates values in the URL.
			if (offers(filters, `tax_${category.taxonomy}`) && !category.facet.includes('|')) {
				url.searchParams.set('typ', owners[0].id);
				url.searchParams.set(`f_tax_${category.taxonomy}`, category.facet);
				return url.toString();
			}
			if (offers(filters, 'category_ids')) {
				url.searchParams.set('typ', owners[0].id);
				url.searchParams.set('f_category_ids', category.id);
				return url.toString();
			}
		}
		if (offers(results.filters, 'category_ids')) {
			url.searchParams.set('f_category_ids', category.id);
			return url.toString();
		}
		return category.url;
	};

	const sectionConfig = (state, id) => sections[state].find((item) => item.id === id);

	const recentIds = () => {
		try {
			const ids = JSON.parse(window.localStorage.getItem(config.recentKey || 'nwr_search_recent') || '[]');
			const current = Number(config.currentProduct) || 0;
			return Array.isArray(ids) ? ids.map(Number).filter((id) => id > 0 && id !== current).slice(0, 12) : [];
		} catch (error) {
			return [];
		}
	};

	/** Fixed or sticky bars covering the top of the viewport (theme headers). */
	const topInset = () => {
		let bottom = 0;
		const x = Math.round(document.documentElement.clientWidth / 2);
		document.elementsFromPoint(x, 2).forEach((element) => {
			for (let node = element; node && node !== document.body; node = node.parentElement) {
				const position = getComputedStyle(node).position;
				if (position === 'fixed' || position === 'sticky') {
					const rect = node.getBoundingClientRect();
					if (rect.top <= 2 && rect.bottom < window.innerHeight / 2) {
						bottom = Math.max(bottom, rect.bottom);
					}
					break;
				}
			}
		});
		return bottom;
	};

	/* ---------------------------------------------------------------------- api */

	const cache = new Map();

	const visibilityFilter = (ids) =>
		['visibility:[visible,search]']
			.concat(config.hideOutOfStock ? ['in_stock:true'] : [])
			.concat(ids ? [`id:[${ids.join(',')}]`] : [])
			.join(' && ');

	const multiSearch = async (searches, signal) => {
		const body = JSON.stringify({
			searches: searches.map((search) => ({
				collection: config.collection,
				query_by: QUERY_BY,
				filter_by: visibilityFilter(),
				include_fields: FIELDS,
				...search,
			})),
		});
		if (cache.has(body)) {
			return cache.get(body);
		}
		// Key as query parameter + text/plain body keeps the request CORS-simple: no preflight per keystroke.
		const response = await fetch(`${config.host}/multi_search?x-typesense-api-key=${encodeURIComponent(config.key)}`, {
			method: 'POST',
			headers: { 'Content-Type': 'text/plain' },
			body,
			signal,
		});
		if (!response.ok) {
			throw new Error(`HTTP ${response.status}`);
		}
		const { results } = await response.json();
		const failed = results.find((result) => result.error);
		if (failed) {
			throw new Error(failed.error);
		}
		cache.set(body, results);
		if (cache.size > 80) {
			cache.delete(cache.keys().next().value);
		}
		return results;
	};

	const byIds = (ids, limit) => ({ q: '*', filter_by: visibilityFilter(ids), per_page: Math.max(1, Math.min(limit, ids.length)) });

	const inOrder = (hits, ids) => hits.slice().sort((a, b) => ids.indexOf(Number(a.document.id)) - ids.indexOf(Number(b.document.id)));

	const relatedIds = (hits, exclude) => {
		const skip = new Set(exclude.map(Number).concat(hits.map((hit) => Number(hit.document.id))));
		const ids = [];
		hits.forEach((hit) =>
			(hit.document.related_ids || []).forEach((id) => {
				const value = Number(id);
				if (!skip.has(value) && !ids.includes(value)) {
					ids.push(value);
				}
			})
		);
		return ids.slice(0, 24);
	};

	/** Matched categories from the category_ids facet; top-level ones only while browsing. */
	const categoriesFrom = (result, browsing) => {
		const facet = (result.facet_counts || []).find((item) => item.field_name === 'category_ids');
		if (!facet) {
			return [];
		}
		return facet.counts
			.map(({ value, count }) => {
				const category = config.categories[value];
				return category
					? { id: value, name: category[0], url: category[1], parent: category[2], taxonomy: category[3] || '', facet: category[4] || category[0], count }
					: null;
			})
			.filter((category) => category && (!browsing || category.parent === 0))
			.sort((a, b) => b.count - a.count || a.name.localeCompare(b.name))
			.slice(0, sections.categories.limit);
	};

	/** Empty field: recently viewed, recommendations for them, popular products, top categories. */
	const loadBrowse = async (signal) => {
		const recent = sectionConfig('browse', 'recent');
		const related = sectionConfig('browse', 'related');
		const popular = sectionConfig('browse', 'popular');
		const ids = recent || related ? recentIds() : [];

		const plan = [];
		if (popular || sections.categories.browse) {
			plan.push([
				'popular',
				{
					q: '*',
					sort_by: RANK,
					per_page: popular ? popular.limit : 0,
					...(sections.categories.browse ? { facet_by: 'category_ids', max_facet_values: 60 } : {}),
				},
			]);
		}
		if (ids.length) {
			plan.push(['recent', byIds(ids, ids.length)]);
		}

		const results = plan.length ? await multiSearch(plan.map(([, search]) => search), signal) : [];
		const result = (key) => results[plan.findIndex(([name]) => name === key)];
		const recentHits = result('recent') ? inOrder(result('recent').hits, ids) : [];

		const view = { query: '', categories: sections.categories.browse && result('popular') ? categoriesFrom(result('popular'), true) : [], blocks: {} };
		if (popular && result('popular')) {
			view.blocks.popular = { title: i18n.popular, hits: result('popular').hits };
		}
		if (recent && recentHits.length) {
			view.blocks.recent = { title: i18n.recent, hits: recentHits.slice(0, recent.limit) };
		}
		if (related && recentHits.length) {
			view.pending = { id: 'related', ids: relatedIds(recentHits, ids), limit: related.limit, title: i18n.relatedBrowse };
		}
		return view;
	};

	/** Typing: matching items (grouped by content type in universal mode) and categories, then recommendations for the best hit. */
	const loadSearch = async (query, productLimit, signal) => {
		const related = sectionConfig('search', 'related');
		const grouped = UNIVERSAL && TYPES.length > 1;
		const base = {
			q: query,
			query_by_weights: QUERY_BY_WEIGHTS,
			num_typos: 2,
			typo_tokens_threshold: Number(config.typoTokens) || 1,
			drop_tokens_threshold: 1,
			prioritize_exact_match: true,
		};
		const facets = { facet_by: 'category_ids', max_facet_values: 60 };
		const searches = [
			{
				...base,
				sort_by: SEARCH_SORT,
				highlight_fields: 'title',
				...(grouped
					? { group_by: 'post_type', group_limit: Math.min(99, Math.max(1, ...TYPES.map((type) => type.limit))), per_page: TYPES.length }
					: { per_page: productLimit, ...facets }),
			},
		];
		// With group_by, Typesense counts groups — not documents — in "found" and in facet counts,
		// so totals and category counts come from a second, ungrouped facet-only search.
		if (grouped) {
			searches.push({ ...base, ...facets, per_page: 0, include_fields: 'id' });
		}
		const [result, totals = result] = await multiSearch(searches, signal);

		let hits = result.hits || [];
		let products = { title: UNIVERSAL && TYPES.length === 1 ? TYPES[0].label : i18n.products, hits, highlight: true };
		if (grouped) {
			const groups = {};
			(result.grouped_hits || []).forEach((group) => {
				groups[String((group.group_key || [])[0] || '')] = group;
			});
			products = {
				highlight: true,
				groups: TYPES.filter((type) => groups[type.id]).map((type) => ({
					id: type.id,
					title: type.label,
					hits: groups[type.id].hits.slice(0, type.limit),
					found: groups[type.id].found || groups[type.id].hits.length,
				})),
			};
			hits = products.groups.flatMap((group) => group.hits);
		}

		const view = {
			query,
			found: totals.found,
			categories: sections.categories.search ? categoriesFrom(totals, false) : [],
			blocks: { products },
		};
		const best = hits[0];
		if (related && best) {
			const ids = relatedIds([best], hits.map((hit) => hit.document.id));
			if (ids.length) {
				view.pending = { id: 'related', ids, limit: related.limit, title: (i18n.relatedSearch || '').replace('%s', best.document.title) };
			}
		}
		return view;
	};

	const loadPending = async (pending, signal) => {
		if (!pending.ids.length) {
			return null;
		}
		const [result] = await multiSearch([byIds(pending.ids, pending.limit)], signal);
		const hits = inOrder(result.hits, pending.ids).slice(0, pending.limit);
		return hits.length ? { title: pending.title, hits } : null;
	};

	/* ----------------------------------------------------------------------- ui */

	let instances = 0;

	class SearchBox {
		constructor(root) {
			this.root = root;
			this.form = root.querySelector('.nwr-search__form');
			this.input = root.querySelector('.nwr-search__input');
			this.panel = root.querySelector('.nwr-search__panel');
			this.clearButton = root.querySelector('.nwr-search__clear');
			if (!this.form || !this.input || !this.panel) {
				return;
			}

			this.id = `nwr-search-${++instances}`;
			// overlay = full-screen sheet, bar = full-width band from the top; both open from an icon.
			this.overlay = root.dataset.layout === 'overlay' || root.dataset.layout === 'bar';
			this.bar = root.dataset.layout === 'bar';
			// fullwidth="no": the band becomes a box placed under the icon.
			this.boxed = this.bar && root.classList.contains('nwr-search--boxed');
			this.align = root.dataset.align || 'left';
			this.productLimit = Number(root.dataset.limit) || (sectionConfig('search', 'products') || { limit: 6 }).limit;
			this.query = null;
			this.request = 0;
			this.controller = null;
			this.timer = 0;
			this.active = -1;

			this.panel.id = `${this.id}-panel`;
			this.input.setAttribute('role', 'combobox');
			this.input.setAttribute('aria-autocomplete', 'list');
			this.input.setAttribute('aria-expanded', 'false');
			this.input.setAttribute('aria-controls', this.panel.id);

			// Floating layers live in <body>; copy the instance classes so builder CSS still applies.
			// Attached theme forms are the exception: their classes belong to the theme's own widget.
			const extraClasses = root.hasAttribute('data-nwr-attached') ? [] : Array.from(root.classList).filter((name) => name !== 'nwr-search');
			if (this.overlay) {
				this.setupOverlay(extraClasses);
			} else {
				this.layer = document.createElement('div');
				this.layer.className = ['nwr-search', 'nwr-search--layer', ...extraClasses].join(' ');
				this.layer.appendChild(this.panel);
				document.body.appendChild(this.layer);
			}

			this.bind();
		}

		setupOverlay(extraClasses) {
			this.toggle = this.root.querySelector('.nwr-search__toggle');
			this.sheet = this.root.querySelector('.nwr-search__sheet');
			this.sheet.classList.add('nwr-search', 'nwr-search--sheet', ...extraClasses);
			document.body.appendChild(this.sheet);

			this.toggle.addEventListener('click', () => this.openSheet());
			this.sheet.querySelector('.nwr-search__close').addEventListener('click', () => this.closeSheet());
			// Escape closes from anywhere inside, not only from the field.
			this.sheet.addEventListener('keydown', (event) => {
				if (event.key === 'Escape' && event.target !== this.input) {
					event.preventDefault();
					this.closeSheet();
				}
			});

			if (this.bar) {
				// The dimmed page under the band; a click on it closes the search.
				this.backdrop = document.createElement('div');
				this.backdrop.className = 'nwr-search__backdrop';
				this.backdrop.hidden = true;
				this.backdrop.addEventListener('click', () => this.closeSheet());
				document.body.appendChild(this.backdrop);
			}
		}

		openSheet() {
			this.sheet.hidden = false;
			if (this.backdrop) {
				this.backdrop.hidden = false;
			}
			if (this.boxed) {
				this.placeBox();
				this.onBoxResize = this.onBoxResize || (() => this.placeBox());
				window.addEventListener('resize', this.onBoxResize, { passive: true });
			}
			this.toggle.setAttribute('aria-expanded', 'true');
			document.documentElement.classList.add('nwr-search-locked');
			this.input.focus(); // Inside the click handler, so mobile browsers open the keyboard.
		}

		closeSheet() {
			this.close();
			this.sheet.hidden = true;
			if (this.onBoxResize) {
				window.removeEventListener('resize', this.onBoxResize);
			}
			if (this.backdrop) {
				this.backdrop.hidden = true;
			}
			this.toggle.setAttribute('aria-expanded', 'false');
			document.documentElement.classList.remove('nwr-search-locked');
			this.toggle.focus();
		}

		/** Box variant: under the icon, aligned per data-align, kept inside the viewport. Phones get the full-screen sheet. */
		placeBox() {
			const style = this.sheet.style;
			style.left = '';
			style.top = '';
			style.maxHeight = '';
			if (mobile.matches) {
				return;
			}
			const viewportWidth = document.documentElement.clientWidth;
			const viewportHeight = window.visualViewport ? window.visualViewport.height : window.innerHeight;
			const icon = this.toggle.getBoundingClientRect();
			const width = this.sheet.offsetWidth;
			const gap = 16;

			let left = icon.left + icon.width / 2 - width / 2;
			if (this.align === 'right') {
				left = icon.right - width;
			} else if (this.align === 'left') {
				left = icon.left;
			}
			style.left = `${Math.round(Math.max(gap, Math.min(left, viewportWidth - width - gap)))}px`;

			// An explicit top="" wins; otherwise the box opens right below the icon.
			const fixedTop = this.sheet.style.getPropertyValue('--nwr-bar-top').trim();
			const top = fixedTop ? this.sheet.getBoundingClientRect().top : icon.bottom + 10;
			if (!fixedTop) {
				style.top = `${Math.round(top)}px`;
			}
			style.maxHeight = `${Math.max(240, Math.round(viewportHeight - top - gap))}px`;
		}

		bind() {
			this.input.addEventListener('focus', () => {
				this.schedule(0);
				this.bringToTop();
			});
			this.input.addEventListener('input', () => {
				this.syncClearButton();
				this.schedule(DEBOUNCE_MS);
			});
			this.input.addEventListener('keydown', (event) => this.onKeydown(event));
			if (this.clearButton) {
				this.clearButton.addEventListener('click', () => {
					this.input.value = '';
					this.syncClearButton();
					this.input.focus();
					this.schedule(0);
				});
			}
			// Hover highlight for real mice only. Touch browsers emit compatibility mouse events,
			// and revealing the hovered option would snap a swiped category row back.
			this.panel.addEventListener('pointermove', (event) => {
				const option = event.pointerType === 'mouse' && event.target.closest('[role="option"]');
				if (option) {
					this.setActive(this.options().indexOf(option), false);
				}
			});
			this.panel.addEventListener(
				'scroll',
				(event) => {
					if (event.target.classList && event.target.classList.contains('nwr-search__categories')) {
						this.updateCategoryFade(event.target);
					}
				},
				{ passive: true, capture: true }
			);
			// iOS Safari pans the whole page instead of the panel's scrollers while the keyboard
			// is open. Touching the results means "browse", so close the keyboard first.
			this.panel.addEventListener(
				'touchstart',
				() => {
					if (document.activeElement === this.input) {
						this.input.blur();
					}
				},
				{ passive: true }
			);
			document.addEventListener('pointerdown', (event) => {
				const inside = this.root.contains(event.target) || this.panel.contains(event.target) || (this.sheet && this.sheet.contains(event.target));
				if (!inside && !this.overlay) {
					this.close();
				}
			});

			if (!this.overlay) {
				let frame = 0;
				const reposition = (event) => {
					// Scrolling inside the panel (results, category row) never moves the field.
					if (event && event.type === 'scroll' && event.target instanceof Node && this.panel.contains(event.target)) {
						return;
					}
					cancelAnimationFrame(frame);
					frame = requestAnimationFrame(() => this.position());
				};
				window.addEventListener('scroll', reposition, { passive: true, capture: true });
				window.addEventListener('resize', reposition, { passive: true });
				if (window.visualViewport) {
					window.visualViewport.addEventListener('resize', reposition, { passive: true });
				}
			}
			this.syncClearButton();
		}

		syncClearButton() {
			if (this.clearButton) {
				this.clearButton.hidden = this.input.value === '';
			}
		}

		/** On phones, scroll the field to the top so the panel gets the whole screen below it. */
		bringToTop() {
			if (this.overlay || !mobile.matches) {
				return;
			}
			const settle = (attempt) => {
				const offset = topInset() + 8;
				const delta = this.root.getBoundingClientRect().top - offset;
				// Absolute targets: a smooth scroll that starts late must not add up with the correction.
				if (Math.abs(delta) > 6) {
					window.scrollTo({ top: Math.max(0, window.scrollY + delta), behavior: attempt === 0 ? 'smooth' : 'auto' });
				}
				// Headers that hide or appear on scroll change the inset; correct once after the scroll settles.
				if (attempt === 0) {
					setTimeout(() => settle(1), 450);
				} else {
					this.position();
				}
			};
			setTimeout(() => settle(0), 60);
		}

		schedule(delay) {
			clearTimeout(this.timer);
			this.timer = setTimeout(() => this.search(), delay);
		}

		async search() {
			const typed = this.input.value.trim();
			if (typed.length > 0 && typed.length < MIN_CHARS) {
				return;
			}
			if (typed === this.query && !this.panel.hidden) {
				return;
			}

			this.query = typed;
			const request = ++this.request;
			if (this.controller) {
				this.controller.abort();
			}
			this.controller = new AbortController();
			const { signal } = this.controller;
			this.root.classList.add('is-loading');

			try {
				const view = typed ? await loadSearch(typed, this.productLimit, signal) : await loadBrowse(signal);
				if (request !== this.request) {
					return;
				}
				this.render(view);
				if (view.pending) {
					const block = await loadPending(view.pending, signal);
					if (request === this.request) {
						this.fillPending(view.pending.id, block);
					}
				}
			} catch (error) {
				if (error.name !== 'AbortError' && request === this.request) {
					this.renderMessage(i18n.error, 'nwr-search__error');
					window.console && console.warn('[nwr-search]', error);
				}
			} finally {
				if (request === this.request) {
					this.root.classList.remove('is-loading');
				}
			}
		}

		render(view) {
			const state = view.query ? 'search' : 'browse';
			if (view.query && view.found === 0) {
				this.renderMessage((i18n.noResults || '').replace('%s', view.query), 'nwr-search__empty');
				return;
			}

			const blocks = sections[state]
				.map((section) => {
					const block = view.blocks[section.id];
					if (block && block.groups) {
						return block.groups
							.map((group) =>
								this.blockHtml(`${section.id}-${group.id}`, {
									title: group.title,
									hits: group.hits,
									highlight: block.highlight,
									more: group.found > group.hits.length ? { type: group.id, count: group.found } : null,
								})
							)
							.join('');
					}
					if (block) {
						return this.blockHtml(section.id, block);
					}
					return view.pending && view.pending.id === section.id ? `<div class="nwr-search__block" data-block="${section.id}" hidden></div>` : '';
				})
				.join('');

			const aside = this.categoriesHtml(view.categories, view.query);
			if (!blocks && !aside) {
				this.close();
				return;
			}

			const showAll = view.query
				? `<a class="nwr-search__all" role="option" id="${this.id}-all" href="${escapeHtml(searchUrl(view.query))}">${escapeHtml((i18n.showAll || '').replace('%d', view.found))}</a>`
				: '';

			this.fill(
				`<div class="nwr-search__body${aside ? '' : ' nwr-search__body--single'}">${aside}<div class="nwr-search__main">${blocks}</div></div>${showAll}`
			);
		}

		blockHtml(id, block) {
			// "All results (12)" next to a type heading, when a results page can show that type alone.
			const more =
				block.more && config.searchParam === 'q'
					? `<a class="nwr-search__more" tabindex="-1" href="${escapeHtml(typeUrl(this.query, block.more.type))}">${escapeHtml((i18n.showAll || '%d').replace('%d', block.more.count))}</a>`
					: '';
			return (
				`<div class="nwr-search__block" data-block="${escapeHtml(id)}"><div class="nwr-search__heading"><span>${escapeHtml(block.title)}</span>${more}</div>` +
				`<div class="nwr-search__products" role="group">${block.hits
					.map((hit) => (isProductHit(hit.document) ? this.productHtml(id, hit, block.highlight) : this.itemHtml(id, hit, block.highlight)))
					.join('')}</div></div>`
			);
		}

		/** Page, post, custom post type entry or file. */
		itemHtml(blockId, hit, highlight) {
			const doc = hit.document;
			const link = hitLink(doc);
			const thumb = doc.image
				? `<img src="${escapeHtml(doc.image)}" alt="" width="64" height="64" loading="lazy" decoding="async">`
				: `<span class="nwr-search__icon">${link.file ? ICONS.file : ICONS.page}</span>`;
			const meta = link.file
				? `<span class="nwr-search__file">${escapeHtml(fileMeta(doc))}</span>`
				: doc.excerpt
					? `<span class="nwr-search__excerpt">${escapeHtml(doc.excerpt)}</span>`
					: '';
			const target = link.file ? ' target="_blank" rel="noopener"' : '';
			return (
				`<a class="nwr-search__product nwr-search__item${link.file ? ' nwr-search__item--file' : ''}" role="option" id="${this.id}-${escapeHtml(blockId)}-${escapeHtml(doc.id)}" href="${escapeHtml(link.href)}"${target}>` +
				`<span class="nwr-search__thumb">${thumb}</span>` +
				`<span class="nwr-search__info"><span class="nwr-search__title">${titleHtml(hit, highlight)}</span>${meta}</span></a>`
			);
		}

		fillPending(id, block) {
			const placeholder = this.panel.querySelector(`.nwr-search__block[data-block="${id}"][hidden]`);
			if (!placeholder) {
				return;
			}
			if (block) {
				placeholder.outerHTML = this.blockHtml(id, block);
				this.setActive(this.active, false);
			} else {
				placeholder.remove();
			}
		}

		categoriesHtml(categories, query) {
			if (!categories.length) {
				return '';
			}
			const links = categories
				.map(
					(category, index) =>
						`<a class="nwr-search__category${index >= sections.categories.mobileLimit ? ' nwr-search__category--extra' : ''}" role="option" id="${this.id}-c${escapeHtml(category.id)}" href="${escapeHtml(categoryUrl(category, query))}">` +
						`<span>${escapeHtml(category.name)}</span><span class="nwr-search__count">${category.count}</span></a>`
				)
				.join('');
			return (
				`<div class="nwr-search__aside"><div class="nwr-search__heading">${escapeHtml(i18n.categories)}</div>` +
				`<div class="nwr-search__categories" role="group">${links}</div></div>`
			);
		}

		productHtml(blockId, hit, highlight) {
			const doc = hit.document;
			const image = doc.image ? `<img src="${escapeHtml(doc.image)}" alt="" width="64" height="64" loading="lazy" decoding="async">` : '';
			const badge = doc.in_stock === false ? `<span class="nwr-search__badge">${escapeHtml(i18n.outOfStock)}</span>` : '';
			return (
				`<a class="nwr-search__product" role="option" id="${this.id}-${blockId}-${escapeHtml(doc.id)}" href="${escapeHtml(doc.url)}">` +
				`<span class="nwr-search__thumb">${image}</span>` +
				`<span class="nwr-search__info"><span class="nwr-search__title">${titleHtml(hit, highlight)}</span>${priceHtml(doc)}${badge}</span></a>`
			);
		}

		renderMessage(text, className) {
			this.fill(`<div class="${className}">${escapeHtml(text)}</div>`);
		}

		fill(html) {
			this.panel.innerHTML = `<div class="nwr-search__listbox" role="listbox" aria-label="${escapeHtml(i18n.results)}">${html}</div>`;
			this.open();
			this.setActive(-1, false);
			this.panel.querySelectorAll('.nwr-search__categories').forEach((row) => this.updateCategoryFade(row));
		}

		/** Fades the right edge while more categories are hidden off-screen, so the row reads as swipeable. */
		updateCategoryFade(row) {
			// The fade is an overlay on the wrapper: masking the scroller itself breaks touch scrolling in WebKit.
			row.parentElement.classList.toggle('has-more', row.scrollWidth - row.clientWidth - row.scrollLeft > 4);
		}

		options() {
			return Array.from(this.panel.querySelectorAll('[role="option"]')).filter((option) => option.getClientRects().length > 0);
		}

		onKeydown(event) {
			const options = this.options();
			switch (event.key) {
				case 'ArrowDown':
					event.preventDefault();
					if (this.panel.hidden) {
						this.schedule(0);
					} else if (options.length) {
						this.setActive((this.active + 1) % options.length);
					}
					break;
				case 'ArrowUp':
					if (!this.panel.hidden && options.length) {
						event.preventDefault();
						this.setActive(this.active <= 0 ? options.length - 1 : this.active - 1);
					}
					break;
				case 'Enter':
					if (!this.panel.hidden && options[this.active]) {
						event.preventDefault();
						if (options[this.active].target === '_blank') {
							window.open(options[this.active].href, '_blank', 'noopener');
						} else {
							window.location.assign(options[this.active].href);
						}
					}
					break; // Otherwise the form submits to the regular WooCommerce search.
				case 'Escape':
					event.preventDefault();
					if (this.overlay) {
						this.closeSheet();
					} else {
						this.close();
					}
					break;
				case 'Tab':
					if (!this.overlay) {
						this.close();
					}
					break;
			}
		}

		/** reveal: scroll the option into view — keyboard navigation only. */
		setActive(index, reveal = true) {
			const options = this.options();
			const current = options[index];
			this.panel.querySelectorAll('[role="option"]').forEach((option) => {
				option.classList.toggle('is-active', option === current);
				option.setAttribute('aria-selected', option === current ? 'true' : 'false');
			});
			this.active = current ? index : -1;
			if (current) {
				this.input.setAttribute('aria-activedescendant', current.id);
				if (reveal) {
					current.scrollIntoView({ block: 'nearest', inline: 'nearest' });
				}
			} else {
				this.input.removeAttribute('aria-activedescendant');
			}
		}

		open() {
			this.panel.hidden = false;
			this.root.classList.add('is-open');
			this.input.setAttribute('aria-expanded', 'true');
			this.position();
		}

		close() {
			if (this.panel.hidden) {
				return;
			}
			this.panel.hidden = true;
			this.root.classList.remove('is-open');
			this.input.setAttribute('aria-expanded', 'false');
			this.setActive(-1, false);
		}

		/** Pins the floating panel under the field, inside the (visual) viewport. */
		position() {
			if (this.overlay || this.panel.hidden) {
				return;
			}
			const field = this.form.getBoundingClientRect();
			const viewportWidth = document.documentElement.clientWidth;
			const viewport = window.visualViewport;
			const viewportBottom = viewport ? viewport.offsetTop + viewport.height : window.innerHeight;
			const gap = mobile.matches ? 8 : 12;
			const preferred = parseInt(getComputedStyle(this.layer).getPropertyValue('--nwr-panel-width'), 10) || 900;
			const width = mobile.matches ? viewportWidth - gap * 2 : Math.min(preferred, viewportWidth - gap * 2);

			let left = field.left;
			if (this.align === 'right') {
				left = field.right - width;
			} else if (this.align === 'center') {
				left = field.left + field.width / 2 - width / 2;
			}
			left = Math.max(gap, Math.min(left, viewportWidth - gap - width));

			const top = field.bottom + 8;
			this.panel.style.top = `${Math.round(top)}px`;
			this.panel.style.left = `${Math.round(left)}px`;
			this.panel.style.width = `${Math.round(width)}px`;
			this.panel.style.maxHeight = `${Math.max(180, Math.round(viewportBottom - top - gap))}px`;
		}
	}

	const init = (scope = document) => {
		scope.querySelectorAll('[data-nwr-search]:not([data-nwr-ready])').forEach((root) => {
			root.setAttribute('data-nwr-ready', '');
			new SearchBox(root);
		});
		attach(scope);
	};

	/**
	 * Enhances a search form the theme already renders (admin setting: CSS selector),
	 * so a header can keep its own markup and still open the Nowera panel. The theme's
	 * classes stay untouched; only the panel is ours.
	 */
	const attach = (scope = document) => {
		if (!config.attach) {
			return;
		}
		let nodes;
		try {
			nodes = scope.querySelectorAll(config.attach);
		} catch (error) {
			window.console && console.warn('[nwr-search] neplatný CSS selektor:', config.attach);
			return;
		}
		nodes.forEach((node) => {
			const form = node.matches('form') ? node : node.closest('form') || node.querySelector('form');
			const input = form && form.querySelector('input[type="search"], input[name="s"], input[type="text"]');
			if (!form || !input || form.hasAttribute('data-nwr-ready')) {
				return;
			}
			form.setAttribute('data-nwr-ready', '');
			form.classList.add('nwr-search__form');
			input.classList.add('nwr-search__input');
			input.setAttribute('autocomplete', 'off');

			const root = form.parentElement || form;
			root.setAttribute('data-nwr-attached', '');
			root.dataset.layout = 'inline';
			const panel = document.createElement('div');
			panel.className = 'nwr-search__panel';
			panel.hidden = true;
			root.appendChild(panel);
			new SearchBox(root);
		});
	};

	// Open the TLS connection early; the first keystroke should not pay for it.
	const preconnect = document.createElement('link');
	preconnect.rel = 'preconnect';
	preconnect.href = config.host;
	preconnect.crossOrigin = 'anonymous';
	document.head.appendChild(preconnect);

	// Shared with the results app (nwr-search-results.js), which is loaded on the results page only.
	window.nwrSearch = {
		init,
		attach,
		api: { config, i18n, multiSearch, visibilityFilter, escapeHtml, formatPrice, priceHtml, titleHtml, formatSize, fileMeta, hitLink, isProductHit, ICONS, TYPES, TYPE_BY_ID, UNIVERSAL, QUERY_BY, QUERY_BY_WEIGHTS, RANK, SEARCH_SORT },
	};
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', () => init());
	} else {
		init();
	}
})();
