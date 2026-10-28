import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useCart } from '../stores/store.js'
import { fabricFacts, money } from '../lib/format.js'

/**
 * A catalogue card: 4:5 framed plate, maker + reference eyebrow, serif
 * name, fibre facts with tabular figures, and a quick "add one unit" action.
 *
 * The quick add is a real cart write (POST /cart) — the price it will charge is
 * the server's, this card only shows what the API returned.
 */
export function ClothCard({ product, showDescription = false, compact = false }) {
  const [state, setState] = useState(null)
  const add = useCart((s) => s.add)
  const specs = product.specs || {}
  const outOfStock = product.stock === 0
  const unit = specs.unit_label || 'unit'
  const facts = fabricFacts(specs)

  const quickAdd = async (event) => {
    event.preventDefault()
    event.stopPropagation()
    setState('busy')
    try {
      await add(product.id, 1)
      setState('added')
      window.setTimeout(() => setState(null), 2500)
    } catch (e) {
      setState(e.message)
    }
  }

  return (
    <Link to={`/product/${product.slug}`} className={`cloth-card${compact ? ' cloth-card--compact' : ''}`}>
      <div className="cloth-media">
        {product.image_url
          ? <img src={product.image_url} alt={product.name} loading="lazy" />
          : <div className="cloth-plate" aria-hidden="true" />}
        {specs.badge && <span className="cloth-badge">{specs.badge}</span>}
        {outOfStock && <span className="cloth-out">Sold out — restocking</span>}
      </div>

      <div className="cloth-body">
        <div className="cloth-meta">
          <span>{specs.maker || specs.origin || 'Buttonwalay'}</span>
          <span className="tabular">{specs.ref || ''}</span>
        </div>

        <h3 className="cloth-name">{product.name}</h3>

        {showDescription && product.description && (
          <p className="cloth-desc">{product.description}</p>
        )}

        {!compact && facts && <p className="cloth-facts">{facts}</p>}

        <div className="cloth-foot">
          <span className="cloth-price">
            {money(product.price, product.currency)}
            {specs.unit_label && <span className="unit"> / {specs.unit_label}</span>}
          </span>
          <button
            type="button"
            className="btn btn--outline btn-sm"
            disabled={outOfStock || state === 'busy'}
            onClick={quickAdd}
          >
            {state === 'busy' ? 'Adding…' : state === 'added' ? 'In bag ✓' : `Add 1 ${unit}`}
          </button>
        </div>

        {state && state !== 'busy' && state !== 'added' && (
          <p className="field-error">{state}</p>
        )}
      </div>
    </Link>
  )
}
