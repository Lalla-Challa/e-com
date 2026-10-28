import { useEffect, useState } from 'react'
import { Link, NavLink, useLocation } from 'react-router-dom'
import { useCart, useAuth } from '../stores/store.js'
import { api } from '../services/api.js'

/**
 * The house shell: announcement strip, centred typographic wordmark between
 * two navigation groups, a ledger footer, and the mobile drawer.
 *
 * Store name, announcement, correspondence copy and the navigation categories
 * are server-owned (cms_settings + categories) — nothing here is re-authored in
 * the client beyond fallbacks for a cold or unreachable API.
 */
export function Layout({ children }) {
  const itemCount = useCart((s) => s.itemCount)
  const refreshCart = useCart((s) => s.refresh)
  const user = useAuth((s) => s.user)
  const initAuth = useAuth((s) => s.init)
  const [categories, setCategories] = useState([])
  const [cmsPages, setCmsPages] = useState([])
  const [settings, setSettings] = useState({})
  const [drawerOpen, setDrawerOpen] = useState(false)
  const location = useLocation()

  useEffect(() => {
    initAuth()
    refreshCart()
    // Best effort: the storefront still renders if the CMS endpoints are down.
    api.get('/pages').then(({ data }) => setCmsPages(data.pages || [])).catch(() => {})
    api.get('/settings').then(({ data }) => setSettings(data.settings || {})).catch(() => {})
    api.get('/categories').then(({ data }) => setCategories(data.categories || [])).catch(() => {})
  }, [initAuth, refreshCart])

  useEffect(() => { setDrawerOpen(false) }, [location.pathname, location.search])

  const setting = (key, fallback) => (settings[key] === undefined || settings[key] === '' ? fallback : settings[key])
  const announcement = Number(settings['announcement.enabled'] ?? 1) ? setting('announcement.text', '') : ''
  const storeName = setting('store.name', 'Buttonwalay')
  const tagline = setting('store.tagline', 'Threads, elastic, needles and sharpenable steel for people who sew.')
  const address = setting('store.address', 'Rye Lane Works, London / Dispatched worldwide')

  // The house chapters lead the navigation; any legacy category the catalogue
  // still carries follows behind rather than pushing a chapter out of the nav.
  const CHAPTER_ORDER = ['threads', 'elastic-lace', 'needles-pins', 'scissors-cutters']
  const shopLinks = [...categories]
    .sort((a, b) => {
      const ai = CHAPTER_ORDER.indexOf(a.slug)
      const bi = CHAPTER_ORDER.indexOf(b.slug)
      return (ai === -1 ? CHAPTER_ORDER.length : ai) - (bi === -1 ? CHAPTER_ORDER.length : bi) || a.name.localeCompare(b.name)
    })
    .slice(0, 4)
  const housePage = cmsPages.find((p) => p.slug === 'about')
  const glossaryPage = cmsPages.find((p) => p.slug === 'notions-glossary')

  const navClass = ({ isActive }) => `nav-link${isActive ? ' is-active' : ''}`

  return (
    <div className="shell">
      {announcement && (
        <div className="announce">
          <p>{announcement}</p>
        </div>
      )}

      <header className="masthead">
        <div className="masthead-inner">
          <nav className="masthead-nav" aria-label="Catalogue">
            {shopLinks.map((c) => (
              <NavLink key={c.slug} to={`/shop?category=${c.slug}`} className={navClass}>
                {c.name}
              </NavLink>
            ))}
            <NavLink to="/page/about" className={navClass}>
              The House
            </NavLink>
          </nav>

          <button
            className="masthead-toggle"
            aria-expanded={drawerOpen}
            onClick={() => setDrawerOpen((open) => !open)}
          >
            {drawerOpen ? 'Close' : 'Index'}
          </button>

          <Link to="/" className="wordmark" aria-label={`${storeName} — home`}>
            <span className="mark">BUTTON</span>
            <span className="sub">walay</span>
          </Link>

          <div className="masthead-nav masthead-nav--end">
            <NavLink to="/page/notions-glossary" className="nav-link">
              Notions Guide
            </NavLink>
            <NavLink to="/shop?focus=search" className="nav-link">
              Search
            </NavLink>
            <NavLink to={user ? '/account' : '/auth'} className={navClass}>
              {user ? user.full_name?.split(' ')[0] || 'Account' : 'Account'}
            </NavLink>
            {user?.role === 'admin' && (
              <>
                <NavLink to="/admin" className="nav-link">
                  Console
                </NavLink>
                <NavLink to="/admin/roles" className="nav-link">
                  Access
                </NavLink>
              </>
            )}
            <NavLink to="/cart" className="nav-link bag-link">
              Bag
              {itemCount > 0 && <span className="bag-count">{itemCount}</span>}
            </NavLink>
          </div>
        </div>

        {drawerOpen && (
          <div className="drawer is-open">
            <ul>
              {shopLinks.map((c) => (
                <li key={c.slug}><Link to={`/shop?category=${c.slug}`}>{c.name}</Link></li>
              ))}
              <li><Link to="/shop">All Notions</Link></li>
              {housePage && <li><Link to={`/page/${housePage.slug}`}>{housePage.title}</Link></li>}
              {glossaryPage && <li><Link to={`/page/${glossaryPage.slug}`}>{glossaryPage.title}</Link></li>}
              <li><Link to="/reviews">Review Ledger</Link></li>
              <li><Link to={user ? '/account' : '/auth'}>{user ? 'Account' : 'Sign in'}</Link></li>
              <li><Link to="/cart">Bag ({itemCount})</Link></li>
            </ul>
          </div>
        )}
      </header>

      <main className="site-main">{children}</main>

      <footer className="foot">
        <div className="wrap">
          <div className="foot-grid">
            <div className="foot-col">
              <p className="foot-brand">Buttonwalay</p>
              <p className="foot-note">{tagline}</p>
              <p className="foot-note">{address}</p>
            </div>

            <div className="foot-col">
              <h4>Materials &amp; Craft</h4>
              <ul>
                {shopLinks.map((c) => (
                  <li key={c.slug}><Link to={`/shop?category=${c.slug}`}>{c.name}</Link></li>
                ))}
                <li><Link to="/shop">Full Catalogue</Link></li>
              </ul>
            </div>

            <div className="foot-col">
              <h4>House Services</h4>
              <ul>
                {cmsPages.map((p) => (
                  <li key={p.slug}><Link to={`/page/${p.slug}`}>{p.title}</Link></li>
                ))}
              {user?.role === 'admin' && (
                <>
                  <li><Link to="/admin">Console</Link></li>
                  <li><Link to="/admin/roles">Access</Link></li>
                  <li><Link to="/admin/tax">Tax</Link></li>
                  <li><Link to="/admin/shipping">Shipping</Link></li>
                  <li><Link to="/admin/currencies">Currencies</Link></li>
                  <li><Link to="/admin/invoices">Invoices</Link></li>
                  <li><Link to="/admin/attributes">Attributes</Link></li>
                  <li><Link to="/admin/purchase-orders">Purchase orders</Link></li>
                  <li><Link to="/admin/returns">Returns</Link></li>
                  <li><Link to="/admin/customers">Customers</Link></li>
                  <li><Link to="/admin/promotions">Promotions</Link></li>
                </>
              )}
              <li><Link to="/reviews">Review Ledger</Link></li>
                <li><Link to={user ? '/account' : '/auth'}>{user ? 'Your Account' : 'Sign In'}</Link></li>
              </ul>
            </div>

            <div className="foot-col">
              <h4>Dispatch &amp; Notes</h4>
              <p className="foot-note">
                {setting('correspondence.body', 'Restock alerts for thread colourways, seasonal lace trims and new blade arrivals. No excess — just inventory.')}
              </p>
              <form className="newsletter-form" onSubmit={(e) => e.preventDefault()}>
                <label className="visually-hidden" htmlFor="footer-email">Email address</label>
                <input className="input" id="footer-email" type="email" placeholder="Your email address" />
                <button className="btn btn-sm" type="submit">Subscribe</button>
              </form>
            </div>
          </div>

          <div className="foot-bottom">
            <span>© {new Date().getFullYear()} Buttonwalay. All rights reserved. / Privacy Policy / Terms / Refund Policy</span>
            <span className="tabular">$ USD • £ GBP • € EUR</span>
          </div>
        </div>
      </footer>
    </div>
  )
}
