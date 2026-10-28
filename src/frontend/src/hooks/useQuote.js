import { useCallback, useEffect, useRef, useState } from 'react'
import { api } from '../services/api.js'

/**
 * Live price breakdown for the current cart.
 *
 * The server owns tax, shipping and discount maths; this hook just keeps a
 * local copy in sync so the Cart and Checkout screens show the same number the
 * order will actually be written with. A request id guards against a slow
 * response overwriting a newer one.
 */
export function useQuote({ country = 'US', region = '', couponCode = '', shippingCode = '' } = {}) {
  const [quote, setQuote] = useState(null)
  const [error, setError] = useState(null)
  const [loading, setLoading] = useState(false)
  const seq = useRef(0)

  const refresh = useCallback(async () => {
    const id = ++seq.current
    setLoading(true)
    setError(null)
    try {
      const { data } = await api.post('/quote', {
        country,
        region: region || undefined,
        coupon_code: couponCode || undefined,
        shipping_code: shippingCode || undefined,
      })
      if (id === seq.current) setQuote(data.quote)
    } catch (e) {
      if (id === seq.current) {
        setQuote(null)
        setError(e.message)
      }
    } finally {
      if (id === seq.current) setLoading(false)
    }
  }, [country, region, couponCode, shippingCode])

  useEffect(() => { refresh() }, [refresh])

  return { quote, error, loading, refresh }
}
