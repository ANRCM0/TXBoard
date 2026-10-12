// Login page (GW-506): plain form POSTing to the server action above.
// No client-side SDK import, no token handling in the browser at all.
import { loginAction } from './actions'

export const dynamic = 'force-dynamic'

export default function LoginPage() {
  return (
    <main className="page">
      <form className="login" action={loginAction} noValidate>
        <h1>登录</h1>
        <label>邮箱地址
          <input name="email" type="email" autoComplete="off" required />
        </label>
        <label>密码
          <input name="password" type="password" autoComplete="off" required />
        </label>
        <button className="primary" type="submit">登录</button>
      </form>
    </main>
  )
}
