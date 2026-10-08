/**
 * Nowera Search — results page.
 * Renders [nwr_search_results]: content-type tabs, facet filters, sorting and
 * endless scrolling, with the whole state kept in the URL so results stay linkable.
 *
 * Shares the transport and formatting helpers with the panel script.
 */
(() => {
	'use strict';

	const shared = window.nwrSearch && window.nwrSearch.api;
	if (!shared) {
		return;
	}

	const { config, i18n, multiSearch, visibilityFilter, escapeHtml, formatPrice, priceHtml, titleHtml, fileMeta, hitLink, isProductHit, ICONS, TYPES, TYPE_BY_ID, UNIVERSAL } = shared;
	const settings = config.results || {};
	const BASE_FILTERS = Array.isArray(settings.filters) ? settings.filters : [];
	const TYPE_FILTERS = settings.typeFilters || {};
	const TABS = UNIVERSAL && TYPES.length > 1;
	const FIELDS = 'id,title,url,image,price,price_max,regular_price,on_sale,in_stock,post_type,excerpt,file_url,file_type,file_size';
	const TERM_TYPES = ['terms', 'categories'];
	const VISIBLE_TERMS = 8;
	const DEBOUNCE_MS = 250;

	const SORTS = {
		relevance: null, // relevance only makes sense with a query; falls back to the rank
		popular: shared.RANK,
		price_asc: 'price:asc',
		price_desc: 'price:desc',
		newest: 'created_at:desc',
	};
	const PRICE_SORTS = ['popular', 'price_asc', 'price_desc'];

	/* ------------------------------------------------------------------ state */

	const isTerms = (filter) => TERM_TYPES.includes(filter.type);

	/** Filters that apply to the current tab: a type's own, or the shared ones on "All". */
	const filtersFor = (type) => (type && Array.isArray(TYPE_FILTERS[type]) ? TYPE_FILTERS[type] : BASE_FILTERS);

	/** Price sorting only makes sense where every hit is a product. */
	const priceSortable = (type) => !UNIVERSAL || Boolean(type && TYPE_BY_ID[type] && TYPE_BY_ID[type].product);

	/** Filter values escaped for Typesense: backticks handle spaces and punctuation. */
	const filterValue = (filter, value) => (filter.type === 'categories' ? String(Number(value) || 0) : '`' + String(value).replace(/`/g, '') + '`');

	const emptyFilters = () => ({ terms: {}, price: { min: null, max: null }, flags: {} });

	const readState = (root) => {
		const params = new URLSearchParams(window.location.search);
		const type = TABS && TYPE_BY_ID[params.get('typ')] ? params.get('typ') : '';
		const sort = Object.prototype.hasOwnProperty.call(SORTS, params.get('sort')) ? params.get('sort') : 'relevance';
		const state = {
			// ?q= is ours; ?s= can still arrive from a theme form or a shared link.
			q: (params.get('q') || params.get('s') || '').trim(),
			type,
			sort: PRICE_SORTS.includes(sort) && !priceSortable(type) ? 'relevance' : sort,
			...emptyFilters(),
			page: 1,
			perPage: Number(root.dataset.perPage) || settings.perPage || 24,
		};

		filtersFor(type).forEach((filter) => {
			const raw = params.get(`f_${filter.id}`);
			if (!raw) {
				return;
			}
			if (isTerms(filter)) {
				state.terms[filter.id] = raw.split('|').filter(Boolean);
			} else if (filter.type === 'toggle') {
				state.flags[filter.id] = raw === '1';
			} else if (filter.type === 'range') {
				const [min, max] = raw.split('-');
				state.price = { min: min === '' ? null : Number(min), max: max === '' || max === undefined ? null : Number(max) };
			}
		});

		return state;
	};

	const writeState = (state) => {
		const url = new URL(window.location.href);
		const params = url.searchParams;

		params.delete('post_type');
		params.delete('s');
		state.q ? params.set('q', state.q) : params.delete('q');
		state.type ? params.set('typ', state.type) : params.delete('typ');
		state.sort === 'relevance' ? params.delete('sort') : params.set('sort', state.sort);

		Array.from(params.keys())
			.filter((key) => key.startsWith('f_'))
			.forEach((key) => params.delete(key));

		filtersFor(state.type).forEach((filter) => {
			const key = `f_${filter.id}`;
			if (isTerms(filter)) {
				const values = state.terms[filter.id] || [];
				if (values.length) {
					params.set(key, values.join('|'));
				}
			} else if (filter.type === 'toggle') {
				if (state.flags[filter.id]) {
					params.set(key, '1');
				}
			} else if (filter.type === 'range') {
				const { min, max } = state.price;
				if (min !== null || max !== null) {
					params.set(key, `${min === null ? '' : min}-${max === null ? '' : max}`);
				}
			}
		});

		window.history.replaceState({}, '', url.toString());
	};

	/** Typesense filter_by; skip names a filter (or "type") left out so it can count its own alternatives. */
	const filterBy = (state, skip) => {
		const clauses = [visibilityFilter()];
		if (state.type && skip !== 'type') {
			clauses.push(`post_type:=\`${state.type}\``);
		}

		filtersFor(state.type).forEach((filter) => {
			if (filter.id === skip) {
				return;
			}
			if (isTerms(filter)) {
				const values = state.terms[filter.id] || [];
				if (values.length) {
					clauses.push(`${filter.id}:[${values.map((value) => filterValue(filter, value)).join(',')}]`);
				}
			} else if (filter.type === 'toggle' && state.flags[filter.id]) {
				clauses.push(`${filter.id}:true`);
			} else if (filter.type === 'range') {
				if (typeof state.price.min === 'number' && !Number.isNaN(state.price.min)) {
					clauses.push(`price:>=${state.price.min}`);
				}
				if (typeof state.price.max === 'number' && !Number.isNaN(state.price.max)) {
					clauses.push(`price:<=${state.price.max}`);
				}
			}
		});

		return clauses.filter(Boolean).join(' && ');
	};

	const sortBy = (state) => {
		if (state.sort !== 'relevance') {
			return SORTS[state.sort];
		}
		return state.q ? shared.SEARCH_SORT : shared.RANK;
	};

	const searchBase = (state) => ({
		q: state.q || '*',
		query_by_weights: shared.QUERY_BY_WEIGHTS,
		num_typos: 2,
		typo_tokens_threshold: Number(config.typoTokens) || 1,
		drop_tokens_threshold: 1,
		prioritize_exact_match: true,
	});

	const searchPlan = (state) => {
		const filters = filtersFor(state.type);
		const main = {
			...searchBase(state),
			filter_by: filterBy(state),
			sort_by: sortBy(state),
			include_fields: FIELDS,
			...(state.q ? { highlight_fields: 'title' } : {}),
			...(filters.length ? { facet_by: filters.map((filter) => filter.id).join(','), max_facet_values: 40 } : {}),
			per_page: state.perPage,
			page: state.page,
		};

		// Extra facet-only searches: tab counts without the type filter, and one per active
		// multi-select so its own options keep showing counts ("OR inside, AND between").
		const extras = [];
		if (TABS) {
			extras.push({ field: 'post_type', search: { ...searchBase(state), filter_by: filterBy(state, 'type'), facet_by: 'post_type', max_facet_values: 40, per_page: 0, include_fields: 'id' } });
		}
		filters
			.filter((filter) => isTerms(filter) && (state.terms[filter.id] || []).length)
			.forEach((filter) => {
				extras.push({ field: filter.id, search: { ...searchBase(state), filter_by: filterBy(state, filter.id), facet_by: filter.id, max_facet_values: 40, per_page: 0, include_fields: 'id' } });
			});

		return { main, extras };
	};

	/* --------------------------------------------------------------------- ui */

	class Results {
		constructor(root) {
			this.root = root;
			this.form = root.querySelector('.nwr-results__search');
			this.input = root.querySelector('.nwr-results__input');
			this.grid = root.querySelector('.nwr-results__grid');
			this.tabs = root.querySelector('.nwr-results__tabs');
			this.facetsBox = root.querySelector('.nwr-results__facets');
			this.sidebar = root.querySelector('.nwr-results__sidebar');
			this.chips = root.querySelector('.nwr-results__chips');
			this.count = root.querySelector('.nwr-results__count');
			this.status = root.querySelector('.nwr-results__status');
			this.sentinel = root.querySelector('.nwr-results__sentinel');
			this.sortSelect = root.querySelector('.nwr-results__sort-select');
			this.toggle = root.querySelector('.nwr-results__toggle');
			this.applyButton = root.querySelector('.nwr-results__apply');
			if (!this.grid) {
				return;
			}

			this.state = readState(root);
			this.request = 0;
			this.controller = null;
			this.found = 0;
			this.facets = {};
			this.typeCounts = {};
			this.expanded = {};
			this.timer = 0;

			if (this.input) {
				this.input.value = this.state.q;
			}
			this.syncSortOptions();

			this.bind();
			this.load(false);
		}

		bind() {
			if (this.form) {
				this.form.addEventListener('submit', (event) => {
					event.preventDefault();
					this.apply({ q: this.input.value.trim() });
				});
				this.input.addEventListener('input', () => {
					clearTimeout(this.timer);
					this.timer = setTimeout(() => this.apply({ q: this.input.value.trim() }), DEBOUNCE_MS);
				});
			}

			if (this.sortSelect) {
				this.sortSelect.addEventListener('change', () => this.apply({ sort: this.sortSelect.value }));
			}

			if (this.tabs) {
				this.tabs.addEventListener('click', (event) => {
					const tab = event.target.closest('[data-type]');
					if (tab && tab.dataset.type !== this.state.type) {
						const type = tab.dataset.type;
						// Every tab has its own filters; the previous tab's picks do not carry over.
						Object.assign(this.state, emptyFilters());
						this.expanded = {};
						this.apply({ type, sort: PRICE_SORTS.includes(this.state.sort) && !priceSortable(type) ? 'relevance' : this.state.sort });
					}
				});
			}

			if (this.toggle) {
				this.toggle.addEventListener('click', () => this.openFilters(!this.root.classList.contains('nwr-results--filters')));
			}
			this.root.querySelectorAll('.nwr-results__sidebar-close, .nwr-results__apply').forEach((button) => {
				button.addEventListener('click', () => this.openFilters(false));
			});
			// The drawer backdrop is the root's ::before, so a click on it targets the root itself.
			this.root.addEventListener('click', (event) => {
				if (event.target === this.root && this.root.classList.contains('nwr-results--filters')) {
					this.openFilters(false);
				}
			});
			document.addEventListener('keydown', (event) => {
				if (event.key === 'Escape' && this.root.classList.contains('nwr-results--filters')) {
					this.openFilters(false);
				}
			});

			this.facetsBox.addEventListener('change', (event) => this.onFacetChange(event));
			this.facetsBox.addEventListener('click', (event) => {
				const more = event.target.closest('[data-more]');
				if (more) {
					this.expanded[more.dataset.more] = !this.expanded[more.dataset.more];
					this.renderFacets();
				}
			});
			this.chips.addEventListener('click', (event) => this.onChipClick(event));

			if (window.IntersectionObserver && this.sentinel) {
				new IntersectionObserver(
					(entries) => {
						if (entries.some((entry) => entry.isIntersecting) && !this.loading && this.state.page * this.state.perPage < this.found) {
							this.state.page += 1;
							this.load(true);
						}
					},
					{ rootMargin: '400px 0px' }
				).observe(this.sentinel);
			}
		}

		/** Phones only: the sidebar becomes a drawer. CSS decides what is visible where. */
		openFilters(open) {
			this.root.classList.toggle('nwr-results--filters', open);
			document.documentElement.classList.toggle('nwr-search-locked', open);
			if (this.toggle) {
				this.toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
			}
		}

		/** Hides price sorting where it cannot apply and keeps the select in sync with the state. */
		syncSortOptions() {
			if (!this.sortSelect) {
				return;
			}
			Array.from(this.sortSelect.options).forEach((option) => {
				const hidden = PRICE_SORTS.includes(option.value) && !priceSortable(this.state.type);
				option.hidden = hidden;
				option.disabled = hidden;
			});
			this.sortSelect.value = this.state.sort;
		}

		/** Any change resets paging and reloads from the first page. */
		apply(changes) {
			Object.assign(this.state, changes, { page: 1 });
			this.syncSortOptions();
			writeState(this.state);
			this.load(false);
		}

		onFacetChange(event) {
			const input = event.target.closest('input');
			if (!input) {
				return;
			}
			const { field, kind } = input.dataset;
			const filter = filtersFor(this.state.type).find((item) => item.id === field);
			if (!filter) {
				return;
			}

			if (kind === 'term') {
				const values = new Set(this.state.terms[field] || []);
				input.checked ? values.add(input.value) : values.delete(input.value);
				this.state.terms[field] = Array.from(values);
			} else if (kind === 'toggle') {
				this.state.flags[field] = input.checked;
			} else if (kind === 'price') {
				const value = input.value === '' ? null : Number(input.value);
				this.state.price[input.dataset.bound] = Number.isNaN(value) ? null : value;
			}
			this.apply({});
		}

		onChipClick(event) {
			const chip = event.target.closest('[data-chip]');
			if (!chip) {
				return;
			}
			if (chip.dataset.chip === 'all') {
				Object.assign(this.state, emptyFilters());
			} else if (chip.dataset.kind === 'term') {
				this.state.terms[chip.dataset.field] = (this.state.terms[chip.dataset.field] || []).filter((value) => value !== chip.dataset.value);
			} else if (chip.dataset.kind === 'toggle') {
				this.state.flags[chip.dataset.field] = false;
			} else {
				this.state.price = { min: null, max: null };
			}
			this.apply({});
		}

		async load(append) {
			const request = ++this.request;
			this.loading = true;
			this.root.classList.add('is-loading');
			this.status.textContent = i18n.loading || '';

			if (this.controller) {
				this.controller.abort();
			}
			this.controller = new AbortController();

			try {
				const plan = searchPlan(this.state);
				const results = await multiSearch([plan.main, ...plan.extras.map((extra) => extra.search)], this.controller.signal);
				if (request !== this.request) {
					return;
				}

				const [main, ...extras] = results;
				this.found = main.found || 0;
				this.facets = {};
				(main.facet_counts || []).forEach((facet) => {
					this.facets[facet.field_name] = facet;
				});
				// Counts from the unrestricted searches win for their own filter.
				extras.forEach((result, index) => {
					const field = plan.extras[index].field;
					const facet = (result.facet_counts || []).find((item) => item.field_name === field);
					if (field === 'post_type') {
						this.typeCounts = {};
						(facet ? facet.counts : []).forEach((item) => {
							this.typeCounts[item.value] = item.count;
						});
					} else if (facet) {
						this.facets[field] = facet;
					}
				});

				this.renderTabs();
				this.renderProducts(main.hits || [], append);
				this.renderFacets();
				this.renderChips();
				this.count.textContent = (i18n.found || '%d').replace('%d', this.found);
				this.status.textContent = '';
				if (this.applyButton) {
					this.applyButton.textContent = `${i18n.apply} (${this.found})`;
				}
			} catch (error) {
				if (error.name !== 'AbortError' && request === this.request) {
					this.status.textContent = i18n.error || '';
					window.console && console.warn('[nwr-search]', error);
				}
			} finally {
				if (request === this.request) {
					this.loading = false;
					this.root.classList.remove('is-loading');
				}
			}
		}

		/** "All" plus one tab per content type that has matches (the active one always stays). */
		renderTabs() {
			if (!TABS || !this.tabs) {
				return;
			}
			const total = Object.values(this.typeCounts).reduce((sum, count) => sum + count, 0);
			const tab = (type, label, count) =>
				`<button type="button" role="tab" class="nwr-results__tab" data-type="${escapeHtml(type)}" aria-selected="${this.state.type === type ? 'true' : 'false'}">` +
				`${escapeHtml(label)}<span class="nwr-results__tab-count">${count}</span></button>`;

			this.tabs.innerHTML =
				tab('', i18n.allTypes || 'Všetko', total) +
				TYPES.filter((type) => this.typeCounts[type.id] || this.state.type === type.id)
					.map((type) => tab(type.id, type.label, this.typeCounts[type.id] || 0))
					.join('');
			this.tabs.hidden = false;
		}

		renderProducts(hits, append) {
			const html = hits.map((hit) => this.cardHtml(hit)).join('');
			if (append) {
				this.grid.insertAdjacentHTML('beforeend', html);
				return;
			}
			if (!hits.length) {
				const hasFilters = this.activeFilters().length > 0;
				const message = hasFilters
					? i18n.noFilterHits
					: this.state.q
						? (i18n.noResults || '').replace('%s', this.state.q)
						: i18n.emptyQuery;
				this.grid.innerHTML = `<p class="nwr-results__empty">${escapeHtml(message || '')}</p>`;
				return;
			}
			this.grid.innerHTML = html;
		}

		cardHtml(hit) {
			return isProductHit(hit.document) ? this.productCardHtml(hit) : this.itemCardHtml(hit);
		}

		productCardHtml(hit) {
			const doc = hit.document;
			const image = doc.image ? `<img src="${escapeHtml(doc.image)}" alt="" width="300" height="300" loading="lazy" decoding="async">` : '';
			const badges = [
				doc.on_sale ? `<span class="nwr-results__badge nwr-results__badge--sale">%</span>` : '',
				doc.in_stock === false ? `<span class="nwr-results__badge">${escapeHtml(i18n.outOfStock)}</span>` : '',
			].join('');

			return (
				`<a class="nwr-results__card" href="${escapeHtml(doc.url)}">` +
				`<span class="nwr-results__media">${image}${badges}</span>` +
				`<span class="nwr-results__name">${titleHtml(hit, Boolean(this.state.q))}</span>` +
				`${priceHtml(doc)}</a>`
			);
		}

		/** Pages, posts, custom post types and files: a text card with type, excerpt or file details. */
		itemCardHtml(hit) {
			const doc = hit.document;
			const link = hitLink(doc);
			const media = doc.image
				? `<span class="nwr-results__thumb"><img src="${escapeHtml(doc.image)}" alt="" width="96" height="96" loading="lazy" decoding="async"></span>`
				: `<span class="nwr-results__thumb nwr-results__thumb--icon">${link.file ? ICONS.file : ICONS.page}</span>`;
			const typeLabel = !this.state.type && link.type ? `<span class="nwr-results__type">${escapeHtml(link.type.label)}</span>` : '';
			const details = link.file
				? `<span class="nwr-results__file">${escapeHtml(fileMeta(doc))}<span class="nwr-results__file-action">${escapeHtml(i18n.download || '')}</span></span>`
				: doc.excerpt
					? `<span class="nwr-results__excerpt">${escapeHtml(doc.excerpt)}</span>`
					: '';
			const target = link.file ? ' target="_blank" rel="noopener"' : '';

			return (
				`<a class="nwr-results__item${link.file ? ' nwr-results__item--file' : ''}" href="${escapeHtml(link.href)}"${target}>` +
				media +
				`<span class="nwr-results__body">${typeLabel}<span class="nwr-results__name">${titleHtml(hit, Boolean(this.state.q))}</span>${details}</span></a>`
			);
		}

		renderFacets() {
			const html = filtersFor(this.state.type)
				.map((filter) => {
					if (isTerms(filter)) {
						return this.termsHtml(filter);
					}
					if (filter.type === 'toggle') {
						return this.toggleHtml(filter);
					}
					return this.priceHtmlBlock(filter);
				})
				.filter(Boolean)
				.join('');

			this.facetsBox.innerHTML = html;
			// Layout switches in CSS: no filters → results use the full width.
			this.root.classList.toggle('has-facets', Boolean(html));
			// Item lists read better in one column; product grids stay grids.
			this.root.classList.toggle('nwr-results--list', UNIVERSAL && !priceSortable(this.state.type));
		}

		termsHtml(filter) {
			const facet = this.facets[filter.id];
			const selected = this.state.terms[filter.id] || [];
			const counts = (facet && facet.counts ? facet.counts : []).map((item) => ({
				value: String(item.value),
				label: filter.type === 'categories' ? this.categoryName(item.value) : String(item.value),
				count: item.count,
			}));
			// Selected values stay listed even when the current result set no longer has them.
			selected.forEach((value) => {
				if (!counts.some((item) => item.value === String(value))) {
					counts.unshift({ value: String(value), label: filter.type === 'categories' ? this.categoryName(value) : String(value), count: 0 });
				}
			});
			const options = counts.filter((item) => item.label);
			if (!options.length) {
				return '';
			}

			const expanded = this.expanded[filter.id];
			const visible = expanded ? options : options.slice(0, VISIBLE_TERMS);
			const rows = visible
				.map(
					(item) =>
						`<label class="nwr-results__option"><input type="checkbox" data-kind="term" data-field="${escapeHtml(filter.id)}" value="${escapeHtml(item.value)}"${
							selected.includes(item.value) ? ' checked' : ''
						}><span>${escapeHtml(item.label)}</span><span class="nwr-results__facet-count">${item.count}</span></label>`
				)
				.join('');
			const more =
				options.length > VISIBLE_TERMS
					? `<button type="button" class="nwr-results__more" data-more="${escapeHtml(filter.id)}">${escapeHtml(expanded ? i18n.less : i18n.more)}</button>`
					: '';

			return this.groupHtml(filter.label, rows + more);
		}

		toggleHtml(filter) {
			const label = filter.id === 'on_sale' ? i18n.onSaleOnly : i18n.inStockOnly;
			return this.groupHtml(
				filter.label,
				`<label class="nwr-results__option"><input type="checkbox" data-kind="toggle" data-field="${escapeHtml(filter.id)}"${
					this.state.flags[filter.id] ? ' checked' : ''
				}><span>${escapeHtml(label || filter.label)}</span></label>`
			);
		}

		priceHtmlBlock(filter) {
			const stats = (this.facets.price && this.facets.price.stats) || {};
			const min = typeof stats.min === 'number' ? Math.floor(stats.min) : '';
			const max = typeof stats.max === 'number' ? Math.ceil(stats.max) : '';
			const value = (bound) => (this.state.price[bound] === null ? '' : this.state.price[bound]);

			return this.groupHtml(
				filter.label,
				`<div class="nwr-results__range">` +
					`<label><span>${escapeHtml(i18n.priceFrom)}</span><input type="number" inputmode="decimal" data-kind="price" data-field="price" data-bound="min" value="${value('min')}" placeholder="${min}"></label>` +
					`<label><span>${escapeHtml(i18n.priceTo)}</span><input type="number" inputmode="decimal" data-kind="price" data-field="price" data-bound="max" value="${value('max')}" placeholder="${max}"></label>` +
					`</div>`
			);
		}

		groupHtml(label, body) {
			return `<div class="nwr-results__group"><div class="nwr-results__group-title">${escapeHtml(label)}</div>${body}</div>`;
		}

		categoryName(id) {
			const category = config.categories[id];
			return category ? category[0] : '';
		}

		activeFilters() {
			const chips = [];
			filtersFor(this.state.type).forEach((filter) => {
				if (isTerms(filter)) {
					(this.state.terms[filter.id] || []).forEach((value) => {
						chips.push({
							kind: 'term',
							field: filter.id,
							value,
							label: filter.type === 'categories' ? this.categoryName(value) : value,
						});
					});
				} else if (filter.type === 'toggle' && this.state.flags[filter.id]) {
					chips.push({ kind: 'toggle', field: filter.id, value: '1', label: filter.id === 'on_sale' ? i18n.onSaleOnly : i18n.inStockOnly });
				} else if (filter.type === 'range' && (this.state.price.min !== null || this.state.price.max !== null)) {
					const from = this.state.price.min === null ? '' : formatPrice(this.state.price.min);
					const to = this.state.price.max === null ? '' : formatPrice(this.state.price.max);
					chips.push({ kind: 'price', field: 'price', value: '', label: `${filter.label}: ${from || '…'} – ${to || '…'}` });
				}
			});
			return chips;
		}

		renderChips() {
			const chips = this.activeFilters();
			if (!chips.length) {
				this.chips.innerHTML = '';
				return;
			}
			const buttons = chips
				.map(
					(chip) =>
						`<button type="button" class="nwr-results__chip" data-chip="one" data-kind="${escapeHtml(chip.kind)}" data-field="${escapeHtml(chip.field)}" data-value="${escapeHtml(
							chip.value
						)}">${escapeHtml(chip.label)}<span aria-hidden="true">×</span></button>`
				)
				.join('');
			this.chips.innerHTML = `${buttons}<button type="button" class="nwr-results__chip nwr-results__chip--clear" data-chip="all">${escapeHtml(i18n.clearFilters)}</button>`;
		}
	}

	const init = (scope = document) => {
		scope.querySelectorAll('[data-nwr-results]:not([data-nwr-ready])').forEach((root) => {
			root.setAttribute('data-nwr-ready', '');
			new Results(root);
		});
	};

	window.nwrSearchResults = { init };
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', () => init());
	} else {
		init();
	}
})();
