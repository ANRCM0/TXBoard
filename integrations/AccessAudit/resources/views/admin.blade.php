<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>访问审计</title>
  <style>
    /* ══════════════════════════════════════════════════════════
       设计 token
       所有组件类统一 aa- 前缀，避免与面板宿主页样式互相污染。
       参考图配色：主色蓝、成功绿、警告琥珀、危险红，卡片白底细边框。
       ══════════════════════════════════════════════════════════ */
    :root {
      --aa-bg: #f7f8fa;
      --aa-surface: #ffffff;
      --aa-line: #e5e7eb;
      --aa-line-strong: #d3d1c7;
      --aa-text: #1f2937;
      --aa-text-2: #5f5e5a;
      --aa-text-3: #888780;

      --aa-primary: #185fa5;
      --aa-primary-soft: #e6f1fb;
      --aa-primary-line: #85b7eb;
      --aa-success: #0f6e56;
      --aa-success-soft: #e1f5ee;
      --aa-success-mid: #1d9e75;
      --aa-warn: #ba7517;
      --aa-warn-soft: #faeeda;
      --aa-warn-mid: #ef9f27;
      --aa-danger: #a32d2d;
      --aa-danger-soft: #fcebeb;
      --aa-danger-mid: #e24b4a;
      --aa-purple: #534ab7;
      --aa-purple-soft: #eeedfe;
      --aa-gray-soft: #f1efe8;

      --aa-r-card: 10px;
      --aa-r-ctrl: 6px;
      --aa-gap: 16px;
    }

    * { box-sizing: border-box; }

    body {
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'PingFang SC', 'Microsoft YaHei', sans-serif;
      margin: 0;
      background: var(--aa-bg);
      color: var(--aa-text);
      font-size: 13px;
      line-height: 1.6;
      -webkit-font-smoothing: antialiased;
    }

    .aa-wrap { max-width: 1180px; margin: 0 auto; padding: 24px; }

    /* ── 卡片 ── */
    .aa-card {
      background: var(--aa-surface);
      border: 1px solid var(--aa-line);
      border-radius: var(--aa-r-card);
      padding: 18px 20px;
      margin-bottom: var(--aa-gap);
    }
    .aa-card-head {
      display: flex; align-items: center; justify-content: space-between;
      gap: 12px; margin-bottom: 14px; flex-wrap: wrap;
    }
    .aa-card-title { font-size: 13px; font-weight: 500; display: flex; align-items: center; gap: 8px; }
    .aa-card-title .aa-ico { color: var(--aa-text-3); display: inline-flex; }

    /* ── 表单控件（统一规格）── */
    .aa-wrap input:not([type=checkbox]):not([type=radio]),
    .aa-wrap select,
    .aa-wrap textarea {
      padding: 7px 11px;
      border: 1px solid var(--aa-line-strong);
      border-radius: var(--aa-r-ctrl);
      font-size: 13px;
      font-family: inherit;
      color: var(--aa-text);
      background: var(--aa-surface);
      height: 34px;
      transition: border-color .12s, box-shadow .12s;
    }
    .aa-wrap textarea { height: auto; min-height: 76px; width: 100%; resize: vertical; }
    .aa-wrap input:focus, .aa-wrap select:focus, .aa-wrap textarea:focus {
      outline: none;
      border-color: var(--aa-primary-line);
      box-shadow: 0 0 0 3px var(--aa-primary-soft);
    }
    .aa-wrap input::placeholder { color: var(--aa-text-3); }
    .aa-wrap select { cursor: pointer; }

    /* ── 按钮 ── */
    .aa-btn {
      display: inline-flex; align-items: center; justify-content: center; gap: 6px;
      height: 34px; padding: 0 15px;
      border: 1px solid transparent;
      border-radius: var(--aa-r-ctrl);
      font-size: 13px; font-family: inherit; font-weight: 400;
      cursor: pointer; white-space: nowrap;
      background: var(--aa-primary); color: #fff;
      transition: filter .12s, background .12s;
    }
    .aa-btn:hover:not(:disabled) { filter: brightness(1.1); }
    .aa-btn:disabled { opacity: .5; cursor: not-allowed; }
    .aa-btn--danger { background: var(--aa-danger); }
    .aa-btn--secondary { background: var(--aa-text-2); }
    .aa-btn--ghost { background: var(--aa-surface); color: var(--aa-text-2); border-color: var(--aa-line-strong); }
    .aa-btn--ghost:hover:not(:disabled) { background: var(--aa-gray-soft); filter: none; }
    .aa-btn--sm { height: 28px; padding: 0 10px; font-size: 12px; }

    /* ── 徽章 ── */
    .aa-badge {
      display: inline-flex; align-items: center; gap: 5px;
      padding: 2px 9px; border-radius: 999px;
      font-size: 12px; white-space: nowrap; line-height: 1.7;
    }
    .aa-badge--ok    { background: var(--aa-success-soft); color: var(--aa-success); }
    .aa-badge--no    { background: var(--aa-danger-soft);  color: var(--aa-danger); }
    .aa-badge--warn  { background: var(--aa-warn-soft);    color: var(--aa-warn); }
    .aa-badge--info  { background: var(--aa-primary-soft); color: var(--aa-primary); }
    .aa-badge--mute  { background: var(--aa-gray-soft);    color: var(--aa-text-2); }
    /* 状态圆点：视觉上比纯文字徽章更轻，用于表格内高频出现 */
    .aa-dot { width: 6px; height: 6px; border-radius: 50%; display: inline-block; flex: none; }
    .aa-dot--ok { background: var(--aa-success-mid); }
    .aa-dot--no { background: var(--aa-danger-mid); }
    .aa-dot--warn { background: var(--aa-warn-mid); }

    /* ── 页头 ── */
    .aa-pagehead {
      display: flex; align-items: flex-start; justify-content: space-between;
      gap: 16px; margin-bottom: 18px; flex-wrap: wrap;
    }
    .aa-pagehead-main { display: flex; gap: 12px; align-items: flex-start; }
    .aa-pagehead-ico {
      width: 40px; height: 40px; flex: none;
      border-radius: 10px;
      background: var(--aa-primary);
      display: flex; align-items: center; justify-content: center;
      color: #fff;
    }
    .aa-pagehead h1 { font-size: 17px; font-weight: 500; margin: 0 0 2px; line-height: 1.4; }
    .aa-pagehead p { font-size: 12px; color: var(--aa-text-3); margin: 0; }
    .aa-pagehead-meta { font-size: 12px; color: var(--aa-text-3); padding-top: 4px; white-space: nowrap; }

    /* ── 统计卡 ── */
    .aa-stats {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
      gap: 12px;
      margin-bottom: var(--aa-gap);
    }
    .aa-stat {
      background: var(--aa-surface);
      border: 1px solid var(--aa-line);
      border-radius: var(--aa-r-card);
      padding: 14px 16px;
      min-width: 0;
    }
    .aa-stat-top { display: flex; align-items: center; gap: 7px; margin-bottom: 8px; }
    .aa-stat-ico {
      width: 22px; height: 22px; flex: none; border-radius: 6px;
      display: flex; align-items: center; justify-content: center;
    }
    .aa-stat-ico--blue   { background: var(--aa-primary-soft); color: var(--aa-primary); }
    .aa-stat-ico--green  { background: var(--aa-success-soft); color: var(--aa-success); }
    .aa-stat-ico--amber  { background: var(--aa-warn-soft);    color: var(--aa-warn); }
    .aa-stat-ico--red    { background: var(--aa-danger-soft);  color: var(--aa-danger); }
    .aa-stat-ico--purple { background: var(--aa-purple-soft);  color: var(--aa-purple); }
    .aa-stat-label { font-size: 12px; color: var(--aa-text-3); }
    .aa-stat-num {
      font-size: 24px; font-weight: 500; line-height: 1.25;
      color: var(--aa-text); font-variant-numeric: tabular-nums;
    }
    .aa-stat-num small { font-size: 14px; color: var(--aa-text-3); font-weight: 400; }
    .aa-stat-foot {
      margin-top: 6px; font-size: 12px; min-height: 20px;
      display: flex; align-items: center; gap: 6px;
    }
    .aa-stat-foot .aa-up { color: var(--aa-danger); }     /* 命中/封禁升高＝坏事，用红 */
    .aa-stat-foot .aa-down { color: var(--aa-success); }  /* 降低＝好事，用绿 */
    .aa-stat-foot .aa-mute { color: var(--aa-text-3); }
    .aa-spark { display: block; width: 100%; height: 30px; margin: 2px 0 4px; }

    /* 进度条 */
    .aa-bar { height: 4px; border-radius: 999px; background: var(--aa-gray-soft); overflow: hidden; margin-top: 8px; }
    .aa-bar > i { display: block; height: 100%; border-radius: 999px; background: var(--aa-primary); transition: width .3s; }
    .aa-bar--green > i { background: var(--aa-success-mid); }
    .aa-bar--purple > i { background: var(--aa-purple); }

    /* ── Tab ── */
    .aa-tabs {
      display: flex; gap: 2px; overflow-x: auto;
      border-bottom: 1px solid var(--aa-line);
      margin-bottom: var(--aa-gap);
    }
    .aa-tabs button {
      background: none; border: 0; border-bottom: 2px solid transparent;
      color: var(--aa-text-3); font-size: 13px; font-family: inherit;
      padding: 9px 16px; margin-bottom: -1px; cursor: pointer;
      white-space: nowrap; transition: color .12s, border-color .12s;
    }
    .aa-tabs button:hover { color: var(--aa-text); }
    .aa-tabs button.active { color: var(--aa-primary); border-bottom-color: var(--aa-primary); font-weight: 500; }
    .aa-pane { display: none; }
    .aa-pane.active { display: block; }

    /* ── 筛选区 ── */
    .aa-filters { display: flex; flex-direction: column; gap: 10px; margin-bottom: 14px; }
    .aa-filter-row { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
    .aa-filter-row .aa-spacer { flex: 1 1 auto; }
    .aa-field { display: flex; align-items: center; gap: 7px; min-width: 0; }
    .aa-field > label { font-size: 12px; color: var(--aa-text-3); white-space: nowrap; }
    .aa-sep { color: var(--aa-text-3); font-size: 12px; }

    /* ── 提示条 ── */
    .aa-hint {
      font-size: 12px; color: var(--aa-text-3);
      background: var(--aa-bg);
      border-radius: var(--aa-r-ctrl);
      padding: 8px 12px; margin-bottom: 14px; line-height: 1.7;
    }
    .aa-hint b { color: var(--aa-text-2); font-weight: 500; }
    .aa-hint code {
      font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
      font-size: 11px; background: var(--aa-surface);
      padding: 1px 5px; border-radius: 4px; border: 1px solid var(--aa-line);
    }

    /* ── 表格 ── */
    .aa-tablebox { overflow-x: auto; border: 1px solid var(--aa-line); border-radius: var(--aa-r-card); }
    .aa-wrap table { width: 100%; border-collapse: separate; border-spacing: 0; font-size: 13px; }
    .aa-wrap thead th {
      background: var(--aa-bg);
      color: var(--aa-text-3);
      font-weight: 500; font-size: 12px;
      text-align: left; white-space: nowrap;
      padding: 10px 12px;
      border-bottom: 1px solid var(--aa-line);
      position: sticky; top: 0; z-index: 1;
    }
    .aa-wrap tbody td {
      padding: 11px 12px;
      border-bottom: 1px solid var(--aa-line);
      vertical-align: middle;
    }
    .aa-wrap tbody tr:last-child td { border-bottom: 0; }
    .aa-wrap tbody tr:nth-child(even) { background: #fafbfc; }
    .aa-wrap tbody tr:hover { background: var(--aa-primary-soft); }
    .aa-wrap tbody tr.editing { background: var(--aa-warn-soft); }
    .aa-empty { text-align: center; color: var(--aa-text-3); padding: 28px 12px !important; }

    /* 单元格辅助 */
    .aa-mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 12px; }
    .aa-nowrap { white-space: nowrap; }
    .aa-muted { color: var(--aa-text-3); }
    .aa-target {
      max-width: 260px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
      display: inline-block; vertical-align: middle;
    }
    /* 节点显示：节点 ID 仅用于识别，不再用随机颜色表达状态 */
    .aa-nodecell { display: inline-flex; align-items: center; gap: 7px; white-space: nowrap; }
    .aa-nodeid {
      display: inline-flex; align-items: center;
      padding: 1px 6px;
      border: 1px solid var(--aa-line);
      border-radius: 999px;
      background: var(--aa-gray-soft);
      font-size: 11px; line-height: 1.5;
      color: var(--aa-text-3);
      font-family: ui-monospace, monospace;
    }
    .aa-actions { display: flex; gap: 6px; white-space: nowrap; }

    /* 表格列宽策略：状态/操作这类短列收窄，把宽度让给时间与目标 */
.aa-tablebox table { table-layout: auto; }
.aa-col-right { text-align: right; }
.aa-tablebox td .aa-target { max-width: 300px; }

/* ── 分页器 ── */
    .aa-pager {
      display: flex; align-items: center; justify-content: space-between;
      gap: 12px; flex-wrap: wrap; margin-top: 14px;
    }
    .aa-pager-info { font-size: 12px; color: var(--aa-text-3); }
    .aa-pager-pages { display: flex; align-items: center; gap: 4px; }
    .aa-page {
      min-width: 30px; height: 30px; padding: 0 8px;
      display: inline-flex; align-items: center; justify-content: center;
      border: 1px solid var(--aa-line-strong); border-radius: var(--aa-r-ctrl);
      background: var(--aa-surface); color: var(--aa-text-2);
      font-size: 12px; font-family: inherit; cursor: pointer;
    }
    .aa-page:hover:not(:disabled):not(.active) { background: var(--aa-gray-soft); }
    .aa-page.active { background: var(--aa-primary); border-color: var(--aa-primary); color: #fff; font-weight: 500; }
    .aa-page:disabled { opacity: .4; cursor: not-allowed; }
    .aa-page--dots { border: 0; background: none; cursor: default; color: var(--aa-text-3); }

    /* ── 消息 ── */
    .aa-msg { margin-top: 10px; padding: 9px 13px; border-radius: var(--aa-r-ctrl); font-size: 13px; display: none; }
    .aa-msg.ok  { display: block; background: var(--aa-success-soft); color: var(--aa-success); }
    .aa-msg.err { display: block; background: var(--aa-danger-soft);  color: var(--aa-danger); }

    /* ── 规则表单 ──
       两段式布局：第一行 5 个字段（名称/类型/阈值/窗口/备注 + 按钮），
       第二行整行 textarea。之所以不把 textarea 塞进同一个 grid，
       是因为它跨全列会把按钮挤到第三行，视觉上很突兀。 */
    .aa-formgrid {
      display: grid;
      grid-template-columns: 1.5fr 1fr 0.85fr 0.85fr 1.1fr auto;
      gap: 10px; align-items: center;
    }
    .aa-formgrid .aa-full { grid-column: 1 / -1; }
    .aa-formgrid input, .aa-formgrid select { width: 100%; min-width: 0; }
    .aa-editbar { display: flex; align-items: center; gap: 8px; margin-top: 10px; }

    /* ── 登录 ── */
    .aa-login { max-width: 380px; margin: 12vh auto 0; }
    .aa-login h2 { margin: 0 0 4px; font-size: 17px; font-weight: 500; display: flex; align-items: center; gap: 8px; }
    .aa-login .aa-hint { margin: 10px 0 14px; }
    .aa-login input { width: 100%; margin-bottom: 10px; }
    .aa-login .aa-btn { width: 100%; }

    /* 顶栏用户区 */
    .aa-userbar { display: flex; align-items: center; gap: 10px; }
    .aa-avatar {
      width: 28px; height: 28px; border-radius: 50%;
      background: var(--aa-primary-soft); color: var(--aa-primary);
      display: flex; align-items: center; justify-content: center;
      font-size: 12px; font-weight: 500;
    }

    /* ── 响应式 ── */
    @media (max-width: 860px) {
      .aa-wrap { padding: 16px; }
      .aa-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
      /* 规则表单：名称+类型一行，阈值+窗口一行，备注+按钮一行 */
      .aa-formgrid { grid-template-columns: 1fr 1fr; }
      .aa-pagehead-meta { display: none; }
      .aa-filter-row .aa-field { flex: 1 1 46%; }
      .aa-filter-row .aa-spacer { display: none; }
      .aa-filter-row .aa-field input, .aa-filter-row .aa-field select { width: 100%; }
    }
    @media (max-width: 560px) {
      .aa-stats { grid-template-columns: minmax(0, 1fr); }
      .aa-formgrid { grid-template-columns: 1fr; }
      .aa-pager { justify-content: center; }
      .aa-filter-row .aa-field { flex: 1 1 100%; }
    }
  </style>
</head>
<body>

<!-- 登录 -->
<div class="aa-wrap" id="loginWrap" style="display:none">
  <div class="aa-card aa-login">
    <h2>
      <span class="aa-pagehead-ico" style="width:28px;height:28px;border-radius:8px">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
      </span>
      访问审计
    </h2>
    <div class="aa-hint">使用 Xboard 管理员账号登录。登录状态保存在本浏览器（localStorage），点「退出」清除。</div>
    <input id="loginEmail" type="email" placeholder="管理员邮箱" autocomplete="username" />
    <input id="loginPassword" type="password" placeholder="密码" autocomplete="current-password" onkeydown="if(event.key==='Enter')login()" />
    <button class="aa-btn" onclick="login()">登 录</button>
    <div id="loginMsg" class="aa-msg"></div>
  </div>
</div>

<!-- 工作区 -->
<div class="aa-wrap" id="workspace" style="display:none">

  <div class="aa-pagehead">
    <div class="aa-pagehead-main">
      <div class="aa-pagehead-ico">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
      </div>
      <div>
        <h1>访问审计</h1>
        <p>实时监控节点访问记录、规则命中及封禁状态，保障网络安全。</p>
      </div>
    </div>
    <div style="display:flex;align-items:center;gap:14px">
      <span class="aa-pagehead-meta" id="headClock"></span>
      <span class="aa-userbar">
        <span class="aa-avatar" id="userAvatar"></span>
        <span style="font-size:12px;color:var(--aa-text-2)" id="userLabel"></span>
        <button class="aa-btn aa-btn--ghost aa-btn--sm" onclick="logout()">退出</button>
      </span>
    </div>
  </div>

  <div class="aa-stats">
    <div class="aa-stat">
      <div class="aa-stat-top">
        <span class="aa-stat-ico aa-stat-ico--blue">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 20V10M10 20V4M16 20v-6M22 20H2"/></svg>
        </span>
        <span class="aa-stat-label">启用规则</span>
      </div>
      <div class="aa-stat-num" id="sRules">-</div>
      <div class="aa-bar"><i id="sRulesBar" style="width:0%"></i></div>
      <div class="aa-stat-foot"><span class="aa-mute" id="sRulesPct">—</span></div>
    </div>

    <div class="aa-stat">
      <div class="aa-stat-top">
        <span class="aa-stat-ico aa-stat-ico--blue">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M3 17l5-5 4 4 8-8"/><path d="M14 8h6v6"/></svg>
        </span>
        <span class="aa-stat-label">今日访问</span>
      </div>
      <div class="aa-stat-num" id="sLogsToday">-</div>
      <svg class="aa-spark" id="sTrendSvg" preserveAspectRatio="none" viewBox="0 0 100 30"></svg>
      <div class="aa-stat-foot" id="sLogsDelta"><span class="aa-mute">较昨日 —</span></div>
    </div>

    <div class="aa-stat">
      <div class="aa-stat-top">
        <span class="aa-stat-ico aa-stat-ico--amber">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
        </span>
        <span class="aa-stat-label">今日命中</span>
      </div>
      <div class="aa-stat-num" id="sReportsToday">-</div>
      <div class="aa-stat-foot" id="sReportsDelta"><span class="aa-mute">较昨日 —</span></div>
    </div>

    <div class="aa-stat">
      <div class="aa-stat-top">
        <span class="aa-stat-ico aa-stat-ico--red">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M5.6 5.6l12.8 12.8"/></svg>
        </span>
        <span class="aa-stat-label">封禁用户</span>
      </div>
      <div class="aa-stat-num" id="sBans">-</div>
      <div class="aa-stat-foot"><span class="aa-mute" id="sBansHint">—</span></div>
    </div>

    <div class="aa-stat">
      <div class="aa-stat-top">
        <span class="aa-stat-ico aa-stat-ico--purple">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg>
        </span>
        <span class="aa-stat-label">在线节点</span>
      </div>
      <div class="aa-stat-num" id="sNodes">-</div>
      <div class="aa-bar aa-bar--purple"><i id="sNodesBar" style="width:0%"></i></div>
      <div class="aa-stat-foot"><span class="aa-mute" id="sNodesPct">—</span></div>
    </div>
  </div>

  <div class="aa-tabs">
    <button class="active" data-tab="logs" onclick="switchTab('logs')">访问日志</button>
    <button data-tab="rules" onclick="switchTab('rules')">审计规则</button>
    <button data-tab="reports" onclick="switchTab('reports')">命中记录</button>
    <button data-tab="nodes" onclick="switchTab('nodes')">节点状态</button>
    <button data-tab="bans" onclick="switchTab('bans')">封禁管理</button>
  </div>

  <!-- 访问日志（全量，report_all 节点上报） -->
  <div class="aa-pane active" id="pane-logs">
    <div class="aa-card">
      <div class="aa-card-head">
        <span class="aa-card-title">
          <span class="aa-ico"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 6h16M4 12h16M4 18h10"/></svg></span>
          访问日志
        </span>
        <button class="aa-btn aa-btn--ghost aa-btn--sm" onclick="exportLogs()">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 3v12M7 11l5 5 5-5M4 21h16"/></svg>
          导出 CSV
        </button>
      </div>

      <div class="aa-filters">
        <div class="aa-filter-row">
          <div class="aa-field">
            <label>节点</label>
            <select id="lNodeId" style="width:150px"><option value="">全部节点</option></select>
          </div>
          <div class="aa-field">
            <label>用户</label>
            <input id="lUserId" type="number" placeholder="用户 ID" style="width:110px" />
          </div>
          <div class="aa-field">
            <label>关键词</label>
            <input id="lKeyword" placeholder="目标域名 / IP" style="width:170px" />
          </div>
          <div class="aa-field">
            <label>状态</label>
            <select id="lMatched" style="width:120px">
              <option value="">全部</option>
              <option value="1">仅命中</option>
              <option value="0">仅未命中</option>
            </select>
          </div>
        </div>
        <div class="aa-filter-row">
          <div class="aa-field">
            <label>时间</label>
            <input id="lFrom" type="datetime-local" style="width:190px" title="起始时间" />
            <span class="aa-sep">→</span>
            <input id="lTo" type="datetime-local" style="width:190px" title="结束时间" />
          </div>
          <span class="aa-spacer"></span>
          <button class="aa-btn" onclick="loadLogs(1)">查询</button>
          <button class="aa-btn aa-btn--ghost" onclick="resetLogFilter()">重置</button>
        </div>
      </div>

      <div class="aa-hint">
        仅显示开启 <code>report_all</code> 的节点上报的全量日志；按插件配置保留天数自动清理（默认 3 天，当前 <b id="logsRetentionHint">3</b> 天）。命中记录请见「命中记录」tab。
      </div>

      <div class="aa-tablebox">
        <table>
          <thead><tr>
            <th>时间</th><th>节点</th><th>用户</th><th>目标</th><th>目标 IP</th><th>来源 IP</th><th>状态</th>
          </tr></thead>
          <tbody id="logsBody"></tbody>
        </table>
      </div>

      <div class="aa-pager">
        <span class="aa-pager-info" id="logsTotal"></span>
        <span class="aa-pager-pages" id="logsPager"></span>
      </div>
    </div>
  </div>

  <!-- 规则 -->
  <div class="aa-pane" id="pane-rules">
    <div class="aa-card">
      <div class="aa-card-head">
        <span class="aa-card-title">
          <span class="aa-ico"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 6h16M4 12h16M4 18h16"/><circle cx="8" cy="6" r="2" fill="currentColor" stroke="none"/></svg></span>
          <span id="ruleFormTitle">添加规则</span>
        </span>
      </div>

      <div class="aa-hint">
        匹配类型：<b>domain</b> 精确域名 · <b>domain_suffix</b> 域名后缀（含子域名，如 <code>example.com</code> 命中 <code>a.example.com</code>）·
        <b>keyword</b> 目标包含关键字 · <b>ip_cidr</b> IP 段（<code>1.2.3.0/24</code>）。
        匹配值每行一个。阈值/窗口留空用全局默认（插件配置里改）。
      </div>

      <input type="hidden" id="rId" />
      <div class="aa-formgrid">
        <input id="rName" placeholder="规则名称 *" />
        <select id="rType">
          <option value="domain_suffix">domain_suffix</option>
          <option value="domain">domain</option>
          <option value="keyword">keyword</option>
          <option value="ip_cidr">ip_cidr</option>
        </select>
        <input id="rThreshold" type="number" min="1" placeholder="阈值(默认)" />
        <input id="rWindow" type="number" min="1" placeholder="窗口分钟" />
        <input id="rRemark" placeholder="备注" />
        <button class="aa-btn" id="rSubmit" onclick="saveRule()">添加规则</button>
      </div>
      <div style="margin-top:10px">
        <textarea id="rValue" placeholder="匹配值 *（每行一个）&#10;gambling-example.com&#10;porn-example.org"></textarea>
      </div>

      <div class="aa-editbar" id="rEditBar" style="display:none">
        <span class="aa-badge aa-badge--warn">正在编辑规则 #<span id="rEditId"></span></span>
        <button class="aa-btn aa-btn--ghost aa-btn--sm" onclick="resetForm()">取消编辑</button>
      </div>
      <div id="ruleMsg" class="aa-msg"></div>

      <div class="aa-tablebox" style="margin-top:14px">
        <table>
          <thead><tr>
            <th>ID</th><th>名称</th><th>类型</th><th>匹配值</th><th>阈值 / 窗口</th><th>状态</th><th>备注</th><th>操作</th>
          </tr></thead>
          <tbody id="rulesBody"></tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- 命中记录 -->
  <div class="aa-pane" id="pane-reports">
    <div class="aa-card">
      <div class="aa-card-head">
        <span class="aa-card-title">
          <span class="aa-ico"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v4M12 16h.01"/></svg></span>
          命中记录
        </span>
      </div>

      <div class="aa-filters">
        <div class="aa-filter-row">
          <div class="aa-field">
            <label>用户</label>
            <input id="fUserId" type="number" placeholder="用户 ID" style="width:110px" />
          </div>
          <div class="aa-field">
            <label>节点</label>
            <select id="fNodeId" style="width:150px"><option value="">全部节点</option></select>
          </div>
          <div class="aa-field">
            <label>关键词</label>
            <input id="fKeyword" placeholder="目标域名 / 规则名" style="width:180px" />
          </div>
          <span class="aa-spacer"></span>
          <button class="aa-btn" onclick="loadReports()">查询</button>
          <button class="aa-btn aa-btn--ghost" onclick="resetReportFilter()">重置</button>
        </div>
      </div>

      <div class="aa-hint">
        最近 200 条，目标关键词为前端过滤。命中记录按插件配置保留 <b id="reportsRetentionHint">30</b> 天自动清理。
      </div>

      <div class="aa-tablebox">
        <table>
          <thead><tr>
            <th>ID</th><th>用户</th><th>规则</th><th>目标</th><th>目标 IP</th><th>节点</th><th>来源 IP</th><th>触发封禁</th><th>时间</th>
          </tr></thead>
          <tbody id="reportsBody"></tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- 节点状态 -->
  <div class="aa-pane" id="pane-nodes">
    <div class="aa-card">
      <div class="aa-card-head">
        <span class="aa-card-title">
          <span class="aa-ico"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="3" width="18" height="7" rx="2"/><rect x="3" y="14" width="18" height="7" rx="2"/><path d="M7 6.5h.01M7 17.5h.01"/></svg></span>
          节点上报状态
        </span>
        <button class="aa-btn aa-btn--ghost aa-btn--sm" onclick="loadNodes()">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 12a9 9 0 11-3-6.7M21 4v5h-5"/></svg>
          刷新
        </button>
      </div>

      <div class="aa-hint">
        节点健康状态来自审计上报通道（tx-node 内嵌 / audit-agent.py 旁路）。「静默时长」为距最后一次上报的时间；
        超过插件配置的中断阈值（当前 <b id="nodesOfflineHint">10</b> 分钟）会触发 TG 告警。
      </div>

      <div class="aa-tablebox">
        <table>
          <thead><tr>
            <th>节点</th><th>最后上报</th><th>状态</th><th>上报批次</th><th>上报事件</th><th>命中</th><th>触发封禁</th>
          </tr></thead>
          <tbody id="nodesBody"></tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- 封禁管理 -->
  <div class="aa-pane" id="pane-bans">
    <div class="aa-card">
      <div class="aa-card-head">
        <span class="aa-card-title">
          <span class="aa-ico"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M5.6 5.6l12.8 12.8"/></svg></span>
          手动封禁 / 解封
        </span>
      </div>

      <div class="aa-filter-row">
        <div class="aa-field" style="flex:1 1 260px">
          <input id="opEmail" type="email" placeholder="用户邮箱" style="width:100%" />
        </div>
        <div class="aa-field" style="flex:1 1 220px">
          <input id="opReason" placeholder="原因（可选）" style="width:100%" />
        </div>
        <button class="aa-btn aa-btn--danger" onclick="opUser('ban')">封禁</button>
        <button class="aa-btn aa-btn--secondary" onclick="opUser('unban')">解封</button>
      </div>
      <div id="opMsg" class="aa-msg"></div>

      <div class="aa-card-head" style="margin-top:18px;margin-bottom:10px">
        <span class="aa-card-title">
          <span class="aa-ico"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 6h16M4 12h16M4 18h16"/></svg></span>
          封禁操作日志
        </span>
        <button class="aa-btn aa-btn--ghost aa-btn--sm" onclick="loadBanLogs()">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 12a9 9 0 11-3-6.7M21 4v5h-5"/></svg>
          刷新
        </button>
      </div>

      <div class="aa-tablebox">
        <table>
          <thead><tr>
            <th>ID</th><th>用户</th><th>动作</th><th>规则</th><th>命中 / 阈值</th><th>操作人</th><th>原因</th><th>时间</th>
          </tr></thead>
          <tbody id="banLogsBody"></tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<script>
let token = null;
let rulesCache = [];
let nodesCache = [];
let statsCache = null;

/* ── 会话持久化（localStorage）── */
const LS_KEY = 'aa_session';
function saveSession(email, tok) {
  try { localStorage.setItem(LS_KEY, JSON.stringify({ email, token: tok, at: Date.now() })); } catch (e) {}
}
function loadSession() {
  try {
    const s = JSON.parse(localStorage.getItem(LS_KEY) || 'null');
    if (s && s.token) return s;
  } catch (e) {}
  return null;
}
function clearSession() {
  try { localStorage.removeItem(LS_KEY); } catch (e) {}
}

/* ── 基础 ── */
function ts(t) { return t ? new Date(t * 1000).toLocaleString('zh-CN', { hour12: false }) : '-'; }
function esc(s) { const d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
function fmtNum(n) { return (n == null || isNaN(n)) ? '-' : Number(n).toLocaleString('zh-CN'); }
function showMsg(id, text, ok) {
  const el = document.getElementById(id);
  el.textContent = text;
  el.className = 'aa-msg ' + (ok ? 'ok' : 'err');
  setTimeout(() => { el.className = 'aa-msg'; }, 4000);
}

/* 节点 ID 只用于识别节点；不使用颜色表达在线、异常或风险状态 */
function nodeCell(id, name) {
  return `<span class="aa-nodecell">`
    + `<span>${esc(name)}</span><span class="aa-nodeid" title="节点 ID，仅用于识别，不代表在线、异常或风险状态">#${esc(id)}</span></span>`;
}

async function api(url, payload) {
  const opt = { headers: { 'Accept': 'application/json', 'Authorization': token } };
  if (payload !== undefined) {
    opt.method = 'POST';
    opt.headers['Content-Type'] = 'application/json';
    opt.body = JSON.stringify(payload);
  }
  const resp = await fetch(url, opt);
  if (resp.status === 401 || resp.status === 403) {
    logout();
    showMsg('loginMsg', '登录已过期，请重新登录', false);
    throw new Error('unauthorized');
  }
  return resp.json();
}

/* ── 登录 ── */
async function login() {
  const email = document.getElementById('loginEmail').value.trim();
  const password = document.getElementById('loginPassword').value;
  if (!email || !password) { showMsg('loginMsg', '请输入邮箱和密码', false); return; }
  let body;
  try {
    const resp = await fetch('/api/v1/passport/auth/login', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({ email, password })
    });
    body = await resp.json();
  } catch (e) { showMsg('loginMsg', '网络错误', false); return; }
  const d = body.data || {};
  if (!d.is_admin || !d.auth_data) {
    showMsg('loginMsg', body.message || '登录失败：仅管理员可访问', false);
    return;
  }
  token = d.auth_data;
  saveSession(email, token);
  document.getElementById('loginPassword').value = '';
  enterWorkspace(email);
}

function enterWorkspace(email) {
  const label = email || 'admin';
  document.getElementById('userLabel').textContent = label;
  document.getElementById('userAvatar').textContent = (label[0] || 'A').toUpperCase();
  renderClock();
  document.getElementById('loginWrap').style.display = 'none';
  document.getElementById('workspace').style.display = '';
  loadStats(); loadRules(); loadNodes().then(() => { loadLogs(1); loadReports(); }); loadBanLogs();
  startAutoRefresh();
}

/* 页头日期：与参考图一致，显示「年月日 星期 时分」 */
function renderClock() {
  const el = document.getElementById('headClock');
  if (!el) return;
  const d = new Date();
  const wd = ['星期日', '星期一', '星期二', '星期三', '星期四', '星期五', '星期六'][d.getDay()];
  const p = n => String(n).padStart(2, '0');
  el.textContent = `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${wd} ${p(d.getHours())}:${p(d.getMinutes())}`;
}

function logout() {
  token = null;
  clearSession();
  stopAutoRefresh();
  document.getElementById('workspace').style.display = 'none';
  document.getElementById('loginWrap').style.display = '';
}

function switchTab(name) {
  document.querySelectorAll('.aa-tabs button').forEach(b => b.classList.toggle('active', b.dataset.tab === name));
  document.querySelectorAll('.aa-pane').forEach(p => p.classList.toggle('active', p.id === 'pane-' + name));
  if (name === 'logs') loadLogs(1);
  if (name === 'reports') loadReports();
  if (name === 'nodes') loadNodes();
  if (name === 'bans') loadBanLogs();
}

/* ── 统计卡 ── */
async function loadStats() {
  const r = await api('/plugin/access-audit/stats');
  const d = r.data || {};
  statsCache = d;

  const rTotal = d.rules_total ?? 0, rEnabled = d.rules_enabled ?? 0;
  document.getElementById('sRules').innerHTML = `${fmtNum(rEnabled)} <small>/ ${fmtNum(rTotal)}</small>`;
  const rPct = rTotal > 0 ? Math.round(rEnabled / rTotal * 100) : 0;
  document.getElementById('sRulesBar').style.width = rPct + '%';
  document.getElementById('sRulesPct').textContent = rTotal > 0 ? rPct + '% 已启用' : '暂未配置规则';

  // 今日访问：来自全量访问日志表（audit_access_logs），与「命中」不是同一张表
  document.getElementById('sLogsToday').textContent = fmtNum(d.logs_today ?? 0);
  renderDelta('sLogsDelta', d.logs_today, d.logs_yesterday, '较昨日');

  document.getElementById('sReportsToday').textContent = fmtNum(d.reports_today ?? 0);
  renderDelta('sReportsDelta', d.reports_today, d.reports_yesterday, '较昨日');

  // 封禁：总数为主，今日新增放在脚注；今日>0 时用红字提示风险
  const bansToday = d.bans_today ?? 0;
  document.getElementById('sBans').textContent = fmtNum(d.bans_total ?? 0);
  document.getElementById('sBansHint').innerHTML = bansToday > 0
    ? `<span class="aa-up">今日 +${fmtNum(bansToday)}</span>`
    : '今日无新增';

  const nTotal = d.nodes_total ?? 0, nOnline = d.nodes_online ?? 0;
  document.getElementById('sNodes').innerHTML = `${fmtNum(nOnline)} <small>/ ${fmtNum(nTotal)}</small>`;
  const nPct = nTotal > 0 ? Math.round(nOnline / nTotal * 100) : 0;
  document.getElementById('sNodesBar').style.width = nPct + '%';
  document.getElementById('sNodesPct').textContent = nTotal > 0 ? nPct + '% 在线' : '暂无节点上报';

  drawSpark(d.trend_24h || []);
}

/* 同比：升高用红（对审计场景＝风险上升），降低用绿。
   prev 为空（后端未返回该字段）时显示占位，不假装有对比数据。 */
function renderDelta(elId, cur, prev, suffix) {
  const el = document.getElementById(elId);
  if (!el) return;
  if (prev == null) { el.innerHTML = `<span class="aa-mute">${suffix} —</span>`; return; }
  const diff = (cur ?? 0) - prev;
  const rate = prev > 0 ? Math.round(diff / prev * 100) : null;
  if (diff === 0) {
    el.innerHTML = `<span class="aa-mute">${suffix}持平</span>`;
    return;
  }
  const cls = diff > 0 ? 'aa-up' : 'aa-down';
  const arrow = diff > 0 ? '↑' : '↓';
  const rateTxt = rate == null ? '新增' : Math.abs(rate) + '%';
  el.innerHTML = `<span class="${cls}">${arrow} ${rateTxt}</span><span class="aa-mute">${suffix}</span>`;
}

/* 迷你折线：把 24 个点归一化到 100x30 的 viewBox 里。
   宽高必须与 <svg viewBox> 和 .aa-spark 的 CSS 高度三者一致，
   否则 preserveAspectRatio="none" 会把线拉变形。 */
function drawSpark(series) {
  const svg = document.getElementById('sTrendSvg');
  if (!svg) return;
  if (!series || series.length < 2) { svg.innerHTML = ''; return; }
  const W = 100, H = 30, pad = 4;
  const max = Math.max(...series, 1);
  const stepX = W / (series.length - 1);
  const y = v => H - pad - (v / max) * (H - pad * 2);
  const pts = series.map((v, i) => [i * stepX, y(v)]);

  const line = pts.map((p, i) => (i ? 'L' : 'M') + p[0].toFixed(2) + ' ' + p[1].toFixed(2)).join(' ');
  const area = line + ` L${W} ${H} L0 ${H} Z`;

  svg.innerHTML = `
    <path d="${area}" fill="#e6f1fb" stroke="none"/>
    <path d="${line}" fill="none" stroke="#378ADD" stroke-width="1.6" stroke-linejoin="round" stroke-linecap="round"/>
    <circle cx="${pts[pts.length - 1][0].toFixed(2)}" cy="${pts[pts.length - 1][1].toFixed(2)}" r="2.2" fill="#185fa5"/>`;
}

/* ── 访问日志 ── */
let logsCurPage = 1, logsTotalPages = 1;

function dtToTs(id) {
  const v = document.getElementById(id).value;
  return v ? Math.floor(new Date(v).getTime() / 1000) : '';
}

function resetLogFilter() {
  ['lNodeId','lUserId','lKeyword','lMatched','lFrom','lTo'].forEach(id => document.getElementById(id).value = '');
  loadLogs(1);
}

function logsPage(p) {
  if (p < 1 || p > logsTotalPages) return;
  loadLogs(p);
}

async function loadLogs(page) {
  logsCurPage = page || 1;
  const params = new URLSearchParams();
  const nid = document.getElementById('lNodeId').value;
  const uid = document.getElementById('lUserId').value;
  const kw = document.getElementById('lKeyword').value.trim();
  const m = document.getElementById('lMatched').value;
  const from = dtToTs('lFrom');
  const to = dtToTs('lTo');
  if (nid) params.set('node_id', nid);
  if (uid) params.set('user_id', uid);
  if (kw) params.set('keyword', kw);
  if (m !== '') params.set('matched', m);
  if (from) params.set('from', from);
  if (to) params.set('to', to);
  params.set('page', logsCurPage);

  const r = await api('/plugin/access-audit/logs?' + params.toString());
  const d = r.data || {};
  const rows = d.list || [];
  logsTotalPages = d.pages || 1;
  lastLogsRows = rows;

  document.getElementById('logsTotal').textContent = `共 ${fmtNum(d.total ?? 0)} 条记录`;
  renderPager('logsPager', d.page ?? 1, logsTotalPages, 'logsPage');

  document.getElementById('logsBody').innerHTML = rows.map(x => `
    <tr>
      <td class="aa-nowrap aa-mono">${ts(x.created_at)}</td>
      <td>${nodeCell(x.node_id, x.node_name)}</td>
      <td class="aa-nowrap">${esc(x.user_email)}</td>
      <td><span class="aa-target aa-mono" title="${esc(x.target)}">${esc(x.target)}</span></td>
      <td class="aa-mono aa-nowrap">${esc(x.target_ip || '-')}</td>
      <td class="aa-mono aa-nowrap">${esc(x.source_ip || '-')}</td>
      <td>${x.matched
        ? '<span class="aa-badge aa-badge--warn"><i class="aa-dot aa-dot--warn"></i>命中</span>'
        : '<span class="aa-badge aa-badge--ok"><i class="aa-dot aa-dot--ok"></i>正常</span>'}</td>
    </tr>`).join('')
    || '<tr><td colspan="7" class="aa-empty">暂无记录 —— 节点 config.yml 开启 <code>audit.report_all</code> 后才有全量数据</td></tr>';
}

/* 分页器：页码带省略号。
   生成规则：首末页始终显示，当前页前后各 1 页，其余折叠为 …。
   注意不能简单「遍历 + 遇到不连续就补省略号」——当前页为 1 时，
   首页与当前页是同一条，会重复推入导致出现「12…6」这种错乱。 */
function renderPager(elId, cur, total, fn) {
  const el = document.getElementById(elId);
  if (!el) return;
  if (total <= 1) { el.innerHTML = ''; return; }

  // 先算出要显示哪些页码（去重 + 升序）
  const set = new Set([1, total]);
  for (let d = -1; d <= 1; d++) {
    const p = cur + d;
    if (p >= 1 && p <= total) set.add(p);
  }
  const pages = Array.from(set).sort((a, b) => a - b);

  const html = [];
  html.push(`<button class="aa-page" onclick="${fn}(${cur - 1})" ${cur <= 1 ? 'disabled' : ''} aria-label="上一页">‹</button>`);

  let prev = 0;
  for (const p of pages) {
    if (prev && p - prev > 1) html.push('<span class="aa-page aa-page--dots">…</span>');
    html.push(`<button class="aa-page${p === cur ? ' active' : ''}" onclick="${fn}(${p})">${p}</button>`);
    prev = p;
  }

  html.push(`<button class="aa-page" onclick="${fn}(${cur + 1})" ${cur >= total ? 'disabled' : ''} aria-label="下一页">›</button>`);
  el.innerHTML = html.join('');
}

/* 导出当前日志（前端 CSV，避免再加一个后端接口） */
let lastLogsRows = [];
function exportLogs() {
  if (!lastLogsRows.length) { alert('当前页没有可导出的数据'); return; }
  const head = ['时间', '节点', '节点ID', '用户', '目标', '目标IP', '来源IP', '是否命中'];
  const lines = [head.join(',')];
  for (const x of lastLogsRows) {
    const cells = [ts(x.created_at), x.node_name, x.node_id, x.user_email, x.target, x.target_ip || '', x.source_ip || '', x.matched ? '命中' : '正常'];
    lines.push(cells.map(v => `"${String(v == null ? '' : v).replace(/"/g, '""')}"`).join(','));
  }
  // 加 BOM 让 Excel 正确识别 UTF-8
  const blob = new Blob(['\ufeff' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' });
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = `access-audit-logs-${new Date().toISOString().slice(0, 10)}.csv`;
  document.body.appendChild(a); a.click(); document.body.removeChild(a);
  URL.revokeObjectURL(a.href);
}

/* ── 节点状态 ── */
async function loadNodes() {
  const r = await api('/plugin/access-audit/nodes');
  nodesCache = r.data || [];
  // 同步刷新命中记录页 + 访问日志页的节点下拉
  ['fNodeId', 'lNodeId'].forEach(id => {
    const sel = document.getElementById(id);
    const cur = sel.value;
    sel.innerHTML = '<option value="">全部节点</option>' + nodesCache.map(n =>
      `<option value="${n.node_id}">${esc(n.node_name)}</option>`).join('');
    sel.value = cur;
  });

  document.getElementById('nodesBody').innerHTML = nodesCache.map(n => {
    const silent = n.silent_minutes;
    const badge = silent === null
      ? '<span class="aa-badge aa-badge--mute">未上报</span>'
      : (silent >= 10
        ? `<span class="aa-badge aa-badge--no"><i class="aa-dot aa-dot--no"></i>静默 ${silent} 分钟</span>`
        : '<span class="aa-badge aa-badge--ok"><i class="aa-dot aa-dot--ok"></i>正常</span>');
    return `<tr>
      <td>${nodeCell(n.node_id, n.node_name)}</td>
      <td class="aa-nowrap">${n.last_report_at ? ts(n.last_report_at) : '-'}</td>
      <td>${badge}</td>
      <td>${fmtNum(n.total_reports)}</td>
      <td>${fmtNum(n.total_events)}</td>
      <td>${fmtNum(n.total_matched)}</td>
      <td>${fmtNum(n.total_banned)}</td>
    </tr>`;
  }).join('') || '<tr><td colspan="7" class="aa-empty">暂无节点上报数据（配置 tx-node 或 audit-agent 后自动出现）</td></tr>';
}

/* ── 规则 ── */
async function loadRules() {
  const r = await api('/plugin/access-audit/rules');
  rulesCache = r.data || [];
  document.getElementById('rulesBody').innerHTML = rulesCache.map(x => {
    const vals = (x.match_value || '').split('\n').filter(Boolean);
    const shown = vals.slice(0, 2).join(', ');
    return `<tr id="rule-row-${x.id}">
      <td class="aa-mono">${x.id}</td>
      <td>${esc(x.name)}</td>
      <td class="aa-mono">${esc(x.match_type)}</td>
      <td class="aa-mono" title="${esc(vals.join('\n'))}">${esc(shown)}${vals.length > 2 ? ` <span class="aa-muted">…共 ${vals.length} 条</span>` : ''}</td>
      <td class="aa-nowrap">${x.threshold || '<span class="aa-muted">默认</span>'} / ${x.window_minutes ? x.window_minutes + ' 分' : '<span class="aa-muted">默认</span>'}</td>
      <td>${x.enabled
        ? '<span class="aa-badge aa-badge--ok"><i class="aa-dot aa-dot--ok"></i>启用</span>'
        : '<span class="aa-badge aa-badge--mute"><i class="aa-dot"></i>停用</span>'}</td>
      <td class="aa-muted">${esc(x.remark || '')}</td>
      <td>
        <span class="aa-actions">
          <button class="aa-btn aa-btn--ghost aa-btn--sm" onclick="editRule(${x.id})">编辑</button>
          <button class="aa-btn aa-btn--ghost aa-btn--sm" onclick="toggleRule(${x.id})">${x.enabled ? '停用' : '启用'}</button>
          <button class="aa-btn aa-btn--ghost aa-btn--sm" style="color:var(--aa-danger);border-color:var(--aa-danger-soft)" onclick="delRule(${x.id})">删除</button>
        </span>
      </td>
    </tr>`;
  }).join('') || '<tr><td colspan="8" class="aa-empty">暂无规则 —— 在上方表单添加第一条审计规则</td></tr>';
  loadStats();
}

function editRule(id) {
  const x = rulesCache.find(r => r.id === id);
  if (!x) return;
  document.getElementById('rId').value = x.id;
  document.getElementById('rName').value = x.name;
  document.getElementById('rType').value = x.match_type;
  document.getElementById('rValue').value = x.match_value;
  document.getElementById('rThreshold').value = x.threshold || '';
  document.getElementById('rWindow').value = x.window_minutes || '';
  document.getElementById('rRemark').value = x.remark || '';
  document.getElementById('rSubmit').textContent = '保存修改';
  document.getElementById('ruleFormTitle').textContent = '编辑规则';
  document.getElementById('rEditId').textContent = x.id;
  document.getElementById('rEditBar').style.display = '';
  document.querySelectorAll('#rulesBody tr').forEach(tr => tr.classList.remove('editing'));
  const row = document.getElementById('rule-row-' + id);
  if (row) row.classList.add('editing');
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

function resetForm() {
  document.getElementById('rId').value = '';
  ['rName', 'rValue', 'rThreshold', 'rWindow', 'rRemark'].forEach(i => document.getElementById(i).value = '');
  document.getElementById('rSubmit').textContent = '添加规则';
  document.getElementById('ruleFormTitle').textContent = '添加规则';
  document.getElementById('rEditBar').style.display = 'none';
  document.querySelectorAll('#rulesBody tr').forEach(tr => tr.classList.remove('editing'));
}

async function saveRule() {
  const id = document.getElementById('rId').value;
  const payload = {
    name: document.getElementById('rName').value.trim(),
    match_type: document.getElementById('rType').value,
    match_value: document.getElementById('rValue').value.trim(),
    threshold: parseInt(document.getElementById('rThreshold').value) || null,
    window_minutes: parseInt(document.getElementById('rWindow').value) || null,
    remark: document.getElementById('rRemark').value.trim(),
    enabled: true,
  };
  if (id) {
    payload.id = parseInt(id);
    const old = rulesCache.find(r => r.id === payload.id);
    if (old) payload.enabled = !!old.enabled;
  }
  if (!payload.name || !payload.match_value) { showMsg('ruleMsg', '名称和匹配值必填', false); return; }
  const r = await api('/plugin/access-audit/rules/save', payload);
  if (r.error) { showMsg('ruleMsg', r.error.message || '保存失败', false); return; }
  showMsg('ruleMsg', (id ? '已更新' : '已添加') + '规则「' + payload.name + '」', true);
  resetForm();
  loadRules();
}

async function toggleRule(id) {
  const x = rulesCache.find(r => r.id === id);
  if (!x) return;
  await api('/plugin/access-audit/rules/save', {
    id: x.id, name: x.name, match_type: x.match_type, match_value: x.match_value,
    threshold: x.threshold, window_minutes: x.window_minutes, remark: x.remark,
    enabled: x.enabled ? 0 : 1,
  });
  loadRules();
}

async function delRule(id) {
  if (!confirm('确认删除该规则？相关历史记录保留。')) return;
  await api('/plugin/access-audit/rules/delete', { id });
  resetForm();
  loadRules();
}

/* ── 命中记录 ── */
function resetReportFilter() {
  document.getElementById('fUserId').value = '';
  document.getElementById('fNodeId').value = '';
  document.getElementById('fKeyword').value = '';
  loadReports();
}

async function loadReports() {
  const uid = document.getElementById('fUserId').value;
  const nid = document.getElementById('fNodeId').value;
  const kw = document.getElementById('fKeyword').value.trim().toLowerCase();
  const params = new URLSearchParams();
  if (uid) params.set('user_id', uid);
  if (nid) params.set('node_id', nid);
  const url = '/plugin/access-audit/reports' + (params.toString() ? '?' + params.toString() : '');
  const r = await api(url);
  let rows = r.data || [];
  if (kw) rows = rows.filter(x => (x.target || '').toLowerCase().includes(kw) || (x.rule_name || '').toLowerCase().includes(kw));
  const nodeName = id => { const n = nodesCache.find(v => v.node_id === id); return n ? n.node_name : '节点'; };
  document.getElementById('reportsBody').innerHTML = rows.map(x => `
    <tr>
      <td class="aa-mono">${x.id}</td>
      <td class="aa-nowrap">${esc(x.user_email)}</td>
      <td>${esc(x.rule_name)}</td>
      <td><span class="aa-target aa-mono" title="${esc(x.target)}">${esc(x.target)}</span></td>
      <td class="aa-mono aa-nowrap">${esc(x.target_ip || '-')}</td>
      <td>${nodeCell(x.node_id, nodeName(x.node_id))}</td>
      <td class="aa-mono aa-nowrap">${esc(x.source_ip || '-')}</td>
      <td>${x.banned
        ? '<span class="aa-badge aa-badge--no"><i class="aa-dot aa-dot--no"></i>已封禁</span>'
        : '<span class="aa-badge aa-badge--mute">否</span>'}</td>
      <td class="aa-nowrap aa-mono">${ts(x.created_at)}</td>
    </tr>`).join('') || '<tr><td colspan="9" class="aa-empty">暂无记录</td></tr>';
}

/* ── 封禁管理 ── */
async function loadBanLogs() {
  const r = await api('/plugin/access-audit/ban-logs');
  document.getElementById('banLogsBody').innerHTML = (r.data || []).map(x => `
    <tr>
      <td class="aa-mono">${x.id}</td>
      <td class="aa-nowrap">${esc(x.user_email)}</td>
      <td>${x.action === 'ban'
        ? '<span class="aa-badge aa-badge--no"><i class="aa-dot aa-dot--no"></i>封禁</span>'
        : '<span class="aa-badge aa-badge--ok"><i class="aa-dot aa-dot--ok"></i>解封</span>'}</td>
      <td>${esc(x.rule_name || '-')}</td>
      <td class="aa-mono">${x.hit_count} / ${x.threshold || '-'}</td>
      <td>${x.operator_id ? '#' + x.operator_id : '<span class="aa-muted">自动</span>'}</td>
      <td class="aa-muted">${esc(x.reason || '')}</td>
      <td class="aa-nowrap aa-mono">${ts(x.created_at)}</td>
    </tr>`).join('') || '<tr><td colspan="8" class="aa-empty">暂无日志</td></tr>';
}

async function opUser(action) {
  const email = document.getElementById('opEmail').value.trim();
  if (!email) { showMsg('opMsg', '请输入用户邮箱', false); return; }
  const verb = action === 'ban' ? '封禁' : '解封';
  if (!confirm(`确认${verb} ${email}？`)) return;
  const r = await api('/plugin/access-audit/' + action, {
    email,
    reason: document.getElementById('opReason').value.trim()
  });
  if (r.error) { showMsg('opMsg', r.error.message || verb + '失败', false); return; }
  showMsg('opMsg', r.data.message, true);
  document.getElementById('opEmail').value = '';
  document.getElementById('opReason').value = '';
  loadBanLogs(); loadStats();
}

/* ── 静默自动刷新（当前 tab，30 秒）── */
let autoTimer = null;
function startAutoRefresh() {
  stopAutoRefresh();
  autoTimer = setInterval(() => {
    if (document.hidden) return; // 后台标签页不刷新，省请求
    const active = document.querySelector('.aa-tabs button.active');
    if (!active) return;
    const name = active.dataset.tab;
    if (name === 'logs') loadLogs(logsCurPage);
    else if (name === 'reports') loadReports();
    else if (name === 'nodes') loadNodes();
    else if (name === 'bans') loadBanLogs();
    loadStats();
    renderClock();
  }, 30000);
}
function stopAutoRefresh() {
  if (autoTimer) { clearInterval(autoTimer); autoTimer = null; }
}

/* 进入页面：有已存会话则直接恢复，否则显示登录卡片 */
(function init() {
  const s = loadSession();
  if (s) {
    token = s.token;
    enterWorkspace(s.email || 'admin');
  } else {
    document.getElementById('loginWrap').style.display = '';
    document.getElementById('loginEmail').focus();
  }
})();
</script>
</body>
</html>
