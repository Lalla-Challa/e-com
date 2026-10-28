import { useCallback, useEffect, useState } from 'react'
import { api } from '../services/api.js'

const BLANK = {
  label: '', full_name: '', line1: '', line2: '', city: '',
  region: '', postal_code: '', country: 'US', phone: '',
}

const LABELS = {
  label: 'Directive name',
  full_name: 'Recipient name',
  line1: 'Address line 1',
  line2: 'Address line 2',
  city: 'City',
  region: 'Region / state',
  postal_code: 'Postal code',
  phone: 'Telephone',
}

/**
 * Saved-address book.
 *
 * `onSelect` lets checkout reuse it as a picker; without it (the Account page)
 * it is a plain manager. Saving and deleting both re-read from the server
 * rather than patching local state, so the "one default per user" rule the API
 * enforces is always reflected back.
 */
export function AddressBook({ onSelect, selectedId, compact = false }) {
  const [addresses, setAddresses] = useState(null)
  const [form, setForm] = useState(BLANK)
  const [open, setOpen] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)
  const [fieldErrors, setFieldErrors] = useState({})

  const load = useCallback(() => {
    api.get('/addresses')
      .then(({ data }) => setAddresses(data.addresses))
      .catch(() => setAddresses([]))
  }, [])

  useEffect(() => { load() }, [load])

  const set = (key) => (e) => setForm((f) => ({ ...f, [key]: e.target.value }))

  const save = async (e) => {
    e.preventDefault()
    setBusy(true)
    setError(null)
    setFieldErrors({})
    try {
      const payload = {
        ...form,
        label: form.label || null,
        line2: form.line2 || null,
        region: form.region || null,
        phone: form.phone || null,
      }
      await api.post('/addresses', payload)
      setForm(BLANK)
      setOpen(false)
      load()
    } catch (err) {
      if (err.fields && Object.keys(err.fields).length > 0) setFieldErrors(err.fields)
      else setError(err.message)
    } finally {
      setBusy(false)
    }
  }

  const remove = async (id) => {
    await api.del(`/addresses?id=${id}`).catch((e) => setError(e.message))
    load()
  }

  if (addresses === null) return <p className="muted t-body-sm">Loading address directives…</p>

  return (
    <div>
      {addresses.length === 0 && !open && (
        <p className="t-body-sm muted">
          {compact ? 'No saved dispatch addresses yet — enter one below.' : 'No dispatch directives saved yet.'}
        </p>
      )}

      {addresses.length > 0 && (
        <div className="grid" style={{ gridTemplateColumns: 'repeat(auto-fill, minmax(240px, 1fr))', gap: 'var(--space-md)' }}>
          {addresses.map((a) => {
            const selectable = typeof onSelect === 'function'
            const isSelected = selectable && selectedId === a.id
            return (
              <div
                key={a.id}
                className={`address-card${isSelected ? ' is-default' : ''}`}
                onClick={selectable ? () => onSelect(a) : undefined}
                role={selectable ? 'button' : undefined}
                tabIndex={selectable ? 0 : undefined}
                onKeyDown={selectable ? (e) => e.key === 'Enter' && onSelect(a) : undefined}
                style={selectable ? { cursor: 'pointer' } : undefined}
              >
                <div className="row row--between" style={{ marginBottom: 'var(--space-sm)' }}>
                  <span className="eyebrow">{a.label || 'Dispatch directive'}</span>
                  {(a.is_default_shipping || a.is_default_billing) && (
                    <span className="tag tag--saddle">
                      {a.is_default_shipping ? 'default dispatch' : 'default billing'}
                    </span>
                  )}
                </div>
                <p className="tabular" style={{ margin: 0 }}>{a.full_name}</p>
                <p className="t-body-sm muted" style={{ margin: 0 }}>
                  {a.line1}{a.line2 ? `, ${a.line2}` : ''}<br />
                  {a.city}{a.region ? `, ${a.region}` : ''} {a.postal_code}<br />
                  {a.country}{a.phone ? ` · ${a.phone}` : ''}
                </p>
                {!compact && (
                  <button
                    className="link-u t-body-sm"
                    style={{ marginTop: 'var(--space-sm)' }}
                    onClick={(e) => { e.stopPropagation(); remove(a.id) }}
                  >
                    Remove directive
                  </button>
                )}
              </div>
            )
          })}
        </div>
      )}

      {error && <p className="alert alert-error" style={{ marginTop: 'var(--space-md)' }}>{error}</p>}

      {!compact && (
        open ? (
          <form className="panel" onSubmit={save} style={{ marginTop: 'var(--space-md)' }}>
            <div className="panel-head">
              <h3 style={{ margin: 0, fontSize: 'var(--title-lg)' }}>New dispatch directive</h3>
              <span className="eyebrow">Stored against your account</span>
            </div>
            <div className="grid grid--2">
              {['label', 'full_name', 'line1', 'line2', 'city', 'region', 'postal_code', 'phone'].map((key) => (
                <div className="field" key={key} style={{ marginBottom: 0 }}>
                  <label htmlFor={`addr-${key}`}>{LABELS[key]}</label>
                  <input id={`addr-${key}`} value={form[key]} onChange={set(key)} />
                  {fieldErrors[`address.${key}`] && <p className="field-error">{fieldErrors[`address.${key}`]}</p>}
                </div>
              ))}
              <div className="field" style={{ marginBottom: 0 }}>
                <label htmlFor="addr-country">Country (ISO-2)</label>
                <input id="addr-country" value={form.country} onChange={set('country')} maxLength={2} />
                {fieldErrors['address.country'] && <p className="field-error">{fieldErrors['address.country']}</p>}
              </div>
            </div>
            <div className="row" style={{ marginTop: 'var(--space-lg)', gap: 'var(--space-sm)' }}>
              <button className="btn" disabled={busy}>{busy ? 'Saving…' : 'Save directive'}</button>
              <button type="button" className="btn btn--outline" onClick={() => setOpen(false)}>Cancel</button>
            </div>
          </form>
        ) : (
          <button className="btn btn--outline" style={{ marginTop: 'var(--space-md)' }} onClick={() => setOpen(true)}>
            Add a dispatch directive
          </button>
        )
      )}
    </div>
  )
}
