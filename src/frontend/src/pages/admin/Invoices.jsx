import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../../services/api.js'
import { useAuth } from '../../stores/store.js'
import { money, dateLabel } from '../../lib/format.js'

/**
 * Invoice console: generate printable HTML invoices for orders, list issued
 * invoices, and mark them paid or voided. Invoices open in a new tab so they
 * can be printed to PDF from the browser.
 */
export function Invoices() {
  const [invoices, setInvoices] = useState([])
  const [page, setPage] = useState(1)
  const [total, setTotal] = useState(0)
  const [perPage, setPerPage] = useState(20)
  const [error, setError] = useState(null)
  const [notice, setNotice] = useState(null)

  const load = useCallback(() => {
    api.get(`/invoices?page=${page}&per_page=${perPage}`)
      .then(({ data, meta }) => {
        setInvoices(data.invoices || [])
        setTotal(meta.total || 0)
        setPage(meta.page || 1)
        setPerPage(meta.per_page || perPage)
      })
      .catch((e) => setError(e.message))
  }, [page, perPage])

  useEffect(() => { load() }, [load])

  const flash = (text) => { setNotice(text); setError(null) }

  const generate = async (orderId) => {
    try {
      const { data } = await api.post(`/invoices?order_id=${orderId}`)
      flash(`Invoice ${data.invoice_number} issued`)
      load()
      window.open(`/api/invoices?id=${data.id}&download=1`, '_blank')
    } catch (err) { setError(err.message) }
  }

  const updateStatus = async (id, status) => {
    try {
      await api.patch(`/invoices/${id}`, { status })
      flash(`Invoice #${id} → ${status}`)
      load()
    } catch (err) { setError(err.message) }
  }

  const download = (id) => {
    window.open(`/api/invoices?id=${id}&download=1`, '_blank')
  }

  const totalPages = Math.max(1, Math.ceil(total / perPage))

  return (
    <div className="wrap">
      <nav className="breadcrumb" aria-label="Breadcrumb">
        <Link to="/admin">House console</Link>
        <span className="sep">/</span>
        <span aria-current="page">Invoices</span>
      </nav>

      <div className="admin-head">
        <div>
          <p className="eyebrow">Invoicing</p>
          <h1 style={{ marginTop: 'var(--space-sm)' }}>Invoices</h1>
          <p className="t-body-sm muted">
            Invoices are generated from orders and rendered as printable HTML — open one and print to PDF.
            Each order produces at most one invoice (idempotent).
          </p>
        </div>
        <div className="right">
          <Link className="eyebrow link-u" to="/admin">Back to console</Link>
        </div>
      </div>

      {error && <p className="alert alert-error" style={{ marginBottom: 'var(--space-md)' }}>{error}</p>}
      {notice && <p className="alert alert-ok" style={{ marginBottom: 'var(--space-md)' }}>{notice}</p>}

      <div className="panel panel--flush">
        <table className="data-table">
          <thead>
            <tr>
              <th>Invoice</th><th className="num">Order</th><th>Status</th><th className="num">Subtotal</th><th className="num">Tax</th><th className="num">Total</th><th>Currency</th><th>Issued</th><th className="num">Paid</th><th />
            </tr>
          </thead>
          <tbody>
            {invoices.map((inv) => (
              <tr key={inv.id}>
                <td className="tabular">
                  <Link className="link-u" to={`/admin/invoices?id=${inv.id}`}>{inv.invoice_number}</Link>
                </td>
                <td className="tabular muted">#{String(inv.order_id).padStart(6, '0')}</td>
                <td><span className={`tag ${inv.status === 'paid' ? 'tag--ok' : inv.status === 'voided' ? 'tag' : ''}`}>{inv.status}</span></td>
                <td className="num">{money(inv.subtotal, inv.currency_code)}</td>
                <td className="num">{money(inv.tax, inv.currency_code)}</td>
                <td className="num">{money(inv.total, inv.currency_code)}</td>
                <td className="tabular muted">{inv.currency_code}</td>
                <td className="tabular muted">{inv.issued_at ? dateLabel(inv.issued_at) : '—'}</td>
                <td className="num muted">{inv.paid_at ? dateLabel(inv.paid_at) : '—'}</td>
                <td className="right">
                  <div className="row" style={{ gap: 'var(--space-xs)' }}>
                    <button className="btn btn-sm btn--outline" onClick={() => download(inv.id)}>Print</button>
                    {inv.status !== 'paid' && (
                      <>
                        <button className="btn btn-sm" onClick={() => updateStatus(inv.id, 'paid')} disabled={inv.status === 'voided'}>Mark paid</button>
                        <button className="btn btn-sm btn--outline" onClick={() => updateStatus(inv.id, 'voided')} disabled={inv.status === 'paid'}>Void</button>
                      </>
                    )}
                  </div>
                </td>
              </tr>
            ))}
            {invoices.length === 0 && <tr><td colSpan={10} className="muted">No invoices issued yet.</td></tr>}
          </tbody>
        </table>
      </div>

      <div className="grid grid--split" style={{ marginTop: 'var(--space-lg)' }}>
        <div className="panel">
          <div className="panel-head">
            <h3 style={{ margin: 0, fontSize: 'var(--title-lg)' }}>Generate invoice</h3>
            <span className="eyebrow">From an existing order</span>
          </div>
          <p className="t-body-sm muted">
            Enter an order id to issue an invoice. If one already exists for that order, it is returned instead.
          </p>
          <div className="field" style={{ marginBottom: 'var(--space-md)' }}>
            <label htmlFor="gen-order-id">Order ID</label>
            <input id="gen-order-id" type="number" min="1" />
          </div>
          <p className="field-help">Orders must exist before an invoice can be generated. Open an order in the console first to see its id.</p>
        </div>
      </div>

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
