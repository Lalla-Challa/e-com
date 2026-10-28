/**
 * Small display helpers shared by the storefront.
 *
 * Every number here was computed by the server (the API returns decimal
 * strings); these functions only format for display — they never re-derive a
 * price, discount or total.
 */

export function money(amount, currency = 'USD') {
  if (amount === null || amount === undefined || amount === '') return '—'
  const value = Number(amount)
  if (Number.isNaN(value)) return String(amount)
  const symbol = currency === 'USD' ? '$' : currency === 'EUR' ? '€' : currency === 'JPY' ? '¥' : ''
  return `${symbol}${value.toFixed(2)}`
}

/** Price per unit, e.g. "$38.00 / m". */
export function unitPrice(amount, unitLabel, currency = 'USD') {
  const base = money(amount, currency)
  return unitLabel ? `${base} / ${unitLabel}` : base
}

export function dateLabel(value, { withYear = true } = {}) {
  if (!value) return '—'
  const date = new Date(String(value).replace(' ', 'T') + (String(value).includes('Z') ? '' : 'Z'))
  if (Number.isNaN(date.getTime())) return String(value)
  return date.toLocaleDateString('en-GB', {
    day: '2-digit',
    month: 'short',
    ...(withYear ? { year: 'numeric' } : {}),
  })
}

export function stars(rating) {
  const filled = Math.max(0, Math.min(5, Math.round(Number(rating) || 0)))
  return '★'.repeat(filled) + '☆'.repeat(5 - filled)
}

/** "Tex 27 / 40wt • 200 m" — the catalogue footnote under a name. */
export function fabricFacts(specs) {
  if (!specs) return ''
  const parts = []
  if (specs.gauge) parts.push(specs.gauge)
  else if (specs.weight_label) parts.push(specs.weight_label)
  else if (specs.weight_gsm) parts.push(`${specs.weight_gsm} gsm`)
  if (specs.length_label) parts.push(specs.length_label)
  else if (specs.width_cm) parts.push(`${specs.width_cm} cm width`)
  return parts.join(' • ')
}

export function specRows(specs) {
  if (!specs) return []
  const map = [
    ['Reference', specs.ref],
    ['Composition', specs.composition],
    ['Fibre', specs.fiber],
    ['Gauge', specs.gauge || specs.weight_label || (specs.weight_gsm ? `${specs.weight_gsm} gsm` : null)],
    ['Length', specs.length_label],
    ['Width', specs.width_cm ? `${specs.width_cm} cm` : null],
    ['Construction', specs.weave],
    ['Colour', specs.color],
    ['Origin', specs.origin],
    ['Maker', specs.maker || specs.mill],
    ['Sold as', specs.unit_label],
  ]
  return map.filter(([, value]) => value !== null && value !== undefined && value !== '')
}

export function titleCase(value) {
  if (!value) return ''
  return String(value).replace(/(^|[\s-])([a-z])/g, (_, a, b) => a + b.toUpperCase()).replace(/-/g, ' ')
}

/** Order status → the tag variant used in the ledger. */
export function statusTone(status) {
  switch (status) {
    case 'paid':
    case 'delivered':
      return 'tag--ok'
    case 'shipped':
      return 'tag--saddle'
    case 'cancelled':
    case 'refunded':
      return 'tag--error'
    default:
      return ''
  }
}
