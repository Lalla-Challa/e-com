import { create } from 'zustand'
import { api } from '../services/api.js'
import { authService } from '../services/auth.js'

export const useCart = create((set, get) => ({
  lines: [],
  subtotal: '0.00',
  itemCount: 0,
  loading: false,

  refresh: async () => {
    set({ loading: true })
    try {
      const { data } = await api.get('/cart')
      set({ lines: data.cart.lines, subtotal: data.cart.subtotal, itemCount: data.cart.item_count })
    } finally {
      set({ loading: false })
    }
  },

  add: async (productId, quantity = 1) => {
    const { data } = await api.post('/cart', { product_id: productId, quantity })
    set({ lines: data.cart.lines, subtotal: data.cart.subtotal, itemCount: data.cart.item_count })
  },

  setQuantity: async (itemId, quantity) => {
    await api.patch('/cart', { item_id: itemId, quantity })
    await get().refresh()
  },

  clear: async () => {
    await api.del('/cart')
    set({ lines: [], subtotal: '0.00', itemCount: 0 })
  },
}))

export const useAuth = create((set) => ({
  user: null,
  ready: false,

  init: async () => {
    try {
      const { data } = await authService.me()
      set({ user: data.user, ready: true })
    } catch {
      set({ user: null, ready: true })
    }
  },

  login: async (email, password) => {
    const { data } = await authService.login(email, password)
    set({ user: data.user })
    return data.user
  },

  register: async (email, password, fullName) => {
    const { data } = await authService.register(email, password, fullName)
    set({ user: data.user })
    return data.user
  },

  logout: async () => {
    try {
      await authService.logout()
    } finally {
      set({ user: null })
    }
  },
}))
