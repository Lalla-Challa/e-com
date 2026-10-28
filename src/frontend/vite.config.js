import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

// /api/<module>[/<action>][?query] → the standalone endpoint file. The
// endpoint files themselves are the source of truth — an unknown module just
// 404s from the plugin — so this rewrite stays generic instead of maintaining
// a hardcoded module list that silently rots as endpoints are added. Both
// `/api/orders` and `/api/orders.php?...` forms are accepted.
function proxyRewrite(path) {
  const [pathname, query] = path.split('?')
  const match = pathname.match(/^\/api\/([a-z][a-z0-9_-]*)(?:\.php)?(?:\/([a-z-]+))?$/)
  if (!match) return path
  const target = `/wp-content/plugins/app-core/api/${match[1]}.php`
  const action = match[2] ? `?action=${match[2]}` : ''
  if (query !== undefined) {
    return `${target}${action ? `${action}&` : '?'}${query}`
  }
  return `${target}${action}`
}

// Dev: Vite serves the SPA at "/" and proxies /api to the WP container.
// Prod: `npm run build` emits dist/ that app-core streams at /app — the SPA
// lives under /app, so built asset URLs and the router basename must too.
// VITE_BASE overrides for static hosts that serve dist/ at the domain root.
export default defineConfig(({ command }) => ({
  base: command === 'serve' ? '/' : (process.env.VITE_BASE || '/app/'),
  plugins: [react()],
  server: {
    port: 5173,
    strictPort: true,
    // Docker Desktop bind mounts on Windows don't propagate inotify events;
    // polling keeps HMR and fresh transforms working.
    watch: { usePolling: true, interval: 300 },
    proxy: {
      '/api': {
        target: process.env.VITE_API_TARGET || 'http://localhost:8081',
        changeOrigin: true,
        rewrite: proxyRewrite,
      },
    },
  },
  build: {
    outDir: 'dist',
    assetsDir: 'assets',
    rollupOptions: {
      output: {
        entryFileNames: 'assets/index.js',
        chunkFileNames: 'assets/[name].js',
        assetFileNames: 'assets/[name].[ext]',
      },
    },
  },
}))
