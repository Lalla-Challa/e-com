import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../../services/api.js'
import { useAuth } from '../../stores/store.js'
import { money, dateLabel } from '../../lib/format.js'

/**
 * Returns console: customer-initiated RMAs. Admin reviews each request,
 * approves or rejects, and on refund restocks resellable items and reverses
 * the stock ledger.
 */
export function Returns() {
  const [returns, setReturns] = useState([])
  const [page, setPage] = useState(1)
  const [total, setTotal] = useState(0)
  const [perPage, setPerPage] = useState(20)
  const [statusFilter, setStatusFilter] = useState('')
  const [openId, setOpenId] = useState(null)
  const [detail, setDetail] = useState(null)
  const [error, setError] = useState(null)
  const [notice, setNotice] = useState(null)

  const load = useCallback(() => {
    api.get(`/returns?page=${page}&per_page=${perPage}${statusFilter ? `&status=${statusFilter}` : ''}`)
      .then(({ data, meta }) => {
        setReturns(data.returns || [])
        setTotal(meta.total || 0)
        setPage(meta.page || 1)
      })
      .catch((e) => setError(e.message))
  }, [page, perPage, statusFilter])

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

  const decide = async (id, status, refundAmount) => {
    try {
      await api.patch(`/returns/${id}`, { status, refund_amount: refundAmount !== '' ? refundAmount : undefined })
      flash(`Return #${id} → ${status}`)
      load()
      loadDetail(id)
    } catch (err) { setError(err.message) }
  }

  const totalPages = Math.max(1, Math.ceil(total / perPage))

  const STATUSES = ['requested', 'approved', 'shipped', 'refunded', 'rejected']

  return (
    <div className="wrap">
      <nav className="breadcrumb" aria-label="Breadcrumb">
        <Link to="/admin">House console</Link>
        <span className="sep">/</span>
        <span aria-current="page">Returns &amp; RMAs</span>
      </nav>

      <div className="admin-head">
        <div>
          <p className="eyebrow">Returns</p>
          <h1 style={{ marginTop: 'var(--space-sm)' }}>Returns &amp; RMAs</h1>
          <p className="t-body-sm muted">
            Customers request returns on delivered orders. Approve to authorise the return; on refund,
            resellable items go back to stock and the ledger is reversed.
          </p>
        </div>
        <div className="right">
          <Link className="eyebrow link-u" to="/admin">Back to console</Link>
        </div>
      </div>

      {error && <p className="alert alert-error" style={{ marginBottom: 'var(--space-md)' }}>{error}</p>}
      {notice && <p className="alert alert-ok" style={{ marginBottom: 'var(--space-md)' }}>{notice}</p>}

      <div className="panel panel--flush">
        <div className="panel-head">
          <h3 style={{ margin: 0, fontSize: 'var(--title-lg)' }}>Return register</h3>
          <div className="toolbar">
            <label className="eyebrow" htmlFor="rs-filter">State</label>
            <select id="rs-filter" value={statusFilter} onChange={(e) => { setStatusFilter(e.target.value); setPage(1) }}>
              <option value="">All states</option>
              {STATUSES.map((s) => <option key={s} value={s}>{s.charAt(0).toUpperCase() + s.slice(1)}</option>)}
            </select>
          </div>
        </div>
        <table className="data-table">
          <thead>
            <tr>
              <th>RMA</th><th className="num">Order</th><th className="num">Customer</th><th>Reason</th><th className="num">Refund</th><th>Status</th><th>Requested</th><th />
            </tr>
          </thead>
          <tbody>
            {returns.map((r) => (
              <tr key={r.id}>
                <td className="tabular">#{String(r.id).padStart(6, '0')}</td>
                <td className="tabular muted">#{String(r.order_id).padStart(6, '0')}</td>
                <td className="tabular muted">{r.user_id}</td>
                <td>{r.reason}</td>
                <td className="num">{money(r.refund_amount)}</td>
                <td><span className={`tag ${r.status === 'approved' ? 'tag--ok' : r.status === 'rejected' ? 'tag' : ''}`}>{r.status}</span></td>
                <td className="tabular muted">{dateLabel(r.created_at)}</td>
                <td className="right"><button className="eyebrow link-u" onClick={() => setOpenId(r.id)}>Open</button></td>
              </tr>
            ))}
            {returns.length === 0 && <tr><td colSpan={8} className="muted">No returns yet.</td></tr>}
          </tbody>
        </table>
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
              <p className="t-body-sm muted">Order #{String(detail.order_id).padStart(6, '0')} · {detail.reason}</p>
              <span className="eyebrow" style={{ marginTop: 'var(--space-md)' }}>Status</span>
              <p className="t-body-sm">{detail.status}</p>
              <span className="eyebrow" style={{ marginTop: 'var(--space-md)' }}>Refund amount</span>
              <p className="t-body-sm">{money(detail.refund_amount)}</p>
            </div>
            <div>
              <span className="eyebrow">Items to return</span>
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

          <div style={{ marginTop: 'var(--space-lg)' }}>
            <span className="eyebrow">Administrative decision</span>
            <div className="row" style={{ gap: 'var(--space-sm)', marginTop: 'var(--space-sm)' }}>
              {detail.status === 'requested' && (
                <>
                  <button className="btn btn-sm" onClick={() => decide(detail.id, 'approved', '')}>Approve</button>
                  <button className="btn btn-sm btn--outline" onClick={() => decide(detail.id, 'rejected', '')}>Reject</button>
                </>
              )}
              {detail.status === 'approved' && (
                <>
                  <input type="number" step="0.01" placeholder="Refund amount" />
                  <button className="btn btn-sm" onClick={() => decide(detail.id, 'refunded', '')}>Mark refunded</button>
                </>
              )}
            </div>
          </div>
        </div>
      )}

      {totalPages > 1 && (
        <div className="pagination" style={{ marginTop: 'var(--space-lg)' }}>
          <button className="page-btn" disabled={page <= 1} onClick={() => setPage(page - 1)}>←</button>
          {Array.from({ length: totalPages }, (_, i) => i + 1).map((n) => (
            <button key={n} className={`page-btn${n === page ? ' is-active' : ''}`} onClick={() => setPage(n)}>{n}</button>
          ))}
          <button className="page-btn" disabled={page >= totalPages} onClick={() => setPage(page + 1)}>→</button>
        </div>
      )}
    </div>
  )
}
