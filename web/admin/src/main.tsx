import React from 'react'
import ReactDOM from 'react-dom/client'
import { QueryClientProvider } from '@tanstack/react-query'
import { RouterProvider } from 'react-router-dom'
import { Toaster } from 'sonner'
import { router } from './router'
import { queryClient } from './native/queryClient'
import { t, useLocale } from './lib/i18n'
import '@fontsource-variable/dm-sans'
import '@fontsource-variable/manrope'
import '@fontsource-variable/jetbrains-mono'
import './design-tokens.css'
import './styles.css'
import './form-system.css'
import './admin-experience.css'
import './admin-density.css'
import './admin-list-density.css'
import './admin-monitor-density.css'
import './native/native.css'
import './admin-theme.css'
import './components/ui/ui.css'
import './components/ui/form-dialog.css'
import './admin-refresh.css'
import './admin-monochrome.css'
import './admin-refinement.css'

function RouteLoading() {
  useLocale()
  return <div className='route-loading' role='status' aria-live='polite'><span />{t('正在加载页面…')}</div>
}

ReactDOM.createRoot(document.getElementById('root')!).render(
  <React.StrictMode>
    <QueryClientProvider client={queryClient}>
      <React.Suspense
        fallback={<RouteLoading />}
      >
        <RouterProvider router={router} future={{ v7_startTransition: true }} />
      </React.Suspense>
      <Toaster richColors position="top-right" />
    </QueryClientProvider>
  </React.StrictMode>,
)
