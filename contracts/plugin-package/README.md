# TXBoard Plugin Package Contract

Plugin Package 是 TXBoard 与独立插件仓库之间的正式扩展协议边界。

当前版本：**Plugin Package v1**。

插件后端是受信任的进程内 PHP 扩展；插件 Admin UI 是插件自带的静态应用，由 TXBoard 以同源 iframe 加载并通过 Admin Bridge 提供运行时上下文。

## Package layout

推荐结构：

```text
YourPlugin/
├── Plugin.php
├── config.json
├── README.md
│
├── routes/
│   ├── api.php
│   └── web.php
│
├── Http/
├── Models/
├── Services/
│
├── database/
│   └── migrations/
│
└── admin/
    └── dist/                 # Plugin Package v1 Admin App
        ├── index.html
        ├── app.js
        └── styles.css
```

TXBoard **不会**在安装插件时运行 npm、Vite 或任意前端构建命令。独立插件仓库必须在发布 ZIP 前自行生成 `admin/dist`。

## Base manifest

`config.json` 的基础字段：

```json
{
  "name": "Example Plugin",
  "code": "example_plugin",
  "type": "feature",
  "version": "1.0.0",
  "description": "Example TXBoard plugin",
  "author": "Example Author",
  "package": {"schema": 1}
}
```

约束：

- `code`：仅允许 `[a-z0-9_]`
- `version`：SemVer 三段式 `x.y.z`
- `type`：当前支持 `feature`、`payment`

所有插件都必须声明整数 `package.schema: 1`，包括只提供支付或配置表单的插件。不声明 `package` 的旧包不再安装、启用或加载。配置完整示例与字段校验见 [schema-v1.json](schema-v1.json)。

## Declaring Plugin Package v1

所有插件必须声明 `package.schema`；自带 Admin App 可附加以下固定布局信息：

```json
{
  "package": {
    "schema": 1,
    "admin": {
      "format": "static-app",
      "dist": "admin/dist"
    }
  }
}
```

当前 `package.schema` 只支持 `1`。

## Admin menu app

复杂管理页面通过 `admin_menus[].app` 声明：

```json
{
  "admin_menus": [
    {
      "id": "dashboard",
      "title": "Dashboard",
      "path": "dashboard",
      "app": "admin/index.html#/dashboard"
    }
  ]
}
```

`app` 的 URL path 部分必须：

- 是相对路径；
- 位于 `admin/` 下；
- 指向 `.html`；
- 不允许协议、绝对路径、反斜杠或 `..`；
- 对应文件必须真实存在于包内 `admin/dist/`。

例如：

```text
manifest: admin/index.html#/dashboard
source:   admin/dist/index.html
public:   /plugins/example_plugin/admin/index.html#/dashboard
```

Hash/query 只属于插件应用自己的客户端路由，不改变静态文件边界。

## Host-rendered UI

简单插件不需要自带前端应用。

TXBoard 仍然支持宿主渲染：

- `config`：动态 Settings 表单；
- `admin_crud`：Schema-driven CRUD；
`admin_menus` 只声明静态应用页面，每项必须包含 `id`、`title`、安全相对 `path` 与 `app`；可选 `icon`、`order`、`description`。ID 和 path 必须唯一，不再从 path 生成 ID、从 label 补 title 或修剪路径。`component`、`url`、`embed`、`renderer` 和 `label` 入口已删除。Settings / CRUD 不需要制造菜单页或空的 Admin App。

## Asset publishing

安装、升级、启用或容器启动时，TXBoard 会将：

```text
plugin/admin/dist/*
        ↓
/www/public/plugins/{code}/admin/*
        ↓
/plugins/{code}/admin/*
```

`resources/assets` 的旧资源合并发布已移除；当前只发布 `admin/dist`。插件前端应使用**相对资源 URL**，例如：

```html
<script type="module" src="./assets/app.js"></script>
<link rel="stylesheet" href="./assets/app.css">
```

不要把部署域名或 TXBoard 根路径硬编码进 bundle。

## Admin Bridge v1

Plugin Package v1 Admin App 运行在 TXBoard Admin 创建的同源 iframe 中。

插件加载后发送：

```js
window.parent.postMessage(
  { type: 'txboard:plugin:ready', version: 1 },
  window.location.origin,
)
```

宿主回复：

```ts
{
  type: 'txboard:plugin:init',
  version: 1,
  plugin: {
    code: string,
    name?: string,
    version?: string
  },
  route: {
    path: string
  },
  api: {
    root: string,
    admin: string
  },
  auth: {
    authorization: string
  }
}
```

含义：

- `api.root`：同源根 API 基址，适用于插件自有 `/plugin/*` 等路径；
- `api.admin`：当前实例的 `/txapi/admin/{secure_path}` 前缀；
- `auth.authorization`：当前管理员 Bearer Authorization；
- `route.path`：当前宿主插件页面 path。

插件必须校验 `event.origin === window.location.origin`。

### Host navigation

插件可以请求宿主跳转到同一插件的其他页面：

```js
window.parent.postMessage(
  {
    type: 'txboard:plugin:navigate',
    version: 1,
    path: 'rules'
  },
  window.location.origin,
)
```

宿主只接受安全的相对插件 path；外部 URL 和目录穿越不会被执行。

## Admin Bridge v2 compatibility

Admin Bridge v1 remains the required compatibility baseline for Plugin Package v1.

Plugins may optionally negotiate the additive Admin Bridge v2 protocol using `txboard:module:ready` version `2`. Bridge v2 adds bounded host services such as navigation, toast, confirmation, refresh, Core entity deep links and theme context.

See [Admin Bridge Contract v2](../admin-bridge/README.md).

A v1-only plugin requires no manifest change or rebuild.

## Backend runtime

插件后端继续使用 TXBoard Plugin Runtime：

- `Plugin.php` 生命周期；
- routes；
- migrations；
- hooks；
- scheduled tasks；
- Artisan commands；
- plugin config；
- payment/feature plugin types。

插件 PHP 代码与 TXBoard 运行在同一 Laravel 进程中，因此它拥有应用级权限。

**只安装可信来源的插件包。** iframe 只隔离 Admin UI 的运行边界，并不能把恶意 PHP 插件变成安全代码。

## ZIP safety

TXBoard 对上传 ZIP 做基础安全限制：

- 最多 2000 个 entry；
- 解压后总大小最多 50 MB；
- 拒绝绝对路径；
- 拒绝 Windows drive absolute path；
- 拒绝 NUL；
- 拒绝 `..` 目录穿越；
- 拒绝符号链接；
- Admin App manifest 必须通过路径验证并存在。

这些限制不能替代代码审查或签名机制。

## Upgrade contract

插件升级包必须：

1. 保持相同 `code`；
2. `version` 高于已安装版本；
3. 包含完整的新版本文件，而非增量 patch；
4. 保证声明的 Admin App entry 存在；
5. migration 能从旧版本安全向前执行。

升级后 TXBoard 会重新发布插件静态资源。

## Independent repositories

Plugin Package v1 的目的就是允许插件拥有独立仓库和发布周期。

典型发布过程：

```text
plugin source repository
        ↓ build/test
plugin release ZIP
        ↓ upload
TXBoard Plugin Manager
        ↓
api/plugins/{Plugin}/
        ↓
install / migrate / publish admin assets
```

独立插件仓库不应要求 TXBoard 修改 `web/admin` 才能新增复杂管理页面。

## Reference implementation

[TXBoard-AccessAudit](https://github.com/ANRCM0/TXBoard-AccessAudit) 是 Plugin Package v1 的第一方参考实现。

它独立维护 PHP backend、migrations、routes、Schema 与 `admin/dist`，TXBoard Admin 不包含 AccessAudit 专属 React renderer，TXBoard 应用镜像也不打包 AccessAudit 源码。
