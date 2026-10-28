import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../services/api.js'
import { useAuth } from '../stores/store.js'
import { money, dateLabel } from '../lib/format.js'

/**
 * Customer returns: request a return on a delivered order, track its status,
 * and view past returns. Returns sit for 30 days from delivery; admin reviews
 * each request before a refund is issued.
 */
export function CustomerReturns() {
  const user = useAuth((s) => s.user)
  const ready = useAuth((s) => s.ready)
  const [returns, setReturns] = useState([])
  const [openId, setOpenId] = useState(null)
  const [detail, setDetail] = useState(null)
  const [newOrderId, setNewOrderId] = useState('')
  const [newReason, setNewReason] = useState('')
  const [error, setError] = useState(null)
  const [notice, setNotice] = useState(null)

  const load = useCallback(() => {
    if (!ready || !user) return
    api.get('/returns')
      .then(({ data }) => setReturns(data.returns || []))
      .catch(() => setReturns([]))
  }, [ready, user])

  useEffect(() => { load() }, [load])

  const flash = (text) => { setNotice(text); setError(null) }

  const loadDetail = async (id) => {
    setDetail(null)
    try {
      const { data } = await api.get(`/returns?id=${id}`)
      setDetail(data.return)
    } catch {
      setDetail({ error: 'Could not open that return.' })
    }
  }

  useEffect(() => { if (openId) loadDetail(openId) }, [openId, loadDetail])

  const requestReturn = async (e) => {
    e.preventDefault()
    if (!newOrderId.trim() || !newReason.trim()) return
    try {
      await api.post('/returns', { order_id: Number(newOrderId), reason: newReason.trim() })
      setNewOrderId('')
      setNewReason('')
      load()
      flash('Return request lodged — the house will review it.')
    } catch (err) { setError(err.message) }
  }

  if (!ready) return <div className="wrap"><p className="muted">Opening the return ledger…</p></div>

  if (!user) {
    return (
      <div className="wrap">
        <h1>Returns</h1>
        <p className="muted">Sign in to request a return on a delivered order.</p>
        <Link className="btn" to="/auth">Sign in</Link>
      </div>
    )
  }

  return (
    <div className="wrap">
      <nav className="breadcrumb" aria-label="Breadcrumb">
        <Link to="/">Buttonwalay</Link>
        <span className="sep">/</span>
        <span aria-current="page">Returns</span>
      </nav>

      <div className="section-head">
        <div>
          <p className="eyebrow">Return a consignment</p>
          <h1 style={{ marginTop: 'var(--space-sm)' }}>Returns</h1>
          <p className="t-body-sm muted">
            Request a return on a delivered order within 30 days. The house reviews each request and will
            confirm refund and restock details.
          </p>
        </div>
        <div className="right">
          <Link className="eyebrow link-u" to="/account">Account dossier</Link>
        </div>
      </div>

      {error && <p className="alert alert-error" style={{ marginBottom: 'var(--space-md)' }}>{error}</p>}
      {notice && <p className="alert alert-ok" style={{ marginBottom: 'var(--space-md)' }}>{notice}</p>}

      <div className="panel">
        <div className="panel-head">
          <h3 style={{ margin: 0, fontSize: 'var(--title-lg)' }}>Lodge a return request</h3>
          <span className="eyebrow">Delivered orders only</span>
        </div>
        <form onSubmit={requestReturn} className="grid" style={{ gridTemplateColumns: 'repeat(2, minmax(0, 1fr))', gap: 'var(--space-sm)' }}>
          <div className="field" style={{ marginBottom: 0 }}>
            <label>Order number</label>
            <input type="number" min="1" value={newOrderId} onChange={(e) => setNewOrderId(e.target.value)} placeholder="e.g. 1024" />
          </div>
          <div className="field" style={{ marginBottom: 0 }}>
            <label>Reason</label>
            <input value={newReason} onChange={(e) => setNewReason(e.target.value)} placeholder="Wrong shade, damaged on arrival, ordered twice…" />
          </div>
          <button className="btn btn-sm" type="submit" disabled={!newOrderId.trim() || !newReason.trim()}>Request return</button>
        </form>
        <p className="field-help">
          Returns are accepted within 30 days of delivery. Resellable items are returned to stock; damaged or
          defective items are not restocked but are still eligible for a refund at the house’s discretion.
        </p>
      </div>

      <div className="panel" style={{ marginTop: 'var(--space-lg)' }}>
        <div className="panel-head">
          <h3 style={{ margin: 0, fontSize: 'var(--title-lg)' }}>Your returns</h3>
          <span className="eyebrow">{returns.length} request{returns.length === 1 ? '' : 's'}</span>
        </div>
        {returns.length === 0 ? (
          <p className="t-body-sm muted">No returns have been lodged yet.</p>
        ) : (
          <table className="data-table">
            <thead>
              <tr><th>RMA</th><th className="num">Order</th><th>Reason</th><th className="num">Refund</th><th>Status</th><th>Requested</th><th /></tr>
            </thead>
            <tbody>
              {returns.map((r) => (
                <tr key={r.id}>
                  <td className="tabular">#{String(r.id).padStart(6, '0')}</td>
                  <td className="tabular muted">#{String(r.order_id).padStart(6, '0')}</td>
                  <td>{r.reason}</td>
                  <td className="num">{money(r.refund_amount)}</td>
                  <td><span className={`tag ${r.status === 'approved' ? 'tag--ok' : r.status === 'rejected' ? 'tag' : ''}`}>{r.status}</span></td>
                  <td className="tabular muted">{dateLabel(r.created_at)}</td>
                  <td className="right"><button className="eyebrow link-u" onClick={() => setOpenId(r.id)}>Open</button></td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>

      {openId && detail && !detail.error && (
        <div style={{ marginTop: 'var(--space-lg)', borderTop: '1px solid var(--hairline)', paddingTop: 'var(--space-md)' }}>
          <div className="panel-head">
            <h3 style={{ margin: 0, fontSize: 'var(--title-lg)' }}>Return #{String(detail.id).padStart(6, '0')}</h3>
            <button className="eyebrow link-u" onClick={() => setOpenId(null)}>Close record</button>
          </div>
          <div className="grid grid--split">
            <div>
              <span className="eyebrow">Order</span>
              <p className="t-body-sm muted">#{String(detail.order_id).padStart(6, '0')}</p>
              <span className="eyebrow" style={{ marginTop: 'var(--space-md)' }}>Reason</span>
              <p className="t-body-sm">{detail.reason}</p>
              <span className="eyebrow" style={{ marginTop: 'var(--space-md)' }}>Status</span>
              <p className="t-body-sm">{detail.status}</p>
              <span className="eyebrow" style={{ marginTop: 'var(--space-md)' }}>Refund</span>
              <p className="t-body-sm">{money(detail.refund_amount)}</p>
            </div>
            <div>
              <span className="eyebrow">Items</span>
              <div className="ledger">
                {detail.items?.map((item) => (
                  <div className="ledger-row" key={item.id} style={{ gridTemplateColumns: '1fr 100px 100px' }}>
                    <span>{item.product_name || 'Item'} — {item.quantity} × {item.condition}</span>
                    <td className="num">{item.restock ? 'Restock' : 'No restock'}</td>
                    <td className="num">{item.variant_sku || '—'}</td>
                  </div>
                ))}
                {detail.items?.length === 0 && <p className="t-body-sm muted">No items recorded yet.</p>}
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
