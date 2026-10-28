import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { useCart } from '../stores/store.js'
import { useQuote } from '../hooks/useQuote.js'
import { QuoteTotals } from '../components/QuoteTotals.jsx'
import { CouponField } from '../components/CouponField.jsx'
import { money } from '../lib/format.js'

/**
 * The bag renders the server's cart lines (unit price snapshotted at add-time,
 * flagging anything whose price has since moved) and the server's quote for
 * the totals. Nothing here recomputes money.
 */
export function Cart() {
  const { lines, subtotal, itemCount, loading, refresh, setQuantity, clear } = useCart()
  const [couponCode, setCouponCode] = useState('')
  const { quote, refresh: refreshQuote } = useQuote({ couponCode })

  useEffect(() => { refresh() }, [refresh])

  const metres = lines.reduce((total, line) => total + (line.specs?.unit === 'metre' ? line.quantity : 0), 0)

  const changeQuantity = async (itemId, quantity) => {
    await setQuantity(itemId, quantity)
    refreshQuote()
  }

  const emptyBag = async () => {
    await clear()
    refreshQuote()
  }

  if (loading && lines.length === 0) {
    return <div className="wrap"><p className="muted">Opening the bag…</p></div>
  }

  return (
    <div className="wrap">
      <nav className="breadcrumb" aria-label="Breadcrumb">
        <Link to="/">House</Link>
        <span className="sep">/</span>
        <Link to="/shop">Order dispatch</Link>
        <span className="sep">/</span>
        <span aria-current="page">Your bag</span>
      </nav>

      <div className="section-head">
        <div>
          <h1>Your Bag</h1>
          <p className="eyebrow" style={{ marginTop: 'var(--space-sm)' }}>
            {itemCount} item{itemCount === 1 ? '' : 's'}
            {metres > 0 ? ` • ${metres} metre${metres === 1 ? '' : 's'} of cut length` : ''}
          </p>
        </div>
        {lines.length > 0 && (
          <button className="eyebrow link-u" onClick={emptyBag}>Clear bag</button>
        )}
      </div>

      {lines.length === 0 ? (
        <div className="empty">
          <p style={{ marginBottom: 'var(--space-md)' }}>The bag is empty. No notions have been reserved.</p>
          <Link className="btn" to="/shop">Enter the catalogue</Link>
        </div>
      ) : (
        <div className="grid grid--detail">
          <section>
            <div className="ledger">
              {lines.map((line) => (
                <div className="bag-line" key={line.id}>
                  <div className="bag-line__media">
                    {line.image_url && <img src={line.image_url} alt={line.name} />}
                  </div>

                  <div className="bag-line__info">
                    <h3 className="bag-line__name"><Link to={`/product/${line.slug}`}>{line.name}</Link></h3>
                    <p className="bag-line__meta">
                      {line.specs?.composition || 'Sewing notion'}
                      {line.specs?.unit_label ? ` • unit rate ${money(line.unit_price)} / ${line.specs.unit_label}` : ''}
                    </p>
                    {line.price_changed && (
                      <span className="tag tag--error" style={{ marginTop: '0.4rem' }}>
                        Price updated at the counter — the bag holds {money(line.unit_price)}
                      </span>
                    )}
                    {!line.is_active && <span className="tag tag--error" style={{ marginTop: '0.4rem' }}>Archived reference</span>}
                  </div>

                  <div className="bag-line__actions">
                    <span className="eyebrow">{line.specs?.unit === 'metre' ? 'Cut length' : 'Quantity'}</span>
                    <div className="qty">
                      <button onClick={() => changeQuantity(line.id, Math.max(0, line.quantity - (line.specs?.unit === 'metre' ? 0.5 : 1)))} aria-label="Reduce">–</button>
                      <input
                        aria-label={`Quantity for ${line.name}`}
                        type="number"
                        min={line.specs?.unit === 'metre' ? 0.5 : 1}
                        step={line.specs?.unit === 'metre' ? 0.5 : 1}
                        max={line.stock || 999}
                        value={line.quantity}
                        onChange={(e) => changeQuantity(line.id, Number(e.target.value))}
                      />
                      <button onClick={() => changeQuantity(line.id, line.quantity + (line.specs?.unit === 'metre' ? 0.5 : 1))} aria-label="Add">+</button>
                    </div>
                  </div>

                  <div className="bag-line__price">
                    <span className="eyebrow">Line ledger</span>
                    <p className="tabular" style={{ margin: '0.35rem 0 0', fontFamily: 'var(--serif)', fontSize: 'var(--title-lg)' }}>
                      {money(line.line_total, quote?.currency || 'USD')}
                    </p>
                  </div>

                  <button className="bag-remove" onClick={() => changeQuantity(line.id, 0)} aria-label={`Remove ${line.name}`}>✕</button>
                </div>
              ))}
            </div>

            <div className="note" style={{ marginTop: 'var(--space-lg)' }}>
              <p className="eyebrow">Cutting room directive</p>
              <p style={{ margin: '0.35rem 0 0' }}>
                Cut elastic, lace, binding and cord are measured from a single roll before dispatch.
                Lengths travel rolled on rigid cores so they arrive without crease memory.
              </p>
            </div>
          </section>

          <aside>
            <div className="panel">
              <div className="panel-head">
                <h2 style={{ margin: 0, fontSize: 'var(--title-lg)' }}>Order ledger</h2>
                <span className="eyebrow">Server-priced</span>
              </div>

              <QuoteTotals quote={quote} />
              <CouponField code={couponCode} onChange={setCouponCode} subtotal={subtotal || '0.00'} />

              <Link className="btn btn--block" to="/checkout" style={{ marginTop: 'var(--space-lg)' }}>
                Proceed to dispatch
              </Link>
              <p className="field-help" style={{ textAlign: 'center' }}>
                Complimentary thread card on orders above $75. Cut lengths guaranteed continuous. Duties prepaid worldwide.
              </p>
            </div>

            <div className="panel" style={{ marginTop: 'var(--space-md)' }}>
              <p className="eyebrow">Bag subtotal</p>
              <p className="tabular" style={{ fontFamily: 'var(--serif)', fontSize: 'var(--headline-sm)', margin: '0.35rem 0 0' }}>
                {money(subtotal, quote?.currency || 'USD')}
              </p>
              <p className="t-body-sm muted" style={{ marginTop: 'var(--space-sm)' }}>
                Tax and carriage are computed by the house before dispatch is committed.
              </p>
            </div>
          </aside>
        </div>
      )}
    </div>
  )
}
