import { t, useLocale } from '../lib/i18n'
import { useQuery } from '@tanstack/react-query'
import { Modal } from '../components/ui/Modal'
import { admin } from './session'
import { ManagementFeedback } from './Feedback'
import { AccountProfileEditor } from './IdentityEditors'

/** A list row is not the current credential revision; read the protected profile before editing. */
export function AccountProfileDialog({ userId, locked, onClose, onChanged }: { userId: string; locked: boolean; onClose: () => void; onChanged: () => void }) {
  useLocale()
  const query = useQuery({ queryKey: ['native-admin', 'account-edit', userId],
    queryFn: ({ signal }) => admin.read('identity.accounts.get', { user_id: userId }, signal) })
  const user = query.isError ? undefined : query.data?.data
  if (user?.identity_editable && user.identity_revision) return <AccountProfileEditor key={user.id + ':' + user.identity_revision} user={user} locked={locked || query.isFetching} onClose={onClose} onChanged={onChanged} />
  return <Modal open title={t("编辑登录资料")} onClose={onClose}>
    <ManagementFeedback loading={query.isFetching} error={query.error} onRetry={() => void query.refetch()} />
    {user ? <p>{t("该账户受保护，不能通过普通资料操作修改。")}</p> : null}
    <button className="button" onClick={onClose}>{t("关闭")}</button>
  </Modal>
}
