import { useCallback, useEffect, useMemo, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { api } from '../services/api.js'
import { useAuth } from '../stores/store.js'
import { AddressBook } from '../components/AddressBook.jsx'
import { dateLabel, money, statusTone, titleCase } from '../lib/format.js'

const STATUS_FLOW = ['pending', 'paid', 'shipped', 'delivered']

const TABS = [
  ['overview', 'Overview & ledger'],
  ['orders', 'Orders & records'],
  ['addresses', 'Dispatch directives'],
  ['profile', 'Client profile & security'],
]

/**
 * Client dossier. Metrics are sums of the order rows the API returned — the
 * server still owns every order's money, this page only aggregates the ledger
 * it was handed for the patron summary.
 */
export function Account() {
  const user = useAuth((s) => s.user)
  const ready = useAuth((s) => s.ready)
  const logout = useAuth((s) => s.logout)
  const navigate = useNavigate()
  const [orders, setOrders] = useState(null)
  const [openId, setOpenId] = useState(null)
  const [detail, setDetail] = useState(null)
  const [tab, setTab] = useState('overview')
  const [noteBody, setNoteBody] = useState('')

  useEffect(() => {
    if (ready && user) {
      api.get('/orders')
        .then(({ data }) => setOrders(data.orders || []))
        .catch(() => setOrders([]))
    }
  }, [ready, user])

  const loadDetail = useCallback(async (id) => {
    setDetail(null)
    try {
      const { data } = await api.get(`/orders?id=${id}`)
      setDetail(data.order)
    } catch {
      setDetail({ error: 'Could not open that consignment record.' })
    }
  }, [])

  useEffect(() => { if (openId) loadDetail(openId) }, [openId, loadDetail])

  const totals = useMemo(() => {
    const list = orders || []
    const spend = list.reduce((sum, o) => sum + Number(o.total || 0), 0)
    const units = list.reduce((sum, o) => sum + Number(o.item_count || 0), 0)
    const inFlight = list.filter((o) => ['pending', 'paid', 'shipped'].includes(o.status))
    return { spend, units, inFlight }
  }, [orders])

  if (!ready) return <div className="wrap"><p className="muted">Opening the dossier…</p></div>

  if (!user) {
    return (
      <div className="wrap">
        <h1>Client dossier</h1>
        <p className="muted">Sign in to review your order history, re-order records and dispatch notes.</p>
        <Link className="btn" to="/auth">Sign in to the ledger</Link>
      </div>
    )
  }

  const addNote = async (e) => {
    e.preventDefault()
    if (!noteBody.trim()) return
    try {
      await api.post('/orders', { order_id: openId, body: noteBody.trim(), visibility: 'customer' })
      setNoteBody('')
      await loadDetail(openId)
    } catch {
      // keep the typed note so it is not lost
    }
  }

  const signOut = async () => {
    await logout()
    navigate('/')
  }

  return (
    <div className="wrap">
      <nav className="breadcrumb" aria-label="Breadcrumb">
        <Link to="/">Buttonwalay</Link>
        <span className="sep">/</span>
        <span aria-current="page">Client dossier</span>
      </nav>

      <div className="section-head">
        <div>
          <p className="eyebrow">Client registry // ref #{String(user.id).padStart(4, '0')}</p>
          <h1 style={{ marginTop: 'var(--space-sm)' }}>{user.full_name}</h1>
          <p className="t-body-sm muted">{user.email} · {titleCase(user.role)} tier</p>
        </div>
        <div className="right">
          <p className="eyebrow">Counter concierge</p>
          <p className="t-body-sm muted">Restock desk · Mon–Sat</p>
          <button className="eyebrow link-u" onClick={signOut}>Sign out</button>
        </div>
      </div>

      <div className="tabs">
        {TABS.map(([key, label], i) => (
          <button key={key} className={`tab${tab === key ? ' is-active' : ''}`} onClick={() => setTab(key)}>
            {String(i + 1).padStart(2, '0')} {label}
          </button>
        ))}
      </div>

      {tab === 'overview' && (
        <>
          <div className="kpi-grid">
            <div className="kpi">
              <span className="eyebrow">Units on order</span>
              <span className="v">{totals.units}</span>
              <span className="note">Across {orders?.length || 0} consignment{(orders?.length || 0) === 1 ? '' : 's'}</span>
            </div>
            <div className="kpi">
              <span className="eyebrow">Lifetime acquisition</span>
              <span className="v">{money(totals.spend)}</span>
              <span className="note">All recorded order ledgers</span>
            </div>
            <div className="kpi">
              <span className="eyebrow">In-flight orders</span>
              <span className="v">{totals.inFlight.length}</span>
              <span className="note">Pending, paid or in transit</span>
            </div>
            <div className="kpi">
              <span className="eyebrow">Guild tier</span>
              <span className="v" style={{ fontSize: 'var(--title-lg)' }}>{titleCase(user.role)}</span>
              <span className="note">Tax-exempt invoicing available on request</span>
            </div>
          </div>

          <div className="grid grid--split" style={{ marginTop: 'var(--space-lg)' }}>
            <div className="panel">
              <div className="panel-head">
                <h2 style={{ margin: 0, fontSize: 'var(--title-lg)' }}>Active consignments</h2>
                <span className="eyebrow">{totals.inFlight.length} in flight</span>
              </div>
              {totals.inFlight.length === 0 ? (
                <p className="t-body-sm muted">No consignment is in transit. Orders appear here once one has been lodged.</p>
              ) : (
                <div className="ledger">
                  {totals.inFlight.map((o) => (
                    <div className="ledger-row" key={o.id} style={{ gridTemplateColumns: '1fr 140px 110px' }}>
                      <span>
                        <span className="eyebrow">Consignment #{String(o.id).padStart(6, '0')}</span>
                        <span className="tabular" style={{ display: 'block' }}>
                          {o.item_count} unit{o.item_count === 1 ? '' : 's'} reserved · discharged {dateLabel(o.created_at)}
                        </span>
                      </span>
                      <span><span className={`tag ${statusTone(o.status)}`}>{titleCase(o.status)}</span></span>
                      <span className="tabular right">{money(o.total, o.currency)}</span>
                    </div>
                  ))}
                </div>
              )}
              <button className="eyebrow link-u" style={{ marginTop: 'var(--space-md)' }} onClick={() => setTab('orders')}>
                View all orders ({orders?.length || 0})
              </button>
            </div>

            <div className="panel">
              <div className="panel-head">
                <h2 style={{ margin: 0, fontSize: 'var(--title-lg)' }}>Account privileges</h2>
                <span className="tag tag--saddle">Active</span>
              </div>
              <div className="spec-line"><span className="k">Restock alerts</span><span className="tabular">Colourways &amp; new blades</span></div>
              <div className="spec-line"><span className="k">Order records</span><span className="tabular">Full ledger retained</span></div>
              <div className="spec-line"><span className="k">Re-order</span><span className="tabular">One-click from past consignments</span></div>
              <div className="spec-line"><span className="k">Trade terms</span><span className="tabular">14-day invoice settlement</span></div>
              <p className="field-help">
                Notes are lodged against each order; add one from the ledger below.
              </p>
            </div>
          </div>
        </>
      )}

      {tab === 'orders' && !openId && (
        orders === null ? (
          <p className="muted">Consulting the ledger…</p>
        ) : orders.length === 0 ? (
          <div className="empty">
            <p style={{ marginBottom: 'var(--space-md)' }}>No consignment has been recorded yet.</p>
            <Link className="btn" to="/shop">Enter the catalogue</Link>
          </div>
        ) : (
          <div className="ledger">
            <div className="ledger-row ledger-head" style={{ gridTemplateColumns: '110px 150px 130px 90px 130px 100px' }}>
              <span>Consignment</span>
              <span>Recorded</span>
              <span>State</span>
              <span>Units</span>
              <span>Ledger total</span>
              <span />
            </div>
            {orders.map((o) => (
              <div className="ledger-row" key={o.id} style={{ gridTemplateColumns: '110px 150px 130px 90px 130px 100px' }}>
                <span className="tabular">#{String(o.id).padStart(6, '0')}</span>
                <span className="tabular muted">{dateLabel(o.created_at)}</span>
                <span><span className={`tag ${statusTone(o.status)}`}>{titleCase(o.status)}</span></span>
                <span className="tabular">{o.item_count}</span>
                <span className="tabular">{money(o.total, o.currency)}</span>
                <span className="right">
                  <button className="eyebrow link-u" onClick={() => setOpenId(o.id)}>Open</button>
                </span>
              </div>
            ))}
          </div>
        )
      )}

      {tab === 'orders' && openId && detail && (
        detail.error ? (
          <p className="alert alert-error">{detail.error}</p>
        ) : (
          <>
            <button className="eyebrow link-u" onClick={() => setOpenId(null)}>← Back to the order ledger</button>

            <div className="section-head" style={{ marginTop: 'var(--space-md)' }}>
              <div>
                <p className="eyebrow">Consignment record</p>
                <h2>#{String(detail.id).padStart(6, '0')}</h2>
              </div>
              <span className={`tag ${statusTone(detail.status)}`}>{titleCase(detail.status)}</span>
            </div>

            <div className="steps" style={{ marginBottom: 'var(--space-lg)' }}>
              {STATUS_FLOW.map((s) => {
                const idx = STATUS_FLOW.indexOf(detail.status)
                const done = idx >= 0 && STATUS_FLOW.indexOf(s) <= idx
                return (
                  <span key={s} className={`step${done ? ' is-current' : ''}`}>
                    {done ? '✓ ' : ''}{titleCase(s)}
                  </span>
                )
              })}
              {['cancelled', 'refunded'].includes(detail.status) && (
                <span className="step is-current">{titleCase(detail.status)}</span>
              )}
            </div>

            <div className="grid grid--split">
              <div className="panel">
                <div className="panel-head">
                  <h3 style={{ margin: 0, fontSize: 'var(--title-lg)' }}>Itemised ledger</h3>
                  <span className="eyebrow">Recorded {dateLabel(detail.created_at)}</span>
                </div>
                <div className="ledger">
                  {detail.items.map((item) => (
                    <div className="ledger-row" key={item.product_id} style={{ gridTemplateColumns: '1fr 120px 120px' }}>
                      <span>
                        <span className="eyebrow">Batch #{item.product_id}</span>
                        <Link className="tabular" style={{ display: 'block' }} to={`/product/${item.slug}`}>{item.name}</Link>
                        <span className="t-body-sm muted">{item.quantity} × {money(item.unit_price, detail.currency)}</span>
                      </span>
                      <span className="tabular muted right">
                        {item.unit === 'metre'
                          ? `${item.quantity} m cut`
                          : `${item.quantity} × ${item.unit_label || 'unit'}`}
                      </span>
                      <span className="tabular right">{money(item.line_total, detail.currency)}</span>
                    </div>
                  ))}
                </div>

                <div className="totals" style={{ marginTop: 'var(--space-md)' }}>
                  <div className="totals-row"><span>Subtotal</span><span className="v">{money(detail.subtotal, detail.currency)}</span></div>
                  {Number(detail.discount) > 0 && (
                    <div className="totals-row"><span>Privilege {detail.coupon_code ? `(${detail.coupon_code})` : ''}</span><span className="v neg">−{money(detail.discount, detail.currency)}</span></div>
                  )}
                  <div className="totals-row">
                    <span>Carriage{detail.shipping_method_name ? ` — ${detail.shipping_method_name}` : ''}</span>
                    <span className="v">{Number(detail.shipping) === 0 ? 'Waived' : money(detail.shipping, detail.currency)}</span>
                  </div>
                  <div className="totals-row"><span>Tax {detail.tax_name ? `(${detail.tax_name})` : ''}</span><span className="v">{money(detail.tax, detail.currency)}</span></div>
                  <div className="totals-row totals-row--total"><span>Total</span><span className="v">{money(detail.total, detail.currency)}</span></div>
                </div>
              </div>

              <div className="stack">
                <div className="panel">
                  <p className="eyebrow">Dispatch destination</p>
                  {detail.shipping_address ? (
                    <p className="t-body-sm" style={{ marginTop: 'var(--space-sm)' }}>
                      {detail.shipping_address.full_name}<br />
                      {detail.shipping_address.line1}<br />
                      {detail.shipping_address.line2 && <>{detail.shipping_address.line2}<br /></>}
                      {detail.shipping_address.city} {detail.shipping_address.region} {detail.shipping_address.postal_code}<br />
                      {detail.shipping_address.country}
                    </p>
                  ) : <p className="t-body-sm muted">No address on file.</p>}
                  <p className="eyebrow" style={{ marginTop: 'var(--space-md)' }}>Contact</p>
                  <p className="t-body-sm muted">{detail.customer_email || user.email}</p>
                </div>

                <div className="panel">
                  <div className="panel-head">
                    <p className="eyebrow" style={{ margin: 0 }}>Cutting notes</p>
                    <span className="eyebrow">{detail.notes.length} entry{detail.notes.length === 1 ? '' : 's'}</span>
                  </div>
                  {detail.notes.length === 0 && <p className="t-body-sm muted">No notes on this consignment.</p>}
                  {detail.notes.map((n) => (
                    <div key={n.id} style={{ borderBottom: '1px solid var(--hairline)', padding: '0.5rem 0' }}>
                      <p className="eyebrow" style={{ margin: 0 }}>{n.author} · {dateLabel(n.created_at)} · {n.visibility}</p>
                      <p className="t-body-sm" style={{ margin: '0.2rem 0 0' }}>{n.body}</p>
                    </div>
                  ))}
                  <form onSubmit={addNote} style={{ marginTop: 'var(--space-md)' }}>
                    <div className="field">
                      <label htmlFor="note">Add a cutting directive</label>
                      <textarea id="note" rows={3} value={noteBody} onChange={(e) => setNoteBody(e.target.value)} placeholder="Continuous length, grain matching, packaging…" />
                    </div>
                    <button className="btn btn-sm">Post directive</button>
                  </form>
                </div>
              </div>
            </div>
          </>
        )
      )}

      {tab === 'addresses' && (
        <>
          <div className="section-head">
            <div>
              <p className="eyebrow">Saved dispatch directives</p>
              <h2>Address ledger</h2>
            </div>
            <span className="eyebrow">One default per directive type</span>
          </div>
          <AddressBook />
        </>
      )}

      {tab === 'profile' && (
        <div className="grid grid--split">
          <div className="panel">
            <div className="panel-head">
              <h3 style={{ margin: 0, fontSize: 'var(--title-lg)' }}>Client dossier</h3>
              <span className="tag tag--ok">Verified</span>
            </div>
            <div className="spec-line"><span className="k">Principal maker</span><span className="tabular">{user.full_name}</span></div>
            <div className="spec-line"><span className="k">Registry email</span><span className="tabular">{user.email}</span></div>
            <div className="spec-line"><span className="k">Guild tier</span><span className="tabular">{titleCase(user.role)}</span></div>
            <div className="spec-line"><span className="k">Dossier ref</span><span className="tabular">#TL-{String(user.id).padStart(4, '0')}</span></div>
          </div>

          <div className="panel">
            <div className="panel-head">
              <h3 style={{ margin: 0, fontSize: 'var(--title-lg)' }}>Security</h3>
              <span className="eyebrow">Vault status: active</span>
            </div>
            <p className="t-body-sm muted">
              Sessions are held in rotating, http-only refresh cookies with a short-lived access token. Password changes and
              email verification are not wired into this build — no endpoint accepts a new passkey yet — so the dossier stays
              read-only here rather than offering a form that cannot succeed.
            </p>
            <div className="row" style={{ marginTop: 'var(--space-md)', gap: 'var(--space-sm)' }}>
              <button className="btn btn--outline btn-sm" onClick={signOut}>End session</button>
              <Link className="btn btn--outline btn-sm" to="/page/faq">Counter protocols</Link>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
