const BASE = import.meta.env.VITE_API_BASE || '/api'

async function request(path, { method = 'GET', body, headers = {}, retry = true } = {}) {
  const res = await fetch(`${BASE}${path}`, {
    method,
    credentials: 'include',
    headers: {
      ...(body !== undefined ? { 'Content-Type': 'application/json' } : {}),
      ...headers,
    },
    body: body !== undefined ? JSON.stringify(body) : undefined,
  })

  if (res.status === 204) {
    return { ok: true, data: null, meta: null }
  }

  let payload = null
  try {
    payload = await res.json()
  } catch {
    payload = null
  }

  // Access token expired → try one silent refresh, then replay the request.
  if (res.status === 401 && retry && !path.startsWith('/auth/login') && !path.startsWith('/auth/register')) {
    const refreshed = await fetch(`${BASE}/auth/refresh`, {
      method: 'POST',
      credentials: 'include',
    })
    if (refreshed.ok) {
      return request(path, { method, body, headers, retry: false })
    }
  }

  if (!res.ok || !payload?.ok) {
    const error = payload?.error || { code: `HTTP_${res.status}`, message: res.statusText || 'Request failed.' }
    const err = new Error(error.message)
    err.code = error.code
    err.fields = error.fields || {}
    err.status = res.status
    throw err
  }

  return payload
}

export const api = {
  get: (path) => request(path),
  post: (path, body) => request(path, { method: 'POST', body }),
  patch: (path, body) => request(path, { method: 'PATCH', body }),
  del: (path) => request(path, { method: 'DELETE' }),
}
