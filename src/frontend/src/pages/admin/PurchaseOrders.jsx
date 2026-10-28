import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../../services/api.js'
import { useAuth } from '../../stores/store.js'
import { money, dateLabel } from '../../lib/format.js'

/**
 * Purchase order console: raise POs against variants, then receive them —
 * receiving increments variant stock and writes a purchase ledger row.
 */
export function PurchaseOrders() {
  const [pos, setPos] = useState([])
  const [page, setPage] = useState(1)
  const [total, setTotal] = useState(0)
  const [perPage, setPerPage] = useState(20)
  const [draft, setDraft] = useState({ supplier: '', reference: '', expected: '', notes: '', items: [] })
  const [error, setError] = useState(null)
  const [notice, setNotice] = useState(null)

  const load = useCallback(() => {
    api.get(`/purchase-orders?page=${page}&per_page=${perPage}`)
      .then(({ data, meta }) => {
        setPos(data.purchase_orders || [])
        setTotal(meta.total || 0)
        setPage(meta.page || 1)
      })
      .catch((e) => setError(e.message))
  }, [page, perPage])

  useEffect(() => { load() }, [load])

  const flash = (text) => { setNotice(text); setError(null) }

  const addItem = () => {
    setDraft((d) => ({ ...d, items: [...d.items, { variantId: '', qty: '', unitCost: '' }] }))
  }

  const removeItem = (idx) => {
    setDraft((d) => ({ ...d, items: d.items.filter((_, i) => i !== idx) }))
  }

  const updateItem = (idx, field, value) => {
    setDraft((d) => ({ ...d, items: d.items.map((it, i) => i === idx ? { ...it, [field]: value } : it) }))
  }

  const create = async (e) => {
    e.preventDefault()
    if (!draft.supplier.trim() || draft.items.length === 0) return
    try {
      await api.post('/purchase-orders', {
        supplier_name: draft.supplier.trim(),
        reference: draft.reference.trim() || null,
        expected_at: draft.expected || null,
        notes: draft.notes.trim() || null,
        items: draft.items.filter((it) => it.variantId && it.qty).map((it) => ({
          variant_id: Number(it.variantId),
          quantity_ordered: Number(it.qty),
          unit_cost: it.unitCost || '0',
        })),
      })
      setDraft({ supplier: '', reference: '', expected: '', notes: '', items: [] })
      load()
      flash('Purchase order raised')
    } catch (err) { setError(err.message) }
  }

  const receive = async (id) => {
    if (!window.confirm('Receive this purchase order? Stock will be incremented and a ledger row written.')) return
    try {
      await api.post(`/purchase-orders/${id}/receive`)
      flash('Order received — stock incremented')
      load()
    } catch (err) { setError(err.message) }
  }

  const totalPages = Math.max(1, Math.ceil(total / perPage))

  return (
    <div className="wrap">
      <nav className="breadcrumb" aria-label="Breadcrumb">
        <Link to="/admin">House console</Link>
        <span className="sep">/</span>
        <span aria-current="page">Purchase orders</span>
      </nav>

      <div className="admin-head">
        <div>
          <p className="eyebrow">Replenishment</p>
          <h1 style={{ marginTop: 'var(--space-sm)' }}>Purchase orders</h1>
          <p className="t-body-sm muted">
            Raise a PO against variants, mark it ordered, then receive — receiving increments variant stock and
            writes a purchase row to the stock ledger.
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
              <th>PO</th><th>Supplier</th><th className="muted">Reference</th><th>Status</th><th className="num">Total cost</th><th>Expected</th><th>Received</th><th />
            </tr>
          </thead>
          <tbody>
            {pos.map((po) => (
              <tr key={po.id}>
                <td className="tabular">#{String(po.id).padStart(6, '0')}</td>
                <td>{po.supplier_name}</td>
                <td className="tabular muted">{po.reference || '—'}</td>
                <td><span className={`tag ${po.status === 'received' ? 'tag--ok' : ''}`}>{po.status}</span></td>
                <td className="num">{money(po.total_cost)}</td>
                <td className="tabular muted">{po.expected_at ? dateLabel(po.expected_at) : '—'}</td>
                <td className="tabular muted">{po.received_at ? dateLabel(po.received_at) : '—'}</td>
                <td className="right">
                  {po.status !== 'received' && (
                    <button className="btn btn-sm" onClick={() => receive(po.id)}>Receive</button>
                  )}
                </td>
              </tr>
            ))}
            {pos.length === 0 && <tr><td colSpan={8} className="muted">No purchase orders yet.</td></tr>}
          </tbody>
        </table>
      </div>

      <div className="panel" style={{ marginTop: 'var(--space-lg)' }}>
        <div className="panel-head">
          <h3 style={{ margin: 0, fontSize: 'var(--title-lg)' }}>Raise purchase order</h3>
          <span className="eyebrow">Variants only — stock arrives per SKU</span>
        </div>
        <form onSubmit={create} className="grid" style={{ gridTemplateColumns: 'repeat(4, minmax(0, 1fr))', gap: 'var(--space-sm)', marginBottom: 'var(--space-lg)' }}>
          <div className="field" style={{ marginBottom: 0 }}>
            <label>Supplier</label>
            <input value={draft.supplier} onChange={(e) => setDraft({ ...draft, supplier: e.target.value })} placeholder="Thread Mill Co." />
          </div>
          <div className="field" style={{ marginBottom: 0 }}>
            <label>Reference</label>
            <input value={draft.reference} onChange={(e) => setDraft({ ...draft, reference: e.target.value })} placeholder="PO-2026-001" />
          </div>
          <div className="field" style={{ marginBottom: 0 }}>
            <label>Expected</label>
            <input type="date" value={draft.expected} onChange={(e) => setDraft({ ...draft, expected: e.target.value })} />
          </div>
          <div className="field" style={{ marginBottom: 0 }}>
            <label>Notes</label>
            <input value={draft.notes} onChange={(e) => setDraft({ ...draft, notes: e.target.value })} placeholder="Urgent — seasonal roll" />
          </div>
        </form>

        <table className="data-table">
          <thead>
            <tr><th>Variant ID</th><th className="num">Qty ordered</th><th className="num">Unit cost</th><th /></tr>
          </thead>
          <tbody>
            {draft.items.map((it, idx) => (
              <tr key={idx}>
                <td>
                  <input type="number" min="1" value={it.variantId} onChange={(e) => updateItem(idx, 'variantId', e.target.value)} placeholder="variant id" />
                </td>
                <td className="num"><input type="number" min="1" value={it.qty} onChange={(e) => updateItem(idx, 'qty', e.target.value)} placeholder="1" style={{ width: 90 }} /></td>
                <td className="num"><input type="number" step="0.01" value={it.unitCost} onChange={(e) => updateItem(idx, 'unitCost', e.target.value)} placeholder="0.00" style={{ width: 90 }} /></td>
                <td className="right"><button className="btn btn-sm" onClick={() => removeItem(idx)}>Remove</button></td>
              </tr>
            ))}
          </tbody>
        </table>
        <button className="btn btn-sm" type="button" onClick={addItem} style={{ marginTop: 'var(--space-sm)' }}>Add line</button>
        <button className="btn btn-sm" type="submit" disabled={!draft.supplier.trim() || draft.items.length === 0}>Raise order</button>
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
