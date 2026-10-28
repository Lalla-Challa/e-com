import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../services/api.js'
import { useAuth } from '../stores/store.js'
import { money } from '../lib/format.js'

/**
 * Customer wishlist: saved products a shopper wants to remember. Add from the
 * product page or the catalogue; remove here. Out-of-stock items stay listed
 * so they can be re-ordered when replenished.
 */
export function Wishlist() {
  const user = useAuth((s) => s.user)
  const ready = useAuth((s) => s.ready)
  const [items, setItems] = useState([])
  const [error, setError] = useState(null)
  const [notice, setNotice] = useState(null)

  const load = useCallback(() => {
    if (!ready || !user) return
    api.get('/wishlist')
      .then(({ data }) => setItems(data.wishlist || []))
      .catch((e) => setError(e.message))
  }, [ready, user])

  useEffect(() => { load() }, [load])

  const flash = (text) => { setNotice(text); setError(null) }

  const remove = async (id) => {
    try {
      await api.delete(`/wishlist/${id}`)
      setItems((prev) => prev.filter((i) => i.id !== id))
      flash('Removed from the wish ledger')
    } catch (err) { setError(err.message) }
  }

  if (!ready) return <div className="wrap"><p className="muted">Opening the wish ledger…</p></div>

  if (!user) {
    return (
      <div className="wrap">
        <h1>Wish ledger</h1>
        <p className="muted">Sign in to keep a wish ledger — products you mean to order when the roll runs again.</p>
        <Link className="btn" to="/auth">Sign in</Link>
      </div>
    )
  }

  return (
    <div className="wrap">
      <nav className="breadcrumb" aria-label="Breadcrumb">
        <Link to="/">Buttonwalay</Link>
        <span className="sep">/</span>
        <span aria-current="page">Wish ledger</span>
      </nav>

      <div className="section-head">
        <div>
          <p className="eyebrow">Saved notions</p>
          <h1 style={{ marginTop: 'var(--space-sm)' }}>Wish ledger</h1>
          <p className="t-body-sm muted">
            Keep notions you mean to order. Add from any product page; remove here when you have what you need.
          </p>
        </div>
        <div className="right">
          <Link className="eyebrow link-u" to="/shop">Enter the catalogue</Link>
        </div>
      </div>

      {error && <p className="alert alert-error" style={{ marginBottom: 'var(--space-md)' }}>{error}</p>}
      {notice && <p className="alert alert-ok" style={{ marginBottom: 'var(--space-md)' }}>{notice}</p>}

      {items.length === 0 ? (
        <div className="empty">
          <p style={{ marginBottom: 'var(--space-md)' }}>Your wish ledger is empty.</p>
          <Link className="btn" to="/shop">Browse notions</Link>
        </div>
      ) : (
        <div className="cloth-grid" style={{ gridTemplateColumns: 'repeat(4, minmax(0, 1fr))' }}>
          {items.map((item) => (
            <div key={item.id} className="cloth-card">
              <div className="cloth-media">
                {item.image_url ? (
                  <img src={item.image_url} alt={item.name} />
                ) : (
                  <div className="cloth-plate" />
                )}
                {!item.is_active && <span className="cloth-out">Archived</span>}
                <button className="eyebrow link-u" onClick={() => remove(item.id)} style={{ position: 'absolute', top: 0, right: 0, padding: '0.25rem 0.5rem' }}>Remove</button>
              </div>
              <div className="cloth-meta">
                <span>{item.sku}</span>
                <span>{item.stock > 0 ? 'in stock' : 'out of stock'}</span>
              </div>
              <h3 className="cloth-name">{item.name}</h3>
              <p className="cloth-desc">{item.stock > 0 ? `${item.stock} unit${item.stock === 1 ? '' : 's'} on hand` : 'Replenishment pending'}</p>
              <div className="cloth-foot">
                <span className="cloth-price">{money(item.price, item.currency)}<span className="unit"> {item.currency}</span></span>
                <Link className="btn btn-sm btn--outline" to={`/product/${item.slug}`}>View</Link>
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  )
}
