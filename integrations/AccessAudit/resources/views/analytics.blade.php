<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>审计分析与设置</title>
  <style>
    :root{--bg:#f7f8fa;--surface:#fff;--line:#e5e7eb;--line2:#d3d1c7;--text:#1f2937;--text2:#5f5e5a;--text3:#888780;--primary:#185fa5;--primary-soft:#e6f1fb;--success:#0f6e56;--success-soft:#e1f5ee;--warn:#ba7517;--warn-soft:#faeeda;--danger:#a32d2d;--danger-soft:#fcebeb;--purple:#534ab7;--purple-soft:#eeedfe}
    *{box-sizing:border-box} body{margin:0;background:var(--bg);color:var(--text);font:13px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif}.aa-wrap{max-width:1180px;margin:0 auto;padding:24px}.aa-head{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;margin-bottom:18px}.aa-head-main{display:flex;gap:12px;align-items:flex-start}.aa-icon{width:40px;height:40px;border-radius:10px;background:var(--primary);color:#fff;display:flex;align-items:center;justify-content:center;font-size:20px}.aa-head h1{margin:0;font-size:17px;font-weight:600}.aa-head p{margin:2px 0 0;color:var(--text3);font-size:12px}.aa-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.aa-btn{height:34px;padding:0 14px;border:1px solid transparent;border-radius:6px;background:var(--primary);color:#fff;cursor:pointer;font:inherit}.aa-btn.ghost{background:#fff;color:var(--text2);border-color:var(--line2)}.aa-btn:disabled{opacity:.55;cursor:not-allowed}.aa-card{background:#fff;border:1px solid var(--line);border-radius:10px;padding:18px 20px;margin-bottom:16px}.aa-card-title{font-weight:600;margin-bottom:12px}.aa-tabs{display:flex;gap:2px;border-bottom:1px solid var(--line);margin-bottom:16px}.aa-tabs button{background:none;border:0;border-bottom:2px solid transparent;padding:10px 16px;margin-bottom:-1px;color:var(--text3);cursor:pointer;font:inherit}.aa-tabs button.active{color:var(--primary);border-bottom-color:var(--primary);font-weight:600}.aa-pane{display:none}.aa-pane.active{display:block}.aa-filter{display:flex;gap:10px;align-items:center;flex-wrap:wrap}.aa-filter label{color:var(--text3);font-size:12px}.aa-filter select,.aa-filter input,.aa-setting input[type=text],.aa-setting input[type=number]{height:34px;padding:6px 10px;border:1px solid var(--line2);border-radius:6px;background:#fff;color:var(--text);font:inherit}.aa-kpis{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:12px;margin:16px 0}.aa-kpi{background:#fff;border:1px solid var(--line);border-radius:10px;padding:14px 16px}.aa-kpi .label{color:var(--text3);font-size:12px}.aa-kpi .num{font-size:24px;font-weight:600;margin-top:5px;font-variant-numeric:tabular-nums}.aa-kpi.blue .num{color:var(--primary)}.aa-kpi.red .num{color:var(--danger)}.aa-kpi.green .num{color:var(--success)}.aa-chart{width:100%;height:260px;border:1px solid var(--line);border-radius:10px;background:#fff;display:block}.aa-legend{display:flex;gap:16px;margin-top:8px;color:var(--text3);font-size:12px}.dot{width:8px;height:8px;border-radius:50%;display:inline-block;margin-right:5px}.dot.blue{background:#378ADD}.dot.red{background:#E24B4A}.aa-grid2{display:grid;grid-template-columns:1fr 1fr;gap:16px}.aa-tablebox{overflow:auto;border:1px solid var(--line);border-radius:10px}table{width:100%;border-collapse:collapse}th,td{padding:10px 12px;border-bottom:1px solid var(--line);text-align:left;white-space:nowrap}th{background:var(--bg);color:var(--text3);font-size:12px;font-weight:500}tr:last-child td{border-bottom:0}.aa-muted{color:var(--text3)}.aa-mono{font-family:ui-monospace,SFMono-Regular,Menlo,monospace}.aa-hint{font-size:12px;color:var(--text3);background:var(--bg);border-radius:6px;padding:9px 12px;margin:10px 0}.aa-settings{display:grid;grid-template-columns:1fr 1fr;gap:16px}.aa-setting-group{background:#fff;border:1px solid var(--line);border-radius:10px;padding:16px}.aa-setting-group h3{font-size:14px;margin:0 0 12px}.aa-setting{display:grid;grid-template-columns:1fr 170px;gap:12px;align-items:center;padding:10px 0;border-bottom:1px solid var(--line)}.aa-setting:last-child{border-bottom:0}.aa-setting .desc{color:var(--text3);font-size:12px;margin-top:2px}.aa-setting input[type=text],.aa-setting input[type=number]{width:100%}.switch{display:flex;justify-content:flex-end;align-items:center;gap:8px}.aa-msg{display:none;margin-top:12px;padding:10px 12px;border-radius:6px}.aa-msg.ok{display:block;background:var(--success-soft);color:var(--success)}.aa-msg.err{display:block;background:var(--danger-soft);color:var(--danger)}.aa-login{max-width:380px;margin:12vh auto;background:#fff;border:1px solid var(--line);border-radius:10px;padding:20px}.aa-login input{width:100%;height:36px;margin:6px 0;padding:6px 10px;border:1px solid var(--line2);border-radius:6px}.aa-login .aa-btn{width:100%;margin-top:8px}.badge{display:inline-flex;padding:2px 8px;border-radius:999px;font-size:12px}.badge.ok{background:var(--success-soft);color:var(--success)}.badge.warn{background:var(--warn-soft);color:var(--warn)}.badge.no{background:var(--danger-soft);color:var(--danger)}
    @media(max-width:900px){.aa-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}.aa-grid2,.aa-settings{grid-template-columns:1fr}.aa-setting{grid-template-columns:1fr}.aa-setting .switch{justify-content:flex-start}}
    @media(max-width:560px){.aa-wrap{padding:14px}.aa-head{flex-direction:column}.aa-kpis{grid-template-columns:1fr}.aa-filter>*{width:100%}.aa-filter select,.aa-filter input,.aa-filter .aa-btn{width:100%}}
  </style>
</head>
<body>
<div class="aa-wrap" id="loginWrap" style="display:none">
  <div class="aa-login">
    <h2 style="margin:0 0 4px">📊 审计分析与设置</h2>
    <div class="aa-hint">使用 Xboard 管理员账号登录。与“访问审计”主页面共用同一登录会话。</div>
    <input id="loginEmail" type="email" placeholder="管理员邮箱" autocomplete="username" />
    <input id="loginPassword" type="password" placeholder="密码" autocomplete="current-password" onkeydown="if(event.key==='Enter')login()" />
    <button class="aa-btn" onclick="login()">登录</button>
    <div id="loginMsg" class="aa-msg"></div>
  </div>
</div>

<div class="aa-wrap" id="workspace" style="display:none">
  <div class="aa-head">
    <div class="aa-head-main"><div class="aa-icon">📊</div><div><h1>审计分析与设置</h1><p>长期趋势、节点/规则排行，以及 AccessAudit 运行参数管理</p></div></div>
    <div class="aa-actions"><a class="aa-btn ghost" href="/plugin/access-audit" style="text-decoration:none;display:inline-flex;align-items:center">返回访问审计</a><span id="userLabel" class="aa-muted"></span><button class="aa-btn ghost" onclick="logout()">退出</button></div>
  </div>

  <div class="aa-tabs"><button class="active" data-tab="analytics" onclick="switchTab('analytics')">数据分析</button><button data-tab="settings" onclick="switchTab('settings')">插件设置</button></div>

  <div class="aa-pane active" id="pane-analytics">
    <div class="aa-card">
      <div class="aa-filter">
        <label>时间范围</label><select id="aRange"><option value="1h">最近 1 小时</option><option value="24h" selected>最近 24 小时</option><option value="7d">最近 7 天</option><option value="30d">最近 30 天</option></select>
        <label>节点</label><select id="aNode"><option value="">全部节点</option></select>
        <button class="aa-btn" id="aRefresh" onclick="loadAnalytics()">刷新</button>
      </div>
      <div class="aa-hint" id="coverageHint">1h / 24h 使用原始日志实时分析；7d / 30d 优先读取小时聚合，减少大表扫描。</div>
    </div>

    <div class="aa-kpis">
      <div class="aa-kpi blue"><div class="label">访问量</div><div class="num" id="kEvents">-</div></div>
      <div class="aa-kpi red"><div class="label">命中量</div><div class="num" id="kMatched">-</div></div>
      <div class="aa-kpi"><div class="label">命中率</div><div class="num" id="kRate">-</div></div>
      <div class="aa-kpi red"><div class="label">封禁</div><div class="num" id="kBans">-</div></div>
      <div class="aa-kpi green"><div class="label">活跃用户</div><div class="num" id="kUsers">-</div></div>
    </div>

    <div class="aa-card"><div class="aa-card-title">访问 / 命中趋势</div><svg id="trendChart" class="aa-chart" viewBox="0 0 1000 260" preserveAspectRatio="none"></svg><div class="aa-legend"><span><i class="dot blue"></i>访问量</span><span><i class="dot red"></i>命中量</span></div></div>

    <div class="aa-grid2">
      <div class="aa-card"><div class="aa-card-title">节点排行</div><div class="aa-tablebox"><table><thead><tr><th>节点</th><th>访问</th><th>命中</th><th>命中率</th></tr></thead><tbody id="nodeRank"></tbody></table></div></div>
      <div class="aa-card"><div class="aa-card-title">规则排行</div><div class="aa-tablebox"><table><thead><tr><th>规则</th><th>命中</th><th>用户</th><th>封禁</th></tr></thead><tbody id="ruleRank"></tbody></table></div></div>
      <div class="aa-card"><div class="aa-card-title">高频用户（最多扫描最近 24h）</div><div class="aa-tablebox"><table><thead><tr><th>用户</th><th>访问</th><th>命中</th></tr></thead><tbody id="userRank"></tbody></table></div></div>
      <div class="aa-card"><div class="aa-card-title">高频目标（最多扫描最近 24h）</div><div class="aa-tablebox"><table><thead><tr><th>目标</th><th>访问</th><th>命中</th></tr></thead><tbody id="targetRank"></tbody></table></div></div>
    </div>
  </div>

  <div class="aa-pane" id="pane-settings">
    <div class="aa-hint">设置通过 Xboard 原生 PluginConfigService 写入插件配置数据库，不修改 config.json。保存后上报处理与健康监控缓存会立即刷新。</div>
    <div class="aa-settings">
      <div class="aa-setting-group"><h3>数据保留与分析</h3>
        <div class="aa-setting"><div><b>全量访问日志保留</b><div class="desc">建议 1～7 天；最大 30 天</div></div><input id="s_access_log_retention_days" type="number" min="1" max="30" /></div>
        <div class="aa-setting"><div><b>命中记录保留</b><div class="desc">audit_reports 明细保留天数</div></div><input id="s_report_retention_days" type="number" min="1" max="3650" /></div>
        <div class="aa-setting"><div><b>数据分析聚合</b><div class="desc">每 5 分钟维护 node/hour 汇总</div></div><div class="switch"><input id="s_analytics_enabled" type="checkbox" /></div></div>
        <div class="aa-setting"><div><b>分析聚合保留</b><div class="desc">小时统计保留天数</div></div><input id="s_analytics_retention_days" type="number" min="7" max="3650" /></div>
      </div>

      <div class="aa-setting-group"><h3>自动风控</h3>
        <div class="aa-setting"><div><b>自动封禁</b><div class="desc">达到阈值后自动封禁用户</div></div><div class="switch"><input id="s_auto_ban_enabled" type="checkbox" /></div></div>
        <div class="aa-setting"><div><b>默认封禁阈值</b><div class="desc">规则未单独设置时生效</div></div><input id="s_default_threshold" type="number" min="1" max="10000" /></div>
        <div class="aa-setting"><div><b>默认统计窗口</b><div class="desc">分钟</div></div><input id="s_default_window_minutes" type="number" min="1" max="10080" /></div>
        <div class="aa-setting"><div><b>TG 告警 Chat ID</b><div class="desc">留空自动寻找绑定 TG 的管理员</div></div><input id="s_alert_chat_id" type="text" /></div>
      </div>

      <div class="aa-setting-group"><h3>节点健康</h3>
        <div class="aa-setting"><div><b>节点异常监控</b></div><div class="switch"><input id="s_node_health_enabled" type="checkbox" /></div></div>
        <div class="aa-setting"><div><b>上报中断阈值</b><div class="desc">分钟</div></div><input id="s_node_offline_minutes" type="number" min="1" max="1440" /></div>
        <div class="aa-setting"><div><b>中断告警冷却</b><div class="desc">秒</div></div><input id="s_node_offline_cooldown" type="number" min="60" max="86400" /></div>
      </div>

      <div class="aa-setting-group"><h3>命中突增</h3>
        <div class="aa-setting"><div><b>突增监控</b></div><div class="switch"><input id="s_node_spike_enabled" type="checkbox" /></div></div>
        <div class="aa-setting"><div><b>统计窗口</b><div class="desc">分钟</div></div><input id="s_node_spike_window" type="number" min="1" max="1440" /></div>
        <div class="aa-setting"><div><b>增长阈值</b><div class="desc">百分比</div></div><input id="s_node_spike_growth_pct" type="number" min="1" max="10000" /></div>
        <div class="aa-setting"><div><b>最小命中数</b><div class="desc">防止小基数误报</div></div><input id="s_node_spike_min_hits" type="number" min="1" max="1000000" /></div>
        <div class="aa-setting"><div><b>突增告警冷却</b><div class="desc">秒</div></div><input id="s_node_spike_cooldown" type="number" min="60" max="86400" /></div>
      </div>
    </div>
    <div class="aa-card" style="margin-top:16px"><button class="aa-btn" id="saveBtn" onclick="saveSettings()">保存设置</button><div id="settingsMsg" class="aa-msg"></div></div>
  </div>
</div>

<script>
let token=null, settingsCache={}; const LS_KEY='aa_session';
function esc(v){const d=document.createElement('div');d.textContent=v==null?'':String(v);return d.innerHTML}
function fmt(v){return v==null||Number.isNaN(Number(v))?'-':Number(v).toLocaleString('zh-CN')}
function showMsg(id,text,ok){const e=document.getElementById(id);e.textContent=text;e.className='aa-msg '+(ok?'ok':'err')}
function loadSession(){try{return JSON.parse(localStorage.getItem(LS_KEY)||'null')}catch(e){return null}}
function saveSession(email,tok){try{localStorage.setItem(LS_KEY,JSON.stringify({email,token:tok,at:Date.now()}))}catch(e){}}
function clearSession(){try{localStorage.removeItem(LS_KEY)}catch(e){}}
async function api(url,payload){const opt={headers:{Accept:'application/json',Authorization:token}};if(payload!==undefined){opt.method='POST';opt.headers['Content-Type']='application/json';opt.body=JSON.stringify(payload)}const r=await fetch(url,opt);if(r.status===401||r.status===403){logout();throw new Error('unauthorized')}const body=await r.json();if(!r.ok||body.error)throw new Error(body?.error?.message||body?.message||('HTTP '+r.status));return body}
async function login(){const email=document.getElementById('loginEmail').value.trim(),password=document.getElementById('loginPassword').value;if(!email||!password){showMsg('loginMsg','请输入邮箱和密码',false);return}try{const r=await fetch('/api/v1/passport/auth/login',{method:'POST',headers:{'Content-Type':'application/json',Accept:'application/json'},body:JSON.stringify({email,password})});const b=await r.json(),d=b.data||{};if(!d.is_admin||!d.auth_data)throw new Error(b.message||'仅管理员可访问');token=d.auth_data;saveSession(email,token);enter(email)}catch(e){showMsg('loginMsg',e.message||'登录失败',false)}}
function enter(email){document.getElementById('loginWrap').style.display='none';document.getElementById('workspace').style.display='';document.getElementById('userLabel').textContent=email||'admin';loadNodes();loadAnalytics();loadSettings()}
function logout(){token=null;clearSession();document.getElementById('workspace').style.display='none';document.getElementById('loginWrap').style.display=''}
function switchTab(name){document.querySelectorAll('.aa-tabs button').forEach(b=>b.classList.toggle('active',b.dataset.tab===name));document.querySelectorAll('.aa-pane').forEach(p=>p.classList.toggle('active',p.id==='pane-'+name));if(name==='analytics')loadAnalytics();if(name==='settings')loadSettings()}
async function loadNodes(){try{const r=await api('/plugin/access-audit/nodes');const sel=document.getElementById('aNode'),cur=sel.value;sel.innerHTML='<option value="">全部节点</option>'+(r.data||[]).map(n=>`<option value="${n.node_id}">${esc(n.node_name)} (#${n.node_id})</option>`).join('');sel.value=cur}catch(e){}}
async function loadAnalytics(){const btn=document.getElementById('aRefresh');btn.disabled=true;try{const qs=new URLSearchParams({range:document.getElementById('aRange').value});const node=document.getElementById('aNode').value;if(node)qs.set('node_id',node);const r=await api('/plugin/access-audit/analytics?'+qs.toString()),d=r.data||{},s=d.summary||{};document.getElementById('kEvents').textContent=fmt(s.events);document.getElementById('kMatched').textContent=fmt(s.matched);document.getElementById('kRate').textContent=s.match_rate==null?'—':s.match_rate+'%';document.getElementById('kBans').textContent=s.bans==null?'—':fmt(s.bans);document.getElementById('kUsers').textContent=fmt(s.active_users);renderTrend(d.trend||[]);renderTable('nodeRank',d.nodes||[],x=>`<tr><td>${esc(x.node_name)}</td><td>${fmt(x.events)}</td><td>${fmt(x.matched)}</td><td>${x.match_rate==null?'—':x.match_rate+'%'}</td></tr>`,4);renderTable('ruleRank',d.rules||[],x=>`<tr><td>${esc(x.rule_name)}</td><td>${fmt(x.hits)}</td><td>${fmt(x.users)}</td><td>${x.bans==null?'—':fmt(x.bans)}</td></tr>`,4);renderTable('userRank',d.top_users||[],x=>`<tr><td>${esc(x.user_email)}</td><td>${fmt(x.events)}</td><td>${fmt(x.matched)}</td></tr>`,3);renderTable('targetRank',d.top_targets||[],x=>`<tr><td class="aa-mono">${esc(x.target)}</td><td>${fmt(x.events)}</td><td>${fmt(x.matched)}</td></tr>`,3);const c=d.coverage||{};let msg=`趋势来源：${c.trend_source||'-'}；排行明细从 ${c.ranking_from?new Date(c.ranking_from*1000).toLocaleString('zh-CN'):'-'} 起统计。`;if(c.aggregate_start)msg+=` 聚合数据最早：${new Date(c.aggregate_start*1000).toLocaleString('zh-CN')}。`;if(document.getElementById('aNode').value)msg+=' 节点筛选时封禁数显示“—”，因为历史 ban log 没有 node_id。';document.getElementById('coverageHint').textContent=msg}catch(e){document.getElementById('coverageHint').textContent='加载失败：'+e.message}finally{btn.disabled=false}}
function renderTable(id,rows,rowFn,colspan){document.getElementById(id).innerHTML=rows.length?rows.map(rowFn).join(''):`<tr><td colspan="${colspan}" class="aa-muted">暂无数据</td></tr>`}
function renderTrend(rows){const svg=document.getElementById('trendChart');if(!rows.length){svg.innerHTML='<text x="500" y="130" text-anchor="middle" fill="#888780">暂无趋势数据</text>';return}const W=1000,H=260,p=28,max=Math.max(1,...rows.map(x=>Math.max(Number(x.events)||0,Number(x.matched)||0))),step=(W-p*2)/Math.max(1,rows.length-1),y=v=>H-p-(Number(v||0)/max)*(H-p*2);const path=k=>rows.map((r,i)=>(i?'L':'M')+(p+i*step).toFixed(1)+' '+y(r[k]).toFixed(1)).join(' ');let grid='';for(let i=0;i<5;i++){const yy=p+i*(H-p*2)/4;grid+=`<line x1="${p}" x2="${W-p}" y1="${yy}" y2="${yy}" stroke="#e5e7eb" stroke-width="1"/>`}svg.innerHTML=grid+`<path d="${path('events')}" fill="none" stroke="#378ADD" stroke-width="2" vector-effect="non-scaling-stroke"/><path d="${path('matched')}" fill="none" stroke="#E24B4A" stroke-width="2" vector-effect="non-scaling-stroke"/>`}
const BOOL_KEYS=['auto_ban_enabled','node_health_enabled','node_spike_enabled','analytics_enabled'];
const SET_KEYS=['alert_chat_id','default_threshold','default_window_minutes','auto_ban_enabled','report_retention_days','node_health_enabled','node_offline_minutes','node_offline_cooldown','node_spike_enabled','node_spike_window','node_spike_growth_pct','node_spike_min_hits','node_spike_cooldown','access_log_retention_days','analytics_enabled','analytics_retention_days'];
async function loadSettings(){try{const r=await api('/plugin/access-audit/settings');settingsCache=r.data||{};for(const key of SET_KEYS){const el=document.getElementById('s_'+key),item=settingsCache[key];if(!el||!item)continue;if(BOOL_KEYS.includes(key))el.checked=Number(item.value)===1||item.value===true;else el.value=item.value??''}}catch(e){showMsg('settingsMsg','加载设置失败：'+e.message,false)}}
async function saveSettings(){const btn=document.getElementById('saveBtn');btn.disabled=true;const payload={};for(const key of SET_KEYS){const el=document.getElementById('s_'+key);if(!el)continue;if(BOOL_KEYS.includes(key))payload[key]=!!el.checked;else if(el.type==='number')payload[key]=parseInt(el.value,10);else payload[key]=el.value.trim()}try{const r=await api('/plugin/access-audit/settings',payload);settingsCache=r.data||{};showMsg('settingsMsg','设置已保存。上报处理与健康监控缓存已刷新。',true);loadSettings()}catch(e){showMsg('settingsMsg','保存失败：'+e.message,false)}finally{btn.disabled=false}}
(function(){const s=loadSession();if(s&&s.token){token=s.token;enter(s.email||'admin')}else{document.getElementById('loginWrap').style.display=''}})();
</script>
</body>
</html>
