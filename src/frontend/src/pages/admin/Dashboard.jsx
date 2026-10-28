import { useCallback, useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../../services/api.js'
import { useAuth } from '../../stores/store.js'
import { dateLabel, money, statusTone, titleCase } from '../../lib/format.js'

const STATUSES = ['pending', 'paid', 'shipped', 'delivered', 'cancelled', 'refunded']

// The API only accepts these two reasons for a manual ledger write.
const ADJUST_REASONS = [
  ['manual_adjustment', 'Manual adjustment'],
  ['restock', 'Restock'],
]

const TABS = [
  ['overview', 'Overview'],
  ['orders', 'Fulfilment'],
  ['products', 'Products'],
  ['inventory', 'Inventory'],
  ['coupons', 'Privilege codes'],
  ['customers', 'Clients'],
  ['outbox', 'Outbox'],
  ['cms', 'CMS'],
]

/**
 * Operations console: revenue overview, fulfilment ledger, product editor,
 * stock ledger with movement log, privilege codes, clients, the queued mail
 * inspector and the CMS.
 *
 * Every write goes through the API — nothing here keeps a private copy of
 * server state, and each list is re-read from the server after a mutation.
 */
export function Dashboard() {
  const user = useAuth((s) => s.user)
  const ready = useAuth((s) => s.ready)
  const [tab, setTab] = useState('overview')
  const [statusFilter, setStatusFilter] = useState('')
  const [orders, setOrders] = useState([])
  const [orderDetail, setOrderDetail] = useState(null)
  const [openOrder, setOpenOrder] = useState(null)
  const [products, setProducts] = useState([])
  const [drafts, setDrafts] = useState({})
  const [error, setError] = useState(null)
  const [notice, setNotice] = useState(null)
  const [categories, setCategories] = useState([])
  const [coupons, setCoupons] = useState([])
  const [customers, setCustomers] = useState([])
  const [outbox, setOutbox] = useState([])
  const [message, setMessage] = useState(null)
  const [lowStock, setLowStock] = useState([])
  const [movements, setMovements] = useState([])
  const [cmsPages, setCmsPages] = useState([])
  const [settingsDraft, setSettingsDraft] = useState({})
  const [valuation, setValuation] = useState(null)
  const [adjust, setAdjust] = useState({})
  const [form, setForm] = useState({})

  const loadOrders = useCallback(() => {
    api.get(`/orders${statusFilter ? `?status=${statusFilter}` : ''}`)
      .then(({ data }) => setOrders(data.orders || []))
      .catch((e) => setError(e.message))
  }, [statusFilter])

  const loadProducts = useCallback(() => {
    api.get('/products?per_page=100')
      .then(({ data }) => setProducts(data.products || []))
      .catch((e) => setError(e.message))
  }, [])

  const loadAdmin = useCallback(() => {
    api.get('/categories?flat=1').then(({ data }) => setCategories(data.categories || [])).catch(() => {})
    api.get('/coupons').then(({ data }) => setCoupons(data.coupons || [])).catch(() => {})
    api.get('/customers').then(({ data }) => setCustomers(data.customers || [])).catch(() => {})
    api.get('/outbox').then(({ data }) => setOutbox(data.messages || [])).catch(() => {})
    api.get('/inventory?report=low_stock').then(({ data }) => setLowStock(data.products || [])).catch(() => {})
    api.get('/inventory?report=valuation').then(({ data }) => setValuation(data.summary)).catch(() => {})
    api.get('/inventory?report=movements').then(({ data }) => setMovements(data.movements || [])).catch(() => {})
    api.get('/pages?all=1').then(({ data }) => setCmsPages(data.pages || [])).catch(() => {})
    api.get('/settings?all=1').then(({ data }) => setSettingsDraft(data.settings || {})).catch(() => {})
  }, [])

  useEffect(() => { loadOrders() }, [loadOrders])
  useEffect(() => { loadProducts() }, [loadProducts])
  useEffect(() => {
    if (!ready || user?.role !== 'admin') return
    loadAdmin()
  }, [ready, user?.role, loadAdmin])

  useEffect(() => {
    if (!openOrder) { setOrderDetail(null); return }
    api.get(`/orders?id=${openOrder}`)
      .then(({ data }) => setOrderDetail(data.order))
      .catch((e) => setError(e.message))
  }, [openOrder])

  const revenue = useMemo(() => {
    const list = orders || []
    const counted = list.filter((o) => !['cancelled', 'refunded'].includes(o.status))
    const gross = counted.reduce((sum, o) => sum + Number(o.total || 0), 0)
    const units = counted.reduce((sum, o) => sum + Number(o.item_count || 0), 0)
    return {
      gross,
      orders: counted.length,
      aov: counted.length > 0 ? gross / counted.length : 0,
      units,
      pending: list.filter((o) => o.status === 'pending').length,
    }
  }, [orders])

  if (!ready) return <div className="wrap"><p className="muted">Opening the console…</p></div>

  if (!user || user.role !== 'admin') {
    return (
      <div className="wrap">
        <h1>House console</h1>
        <p className="muted">Administrators only. <Link className="link-u" to="/auth">Sign in</Link> with an admin account.</p>
      </div>
    )
  }

  const flash = (text) => {
    setNotice(text)
    setError(null)
  }

  const setOrderStatus = async (id, status) => {
    try {
      await api.patch('/orders', { id, status })
      flash(`Consignment #${id} → ${status}`)
      loadOrders()
      loadAdmin()
      if (openOrder === id) {
        const { data } = await api.get(`/orders?id=${id}`)
        setOrderDetail(data.order)
      }
    } catch (e) {
      setError(e.message)
    }
  }

  const draft = (id) => drafts[id] || {}
  const setDraft = (id, key, value) => setDrafts((d) => ({ ...d, [id]: { ...d[id], [key]: value } }))

  const saveProduct = async (p) => {
    const d = draft(p.id)
    const body = { id: p.id }
    if (d.name !== undefined && d.name !== p.name) body.name = d.name
    if (d.price !== undefined && d.price !== p.price) body.price = d.price
    if (d.stock !== undefined && d.stock !== p.stock) body.stock = Number(d.stock)
    if (d.is_active !== undefined && d.is_active !== String(p.is_active)) body.is_active = d.is_active === '1'
    if (Object.keys(body).length === 1) return
    try {
      await api.patch('/products', body)
      flash(`Notion #${p.id} saved`)
      setDrafts((prev) => ({ ...prev, [p.id]: {} }))
      loadProducts()
    } catch (e) {
      setError(e.message)
    }
  }

  const createCategory = async (e) => {
    e.preventDefault()
    try {
      await api.post('/categories', { name: form.catName })
      flash('Chapter created')
      setForm((f) => ({ ...f, catName: '' }))
      api.get('/categories?flat=1').then(({ data }) => setCategories(data.categories || []))
    } catch (err) { setError(err.message) }
  }

  const createCoupon = async (e) => {
    e.preventDefault()
    try {
      await api.post('/coupons', {
        code: form.code || undefined,
        type: form.type || 'fixed',
        value: form.value || '0',
        minimum_order: form.min || '0',
        max_uses: form.maxUses ? Number(form.maxUses) : null,
        expires_at: form.expires || null,
      })
      flash('Privilege code created')
      setForm((f) => ({ ...f, code: '', value: '', min: '', maxUses: '', expires: '' }))
      api.get('/coupons').then(({ data }) => setCoupons(data.coupons || []))
    } catch (err) { setError(err.message) }
  }

  const adjustStock = async (p) => {
    const a = adjust[p.id] || {}
    if (!a.delta) return
    try {
      await api.post('/inventory', {
        product_id: p.id,
        delta: Number(a.delta),
        reason: a.reason || 'manual_adjustment',
        note: a.note || undefined,
      })
      flash(`${p.name}: stock ${Number(a.delta) > 0 ? '+' : ''}${a.delta} written to the ledger`)
      setAdjust((prev) => ({ ...prev, [p.id]: {} }))
      loadProducts()
      loadAdmin()
    } catch (err) { setError(err.message) }
  }

  const createPage = async () => {
    try {
      const { data } = await api.post('/pages', { title: form.pageTitle || 'Untitled' })
      flash(`Dossier #${data.page.id} created as a draft`)
      setForm((f) => ({ ...f, pageTitle: '' }))
      api.get('/pages?all=1').then(({ data: d }) => setCmsPages(d.pages || []))
    } catch (err) { setError(err.message) }
  }

  const savePage = async (p) => {
    try {
      await api.patch('/pages', { id: p.id, title: p.title, status: p.status })
      flash(`Dossier “${p.title}” saved`)
      api.get('/pages?all=1').then(({ data }) => setCmsPages(data.pages || []))
    } catch (err) { setError(err.message) }
  }

  const saveSettings = async () => {
    try {
      await api.patch('/settings', { settings: settingsDraft })
      flash('Storefront copy saved')
      api.get('/settings?all=1').then(({ data }) => setSettingsDraft(data.settings || {}))
    } catch (err) { setError(err.message) }
  }

  const openMessage = async (id) => {
    try {
      const { data } = await api.get(`/outbox?id=${id}`)
      setMessage(data.message)
    } catch (err) { setError(err.message) }
  }

  return (
    <div className="wrap">
      <div className="admin-head">
        <div>
          <p className="eyebrow">House operations console</p>
          <h1 style={{ marginTop: 'var(--space-sm)' }}>{titleCase(tab)}</h1>
        </div>
        <div className="right">
          <p className="eyebrow">Signed in as {user.full_name}</p>
          <Link className="eyebrow link-u" to="/">View storefront</Link>
        </div>
      </div>

      <div className="tabs">
        {TABS.map(([key, label]) => (
          <button key={key} className={`tab${tab === key ? ' is-active' : ''}`} onClick={() => setTab(key)}>{label}</button>
        ))}
      </div>

      {error && <p className="alert alert-error" style={{ marginBottom: 'var(--space-md)' }}>{error}</p>}
      {notice && <p className="alert alert-ok" style={{ marginBottom: 'var(--space-md)' }}>{notice}</p>}

      {tab === 'overview' && (
        <>
          <div className="kpi-grid">
            <div className="kpi">
              <span className="eyebrow">Net revenue</span>
              <span className="v">{money(revenue.gross)}</span>
              <span className="note">Excludes cancelled and refunded consignments</span>
            </div>
            <div className="kpi">
              <span className="eyebrow">Consignments</span>
              <span className="v">{revenue.orders}</span>
              <span className="note">{revenue.pending} awaiting payment</span>
            </div>
            <div className="kpi">
              <span className="eyebrow">Average order</span>
              <span className="v">{money(revenue.aov)}</span>
              <span className="note">{revenue.units} units dispatched</span>
            </div>
            <div className="kpi">
              <span className="eyebrow">Stock at valuation</span>
              <span className="v">{valuation ? money(valuation.total_value) : '—'}</span>
              <span className="note">
                {valuation ? `${valuation.product_count} references` : 'No valuation yet'}
                {valuation?.units_unpriced > 0 ? ` · ${valuation.units_unpriced} unpriced units` : ''}
              </span>
            </div>
          </div>

          <div className="grid grid--split" style={{ marginTop: 'var(--space-lg)' }}>
            <div className="panel">
              <div className="panel-head">
                <h2 style={{ margin: 0, fontSize: 'var(--title-lg)' }}>Recent consignments</h2>
                <button className="eyebrow link-u" onClick={() => setTab('orders')}>Open fulfilment</button>
              </div>
              {orders.length === 0 ? <p className="t-body-sm muted">No orders recorded yet.</p> : (
                <div className="ledger">
                  {orders.slice(0, 6).map((o) => (
                    <div className="ledger-row" key={o.id} style={{ gridTemplateColumns: '1fr 130px 110px' }}>
                      <span>
                        <span className="tabular">#{String(o.id).padStart(6, '0')}</span>
                        <span className="t-body-sm muted" style={{ display: 'block' }}>{o.customer_email || '—'} · {dateLabel(o.created_at)}</span>
                      </span>
                      <span><span className={`tag ${statusTone(o.status)}`}>{titleCase(o.status)}</span></span>
                      <span className="tabular right">{money(o.total, o.currency)}</span>
                    </div>
                  ))}
                </div>
              )}
            </div>

            <div className="panel">
              <div className="panel-head">
                <h2 style={{ margin: 0, fontSize: 'var(--title-lg)' }}>Replenishment watch</h2>
                <button className="eyebrow link-u" onClick={() => setTab('inventory')}>Open inventory</button>
              </div>
              {lowStock.length === 0 ? <p className="t-body-sm muted">Nothing is at or below its threshold.</p> : (
                <div className="ledger">
                  {lowStock.slice(0, 6).map((p) => (
                    <div className="ledger-row" key={p.id} style={{ gridTemplateColumns: '1fr 90px 90px' }}>
                      <span>{p.name}</span>
                      <span className="tabular muted right">{p.stock} on hand</span>
                      <span className="tabular right">min {p.low_stock_threshold}</span>
                    </div>
                  ))}
                </div>
              )}
            </div>
          </div>
        </>
      )}

      {tab === 'orders' && (
        <div className="panel">
          <div className="panel-head">
            <h2 style={{ margin: 0, fontSize: 'var(--title-lg)' }}>Fulfilment ledger</h2>
            <div className="toolbar">
              <label className="eyebrow" htmlFor="status-filter">State</label>
              <select id="status-filter" value={statusFilter} onChange={(e) => setStatusFilter(e.target.value)}>
                <option value="">All states</option>
                {STATUSES.map((s) => <option key={s} value={s}>{titleCase(s)}</option>)}
              </select>
            </div>
          </div>

          <table className="data-table">
            <thead>
              <tr>
                <th>Consignment</th><th>Recorded</th><th>Client</th><th>Units</th>
                <th className="num">Total</th><th>State</th><th>Move to</th><th />
              </tr>
            </thead>
            <tbody>
              {orders.map((o) => (
                <tr key={o.id}>
                  <td className="tabular">#{String(o.id).padStart(6, '0')}</td>
                  <td className="tabular muted">{dateLabel(o.created_at)}</td>
                  <td className="muted">{o.customer_email || '—'}</td>
                  <td className="tabular">{o.item_count}</td>
                  <td className="num">{money(o.total, o.currency)}</td>
                  <td><span className={`tag ${statusTone(o.status)}`}>{titleCase(o.status)}</span></td>
                  <td>
                    <select value="" onChange={(e) => e.target.value && setOrderStatus(o.id, e.target.value)}>
                      <option value="">change…</option>
                      {STATUSES.map((s) => <option key={s} value={s}>{titleCase(s)}</option>)}
                    </select>
                  </td>
                  <td className="right">
                    <button className="eyebrow link-u" onClick={() => setOpenOrder(o.id)}>Open</button>
                  </td>
                </tr>
              ))}
              {orders.length === 0 && <tr><td colSpan={8} className="muted">No consignments in this state.</td></tr>}
            </tbody>
          </table>

          {openOrder && orderDetail && !orderDetail.error && (
            <div style={{ marginTop: 'var(--space-lg)', borderTop: '1px solid var(--hairline)', paddingTop: 'var(--space-md)' }}>
              <div className="panel-head">
                <h3 style={{ margin: 0, fontSize: 'var(--title-lg)' }}>Consignment #{String(orderDetail.id).padStart(6, '0')}</h3>
                <button className="eyebrow link-u" onClick={() => setOpenOrder(null)}>Close record</button>
              </div>

              <div className="grid grid--split">
                <div>
                  <span className="eyebrow">Items</span>
                  <div className="ledger">
                    {orderDetail.items?.map((item) => (
                      <div className="ledger-row" key={item.id || item.product_id} style={{ gridTemplateColumns: '1fr 100px 110px' }}>
                        <span>
                          <Link className="tabular" to={`/product/${item.slug}`}>{item.name}</Link>
                          <span className="t-body-sm muted" style={{ display: 'block' }}>{item.quantity} × {money(item.unit_price, orderDetail.currency)}</span>
                        </span>
                        <span className="tabular muted right">
                          {item.unit === 'metre'
                            ? `${item.quantity} m cut`
                            : `${item.quantity} × ${item.unit_label || 'unit'}`}
                        </span>
                        <span className="tabular right">{money(item.line_total, orderDetail.currency)}</span>
                      </div>
                    ))}
                  </div>
                  <div className="totals" style={{ marginTop: 'var(--space-md)' }}>
                    <div className="totals-row"><span>Subtotal</span><span className="v">{money(orderDetail.subtotal, orderDetail.currency)}</span></div>
                    {Number(orderDetail.discount) > 0 && (
                      <div className="totals-row"><span>Privilege {orderDetail.coupon_code ? `(${orderDetail.coupon_code})` : ''}</span><span className="v neg">−{money(orderDetail.discount, orderDetail.currency)}</span></div>
                    )}
                    <div className="totals-row"><span>Carriage{orderDetail.shipping_method_name ? ` — ${orderDetail.shipping_method_name}` : ''}</span><span className="v">{money(orderDetail.shipping, orderDetail.currency)}</span></div>
                    <div className="totals-row"><span>Tax</span><span className="v">{money(orderDetail.tax, orderDetail.currency)}</span></div>
                    <div className="totals-row totals-row--total"><span>Total</span><span className="v">{money(orderDetail.total, orderDetail.currency)}</span></div>
                  </div>
                </div>

                <div>
                  <span className="eyebrow">Dispatch &amp; notes</span>
                  {orderDetail.shipping_address && (
                    <p className="t-body-sm" style={{ marginTop: 'var(--space-sm)' }}>
                      {orderDetail.shipping_address.full_name}<br />
                      {orderDetail.shipping_address.line1}<br />
                      {orderDetail.shipping_address.city} {orderDetail.shipping_address.postal_code} {orderDetail.shipping_address.country}
                    </p>
                  )}
                  {orderDetail.allowed_next?.length > 0 && (
                    <p className="t-body-sm muted">Permitted transitions: {orderDetail.allowed_next.join(' · ')}</p>
                  )}
                  <div style={{ marginTop: 'var(--space-md)' }}>
                    {(orderDetail.notes || []).map((n) => (
                      <div key={n.id} style={{ borderBottom: '1px solid var(--hairline)', padding: '0.5rem 0' }}>
                        <p className="eyebrow" style={{ margin: 0 }}>{n.author} · {dateLabel(n.created_at)} · {n.visibility}</p>
                        <p className="t-body-sm" style={{ margin: '0.2rem 0 0' }}>{n.body}</p>
                      </div>
                    ))}
                    {(orderDetail.notes || []).length === 0 && <p className="t-body-sm muted">No notes recorded.</p>}
                  </div>
                </div>
              </div>
            </div>
          )}
        </div>
      )}

      {tab === 'products' && (
        <>
          <p className="t-body-sm muted" style={{ marginBottom: 'var(--space-md)' }}>
            Edit name, price, stock or visibility. Price changes are recorded in price history, and stock edits belong in the
            inventory ledger — the stock field here writes directly to the product row.
          </p>
          <div className="panel panel--flush">
            <table className="data-table">
              <thead>
                <tr><th>#</th><th>Reference</th><th>Name</th><th>Price</th><th>Stock</th><th>Active</th><th /></tr>
              </thead>
              <tbody>
                {products.map((p) => {
                  const d = draft(p.id)
                  return (
                    <tr key={p.id}>
                      <td className="tabular muted">{p.id}</td>
                      <td className="tabular muted">{p.specs?.ref || '—'}</td>
                      <td><input value={d.name ?? p.name} onChange={(e) => setDraft(p.id, 'name', e.target.value)} style={{ minWidth: 200 }} /></td>
                      <td><input value={d.price ?? p.price} onChange={(e) => setDraft(p.id, 'price', e.target.value)} style={{ width: 90 }} /></td>
                      <td><input type="number" min="0" value={d.stock ?? p.stock} onChange={(e) => setDraft(p.id, 'stock', e.target.value)} style={{ width: 80 }} /></td>
                      <td>
                        <select value={d.is_active ?? String(p.is_active)} onChange={(e) => setDraft(p.id, 'is_active', e.target.value)}>
                          <option value="1">Listed</option>
                          <option value="0">Archived</option>
                        </select>
                      </td>
                      <td className="right"><button className="btn btn-sm" onClick={() => saveProduct(p)}>Save</button></td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>

          <div className="panel" style={{ marginTop: 'var(--space-lg)' }}>
            <div className="panel-head">
              <h3 style={{ margin: 0, fontSize: 'var(--title-lg)' }}>Catalogue chapters</h3>
              <span className="eyebrow">{categories.length} chapters</span>
            </div>
            <form onSubmit={createCategory} className="row" style={{ gap: 'var(--space-sm)', marginBottom: 'var(--space-md)' }}>
              <input
                className="input grow"
                placeholder="New chapter name"
                value={form.catName || ''}
                onChange={(e) => setForm((f) => ({ ...f, catName: e.target.value }))}
              />
              <button className="btn btn-sm" disabled={!form.catName?.trim()}>Add chapter</button>
            </form>
            <table className="data-table">
              <thead><tr><th>ID</th><th>Name</th><th>Slug</th><th className="num">References</th></tr></thead>
              <tbody>
                {categories.map((c) => (
                  <tr key={c.id}>
                    <td className="tabular muted">{c.id}</td>
                    <td>{c.name}</td>
                    <td className="tabular muted">{c.slug}</td>
                    <td className="num">{c.product_count ?? '—'}</td>
                  </tr>
                ))}
                {categories.length === 0 && <tr><td colSpan={4} className="muted">No chapters yet.</td></tr>}
              </tbody>
            </table>
          </div>
        </>
      )}

      {tab === 'inventory' && (
        <>
          <div className="grid grid--split">
            <div className="panel">
              <div className="panel-head">
                <h3 style={{ margin: 0, fontSize: 'var(--title-lg)' }}>Replenishment watch</h3>
                <span className="eyebrow">{lowStock.length} at threshold</span>
              </div>
              {lowStock.length === 0 ? <p className="t-body-sm muted">Nothing is at or below its threshold.</p> : (
                <table className="data-table">
                  <thead><tr><th>Reference</th><th>Notion</th><th className="num">On hand</th><th className="num">Threshold</th></tr></thead>
                  <tbody>
                    {lowStock.map((p) => (
                      <tr key={p.id}>
                        <td className="tabular muted">{p.sku || '—'}</td>
                        <td>{p.name}</td>
                        <td className="num">{p.stock}</td>
                        <td className="num muted">{p.low_stock_threshold}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              )}
            </div>

            <div className="panel">
              <div className="panel-head">
                <h3 style={{ margin: 0, fontSize: 'var(--title-lg)' }}>Valuation</h3>
                <span className="eyebrow">At unit cost</span>
              </div>
              {valuation ? (
                <>
                  <p className="tabular" style={{ fontFamily: 'var(--serif)', fontSize: 'var(--headline-sm)', margin: 0 }}>
                    {money(valuation.total_value)}
                  </p>
                  <div className="spec-line"><span className="k">References</span><span className="tabular">{valuation.product_count}</span></div>
                  <div className="spec-line"><span className="k">Unpriced units</span><span className="tabular">{valuation.units_unpriced ?? 0}</span></div>
                  <p className="field-help">Units without a cost set are excluded from the valuation.</p>
                </>
              ) : <p className="t-body-sm muted">No valuation returned.</p>}
            </div>
          </div>

          <div className="panel" style={{ marginTop: 'var(--space-lg)' }}>
            <div className="panel-head">
              <h3 style={{ margin: 0, fontSize: 'var(--title-lg)' }}>Adjust stock</h3>
              <span className="eyebrow">Every write is a ledger row</span>
            </div>
            <table className="data-table">
              <thead><tr><th>Notion</th><th className="num">On hand</th><th>Delta</th><th>Reason</th><th>Note</th><th /></tr></thead>
              <tbody>
                {products.map((p) => {
                  const a = adjust[p.id] || {}
                  return (
                    <tr key={p.id}>
                      <td>{p.name}</td>
                      <td className="num">{p.stock}</td>
                      <td>
                        <input
                          type="number"
                          value={a.delta ?? ''}
                          onChange={(e) => setAdjust((s) => ({ ...s, [p.id]: { ...a, delta: e.target.value } }))}
                          style={{ width: 90 }}
                          aria-label={`Delta for ${p.name}`}
                        />
                      </td>
                      <td>
                        <select
                          value={a.reason || 'manual_adjustment'}
                          onChange={(e) => setAdjust((s) => ({ ...s, [p.id]: { ...a, reason: e.target.value } }))}
                        >
                          {ADJUST_REASONS.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                        </select>
                      </td>
                      <td>
                        <input
                          value={a.note || ''}
                          onChange={(e) => setAdjust((s) => ({ ...s, [p.id]: { ...a, note: e.target.value } }))}
                          style={{ minWidth: 160 }}
                          placeholder="Cutting room note"
                        />
                      </td>
                      <td className="right"><button className="btn btn-sm" onClick={() => adjustStock(p)} disabled={!a.delta}>Apply</button></td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>

          <div className="panel" style={{ marginTop: 'var(--space-lg)' }}>
            <div className="panel-head">
              <h3 style={{ margin: 0, fontSize: 'var(--title-lg)' }}>Movement log</h3>
              <span className="eyebrow">Most recent first</span>
            </div>
            <table className="data-table">
              <thead><tr><th>Recorded</th><th>Notion</th><th className="num">Delta</th><th>Reason</th><th>Note</th></tr></thead>
              <tbody>
                {movements.map((m) => (
                  <tr key={m.id}>
                    <td className="tabular muted">{dateLabel(m.created_at)}</td>
                    <td>{m.name}</td>
                    <td className="num" style={{ color: m.delta > 0 ? 'var(--moss)' : 'var(--madder)' }}>
                      {m.delta > 0 ? '+' : ''}{m.delta}
                    </td>
                    <td className="muted">{titleCase(m.reason)}</td>
                    <td className="muted">{m.note || '—'}</td>
                  </tr>
                ))}
                {movements.length === 0 && <tr><td colSpan={5} className="muted">No movements recorded.</td></tr>}
              </tbody>
            </table>
          </div>
        </>
      )}

      {tab === 'coupons' && (
        <div className="panel">
          <div className="panel-head">
            <h3 style={{ margin: 0, fontSize: 'var(--title-lg)' }}>Privilege codes</h3>
            <span className="eyebrow">{coupons.length} issued</span>
          </div>

          <form onSubmit={createCoupon} className="grid" style={{ gridTemplateColumns: 'repeat(5, minmax(0, 1fr))' }}>
            <div className="field" style={{ marginBottom: 0 }}>
              <label>Code</label>
              <input value={form.code || ''} onChange={(e) => setForm((f) => ({ ...f, code: e.target.value.toUpperCase() }))} placeholder="GUILD25" />
            </div>
            <div className="field" style={{ marginBottom: 0 }}>
              <label>Type</label>
              <select value={form.type || 'fixed'} onChange={(e) => setForm((f) => ({ ...f, type: e.target.value }))}>
                <option value="fixed">Fixed amount</option>
                <option value="percent">Percent off</option>
              </select>
            </div>
            <div className="field" style={{ marginBottom: 0 }}>
              <label>Value</label>
              <input value={form.value || ''} onChange={(e) => setForm((f) => ({ ...f, value: e.target.value }))} placeholder={form.type === 'percent' ? '10' : '25.00'} />
            </div>
            <div className="field" style={{ marginBottom: 0 }}>
              <label>Minimum order</label>
              <input value={form.min || ''} onChange={(e) => setForm((f) => ({ ...f, min: e.target.value }))} />
            </div>
            <div className="field" style={{ marginBottom: 0 }}>
              <label>Max uses</label>
              <input type="number" min="1" value={form.maxUses || ''} onChange={(e) => setForm((f) => ({ ...f, maxUses: e.target.value }))} />
            </div>
          </form>
          <button className="btn btn-sm" style={{ marginTop: 'var(--space-lg)' }} disabled={!form.value} onClick={createCoupon}>Issue code</button>

          <table className="data-table" style={{ marginTop: 'var(--space-lg)' }}>
            <thead><tr><th>Code</th><th>Type</th><th className="num">Value</th><th className="num">Minimum</th><th className="num">Used</th><th>Expires</th><th>Active</th></tr></thead>
            <tbody>
              {coupons.map((c) => (
                <tr key={c.id}>
                  <td className="tabular">{c.code}</td>
                  <td className="muted">{titleCase(c.type)}</td>
                  <td className="num">{c.type === 'percent' ? `${c.value}%` : money(c.value)}</td>
                  <td className="num muted">{Number(c.minimum_order) > 0 ? money(c.minimum_order) : '—'}</td>
                  <td className="num">{c.used_count ?? c.uses ?? 0}{c.max_uses ? ` / ${c.max_uses}` : ''}</td>
                  <td className="tabular muted">{c.expires_at ? dateLabel(c.expires_at) : '—'}</td>
                  <td>{c.is_active ? <span className="tag tag--ok">Active</span> : <span className="tag">Retired</span>}</td>
                </tr>
              ))}
              {coupons.length === 0 && <tr><td colSpan={7} className="muted">No privilege codes issued yet.</td></tr>}
            </tbody>
          </table>
        </div>
      )}

      {tab === 'customers' && (
        <div className="panel">
          <div className="panel-head">
            <h3 style={{ margin: 0, fontSize: 'var(--title-lg)' }}>Client registry</h3>
            <span className="eyebrow">{customers.length} dossiers</span>
          </div>
          <table className="data-table">
            <thead><tr><th>#</th><th>Name</th><th>Email</th><th>Guild tier</th><th className="num">Orders</th><th className="num">Lifetime spend</th><th>Joined</th></tr></thead>
            <tbody>
              {customers.map((c) => (
                <tr key={c.id}>
                  <td className="tabular muted">#{String(c.id).padStart(4, '0')}</td>
                  <td>{c.full_name}</td>
                  <td className="muted">{c.email}</td>
                  <td className="muted">{titleCase(c.role)}</td>
                  <td className="num">{c.order_count}</td>
                  <td className="num">{money(c.total_spent ?? c.lifetime_spend ?? 0)}</td>
                  <td className="tabular muted">{dateLabel(c.created_at)}</td>
                </tr>
              ))}
              {customers.length === 0 && <tr><td colSpan={7} className="muted">No clients registered yet.</td></tr>}
            </tbody>
          </table>
        </div>
      )}

      {tab === 'outbox' && (
        <>
          <div className="panel">
            <div className="panel-head">
              <h3 style={{ margin: 0, fontSize: 'var(--title-lg)' }}>Queued correspondence</h3>
              <span className="eyebrow">{outbox.length} messages</span>
            </div>
            <p className="t-body-sm muted">
              Mail is queued to the database rather than sent by this build, so every message is inspectable — select one to read
              its body.
            </p>
            <table className="data-table">
              <thead><tr><th>To</th><th>Subject</th><th>State</th><th>Queued</th><th /></tr></thead>
              <tbody>
                {outbox.map((m) => (
                  <tr key={m.id}>
                    <td className="muted">{m.to_email}</td>
                    <td>{m.subject}</td>
                    <td><span className={`tag ${m.status === 'sent' ? 'tag--ok' : m.status === 'failed' ? 'tag--error' : ''}`}>{titleCase(m.status)}</span></td>
                    <td className="tabular muted">{dateLabel(m.queued_at)}</td>
                    <td className="right"><button className="eyebrow link-u" onClick={() => openMessage(m.id)}>Inspect</button></td>
                  </tr>
                ))}
                {outbox.length === 0 && <tr><td colSpan={5} className="muted">Nothing has been queued.</td></tr>}
              </tbody>
            </table>
          </div>

          {message && (
            <div className="panel" style={{ marginTop: 'var(--space-lg)' }}>
              <div className="panel-head">
                <h3 style={{ margin: 0, fontSize: 'var(--title-lg)' }}>{message.subject}</h3>
                <button className="eyebrow link-u" onClick={() => setMessage(null)}>Close message</button>
              </div>
              <div className="spec-line"><span className="k">To</span><span className="tabular">{message.to_email}</span></div>
              <div className="spec-line"><span className="k">State</span><span className="tabular">{titleCase(message.status)}</span></div>
              <div className="spec-line"><span className="k">Queued</span><span className="tabular">{dateLabel(message.queued_at)}</span></div>
              <div className="spec-line"><span className="k">Template</span><span className="tabular">{message.template || '—'}</span></div>
              <pre style={{
                whiteSpace: 'pre-wrap',
                fontFamily: 'var(--sans)',
                fontSize: 'var(--body-sm)',
                borderTop: '1px solid var(--hairline)',
                marginTop: 'var(--space-md)',
                paddingTop: 'var(--space-md)',
              }}>
                {message.body || message.body_text || '(no body recorded)'}
              </pre>
            </div>
          )}
        </>
      )}

      {tab === 'cms' && (
        <>
          <div className="panel">
            <div className="panel-head">
              <h3 style={{ margin: 0, fontSize: 'var(--title-lg)' }}>Content dossiers</h3>
              <span className="eyebrow">Drafts stay invisible to the storefront</span>
            </div>
            <div className="row" style={{ gap: 'var(--space-sm)', marginBottom: 'var(--space-md)' }}>
              <input
                className="input grow"
                placeholder="New dossier title"
                value={form.pageTitle || ''}
                onChange={(e) => setForm((f) => ({ ...f, pageTitle: e.target.value }))}
              />
              <button className="btn btn-sm" disabled={!form.pageTitle?.trim()} onClick={createPage}>Create draft</button>
            </div>
            <table className="data-table">
              <thead><tr><th>#</th><th>Title</th><th>Slug</th><th>Status</th><th>Updated</th><th /></tr></thead>
              <tbody>
                {cmsPages.map((p) => (
                  <tr key={p.id}>
                    <td className="tabular muted">{p.id}</td>
                    <td>
                      <input
                        value={p.title}
                        onChange={(e) => setCmsPages((ps) => ps.map((x) => (x.id === p.id ? { ...x, title: e.target.value } : x)))}
                        style={{ minWidth: 220 }}
                      />
                    </td>
                    <td><Link className="link-u" to={`/page/${p.slug}`}>{p.slug}</Link></td>
                    <td>
                      <select
                        value={p.status}
                        onChange={(e) => setCmsPages((ps) => ps.map((x) => (x.id === p.id ? { ...x, status: e.target.value } : x)))}
                      >
                        <option value="draft">Draft</option>
                        <option value="published">Published</option>
                      </select>
                    </td>
                    <td className="tabular muted">{dateLabel(p.updated_at)}</td>
                    <td className="right"><button className="btn btn-sm" onClick={() => savePage(p)}>Save</button></td>
                  </tr>
                ))}
                {cmsPages.length === 0 && <tr><td colSpan={6} className="muted">No dossiers yet.</td></tr>}
              </tbody>
            </table>
          </div>

          <div className="panel" style={{ marginTop: 'var(--space-lg)' }}>
            <div className="panel-head">
              <h3 style={{ margin: 0, fontSize: 'var(--title-lg)' }}>Storefront copy</h3>
              <span className="eyebrow">{Object.keys(settingsDraft).length} keys</span>
            </div>
            {Object.keys(settingsDraft).length === 0 ? (
              <p className="t-body-sm muted">No settings returned — run migration 019 (or the seeder) to populate the house copy.</p>
            ) : (
              <div className="grid grid--2">
                {Object.entries(settingsDraft).map(([key, value]) => (
                  <div className="field" key={key} style={{ marginBottom: 0 }}>
                    <label htmlFor={`s-${key}`}>{key}</label>
                    <input id={`s-${key}`} value={value} onChange={(e) => setSettingsDraft((s) => ({ ...s, [key]: e.target.value }))} />
                  </div>
                ))}
              </div>
            )}
            <button className="btn btn-sm" style={{ marginTop: 'var(--space-lg)' }} onClick={saveSettings}>Save copy</button>
          </div>
        </>
      )}
    </div>
  )
}
