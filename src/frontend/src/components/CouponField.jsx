import { useState } from 'react'
import { api } from '../services/api.js'
import { money } from '../lib/format.js'

/**
 * Coupon entry. Validation is asked of the server against the live subtotal so
 * the "this code needs a larger order" message matches what checkout will say.
 *
 * Controlled when a `code` prop is supplied (checkout needs it for the quote),
 * self-managed otherwise (the bag).
 */
export function CouponField({ code, onChange, onApplied, subtotal }) {
  const [localCode, setLocalCode] = useState('')
  const [checking, setChecking] = useState(false)
  const [result, setResult] = useState(null)

  const value = code === undefined ? localCode : code

  const update = (next) => {
    if (onChange) onChange(next)
    else setLocalCode(next)
    setResult(null)
  }

  const apply = async () => {
    const trimmed = value.trim()
    if (!trimmed) return
    setChecking(true)
    setResult(null)
    try {
      const { data } = await api.get(`/coupons.php?code=${encodeURIComponent(trimmed)}&subtotal=${encodeURIComponent(subtotal)}`)
      const valid = Boolean(data.valid)
      setResult({
        ok: valid,
        message: valid
          ? `Code ${data.coupon.code} accepted — ${money(data.coupon.discount)} credited at dispatch.`
          : data.error?.message || 'That code is not valid for this ledger.',
      })
      if (valid && onApplied) onApplied(trimmed)
    } catch (err) {
      setResult({ ok: false, message: err.message })
    } finally {
      setChecking(false)
    }
  }

  return (
    <div style={{ marginTop: 'var(--space-md)' }}>
      <span className="field-label">Trade or privilege code</span>
      <div className="row" style={{ gap: 'var(--space-sm)', alignItems: 'stretch' }}>
        <input
          className="input grow"
          aria-label="Coupon code"
          placeholder="e.g. WELCOME10"
          value={value}
          onChange={(e) => update(e.target.value.toUpperCase())}
          onKeyDown={(e) => {
            if (e.key === 'Enter') {
              e.preventDefault()
              apply()
            }
          }}
        />
        <button type="button" className="btn btn--outline btn-sm" onClick={apply} disabled={checking || !value.trim()}>
          {checking ? 'Verifying…' : 'Apply'}
        </button>
      </div>
      {result && (
        <p className={result.ok ? 'field-help' : 'field-error'} style={{ color: result.ok ? 'var(--moss)' : undefined }}>
          {result.message}
        </p>
      )}
    </div>
  )
}
