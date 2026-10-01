<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>订阅泄露风险分析</title>
    <style>
        :root{font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI","Microsoft YaHei",sans-serif;color:#17211f;background:#f2f5f4}
        *{box-sizing:border-box}body{margin:0}.page{max-width:1280px;margin:0 auto;padding:26px 22px 42px}.top{display:flex;justify-content:space-between;align-items:center;gap:16px}.eyebrow{color:#087f72;font-size:11px;font-weight:800;letter-spacing:.12em}.top h1{margin:5px 0;font-size:25px}.top p{margin:0;color:#687572;font-size:13px}.actions{display:flex;gap:8px}.button{padding:8px 12px;border:1px solid #d6dfdc;border-radius:6px;background:#fff;color:#23322e;font:inherit;font-size:12px;cursor:pointer}.button.primary{border-color:#17403a;background:#17403a;color:#fff}.button:disabled{opacity:.45;cursor:not-allowed}.page-size{height:31px;padding:4px 8px;border:1px solid #d6dfdc;border-radius:5px;background:#fff;color:#30413c;font:inherit;font-size:11px}
        .filters{display:flex;align-items:end;gap:9px;flex-wrap:wrap;margin-top:16px;padding:12px 14px;background:#fff;border:1px solid #e0e7e4;border-radius:8px}.filters label{display:grid;gap:4px;color:#6b7975;font-size:11px}.filters input{width:150px;height:33px;padding:6px 8px;border:1px solid #d9e1de;border-radius:5px;font:inherit;font-size:12px}.filters .wide input{width:195px}.hint{margin:9px 1px 0;color:#75817e;font-size:11px}
        .panel{margin-top:13px;border:1px solid #e0e7e4;border-radius:8px;background:#fff;overflow:hidden}.panel-head{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:13px 15px;border-bottom:1px solid #e8eeeb}.panel-head h2{margin:0;font-size:15px}.panel-head p{margin:4px 0 0;color:#75817e;font-size:11px}.head-actions{display:flex;align-items:center;gap:8px}.counter{padding:4px 8px;border-radius:12px;background:#edf3f1;color:#55635f;font-size:11px;white-space:nowrap}.rank-tabs{display:flex;gap:5px;overflow:auto;padding:9px 12px 0;border-bottom:1px solid #e6ece9}.rank-tab{flex:none;margin-bottom:-1px;padding:8px 10px;border:1px solid #dce5e1;border-bottom-color:#e6ece9;border-radius:6px 6px 0 0;background:#f8faf9;color:#51615d;font:inherit;font-size:11px;white-space:nowrap;cursor:pointer}.rank-tab.active{border-color:#168075;border-bottom-color:#fff;background:#fff;color:#08766c;font-weight:750}.rank-tab b{margin-left:3px;font-size:10px}
        .table-wrap{overflow:auto}.table{width:100%;border-collapse:collapse;min-width:760px}.table th,.table td{padding:9px 11px;border-bottom:1px solid #edf1ef;text-align:left;vertical-align:middle}.table th{background:#f8faf9;color:#71807b;font-size:10px;font-weight:750}.table td{font-size:11px}.table tbody tr:hover{background:#f7faf8}.table tbody tr.selected{background:#edf7f4}.account{font-weight:750;color:#24332f}.subtle{display:block;margin-top:3px;color:#83908b;font-size:10px}.pill{display:inline-block;margin:2px 3px 2px 0;padding:3px 7px;border-radius:10px;background:#eef3f1;color:#53635e;font-size:10px;white-space:nowrap}.pill.red{background:#fff0ed;color:#b83d32}.pill.orange{background:#fff4e5;color:#a95d00}.pill.blacklisted{background:#e8f5ed;color:#24734c;font-weight:750}.ip{font-family:Consolas,"SFMono-Regular",monospace;font-weight:700;color:#36549b}.row-button{border:0;background:none;padding:0;color:#126d64;font:inherit;font-size:11px;font-weight:700;cursor:pointer}.blacklist-button{margin-top:7px;padding:5px 8px;border:1px solid #edc2bd;border-radius:5px;background:#fff8f7;color:#ad3d32;font:inherit;font-size:10px;font-weight:700;cursor:pointer}.blacklist-button:disabled{border-color:#dce5e1;background:#f3f7f5;color:#54816e;cursor:default}.empty{padding:22px;text-align:center;color:#87928e;font-size:12px}.pager{display:flex;justify-content:flex-end;align-items:center;gap:8px;padding:9px 12px}.pager span{color:#70807a;font-size:11px}
        .detail-grid{display:grid;grid-template-columns:minmax(210px,.65fr) minmax(0,1.8fr);gap:15px;padding:15px}.detail-summary{padding:13px;border:1px solid #e2e9e6;border-radius:7px;background:#fafcfb}.detail-summary h3{margin:0 0 8px;font-size:14px;overflow-wrap:anywhere}.detail-summary p{margin:5px 0;color:#64726e;font-size:11px;line-height:1.6}.detail-content h3{margin:0 0 8px;font-size:12px}.ip-list,.account-list{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:7px}.ip-card,.account-card{padding:10px;border:1px solid #e1e8e5;border-radius:6px;background:#fafcfb}.ip-card small,.account-card small{display:block;margin-top:4px;color:#71807b;font-size:10px;line-height:1.45}.timeline-list{display:grid;gap:6px}.timeline-item{display:grid;grid-template-columns:145px minmax(115px,.8fr) minmax(0,1.8fr);gap:10px;padding:8px 10px;border:1px solid #e7ecea;border-radius:5px;font-size:10px}.timeline-item span{color:#70807a}.logs-wrap{padding:11px 15px}.logs-wrap summary{color:#30413c;font-size:12px;font-weight:700;cursor:pointer}
        @media(max-width:760px){.page{padding:18px 12px 30px}.top{align-items:flex-start}.detail-grid{grid-template-columns:1fr}.filters input,.filters .wide input{width:140px}.timeline-item{grid-template-columns:1fr;gap:3px}}@media(max-width:500px){.top{display:block}.actions{margin-top:12px}.filters label,.filters .wide{flex:1 1 42%}.filters input,.filters .wide input{width:100%}.head-actions{gap:5px}}
    </style>
</head>
<body>
<main class="page">
    <header class="top">
        <div><div class="eyebrow">SUBSCRIPTION SECURITY</div><h1>订阅泄露风险分析</h1><p>按线索分类查看排行，点选一条记录看关联账号和访问明细。</p></div>
        <div class="actions"><button id="refresh" class="button primary" type="button">刷新</button><button id="logout" class="button" type="button">退出</button></div>
    </header>

    <form id="filters" class="filters">
        <label>开始日期<input name="start" type="date"></label>
        <label>结束日期<input name="end" type="date"></label>
        <label class="wide">账号关键字<input name="email" maxlength="64" placeholder="按邮箱筛选"></label>
        <button class="button primary" type="submit">筛选</button>
        <button id="reset" class="button" type="button">重置</button>
    </form>
    <p class="hint">短时扩散表示同一账号在 1 小时内出现至少 3 个 IP；共享网络、移动网络和 VPN 可能造成误报，请结合明细核查。</p>

    <section class="panel">
        <div class="panel-head"><div><h2>线索排行榜</h2><p>切换标签看不同线索，点击排行记录查看详情</p></div><div class="head-actions"><span id="rank-count" class="counter">加载中</span><label for="page-size" class="subtle" style="margin:0;white-space:nowrap">每页</label><select id="page-size" class="page-size" aria-label="每页显示条数"><option value="10" selected>10</option><option value="20">20</option><option value="50">50</option></select></div></div>
        <nav id="rank-tabs" class="rank-tabs" aria-label="排行线索分类"></nav>
        <div class="table-wrap"><table class="table"><thead id="rank-head"></thead><tbody id="rank-body"></tbody></table></div>
        <div id="rank-empty" class="empty" hidden>当前筛选范围没有此类记录。</div>
        <div class="pager"><button id="previous" class="button" type="button">上一页</button><span id="page"></span><button id="next" class="button" type="button">下一页</button></div>
    </section>

    <section class="panel">
        <div class="panel-head"><div><h2 id="detail-heading">排行详情</h2><p>选择上方一条排行记录查看具体线索</p></div></div>
        <div id="details"><div class="empty">尚未选择排行记录。</div></div>
    </section>

    <details class="panel">
        <summary class="logs-wrap">原始订阅访问记录</summary>
        <div class="table-wrap"><table class="table"><thead><tr><th>时间</th><th>账号</th><th>访问 IP</th><th>地区</th><th>网络风险</th><th>规则结果</th></tr></thead><tbody id="logs"></tbody></table></div>
        <div id="logs-empty" class="empty" hidden>没有符合条件的访问记录。</div>
    </details>
</main>
<script>
const form=document.querySelector('#filters');
const baseUrl='{{ $analysisBaseUrl }}';
const dataUrl=baseUrl+'/data';
const blacklistUrl=baseUrl+'/blacklist';
let page=1,pageSize=10,total=0,payload=null,activeRank='ip_details',selectedIndex=null;
const escapeHtml=value=>String(value??'').replace(/[&<>"']/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
const formatDate=value=>value?new Date(value).toLocaleString('zh-CN',{hour12:false}):'—';
const pill=(text,kind='')=>'<span class="pill '+kind+'">'+escapeHtml(text)+'</span>';
const blacklistButton=(type,value,blacklisted=false,entry=value)=>!value?'':(blacklisted?'<span class="pill blacklisted">🔒 已拉黑</span><button class="blacklist-button" type="button" data-blacklist-action="remove" data-blacklist-type="'+type+'" data-blacklist-value="'+escapeHtml(entry||value)+'">解除'+(type==='ip'&&String(entry||value).includes('/')?'网段':'拉黑')+'</button>':'<button class="blacklist-button" type="button" data-blacklist-action="add" data-blacklist-type="'+type+'" data-blacklist-value="'+escapeHtml(value)+'">拉黑'+(type==='ip'?' IP':'邮箱')+'</button>');
const tabInfo={
    ip_details:{label:'IP 明细',summary:'distinct_ips'},
    shared_ips:{label:'共享 IP',summary:'shared_ip_count'},
    short_term_spread:{label:'短时扩散',summary:'short_term_spread_count'},
    multi_ip_users:{label:'多 IP 访问',summary:'multi_ip_user_count'},
    cross_region_users:{label:'跨地区切换',summary:'cross_region_user_count'},
    proxy_users:{label:'代理 / 机房来源',summary:'proxy_user_count'},
    shared_user_agents:{label:'共享 User-Agent',summary:'shared_user_agent_count'},
    high_risk_ips:{label:'高风险 IP',summary:'high_risk_ip_count'},
    timeline:{label:'访问时间线',summary:'total_requests'}
};

function rowsForTab(){
    if(activeRank==='timeline')return payload?.logs?.data||[];
    return payload?.rankings?.[activeRank]||[];
}

function countForTab(){
    if(activeRank==='timeline')return payload?.logs?.total||0;
    return payload?.summary?.[tabInfo[activeRank].summary]??rowsForTab().length;
}

function renderTabs(){
    const summary=payload?.summary||{};
    document.querySelector('#rank-tabs').innerHTML=Object.entries(tabInfo).map(([key,info])=>'<button type="button" class="rank-tab '+(key===activeRank?'active':'')+'" data-rank-tab="'+key+'">'+info.label+' <b>· '+escapeHtml(summary[info.summary]??payload?.logs?.total??0)+'</b></button>').join('');
}

function renderMetrics(){
    const summary=payload?.summary||{};
    const info=tabInfo[activeRank];
    document.querySelector('#rank-count').textContent=countForTab()+' 条';
    const rows=rowsForTab();
    const pageTotal=activeRank==='timeline'?countForTab():rows.length;
    const pageCount=Math.max(1,Math.ceil(pageTotal/pageSize));
    page=Math.min(page,pageCount);
    const shown=activeRank==='timeline'?rows:rows.slice((page-1)*pageSize,page*pageSize);
    document.querySelector('#rank-empty').hidden=shown.length>0;
    document.querySelector('#previous').disabled=page<=1;
    document.querySelector('#next').disabled=page>=pageCount;
    document.querySelector('#page').textContent='第 '+page+' / '+pageCount+' 页';

    let headers=[],body='';
    if(['ip_details','shared_ips','high_risk_ips'].includes(activeRank)){
        headers=['IP','风险标签','访问概况','地区 / 运营商','详情'];
        body=shown.map((row,index)=>'<tr class="'+(selectedIndex===(page-1)*pageSize+index?'selected':'')+'" data-row-index="'+((page-1)*pageSize+index)+'"><td><span class="ip">'+escapeHtml(row.ip)+'</span>'+(row.is_blacklisted?'<small>'+pill('🔒 IP 已拉黑','blacklisted')+'</small>':'')+'</td><td>'+(row.is_proxy?pill('代理','red'):'')+(row.risk_flags||[]).map(flag=>pill(flag,'orange')).join('')+(Number(row.max_fraud_score)>=70?pill('高风险 '+row.max_fraud_score,'red'):'')+'</td><td>'+escapeHtml(row.request_count)+' 次 · '+escapeHtml(row.distinct_users)+' 个账号<small class="subtle">最近 '+formatDate(row.latest_seen_at)+'</small></td><td>'+escapeHtml([row.country_code,row.country,row.city].filter(Boolean).join(' · ')||'未知')+'<small class="subtle">'+escapeHtml([row.as_name,row.isp].filter(Boolean).join(' · ')||'未知运营商')+'</small></td><td><button class="row-button" type="button" data-select-index="'+((page-1)*pageSize+index)+'">查看详情</button></td></tr>').join('');
    }else if(activeRank==='short_term_spread'){
        headers=['账号','1 小时内 IP','窗口请求','时间范围','详情'];
        body=shown.map((row,index)=>'<tr class="'+(selectedIndex===(page-1)*pageSize+index?'selected':'')+'" data-row-index="'+((page-1)*pageSize+index)+'"><td><span class="account">'+escapeHtml(row.email)+'</span>'+(row.email_blacklisted?'<small>'+pill('🔒 邮箱已拉黑','blacklisted')+'</small>':'')+'<small class="subtle">用户 #'+escapeHtml(row.user_id)+'</small></td><td>'+pill(row.spread_ip_count+' 个 IP','red')+'</td><td>'+escapeHtml(row.request_count)+' 次</td><td>'+formatDate(row.window_start)+'<small class="subtle">至 '+formatDate(row.window_end)+'</small></td><td><button class="row-button" type="button" data-select-index="'+((page-1)*pageSize+index)+'">查看详情</button></td></tr>').join('');
    }else if(['multi_ip_users','cross_region_users','proxy_users'].includes(activeRank)){
        headers=['账号','线索数量','请求概况','最近访问','详情'];
        body=shown.map((row,index)=>{
            const count=activeRank==='multi_ip_users'?row.distinct_ips:(activeRank==='cross_region_users'?row.distinct_countries:row.proxy_ips);
            const label=activeRank==='multi_ip_users'?'个 IP':(activeRank==='cross_region_users'?'个地区':'个代理 IP');
            const requests=row.request_count??row.proxy_requests??0;
            return '<tr class="'+(selectedIndex===(page-1)*pageSize+index?'selected':'')+'" data-row-index="'+((page-1)*pageSize+index)+'"><td><span class="account">'+escapeHtml(row.email)+'</span>'+(row.email_blacklisted?'<small>'+pill('🔒 邮箱已拉黑','blacklisted')+'</small>':'')+'<small class="subtle">用户 #'+escapeHtml(row.user_id)+'</small></td><td>'+pill(count+' '+label,activeRank==='proxy_users'?'red':'')+'</td><td>'+escapeHtml(requests)+' 次<small class="subtle">'+escapeHtml(row.distinct_ips??0)+' 个 IP</small></td><td>'+formatDate(row.last_seen_at)+'</td><td><button class="row-button" type="button" data-select-index="'+((page-1)*pageSize+index)+'">查看详情</button></td></tr>';
        }).join('');
    }else if(activeRank==='shared_user_agents'){
        headers=['User-Agent','关联账号','访问 IP','请求数','详情'];
        body=shown.map((row,index)=>'<tr class="'+(selectedIndex===(page-1)*pageSize+index?'selected':'')+'" data-row-index="'+((page-1)*pageSize+index)+'"><td style="max-width:360px;overflow-wrap:anywhere">'+escapeHtml(row.user_agent)+'</td><td>'+escapeHtml(row.distinct_users)+' 个'+((row.accounts||[]).some(account=>account.email_blacklisted)?'<small>'+pill('🔒 含已拉黑邮箱','blacklisted')+'</small>':'')+'</td><td>'+escapeHtml(row.distinct_ips)+' 个</td><td>'+escapeHtml(row.request_count)+' 次</td><td><button class="row-button" type="button" data-select-index="'+((page-1)*pageSize+index)+'">查看详情</button></td></tr>').join('');
    }else{
        headers=['时间','账号','访问 IP','地区','风险 / 结果','详情'];
        body=shown.map((row,index)=>'<tr class="'+(selectedIndex===index?'selected':'')+'"><td>'+formatDate(row.created_at)+'</td><td><span class="account">'+escapeHtml(row.email)+'</span>'+(row.email_blacklisted?'<small>'+pill('🔒 邮箱已拉黑','blacklisted')+'</small>':'')+'<small class="subtle">用户 #'+escapeHtml(row.user_id)+'</small></td><td><span class="ip">'+escapeHtml(row.ip||'—')+'</span>'+(row.ip_blacklisted?'<small>'+pill('🔒 IP 已拉黑','blacklisted')+'</small>':'')+'</td><td>'+escapeHtml([row.country_code,row.country,row.city].filter(Boolean).join(' · ')||'—')+'</td><td>'+(row.is_proxy?pill('代理','red'):'')+(row.masked?pill('已伪装'):(row.reason?pill(row.reason,'orange'):'原始域名'))+'<small class="subtle">风险分 '+escapeHtml(row.fraud_score??'—')+'</small></td><td><button class="row-button" type="button" data-select-index="'+index+'">查看详情</button></td></tr>').join('');
    }
    document.querySelector('#rank-head').innerHTML='<tr>'+headers.map(header=>'<th>'+header+'</th>').join('')+'</tr>';
    document.querySelector('#rank-body').innerHTML=body;
}

function accountEvidence(userId){return (payload?.account_evidence||[]).find(row=>Number(row.user_id)===Number(userId))}

function accountCards(accounts){
    if(!accounts?.length)return '<div class="empty">没有关联账号。</div>';
    return '<div class="account-list">'+accounts.map(account=>'<article class="account-card"><strong>'+escapeHtml(account.email)+'</strong>'+(account.email_blacklisted?pill('🔒 已拉黑','blacklisted'):'')+'<small>用户 #'+escapeHtml(account.user_id)+' · '+escapeHtml(account.request_count)+' 次请求</small><small>'+escapeHtml(account.distinct_ips)+' 个 IP · '+escapeHtml(account.distinct_countries)+' 个地区 · '+escapeHtml(account.proxy_requests)+' 次代理访问</small><small>最近 '+formatDate(account.last_seen_at)+'</small>'+blacklistButton('email',account.email,account.email_blacklisted,account.email_blacklist_entry||account.email)+'</article>').join('')+'</div>';
}

function ipCards(ips){
    if(!ips?.length)return '<div class="empty">没有 IP 明细。</div>';
    return '<div class="ip-list">'+ips.map(ip=>'<article class="ip-card"><strong class="ip">'+escapeHtml(ip.ip)+'</strong>'+(ip.is_blacklisted?pill('🔒 已拉黑','blacklisted'):'')+(ip.is_proxy?pill('代理','red'):'')+(ip.related_users>1?pill('共享 '+ip.related_users+' 个账号','orange'):'')+'<small>'+escapeHtml([ip.country_code,ip.country,ip.region,ip.city].filter(Boolean).join(' · ')||'地区未知')+'</small><small>'+escapeHtml([ip.as_name,ip.isp].filter(Boolean).join(' · ')||'运营商未知')+'</small><small>'+escapeHtml(ip.request_count)+' 次访问 · 风险分 '+escapeHtml(ip.max_fraud_score??'—')+' · 最近 '+formatDate(ip.last_seen_at)+'</small><a class="row-button" href="https://iplark.com/'+encodeURIComponent(ip.ip)+'" target="_blank" rel="noopener noreferrer">查看 IPLark ↗</a>'+blacklistButton('ip',ip.ip,ip.is_blacklisted,ip.blacklist_entry||ip.ip)+'</article>').join('')+'</div>';
}

function renderSelectedDetail(){
    const rows=rowsForTab();
    const row=selectedIndex===null?null:rows[selectedIndex];
    const target=document.querySelector('#details');
    if(!row){document.querySelector('#detail-heading').textContent='排行详情';target.innerHTML='<div class="empty">点击排行中的一条记录查看详情。</div>';return}

    let title='',summary='',html='';
    if(['ip_details','shared_ips','high_risk_ips'].includes(activeRank)){
        title=row.ip;
        summary=[row.country_code,row.country,row.region,row.city,row.as_name].filter(Boolean).join(' · ');
        html='<h3>风险标签与访问统计</h3><p>'+((row.risk_flags||[]).map(flag=>pill(flag,'orange')).join('')||(row.is_proxy?pill('代理','red'):'无明显风险标签'))+'</p><p>'+escapeHtml(row.request_count)+' 次访问 · '+escapeHtml(row.distinct_users)+' 个账号 · 最近 '+formatDate(row.latest_seen_at)+'</p>'+blacklistButton('ip',row.ip,row.is_blacklisted,row.blacklist_entry||row.ip)+'<h3 style="margin-top:15px">关联账号</h3>'+accountCards(row.accounts);
    }else if(activeRank==='short_term_spread'){
        title=row.email;summary='用户 #'+row.user_id+' · 1 小时内 '+row.spread_ip_count+' 个 IP · '+row.request_count+' 次请求';
        const evidence=accountEvidence(row.user_id);
        html='<h3>扩散时间窗</h3><p>'+formatDate(row.window_start)+' 至 '+formatDate(row.window_end)+'</p>'+blacklistButton('email',row.email,row.email_blacklisted,row.email_blacklist_entry||row.email)+'<h3 style="margin-top:15px">该账号 IP</h3>'+ipCards(evidence?.ips||[]);
    }else if(['multi_ip_users','cross_region_users','proxy_users'].includes(activeRank)){
        title=row.email;summary='用户 #'+row.user_id+' · '+row.request_count+' 次请求 · 最近 '+formatDate(row.last_seen_at);
        const evidence=accountEvidence(row.user_id);
        let ips=evidence?.ips||[];
        if(activeRank==='proxy_users')ips=ips.filter(ip=>ip.is_proxy);
        html='<h3>关联账号</h3>'+accountCards(row.accounts||[row])+'<h3 style="margin-top:15px">相关 IP</h3>'+ipCards(ips);
    }else if(activeRank==='shared_user_agents'){
        title='共享 User-Agent';summary=row.user_agent;
        html='<h3>关联账号</h3>'+accountCards(row.accounts);
    }else{
        title=row.email;summary=formatDate(row.created_at)+' · 用户 #'+row.user_id;
        html='<h3>本次访问</h3><p>IP：'+escapeHtml(row.ip||'—')+' '+(row.ip_blacklisted?pill('🔒 已拉黑','blacklisted'):'')+' · '+escapeHtml([row.country_code,row.country,row.city].filter(Boolean).join(' · ')||'地区未知')+'</p>'+blacklistButton('ip',row.ip||'',row.ip_blacklisted,row.ip_blacklist_entry||row.ip)+blacklistButton('email',row.email,row.email_blacklisted,row.email_blacklist_entry||row.email)+'<p>UA：'+escapeHtml(row.user_agent||'—')+'</p><p>规则结果：'+escapeHtml(row.masked?'已伪装':(row.reason||'原始域名'))+' · 风险分 '+escapeHtml(row.fraud_score??'—')+'</p>';
    }
    document.querySelector('#detail-heading').textContent=title+' · '+tabInfo[activeRank].label;
    target.innerHTML='<div class="detail-grid"><aside class="detail-summary"><h3>'+escapeHtml(title)+'</h3><p>'+escapeHtml(summary||'—')+'</p></aside><div class="detail-content">'+html+'</div></div>';
}

function clearSelectedDetail(){
    selectedIndex=null;
    document.querySelector('#detail-heading').textContent='排行详情';
    document.querySelector('#details').innerHTML='<div class="empty">点击排行中的一条记录查看详情。</div>';
}

function renderLogs(data){
    const rows=data.data||[];
    document.querySelector('#logs-empty').hidden=rows.length>0;
    document.querySelector('#logs').innerHTML=rows.map(row=>'<tr><td>'+formatDate(row.created_at)+'</td><td>'+escapeHtml(row.email)+'<small class="subtle">用户 #'+escapeHtml(row.user_id)+'</small></td><td><span class="ip">'+escapeHtml(row.ip||'—')+'</span></td><td>'+escapeHtml([row.country_code,row.country,row.city].filter(Boolean).join(' · ')||'—')+'</td><td>'+(row.is_proxy?pill('代理','red'):'—')+'<small class="subtle">风险分 '+escapeHtml(row.fraud_score??'—')+'</small></td><td>'+(row.masked?pill('已伪装'):(row.reason?pill(row.reason,'orange'):'原始域名'))+'</td></tr>').join('');
}

async function load(){
    const query=new URLSearchParams(new FormData(form));query.set('page',page);query.set('page_size',pageSize);
    document.querySelector('#rank-body').innerHTML='<tr><td colspan="6" class="empty">正在加载排行…</td></tr>';
    try{
        const response=await fetch(dataUrl+'?'+query,{credentials:'include'});
        if(response.status===401){location.href=baseUrl;return}
        const result=await response.json();if(!response.ok)throw new Error(result.message||'读取失败');
        payload=result;renderTabs();renderMetrics();renderLogs(result.logs||{data:[]});renderSelectedDetail();
    }catch(error){document.querySelector('#rank-body').innerHTML='<tr><td colspan="6" class="empty">'+escapeHtml(error.message||'排行加载失败')+'</td></tr>';document.querySelector('#details').innerHTML=''}
}

document.querySelector('#rank-tabs').addEventListener('click',event=>{
    const button=event.target.closest('[data-rank-tab]');if(!button)return;
    activeRank=button.dataset.rankTab;page=1;clearSelectedDetail();renderTabs();load();
});
document.querySelector('#rank-body').addEventListener('click',event=>{
    const button=event.target.closest('[data-select-index]');if(!button)return;
    selectedIndex=Number(button.dataset.selectIndex);renderMetrics();renderSelectedDetail();
});
document.querySelector('#details').addEventListener('click',async event=>{
    const button=event.target.closest('[data-blacklist-type]');if(!button||button.disabled)return;
    const type=button.dataset.blacklistType,value=button.dataset.blacklistValue,action=button.dataset.blacklistAction||'add';
    const label=type==='ip'?'IP':'邮箱';
    if(!value){alert('没有可拉黑的'+label);return}
    const confirmation=action==='remove'
        ? (type==='ip'&&value.includes('/')?'确认删除网段 '+value+'？该网段内所有 IP 都会解除拉黑。':'确认将 '+value+' 从'+label+'黑名单移除？')
        : '确认将 '+value+' 加入 '+label+' 黑名单？后续匹配到该项时会返回伪装订阅。';
    if(!confirm(confirmation))return;
    button.disabled=true;button.textContent='正在写入…';
    try{
        const response=await fetch(blacklistUrl,{method:'POST',credentials:'include',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({type,action,value})});
        const result=await response.json();
        if(response.status===401){location.href=baseUrl;return}
        if(!response.ok)throw new Error(result.message||'黑名单写入失败');
        button.textContent=action==='remove'?(result.data?.removed?'已解除':'规则已不存在'):(result.data?.already_blacklisted?'已在黑名单':'已加入黑名单');
        await load();
    }catch(error){button.disabled=false;button.textContent=action==='remove'?'解除拉黑':'拉黑'+label;alert(error.message||'黑名单更新失败')}
});
form.addEventListener('submit',event=>{event.preventDefault();page=1;clearSelectedDetail();load()});
document.querySelector('#reset').addEventListener('click',()=>{form.reset();page=1;clearSelectedDetail();load()});
document.querySelector('#refresh').addEventListener('click',load);
document.querySelector('#previous').addEventListener('click',()=>{if(page>1){page--;clearSelectedDetail();load()}});
document.querySelector('#next').addEventListener('click',()=>{if(page*pageSize<countForTab()){page++;clearSelectedDetail();load()}});
document.querySelector('#page-size').addEventListener('change',event=>{pageSize=Number(event.target.value);page=1;clearSelectedDetail();load()});
document.querySelector('#logout').addEventListener('click',async()=>{await fetch(baseUrl+'/logout',{method:'POST',credentials:'include'});location.reload()});
load();
</script>
</body>
</html>
