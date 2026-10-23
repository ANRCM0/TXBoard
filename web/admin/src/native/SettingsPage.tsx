import './settings-reference.css'
import { ConfigurationPage, type ConfigurationGroup } from './ConfigurationPage'

export function SettingsPage({ group = 'site' }: { group?: ConfigurationGroup }) {
  return <div className="settings-reference"><ConfigurationPage group={group} /></div>
}
