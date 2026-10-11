// Version-incompatibility refusal page (GW-507): rendered when the three
// version planes disagree. The theme refuses — it never downgrades.
export default function VersionIncompatible() {
  return (
    <main className="page">
      <div className="error-state" role="alert">
        <strong>版本不兼容</strong>
        <p>本主题与当前 Gateway / SDK 版本不兼容，已停止运行。</p>
        <p className="muted">请联系管理员升级主题或网关组件；系统不会降级到不兼容版本继续运行。</p>
      </div>
    </main>
  )
}
