// Root layout: appearance-only theming from bootstrap (GW-506).
// Server component — no client-side token access anywhere.
import type { ReactNode } from 'react'
import '@/styles/tokens.css'
import '@/styles/base.css'

export const metadata = { title: 'TXBoard SSR 主题' }

export default function RootLayout({ children }: { children: ReactNode }) {
  return (
    <html lang="zh-CN">
      <body>
        <div className="app">
          <header className="top">
            <span className="brand">Theme</span>
            <nav>
              <a href="/">首页</a>
              <a href="/dashboard">用户中心</a>
              <a href="/orders">我的订单</a>
              <a href="/login">登录</a>
            </nav>
          </header>
          {children}
        </div>
      </body>
    </html>
  )
}
