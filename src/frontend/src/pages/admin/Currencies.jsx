import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../../services/api.js'
import { useAuth } from '../../stores/store.js'
import { money } from '../../lib/format.js'

export function Currencies() {
  const [currencies, setCurrencies] = useState([])
  const [draft, setDraft] = useState({ code: '', symbol: '$', rate: '1.00', isDefault: false, isActive: true })
  const [editId, setEditId] = useState(null)
  const [editDraft, setEditDraft] = useState({})
  const [error, setError] = useState(null)
  const [notice, setNotice] = useState(null)

  const load = useCallback(() => {
    api.get('/currencies').then(({ data }) => setCurrencies(data.currencies || [])).catch((e) => setError(e.message))
  }, [])

  useEffect(() => { load() }, [load])

  const flash = (text) => { setNotice(text); setError(null) }

  const create = async (e) => {
    e.preventDefault()
    if (!draft.code.trim()) return
    try {
      await api.post('/currencies', {
        code: draft.code.trim().toUpperCase(),
        symbol: draft.symbol || '$',
        rate_vs_default: draft.rate,
        is_default: draft.isDefault,
        is_active: draft.isActive,
      })
      setDraft({ code: '', symbol: '$', rate: '1.00', isDefault: false, isActive: true })
      load()
      flash('Currency created')
    } catch (err) { setError(err.message) }
  }

  const update = async (id) => {
    const d = editDraft[id] || {}
    const body = {}
    if (d.rate !== undefined) body.rate_vs_default = d.rate
    if (d.isDefault !== undefined) body.is_default = d.isDefault
    if (d.isActive !== undefined) body.is_active = d.isActive
    if (Object.keys(body).length === 0) return
    try {
      await api.patch(`/currencies/${id}`, body)
      setEditDraft((prev) => ({ ...prev, [id]: {} }))
      load()
      flash('Currency updated')
    } catch (err) { setError(err.message) }
  }

  const deactivate = async (id) => {
    if (!window.confirm('Deactivate this currency? Orders already issued in it remain unchanged.')) return
    try {
      await api.delete(`/currencies/${id}`)
      load()
      flash('Currency deactivated')
    } catch (err) { setError(err.message) }
  }

  const isDefault = (c) => c.is_default

  return (
    <div className="wrap">
      <nav className="breadcrumb" aria-label="Breadcrumb">
        <Link to="/admin">House console</Link>
        <span className="sep">›</span>
        <span aria-current="page">Currencies</span>
      </nav>
      <div className="admin-head">
        <div>
          <p className="eyebrow">Multi-currency</p>
          <h1 style={{ marginTop: 'var(--space-sm)' }}>Currencies</h1>
        </div>
        <div className="right">
          <Link className="eyebrow link-u" to="/admin">Back to console</Link>
        </div>
      </div>
      {error && <p className="alert alert-error" style={{ marginBottom: 'var(--space-md)' }}>{error}</p>}
      {notice && <p className="alert alert-ok" style={{ marginBottom: 'var(--space-md)' }}>{notice}</p>}

      <div className="panel">
        <div className="panel-head">
          <h3 style={{ margin: 0, fontSize: 'var(--title-lg)' }}>Add currency</h3>
          <span className="eyebrow">ISO code, symbol, rate vs default</span>
        </div>
        <form onSubmit={create} className="grid" style={{ gridTemplateColumns: 'repeat(5, minmax(0, 1fr))', gap: 'var(--space-sm)' }}>
          <div className="field" style={{ marginBottom: 0 }}>
            <label>Code</label>
            <input value={draft.code} onChange={(e) => setDraft({ ...draft, code: e.target.value.toUpperCase() })} placeholder="EUR" maxLength="3" />
          </div>
          <div className="field" style={{ marginBottom: 0 }}>
            <label>Symbol</label>
            <input value={draft.symbol} onChange={(e) => setDraft({ ...draft, symbol: e.target.value })} placeholder="€" />
          </div>
          <div className="field" style={{ marginBottom: 0 }}>
            <label>Rate vs default</label>
            <input type="number" step="0.000001" value={draft.rate} onChange={(e) => setDraft({ ...draft, rate: e.target.value })} placeholder="1.00" />
          </div>
          <div className="field checkline" style={{ marginBottom: 0, alignItems: 'flex-end' }}>
            <input type="checkbox" checked={draft.isDefault} onChange={(e) => setDraft({ ...draft, isDefault: e.target.checked })} />
            <label>Default</label>
          </div>
          <div className="field checkline" style={{ marginBottom: 0, alignItems: 'flex-end' }}>
            <input type="checkbox" checked={draft.isActive} onChange={(e) => setDraft({ ...draft, isActive: e.target.checked })} />
            <label>Active</label>
          </div>
          <button className="btn btn-sm" type="submit" disabled={!draft.code.trim()}>Add currency</button>
        </form>
      </div>

      <div className="panel">
        <div className="panel-head">
          <h3 style={{ margin: 0, fontSize: 'var(--title-lg)' }}>Currency register</h3>
          <span className="eyebrow">{currencies.length} codes</span>
        </div>
        <table className="data-table">
          <thead>
            <tr>
              <th>Code</th><th>Symbol</th><th className="num">Rate vs default</th><th className="muted">Default</th><th className="muted">Active</th>
            </tr>
          </thead>
          <tbody>
            {currencies.map((c) => (
              <tr key={c.id}>
                <td className="tabular">{c.code}</td>
                <td className="tabular">{c.symbol}</td>
                <td className="num">{editDraft[c.id]?.rate !== undefined ? <input type="number" step="0.000001" value={editDraft[c.id].rate} onChange={(e) => setEditDraft((s) => ({ ...s, [c.id]: { ...s[c.id], rate: e.target.value } }))} style={{ width: 100 }} /> : c.rate_vs_default}</td>
                <td className="num muted">{editDraft[c.id]?.isDefault !== undefined ? <input type="checkbox" checked={editDraft[c.id].isDefault} onChange={(e) => setEditDraft((s) => ({ ...s, [c.id]: { ...s[c.id], isDefault: e.target.checked } }))} /> : (c.is_default ? 'default' : '—')}</td>
                <td className="num muted">{editDraft[c.id]?.isActive !== undefined ? <input type="checkbox" checked={editDraft[c.id].isActive} onChange={(e) => setEditDraft((s) => ({ ...s, [c.id]: { ...s[c.id], isActive: e.target.checked } }))} /> : (c.is_active ? 'active' : 'inactive')}</td>
                <td className="right">
                  {editDraft[c.id] ? (
                    <>
                      <button className="btn btn-sm" onClick={() => update(c.id)}>Save</button>
                      <button className="btn btn-sm btn--outline" onClick={() => setEditDraft((s) => ({ ...s, [c.id]: {} }))}>Cancel</button>
                    </>
                  ) : (
                    <>
                      <button className="btn btn-sm" onClick={() => setEditId(c.id)}>Edit</button>
                      {!isDefault(c) && <button className="btn btn-sm" style={{ marginLeft: 'var(--space-xs)' }} onClick={() => deactivate(c.id)}>Deactivate</button>}
                    </>
                  )}
                </td>
              </tr>
            ))}
            {currencies.length === 0 && <tr><td colSpan={7} className="muted">No currencies defined yet.</td></tr>}
          </tbody>
        </table>
      </div>
    </div>
  )
}
