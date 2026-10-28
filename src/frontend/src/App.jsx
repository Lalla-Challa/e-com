import { BrowserRouter } from 'react-router-dom'
import { AppRoutes } from './router.jsx'

// BASE_URL is "/" in dev and "/app/" in the production build (see vite.config.js).
const basename = import.meta.env.BASE_URL.replace(/\/+$/, '') || '/'

export default function App() {
  return (
    <BrowserRouter basename={basename}>
      <AppRoutes />
    </BrowserRouter>
  )
}
