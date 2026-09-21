# TXBoard Plugin Development Guide

TXBoard 插件是正式的扩展层。插件可以只提供 PHP 能力，也可以通过 Schema 让 TXBoard 自动生成后台 UI；复杂插件还可以使用 **Plugin Package v1** 自带完整的 Admin App。

跨仓库规范以 [Plugin Package Contract](../../../../contracts/plugin-package/README.md) 为准。

## 1. Plugin structure

推荐结构：

```text
YourPlugin/
├── Plugin.php
├── config.json
├── README.md
├── routes/
│   ├── api.php
│   └── web.php
├── Http/Controllers/
├── Models/
├── Services/
├── Commands/
├── database/migrations/
├── resources/assets/
└── admin/dist/               # optional Plugin Package v1 Admin App
```

目录名通常使用 PascalCase；manifest `code` 使用小写字母、数字与下划线。

## 2. Minimal config.json

```json
{
  "name": "My Plugin",
  "code": "my_plugin",
  "type": "feature",
  "version": "1.0.0",
  "description": "My TXBoard plugin",
  "author": "Your Name",
  "config": {
    "api_key": {
      "type": "string",
      "default": "",
      "label": "API Key"
    }
  }
}
```

支持的常用配置类型包括 `string`、`number`、`boolean`、`json`、`yaml`、`select` 等。后台 Settings 页面由 TXBoard 根据 Schema 生成。

## 3. Plugin.php

```php
<?php

namespace Plugin\MyPlugin;

use App\Services\Plugin\AbstractPlugin;

class Plugin extends AbstractPlugin
{
    public function boot(): void
    {
        $this->filter('guest_comm_config', function (array $config) {
            $config['my_plugin_enabled'] = true;
            return $config;
        });
    }
}
```

Plugin Runtime 负责加载启用插件并执行生命周期逻辑。

## 4. Routes and controllers

`routes/api.php`：

```php
<?php

use Illuminate\Support\Facades\Route;
use Plugin\MyPlugin\Http\Controllers\ExampleController;

Route::middleware(['api'])->prefix('/api/v1/plugin/my-plugin')->group(function () {
    Route::post('/example', [ExampleController::class, 'handle']);
});
```

管理员专用插件 API 可以在 `routes/web.php` 中使用 `admin` middleware：

```php
Route::middleware(['web', 'admin'])->group(function () {
    Route::get('/plugin/my-plugin/stats', [AdminController::class, 'stats']);
});
```

不要假设 `secure_path` 的值；如果需要访问 TXBoard Admin API，由 Admin Bridge 提供当前 prefix。

## 5. Database migrations

将 migration 放在：

```text
database/migrations/
```

安装时 TXBoard 会执行插件 migration；升级会继续运行尚未执行的 migration。

Migration 必须支持已有生产数据向前迁移，不要依赖清空表或重装插件。

## 6. Settings UI

仅配置项时，不要写 Admin App。

```json
{
  "config": {
    "enabled": {
      "type": "boolean",
      "default": true,
      "label": "启用功能"
    },
    "endpoint": {
      "type": "string",
      "default": "",
      "label": "Endpoint"
    }
  }
}
```

TXBoard 会自动生成 Settings 页面。

## 7. CRUD UI

标准表格/表单优先使用 `admin_crud`：

```json
{
  "admin_crud": {
    "rules": {
      "version": 1,
      "title": "Rules",
      "id_field": "id",
      "api": {
        "list": "/plugin/my-plugin/rules",
        "save": "/plugin/my-plugin/rules/save",
        "delete": "/plugin/my-plugin/rules/delete"
      },
      "columns": [
        { "key": "id", "title": "ID", "type": "number" },
        { "key": "name", "title": "Name", "searchable": true }
      ],
      "form": [
        { "name": "id", "type": "number", "hidden": true },
        { "name": "name", "type": "string", "label": "Name", "required": true }
      ]
    }
  }
}
```

这样插件无需维护自己的表格、分页和表单壳层。

## 8. Plugin Package v1 Admin App

只有 Dashboard、图表、可视化工作台等复杂页面才建议自带 Admin App。

Manifest：

```json
{
  "package": {
    "schema": 1,
    "admin": {
      "format": "static-app",
      "dist": "admin/dist"
    }
  },
  "admin_menus": [
    {
      "title": "Dashboard",
      "path": "dashboard",
      "app": "admin/index.html#/dashboard"
    }
  ]
}
```

插件 release ZIP 必须已经包含：

```text
admin/dist/index.html
admin/dist/assets/...
```

TXBoard 不会帮插件运行 npm 或 Vite。

React、Vue、Svelte、原生 JS 都可以，前提是输出纯静态文件并使用相对资源路径。

## 9. Admin Bridge v1

插件 App 启动后：

```js
const origin = window.location.origin

window.parent.postMessage(
  { type: 'txboard:plugin:ready', version: 1 },
  origin,
)

window.addEventListener('message', (event) => {
  if (event.origin !== origin) return

  const message = event.data
  if (message?.type !== 'txboard:plugin:init' || message.version !== 1) return

  const authorization = message.auth.authorization
  const rootApi = message.api.root
  const adminApi = message.api.admin

  // Example:
  fetch(rootApi + '/plugin/my-plugin/stats', {
    headers: { Authorization: authorization }
  })
})
```

跳到同一插件宿主页：

```js
window.parent.postMessage(
  {
    type: 'txboard:plugin:navigate',
    version: 1,
    path: 'rules'
  },
  origin,
)
```

完整字段和安全规则见 Plugin Package Contract。

## 10. Hooks

插件可以使用 `filter` 与 `listen` 扩展业务流程。

示例：

```php
$this->filter('guest_comm_config', function (array $config) {
    $config['my_setting'] = $this->getConfig('setting');
    return $config;
});

$this->listen('user.created', function ($user) {
    // plugin logic
});
```

Hook 属于应用级扩展点。使用前应通过当前源码或 `php artisan hook:list` 确认名称仍然存在。

## 11. Scheduled tasks

```php
use Illuminate\Console\Scheduling\Schedule;

public function schedule(Schedule $schedule): void
{
    $schedule->call(function () {
        // cleanup / aggregation
    })->hourly()->onOneServer();
}
```

TXBoard 会为已启用插件注册 scheduler。

## 12. Artisan commands

插件可以在 `Commands/` 中提供命令。命令类应使用独立命名空间，例如：

```text
my-plugin:sync
my-plugin:check
```

插件禁用后，不应依赖这些命令继续维持核心业务。

## 13. Lifecycle

典型生命周期：

```text
ZIP upload
   ↓
package validation
   ↓
copy to api/plugins/{Plugin}
   ↓
install
   ├── migrations
   ├── default config
   └── publish assets
   ↓
enable
   ├── service provider
   ├── routes
   ├── views
   ├── commands
   └── boot()
```

升级要求新版本号高于旧版本，并重新发布静态资源。

## 14. Security model

PHP 插件运行在 TXBoard Laravel 进程内，拥有高权限。**插件不是沙箱代码。**

安装前必须信任其来源并审查代码。

TXBoard 对 ZIP 做路径穿越、符号链接、entry 数和解压体积检查，但这些只解决包格式风险，不代表插件业务代码安全。

Plugin Admin App 运行在 iframe 中，用于隔离 UI 生命周期和依赖，而不是为恶意 PHP 插件提供安全边界。

## 15. Packaging

推荐独立插件仓库的发布流程：

```text
source
  ↓ tests
frontend build
  ↓
admin/dist
  ↓
release ZIP
  ↓
TXBoard upload
```

ZIP 根目录可以直接是插件目录，也可以包含一层插件目录；必须能找到 `config.json`。

## 16. Reference plugin

AccessAudit 2.4.x 是 Plugin Package v1 的参考实现：

- PHP backend / migrations / routes 位于插件包；
- CRUD/Settings 使用宿主 Schema；
- Dashboard / Analytics 位于插件自己的 `admin/dist`；
- TXBoard Admin 不包含 AccessAudit 专属 React 源码。
