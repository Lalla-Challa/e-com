import { money } from '../lib/format.js'

/**
 * The price breakdown shared by the bag, checkout review and confirmation.
 *
 * Renders whatever the server's quote returned, so discount, tax and carriage
 * can never be re-derived on the client and drift from the persisted order.
 */
export function QuoteTotals({ quote, couponError }) {
  if (!quote) {
    return <p className="muted t-body-sm">Calculating the order totals…</p>
  }

  const error = couponError || quote.coupon_error
  const shipping = quote.shipping || {}
  const shippingFree = Number(shipping.amount) === 0

  return (
    <div className="totals">
      <div className="totals-row">
        <span>Notions subtotal</span>
        <span className="v">{money(quote.subtotal, quote.currency)}</span>
      </div>

      {Number(quote.discount) > 0 && (
        <div className="totals-row">
          <span>Studio privilege{quote.coupon ? ` · ${quote.coupon.code}` : ''}</span>
          <span className="v neg">−{money(quote.discount, quote.currency)}</span>
        </div>
      )}

      {quote.tax?.name && (
        <div className="totals-row">
          <span>{quote.tax.name} ({(Number(quote.tax.rate) * 100).toFixed(2)}%)</span>
          <span className="v">{money(quote.tax.amount, quote.currency)}</span>
        </div>
      )}

      <div className="totals-row">
        <span>{shipping.name ? `Carriage — ${shipping.name}` : 'Carriage'}</span>
        <span className="v">{shippingFree ? 'Waived' : money(shipping.amount, quote.currency)}</span>
      </div>

      {shippingFree && (
        <p className="field-help" style={{ marginTop: '0.35rem' }}>
          Complimentary carriage applied{shipping.free_over ? ` on orders above ${money(shipping.free_over, quote.currency)}` : ''} — DDP, duties settled before dispatch.
        </p>
      )}

      {error && <p className="field-error">{error.message}</p>}

      <div className="totals-row totals-row--total">
        <span>Total</span>
        <span className="v">{money(quote.total, quote.currency)}</span>
      </div>
      <p className="field-help">Incl. tax · tax-free for export where applicable</p>
    </div>
  )
}
