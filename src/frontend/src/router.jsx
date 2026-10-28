import { Routes, Route, Navigate } from 'react-router-dom'
import { Layout } from './components/Layout.jsx'
import { Home } from './pages/Home.jsx'
import { Shop } from './pages/Shop.jsx'
import { Product } from './pages/Product.jsx'
import { Cart } from './pages/Cart.jsx'
import { Checkout } from './pages/Checkout.jsx'
import { Account } from './pages/Account.jsx'
import { Auth } from './pages/Auth.jsx'
import { Reviews } from './pages/Reviews.jsx'
import { Page } from './pages/Page.jsx'
import { Dashboard } from './pages/admin/Dashboard.jsx'
import { Roles } from './pages/admin/Roles.jsx'
import { Tax } from './pages/admin/Tax.jsx'
import { Shipping } from './pages/admin/Shipping.jsx'
import { Currencies } from './pages/admin/Currencies.jsx'
import { Invoices } from './pages/admin/Invoices.jsx'
import { Attributes } from './pages/admin/Attributes.jsx'
import { PurchaseOrders } from './pages/admin/PurchaseOrders.jsx'
import { Returns } from './pages/admin/Returns.jsx'
import { Customers } from './pages/admin/Customers.jsx'
import { Promotions } from './pages/admin/Promotions.jsx'
import { Wishlist } from './pages/Wishlist.jsx'
import { CustomerReturns } from './pages/CustomerReturns.jsx'

export function AppRoutes() {
  return (
    <Layout>
      <Routes>
        <Route path="/" element={<Home />} />
        <Route path="/shop" element={<Shop />} />
        <Route path="/product/:slug" element={<Product />} />
        <Route path="/cart" element={<Cart />} />
        <Route path="/checkout" element={<Checkout />} />
        <Route path="/account" element={<Account />} />
        <Route path="/wishlist" element={<Wishlist />} />
        <Route path="/returns" element={<CustomerReturns />} />
        <Route path="/auth" element={<Auth />} />
        <Route path="/reviews" element={<Reviews />} />
        <Route path="/page/:slug" element={<Page />} />
        <Route path="/admin" element={<Dashboard />} />
        <Route path="/admin/roles" element={<Roles />} />
        <Route path="/admin/tax" element={<Tax />} />
        <Route path="/admin/shipping" element={<Shipping />} />
        <Route path="/admin/currencies" element={<Currencies />} />
        <Route path="/admin/invoices" element={<Invoices />} />
        <Route path="/admin/attributes" element={<Attributes />} />
        <Route path="/admin/purchase-orders" element={<PurchaseOrders />} />
        <Route path="/admin/returns" element={<Returns />} />
        <Route path="/admin/customers" element={<Customers />} />
        <Route path="/admin/promotions" element={<Promotions />} />
        <Route path="*" element={<Navigate to="/" replace />} />
      </Routes>
    </Layout>
  )
}
