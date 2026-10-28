import { useEffect, useRef, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { api } from '../services/api.js'
import { ClothCard } from '../components/ClothCard.jsx'
import { fabricFacts, money } from '../lib/format.js'

const PER_PAGE = 9

/**
 * The notions store. Filters are server-side (?category, ?q, ?sort, min/max
 * price, in_stock) so paging through the catalogue never disagrees with the
 * header count; the two view modes are the only local state.
 */
export function Shop() {
  const [params, setParams] = useSearchParams()
  const [categories, setCategories] = useState([])
  const [settings, setSettings] = useState({})
  const [result, setResult] = useState({ products: [], meta: { total: 0, page: 1 } })
  const [loading, setLoading] = useState(true)
  const [view, setView] = useState('grid')
  const searchRef = useRef(null)

  const page = Math.max(1, Number(params.get('page') || 1))
  const filters = {
    category: params.get('category') || '',
    q: params.get('q') || '',
    sort: params.get('sort') || '-created_at',
    min_price: params.get('min_price') || '',
    max_price: params.get('max_price') || '',
    in_stock: params.get('in_stock') || '',
  }

  useEffect(() => {
    api.get('/categories').then(({ data }) => setCategories(data.categories || [])).catch(() => {})
    api.get('/settings').then(({ data }) => setSettings(data.settings || {})).catch(() => {})
  }, [])

  useEffect(() => {
    if (params.get('focus') === 'search' && searchRef.current) {
      searchRef.current.focus()
      window.setTimeout(() => searchRef.current?.scrollIntoView({ block: 'center' }), 60)
    }
  }, [params])

  useEffect(() => {
    setLoading(true)
    const qs = new URLSearchParams()
    Object.entries(filters).forEach(([key, value]) => { if (value !== '') qs.set(key, value) })
    qs.set('page', String(page))
    qs.set('per_page', String(PER_PAGE))
    api.get(`/products?${qs}`)
      .then(({ data, meta }) => setResult({ products: data.products || [], meta: meta || { total: 0 } }))
      .catch(() => setResult({ products: [], meta: { total: 0 } }))
      .finally(() => setLoading(false))
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [params])

  const update = (key, value) => {
    const next = new URLSearchParams(params)
    if (value === '') next.delete(key)
    else next.set(key, value)
    if (key !== 'page') next.delete('page')
    next.delete('focus')
    setParams(next)
  }

  const clearAll = () => setParams(new URLSearchParams())
  const activeCategory = categories.find((c) => c.slug === filters.category)
  const totalPages = Math.max(1, Math.ceil((result.meta.total || 0) / PER_PAGE))
  const activeCount = ['category', 'q', 'min_price', 'max_price', 'in_stock'].filter((k) => filters[k] !== '').length

  const heading = activeCategory
    ? activeCategory.name
    : (settings['shop.heading'] || 'Threads, Elastic, Needles & More')

  return (
    <>
      <div className="wrap">
        <nav className="breadcrumb" aria-label="Breadcrumb">
          <Link to="/">House</Link>
          <span className="sep">/</span>
          <Link to="/shop">Catalogue</Link>
          {activeCategory && (
            <>
              <span className="sep">/</span>
              <span aria-current="page">{activeCategory.name}</span>
            </>
          )}
        </nav>

        <div className="section-head">
          <div>
            <h1>{heading}</h1>
            <p className="t-body-lg muted" style={{ marginTop: 'var(--space-sm)', maxWidth: '62ch' }}>
              {settings['shop.intro'] || 'Curated sewing notions selected for strength, finish and daily reliability — sold by the spool, pack and metre.'}
            </p>
          </div>
          <div className="right">
            <p className="eyebrow">{result.meta.total || 0} notions catalogued</p>
            <p className="eyebrow">Counter catalogue • 2026</p>
          </div>
        </div>

        <div className="grid grid--sidebar">
          <aside className="filters">
            <div className="field">
              <label htmlFor="q">Search the catalogue</label>
              <input
                id="q"
                ref={searchRef}
                value={filters.q}
                onChange={(e) => update('q', e.target.value)}
                placeholder="thread, elastic, shears…"
              />
            </div>

            <div className="filter-group">
              <h4>Chapter</h4>
              <div className="stack-sm">
                <button
                  className={`chip${filters.category === '' ? ' is-active' : ''}`}
                  onClick={() => update('category', '')}
                >
                  All notions ({result.meta.total || 0})
                </button>
                {categories.map((c) => (
                  <button
                    key={c.slug}
                    className={`chip${filters.category === c.slug ? ' is-active' : ''}`}
                    onClick={() => update('category', c.slug)}
                  >
                    {c.name} ({c.product_count})
                  </button>
                ))}
              </div>
            </div>

            <div className="filter-group">
              <h4>Price guide</h4>
              <div className="row" style={{ gap: 'var(--space-sm)' }}>
                <div className="field grow" style={{ marginBottom: 0 }}>
                  <label htmlFor="min_price">From</label>
                  <input
                    id="min_price"
                    type="number"
                    min="0"
                    value={filters.min_price}
                    onChange={(e) => update('min_price', e.target.value)}
                  />
                </div>
                <div className="field grow" style={{ marginBottom: 0 }}>
                  <label htmlFor="max_price">To</label>
                  <input
                    id="max_price"
                    type="number"
                    min="0"
                    value={filters.max_price}
                    onChange={(e) => update('max_price', e.target.value)}
                  />
                </div>
              </div>
            </div>

            <div className="filter-group">
              <h4>Availability</h4>
              <label className="checkline">
                <input
                  type="checkbox"
                  checked={filters.in_stock === '1'}
                  onChange={(e) => update('in_stock', e.target.checked ? '1' : '')}
                />
                In stock only — ready for dispatch
              </label>
            </div>

            <div className="filter-group">
              <h4>Sort</h4>
              <div className="field" style={{ marginBottom: 0 }}>
                <label htmlFor="sort" className="visually-hidden">Sort</label>
                <select id="sort" value={filters.sort} onChange={(e) => update('sort', e.target.value)}>
                  <option value="-created_at">Newest arrivals</option>
                  <option value="created_at">Archive order</option>
                  <option value="price">Price, ascending</option>
                  <option value="-price">Price, descending</option>
                  <option value="name">Alphabetical</option>
                </select>
              </div>
            </div>

            {activeCount > 0 && (
              <div className="filter-group">
                <h4>Active criteria</h4>
                <div className="chip-row">
                  {filters.category && <button className="chip is-active" onClick={() => update('category', '')}>{activeCategory?.name || filters.category} ✕</button>}
                  {filters.q && <button className="chip is-active" onClick={() => update('q', '')}>“{filters.q}” ✕</button>}
                  {filters.min_price && <button className="chip is-active" onClick={() => update('min_price', '')}>from {money(filters.min_price)} ✕</button>}
                  {filters.max_price && <button className="chip is-active" onClick={() => update('max_price', '')}>to {money(filters.max_price)} ✕</button>}
                  {filters.in_stock && <button className="chip is-active" onClick={() => update('in_stock', '')}>In stock ✕</button>}
                </div>
                <button className="link-u t-body-sm" style={{ marginTop: 'var(--space-md)' }} onClick={clearAll}>Reset filters</button>
              </div>
            )}
          </aside>

          <section>
            <div className="row row--between" style={{ paddingBottom: 'var(--space-sm)', borderBottom: '1px solid var(--hairline)', marginBottom: 'var(--space-lg)' }}>
              <span className="eyebrow">
                {loading ? 'Consulting the catalogue…' : `Displaying ${result.products.length} of ${result.meta.total || 0} catalogued notions`}
              </span>
              <div className="row" style={{ gap: '0.25rem' }}>
                <button
                  className={`chip${view === 'grid' ? ' is-active' : ''}`}
                  onClick={() => setView('grid')}
                  aria-pressed={view === 'grid'}
                >
                  Grid
                </button>
                <button
                  className={`chip${view === 'ledger' ? ' is-active' : ''}`}
                  onClick={() => setView('ledger')}
                  aria-pressed={view === 'ledger'}
                >
                  Ledger
                </button>
              </div>
            </div>

            {loading ? (
              <p className="muted t-body-sm">Loading catalogue…</p>
            ) : result.products.length === 0 ? (
              <p className="empty">Nothing in the catalogue matches those criteria.</p>
            ) : view === 'grid' ? (
              <div className="cloth-grid" style={{ gridTemplateColumns: 'repeat(3, minmax(0, 1fr))' }}>
                {result.products.map((p) => <ClothCard key={p.id} product={p} showDescription />)}
              </div>
            ) : (
              <div className="ledger">
                {result.products.map((p) => (
                  <Link key={p.id} to={`/product/${p.slug}`} className="ledger-row" style={{ gridTemplateColumns: '1fr 220px 160px 120px' }}>
                    <span>
                      <span className="eyebrow">{p.specs?.maker || p.specs?.origin || 'Notions'}</span>
                      <span className="t-title serif" style={{ display: 'block' }}>{p.name}</span>
                      <span className="t-body-sm muted">{fabricFacts(p.specs)}</span>
                    </span>
                    <span className="t-body-sm muted">{p.specs?.composition || '—'}</span>
                    <span className="tabular muted">{p.specs?.ref || '—'}</span>
                    <span className="tabular right">
                      {money(p.price, p.currency)}
                      {p.specs?.unit_label ? ` / ${p.specs.unit_label}` : ''}
                    </span>
                  </Link>
                ))}
              </div>
            )}

            {totalPages > 1 && (
              <div className="pagination">
                <button className="page-btn" disabled={page <= 1} onClick={() => update('page', String(page - 1))}>←</button>
                {Array.from({ length: totalPages }, (_, i) => i + 1).map((n) => (
                  <button
                    key={n}
                    className={`page-btn${n === page ? ' is-active' : ''}`}
                    onClick={() => update('page', String(n))}
                  >
                    {n}
                  </button>
                ))}
                <button className="page-btn" disabled={page >= totalPages} onClick={() => update('page', String(page + 1))}>→</button>
              </div>
            )}

            <div className="discipline-grid" style={{ marginTop: 'var(--space-xl)', borderTop: '1px solid var(--hairline)' }}>
              <div className="discipline">
                <p className="eyebrow">Cutting discipline</p>
                <h3>Cut to the Metre</h3>
                <p>Elastic, lace, binding and cord are cut from a single roll to your exact length and dispatched rolled — never folded into a parcel.</p>
              </div>
              <div className="discipline">
                <p className="eyebrow">Serviceable steel</p>
                <h3>Built to Sharpen</h3>
                <p>Carbon-steel shears and scissors from benches that still grind a hollow edge — tools meant for a whetstone, not a landfill.</p>
              </div>
              <div className="discipline">
                <p className="eyebrow">Studio accounts</p>
                <h3>Reorder in One Click</h3>
                <p>Registered studios keep a full re-order history: thread colours, elastic widths and needle sizes repeat exactly from previous consignments.</p>
              </div>
            </div>
          </section>
        </div>
      </div>
    </>
  )
}
