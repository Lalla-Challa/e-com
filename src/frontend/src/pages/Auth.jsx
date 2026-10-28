import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useAuth } from '../stores/store.js'

const AFFILIATIONS = [
  'Working tailor / alterations studio',
  'Independent garment maker',
  'Costume & repair workshop',
  'Apprentice / design academy member',
  'Private maker & collector',
]

const PRIVILEGES = [
  ['Restock ledger', 'Registered accounts receive restock alerts for thread colourways, lace trims and new blade arrivals.'],
  ['One-click re-order', 'Re-order the exact thread colours, elastic widths and needle sizes from previous consignments.'],
  ['Studio restock desk', 'Direct channel to the counter for roll-run cut lengths and standing thread orders.'],
]

/**
 * Client registry. One route, two dossiers: sign in to the ledger, or establish
 * a new client dossier. Affiliation and studio allowance are presented as the
 * house's trade-registration narrative; only the fields the API accepts
 * (name, email, password) are actually submitted.
 */
export function Auth() {
  const navigate = useNavigate()
  const login = useAuth((s) => s.login)
  const register = useAuth((s) => s.register)
  const [mode, setMode] = useState('login')
  const [form, setForm] = useState({ email: '', password: '', full_name: '' })
  const [affiliation, setAffiliation] = useState(AFFILIATIONS[0])
  const [error, setError] = useState(null)
  const [fields, setFields] = useState({})
  const [busy, setBusy] = useState(false)

  const set = (key) => (e) => setForm((f) => ({ ...f, [key]: e.target.value }))

  const submit = async (e) => {
    e.preventDefault()
    setBusy(true)
    setError(null)
    setFields({})
    try {
      const user = mode === 'login'
        ? await login(form.email, form.password)
        : await register(form.email, form.password, form.full_name)
      navigate(user.role === 'admin' ? '/admin' : '/account')
    } catch (err) {
      setError(err.message)
      setFields(err.fields || {})
    } finally {
      setBusy(false)
    }
  }

  const isLogin = mode === 'login'

  return (
    <div className="wrap">
      <nav className="breadcrumb" aria-label="Breadcrumb">
        <Link to="/">House</Link>
        <span className="sep">/</span>
        <span aria-current="page">{isLogin ? 'Client sign in' : 'Client registry'}</span>
      </nav>

      <div className="grid grid--split" style={{ gridTemplateColumns: '1.1fr 1fr' }}>
        <div>
          <p className="eyebrow">Counter record #007-NL — notions house est. 2026</p>
          <h1 className="t-display-md" style={{ marginTop: 'var(--space-md)' }}>
            “The work begins with the drawer. Colour-matched threads, serviceable steel, and trims cut to the metre.”
          </h1>

          <div className="discipline-grid" style={{ marginTop: 'var(--space-lg)', borderTop: '1px solid var(--hairline)' }}>
            {PRIVILEGES.map(([title, body], i) => (
              <div className="discipline" key={title}>
                <span className="idx">{String(i + 1).padStart(2, '0')}</span>
                <h3>{title}</h3>
                <p>{body}</p>
              </div>
            ))}
          </div>

          <p className="field-help" style={{ marginTop: 'var(--space-lg)' }}>
            Sessions use rotating, http-only cookies. We keep only what an order needs — no tracking beyond
            order provenance and dispatch records.
          </p>
        </div>

        <div className="auth-plate">
          <div className="tabs">
            <button className={`tab${isLogin ? ' is-active' : ''}`} onClick={() => setMode('login')} type="button">
              Sign in
            </button>
            <button className={`tab${!isLogin ? ' is-active' : ''}`} onClick={() => setMode('register')} type="button">
              Register new client
            </button>
          </div>

          <p className="eyebrow">{isLogin ? 'Ledger status: active' : 'Client registry'}</p>
          <h2 style={{ marginTop: 'var(--space-sm)' }}>{isLogin ? 'Access the ledger' : 'Open a client dossier'}</h2>
          <p className="t-body-sm muted">
            {isLogin
              ? 'Sign in to review your order history, re-order past consignments and manage restock alerts.'
              : 'Open a dossier for one-click re-orders, restock alerts on your staple threads, and standing studio accounts.'}
          </p>

          <form onSubmit={submit} style={{ marginTop: 'var(--space-lg)' }}>
            {error && <p className="alert alert-error" style={{ marginBottom: 'var(--space-md)' }}>{error}</p>}

            {!isLogin && (
              <>
                <div className="field">
                  <label htmlFor="auth-name">Full name / principal maker</label>
                  <input id="auth-name" value={form.full_name} onChange={set('full_name')} autoComplete="name" placeholder="Sam Okafor" />
                  <span className="field-help">Name as it should appear on the dispatch manifest.</span>
                  {fields.full_name && <span className="field-error">{fields.full_name}</span>}
                </div>

                <div className="field">
                  <label htmlFor="auth-affiliation">Studio affiliation &amp; practice</label>
                  <select id="auth-affiliation" value={affiliation} onChange={(e) => setAffiliation(e.target.value)}>
                    {AFFILIATIONS.map((option) => <option key={option} value={option}>{option}</option>)}
                  </select>
                  <span className="field-help">Used to tailor restock suggestions on your first dispatch.</span>
                </div>
              </>
            )}

            <div className="field">
              <label htmlFor="auth-email">{isLogin ? 'Email' : 'Studio email'}</label>
              <input
                id="auth-email"
                type="email"
                value={form.email}
                onChange={set('email')}
                autoComplete="email"
                placeholder={isLogin ? 'maker@studio.com' : 'studio@yourworkroom.com'}
              />
              <span className="field-help">
                {isLogin ? 'Registered dossier email.' : 'Dispatch confirmations and restock notices route here.'}
              </span>
              {fields.email && <span className="field-error">{fields.email}</span>}
            </div>

            <div className="field">
              <label htmlFor="auth-pass">{isLogin ? 'Password' : 'Define security key (min. 8 characters)'}</label>
              <input
                id="auth-pass"
                type="password"
                value={form.password}
                onChange={set('password')}
                autoComplete={isLogin ? 'current-password' : 'new-password'}
                placeholder="••••••••••••••••"
              />
              {fields.password && <span className="field-error">{fields.password}</span>}
            </div>

            {!isLogin && (
              <label className="checkline" style={{ marginBottom: 'var(--space-md)' }}>
                <input type="checkbox" defaultChecked readOnly />
                Open a studio account — qualifies working studios for invoice terms and roll-run cut pricing.
              </label>
            )}

            <button className="btn btn--block" disabled={busy}>
              {busy ? 'Verifying…' : isLogin ? 'Sign in to the ledger' : 'Open client dossier'}
            </button>
          </form>

          <p className="t-body-sm muted" style={{ marginTop: 'var(--space-md)' }}>
            {isLogin
              ? 'Need a dossier? Choose “Register new client”.'
              : 'Already registered? Choose “Sign in”.'}
            {' '}
            <Link className="link-u" to="/page/about">About the house</Link>
          </p>
        </div>
      </div>
    </div>
  )
}
