import React from 'react'
import ReactDOM from 'react-dom/client'
import { QueryClientProvider } from '@tanstack/react-query'
import { RouterProvider } from 'react-router-dom'
import { Toaster } from 'sonner'
import { router } from './router'
import { queryClient } from './native/queryClient'
import './design-tokens.css'
import './styles.css'
import './form-system.css'
import './admin-experience.css'
import './admin-density.css'
import './admin-list-density.css'
import './admin-monitor-density.css'
import './native/native.css'

ReactDOM.createRoot(document.getElementById('root')!).render(
  <React.StrictMode>
    <QueryClientProvider client={queryClient}>
      <React.Suspense
        fallback={<div className='route-loading' role='status' aria-live='polite'><span />正在加载页面…</div>}
      >
        <RouterProvider router={router} future={{ v7_startTransition: true }} />
      </React.Suspense>
      <Toaster richColors position="top-right" />
    </QueryClientProvider>
  </React.StrictMode>,
)
