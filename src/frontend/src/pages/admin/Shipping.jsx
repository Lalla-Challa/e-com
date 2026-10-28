import { useCallback, useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../../services/api.js'
import { useAuth } from '../../stores/store.js'
import { money } from '../../lib/format.js'

/**
 * Shipping console: zones group countries/regions; methods live inside a zone
 * and charge either a flat rate or a per-kilogram surcharge. Free shipping
 * kicks in above a subtotal threshold.
 */
export function Shipping() {
  const [zones, setZones] = useState([])
  const [methods, setMethods] = useState([])
  const [newZone, setNewZone] = useState({ name: '', countries: '', regions: '', isDefault: false })
  const [newMethod, setNewMethod] = useState({ zoneId: '', code: '', name: '', type: 'flat_rate', baseCost: '', costPerKg: '', minOrderFree: '', maxWeight: '', sortOrder: '0', isActive: true })
  const [calc, setCalc] = useState({ country: '', region: '', weight: '', subtotal: '' })
  const [calcResult, setCalcResult] = useState(null)
  const [error, setError] = useState(null)
  const [notice, setNotice] = useState(null)

  const load = useCallback(() => {
    api.get('/shipping').then(({ data }) => {
      setZones(data.zones || [])
      setMethods(data.methods || [])
    }).catch((e) => setError(e.message))
  }, [])

  useEffect(() => { load() }, [load])

  const flash = (text) => { setNotice(text); setError(null) }

  const zoneCountries = (z) => {
    try { return JSON.parse(z.countries || '[]') } catch { return [] }
  }

  const createZone = async (e) => {
    e.preventDefault()
    if (!newZone.name.trim()) return
    try {
      const countries = newZone.countries.split(',').map((c) => c.trim().toUpperCase()).filter(Boolean)
      const regions = newZone.regions.split(',').map((c) => c.trim()).filter(Boolean)
      await api.post('/shipping?zones=1', {
        name: newZone.name.trim(),
        countries,
        regions: regions.length > 0 ? regions : null,
        is_default: newZone.isDefault,
      })
      setNewZone({ name: '', countries: '', regions: '', isDefault: false })
      load()
      flash('Shipping zone created')
    } catch (err) { setError(err.message) }
  }

  const createMethod = async (e) => {
    e.preventDefault()
    if (!newMethod.zoneId || !newMethod.code.trim() || !newMethod.name.trim() || !newMethod.baseCost) return
    try {
      await api.post('/shipping?methods=1', {
        zone_id: Number(newMethod.zoneId),
        code: newMethod.code.trim(),
        name: newMethod.name.trim(),
        type: newMethod.type,
        base_cost: newMethod.baseCost,
        cost_per_kg: newMethod.costPerKg || '0.00',
        min_order_free: newMethod.minOrderFree || '0.00',
        max_weight_kg: newMethod.maxWeight || null,
        sort_order: Number(newMethod.sortOrder || 0),
        is_active: newMethod.isActive,
      })
      setNewMethod({ zoneId: '', code: '', name: '', type: 'flat_rate', baseCost: '', costPerKg: '', minOrderFree: '', maxWeight: '', sortOrder: '0', isActive: true })
      load()
      flash('Shipping method created')
    } catch (err) { setError(err.message) }
  }

  const calculate = async (e) => {
    e.preventDefault()
    try {
      const qs = new URLSearchParams({
        country: calc.country,
        weight_kg: calc.weight || '0',
        subtotal: calc.subtotal || '0',
      })
      if (calc.region) qs.set('region', calc.region)
      const { data } = await api.get(`/shipping?calculate=1&${qs}`)
      setCalcResult(data)
    } catch (err) { setError(err.message) }
  }

  const delZone = async (id) => {
    try {
      await api.delete(`/shipping/zones/${id}`)
      load()
      flash('Zone deleted')
    } catch (err) { setError(err.message) }
  }

  const delMethod = async (id) => {
    try {
      await api.delete(`/shipping/methods/${id}`)
      load()
      flash('Method deleted')
    } catch (err) { setError(err.message) }
  }

  return (
    <div className="wrap">
      <nav className="breadcrumb" aria-label="Breadcrumb">
        <Link to="/admin">House console</Link>
        <span className="sep">/</span>
        <span aria-current="page">Shipping zones &amp; methods</span>
      </nav>

      <div className="admin-head">
        <div>
          <p className="eyebrow">Shipping</p>
          <h1 style={{ marginTop: 'var(--space-sm)' }}>Zones &amp; methods</h1>
          <p className="t-body-sm muted">
            Zones group countries and regions. Methods live inside a zone and charge flat rate, per-kilogram,
            or free shipping above a subtotal threshold.
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
            <h3 style={{ margin: 0, fontSize: 'var(--title-lg)' }}>Shipping zones</h3>
            <span className="eyebrow">{zones.length} zones</span>
          </div>
          <form onSubmit={createZone} className="field" style={{ marginBottom: 'var(--space-lg)' }}>
            <div className="row" style={{ gap: 'var(--space-sm)' }}>
              <input className="input grow" placeholder="Zone name (e.g. United Kingdom)" value={newZone.name} onChange={(e) => setNewZone({ ...newZone, name: e.target.value })} />
              <input className="input grow" placeholder="Countries (GB, FR, DE)" value={newZone.countries} onChange={(e) => setNewZone({ ...newZone, countries: e.target.value })} />
              <input className="input grow" placeholder="Regions (optional)" value={newZone.regions} onChange={(e) => setNewZone({ ...newZone, regions: e.target.value })} />
              <div className="field checkline" style={{ marginBottom: 0, alignItems: 'flex-end' }}>
                <input type="checkbox" checked={newZone.isDefault} onChange={(e) => setNewZone({ ...newZone, isDefault: e.target.checked })} />
                <label>Default zone</label>
              </div>
              <button className="btn btn-sm" type="submit" disabled={!newZone.name.trim()}>Create zone</button>
            </div>
          </form>
          <table className="data-table">
            <thead><tr><th>Name</th><th>Countries</th><th className="muted">Regions</th><th className="num">Methods</th><th className="muted">Default</th><th /></tr></thead>
            <tbody>
              {zones.map((z) => {
                const zMethods = methods.filter((m) => m.zone_id === z.id)
                return (
                  <tr key={z.id}>
                    <td className="tabular">{z.name}</td>
                    <td className="tabular muted">{zoneCountries(z).join(', ') || '*'}</td>
                    <td className="muted">{z.regions ? JSON.parse(z.regions).join(', ') : '—'}</td>
                    <td className="num">{zMethods.length}</td>
                    <td className="num muted">{z.is_default ? 'default' : '—'}</td>
                    <td className="right"><button className="btn btn-sm" onClick={() => delZone(z.id)} disabled={z.is_default}>Delete</button></td>
                  </tr>
                )
              })}
              {zones.length === 0 && <tr><td colSpan={6} className="muted">No shipping zones yet.</td></tr>}
            </tbody>
          </table>
        </div>

        <div className="panel">
          <div className="panel-head">
            <h3 style={{ margin: 0, fontSize: 'var(--title-lg)' }}>Shipping methods</h3>
            <span className="eyebrow">{methods.length} methods</span>
          </div>
          <form onSubmit={createMethod} className="grid" style={{ gridTemplateColumns: 'repeat(5, minmax(0, 1fr))', gap: 'var(--space-sm)', marginBottom: 'var(--space-lg)' }}>
            <div className="field" style={{ marginBottom: 0 }}>
              <label>Zone</label>
              <select value={newMethod.zoneId} onChange={(e) => setNewMethod({ ...newMethod, zoneId: e.target.value })}>
                <option value="">— zone —</option>
                {zones.map((z) => <option key={z.id} value={z.id}>{z.name}</option>)}
              </select>
            </div>
            <div className="field" style={{ marginBottom: 0 }}>
              <label>Code</label>
              <input value={newMethod.code} onChange={(e) => setNewMethod({ ...newMethod, code: e.target.value })} placeholder="standard" />
            </div>
            <div className="field" style={{ marginBottom: 0 }}>
              <label>Name</label>
              <input value={newMethod.name} onChange={(e) => setNewMethod({ ...newMethod, name: e.target.value })} placeholder="Standard Delivery" />
            </div>
            <div className="field" style={{ marginBottom: 0 }}>
              <label>Type</label>
              <select value={newMethod.type} onChange={(e) => setNewMethod({ ...newMethod, type: e.target.value })}>
                <option value="flat_rate">Flat rate</option>
                <option value="weight_based">Weight-based</option>
                <option value="free">Free shipping</option>
              </select>
            </div>
            <div className="field" style={{ marginBottom: 0 }}>
              <label>Base cost</label>
              <input type="number" step="0.01" value={newMethod.baseCost} onChange={(e) => setNewMethod({ ...newMethod, baseCost: e.target.value })} placeholder="0.00" />
            </div>
            <button className="btn btn-sm" type="submit" disabled={!newMethod.zoneId || !newMethod.code.trim() || !newMethod.name.trim() || !newMethod.baseCost}>Create method</button>
          </form>
          <table className="data-table">
            <thead><tr><th>Zone</th><th>Code</th><th>Name</th><th>Type</th><th className="num">Base cost</th><th className="num">Per kg</th><th className="num">Min free</th><th className="num">Max kg</th><th className="muted">Active</th><th /></tr></thead>
            <tbody>
              {methods.map((m) => {
                const z = zones.find((x) => x.id === m.zone_id)
                return (
                  <tr key={m.id}>
                    <td className="tabular">{z?.name || '—'}</td>
                    <td className="tabular">{m.code}</td>
                    <td>{m.name}</td>
                    <td className="muted">{m.type}</td>
                    <td className="num">{money(m.base_cost)}</td>
                    <td className="num">{money(m.cost_per_kg)}</td>
                    <td className="num">{money(m.min_order_free)}</td>
                    <td className="num">{m.max_weight_kg !== null ? m.max_weight_kg : '—'}</td>
                    <td className="num muted">{m.is_active ? 'yes' : 'no'}</td>
                    <td className="right"><button className="btn btn-sm" onClick={() => delMethod(m.id)}>Delete</button></td>
                  </tr>
                )
              })}
              {methods.length === 0 && <tr><td colSpan={10} className="muted">No shipping methods yet.</td></tr>}
            </tbody>
          </table>
        </div>
      </div>

      <div className="panel" style={{ marginTop: 'var(--space-lg)' }}>
        <div className="panel-head">
          <h3 style={{ margin: 0, fontSize: 'var(--title-lg)' }}>Shipping calculator</h3>
          <span className="eyebrow">Quote a method for a destination</span>
        </div>
        <form onSubmit={calculate} className="grid" style={{ gridTemplateColumns: 'repeat(4, minmax(0, 1fr)) auto', gap: 'var(--space-sm)' }}>
          <div className="field" style={{ marginBottom: 0 }}>
            <label>Country (ISO)</label>
            <input value={calc.country} onChange={(e) => setCalc({ ...calc, country: e.target.value.toUpperCase() })} placeholder="GB" maxLength="2" />
          </div>
          <div className="field" style={{ marginBottom: 0 }}>
            <label>Region</label>
            <input value={calc.region} onChange={(e) => setCalc({ ...calc, region: e.target.value })} placeholder="England" />
          </div>
          <div className="field" style={{ marginBottom: 0 }}>
            <label>Weight (kg)</label>
            <input type="number" step="0.1" value={calc.weight} onChange={(e) => setCalc({ ...calc, weight: e.target.value })} placeholder="1.0" />
          </div>
          <div className="field" style={{ marginBottom: 0 }}>
            <label>Subtotal</label>
            <input type="number" step="0.01" value={calc.subtotal} onChange={(e) => setCalc({ ...calc, subtotal: e.target.value })} placeholder="0.00" />
          </div>
          <button className="btn btn-sm" type="submit">Calculate</button>
        </form>
        {calcResult && calcResult.methods.length > 0 && (
          <div className="panel panel--flush" style={{ marginTop: 'var(--space-md)' }}>
            <table className="data-table">
              <thead><tr><th>Zone</th><th>Method</th><th className="num">Cost</th></tr></thead>
              <tbody>
                {calcResult.methods.map((m) => (
                  <tr key={m.id}>
                    <td className="tabular">{calcResult.zone?.name || '—'}</td>
                    <td>{m.name} ({m.type})</td>
                    <td className="num">{m.cost}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
        {calcResult && calcResult.methods.length === 0 && (
          <p className="t-body-sm muted">No applicable shipping methods for this destination.</p>
        )}
      </div>
    </div>
  )
}
