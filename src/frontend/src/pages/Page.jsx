import { useEffect, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { api } from '../services/api.js'

/**
 * Renders a CMS page. The body is structured blocks — never HTML — so this
 * maps each block type onto a real element instead of using
 * dangerouslySetInnerHTML.
 */
function Block({ block, index }) {
  switch (block.type) {
    case 'eyebrow':
      return <p className="eyebrow" style={{ marginTop: 'var(--space-lg)' }}>{block.text}</p>
    case 'heading':
      return <h2>{block.text}</h2>
    case 'paragraph':
      return <p>{block.text}</p>
    case 'quote':
      return <blockquote>{block.text}</blockquote>
    case 'list':
      return (
        <ul>
          {(block.items || []).map((item, i) => <li key={i}>{item}</li>)}
        </ul>
      )
    case 'figure':
      return block.src ? (
        <figure style={{ margin: 'var(--space-lg) 0' }}>
          <img src={block.src} alt={block.alt || ''} style={{ border: '1px solid var(--hairline)', width: '100%' }} />
          {block.caption && <figcaption className="field-help">{block.caption}</figcaption>}
        </figure>
      ) : null
    default:
      return <p className="muted" key={index}>Unsupported block: {String(block.type)}</p>
  }
}

export function Page() {
  const { slug } = useParams()
  const [page, setPage] = useState(null)
  const [error, setError] = useState(null)

  useEffect(() => {
    setPage(null)
    setError(null)
    api.get(`/pages?slug=${encodeURIComponent(slug)}`)
      .then(({ data }) => setPage(data.page))
      .catch((err) => setError(err.message))
  }, [slug])

  if (error) {
    return (
      <div className="wrap">
        <h1>Page not found</h1>
        <p className="muted">{error}</p>
        <Link className="btn" to="/">Back to the house</Link>
      </div>
    )
  }

  if (!page) return <div className="wrap"><p className="muted">Opening the dossier…</p></div>

  const body = page.body || []
  const hasOwnEyebrow = body.some((b) => b.type === 'eyebrow')

  return (
    <div className="wrap">
      <nav className="breadcrumb" aria-label="Breadcrumb">
        <Link to="/">House</Link>
        <span className="sep">/</span>
        <span aria-current="page">{page.title}</span>
      </nav>

      <div className="grid grid--split" style={{ gridTemplateColumns: '1.6fr 1fr', alignItems: 'start' }}>
        <article className="prose">
          {!hasOwnEyebrow && <p className="eyebrow">House record</p>}
          <h1 style={{ marginTop: 'var(--space-sm)' }}>{page.title}</h1>
          {page.excerpt && <p className="t-body-lg muted">{page.excerpt}</p>}
          <hr />
          {body.map((block, i) => <Block key={i} block={block} index={i} />)}
        </article>

        <aside className="panel">
          <div className="panel-head">
            <span className="eyebrow">Fascicle protocol</span>
            <span className="tabular muted">{page.status === 'published' ? 'Published' : 'Draft'}</span>
          </div>
          <div className="spec-line"><span className="k">Slug</span><span className="tabular">{page.slug}</span></div>
          <div className="spec-line"><span className="k">Published</span><span className="tabular">{page.published_at ? page.published_at.slice(0, 10) : '—'}</span></div>
          <div className="spec-line"><span className="k">Blocks</span><span className="tabular">{body.length}</span></div>
          <p className="field-help">
            Content is served by the headless CMS: the admin console edits this page at /admin → CMS pages.
          </p>
          <div className="stack-sm" style={{ marginTop: 'var(--space-md)' }}>
            <Link className="eyebrow link-u" to="/page/shipping-returns">Dispatch &amp; returns protocol</Link>
            <Link className="eyebrow link-u" to="/page/notions-glossary">Notions guide</Link>
            <Link className="eyebrow link-u" to="/reviews">Review ledger</Link>
          </div>
        </aside>
      </div>
    </div>
  )
}
