import { PageHeader } from '../components/ui/PageHeader'
export function PlaceholderPage({ title, description }: { title: string; description: string }) {
  return <><PageHeader title={title} description={description}/><div className="card placeholder-card"><div className="skeleton-row"/><div className="skeleton-row short"/><div className="skeleton-grid">{Array.from({length:6}).map((_,i)=><div key={i} className="skeleton-box"/>)}</div></div></>
}
