<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>订阅泄露风险分析</title>
    <style>
:root{font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI","Microsoft YaHei",sans-serif;color:#34415c;background:#f2f6f9;font-synthesis:none;--accent:#00a3ad;--link:#0874ff;--border:#e5ebf2;--drawer-width:31.3vw}
*{box-sizing:border-box}body{margin:0;min-width:360px}.page{padding:12px 14px 20px;width:100%}.top{height:27px;display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:12px}.top h1{margin:0;color:#111b2e;font-size:24px;line-height:27px;font-weight:800;letter-spacing:-.03em}.top p{display:none}.actions,.head-actions,.pager{display:flex;align-items:center;gap:7px}.button{height:32px;min-width:30px;padding:0 11px;border:1px solid #d7e0eb;border-radius:5px;background:#fff;color:#34415c;font:inherit;font-size:12px;cursor:pointer;white-space:nowrap}.button:hover{background:#f4f8fc}.button.primary{background:linear-gradient(135deg,#08acb6,#00939f);border-color:#009ba6;color:#fff}.button:disabled{opacity:.4;cursor:not-allowed}.filters{display:grid;grid-template-columns:1.2fr 1.2fr 1fr;align-items:center;gap:12px;height:auto;min-height:57px;margin:0 0 14px;padding:10px 12px;background:#fff;border:1px solid var(--border);border-radius:7px}.filters label{display:flex;align-items:center;gap:10px;color:#46546e;font-size:12px;white-space:nowrap;min-width:0}.filters label>span{flex:none}.filters input{min-width:0;width:100%;height:32px;padding:6px 10px;border:1px solid #dbe4ee;border-radius:4px;background:#fff;color:#34415c;font:inherit;font-size:12px}.filters input::placeholder{color:#99a8bf}.date-inputs{display:flex;align-items:center;gap:4px;min-width:0;height:32px;padding:0 5px;border:1px solid #dbe4ee;border-radius:4px;background:#fff}.date-inputs input{width:112px;height:28px;padding:3px 4px;border:0;font-size:11px}.date-inputs>span{font-size:11px;color:#8693aa}.filter-actions{display:flex;gap:8px}.metrics{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:14px}.metric{height:78px;display:flex;align-items:center;gap:18px;padding:10px 16px;background:#fff;border:1px solid var(--border);border-radius:7px}.metric-icon{display:grid;place-items:center;flex:none;width:54px;height:54px;border-radius:50%;background:#edf5ff;color:#2585ff}.metric-icon svg{width:28px;height:28px}.metric:nth-child(2) .metric-icon{background:#e8faf4;color:#00c696}.metric:nth-child(3) .metric-icon{background:#fff5e9;color:#f28a08}.metric:nth-child(4) .metric-icon{background:#fff0f2;color:#f32642}.metric-label{font-size:12px;color:#63718a;line-height:18px}.metric-value{font-size:24px;color:#111b2e;font-weight:800;line-height:28px}.panel{margin-top:14px;background:#fff;border:1px solid var(--border);border-radius:7px;overflow:hidden}.panel-head{height:50px;display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 15px}.panel-title{display:flex;align-items:center;gap:10px}.panel-head h2{margin:0;font-size:16px;color:#111b2e;font-weight:750}.counter{font-size:12px;color:#46546e;white-space:nowrap}.page-size{height:28px;padding:3px 7px;border:1px solid #dbe4ee;border-radius:4px;background:#fff;color:#46546e;font:inherit;font-size:11px}.pager-label{font-size:11px;white-space:nowrap;color:#6e7a92}.pager .button{height:28px;min-width:27px;padding:0 7px;border-color:#e1e8f1;font-size:11px}.pager-pages{display:flex;gap:5px;align-items:center}.pager-pages .current{background:linear-gradient(135deg,#0fb1be,#079ca8);border-color:#079ca8;color:#fff}.pager-gap{font-size:11px;color:#79859a;padding:0 2px}.table-wrap{overflow:auto;padding:0 7px 8px}.table{width:100%;border-collapse:collapse;table-layout:fixed}.table th,.table td{padding:6px 12px;text-align:left;vertical-align:middle;border-bottom:1px solid #edf1f6;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;height:33px;line-height:20px}.table th{height:31px;background:#f3f6f9;color:#46546e;font-size:11px;font-weight:650}.table td{font-size:12px;color:#46546e}.table tbody tr:last-child td{border-bottom:0}.table tbody tr:hover,.table tbody tr.selected{background:#e7f2ff}.account{font-weight:400}.subtle{color:#8b98ae;font-size:10px}.pill{display:inline-flex;align-items:center;min-height:21px;margin:0 3px 0 0;padding:1px 6px;border:1px solid #e1e7ef;border-radius:4px;background:#f3f6f9;color:#67758d;font-size:11px;line-height:17px;white-space:nowrap}.pill.red{background:#fff0f1;border-color:#ffbcc2;color:#ff3a48}.pill.orange{background:#fff5e8;border-color:#ffd18c;color:#ee8a00}.pill.green,.pill.blacklisted{background:#edfff5;border-color:#b4ebd0;color:#19b37b}.ip,.iplark-link{font-family:inherit;font-weight:400}.iplark-link{color:var(--link);text-decoration:underline;text-underline-offset:2px}.row-button{border:0;background:transparent;padding:0;color:var(--link);font:inherit;font-size:12px;cursor:pointer;white-space:nowrap}.empty{padding:20px;text-align:center;color:#8490a5;font-size:12px}.rank-tabs{display:flex;align-items:center;gap:10px;height:42px;overflow:auto;padding:0 19px;border-bottom:1px solid var(--border);white-space:nowrap}.rank-tab{height:42px;flex:none;border:0;border-bottom:2px solid transparent;padding:0 9px;background:#fff;color:#46546e;font:inherit;font-size:12px;font-weight:550;cursor:pointer}.rank-tab.active{border-bottom-color:var(--accent);color:var(--accent);font-weight:700}.drawer{position:fixed;right:15px;top:var(--drawer-top,266px);bottom:20px;z-index:30;width:var(--drawer-width);display:flex;flex-direction:column;background:#fff;border:1px solid var(--border);border-top:0;border-radius:0 0 7px 0;transform:translateX(calc(100% + 20px));transition:transform .18s ease}.drawer.open{transform:translateX(0)}.drawer-head{display:flex;align-items:center;justify-content:space-between;flex:none;height:46px;padding:10px 13px;border-bottom:1px solid #edf1f6}.drawer-head h2{margin:0;color:#111b2e;font-size:16px}.drawer-close{width:22px;height:24px;padding:0;border:0;background:#fff;color:#67758d;font-size:19px;cursor:pointer}.drawer-body{overflow:auto;min-height:0;overscroll-behavior:contain}.drawer-backdrop{display:none}body.drawer-open #logs-panel .table-wrap,body.drawer-open #rankings-panel .rank-tabs,body.drawer-open #rankings-panel .rank-table,body.drawer-open #rankings-panel #rank-empty{width:calc(100% - var(--drawer-width))}.detail-grid{padding:13px;display:grid;gap:13px}.detail-summary{padding:0;border:0;background:#fff}.detail-summary h3{margin:0 0 6px;color:#111b2e;font-size:16px;overflow-wrap:anywhere}.detail-summary p{margin:5px 0;color:#67758d;font-size:12px;line-height:1.5}.detail-content h3,.detail-section h3{margin:0 0 8px;color:#111b2e;font-size:14px}.fact-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:6px;margin-bottom:14px}.fact{min-width:0;padding:8px;border:1px solid var(--border);border-radius:4px;background:#fafbfd}.fact label{display:block;color:#8693aa;font-size:10px;margin-bottom:3px}.fact div{font-size:12px;color:#46546e;overflow-wrap:anywhere;white-space:pre-wrap}.blacklist-button{height:30px;padding:3px 10px;margin:0 4px 0 0;border:1px solid #ed4551;border-radius:4px;background:#f5263c;color:#fff;font:inherit;font-size:11px;cursor:pointer}.blacklist-button[data-blacklist-action="remove"]{background:#fff;border-color:#d9e2eb;color:#62718a}.blacklist-button:disabled{opacity:.5;cursor:default}.ip-list,.account-list{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:7px;margin-bottom:12px}.ip-card,.account-card{padding:9px;border:1px solid var(--border);border-radius:4px;background:#fafbfd;min-width:0;font-size:12px}.ip-card small,.account-card small{display:block;margin:5px 0;color:#77849a;font-size:11px;line-height:1.4}.ip-hero{display:flex;align-items:center;gap:7px;flex-wrap:wrap}.ip-hero .iplark-link{font-size:20px;font-weight:700;text-decoration:none}.ip-hero .blacklist-button{margin-left:auto}.detail-location{display:flex;align-items:center;gap:5px;margin-top:10px;color:#64718a;font-size:12px}.detail-stats{display:grid;grid-template-columns:.8fr .7fr .7fr 1.4fr 1.4fr;margin:16px -13px 0;border-top:1px solid var(--border);border-bottom:1px solid var(--border)}.detail-stat{padding:10px 7px;border-right:1px solid var(--border);min-width:0}.detail-stat:last-child{border:0}.detail-stat:nth-child(n+4) strong{font-size:11px}.detail-stat strong{display:block;color:#111b2e;font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.detail-stat small{display:block;margin-top:5px;color:#8a97ac;font-size:10px;white-space:nowrap}.detail-section{margin-top:13px}.detail-section-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:8px}.detail-section-head h3{margin:0}.detail-table{width:100%;border-collapse:collapse;table-layout:fixed}.detail-table th,.detail-table td{padding:6px 7px;border-bottom:1px solid #edf1f6;text-align:left;font-size:11px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;line-height:16px}.detail-table th{background:#f3f6f9;font-size:10px;color:#46546e}.detail-table tr:last-child td{border-bottom:0}.detail-table td{color:#46546e}.detail-table .blacklist-button{height:22px;padding:1px 4px;font-size:9px}.detail-table .pill{font-size:9px;min-height:18px;line-height:14px;padding:1px 3px}.risk-labels{display:flex;gap:6px;flex-wrap:wrap}.risk-labels .pill{margin:0}.rank-number{display:inline-grid;place-items:center;width:18px;height:18px;font-size:10px;border-radius:50%;color:#66758d}.rank-number.rank-1{background:#f5b400;color:#fff}.rank-number.rank-2{background:#b7c4d3;color:#fff}.rank-number.rank-3{background:#d67820;color:#fff}.toast{position:fixed;left:50%;bottom:20px;z-index:50;transform:translateX(-50%);padding:10px 14px;background:#263952;color:#fff;border-radius:6px;font-size:12px}#logs-panel .table th:nth-child(1){width:15%}#logs-panel .table th:nth-child(2){width:18%}#logs-panel .table th:nth-child(3){width:10%}#logs-panel .table th:nth-child(4){width:9%}#logs-panel .table th:nth-child(5){width:13%}#logs-panel .table th:nth-child(6){width:15%}#logs-panel .table th:nth-child(7){width:9%}#logs-panel .table th:nth-child(8){width:8%}#logs-panel .table th:nth-child(9){width:5%}#rankings-panel[data-active-rank="ip_details"] th:nth-child(1),#rankings-panel[data-active-rank="shared_ips"] th:nth-child(1),#rankings-panel[data-active-rank="high_risk_ips"] th:nth-child(1){width:7%}#rankings-panel[data-active-rank="ip_details"] th:nth-child(2),#rankings-panel[data-active-rank="shared_ips"] th:nth-child(2),#rankings-panel[data-active-rank="high_risk_ips"] th:nth-child(2){width:18%}
html{scrollbar-width:none}html::-webkit-scrollbar{width:0}:root{--drawer-width:31.2vw}.page{padding-left:13px;padding-right:12px}.top{padding-left:7px}.top h1{font-size:25px;line-height:28px}.panel{margin-top:15px;background:#fefefe}.filters,.metric{background:#fefefe}.table-wrap{padding:0 6px 11px}.table th,.detail-table th{background:#f4f7f9}.table td{color:#414d63}.rank-table .table td{height:29px;padding-top:3px;padding-bottom:3px}.metric{gap:25px;padding-left:17px}.drawer{right:12px;background:#fefefe}.drawer-head{padding-left:12px}.detail-grid{padding:13px 15px}.detail-stats{margin-left:-15px;margin-right:-15px}.rank-tabs{overflow-x:auto;overflow-y:hidden}.metric-trend{align-self:center;margin-top:18px;font-size:11px;white-space:nowrap}.metric-trend strong{display:block;color:#f34755;font-weight:500;line-height:17px}.metric-trend strong.down{color:#19b37b}.metric-trend strong.flat{color:#8693aa}.metric-trend small{display:block;color:#95a3b9;font-size:10px;line-height:14px}.detail-section{margin-top:18px}.detail-table .detail-inline-action{display:none;position:absolute;right:4px;top:3px}.detail-table td:first-child{position:relative}.detail-table tr:hover .detail-inline-action,.detail-table tr:focus-within .detail-inline-action{display:inline-flex}.table td:last-child,.table th:last-child{padding-left:5px;padding-right:5px}
.rank-tab b{display:inline-block;margin-left:4px;padding:1px 5px;border-radius:9px;background:#eef2f6;color:#738097;font-size:10px;font-weight:500;line-height:16px}.rank-tab.active b{background:#e5f6f7;color:#009ba6}.rank-tabs{scrollbar-width:none}.rank-tabs::-webkit-scrollbar{height:0}
.list-identity{display:flex;align-items:center;gap:5px;min-width:0;max-width:100%}.list-identity-value{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.blacklist-state{display:inline-flex;align-items:center;flex:none;gap:2px;padding:0 4px;height:18px;border:1px solid #ffc4cc;border-radius:4px;background:#fff1f3;color:#d73749;font-size:9px;font-weight:600;line-height:16px;white-space:nowrap;vertical-align:middle}
#rankings-panel[data-active-rank="traffic_users"] th:nth-child(1){width:24%}#rankings-panel[data-active-rank="traffic_users"] th:nth-child(2){width:18%}#rankings-panel[data-active-rank="traffic_users"] th:nth-child(3),#rankings-panel[data-active-rank="traffic_users"] th:nth-child(4),#rankings-panel[data-active-rank="traffic_users"] th:nth-child(5){width:10%}#rankings-panel[data-active-rank="traffic_users"] th:nth-child(6){width:8%}#rankings-panel[data-active-rank="traffic_users"] th:nth-child(7){width:14%}#rankings-panel[data-active-rank="traffic_users"] th:nth-child(8){width:6%}
@media(min-width:1800px){:root{--drawer-width:30vw}.filters{grid-template-columns:320px 320px minmax(140px,1fr) minmax(170px,1fr) minmax(190px,1fr) auto}}
@media(max-width:1100px){.filters{height:auto;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.date-inputs input{width:100%}.filter-actions{justify-self:end}.drawer{top:var(--drawer-top,300px);width:38vw}body.drawer-open #logs-panel .table-wrap,body.drawer-open #rankings-panel .rank-tabs,body.drawer-open #rankings-panel .rank-table{width:62%}.table{min-width:850px}.metric{gap:10px;padding:10px}.metric-icon{width:42px;height:42px}}
@media(max-width:720px){.head-actions{max-width:100%;margin-left:0}.pager{max-width:100%;overflow:auto}.pager .button,.pager-label,.page-size{flex:none}.page{padding:12px}.top{height:auto;align-items:flex-start}.top h1{font-size:20px}.top .button{font-size:10px;padding:0 7px}.filters{grid-template-columns:1fr;gap:9px}.filters label{display:grid;grid-template-columns:75px 1fr}.date-inputs{width:100%}.date-inputs input{width:45%}.filter-actions{justify-self:end}.metrics{grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.metric{height:70px}.metric-label{font-size:11px}.metric-value{font-size:21px}.panel-head{height:auto;min-height:50px;flex-wrap:wrap;padding:10px}.head-actions{margin-left:auto}.panel-head h2{font-size:14px}.drawer{top:0;right:0;bottom:0;width:100vw;border-radius:0}.drawer.open{box-shadow:-10px 0 30px #0002}body.drawer-open #logs-panel .table-wrap,body.drawer-open #rankings-panel .rank-tabs,body.drawer-open #rankings-panel .rank-table,body.drawer-open #rankings-panel #rank-empty{width:100%}.detail-stats{grid-template-columns:repeat(5,minmax(0,1fr))}.detail-stat{padding:9px 5px}.detail-stat strong{font-size:11px}}
</style>
</head>
<body>
<main class="page">
    <header class="top">
        <div><h1>订阅泄露风险分析</h1><p>监控订阅访问记录，按 IP、账号和网络风险定位异常线索</p></div>
        <div class="actions"><button id="refresh" class="button" type="button">⟳ 刷新</button><button id="logout" class="button" type="button">↪ 退出</button></div>
    </header>

    <form id="filters" class="filters">
        <label class="date-range"><span>访问时间</span><div class="date-inputs"><input name="start" type="date" aria-label="开始日期"><span>至</span><input name="end" type="date" aria-label="结束日期"></div></label>
        <label class="date-range"><span>注册时间</span><div class="date-inputs"><input name="registered_start" type="date" aria-label="注册开始日期"><span>至</span><input name="registered_end" type="date" aria-label="注册结束日期"></div></label>
        <label class="wide">邮箱 / 账号<input name="email" maxlength="64" placeholder="输入邮箱或账号"></label>
        <label class="wide">IP 段<input name="ip_range" maxlength="128" placeholder="IP 或 CIDR，如 1.2.3.0/24"></label>
        <label class="wide">User-Agent<input name="user_agent" maxlength="512" placeholder="输入 User-Agent 关键词"></label>
        <div class="filter-actions"><button class="button primary" type="submit">⌕ 筛选</button><button id="reset" class="button" type="button">重置</button></div>
    </form>

    <section class="metrics" aria-label="访问概况">
        <article class="metric"><span class="metric-icon"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M6 2h8l5 5v14a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V3a1 1 0 0 1 1-1zm7 2v5h5z"/><path d="M8 12h7M8 15h7M8 18h4" stroke="white" stroke-width="1.3" fill="none"/></svg></span><div><div class="metric-label">访问次数</div><div id="metric-requests" class="metric-value">—</div></div><div id="trend-requests" class="metric-trend"></div></article>
        <article class="metric"><span class="metric-icon"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="12" cy="7" r="5"/><path d="M2 23v-2a10 10 0 0 1 20 0v2z"/></svg></span><div><div class="metric-label">账号数</div><div id="metric-users" class="metric-value">—</div></div><div id="trend-users" class="metric-trend"></div></article>
        <article class="metric"><span class="metric-icon"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 1a9 9 0 0 0-9 9c0 7 9 13 9 13s9-6 9-13a9 9 0 0 0-9-9z"/><circle cx="12" cy="10" r="3.5" fill="white"/></svg></span><div><div class="metric-label">IP 数</div><div id="metric-ips" class="metric-value">—</div></div><div id="trend-ips" class="metric-trend"></div></article>
        <article class="metric"><span class="metric-icon"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="m12 1 9 4v7c0 6-9 11-9 11S3 18 3 12V5z"/><path d="M12 6v7" stroke="white" stroke-width="2"/><circle cx="12" cy="17" r="1.2" fill="white"/></svg></span><div><div class="metric-label">高风险 IP</div><div id="metric-high-risk" class="metric-value">—</div></div><div id="trend-high-risk" class="metric-trend"></div></article>
    </section>

    <section class="panel" id="logs-panel">
        <div class="panel-head">
            <div class="panel-title"><h2>原始订阅访问记录</h2><span id="logs-count" class="counter">加载中</span></div>
            <div class="head-actions"><div class="pager"><span class="pager-label">每页显示</span><select id="log-page-size" class="page-size" aria-label="原始记录每页显示条数"><option value="10" selected>10 条</option><option value="20">20 条</option><option value="50">50 条</option></select><button id="logs-first" class="button" type="button" aria-label="原始记录首页">«</button><button id="logs-previous" class="button" type="button" aria-label="上一页">‹</button><div id="logs-pages" class="pager-pages"></div><span id="logs-page" hidden></span><button id="logs-next" class="button" type="button" aria-label="下一页">›</button><button id="logs-last" class="button" type="button" aria-label="原始记录末页">»</button></div></div>
        </div>
        <div class="table-wrap"><table class="table"><thead><tr><th>时间</th><th>账号</th><th>套餐</th><th>分组</th><th>访问 IP</th><th>地区</th><th>网络风险</th><th>规则结果</th><th>详情</th></tr></thead><tbody id="logs"></tbody></table></div>
        <div id="logs-empty" class="empty" hidden>没有符合条件的访问记录。</div>
    </section>

    <section class="panel" id="rankings-panel">
        <div class="panel-head">
            <div class="panel-title"><h2>线索排行榜</h2><span id="rank-count" class="counter">加载中</span></div>
            <div class="head-actions"><div class="pager"><span class="pager-label">每页显示</span><select id="page-size" class="page-size" aria-label="排行榜每页显示条数"><option value="10" selected>10 条</option><option value="20">20 条</option><option value="50">50 条</option></select><button id="rank-first" class="button" type="button" aria-label="排行榜首页">«</button><button id="previous" class="button" type="button" aria-label="上一页">‹</button><div id="rank-pages" class="pager-pages"></div><span id="page" hidden></span><button id="next" class="button" type="button" aria-label="下一页">›</button><button id="rank-last" class="button" type="button" aria-label="排行榜末页">»</button></div></div>
        </div>
        <nav id="rank-tabs" class="rank-tabs" aria-label="排行线索分类"></nav>
        <div class="table-wrap rank-table"><table class="table"><thead id="rank-head"></thead><tbody id="rank-body"></tbody></table></div>
        <div id="rank-empty" class="empty" hidden>当前筛选范围没有此类记录。</div>
    </section>
</main>

<div id="drawer-backdrop" class="drawer-backdrop"></div>
<aside id="detail-drawer" class="drawer" aria-hidden="true" role="dialog" aria-labelledby="drawer-title">
    <header class="drawer-head"><h2 id="drawer-title">访问详情</h2><button id="drawer-close" class="drawer-close" type="button" aria-label="关闭详情">×</button></header>
    <div id="drawer-body" class="drawer-body"></div>
</aside>
<div id="toast" class="toast" hidden></div>

<script>
const form=document.querySelector('#filters');
const baseUrl='{{ $analysisBaseUrl }}';
const rankingsUrl=baseUrl+'/rankings';
const logsUrl=baseUrl+'/logs';
const blacklistUrl=baseUrl+'/blacklist';
const accountIpsUrl=baseUrl+'/account-ips';
let page=1,pageSize=10,logPage=1,logPageSize=10,rankingPayload=null,logsPayload=null,activeRank='ip_details',selectedIndex=null,activeLogDetailIndex=null,toastTimer=null,firstLoad=true,rankingRequestId=0,logRequestId=0,drawerPositionFrame=0,drawerRequestId=0,accountIpView=null;
const escapeHtml=value=>String(value??'').replace(/[&<>"']/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
const formatDate=value=>{if(!value)return '—';const date=new Date(value);if(Number.isNaN(date.getTime()))return '—';const pad=part=>String(part).padStart(2,'0');return date.getFullYear()+'-'+pad(date.getMonth()+1)+'-'+pad(date.getDate())+' '+pad(date.getHours())+':'+pad(date.getMinutes())+':'+pad(date.getSeconds())};
const shortDate=value=>formatDate(value).slice(0,16);
const formatNumber=value=>Number(value||0).toLocaleString('zh-CN');
const formatRegisteredAt=value=>value?formatDate(new Date(Number(value)*1000)):'—';
function formatTraffic(value){
    if(value==null||value==='')return '—';
    let amount=Number(value);if(!Number.isFinite(amount))return '—';amount=Math.max(0,amount);
    const units=['B','K','M','G','T','P','E'];let unit=0;
    while(amount>=1024&&unit<units.length-1){amount/=1024;unit++}
    if(Number(amount.toFixed(2))>=1024&&unit<units.length-1){amount/=1024;unit++}
    return Number(amount.toFixed(2)).toLocaleString('zh-CN',{maximumFractionDigits:2})+' '+units[unit];
}
const ipLink=(value,label=value)=>value?'<a class="iplark-link" title="'+escapeHtml(value)+' · 在 IPLark 查看详情" href="https://iplark.com/'+encodeURIComponent(value)+'" target="_blank" rel="noopener noreferrer">'+escapeHtml(label)+'</a>':escapeHtml(label||'—');
const ipLinks=value=>String(value||'').split(/[,，\s]+/).filter(Boolean).map(ip=>ipLink(ip)).join(' · ')||'—';
const pill=(text,kind='')=>'<span class="pill '+kind+'">'+escapeHtml(text)+'</span>';
const riskPill=(score,isProxy=false)=>score==null&&!isProxy?pill('未知'):Number(score)>=70?pill('高风险','red'):(Number(score)>=40||isProxy?pill('中风险','orange'):pill('低风险','green'));
const rankNumber=number=>'<span class="rank-number rank-'+number+'">'+number+'</span>';
const blacklistState=(listed,type,entry=null)=>listed?'<span class="blacklist-state" data-blacklist-kind="'+type+'" title="'+escapeHtml((type==='email'?'邮箱':'IP')+'已拉黑'+(entry?'，命中 '+entry:''))+'" aria-label="'+(type==='email'?'邮箱':'IP')+'已拉黑">🔒 已拉黑'+(type==='ip'&&String(entry||'').includes('/')?' /'+escapeHtml(String(entry).split('/')[1]):'')+'</span>':'';
const listIdentity=(html,listed,type,entry=null)=>'<span class="list-identity"><span class="list-identity-value">'+html+'</span>'+blacklistState(listed,type,entry)+'</span>';
const relatedEmailBlacklist=accounts=>(accounts||[]).some(account=>account.email_blacklisted)?'<span class="blacklist-state" title="关联账号中包含已拉黑的邮箱">🔒 含已拉黑邮箱</span>':'';
const detailFacts=items=>'<div class="fact-grid">'+items.map(([label,value])=>'<div class="fact"><label>'+escapeHtml(label)+'</label><div>'+(/请求 IP|X-Forwarded-For|X-Real-IP/i.test(label)?ipLinks(value):escapeHtml(value??'—'))+'</div></div>').join('')+'</div>';
const accountUsageHtml=account=>'<section class="detail-section" style="margin-top:0"><h3>账号信息（当前）</h3>'+detailFacts([['注册时间',formatRegisteredAt(account?.registered_at)],['已用流量',formatTraffic(account?.traffic_used)],['剩余流量',formatTraffic(account?.traffic_remaining)],['总流量',formatTraffic(account?.traffic_total)]])+'</section>';
function ipBlacklistTarget(value){
    const address=String(value||'').split('/')[0],octets=address.split('.');
    return octets.length===4&&octets.every(part=>/^\d{1,3}$/.test(part)&&Number(part)<=255)?octets.slice(0,3).join('.')+'.0/24':String(value||'');
}
function blacklistButton(type,value,blacklisted=false,entry=value){
    if(!value)return '';
    const target=type==='ip'?ipBlacklistTarget(value):value,ipv4Range=type==='ip'&&target.endsWith('/24')&&!target.includes(':');
    const addButton='<button class="blacklist-button" type="button" data-blacklist-action="add" data-blacklist-type="'+type+'" data-blacklist-value="'+escapeHtml(target)+'">'+(ipv4Range?'拉黑 /24 网段':type==='ip'?'拉黑 IP':'拉黑邮箱')+'</button>';
    if(!blacklisted)return addButton;
    const matched=String(entry||value),isRange=type==='ip'&&matched.includes('/');
    const status=type==='email'?'邮箱已拉黑':isRange?'网段已拉黑':'单 IP 已拉黑';
    let html='<span class="pill blacklisted">🔒 '+status+'</span><button class="blacklist-button" type="button" data-blacklist-action="remove" data-blacklist-type="'+type+'" data-blacklist-value="'+escapeHtml(matched)+'">解除'+(isRange?'网段':'拉黑')+'</button>';
    if(ipv4Range&&(!isRange||Number(matched.split('/')[1])>24))html+=addButton;
    return html;
}
const tabInfo={
    ip_details:{label:'IP 明细',summary:'distinct_ips'},
    shared_ips:{label:'共享 IP',summary:'shared_ip_count'},
    short_term_spread:{label:'短时扩散',summary:'short_term_spread_count'},
    multi_ip_users:{label:'多 IP 访问',summary:'multi_ip_user_count'},
    cross_region_users:{label:'跨地区切换',summary:'cross_region_user_count'},
    proxy_users:{label:'代理 / 机房来源',summary:'proxy_user_count'},
    shared_user_agents:{label:'共享 User-Agent',summary:'shared_user_agent_count'},
    high_risk_ips:{label:'高风险 IP',summary:'high_risk_ip_count'},
    traffic_users:{label:'已用流量 ↑',summary:'traffic_user_count'}
};

function rankingRows(){return rankingPayload?.rankings?.[activeRank]||[]}
function countForTab(){return rankingPayload?.summary?.[tabInfo[activeRank].summary]??rankingRows().length}
function accountEvidence(userId){return (rankingPayload?.account_evidence||[]).find(row=>Number(row.user_id)===Number(userId))}
function ipsForAccounts(accounts){
    const byIp=new Map();
    (accounts||[]).forEach(account=>{
        const evidence=accountEvidence(account.user_id);
        (evidence?.ips||[]).forEach(ip=>{
            const existing=byIp.get(ip.ip);
            if(existing){existing.request_count+=Number(ip.request_count||0);existing.related_users+=1;existing.is_proxy=existing.is_proxy||ip.is_proxy;existing.max_fraud_score=Math.max(Number(existing.max_fraud_score||0),Number(ip.max_fraud_score||0));if(ip.first_seen_at&&(!existing.first_seen_at||ip.first_seen_at<existing.first_seen_at))existing.first_seen_at=ip.first_seen_at;if(ip.last_seen_at&&(!existing.last_seen_at||ip.last_seen_at>existing.last_seen_at))existing.last_seen_at=ip.last_seen_at;}
            else byIp.set(ip.ip,{...ip,request_count:Number(ip.request_count||0),related_users:1});
        });
    });
    return [...byIp.values()].sort((left,right)=>right.request_count-left.request_count);
}
function showToast(message){const toast=document.querySelector('#toast');toast.textContent=message;toast.hidden=false;clearTimeout(toastTimer);toastTimer=setTimeout(()=>toast.hidden=true,2600)}
function updateDrawerPosition(){
    const drawer=document.querySelector('#detail-drawer');
    if(!drawer.classList.contains('open'))return;
    if(innerWidth<=720){drawer.style.removeProperty('--drawer-top');return}
    // 以列表内容的实际位置为起点，页面滚动后在视口顶部保持间距。
    const anchorTop=document.querySelector('#logs-panel .table-wrap').getBoundingClientRect().top;
    const top=Math.min(Math.max(16,anchorTop),Math.max(16,innerHeight-160));
    drawer.style.setProperty('--drawer-top',top+'px');
}
function scheduleDrawerPosition(){if(drawerPositionFrame)return;drawerPositionFrame=requestAnimationFrame(()=>{drawerPositionFrame=0;updateDrawerPosition()})}
function openDrawer(title,html){drawerRequestId++;document.querySelector('#drawer-title').textContent=title;const body=document.querySelector('#drawer-body');body.innerHTML=html;body.scrollTop=0;document.querySelector('#detail-drawer').classList.add('open');document.querySelector('#detail-drawer').setAttribute('aria-hidden','false');document.querySelector('#drawer-backdrop').classList.add('open');document.body.classList.add('drawer-open');updateDrawerPosition();ensureActiveRankTabVisible()}
function closeDrawer(){drawerRequestId++;accountIpView=null;document.querySelector('#detail-drawer').classList.remove('open');document.querySelector('#detail-drawer').setAttribute('aria-hidden','true');document.querySelector('#drawer-backdrop').classList.remove('open');document.body.classList.remove('drawer-open');selectedIndex=null;activeLogDetailIndex=null;renderRankings()}
function requestDetailHtml(row){
    const expiry=row.expired_at?formatDate(new Date(Number(row.expired_at)*1000)):'长期 / 未记录';
    const snapshotUsed=row.upload==null||row.download==null?null:Number(row.upload)+Number(row.download);
    const snapshotRemaining=row.transfer_enable==null||snapshotUsed==null?null:Math.max(0,Number(row.transfer_enable)-snapshotUsed);
    return '<div class="detail-grid"><section class="detail-summary"><h3>'+escapeHtml(row.email)+'</h3><p>用户 #'+escapeHtml(row.user_id)+' · '+formatDate(row.created_at)+'</p><p>'+escapeHtml(row.plan_name||'—')+' · '+escapeHtml(row.group_name||'—')+'</p></section><div class="detail-content">'+accountUsageHtml(row.account_profile)+'<h3>请求信息</h3>'+detailFacts([
        ['记录 ID',row.id],['请求时间',formatDate(row.created_at)],['请求方法',row.request_method],['路由',row.route],['请求域名',row.request_host],['来源页 Referer',row.referer],['客户端标记',row.client_flag],['节点类型筛选',row.requested_types],['线路筛选词',row.filter_keyword],['User-Agent',row.user_agent]
    ])+'<h3>访问 IP 与网络情报</h3>'+detailFacts([
        ['请求 IP',row.ip],['X-Forwarded-For',row.forwarded_for],['X-Real-IP',row.real_ip],['地理位置',[row.continent,row.country,row.region,row.city].filter(Boolean).join(' · ')],['国家代码',row.country_code],['ISP',row.isp],['ASN',row.asn],['AS / 企业',row.as_name],['IP 域名',row.ip_domain],['使用类型',row.usage_type],['网络速度',row.net_speed],['代理状态',row.is_proxy?'是':'否 / 未知'],['代理类型',row.proxy_type],['代理提供商',row.proxy_provider],['代理最后出现',row.proxy_last_seen],['欺诈评分',row.fraud_score],['风险标记',(row.risk_flags||[]).join('、')],['威胁信息',row.threat]
    ])+'<h3>订阅时账号快照</h3>'+detailFacts([
        ['用户 ID',row.user_id],['邮箱',row.email],['套餐',row.plan_name],['套餐 ID',row.plan_id],['分组',row.group_name],['分组 ID',row.group_id],['总流量',formatTraffic(row.transfer_enable)],['已用流量',formatTraffic(snapshotUsed)],['剩余流量',formatTraffic(snapshotRemaining)],['已用上传',formatTraffic(row.upload)],['已用下载',formatTraffic(row.download)],['限速',row.speed_limit],['设备限制',row.device_limit],['封禁状态',row.banned?'已封禁':'正常'],['到期时间',expiry]
    ])+'<h3>伪装处理</h3>'+detailFacts([
        ['处理完成',row.completed?'是':'否'],['是否伪装',row.masked?'已伪装':'未伪装'],['命中原因',row.reason],['命中内容',row.matched_value],['返回伪装域名',row.fake_domain],['处理耗时（毫秒）',row.processing_ms]
    ])+'<h3>名单操作</h3>'+blacklistButton('ip',row.ip||'',row.ip_blacklisted,row.ip_blacklist_entry||row.ip)+blacklistButton('email',row.email,row.email_blacklisted,row.email_blacklist_entry||row.email)+'</div></div>';
}
function accountCards(accounts){
    if(!accounts?.length)return '<div class="empty">没有关联账号。</div>';
    return '<div class="account-list">'+accounts.map(account=>'<article class="account-card"><strong>'+escapeHtml(account.email)+'</strong>'+(account.email_blacklisted?pill('🔒 邮箱已拉黑','blacklisted'):'')+'<small>用户 #'+escapeHtml(account.user_id)+' · '+escapeHtml(account.request_count)+' 次请求</small><small>'+escapeHtml(account.distinct_ips)+' 个 IP · '+escapeHtml(account.distinct_countries)+' 个地区 · '+escapeHtml(account.proxy_requests)+' 次代理访问</small><small>最近 '+formatDate(account.last_seen_at)+'</small><small>注册时间 '+formatRegisteredAt(account.registered_at)+'</small><small>已用 '+formatTraffic(account.traffic_used)+' · 剩余 '+formatTraffic(account.traffic_remaining)+' · 总量 '+formatTraffic(account.traffic_total)+'</small>'+blacklistButton('email',account.email,account.email_blacklisted,account.email_blacklist_entry||account.email)+'</article>').join('')+'</div>';
}
function ipCards(ips){
    if(!ips?.length)return '<div class="empty">没有 IP 明细。</div>';
    return '<div class="ip-list">'+ips.map(ip=>'<article class="ip-card"><strong class="ip">'+ipLink(ip.ip)+'</strong>'+(ip.is_blacklisted?pill('🔒 已拉黑','blacklisted'):'')+(ip.is_proxy?pill('代理','red'):'')+(ip.related_users>1?pill('共享 '+ip.related_users+' 个账号','orange'):'')+'<small>'+escapeHtml([ip.country_code,ip.country,ip.region,ip.city].filter(Boolean).join(' · ')||'地区未知')+'</small><small>'+escapeHtml([ip.as_name,ip.isp].filter(Boolean).join(' · ')||'运营商未知')+'</small><small>'+escapeHtml(ip.request_count)+' 次访问 · 风险分 '+escapeHtml(ip.max_fraud_score??'—')+' · 最近 '+formatDate(ip.last_seen_at)+'</small>'+blacklistButton('ip',ip.ip,ip.is_blacklisted,ip.blacklist_entry||ip.ip)+'</article>').join('')+'</div>';
}
function detailAccountTable(accounts){
    const rows=(accounts||[]).map((account,index)=>{const log=(logsPayload?.data||[]).find(item=>Number(item.user_id)===Number(account.user_id)),planName=account.plan_name||log?.plan_name||'—';return '<tr tabindex="0"'+(index>=5?' hidden':'')+'><td title="'+escapeHtml(account.email)+'">'+listIdentity(escapeHtml(account.email),account.email_blacklisted,'email',account.email_blacklist_entry)+'<span class="detail-inline-action">'+blacklistButton('email',account.email,account.email_blacklisted,account.email_blacklist_entry||account.email)+'</span></td><td title="'+escapeHtml(planName)+'">'+escapeHtml(planName)+'</td><td title="'+escapeHtml(formatDate(account.last_seen_at))+'">'+shortDate(account.last_seen_at)+'</td><td>'+formatNumber(account.request_count)+'</td><td><button class="row-button" style="font-size:11px" type="button" data-account-ips="'+Number(account.user_id)+'" title="查看 '+escapeHtml(account.email)+' 使用过的 IP">查看 IP</button></td></tr>'}).join('');
    return '<section class="detail-section"><div class="detail-section-head"><h3>关联账号（'+formatNumber(accounts?.length)+'）</h3>'+(accounts?.length>5?'<button class="row-button" type="button" data-expand-detail="detail-accounts-table">查看更多 ›</button>':'')+'</div><table id="detail-accounts-table" class="detail-table"><colgroup><col style="width:30%"><col style="width:17%"><col style="width:24%"><col style="width:12%"><col style="width:17%"></colgroup><thead><tr><th>账号</th><th>套餐</th><th>最近访问时间</th><th>访问次数</th><th>账号访问 IP</th></tr></thead><tbody>'+rows+'</tbody></table></section>';
}
const accountIpsBackLabel=()=>accountIpView?.parent.title==='IP 详情'?'‹ 返回 IP 详情':'‹ 返回账号详情';
function accountIpsHtml(result){
    const account=result.account,rows=(result.data||[]).map(ip=>'<tr tabindex="0"><td>'+listIdentity(ipLink(ip.ip),ip.is_blacklisted,'ip',ip.blacklist_entry)+'<span class="detail-inline-action">'+blacklistButton('ip',ip.ip,ip.is_blacklisted,ip.blacklist_entry||ip.ip)+'</span></td><td title="'+escapeHtml([...new Set([ip.country,ip.city].filter(Boolean))].join(' '))+'">'+escapeHtml([...new Set([ip.country,ip.city].filter(Boolean))].join(' ')||'—')+'</td><td>'+formatNumber(ip.request_count)+'</td><td>'+riskPill(ip.max_fraud_score,ip.is_proxy)+'</td><td title="'+escapeHtml(formatDate(ip.first_seen_at))+'">'+shortDate(ip.first_seen_at).slice(5)+'</td><td title="'+escapeHtml(formatDate(ip.last_seen_at))+'">'+shortDate(ip.last_seen_at).slice(5)+'</td></tr>').join('');
    const pageCount=Math.max(1,Math.ceil(result.total/result.page_size));
    return '<div class="detail-grid"><button class="row-button" style="justify-self:start" type="button" data-account-ips-back>'+accountIpsBackLabel()+'</button><section class="detail-summary"><h3>'+escapeHtml(account.email||'用户 #'+account.user_id)+'</h3><p>用户 #'+escapeHtml(account.user_id)+' · '+escapeHtml(account.plan_name||'—')+'</p><p>'+escapeHtml(result.start)+' 至 '+escapeHtml(result.end)+' · '+formatNumber(result.request_count)+' 次请求</p><p>仅展示这个账号在上述时间范围内使用过的 IP。</p>'+blacklistButton('email',account.email,account.email_blacklisted,account.email_blacklist_entry||account.email)+'</section>'+accountUsageHtml(account)+'<section class="detail-section" style="margin-top:0"><h3>该账号使用过的 IP（'+formatNumber(result.total)+'）</h3><div class="pager" style="margin-bottom:9px;flex-wrap:wrap"><span class="pager-label">每页</span><select class="page-size" aria-label="账号 IP 每页显示条数" data-account-ip-size>'+[10,20,50].map(size=>'<option value="'+size+'"'+(size===result.page_size?' selected':'')+'>'+size+' 条</option>').join('')+'</select><button class="button" type="button" data-account-ip-page="'+(result.page-1)+'"'+(result.page<=1?' disabled':'')+'>‹</button><span class="pager-label">第 '+result.page+' / '+pageCount+' 页</span><button class="button" type="button" data-account-ip-page="'+(result.page+1)+'"'+(result.page>=pageCount?' disabled':'')+'>›</button></div><table class="detail-table"><colgroup><col style="width:28%"><col style="width:15%"><col style="width:9%"><col style="width:14%"><col style="width:17%"><col style="width:17%"></colgroup><thead><tr><th>访问 IP</th><th>地区</th><th>次数</th><th>风险</th><th>首次访问</th><th>最近访问</th></tr></thead><tbody>'+rows+'</tbody></table>'+(rows?'':'<div class="empty">这个账号在所选时间范围内没有访问 IP 记录。</div>')+'</section></div>';
}
async function showAccountIps(userId,accountPage=1,accountPageSize=10){
    const previous=accountIpView?.userId===userId?accountIpView:null,body=document.querySelector('#drawer-body');
    const parent=previous?.parent||{title:document.querySelector('#drawer-title').textContent,html:body.innerHTML,scrollTop:body.scrollTop};
    const view={userId,page:accountPage,pageSize:accountPageSize,parent};accountIpView=view;
    openDrawer('账号访问 IP','<div class="detail-grid"><button class="row-button" type="button" data-account-ips-back>'+accountIpsBackLabel()+'</button><div class="empty">正在读取这个账号的访问 IP…</div></div>');
    const requestId=drawerRequestId,query=new URLSearchParams();query.set('user_id',userId);query.set('page',accountPage);query.set('page_size',accountPageSize);
    ['start','end','registered_start','registered_end'].forEach(key=>query.set(key,form.elements[key].value));
    try{const response=await fetch(accountIpsUrl+'?'+query,{credentials:'include'});if(response.status===401){location.href=baseUrl;return}const result=await response.json();if(requestId!==drawerRequestId||accountIpView!==view)return;if(!response.ok)throw new Error(result.message||'账号 IP 读取失败');view.page=result.page;view.pageSize=result.page_size;view.total=result.total;body.innerHTML=accountIpsHtml(result);body.scrollTop=0;updateDrawerPosition()}
    catch(error){if(requestId!==drawerRequestId||accountIpView!==view)return;body.innerHTML='<div class="detail-grid"><button class="row-button" type="button" data-account-ips-back>'+accountIpsBackLabel()+'</button><div class="empty">'+escapeHtml(error.message||'账号 IP 读取失败')+'</div></div>'}
}
function rankingDetailHtml(row){
    if(['ip_details','shared_ips','high_risk_ips'].includes(activeRank)){
        const accounts=row.accounts||[],firstSeen=accounts.map(account=>account.first_seen_at).filter(Boolean).sort()[0];
        const labels=[...(row.risk_flags||[])];if(row.distinct_users>1)labels.push('共享 IP');if(row.is_proxy)labels.push('代理 / 机房来源');
        const stats=[[row.request_count,'访问次数'],[row.distinct_users,'涉及账号数'],[row.country_count,'涉及地区数'],[firstSeen?shortDate(firstSeen):'—','首次访问'],[shortDate(row.latest_seen_at),'最后访问']];
        return '<div class="detail-grid"><section class="detail-summary"><div class="ip-hero">'+ipLink(row.ip,row.ip)+riskPill(row.max_fraud_score,row.is_proxy)+blacklistButton('ip',row.ip,row.is_blacklisted,row.blacklist_entry||row.ip)+'<a class="button" style="display:inline-flex;align-items:center;height:30px;text-decoration:none;font-size:11px" href="https://iplark.com/'+encodeURIComponent(row.ip)+'" target="_blank" rel="noopener noreferrer">查看 IPLark</a></div><div class="detail-location">⌖ '+escapeHtml([row.country,row.city,row.as_name?('('+row.as_name+')'):''].filter(Boolean).join(' ')||'地区未知')+'</div><div class="detail-stats">'+stats.map(([value,label])=>'<div class="detail-stat"><strong title="'+escapeHtml(value)+'">'+escapeHtml(value??'—')+'</strong><small>'+label+'</small></div>').join('')+'</div></section><div class="detail-content"><section class="detail-section" style="margin-top:0"><h3>风险标签</h3><div class="risk-labels">'+(labels.length?labels.map(label=>pill(label,'red')).join(''):pill('无明显风险标签','green'))+'</div></section>'+detailAccountTable(accounts)+'</div></div>';
    }
    if(activeRank==='short_term_spread'){
        const evidence=accountEvidence(row.user_id);
        return '<div class="detail-grid"><section class="detail-summary"><h3>'+escapeHtml(row.email)+'</h3><p>用户 #'+escapeHtml(row.user_id)+' · 1 小时内 '+escapeHtml(row.spread_ip_count)+' 个 IP · '+escapeHtml(row.request_count)+' 次请求</p><p>'+formatDate(row.window_start)+' 至 '+formatDate(row.window_end)+'</p>'+blacklistButton('email',row.email,row.email_blacklisted,row.email_blacklist_entry||row.email)+'</section><div class="detail-content">'+accountUsageHtml(row)+'<h3>该账号 IP</h3>'+ipCards(evidence?.ips||[])+'</div></div>';
    }
    if(['multi_ip_users','cross_region_users','proxy_users'].includes(activeRank)){
        const evidence=accountEvidence(row.user_id);let ips=evidence?.ips||[];if(activeRank==='proxy_users')ips=ips.filter(ip=>ip.is_proxy);
        return '<div class="detail-grid"><section class="detail-summary"><h3>'+escapeHtml(row.email)+'</h3><p>用户 #'+escapeHtml(row.user_id)+' · '+escapeHtml(row.request_count)+' 次请求 · 最近 '+formatDate(row.last_seen_at)+'</p>'+blacklistButton('email',row.email,row.email_blacklisted,row.email_blacklist_entry||row.email)+'</section><div class="detail-content">'+accountUsageHtml(row)+'<h3>关联账号</h3>'+accountCards(row.accounts||[row])+'<h3>相关 IP</h3>'+ipCards(ips)+'</div></div>';
    }
    if(activeRank==='traffic_users'){
        return '<div class="detail-grid"><section class="detail-summary"><h3>'+escapeHtml(row.email)+'</h3><p>用户 #'+escapeHtml(row.user_id)+' · '+escapeHtml(row.plan_name||'—')+'</p><p>所选范围内 '+formatNumber(row.request_count)+' 次订阅请求 · 最近 '+formatDate(row.last_seen_at)+'</p>'+blacklistButton('email',row.email,row.email_blacklisted,row.email_blacklist_entry||row.email)+'</section><div class="detail-content">'+accountUsageHtml(row)+'<button class="button" type="button" data-account-ips="'+Number(row.user_id)+'">查看该账号使用过的 IP</button></div></div>';
    }
    return '<div class="detail-grid"><section class="detail-summary"><h3>共享 User-Agent</h3><p>'+escapeHtml(row.user_agent)+'</p><p>'+escapeHtml(row.distinct_users)+' 个账号 · '+escapeHtml(row.distinct_ips)+' 个 IP · '+escapeHtml(row.request_count)+' 次请求</p></section><div class="detail-content"><h3>关联账号</h3>'+accountCards(row.accounts)+'<h3>关联 IP</h3>'+ipCards(ipsForAccounts(row.accounts))+'</div></div>';
}
function ensureActiveRankTabVisible(){
    const nav=document.querySelector('#rank-tabs'),active=nav.querySelector('.rank-tab.active');if(!active)return;
    const bounds=nav.getBoundingClientRect(),tab=active.getBoundingClientRect();
    if(tab.right>bounds.right)nav.scrollLeft+=tab.right-bounds.right+8;
    else if(tab.left<bounds.left)nav.scrollLeft-=bounds.left-tab.left+8;
}
function renderTabs(){
    const summary=rankingPayload?.summary||{};
    document.querySelector('#rank-tabs').innerHTML=Object.entries(tabInfo).map(([key,info])=>'<button type="button" class="rank-tab '+(key===activeRank?'active':'')+'" data-rank-tab="'+key+'">'+info.label+' <b>· '+formatNumber(summary[info.summary]??0)+'</b></button>').join('');
    ensureActiveRankTabVisible();
}
function renderMetrics(){
    const summary=rankingPayload?.summary||{};
    document.querySelector('#metric-requests').textContent=formatNumber(summary.total_requests);
    document.querySelector('#metric-users').textContent=formatNumber(summary.distinct_users);
    document.querySelector('#metric-ips').textContent=formatNumber(summary.distinct_ips);
    document.querySelector('#metric-high-risk').textContent=formatNumber(summary.high_risk_ip_count);
    const previous=rankingPayload?.previous_summary||{};
    [['requests','total_requests'],['users','distinct_users'],['ips','distinct_ips'],['high-risk','high_risk_ip_count']].forEach(([id,key])=>{const old=Number(previous[key]||0),current=Number(summary[key]||0),change=old?((current-old)/old*100):null,label=change===null?(current?'新增':'0%'):(change>0?'↑ +':change<0?'↓ ':'')+Math.abs(change).toFixed(0)+'%';document.querySelector('#trend-'+id).innerHTML='<strong class="'+(change<0?'down':change===0?'flat':'')+'">'+label+'</strong><small>较上期</small>'});
}
function renderPageButtons(id,current,total){
    let numbers=total<=7?Array.from({length:total},(_,index)=>index+1):(current<=4?[1,2,3,4,5,'…',total]:(current>=total-3?[1,'…',total-4,total-3,total-2,total-1,total]:[1,'…',current-1,current,current+1,'…',total]));
    document.querySelector('#'+id).innerHTML=numbers.map(number=>typeof number==='number'?'<button type="button" class="button '+(number===current?'current':'')+'" data-page-number="'+number+'" aria-label="第 '+number+' 页"'+(number===current?' aria-current="page"':'')+'>'+number+'</button>':'<span class="pager-gap">…</span>').join('');
}
function renderRankings(){
    document.querySelector('#rankings-panel').dataset.activeRank=activeRank;
    const rows=rankingRows(),count=rows.length,pageCount=Math.max(1,Math.ceil(count/pageSize));page=Math.min(page,pageCount);
    document.querySelector('#rank-count').textContent=formatNumber(countForTab())+' 条';
    document.querySelector('#page-size').value=String(pageSize);
    document.querySelector('#previous').disabled=page<=1;document.querySelector('#next').disabled=page>=pageCount;
    document.querySelector('#page').textContent='第 '+page+' / '+pageCount+' 页';
    renderPageButtons('rank-pages',page,pageCount);document.querySelector('#rank-first').disabled=page<=1;document.querySelector('#rank-last').disabled=page>=pageCount;
    const shown=rows.slice((page-1)*pageSize,page*pageSize);document.querySelector('#rank-empty').hidden=shown.length>0;
    let headers=[],body='';
    if(['ip_details','shared_ips','high_risk_ips'].includes(activeRank)){
        headers=['排名','IP 地址','访问次数','涉及账号','地区','网络风险','主要规则结果','操作'];
        body=shown.map((row,index)=>'<tr class="'+(selectedIndex===(page-1)*pageSize+index?'selected':'')+'"><td>'+rankNumber((page-1)*pageSize+index+1)+'</td><td>'+listIdentity(ipLink(row.ip,row.ip),row.is_blacklisted,'ip',row.blacklist_entry)+'</td><td>'+formatNumber(row.request_count)+'</td><td>'+formatNumber(row.distinct_users)+'</td><td title="'+escapeHtml([row.country,row.city].filter(Boolean).join(' '))+'">'+escapeHtml([row.country,row.city].filter(Boolean).join(' ')||'未知')+'</td><td>'+riskPill(row.max_fraud_score,row.is_proxy)+'</td><td title="'+escapeHtml((row.risk_flags||[]).join('、'))+'">'+escapeHtml((row.risk_flags||[]).join('、')||(row.distinct_users>1?'共享 IP':'正常访问'))+'</td><td><button class="row-button" type="button" data-rank-detail="'+((page-1)*pageSize+index)+'">查看</button></td></tr>').join('');
    }else if(activeRank==='short_term_spread'){
        headers=['账号','1 小时内 IP','请求数','时间范围','操作'];
        body=shown.map((row,index)=>'<tr class="'+(selectedIndex===(page-1)*pageSize+index?'selected':'')+'"><td>'+listIdentity('<span class="account" title="'+escapeHtml(row.email)+'">'+escapeHtml(row.email)+'</span><small class="subtle"> · 用户 #'+escapeHtml(row.user_id)+'</small>',row.email_blacklisted,'email',row.email_blacklist_entry)+'</td><td>'+pill(row.spread_ip_count+' 个 IP','orange')+'</td><td>'+formatNumber(row.request_count)+'</td><td>'+formatDate(row.window_start)+'<small class="subtle">至 '+formatDate(row.window_end)+'</small></td><td><button class="row-button" type="button" data-rank-detail="'+((page-1)*pageSize+index)+'">查看</button></td></tr>').join('');
    }else if(['multi_ip_users','cross_region_users','proxy_users'].includes(activeRank)){
        headers=['账号','风险线索','请求概况','最近访问','操作'];
        body=shown.map((row,index)=>{const count=activeRank==='multi_ip_users'?row.distinct_ips:(activeRank==='cross_region_users'?row.distinct_countries:row.proxy_ips);const label=activeRank==='multi_ip_users'?'个 IP':(activeRank==='cross_region_users'?'个地区':'个代理 IP');return '<tr class="'+(selectedIndex===(page-1)*pageSize+index?'selected':'')+'"><td>'+listIdentity('<span class="account" title="'+escapeHtml(row.email)+'">'+escapeHtml(row.email)+'</span><small class="subtle"> · 用户 #'+escapeHtml(row.user_id)+'</small>',row.email_blacklisted,'email',row.email_blacklist_entry)+'</td><td>'+pill(count+' '+label,activeRank==='proxy_users'?'red':'orange')+'</td><td>'+formatNumber(row.request_count??row.proxy_requests)+' 次 · '+escapeHtml(row.distinct_ips??0)+' 个 IP</td><td>'+formatDate(row.last_seen_at)+'</td><td><button class="row-button" type="button" data-rank-detail="'+((page-1)*pageSize+index)+'">查看</button></td></tr>'}).join('');
    }else if(activeRank==='traffic_users'){
        headers=['账号','注册时间','已用流量 ↑','剩余流量','总流量','订阅请求','最近访问','详情'];
        body=shown.map((row,index)=>'<tr class="'+(selectedIndex===(page-1)*pageSize+index?'selected':'')+'" data-traffic-used="'+escapeHtml(row.traffic_used??'')+'"><td title="'+escapeHtml(row.email)+'">'+listIdentity('<span class="account">'+escapeHtml(row.email)+'</span>',row.email_blacklisted,'email',row.email_blacklist_entry)+'</td><td title="'+escapeHtml(formatRegisteredAt(row.registered_at))+'">'+formatRegisteredAt(row.registered_at).slice(0,16)+'</td><td>'+formatTraffic(row.traffic_used)+'</td><td>'+formatTraffic(row.traffic_remaining)+'</td><td>'+formatTraffic(row.traffic_total)+'</td><td>'+formatNumber(row.request_count)+'</td><td title="'+escapeHtml(formatDate(row.last_seen_at))+'">'+shortDate(row.last_seen_at)+'</td><td><button class="row-button" type="button" data-rank-detail="'+((page-1)*pageSize+index)+'">查看</button></td></tr>').join('');
    }else{
        headers=['User-Agent','关联账号','访问 IP 数','请求数','操作'];
        body=shown.map((row,index)=>'<tr class="'+(selectedIndex===(page-1)*pageSize+index?'selected':'')+'"><td style="max-width:420px;overflow-wrap:anywhere">'+escapeHtml(row.user_agent)+'</td><td><span class="list-identity"><span class="list-identity-value">'+formatNumber(row.distinct_users)+' 个账号</span>'+relatedEmailBlacklist(row.accounts)+'</span></td><td>'+formatNumber(row.distinct_ips)+' 个</td><td>'+formatNumber(row.request_count)+'</td><td><button class="row-button" type="button" data-rank-detail="'+((page-1)*pageSize+index)+'">查看</button></td></tr>').join('');
    }
    document.querySelector('#rank-head').innerHTML='<tr>'+headers.map(header=>'<th>'+header+'</th>').join('')+'</tr>';
    document.querySelector('#rank-body').innerHTML=body||'<tr><td colspan="8" class="empty">当前筛选范围没有此类记录。</td></tr>';
}
function renderLogs(data){
    const rows=data.data||[];logPage=data.page||logPage;logPageSize=data.page_size||logPageSize;
    document.querySelector('#logs-count').textContent='（共 '+formatNumber(data.total)+' 条）';
    document.querySelector('#logs-page').textContent='第 '+logPage+' / '+Math.max(1,Math.ceil((data.total||0)/logPageSize))+' 页';
    const logPageCount=Math.max(1,Math.ceil((data.total||0)/logPageSize));renderPageButtons('logs-pages',logPage,logPageCount);document.querySelector('#logs-first').disabled=logPage<=1;document.querySelector('#logs-last').disabled=logPage>=logPageCount;
    document.querySelector('#logs-previous').disabled=logPage<=1;document.querySelector('#logs-next').disabled=logPage*logPageSize>=(data.total||0);
    document.querySelector('#log-page-size').value=String(logPageSize);document.querySelector('#logs-empty').hidden=rows.length>0;
    document.querySelector('#logs').innerHTML=rows.map((row,index)=>'<tr><td>'+formatDate(row.created_at)+'</td><td title="'+escapeHtml(row.email)+'">'+listIdentity('<span class="account">'+escapeHtml(row.email)+'</span>',row.email_blacklisted,'email',row.email_blacklist_entry)+'</td><td title="'+escapeHtml(row.plan_name||'—')+'">'+escapeHtml(row.plan_name||'—')+'</td><td>'+escapeHtml(row.group_name||'—')+'</td><td>'+listIdentity(ipLink(row.ip,row.ip||'—'),row.ip_blacklisted,'ip',row.ip_blacklist_entry)+'</td><td title="'+escapeHtml([row.country,row.city].filter(Boolean).join(' '))+'">'+escapeHtml([row.country,row.city].filter(Boolean).join(' ')||'—')+'</td><td title="风险分 '+escapeHtml(row.fraud_score??'未知')+'">'+riskPill(row.fraud_score,row.is_proxy)+'</td><td>'+escapeHtml(row.masked?'已伪装':(row.reason||'正常访问'))+'</td><td><button class="row-button" type="button" data-log-detail="'+index+'">查看</button></td></tr>').join('');
}
async function loadRankings(){
    const requestId=++rankingRequestId,query=new URLSearchParams(new FormData(form));document.querySelector('#rank-body').innerHTML='<tr><td colspan="8" class="empty">正在加载排行…</td></tr>';
    try{const response=await fetch(rankingsUrl+'?'+query,{credentials:'include'});if(response.status===401){location.href=baseUrl;return false}const result=await response.json();if(requestId!==rankingRequestId)return false;if(!response.ok)throw new Error(result.message||'读取排行失败');rankingPayload=result;return true}
    catch(error){if(requestId===rankingRequestId)document.querySelector('#rank-body').innerHTML='<tr><td colspan="8" class="empty">'+escapeHtml(error.message||'排行加载失败')+'</td></tr>';return false}
}
async function loadLogs(){
    const requestId=++logRequestId,query=new URLSearchParams(new FormData(form));query.set('log_page',logPage);query.set('log_page_size',logPageSize);document.querySelector('#logs').innerHTML='<tr><td colspan="9" class="empty">正在加载访问记录…</td></tr>';
    try{const response=await fetch(logsUrl+'?'+query,{credentials:'include'});if(response.status===401){location.href=baseUrl;return false}const result=await response.json();if(requestId!==logRequestId)return false;if(!response.ok)throw new Error(result.message||'读取访问记录失败');logsPayload=result;renderLogs(result);return true}
    catch(error){if(requestId===logRequestId)document.querySelector('#logs').innerHTML='<tr><td colspan="9" class="empty">'+escapeHtml(error.message||'访问记录读取失败')+'</td></tr>';return false}
}
async function loadAll(){closeDrawer();const [ranked]=await Promise.all([loadRankings(),loadLogs()]);if(ranked){renderTabs();renderMetrics();renderRankings();if(firstLoad&&innerWidth>720&&rankingRows().length)showRankingDetail(0);firstLoad=false}}
function showRankingDetail(index){const row=rankingRows()[index];if(!row)return;selectedIndex=index;activeLogDetailIndex=null;accountIpView=null;renderRankings();openDrawer(['ip_details','shared_ips','high_risk_ips'].includes(activeRank)?'IP 详情':activeRank==='traffic_users'?'账号详情':tabInfo[activeRank].label+' · 详情',rankingDetailHtml(row))}
function showLogDetail(index){const row=logsPayload?.data?.[index];if(!row)return;if(activeLogDetailIndex===index&&document.querySelector('#detail-drawer').classList.contains('open')){closeDrawer();return}selectedIndex=null;activeLogDetailIndex=index;accountIpView=null;renderRankings();openDrawer('订阅访问详情',requestDetailHtml(row))}

document.querySelector('#rank-tabs').addEventListener('click',event=>{const button=event.target.closest('[data-rank-tab]');if(!button)return;activeRank=button.dataset.rankTab;page=1;selectedIndex=null;closeDrawer();renderTabs();renderRankings()});
document.querySelector('#rank-body').addEventListener('click',event=>{if(event.target.closest('.iplark-link'))return;const button=event.target.closest('[data-rank-detail]');if(!button)return;const index=Number(button.dataset.rankDetail);if(selectedIndex===index&&document.querySelector('#detail-drawer').classList.contains('open')){closeDrawer();return}showRankingDetail(index)});
document.querySelector('#logs').addEventListener('click',event=>{if(event.target.closest('.iplark-link'))return;const button=event.target.closest('[data-log-detail]');if(button)showLogDetail(Number(button.dataset.logDetail))});
document.querySelector('#drawer-body').addEventListener('click',async event=>{
    const accountButton=event.target.closest('[data-account-ips]');if(accountButton){showAccountIps(Number(accountButton.dataset.accountIps));return}
    const backButton=event.target.closest('[data-account-ips-back]');if(backButton&&accountIpView){const parent=accountIpView.parent;accountIpView=null;openDrawer(parent.title,parent.html);document.querySelector('#drawer-body').scrollTop=parent.scrollTop;return}
    const accountPageButton=event.target.closest('[data-account-ip-page]');if(accountPageButton&&accountIpView&&!accountPageButton.disabled){showAccountIps(accountIpView.userId,Number(accountPageButton.dataset.accountIpPage),accountIpView.pageSize);return}
    const expandButton=event.target.closest('[data-expand-detail]');if(expandButton){const table=document.querySelector('#'+expandButton.dataset.expandDetail),expanded=expandButton.dataset.expanded==='true';table.querySelectorAll('tbody tr').forEach((row,index)=>row.hidden=!expanded?false:index>=5);expandButton.dataset.expanded=String(!expanded);expandButton.textContent=expanded?'查看更多 ›':'收起 ‹';return}
    const button=event.target.closest('[data-blacklist-type]');if(!button||button.disabled)return;
    const type=button.dataset.blacklistType,action=button.dataset.blacklistAction||'add',originalText=button.textContent,label=type==='ip'?'IP':'邮箱';
    const value=type==='ip'&&action==='add'?ipBlacklistTarget(button.dataset.blacklistValue):button.dataset.blacklistValue;
    if(!value){showToast('没有可拉黑的'+label);return}
    const confirmation=action==='remove'?(type==='ip'&&value.includes('/')?'确认删除网段 '+value+'？该网段内所有 IP 都会解除拉黑。':'确认将 '+value+' 从'+label+'黑名单移除？'):(type==='ip'&&value.endsWith('/24')&&!value.includes(':')?'确认拉黑 /24 网段 '+value+'？该网段的 256 个地址后续获取订阅都会返回伪装域名。':'确认将 '+value+' 加入 '+label+' 黑名单？后续匹配到该项时会返回伪装订阅。');
    if(!confirm(confirmation))return;button.disabled=true;button.textContent='正在写入…';
    try{const response=await fetch(blacklistUrl,{method:'POST',credentials:'include',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({type,action,value})});const result=await response.json();if(response.status===401){location.href=baseUrl;return}if(!response.ok)throw new Error(result.message||'黑名单写入失败');showToast(action==='remove'?(result.data?.removed?'黑名单已解除':'规则已不存在'):(result.data?.already_blacklisted?'已在黑名单中：'+(result.data.matched_entry||result.data.value):'已加入黑名单：'+result.data.value));await loadAll()}
    catch(error){button.disabled=false;button.textContent=originalText;showToast(error.message||'黑名单更新失败')}
});
document.querySelector('#drawer-body').addEventListener('change',event=>{if(event.target.matches('[data-account-ip-size]')&&accountIpView)showAccountIps(accountIpView.userId,1,Number(event.target.value))});
document.querySelector('#drawer-close').addEventListener('click',closeDrawer);
window.addEventListener('scroll',scheduleDrawerPosition,{passive:true});
window.addEventListener('resize',()=>{scheduleDrawerPosition();ensureActiveRankTabVisible()});
document.querySelector('#drawer-backdrop').addEventListener('click',closeDrawer);
document.addEventListener('keydown',event=>{if(event.key==='Escape'&&document.querySelector('#detail-drawer').classList.contains('open'))closeDrawer()});
form.addEventListener('submit',event=>{event.preventDefault();const start=form.elements.registered_start.value,end=form.elements.registered_end.value;if(start&&end&&end<start){showToast('注册结束日期不能早于注册开始日期');return}page=1;logPage=1;selectedIndex=null;loadAll()});
document.querySelector('#reset').addEventListener('click',()=>{HTMLFormElement.prototype.reset.call(form);initializeDateRange();page=1;logPage=1;selectedIndex=null;loadAll()});
document.querySelector('#refresh').addEventListener('click',loadAll);
document.querySelector('#previous').addEventListener('click',()=>{if(page>1){page--;selectedIndex=null;closeDrawer();renderRankings()}});
document.querySelector('#next').addEventListener('click',()=>{if(page*pageSize<rankingRows().length){page++;selectedIndex=null;closeDrawer();renderRankings()}});
document.querySelector('#page-size').addEventListener('change',event=>{pageSize=Number(event.target.value);page=1;selectedIndex=null;closeDrawer();renderRankings()});
document.querySelector('#logs-previous').addEventListener('click',()=>{if(logPage>1){logPage--;closeDrawer();loadLogs()}});
document.querySelector('#logs-next').addEventListener('click',()=>{if(logPage*logPageSize<(logsPayload?.total||0)){logPage++;closeDrawer();loadLogs()}});
document.querySelector('#log-page-size').addEventListener('change',event=>{logPageSize=Number(event.target.value);logPage=1;closeDrawer();loadLogs()});
document.querySelector('#logs-pages').addEventListener('click',event=>{const button=event.target.closest('[data-page-number]');if(!button)return;logPage=Number(button.dataset.pageNumber);closeDrawer();loadLogs()});
document.querySelector('#rank-pages').addEventListener('click',event=>{const button=event.target.closest('[data-page-number]');if(!button)return;page=Number(button.dataset.pageNumber);closeDrawer();renderRankings()});
document.querySelector('#logs-first').addEventListener('click',()=>{logPage=1;closeDrawer();loadLogs()});
document.querySelector('#logs-last').addEventListener('click',()=>{logPage=Math.max(1,Math.ceil((logsPayload?.total||0)/logPageSize));closeDrawer();loadLogs()});
document.querySelector('#rank-first').addEventListener('click',()=>{page=1;closeDrawer();renderRankings()});
document.querySelector('#rank-last').addEventListener('click',()=>{page=Math.max(1,Math.ceil(rankingRows().length/pageSize));closeDrawer();renderRankings()});
document.querySelector('#logout').addEventListener('click',async()=>{await fetch(baseUrl+'/logout',{method:'POST',credentials:'include'});location.reload()});
function initializeDateRange(){const toInput=value=>{const year=value.getFullYear(),month=String(value.getMonth()+1).padStart(2,'0'),day=String(value.getDate()).padStart(2,'0');return year+'-'+month+'-'+day};const end=new Date(),start=new Date(end);start.setDate(start.getDate()-6);form.elements.start.value=toInput(start);form.elements.end.value=toInput(end)}
initializeDateRange();
loadAll();
</script>
</body>
</html>
