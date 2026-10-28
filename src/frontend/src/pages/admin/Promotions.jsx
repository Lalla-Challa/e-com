import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../../services/api.js'
import { useAuth } from '../../stores/store.js'

const TYPES = [
  ['cart_discount', 'Cart discount'],
  ['bogo', 'Buy one, get one'],
  ['tiered', 'Tiered pricing'],
  ['free_shipping', 'Free shipping'],
]

/**
 * Promotion console: cart-level rules (discounts, BOGO, tiered, free
 * shipping) with conditions and actions stored as JSON. The cart evaluates
 * active rules at checkout time.
 */
export function Promotions() {
  const [rules, setRules] = useState([])
  const [draft, setDraft] = useState({ code: '', type: 'cart_discount', conditions: {}, actions: {}, isActive: true })
  const [error, setError] = useState(null)
  const [notice, setNotice] = useState(null)

  const load = useCallback(() => {
    api.get('/promotions').then(({ data }) => setRules(data.rules || [])).catch((e) => setError(e.message))
  }, [])

  useEffect(() => { load() }, [load])

  const flash = (text) => { setNotice(text); setError(null) }

  const create = async (e) => {
    e.preventDefault()
    if (!draft.code.trim()) return
    try {
      await api.post('/promotions', {
        code: draft.code.trim(),
        type: draft.type,
        conditions: draft.conditions,
        actions: draft.actions,
        is_active: draft.isActive,
      })
      setDraft({ code: '', type: 'cart_discount', conditions: {}, actions: {}, isActive: true })
      load()
      flash('Promotion rule created')
    } catch (err) { setError(err.message) }
  }

  const toggle = async (id, active) => {
    try {
      await api.patch(`/promotions/${id}`, { is_active: active })
      load()
      flash(`Rule ${active ? 'activated' : 'paused'}`)
    } catch (err) { setError(err.message) }
  }

  const del = async (id) => {
    if (!window.confirm('Delete this promotion rule?')) return
    try {
      await api.delete(`/promotions/${id}`)
      load()
      flash('Rule deleted')
    } catch (err) { setError(err.message) }
  }

  return (
    <div className="wrap">
      <nav className="breadcrumb" aria-label="Breadcrumb">
        <Link to="/admin">House console</Link>
        <span className="sep">/</span>
        <span aria-current="page">Promotions</span>
      </nav>

      <div className="admin-head">
        <div>
          <p className="eyebrow">Promotions &amp; affiliates</p>
          <h1 style={{ marginTop: 'var(--space-sm)' }}>Promotion rules</h1>
          <p className="t-body-sm muted">
            Cart-level rules evaluated at checkout: cart discounts, BOGO, tiered pricing and free shipping.
            Conditions and actions are free-form JSON consumed by the cart service.
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
              <th>Code</th><th>Type</th><th className="muted">Conditions</th><th className="muted">Actions</th><th className="muted">Active</th><th className="num">Usage limit</th><th className="num">Per user</th><th />
            </tr>
          </thead>
          <tbody>
            {rules.map((r) => (
              <tr key={r.id}>
                <td className="tabular">{r.code}</td>
                <td className="muted">{r.type}</td>
                <td className="muted tabular">{JSON.stringify(r.conditions || {}).slice(0, 40)}</td>
                <td className="muted tabular">{JSON.stringify(r.actions || {}).slice(0, 40)}</td>
                <td className="num muted">{r.is_active ? 'active' : 'paused'}</td>
                <td className="num">{r.usage_limit ?? '—'}</td>
                <td className="num">{r.per_user_limit ?? '—'}</td>
                <td className="right">
                  <button className="btn btn-sm" onClick={() => toggle(r.id, !r.is_active)}>{r.is_active ? 'Pause' : 'Activate'}</button>
                  <button className="btn btn-sm" style={{ marginLeft: 'var(--space-xs)' }} onClick={() => del(r.id)}>Delete</button>
                </td>
              </tr>
            ))}
            {rules.length === 0 && <tr><td colSpan={8} className="muted">No promotion rules yet.</td></tr>}
          </tbody>
        </table>
      </div>

      <div className="panel" style={{ marginTop: 'var(--space-lg)' }}>
        <div className="panel-head">
          <h3 style={{ margin: 0, fontSize: 'var(--title-lg)' }}>Create rule</h3>
          <span className="eyebrow">Conditions + actions as JSON</span>
        </div>
        <form onSubmit={create} className="grid" style={{ gridTemplateColumns: 'repeat(3, minmax(0, 1fr))', gap: 'var(--space-sm)', marginBottom: 'var(--space-lg)' }}>
          <div className="field" style={{ marginBottom: 0 }}>
            <label>Code</label>
            <input value={draft.code} onChange={(e) => setDraft({ ...draft, code: e.target.value.toUpperCase() })} placeholder="WELCOME10" />
          </div>
          <div className="field" style={{ marginBottom: 0 }}>
            <label>Type</label>
            <select value={draft.type} onChange={(e) => setDraft({ ...draft, type: e.target.value })}>
              {TYPES.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
            </select>
          </div>
          <div className="field checkline" style={{ marginBottom: 0, alignItems: 'flex-end' }}>
            <input type="checkbox" checked={draft.isActive} onChange={(e) => setDraft({ ...draft, isActive: e.target.checked })} />
            <label>Active</label>
          </div>
        </form>
        <div className="field">
          <label>Conditions (JSON)</label>
          <textarea
            rows={4}
            value={JSON.stringify(draft.conditions, null, 2)}
            onChange={(e) => {
              try { setDraft({ ...draft, conditions: JSON.parse(e.target.value) }) } catch { /* keep invalid until fixed */ }
            }}
            placeholder='{ "min_subtotal": 50 }'
          />
          <span className="field-help">Example: {{ "min_subtotal": 50, "categories": ["threads"] }}</span>
        </div>
        <div className="field" style={{ marginBottom: 'var(--space-lg)' }}>
          <label>Actions (JSON)</label>
          <textarea
            rows={4}
            value={JSON.stringify(draft.actions, null, 2)}
            onChange={(e) => {
              try { setDraft({ ...draft, actions: JSON.parse(e.target.value) }) } catch { /* keep invalid until fixed */ }
            }}
            placeholder='{ "discount_percent": 10, "max_discount": 50 }'
          />
          <span className="field-help">Example: {{ "discount_percent": 10, "max_discount": 50 }}</span>
        </div>
        <button className="btn btn-sm" type="submit" disabled={!draft.code.trim()}>Create rule</button>
      </div>

      <div className="panel" style={{ marginTop: 'var(--space-lg)' }}>
        <div className="panel-head">
          <h3 style={{ margin: 0, fontSize: 'var(--title-lg)' }}>Affiliates</h3>
          <span className="eyebrow">Referral commission tracking</span>
        </div>
        <p className="t-body-sm muted">
          Affiliates earn a commission percentage on referred orders. Create an affiliate by assigning
          a customer to the affiliate role and generating a referral code.
        </p>
        <div className="note">
          <span className="eyebrow">Coming soon</span>
          <p className="t-body-sm muted">Affiliate signup, conversion tracking and commission payouts are modelled at the schema level; the referral dashboard follows next.</p>
        </div>
      </div>
    </div>
  )
}
