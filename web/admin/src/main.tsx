import React from 'react'
import ReactDOM from 'react-dom/client'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { RouterProvider } from 'react-router-dom'
import { Toaster } from 'sonner'
import { router } from './router'
import './styles.css'

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 30_000,
      retry: (failureCount, error) => {
        const status = (error as { response?: { status?: number } })?.response?.status
        return failureCount < 1 && (status === undefined || status >= 500)
      },
      refetchOnWindowFocus: false,
    },
  },
})

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
