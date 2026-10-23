import { useState } from 'react'

export function SiteMark({ logo }: { logo: string | null }) {
  const [failed, setFailed] = useState('')
  return <div className="admin-brand-mark" aria-hidden="true">
    {logo && failed !== logo ? <img src={logo} alt="" referrerPolicy="no-referrer" onError={() => setFailed(logo)} /> : 'tx'}
  </div>
}
