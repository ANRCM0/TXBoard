# TXBoard Theme Package Contract

第三方主题统一使用 **Theme Package v2 静态 SPA 包**，由 `ThemeV2Package` 验证、`ThemeV2Runtime` 安装和发布，`ThemeService` 维护唯一 `frontend_theme` 选择。旧 `config.json + dashboard.blade.php` 包、Blade namespace、旧上传分支和整包公开复制已删除；不提供旧格式适配。

系统内置 `TXBoard` 仍由现有用户 SPA 提供，`api/theme/TXBoard/config.json` 仅是系统自有展示配置，不是可上传的第三方包格式。

## Package layout

```text
txboard-theme.json
README.md                    # optional
theme.config.schema.json     # optional
dist/index.js
assets/                      # optional static assets
```

ZIP 在根目录或恰好一个顶层目录包含一个 `txboard-theme.json`。只允许 `dist/`、`assets/` 的受限静态文件和已列元数据。所有静态文件必须在 `assets` 清单中声明 SHA-256 SRI；运行时不执行 PHP、Blade、shell 或 SSR JavaScript。

- [JSON Schema](schema-v2.json)
- [Manifest example](examples/theme.json)
- [Admission validation](v2-validation.md)
- [Runtime, activation and rollback](v2-runtime.md)
- [Current HTTP integration](../http/theme-integration-current.md)

## Authority and configuration

包声明 identity、version、engines、SPA entry、asset digest、配置默认值与公开字段候选；installed、enabled、active 和 health 由运行时派生。Module Registry 只读委派 `ThemeService`，不重新实现安装逻辑。

`frontend_theme` 是唯一活动主题设置。主题配置保存在 `theme_{name}`。第三方公开字段必须同时在 manifest `config.public` 和服务端 `theme_v2.public_fields` allowlist 内；业务设置和凭据不属于主题字段。系统内置主题沿用其系统字段声明过滤。

上传只安装，不自动激活新主题。激活通过现有受审计设置完成；系统主题和当前活动主题不能卸载。升级、失败补偿和上一版本回滚由静态 runtime 负责。

ZIP 继续拒绝目录穿越、绝对路径、反斜杠、NUL、符号链接和大小写重复路径，限制 2000 entries 与 50 MiB 解压体积。启用前必须信任包作者：同源主题 JS 没有进程沙箱，SRI 也不是作者签名。

所有服务由容器管理，不引入 Octane 全局可变主题状态。
