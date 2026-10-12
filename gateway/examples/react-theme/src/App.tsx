// App shell: brand, nav, routes (GW-505).
import { BrowserRouter, Routes, Route, Link, useNavigate } from 'react-router-dom'
import { useAuth } from './hooks/useAuth'
import { HomePage } from './pages/HomePage'
import { LoginPage } from './pages/LoginPage'
import { DashboardPage } from './pages/DashboardPage'
import { OrdersPage } from './pages/OrdersPage'

function Shell() {
  const auth = useAuth()
  const navigate = useNavigate()
  return (
    <div className="app">
      <header className="top">
        <span className="brand">Theme</span>
        <nav>
          <Link to="/">首页</Link>
          {auth.isAuthed && <Link to="/dashboard">用户中心</Link>}
          {auth.isAuthed && <Link to="/orders">我的订单</Link>}
          {!auth.isAuthed && <Link to="/login">登录</Link>}
          {auth.isAuthed && <button className="link" onClick={auth.logout}>退出</button>}
        </nav>
      </header>
      <Routes>
        <Route path="/" element={<HomePage />} />
        <Route path="/login" element={<LoginPage onDone={() => navigate('/dashboard')} />} />
        <Route path="/dashboard" element={<DashboardPage />} />
        <Route path="/orders" element={<OrdersPage />} />
        <Route path="/plans" element={<HomePage />} />
      </Routes>
    </div>
  )
}

export default function App() {
  return (
    <BrowserRouter>
      <Shell />
    </BrowserRouter>
  )
}
