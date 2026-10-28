import { useEffect, useMemo, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { api } from '../services/api.js'
import { useCart, useAuth } from '../stores/store.js'
import { ClothCard } from '../components/ClothCard.jsx'
import { dateLabel, money, specRows, stars } from '../lib/format.js'

/**
 * Notion detail: framed plate with view switching, the technical dossier from
 * products.metadata, a cut panel that prices in metres, and the maker
 * appraisal ledger (approved reviews only).
 *
 * The line total is the only arithmetic done here, and it is the same
 * unit_price × quantity the cart will persist server-side.
 */
const PROJECTS = [
  { label: 'Waistband run', metres: 1.0 },
  { label: 'Full trim run', metres: 2.5 },
]

export function Product() {
  const { slug } = useParams()
  const [product, setProduct] = useState(null)
  const [summary, setSummary] = useState({ count: 0, average: 0 })
  const [reviews, setReviews] = useState([])
  const [related, setRelated] = useState([])
  const [view, setView] = useState(0)
  const [metres, setMetres] = useState(1)
  const [status, setStatus] = useState(null)
  const [busy, setBusy] = useState(false)
  const add = useCart((s) => s.add)
  const user = useAuth((s) => s.user)
  const specs = product?.specs || {}
  const dossier = specs.dossier || {}
  const unit = specs.unit_label || 'unit'
  const price = Number(product?.price || 0)

  useEffect(() => {
    setProduct(null)
    setRelated([])
    setStatus(null)
    setView(0)
    setMetres(1)
    api.get(`/products?slug=${slug}`)
      .then(({ data }) => {
        setProduct(data.product)
        setSummary(data.reviews_summary ?? { count: 0, average: 0 })
      })
      .catch(() => setProduct(false))
    api.get(`/reviews?product=${slug}`)
      .then(({ data }) => setReviews(data.reviews ?? []))
      .catch(() => setReviews([]))
  }, [slug])

  useEffect(() => {
    if (!product?.category?.slug) return
    api.get(`/products?category=${product.category.slug}&per_page=4`)
      .then(({ data }) => setRelated((data.products || []).filter((p) => p.slug !== product.slug).slice(0, 3)))
      .catch(() => setRelated([]))
  }, [product])

  const lineTotal = useMemo(() => (price * metres).toFixed(2), [price, metres])

  if (product === null) return <div className="wrap"><p className="muted">Consulting the catalogue…</p></div>
  if (product === false) {
    return (
      <div className="wrap">
        <h1>Notion not found</h1>
        <p className="muted">That reference is not in the catalogue. It may have been retired.</p>
        <Link className="btn" to="/shop">Back to the catalogue</Link>
      </div>
    )
  }

  const outOfStock = product.stock === 0
  const step = specs.unit === 'metre' ? 0.5 : 1
  const maxMetres = outOfStock ? step : Math.max(step, product.stock)
  const views = [product.image_url].filter(Boolean)

  const addToCart = async () => {
    setBusy(true)
    setStatus(null)
    try {
      await add(product.id, metres)
      setStatus({ ok: true, message: `${metres}${specs.unit === 'metre' ? 'm' : ''} ${product.name} added to your bag.` })
    } catch (e) {
      setStatus({ ok: false, message: e.message })
    } finally {
      setBusy(false)
    }
  }

  const clamp = (value) => Math.max(step, Math.min(maxMetres, Math.round(value / step) * step))

  return (
    <>
      <div className="wrap">
        <nav className="breadcrumb" aria-label="Breadcrumb">
          <Link to="/shop">Catalogue</Link>
          <span className="sep">/</span>
          <Link to={`/shop?category=${product.category?.slug || ''}`}>{specs.fiber || product.category?.name || 'Notions'}</Link>
          <span className="sep">/</span>
          <span aria-current="page">Reference {specs.ref ? `N° ${specs.ref}` : ''}</span>
        </nav>

        <div className="pdp">
          <div className="pdp-media">
            <p className="eyebrow" style={{ marginBottom: 'var(--space-sm)' }}>
              {views.length > 1 ? `View ${String(view + 1).padStart(2, '0')} • counter plate` : 'Plate 01 • counter plate'}
            </p>
            {views[view] && <img src={views[view]} alt={product.name} />}
            <div className="row row--between" style={{ marginTop: 'var(--space-sm)' }}>
              <span className="eyebrow tabular">Batch record: {specs.ref || 'BTH-00'}</span>
              <span className="eyebrow">Daylight verified</span>
            </div>
          </div>

          <div>
            <p className="eyebrow">{specs.maker || specs.origin || 'Buttonwalay'}</p>
            <h1 className="pdp-title">{product.name}</h1>

            <div className="row" style={{ gap: 'var(--space-md)', marginBottom: 'var(--space-md)' }}>
              {summary.count > 0 && (
                <span className="row" style={{ gap: '0.4rem' }}>
                  <span className="stars">{stars(summary.average)}</span>
                  <span className="t-body-sm muted">{Number(summary.average).toFixed(1)} / 5.0 · {summary.count} verified appraisal{summary.count === 1 ? '' : 's'}</span>
                </span>
              )}
              {outOfStock
                ? <span className="tag tag--error">Sold out</span>
                : <span className="tag tag--ok">In stock · {product.stock}{specs.unit === 'metre' ? 'm' : ''}</span>}
            </div>

            <p className="t-body-lg">{product.description}</p>

            <div className="pdp-price">
              {money(product.price, product.currency)}
              {specs.unit_label && <span className="unit"> / {specs.unit_label}</span>}
            </div>

            <div className="pdp-specs">
              {specRows(specs).map(([label, value]) => (
                <div className="spec-line" key={label}>
                  <span className="k">{label}</span>
                  <span className="tabular">{value}</span>
                </div>
              ))}
            </div>

            <div className="row" style={{ gap: 'var(--space-lg)' }}>
              <div>
                <span className="field-label">Cut quantity</span>
                <div className="qty">
                  <button type="button" onClick={() => setMetres((m) => clamp(m - step))} aria-label="Reduce quantity">–</button>
                  <input
                    aria-label={`Quantity in ${unit}`}
                    type="number"
                    min={step}
                    step={step}
                    max={maxMetres}
                    value={metres}
                    onChange={(e) => setMetres(clamp(Number(e.target.value) || step))}
                  />
                  <button type="button" onClick={() => setMetres((m) => clamp(m + step))} aria-label="Add quantity">+</button>
                </div>
                <span className="field-help">
                  {specs.unit === 'metre' ? 'Sold in 0.5m increments (minimum 1.0m)' : `Supplied as ${specs.unit_label || 'one unit'}`}
                </span>
              </div>
              <div>
                <span className="field-label">Line total</span>
                <p className="tabular" style={{ fontSize: 'var(--title-lg)', margin: '0.35rem 0 0' }}>
                  {money(lineTotal, product.currency)}
                </p>
              </div>
            </div>

            <div className="pdp-actions" style={{ marginTop: 'var(--space-lg)' }}>
              <button className="btn" onClick={addToCart} disabled={outOfStock || busy}>
                {busy ? 'Adding…' : `Add ${metres}${specs.unit === 'metre' ? 'm' : ''} to bag — ${money(lineTotal, product.currency)}`}
              </button>
              {specs.swatch_price && (
                <Link className="btn btn--outline" to={`/product/all-purpose-thread-card-24`}>
                  Order the 24-Card {money(specs.swatch_price, product.currency)}
                </Link>
              )}
            </div>

            {status && (
              <p className={`alert ${status.ok ? 'alert-ok' : 'alert-error'}`} style={{ marginTop: 'var(--space-md)' }}>
                {status.message}
              </p>
            )}

            <div className="grid grid--3" style={{ marginTop: 'var(--space-lg)', gap: 'var(--space-md)' }}>
              {PROJECTS.slice(0, specs.unit === 'metre' ? 2 : 1).map((p) => (
                <div key={p.label}>
                  <span className="eyebrow">{p.label}</span>
                  <p className="tabular muted" style={{ margin: '0.2rem 0 0' }}>
                    {p.metres}m • {money(price * p.metres, product.currency)}
                  </p>
                </div>
              ))}
              {specs.unit === 'metre' && (
                <div>
                  <span className="eyebrow">Full roll (50m)</span>
                  <p className="tabular muted" style={{ margin: '0.2rem 0 0' }}>
                    {money(price * 50 * 0.85, product.currency)} · 15% studio privilege
                  </p>
                </div>
              )}
            </div>
          </div>
        </div>

        <section className="section" style={{ marginTop: 'var(--space-xl)' }}>
          <div className="section-head">
            <div>
              <p className="eyebrow">01</p>
              <h2>Technical Specifications</h2>
            </div>
            <span className="eyebrow">{specs.composition || ''}</span>
          </div>
          <div className="grid grid--split">
            <div className="prose">
              <p>{product.description}</p>
              {dossier.stability && <p className="t-body-sm muted">Dimensional stability: {dossier.stability}</p>}
            </div>
            <div className="panel">
              <div className="panel-head"><span className="eyebrow">Batch record</span><span className="tabular muted">{specs.ref || '—'}</span></div>
              {specRows(specs).map(([label, value]) => (
                <div className="spec-line" key={`b-${label}`}>
                  <span className="k">{label}</span>
                  <span className="tabular">{value}</span>
                </div>
              ))}
            </div>
          </div>
        </section>

        {(dossier.construction || dossier.care) && (
          <section className="section">
            <div className="section-head">
              <div>
                <p className="eyebrow">02 — 03</p>
                <h2>Use, Construction &amp; Care</h2>
              </div>
            </div>
            <div className="grid grid--split">
              {dossier.construction && (
                <div>
                  <h3>Construction notes</h3>
                  {Object.entries(dossier.construction).map(([key, value]) => (
                    <div className="spec-line" key={key} style={{ display: 'block', borderBottom: '1px solid var(--hairline)', padding: '0.6rem 0' }}>
                      <span className="k" style={{ display: 'block' }}>{key}</span>
                      <span className="t-body-sm">{value}</span>
                    </div>
                  ))}
                </div>
              )}
              {dossier.care && (
                <div>
                  <h3>Care &amp; storage protocol</h3>
                  {Object.entries(dossier.care).map(([key, value]) => (
                    <div className="spec-line" key={key} style={{ display: 'block', borderBottom: '1px solid var(--hairline)', padding: '0.6rem 0' }}>
                      <span className="k" style={{ display: 'block' }}>{key}</span>
                      <span className="t-body-sm">{value}</span>
                    </div>
                  ))}
                </div>
              )}
            </div>
          </section>
        )}

        <section className="section">
          <div className="section-head">
            <div>
              <p className="eyebrow">Maker appraisals</p>
              <h2>Workroom Notes</h2>
            </div>
            <div className="right">
              <span className="stars">{stars(summary.average)}</span>
              <p className="eyebrow tabular" style={{ margin: 0 }}>
                {Number(summary.average || 0).toFixed(1)} / 5.0 ({summary.count} verified note{summary.count === 1 ? '' : 's'})
              </p>
            </div>
          </div>

          {reviews.length === 0 ? (
            <p className="empty">
              No appraisal recorded for this notion yet. Verified buyers may publish the first note once their order is paid.
            </p>
          ) : (
            <div className="review-list">
              {reviews.map((r) => (
                <article className="review-item" key={r.id}>
                  <div className="review-item__head">
                    <div>
                      <p className="review-item__who">{r.author}{r.is_verified_purchase ? ' · verified purchase' : ''}</p>
                      <h3 className="review-item__title">{r.title}</h3>
                    </div>
                    <div className="right">
                      <span className="stars">{stars(r.rating)}</span>
                      <p className="eyebrow" style={{ margin: 0 }}>{dateLabel(r.created_at)}</p>
                    </div>
                  </div>
                  <p className="review-item__body">“{r.body}”</p>
                  <div className="review-item__facts">
                    <div><span className="eyebrow">Notion</span><span className="tabular">{product.name}</span></div>
                    <div><span className="eyebrow">Reference</span><span className="tabular">{specs.ref || '—'}</span></div>
                    <div><span className="eyebrow">Rating</span><span className="tabular">{r.rating}/5 from a verified buyer</span></div>
                  </div>
                </article>
              ))}
            </div>
          )}

          {summary.count > 0 && !user && (
            <p className="note" style={{ marginTop: 'var(--space-md)' }}>
              Sign in with the account used for the purchase to submit an appraisal note.
              {' '}<Link className="link-u" to="/auth">Sign in</Link>
            </p>
          )}
        </section>

        {related.length > 0 && (
          <section className="section">
            <div className="section-head">
              <div>
                <p className="eyebrow">Same chapter</p>
                <h2>More from this chapter</h2>
              </div>
              <Link className="eyebrow link-u" to={`/shop?category=${product.category?.slug || ''}`}>View chapter</Link>
            </div>
            <div className="cloth-grid" style={{ gridTemplateColumns: 'repeat(3, minmax(0, 1fr))' }}>
              {related.map((p) => <ClothCard key={p.id} product={p} />)}
            </div>
          </section>
        )}
      </div>
    </>
  )
}
