import { api } from './api.js'

export const authService = {
  me: () => api.get('/auth/me'),
  login: (email, password) => api.post('/auth/login', { email, password }),
  register: (email, password, full_name) => api.post('/auth/register', { email, password, full_name }),
  logout: () => api.post('/auth/logout'),
  refresh: () => api.post('/auth/refresh'),
}
