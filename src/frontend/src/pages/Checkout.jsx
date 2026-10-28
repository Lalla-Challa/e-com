import { useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { api } from '../services/api.js'
import { useCart, useAuth } from '../stores/store.js'
import { useQuote } from '../hooks/useQuote.js'
import { QuoteTotals } from '../components/QuoteTotals.jsx'
import { CouponField } from '../components/CouponField.jsx'
import { AddressBook } from '../components/AddressBook.jsx'
import { money } from '../lib/format.js'

const EMPTY = {
  email: '', full_name: '', line1: '', line2: '', city: '',
  region: '', postal_code: '', country: 'US', phone: '',
}

const PACKAGING = [
  {
    code: 'tube',
    name: 'Rolled on a rigid core',
    body: 'Cut elastic, lace, binding and cord travel rolled so they arrive without crease memory. Ideal for long trims.',
  },
  {
    code: 'box',
    name: 'Boxed with moulded insert',
    body: 'Spools, packs and scissors ship boxed with a moulded insert. Compact footprint for smaller notions.',
  },
]

/**
 * Dispatch flow: contact → destination & directives → settlement.
 *
 * The server still decides everything that matters (pricing, stock, order
 * state); this page only collects the address, the carriage method and the
 * coupon, then reviews the quote it is about to persist.
 *
 * Settlement is deliberately invoice-based: no payment provider is connected
 * to this build, and the confirmation says so rather than pretending a card
 * was charged.
 */
export function Checkout() {
  const navigate = useNavigate()
  const { lines, refresh } = useCart()
  const user = useAuth((s) => s.user)
  const [step, setStep] = useState(1)
  const [form, setForm] = useState(EMPTY)
  const [errors, setErrors] = useState({})
  const [order, setOrder] = useState(null)
  const [busy, setBusy] = useState(false)
  const [couponCode, setCouponCode] = useState('')
  const [shippingCode, setShippingCode] = useState('')
  const [packaging, setPackaging] = useState('tube')

  const { quote } = useQuote({
    country: form.country || 'US',
    region: form.region || '',
    couponCode,
    shippingCode,
  })

  const set = (key) => (e) => setForm((f) => ({ ...f, [key]: e.target.value }))

  // Adopt the server's default method so the review step and the order row
  // agree on which carriage was charged.
  useEffect(() => {
    if (quote?.shipping?.code && !shippingCode) setShippingCode(quote.shipping.code)
  }, [quote, shippingCode])

  useEffect(() => {
    if (user?.email) setForm((f) => (f.email ? f : { ...f, email: user.email }))
  }, [user])

  const submit = async () => {
    setBusy(true)
    setErrors({})
    try {
      const { data } = await api.post('/checkout', {
        email: user?.email || form.email || undefined,
        shipping_address: form,
        billing_address: null,
        coupon_code: couponCode || undefined,
        shipping_code: shippingCode || undefined,
        packaging,
      })
      setOrder(data.order)

      // The packaging directive is lodged by the server on the order itself,
      // inside the checkout transaction, so it lands on the record for guests
      // as well as signed-in buyers. (A follow-up POST /orders would 401 for
      // guests — that endpoint is owner-only.)

      await refresh()
    } catch (err) {
      if (err.fields && Object.keys(err.fields).length > 0) {
        setErrors(err.fields)
        setStep(1)
      } else {
        setErrors({ _root: err.message })
      }
    } finally {
      setBusy(false)
    }
  }

  if (order) {
    return (
      <div className="wrap wrap--narrow">
        <p className="eyebrow">Ledger recorded / Ref. TL-{new Date().getFullYear()}-{String(order.id).padStart(4, '0')}</p>
        <h1 style={{ marginTop: 'var(--space-sm)' }}>Dispatch confirmed</h1>
        <p className="t-body-lg muted">
          Your order has been lodged with the Buttonwalay counter. A confirmation has been queued to{' '}
          {order.customer_email || form.email || 'your email address'}.
        </p>

        <div className="panel" style={{ marginTop: 'var(--space-lg)' }}>
          <div className="panel-head">
            <h2 style={{ margin: 0, fontSize: 'var(--title-lg)' }}>Fulfilment cadence</h2>
            <span className="tag tag--ok">Order {order.status}</span>
          </div>
          <div className="ledger">
            {[
              ['01', 'Order lodged & verified', 'Logged in the counter ledger; verification queued for dispatch.', 'Completed'],
              ['02', 'Counter pick & pack', 'Spools, packs and cut lengths picked against the consignment record.', 'In queue'],
              ['03', 'Rolled for dispatch', 'Cut lengths rolled on rigid cores so they arrive without crease memory.', 'Pending'],
              ['04', 'Courier delivery', 'Tracked transit with the dispatch manifest attached.', 'Scheduled'],
            ].map(([idx, title, body, state]) => (
              <div className="ledger-row" key={idx} style={{ gridTemplateColumns: '52px 1fr auto' }}>
                <span className="tabular muted">{idx}</span>
                <span>
                  <span className="tabular" style={{ display: 'block' }}>{title}</span>
                  <span className="t-body-sm muted">{body}</span>
                </span>
                <span className="eyebrow right">{state}</span>
              </div>
            ))}
          </div>
        </div>

        <div className="grid grid--split" style={{ marginTop: 'var(--space-lg)' }}>
          <div className="panel">
            <p className="eyebrow">Dispatch destination</p>
            <p className="tabular" style={{ marginTop: 'var(--space-sm)' }}>{form.full_name}</p>
            <p className="t-body-sm muted">
              {form.line1}{form.line2 ? `, ${form.line2}` : ''}<br />
              {form.city}{form.region ? `, ${form.region}` : ''} {form.postal_code}<br />
              {form.country}{form.phone ? ` · ${form.phone}` : ''}
            </p>
            <p className="eyebrow" style={{ marginTop: 'var(--space-md)' }}>Method of conveyance</p>
            <p className="t-body-sm muted">
              {order.shipping_method_name || quote?.shipping?.name || 'Express dedicated courier dispatch'} — insured, climate-stable, end-to-end custody.
            </p>
          </div>

          <div className="panel">
            <p className="eyebrow">Settlement</p>
            <p className="t-body-sm muted" style={{ marginTop: 'var(--space-sm)' }}>
              No payment provider is connected to this store build: the order is lodged as <strong>{order.status}</strong> and
              settled against the studio trade account on invoice.
            </p>
            <div className="spec-line"><span className="k">Total due</span><span className="tabular">{money(order.total, order.currency)}</span></div>
            {order.coupon && <div className="spec-line"><span className="k">Privilege code</span><span className="tabular">{order.coupon.code} · −{money(order.coupon.discount, order.currency)}</span></div>}
            <div className="spec-line"><span className="k">Terms</span><span className="tabular">14 days from delivery confirmation</span></div>
          </div>
        </div>

        <div className="row" style={{ marginTop: 'var(--space-lg)', gap: 'var(--space-sm)' }}>
          <Link className="btn" to="/account">View order ledger</Link>
          <Link className="btn btn--outline" to="/shop">Continue shopping</Link>
        </div>
      </div>
    )
  }

  if (lines.length === 0) {
    return (
      <div className="wrap">
        <h1>Order dispatch</h1>
        <p className="muted">The bag is empty — no notions have been reserved.</p>
        <Link className="btn" to="/shop">Enter the catalogue</Link>
      </div>
    )
  }

  const quoteMatchesForm = quote && quote.shipping?.code === shippingCode

  return (
    <div className="wrap">
      <nav className="breadcrumb" aria-label="Breadcrumb">
        <Link to="/cart">Your bag</Link>
        <span className="sep">/</span>
        <span aria-current="page">Order dispatch</span>
      </nav>

      <div className="section-head">
        <div>
          <h1>Order dispatch</h1>
          <p className="eyebrow" style={{ marginTop: 'var(--space-sm)' }}>
            Secure checkout / dispatched worldwide
          </p>
        </div>
        <span className="eyebrow">Step {step} of 3</span>
      </div>

      <div className="steps" style={{ marginBottom: 'var(--space-lg)' }}>
        {['Contact details', 'Destination & directives', 'Settlement & review'].map((label, i) => (
          <button
            key={label}
            className={`step${step === i + 1 ? ' is-current' : ''}`}
            onClick={() => (i + 1 < step ? setStep(i + 1) : undefined)}
            type="button"
          >
            {String(i + 1).padStart(2, '0')} · {label}
          </button>
        ))}
      </div>

      {errors._root && <p className="alert alert-error" style={{ marginBottom: 'var(--space-md)' }}>{errors._root}</p>}

      <div className="grid grid--detail">
        <div>
          {step === 1 && (
            <form className="panel" onSubmit={(e) => { e.preventDefault(); setStep(2) }}>
              <div className="panel-head">
                <h2 style={{ margin: 0, fontSize: 'var(--title-lg)' }}>Step 01 — Client dossier</h2>
                <span className="eyebrow">Contact for white-glove clearance</span>
              </div>

              {!user && (
                <>
                  <div className="field">
                    <label htmlFor="f-email">Email for dispatch receipt</label>
                    <input id="f-email" type="email" value={form.email || ''} onChange={set('email')} placeholder="maker@studio.com" />
                    <span className="field-help">Dispatch confirmation and tracking route here.</span>
                    {errors.email && <p className="field-error">{errors.email}</p>}
                  </div>
                  <p className="t-body-sm muted">
                    Have an account? <Link className="link-u" to="/auth">Sign in</Link> to reuse saved addresses and your order ledger.
                  </p>
                </>
              )}

              {user && (
                <>
                  <p className="eyebrow" style={{ marginBottom: 'var(--space-sm)' }}>Saved dispatch directives</p>
                  <AddressBook
                    compact
                    selectedId={null}
                    onSelect={(a) => setForm((f) => ({
                      ...f,
                      full_name: a.full_name,
                      line1: a.line1,
                      line2: a.line2 || '',
                      city: a.city,
                      region: a.region || '',
                      postal_code: a.postal_code,
                      country: a.country,
                      phone: a.phone || '',
                    }))}
                  />
                  <p className="t-body-sm muted">Select one to fill the destination below, or key a new address.</p>
                </>
              )}

              <div className="field">
                <label htmlFor="f-phone">Courier telephony</label>
                <input id="f-phone" value={form.phone || ''} onChange={set('phone')} placeholder="+33 …" />
                <span className="field-help">Direct contact for delivery clearance and signature release.</span>
              </div>

              <button className="btn">Continue to destination</button>
            </form>
          )}

          {step === 2 && (
            <form className="panel" onSubmit={(e) => { e.preventDefault(); setStep(3) }}>
              <div className="panel-head">
                <h2 style={{ margin: 0, fontSize: 'var(--title-lg)' }}>Step 02 — Destination &amp; directives</h2>
                <button type="button" className="eyebrow link-u" onClick={() => setStep(1)}>Change dossier</button>
              </div>

              <div className="grid grid--2">
                {['full_name', 'line1', 'line2', 'city', 'region', 'postal_code'].map((key) => (
                  <div className="field" key={key} style={{ marginBottom: 0 }}>
                    <label htmlFor={`f-${key}`}>{key.replace(/_/g, ' ')}</label>
                    <input id={`f-${key}`} value={form[key]} onChange={set(key)} />
                    {errors[`shipping_address.${key}`] && <p className="field-error">{errors[`shipping_address.${key}`]}</p>}
                  </div>
                ))}
                <div className="field" style={{ marginBottom: 0 }}>
                  <label htmlFor="f-country">Country (ISO-2)</label>
                  <input id="f-country" value={form.country} onChange={set('country')} maxLength={2} />
                  {errors['shipping_address.country'] && <p className="field-error">{errors['shipping_address.country']}</p>}
                </div>
              </div>

              <div style={{ marginTop: 'var(--space-lg)' }}>
                <p className="eyebrow" style={{ marginBottom: 'var(--space-sm)' }}>Packaging directive</p>
                <p className="field-help" style={{ marginBottom: 'var(--space-sm)' }}>
                  Your choice is lodged on the consignment record for every order — guests included — so the house applies it before dispatch.
                </p>
                <div className="ship-list">
                  {PACKAGING.map((option) => (
                    <label key={option.code} className={`ship-option${packaging === option.code ? ' is-selected' : ''}`} style={{ gridTemplateColumns: '20px 1fr' }}>
                      <input
                        type="radio"
                        name="packaging"
                        checked={packaging === option.code}
                        onChange={() => setPackaging(option.code)}
                      />
                      <span>
                        <span className="name">{option.name}</span>
                        <span className="hint" style={{ display: 'block' }}>{option.body}</span>
                      </span>
                    </label>
                  ))}
                </div>
              </div>

              {quote?.shipping?.options?.length > 0 && (
                <div style={{ marginTop: 'var(--space-lg)' }}>
                  <p className="eyebrow" style={{ marginBottom: 'var(--space-sm)' }}>Method of conveyance</p>
                  <div className="ship-list">
                    {quote.shipping.options.map((o) => (
                      <label key={o.code} className={`ship-option${shippingCode === o.code ? ' is-selected' : ''}`}>
                        <input
                          type="radio"
                          name="shipping"
                          value={o.code}
                          checked={shippingCode === o.code}
                          onChange={() => setShippingCode(o.code)}
                        />
                        <span>
                          <span className="name">{o.name}</span>
                          {o.description && <span className="hint" style={{ display: 'block' }}>{o.description}</span>}
                          {o.estimated_days_min && (
                            <span className="hint" style={{ display: 'block' }}>
                              {o.estimated_days_min}–{o.estimated_days_max} business days
                            </span>
                          )}
                        </span>
                        <span className="price">{Number(o.price) === 0 ? 'Waived' : money(o.price, quote.currency)}</span>
                      </label>
                    ))}
                  </div>
                </div>
              )}

              <CouponField
                code={couponCode}
                onChange={setCouponCode}
                subtotal={quote?.subtotal || '0.00'}
              />

              {Object.entries(errors).filter(([k]) => k !== '_root').map(([k, v]) => (
                <p key={k} className="field-error">{k}: {v}</p>
              ))}

              <button className="btn" style={{ marginTop: 'var(--space-lg)' }}>Continue to settlement</button>
            </form>
          )}

          {step === 3 && (
            <div className="panel">
              <div className="panel-head">
                <h2 style={{ margin: 0, fontSize: 'var(--title-lg)' }}>Step 03 — Settlement</h2>
                <span className="eyebrow">Invoice settlement</span>
              </div>

              <div className="note">
                <p className="eyebrow">Studio trade account</p>
                <p style={{ margin: '0.35rem 0 0' }}>
                  Settlement is by invoice, payable within 14 days of delivery confirmation. No card details are collected and no
                  payment provider is connected to this build — the order is lodged immediately and the manifest follows by email.
                </p>
              </div>

              <div className="ledger" style={{ marginTop: 'var(--space-lg)' }}>
                {lines.map((line) => (
                  <div className="ledger-row" key={line.id} style={{ gridTemplateColumns: '1fr 140px 120px' }}>
                    <span>
                      <span className="eyebrow">{line.specs?.maker || line.specs?.origin || 'Notions'}</span>
                      <span className="tabular" style={{ display: 'block' }}>{line.name}</span>
                      <span className="t-body-sm muted">
                        {line.specs?.composition || ''}{line.specs?.unit === 'metre' ? ` • cut ${line.quantity} metres` : ` • ${line.quantity} × ${line.specs?.unit_label || 'unit'}`}
                      </span>
                    </span>
                    <span className="tabular muted right">{money(line.unit_price)}{line.specs?.unit_label ? ` / ${line.specs.unit_label}` : ''}</span>
                    <span className="tabular right">{money(line.line_total, quote?.currency || 'USD')}</span>
                  </div>
                ))}
              </div>

              <div className="row" style={{ marginTop: 'var(--space-lg)', gap: 'var(--space-sm)' }}>
                <button className="btn btn--outline" onClick={() => setStep(2)}>Back</button>
                <button className="btn" onClick={submit} disabled={busy || !quoteMatchesForm}>
                  {busy ? 'Placing your order…' : `Place order — ${money(quote?.total || 0, quote?.currency || 'USD')}`}
                </button>
              </div>
              {!quoteMatchesForm && <p className="field-help">Recalculating carriage for this destination…</p>}
            </div>
          )}
        </div>

        <aside>
          <div className="panel">
            <div className="panel-head">
              <h2 style={{ margin: 0, fontSize: 'var(--title-lg)' }}>Order ledger</h2>
              <span className="eyebrow">{lines.length} line{lines.length === 1 ? '' : 's'} reserved</span>
            </div>
            <QuoteTotals quote={quote} />
            <p className="field-help">
              Duties prepaid worldwide (DDP). Cut lengths guaranteed continuous. Thread card complimentary above $75.
            </p>
          </div>
        </aside>
      </div>
    </div>
  )
}
