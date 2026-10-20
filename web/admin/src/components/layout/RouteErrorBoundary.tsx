import { Component, type ErrorInfo, type ReactNode } from 'react'
import { AlertTriangle } from 'lucide-react'
import { Link, useLocation } from 'react-router-dom'

type Props = { children: ReactNode }
type State = { error: Error | null }

class ErrorBoundary extends Component<Props, State> {
  state: State = { error: null }

  static getDerivedStateFromError(error: Error): State {
    return { error }
  }

  componentDidCatch(error: Error, info: ErrorInfo) {
    if (import.meta.env.DEV) console.error('Admin route render failed', error, info.componentStack)
  }

  render() {
    if (!this.state.error) return this.props.children
    return <section className="card ui-route-error" role="alert">
      <span className="ui-route-error-icon" aria-hidden="true"><AlertTriangle size={24} /></span>
      <div className="ui-route-error-copy">
        <h1>页面加载失败</h1>
        <p>此页面的渲染出现异常，管理导航和其他页面仍可继续使用。</p>
        <div className="ui-route-error-actions">
          <button type="button" className="button primary" onClick={() => this.setState({ error: null })}>重试当前页面</button>
          <button type="button" className="button" onClick={() => window.location.reload()}>重新加载</button>
          <Link className="button" to="/">返回仪表盘</Link>
        </div>
        <details className="ui-route-error-detail">
          <summary>查看错误信息</summary>
          <code>{this.state.error.message || '未知渲染错误'}</code>
        </details>
      </div>
    </section>
  }
}

export function RouteErrorBoundary({ children }: Props) {
  const { pathname } = useLocation()
  return <ErrorBoundary key={pathname}>{children}</ErrorBoundary>
}
