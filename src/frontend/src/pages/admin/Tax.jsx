import { useCallback, useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../../services/api.js'
import { useAuth } from '../../stores/store.js'
import { money } from '../../lib/format.js'

/**
 * Tax console: classes define what is taxed (standard / reduced / zero),
 * rates define the percentage by country (+ optional region / postal).
 * Rates resolve most-specific-first: postal → region → country → default.
 */
export function Tax() {
  const [classes, setClasses] = useState([])
  const [rates, setRates] = useState([])
  const [calc, setCalc] = useState({ country: '', region: '', postal: '', class: 'standard', rate: '' })
  const [calcResult, setCalcResult] = useState(null)
  const [newClass, setNewClass] = useState({ code: '', label: '' })
  const [newRate, setNewRate] = useState({ classId: '', country: '', region: '', postal: '', rate: '', isDefault: false })
  const [error, setError] = useState(null)
  const [notice, setNotice] = useState(null)

  const load = useCallback(() => {
    api.get('/tax').then(({ data }) => {
      setClasses(data.classes || [])
      setRates(data.rates || [])
    }).catch((e) => setError(e.message))
  }, [])

  useEffect(() => { load() }, [load])

  const flash = (text) => { setNotice(text); setError(null) }

  const createClass = async (e) => {
    e.preventDefault()
    if (!newClass.code.trim() || !newClass.label.trim()) return
    try {
      await api.post('/tax?classes=1', { code: newClass.code.trim(), label: newClass.label.trim() })
      setNewClass({ code: '', label: '' })
      load()
      flash('Tax class created')
    } catch (err) { setError(err.message) }
  }

  const createRate = async (e) => {
    e.preventDefault()
    if (!newRate.classId || !newRate.country.trim() || !newRate.rate) return
    try {
      await api.post('/tax?rates=1', {
        class_id: Number(newRate.classId),
        country: newRate.country.trim().toUpperCase(),
        region: newRate.region.trim() || null,
        postal_code: newRate.postal.trim() || null,
        rate: newRate.rate,
        is_default: newRate.isDefault,
      })
      setNewRate({ classId: '', country: '', region: '', postal: '', rate: '', isDefault: false })
      load()
      flash('Tax rate created')
    } catch (err) { setError(err.message) }
  }

  const calculate = async (e) => {
    e.preventDefault()
    try {
      const qs = new URLSearchParams({
        country: calc.country,
        class: calc.class || 'standard',
      })
      if (calc.region) qs.set('region', calc.region)
      if (calc.postal) qs.set('postal_code', calc.postal)
      const { data } = await api.get(`/tax?calculate=1&${qs}`)
      setCalcResult(data)
    } catch (err) { setError(err.message) }
  }

  const delClass = async (id) => {
    try {
      await api.delete(`/tax/classes/${id}`)
      load()
      flash('Tax class deleted')
    } catch (err) { setError(err.message) }
  }

  const delRate = async (id) => {
    try {
      await api.delete(`/tax/rates/${id}`)
      load()
      flash('Tax rate deleted')
    } catch (err) { setError(err.message) }
  }

  return (
    <div className="wrap">
      <nav className="breadcrumb" aria-label="Breadcrumb">
        <Link to="/admin">House console</Link>
        <span className="sep">/</span>
        <span aria-current="page">Tax classes &amp; rates</span>
      </nav>

      <div className="admin-head">
        <div>
          <p className="eyebrow">Tax</p>
          <h1 style={{ marginTop: 'var(--space-sm)' }}>Classes &amp; rates</h1>
          <p className="t-body-sm muted">
            Classes describe what is taxed. Rates carry the percentage by country — matched most-specific-first
            (postal → region → country → class default → store default).
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
            <h3 style={{ margin: 0, fontSize: 'var(--title-lg)' }}>Tax classes</h3>
            <span className="eyebrow">{classes.length} classes</span>
          </div>
          <form onSubmit={createClass} className="field" style={{ marginBottom: 'var(--space-lg)' }}>
            <div className="row" style={{ gap: 'var(--space-sm)' }}>
              <input
                className="input grow"
                placeholder="code (e.g. standard)"
                value={newClass.code}
                onChange={(e) => setNewClass({ ...newClass, code: e.target.value })}
              />
              <input
                className="input grow"
                placeholder="label (e.g. Standard rate)"
                value={newClass.label}
                onChange={(e) => setNewClass({ ...newClass, label: e.target.value })}
              />
              <button className="btn btn-sm" type="submit" disabled={!newClass.code.trim() || !newClass.label.trim()}>Create class</button>
            </div>
          </form>
          <table className="data-table">
            <thead><tr><th>Code</th><th>Label</th><th className="num">Default rate</th><th className="num">Rates</th><th /></tr></thead>
            <tbody>
              {classes.map((c) => {
                const cRates = rates.filter((r) => r.class_id === c.id)
                return (
                  <tr key={c.id}>
                    <td className="tabular">{c.code}</td>
                    <td>{c.label}</td>
                    <td className="num">{c.default_rate !== null ? c.default_rate + '%' : '—'}</td>
                    <td className="num">{cRates.length}</td>
                    <td className="right"><button className="btn btn-sm" onClick={() => delClass(c.id)} disabled={c.is_system}>Delete</button></td>
                  </tr>
                )
              })}
              {classes.length === 0 && <tr><td colSpan={5} className="muted">No tax classes yet.</td></tr>}
            </tbody>
          </table>
        </div>

        <div className="panel">
          <div className="panel-head">
            <h3 style={{ margin: 0, fontSize: 'var(--title-lg)' }}>Tax rates</h3>
            <span className="eyebrow">{rates.length} rates</span>
          </div>
          <form onSubmit={createRate} className="grid" style={{ gridTemplateColumns: 'repeat(5, minmax(0, 1fr))', gap: 'var(--space-sm)', marginBottom: 'var(--space-lg)' }}>
            <div className="field" style={{ marginBottom: 0 }}>
              <label>Class</label>
              <select value={newRate.classId} onChange={(e) => setNewRate({ ...newRate, classId: e.target.value })}>
                <option value="">— class —</option>
                {classes.map((c) => <option key={c.id} value={c.id}>{c.code}</option>)}
              </select>
            </div>
            <div className="field" style={{ marginBottom: 0 }}>
              <label>Country (ISO)</label>
              <input value={newRate.country} onChange={(e) => setNewRate({ ...newRate, country: e.target.value })} placeholder="GB" maxLength="2" />
            </div>
            <div className="field" style={{ marginBottom: 0 }}>
              <label>Region</label>
              <input value={newRate.region} onChange={(e) => setNewRate({ ...newRate, region: e.target.value })} placeholder="England" />
            </div>
            <div className="field" style={{ marginBottom: 0 }}>
              <label>Postal</label>
              <input value={newRate.postal} onChange={(e) => setNewRate({ ...newRate, postal: e.target.value })} placeholder="SW1A" />
            </div>
            <div className="field" style={{ marginBottom: 0 }}>
              <label>Rate %</label>
              <input type="number" step="0.0001" value={newRate.rate} onChange={(e) => setNewRate({ ...newRate, rate: e.target.value })} placeholder="20.0000" />
            </div>
            <div className="field checkline" style={{ marginBottom: 0, alignItems: 'flex-end' }}>
              <input type="checkbox" checked={newRate.isDefault} onChange={(e) => setNewRate({ ...newRate, isDefault: e.target.checked })} />
              <label>Default</label>
            </div>
            <button className="btn btn-sm" type="submit" disabled={!newRate.classId || !newRate.country.trim() || !newRate.rate}>Create rate</button>
          </form>
          <table className="data-table">
            <thead><tr><th>Class</th><th>Country</th><th>Region</th><th className="num">Postal</th><th className="num">Rate</th><th className="muted">Default</th><th /></tr></thead>
            <tbody>
              {rates.map((r) => {
                const c = classes.find((x) => x.id === r.class_id)
                return (
                  <tr key={r.id}>
                    <td className="tabular">{c?.code || '—'}</td>
                    <td className="tabular">{r.country}</td>
                    <td className="muted">{r.region || '—'}</td>
                    <td className="num muted">{r.postal_code || '—'}</td>
                    <td className="num">{r.rate}%</td>
                    <td className="num muted">{r.is_default ? 'default' : '—'}</td>
                    <td className="right"><button className="btn btn-sm" onClick={() => delRate(r.id)}>Delete</button></td>
                  </tr>
                )
              })}
              {rates.length === 0 && <tr><td colSpan={7} className="muted">No tax rates yet.</td></tr>}
            </tbody>
          </table>
        </div>
      </div>

      <div className="panel" style={{ marginTop: 'var(--space-lg)' }}>
        <div className="panel-head">
          <h3 style={{ margin: 0, fontSize: 'var(--title-lg)' }}>Rate calculator</h3>
          <span className="eyebrow">Resolve the effective rate for an address</span>
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
            <label>Postal</label>
            <input value={calc.postal} onChange={(e) => setCalc({ ...calc, postal: e.target.value })} placeholder="SW1A 1AA" />
          </div>
          <div className="field" style={{ marginBottom: 0 }}>
            <label>Class</label>
            <select value={calc.class} onChange={(e) => setCalc({ ...calc, class: e.target.value })}>
              {classes.map((c) => <option key={c.id} value={c.code}>{c.code}</option>)}
            </select>
          </div>
          <button className="btn btn-sm" type="submit">Calculate</button>
        </form>
        {calcResult && (
          <div className="note">
            <span className="eyebrow">Effective rate</span>
            <span className="v" style={{ fontSize: 'var(--headline-sm)', marginLeft: 'var(--space-sm)' }}>{calcResult.rate}%</span>
            <p className="t-body-sm muted" style={{ marginTop: 'var(--space-xs)' }}>
              {calcResult.class} · {calcResult.country}{calcResult.region ? ` · ${calcResult.region}` : ''}{calcResult.postal ? ` · ${calcResult.postal}` : ''}
            </p>
          </div>
        )}
      </div>
    </div>
  )
}
