<?php

namespace Plugin\AccessAudit\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Plugin\AccessAudit\Models\AuditAccessLog;

class DashboardController extends Controller
{
    private const LOGS_PER_PAGE = 20;

    /**
     * 渲染访问审计主页，并注入主页快捷入口和访问日志手动操作。
     *
     * 不直接改动体积较大的 admin.blade.php，避免后续上游页面调整时产生大块冲突；
     * 所有增强都在原页面脚本加载完成后执行。
     */
    public function page()
    {
        $html = view('AccessAudit::admin')->render();

        $entryScript = <<<'HTML'
<script>
(function () {
  function installDashboardEnhancements() {
    // 数据分析与设置入口：优先放在页头时间后面；页头结构改变时退化为浮动按钮。
    if (!document.getElementById('aaInsightsEntry')) {
      const link = document.createElement('a');
      link.id = 'aaInsightsEntry';
      link.className = 'aa-btn aa-btn--ghost aa-btn--sm';
      link.href = '/plugin/access-audit/insights';
      link.title = '打开数据分析、排行和插件设置';
      link.style.textDecoration = 'none';
      link.innerHTML = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20V10M10 20V4M16 20v-6M22 20H2"/></svg><span>数据分析与设置</span>';

      const clock = document.getElementById('headClock');
      if (clock && clock.parentElement) {
        clock.insertAdjacentElement('afterend', link);
      } else {
        link.style.position = 'fixed';
        link.style.top = '18px';
        link.style.right = '18px';
        link.style.zIndex = '9999';
        link.style.background = '#fff';
        document.body.appendChild(link);
      }
    }

    // 访问日志操作区：显示固定分页数量，并提供管理员手动清空入口。
    if (!document.getElementById('aaLogActions')) {
      const exportBtn = document.querySelector('button[onclick="exportLogs()"]');
      const head = exportBtn && exportBtn.parentElement;
      if (exportBtn && head) {
        const actions = document.createElement('span');
        actions.id = 'aaLogActions';
        actions.style.display = 'inline-flex';
        actions.style.alignItems = 'center';
        actions.style.gap = '8px';
        actions.style.flexWrap = 'wrap';

        const pageSize = document.createElement('span');
        pageSize.className = 'aa-badge aa-badge--mute';
        pageSize.textContent = '每页固定 20 条';

        const clearBtn = document.createElement('button');
        clearBtn.type = 'button';
        clearBtn.className = 'aa-btn aa-btn--ghost aa-btn--sm';
        clearBtn.style.color = 'var(--aa-danger)';
        clearBtn.style.borderColor = 'var(--aa-danger-soft)';
        clearBtn.innerHTML = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6M10 10v6M14 10v6"/></svg><span>清空访问日志</span>';
        clearBtn.onclick = clearAccessLogs;

        head.insertBefore(actions, exportBtn);
        actions.appendChild(pageSize);
        actions.appendChild(clearBtn);
        actions.appendChild(exportBtn);
      }
    }
  }

  window.clearAccessLogs = async function () {
    if (!window.confirm('确认清空全部访问日志？\n\n该操作只清除访问日志明细，不会删除审计规则、命中记录或长期分析聚合数据，且无法撤销。')) {
      return;
    }

    const btn = document.querySelector('#aaLogActions button');
    const oldHtml = btn ? btn.innerHTML : '';
    if (btn) {
      btn.disabled = true;
      btn.textContent = '正在清空…';
    }

    try {
      const r = await api('/plugin/access-audit/logs/clear', {});
      if (r.error) {
        alert(r.error.message || '清空失败');
        return;
      }
      const d = r.data || {};
      alert(d.message || ('已清空 ' + (d.deleted || 0) + ' 条访问日志'));
      logsCurPage = 1;
      await loadLogs(1);
      await loadStats();
    } catch (e) {
      if (String(e && e.message) !== 'unauthorized') alert('清空失败，请稍后重试');
    } finally {
      if (btn) {
        btn.disabled = false;
        btn.innerHTML = oldHtml;
      }
    }
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', installDashboardEnhancements, { once: true });
  } else {
    installDashboardEnhancements();
  }
})();
</script>
HTML;

        if (str_contains($html, '</body>')) {
            $html = str_replace('</body>', $entryScript . "\n</body>", $html);
        } else {
            $html .= $entryScript;
        }

        return response($html);
    }

    /**
     * 访问日志分页查询。
     *
     * 每页固定 20 条，避免日志量大时单页高度失控；筛选条件与旧接口完全兼容。
     */
    public function logs(Request $request)
    {
        $query = AuditAccessLog::query()->orderByDesc('id');

        if ($request->filled('node_id')) {
            $query->where('node_id', (int) $request->input('node_id'));
        }
        if ($request->filled('user_id')) {
            $query->where('user_id', (int) $request->input('user_id'));
        }
        if ($request->filled('keyword')) {
            $kw = trim((string) $request->input('keyword'));
            $query->where('target', 'like', '%' . str_replace(['%', '_'], ['\\%', '\\_'], $kw) . '%');
        }
        if ($request->filled('from')) {
            $query->where('created_at', '>=', (int) $request->input('from'));
        }
        if ($request->filled('to')) {
            $query->where('created_at', '<=', (int) $request->input('to'));
        }
        if ($request->filled('matched')) {
            $query->where('matched', (int) $request->input('matched'));
        }

        $page = max(1, (int) $request->input('page', 1));
        $perPage = self::LOGS_PER_PAGE;
        $total = (clone $query)->count();
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);

        $logs = $query
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        $emails = User::query()
            ->whereIn('id', $logs->pluck('user_id')->unique())
            ->pluck('email', 'id');
        $nodeNames = \App\Models\Server::query()
            ->whereIn('id', $logs->pluck('node_id')->unique())
            ->pluck('name', 'id');

        return response()->json(['data' => [
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => $pages,
            'list' => $logs->map(fn ($l) => [
                'id' => $l->id,
                'node_id' => $l->node_id,
                'node_name' => $nodeNames[$l->node_id] ?? "节点 #{$l->node_id}",
                'user_id' => $l->user_id,
                'user_email' => $emails[$l->user_id] ?? "?#{$l->user_id}",
                'target' => $l->target,
                'source_ip' => $l->source_ip,
                'matched' => (bool) $l->matched,
                'created_at' => $l->created_at,
            ]),
        ]]);
    }

    /**
     * 管理员手动清空全部访问日志明细。
     *
     * 仅删除 audit_access_logs；审计规则、命中记录、封禁记录与分析聚合均保留。
     */
    public function clearLogs()
    {
        $deleted = (int) AuditAccessLog::query()->delete();

        return response()->json(['data' => [
            'deleted' => $deleted,
            'message' => "已清空 {$deleted} 条访问日志",
        ]]);
    }
}
