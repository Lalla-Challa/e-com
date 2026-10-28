import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../../services/api.js'
import { useAuth } from '../../stores/store.js'
import { money } from '../../lib/format.js'

/**
 * Customer console: groups (retail / wholesale / vip) with price markups and
 * loyalty points. Customers are assigned to groups; points accrue per order.
 */
export function Customers() {
  const [groups, setGroups] = useState([])
  const [newGroup, setNewGroup] = useState({ code: '', name: '', markup: '0.00' })
  const [error, setError] = useState(null)
  const [notice, setNotice] = useState(null)

  const load = useCallback(() => {
    api.get('/customer-groups').then(({ data }) => setGroups(data.groups || [])).catch((e) => setError(e.message))
  }, [])

  useEffect(() => { load() }, [load])

  const flash = (text) => { setNotice(text); setError(null) }

  const createGroup = async (e) => {
    e.preventDefault()
    if (!newGroup.code.trim() || !newGroup.name.trim()) return
    try {
      await api.post('/customer-groups', {
        code: newGroup.code.trim(),
        name: newGroup.name.trim(),
        price_markup_pct: newGroup.markup || '0.00',
      })
      setNewGroup({ code: '', name: '', markup: '0.00' })
      load()
      flash('Customer group created')
    } catch (err) { setError(err.message) }
  }

  const assignMember = async (groupId, userId) => {
    try {
      await api.post('/customer-groups/members', { user_id: Number(userId), group_id: Number(groupId) })
      flash('Customer assigned to group')
      load()
    } catch (err) { setError(err.message) }
  }

  return (
    <div className="wrap">
      <nav className="breadcrumb" aria-label="Breadcrumb">
        <Link to="/admin">House console</Link>
        <span className="sep">/</span>
        <span aria-current="page">Customer groups &amp; loyalty</span>
      </nav>

      <div className="admin-head">
        <div>
          <p className="eyebrow">Customers</p>
          <h1 style={{ marginTop: 'var(--space-sm)' }}>Groups &amp; loyalty</h1>
          <p className="t-body-sm muted">
            Groups apply a price markup (negative for wholesale, positive for premium tiers). Loyalty points
            accrue per order and can be redeemed at checkout.
          </p>
        </div>
        <div className="right">
          <Link className="eyebrow link-u" to="/admin">Back to console</Link>
        </div>
      </div>

      {error && <p className="alert alert-error" style={{ marginBottom: 'var(--space-md)' }}>{error}</p>}
      {notice && <p className="alert alert-ok" style={{ marginBottom: 'var(--space-md)' }}>{notice}</p>}

      <div className="grid grid--split">
        <div className="panel">
          <div className="panel-head">
            <h3 style={{ margin: 0, fontSize: 'var(--title-lg)' }}>Customer groups</h3>
            <span className="eyebrow">{groups.length} tiers</span>
          </div>
          <form onSubmit={createGroup} className="grid" style={{ gridTemplateColumns: 'repeat(4, minmax(0, 1fr))', gap: 'var(--space-sm)', marginBottom: 'var(--space-lg)' }}>
            <div className="field" style={{ marginBottom: 0 }}>
              <label>Code</label>
              <input value={newGroup.code} onChange={(e) => setNewGroup({ ...newGroup, code: e.target.value.toUpperCase() })} placeholder="WHOLESALE" />
            </div>
            <div className="field" style={{ marginBottom: 0 }}>
              <label>Name</label>
              <input value={newGroup.name} onChange={(e) => setNewGroup({ ...newGroup, name: e.target.value })} placeholder="Wholesale studios" />
            </div>
            <div className="field" style={{ marginBottom: 0 }}>
              <label>Price markup %</label>
              <input type="number" step="0.0001" value={newGroup.markup} onChange={(e) => setNewGroup({ ...newGroup, markup: e.target.value })} placeholder="0.00" />
            </div>
            <button className="btn btn-sm" type="submit" disabled={!newGroup.code.trim() || !newGroup.name.trim()}>Create group</button>
          </form>
          <table className="data-table">
            <thead><tr><th>Code</th><th>Name</th><th className="num">Markup %</th><th className="num">Members</th></tr></thead>
            <tbody>
              {groups.map((g) => (
                <tr key={g.id}>
                  <td className="tabular">{g.code}</td>
                  <td>{g.name}</td>
                  <td className="num">{g.price_markup_pct}%</td>
                  <td className="num">{g.member_count}</td>
                </tr>
              ))}
              {groups.length === 0 && <tr><td colSpan={4} className="muted">No customer groups yet.</td></tr>}
            </tbody>
          </table>
        </div>

        <div className="panel">
          <div className="panel-head">
            <h3 style={{ margin: 0, fontSize: 'var(--title-lg)' }}>Assign customer to group</h3>
            <span className="eyebrow">One or more groups per customer</span>
          </div>
          <p className="t-body-sm muted" style={{ marginBottom: 'var(--space-md)' }}>
            Look up a customer by email in the console, then assign them to a group here. Group membership drives
            tiered pricing across the catalogue.
          </p>
        </div>
      </div>

      <div className="panel" style={{ marginTop: 'var(--space-lg)' }}>
        <div className="panel-head">
          <h3 style={{ margin: 0, fontSize: 'var(--title-lg)' }}>Loyalty</h3>
          <span className="eyebrow">Points accrue on paid orders</span>
        </div>
        <p className="t-body-sm muted">
          Loyalty points are awarded when an order is paid and can be redeemed as a discount at checkout.
          Points expire unless used — set an expiry when awarding manually.
        </p>
        <div className="note">
          <span className="eyebrow">Coming soon</span>
          <p className="t-body-sm muted">Points award on checkout completion is wired at the API level; manual adjustment and redemption UI follows in the next build.</p>
        </div>
      </div>
    </div>
  )
}
