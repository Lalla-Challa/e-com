import { useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../services/api.js'
import { ClothCard } from '../components/ClothCard.jsx'

const DISCIPLINE = [
  { idx: '01', title: 'Colour-matched threads', tone: 'moss' },
  { idx: '02', title: 'Sharpenable steel', tone: 'saddle' },
  { idx: '03', title: 'Always in stock', tone: 'night' },
]

const CHAPTERS = [
  { slug: 'threads', idx: '01', kicker: 'Chapter I', title: 'Threads & Spools', teaser: 'Mercerized cotton, silk twist and high-mileage polyester cones.', cta: 'Enter the Spool Cabinet' },
  { slug: 'elastic-lace', idx: '02', kicker: 'Chapter II', title: 'Elastic, Lace & Ribbon', teaser: 'Waistband elastic, Calais lace and grosgrain that keeps its spring.', cta: 'Browse Cut Trims' },
  { slug: 'needles-pins', idx: '03', kicker: 'Chapter III', title: 'Needles, Pins & Buttons', teaser: 'Machine needles, glass-head pins and corozo buttons.', cta: 'Open the Notion Drawer' },
  { slug: 'scissors-cutters', idx: '04', kicker: 'Chapter IV', title: 'Scissors & Cutters', teaser: 'Dressmaker shears, snips and seam rippers built to be sharpened.', cta: 'Inspect the Steel' },
]

/**
 * Storefront home: hero, category tiles and featured products are driven by
 * the catalogue API; editorial blocks stay short so imagery leads.
 */
export function Home() {
  const [featured, setFeatured] = useState([])
  const [categoryImages, setCategoryImages] = useState({})
  const [categories, setCategories] = useState([])
  const [settings, setSettings] = useState({})
  const [featuredError, setFeaturedError] = useState(false)

  useEffect(() => {
    let cancelled = false

    async function loadCatalogue() {
      try {
        const [catalogue, cats, sets] = await Promise.all([
          api.get('/products?per_page=24'),
          api.get('/categories'),
          api.get('/settings'),
        ])
        if (cancelled) return

        const products = catalogue.data.products || []
        const threads = products.filter((p) => p.category === 'threads')
        setFeatured((threads.length >= 4 ? threads : products).slice(0, 4))
        setFeaturedError(false)

        const images = {}
        for (const p of products) {
          const slug = p.category
          if (slug && p.image_url && !images[slug]) {
            images[slug] = p.image_url
          }
        }
        setCategoryImages(images)
        setCategories(cats.data.categories || [])
        setSettings(sets.data.settings || {})
      } catch {
        if (!cancelled) {
          setFeatured([])
          setFeaturedError(true)
        }
      }
    }

    loadCatalogue()
    return () => { cancelled = true }
  }, [])

  const setting = (key, fallback) => (settings[key] === undefined || settings[key] === '' ? fallback : settings[key])
  const heroImage = setting('home.hero.image', '') || featured[0]?.image_url || categoryImages.threads || ''

  const chapters = useMemo(
    () => CHAPTERS.map((c) => ({ ...c, image: categoryImages[c.slug] || featured.find((p) => p.category === c.slug)?.image_url })),
    [categoryImages, featured],
  )

  return (
    <>
      <section className="wrap hero hero--visual">
        <div className="hero-copy">
          <p className="eyebrow eyebrow--accent">{setting('home.hero.eyebrow', 'Catalogue N° 07 — Essentials')}</p>
          <h1 className="hero-title">{setting('home.hero.heading', 'Essential notions for every stitch.')}</h1>
          <p className="hero-lede">{setting('home.hero.subheading', 'Threads, elastics, needles and sharpenable steel — curated for people who sew seriously.')}</p>
          <div className="hero-actions">
            <Link className="btn btn--saddle" to="/shop">Shop all notions</Link>
            <Link className="btn btn--outline" to="/shop?category=threads">Browse threads</Link>
          </div>
          <div className="hero-foot">
            <span>Dispatch</span>
            <span className="t-body-sm hero-foot-note">
              {setting('home.hero.note', 'Sourced from specialist makers worldwide')}
            </span>
          </div>
        </div>

        <div className="hero-media">
          {heroImage
            ? <img src={heroImage} alt="Featured notions from the Buttonwalay catalogue" />
            : <div className="hero-media-placeholder" aria-hidden="true" />}
        </div>
      </section>

      <section className="wrap section">
        <div className="section-head">
          <div>
            <p className="eyebrow eyebrow--accent">Shop by category</p>
            <h2>Curated chapters</h2>
          </div>
          <Link className="btn btn--outline btn-sm" to="/shop">Full catalogue</Link>
        </div>

        <div className="cat-grid cat-grid--visual">
          {chapters.map((c) => (
            <Link key={c.slug} className="cat-card cat-card--visual" to={`/shop?category=${c.slug}`}>
              <div className="cat-card__media">
                {c.image
                  ? <img src={c.image} alt={c.title} loading="lazy" />
                  : <div className="cat-card__placeholder" aria-hidden="true" />}
              </div>
              <div className="cat-card__body">
                <div className="idx">
                  <span>{c.idx} / 04</span>
                  <span className="cat-kicker">{c.kicker}</span>
                </div>
                <h3 className="name">{c.title}</h3>
                <p className="cat-teaser">{c.teaser}</p>
                <span className="cta">{c.cta} →</span>
              </div>
            </Link>
          ))}
        </div>
      </section>

      <section className="wrap section section--band">
        <div className="section-head">
          <div>
            <p className="eyebrow eyebrow--accent">Featured</p>
            <h2>From the spool cabinet</h2>
          </div>
          <Link className="link-u link-u--accent" to="/shop">Browse everything</Link>
        </div>

        {featured.length === 0 ? (
          <p className="empty">
            {featuredError
              ? 'Could not load featured products. Check the API connection and refresh.'
              : 'Loading featured products…'}
          </p>
        ) : (
          <div className="cloth-grid cloth-grid--home">
            {featured.map((p) => <ClothCard key={p.id} product={p} compact />)}
          </div>
        )}
      </section>

      <section className="wrap section section--tight">
        <div className="discipline-strip">
          {DISCIPLINE.map((d) => (
            <div key={d.idx} className={`discipline-pill discipline-pill--${d.tone}`}>
              <span className="idx">{d.idx}</span>
              <span>{d.title}</span>
            </div>
          ))}
        </div>
      </section>

      <section className="wrap section">
        <div className="panel panel--accent">
          <div className="grid grid--split">
            <div>
              <p className="eyebrow eyebrow--accent">{setting('correspondence.heading', 'Restock notices')}</p>
              <h2 style={{ marginTop: 'var(--space-sm)' }}>Join the restock ledger</h2>
              <p className="lede-sm">
                {setting('correspondence.body', 'Alerts for thread colourways, lace trims and new blade arrivals.')}
              </p>
            </div>
            <div>
              <form className="newsletter-form" onSubmit={(e) => e.preventDefault()}>
                <div className="field grow" style={{ marginBottom: 0 }}>
                  <label htmlFor="home-email">Studio or maker email</label>
                  <input id="home-email" type="email" placeholder="studio@yourworkroom.com" />
                </div>
                <button className="btn btn--saddle" type="submit">Subscribe</button>
              </form>
            </div>
          </div>
        </div>
      </section>

      {categories.length > 0 && (
        <section className="wrap section--tight">
          <div className="row row--between">
            <span className="eyebrow">All chapters</span>
            <div className="row chapter-links">
              {categories.map((c) => (
                <Link key={c.slug} className="chapter-link" to={`/shop?category=${c.slug}`}>
                  {c.name} <span className="tabular">({c.product_count})</span>
                </Link>
              ))}
            </div>
          </div>
        </section>
      )}
    </>
  )
}
