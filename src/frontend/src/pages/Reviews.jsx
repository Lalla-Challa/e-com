import { useEffect, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { api } from '../services/api.js'
import { dateLabel, stars } from '../lib/format.js'

const PER_PAGE = 10

/**
 * The review ledger: every approved appraisal across the catalogue, with the
 * score distribution the API computes over the whole ledger (not just the page
 * being shown).
 */
export function Reviews() {
  const [params, setParams] = useSearchParams()
  const [reviews, setReviews] = useState([])
  const [summary, setSummary] = useState({ count: 0, average: 0, distribution: {} })
  const [total, setTotal] = useState(0)
  const [loading, setLoading] = useState(true)

  const page = Math.max(1, Number(params.get('page') || 1))
  const rating = params.get('rating') || ''

  useEffect(() => {
    setLoading(true)
    const qs = new URLSearchParams()
    qs.set('page', String(page))
    qs.set('per_page', String(PER_PAGE))
    if (rating) qs.set('rating', rating)
    api.get(`/reviews?${qs}`)
      .then(({ data, meta }) => {
        setReviews(data.reviews || [])
        setTotal(meta?.total || 0)
        setSummary(meta?.summary || { count: 0, average: 0, distribution: {} })
      })
      .catch(() => {
        setReviews([])
        setTotal(0)
      })
      .finally(() => setLoading(false))
  }, [page, rating])

  const update = (key, value) => {
    const next = new URLSearchParams(params)
    if (value === '') next.delete(key)
    else next.set(key, value)
    if (key !== 'page') next.delete('page')
    setParams(next)
  }

  const distribution = summary.distribution || {}
  const totalPages = Math.max(1, Math.ceil(total / PER_PAGE))
  const count = summary.count || 0

  return (
    <div className="wrap">
      <nav className="breadcrumb" aria-label="Breadcrumb">
        <Link to="/">House</Link>
        <span className="sep">/</span>
        <span aria-current="page">Review ledger</span>
      </nav>

      <div className="section-head">
        <div>
          <h1>Review Ledger</h1>
          <p className="t-body-lg muted" style={{ marginTop: 'var(--space-sm)', maxWidth: '62ch' }}>
            Unfiltered appraisals — thread behaviour, blade edge retention and trim quality — documented by makers, tailors and studios.
          </p>
        </div>
        <div className="right">
          <p className="eyebrow">Counter registry</p>
          <p className="eyebrow">Vol. VII — Issue 10</p>
        </div>
      </div>

      <div className="review-summary">
        <div className="panel">
          <p className="eyebrow">Aggregate evaluation</p>
          <p className="review-score" style={{ marginTop: 'var(--space-sm)' }}>
            {Number(summary.average || 0).toFixed(2)}
            <span className="muted" style={{ fontSize: 'var(--title-lg)' }}> / 5.0</span>
          </p>
          <p className="stars" style={{ marginTop: 'var(--space-sm)' }}>{stars(summary.average)}</p>
          <p className="t-body-sm muted">Based on {count} verified purchase{count === 1 ? '' : 's'}</p>
          <p className="tag tag--ok" style={{ marginTop: 'var(--space-md)' }}>100% verified purchases</p>
        </div>

        <div className="panel">
          <div className="panel-head">
            <span className="eyebrow">Score distribution</span>
            <span className="eyebrow">{rating ? `Filtered to ${rating}★` : 'All appraisals'}</span>
          </div>
          {[5, 4, 3, 2, 1].map((star) => {
            const n = distribution[star] || 0
            const pct = count > 0 ? Math.round((n / count) * 100) : 0
            return (
              <button
                key={star}
                className="dist-row"
                onClick={() => update('rating', rating === String(star) ? '' : String(star))}
                style={{ width: '100%', textAlign: 'left' }}
              >
                <span className={rating === String(star) ? '' : 'muted'}>{star} star{star === 1 ? '' : 's'}</span>
                <span className="dist-bar"><span style={{ width: `${pct}%` }} /></span>
                <span className="tabular right">{pct}%</span>
              </button>
            )
          })}
          {rating && (
            <button className="link-u t-body-sm" style={{ marginTop: 'var(--space-md)' }} onClick={() => update('rating', '')}>
              Clear rating filter
            </button>
          )}
        </div>
      </div>

      <div className="section-head" style={{ marginTop: 'var(--space-xl)' }}>
        <div>
          <p className="eyebrow">All notions</p>
          <h2>{loading ? 'Consulting the ledger…' : `${total} appraisal${total === 1 ? '' : 's'} recorded`}</h2>
        </div>
        <span className="eyebrow">Most recent appraisals first</span>
      </div>

      {!loading && reviews.length === 0 ? (
        <p className="empty">No appraisals recorded yet. Verified buyers may publish the first note.</p>
      ) : (
        <div className="review-list">
          {reviews.map((r) => (
            <article className="review-item" key={r.id}>
              <div className="review-item__head">
                <div>
                  <p className="review-item__who">
                    {r.author}
                    {r.is_verified_purchase && <span className="tag tag--ok" style={{ marginLeft: '0.6rem' }}>Verified</span>}
                  </p>
                  <h3 className="review-item__title">{r.title}</h3>
                </div>
                <div className="right">
                  <span className="stars">{stars(r.rating)}</span>
                  <p className="eyebrow" style={{ margin: 0 }}>Recorded {dateLabel(r.created_at)}</p>
                </div>
              </div>

              <p className="review-item__body">“{r.body}”</p>

              <div className="review-item__facts">
                <div>
                  <span className="eyebrow">Notion</span>
                  <Link className="tabular link-u" to={`/product/${r.product?.slug}`}>{r.product?.name}</Link>
                </div>
                <div>
                  <span className="eyebrow">Ledger reference</span>
                  <span className="tabular">Verified client #{String(r.id).padStart(4, '0')}</span>
                </div>
                <div>
                  <span className="eyebrow">Assessment</span>
                  <span className="tabular">{r.rating}/5 from a verified buyer</span>
                </div>
              </div>
            </article>
          ))}
        </div>
      )}

      {totalPages > 1 && (
        <div className="pagination">
          <button className="page-btn" disabled={page <= 1} onClick={() => update('page', String(page - 1))}>←</button>
          {Array.from({ length: Math.min(totalPages, 8) }, (_, i) => i + 1).map((n) => (
            <button key={n} className={`page-btn${n === page ? ' is-active' : ''}`} onClick={() => update('page', String(n))}>{n}</button>
          ))}
          {totalPages > 8 && <span className="muted">… of {totalPages}</span>}
          <button className="page-btn" disabled={page >= totalPages} onClick={() => update('page', String(page + 1))}>→</button>
        </div>
      )}

      <div className="note" style={{ marginTop: 'var(--space-xl)' }}>
        <p className="eyebrow">Submit an appraisal note</p>
        <p style={{ margin: '0.35rem 0 0' }}>
          Appraisals are open to verified purchasers only: once an order containing a notion is paid, the product page accepts a
          note from that account. Open any product you have bought and publish your assessment there.
        </p>
      </div>
    </div>
  )
}
