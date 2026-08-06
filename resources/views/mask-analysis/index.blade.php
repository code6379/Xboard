<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>订阅访问分析</title>
    <style>
        :root{--ink:#1d2939;--muted:#667085;--line:#dfe5ec;--soft:#f7f9fb;--paper:#fff;--teal:#0f766e;--teal-soft:#e7f5f2;--red:#b42318;--red-soft:#fff1f0}*{box-sizing:border-box}body{margin:0;background:#f4f6f8;color:var(--ink);font:14px Arial,sans-serif}.shell{max-width:1540px;margin:auto;padding:22px}.top{display:flex;justify-content:space-between;align-items:flex-start;gap:18px}.title-block{display:flex;gap:12px;align-items:flex-start}.mark{width:36px;height:36px;display:grid;place-items:center;background:var(--teal);color:#fff;font-weight:700;font-size:17px}.top h1{margin:0;font-size:24px;line-height:1.25}.top p{margin:5px 0 0;color:var(--muted)}button,input,select{font:inherit}.logout,.apply,.pager button{border:1px solid var(--line);background:var(--paper);padding:8px 11px;cursor:pointer}.apply{border-color:var(--teal);background:var(--teal);color:#fff;font-weight:600}.toolbar{margin-top:20px;padding:13px 14px;background:var(--paper);border:1px solid var(--line)}.filters{display:flex;align-items:end;flex-wrap:wrap;gap:10px}.filters label{display:grid;gap:5px;color:var(--muted);font-size:12px}.filters input,.filters select{width:132px;min-height:34px;padding:7px 8px;border:1px solid var(--line);border-radius:3px;color:var(--ink);background:#fff}.filters .wide input{width:190px}.summary{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:1px;margin-top:16px;border:1px solid var(--line);background:var(--line)}.summary-item{padding:12px 14px;background:var(--paper)}.summary-item span{display:block;color:var(--muted);font-size:12px}.summary-item b{display:block;margin-top:5px;font-size:23px;line-height:1}.workspace{display:grid;grid-template-columns:minmax(300px,0.8fr) minmax(0,2fr);gap:16px;margin-top:16px}.panel{background:var(--paper);border:1px solid var(--line)}.panel-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 15px;border-bottom:1px solid var(--line)}.panel-head h2{margin:0;font-size:15px}.panel-head span{color:var(--muted);font-size:12px}.active-filter{min-height:17px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.evidence-group{padding:12px 0;border-bottom:1px solid var(--line)}.evidence-group:last-child{border-bottom:0}.evidence-group h3{margin:0 15px 7px;color:var(--muted);font-size:12px;font-weight:600}.evidence-row{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:10px;width:100%;padding:9px 15px;border:0;border-left:3px solid transparent;background:transparent;color:var(--ink);text-align:left;cursor:pointer}.evidence-row:hover,.evidence-row:focus-visible{background:var(--teal-soft);border-left-color:var(--teal);outline:0}.evidence-value{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-family:ui-monospace,SFMono-Regular,Consolas,monospace;font-size:12px}.evidence-meta{color:var(--muted);font-size:12px;white-space:nowrap}.empty{padding:8px 15px;color:var(--muted);font-size:12px}.records{min-width:0}.table-wrap{overflow:auto}.logs{width:100%;min-width:950px;border-collapse:collapse;font-size:13px}.logs th,.logs td{padding:10px 12px;border-bottom:1px solid #edf0f2;text-align:left;vertical-align:top}.logs th{position:sticky;top:0;background:var(--soft);color:var(--muted);font-size:12px;font-weight:600}.logs tr:hover td{background:#fbfcfd}.user-agent{min-width:330px;max-width:520px;overflow-wrap:anywhere;font-family:ui-monospace,SFMono-Regular,Consolas,monospace;font-size:12px;color:#344054}.badge{display:inline-block;padding:2px 6px;border-radius:10px;background:var(--teal-soft);color:#06685f;font-size:11px}.risk-badge{background:var(--red-soft);color:var(--red)}.state{padding:32px 15px;color:var(--muted)}.pager{display:flex;align-items:center;gap:8px;padding:12px 15px}.pager button:disabled{opacity:.45;cursor:not-allowed}.pager span{color:var(--muted);font-size:12px}@media(max-width:940px){.workspace{grid-template-columns:1fr}.evidence-panel{max-height:none}}@media(max-width:640px){.shell{padding:14px}.top{align-items:center}.top p{display:none}.mark{display:none}.filters label,.filters .wide{width:calc(50% - 5px)}.filters input,.filters select,.filters .wide input{width:100%}.summary{grid-template-columns:1fr}.workspace{gap:12px}.panel-head{align-items:flex-start;flex-direction:column}.active-filter{max-width:100%}}
    </style>
</head>
<body>
<main class="shell">
    <header class="top">
        <div class="title-block"><div class="mark">A</div><div><h1>订阅访问分析</h1><p>从访问证据中定位异常共享与可疑客户端。</p></div></div>
        <button id="logout" class="logout" type="button">退出</button>
    </header>

    <section class="toolbar" aria-label="筛选条件">
        <form id="filters" class="filters">
            <label>开始日期<input name="start" type="date"></label>
            <label>结束日期<input name="end" type="date"></label>
            <label>邮箱<input name="email" placeholder="包含文本"></label>
            <label>IP<input name="ip" placeholder="精确 IP"></label>
            <label>国家<input name="country" maxlength="2" placeholder="US"></label>
            <label class="wide">User-Agent<input name="user_agent" placeholder="客户端关键字"></label>
            <label><span>仅代理</span><select name="proxy_only"><option value="">全部</option><option value="1">是</option></select></label>
            <label><span>仅伪装</span><select name="masked_only"><option value="">全部</option><option value="1">是</option></select></label>
            <button class="apply" type="submit">筛选记录</button>
        </form>
    </section>

    <section id="stats" class="summary" aria-label="当前筛选摘要"></section>

    <section class="workspace">
        <aside class="panel evidence-panel">
            <div class="panel-head"><h2>可疑线索</h2><span>点击直接下钻</span></div>
            <div id="risks"></div>
        </aside>
        <section class="panel records">
            <div class="panel-head"><h2>访问记录</h2><span id="active-filter" class="active-filter">当前显示全部记录</span></div>
            <div id="state" class="state">正在加载记录...</div>
            <div class="table-wrap"><table id="logs" class="logs" hidden><thead><tr><th>时间</th><th>用户</th><th>IP / 国家</th><th>风险</th><th>User-Agent</th><th>结果</th></tr></thead><tbody></tbody></table></div>
            <div class="pager"><button id="prev" type="button">上一页</button><span id="page"></span><button id="next" type="button">下一页</button></div>
        </section>
    </section>
</main>
<script>
const form=document.querySelector('#filters'),stats=document.querySelector('#stats'),risks=document.querySelector('#risks'),table=document.querySelector('#logs'),body=table.querySelector('tbody'),state=document.querySelector('#state'),pageText=document.querySelector('#page'),activeFilter=document.querySelector('#active-filter');let page=1,total=0,size=50;
const escape=value=>String(value??'').replace(/[&<>"']/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
const summaryMetrics={total_requests:'请求数',distinct_users:'独立用户',distinct_ips:'独立 IP'};
const dataUrl='{{ $analysisBaseUrl }}/data',logoutUrl='{{ $analysisBaseUrl }}/logout';
function evidenceGroup(title,rows,key,value,meta,extra){if(!rows.length)return '<section class="evidence-group"><h3>'+title+'</h3><p class="empty">无匹配线索</p></section>';return '<section class="evidence-group"><h3>'+title+'</h3>'+rows.map(row=>'<button class="evidence-row" type="button" data-filter-key="'+key+'" data-filter-value="'+escape(row[value])+'"'+(extra?extra(row):'')+'><span class="evidence-value" title="'+escape(row[value])+'">'+escape(row[value])+'</span><span class="evidence-meta">'+meta(row)+'</span></button>').join('')+'</section>'}
function renderEvidence(suspicion){risks.innerHTML=evidenceGroup('共享 User-Agent',suspicion.shared_user_agents,'user_agent','user_agent',row=>row.distinct_users+' 用户 · '+row.request_count+' 次')+evidenceGroup('共享 IP',suspicion.shared_ips,'ip','ip',row=>row.distinct_users+' 用户 · '+row.request_count+' 次')+evidenceGroup('同用户多 IP',suspicion.multi_ip_users,'email','email',row=>row.distinct_ips+' IP · '+row.request_count+' 次')+evidenceGroup('跨国用户',suspicion.multi_country_users,'email','email',row=>row.distinct_countries+' 国家 · '+row.request_count+' 次')+evidenceGroup('高频用户 / IP',suspicion.high_frequency_pairs,'email','email',row=>row.request_count+' 次',row=>' data-filter-ip="'+escape(row.ip)+'"')}
async function load(){const query=new URLSearchParams(new FormData(form));query.set('page',page);query.set('page_size',size);state.hidden=false;state.textContent='正在加载记录...';table.hidden=true;try{const response=await fetch(dataUrl+'?'+query,{credentials:'same-origin'});if(response.status===401){location.href='{{ $analysisBaseUrl }}';return}const payload=await response.json();if(!response.ok){state.textContent=payload.message||'加载失败';return}stats.innerHTML=Object.entries(summaryMetrics).map(([key,label])=>'<div class="summary-item"><span>'+label+'</span><b>'+escape(payload.summary[key])+'</b></div>').join('');renderEvidence(payload.suspicion);body.innerHTML=payload.logs.data.map(row=>'<tr><td>'+escape(row.created_at)+'</td><td>'+escape(row.email)+'<br><span class="evidence-meta">#'+escape(row.user_id)+'</span></td><td>'+escape(row.ip)+'<br><span class="evidence-meta">'+escape(row.country_code)+'</span></td><td>'+(row.is_proxy?'<span class="badge">代理</span> ':'')+(row.fraud_score>=70?'<span class="badge risk-badge">'+escape(row.fraud_score)+'</span>':escape(row.fraud_score))+'</td><td class="user-agent" title="'+escape(row.user_agent)+'">'+escape(row.user_agent)+'</td><td>'+(row.masked?'<span class="badge">已伪装</span>':'原始域名')+'</td></tr>').join('');total=payload.logs.total;page=payload.logs.page;state.hidden=true;table.hidden=false;pageText.textContent='第 '+page+' 页 / 共 '+Math.max(1,Math.ceil(total/size))+' 页';document.querySelector('#prev').disabled=page===1;document.querySelector('#next').disabled=page*size>=total}catch(error){state.textContent='加载失败，请稍后重试'}}
form.addEventListener('submit',event=>{event.preventDefault();page=1;activeFilter.textContent='按当前筛选条件查看';load()});
risks.addEventListener('click',event=>{const row=event.target.closest('[data-filter-key]');if(!row)return;form.elements[row.dataset.filterKey].value=row.dataset.filterValue;if(row.dataset.filterIp)form.elements.ip.value=row.dataset.filterIp;page=1;activeFilter.textContent='已下钻：'+row.dataset.filterValue;load()});
document.querySelector('#prev').onclick=()=>{if(page>1){page--;load()}};document.querySelector('#next').onclick=()=>{if(page*size<total){page++;load()}};document.querySelector('#logout').onclick=async()=>{await fetch(logoutUrl,{method:'POST',credentials:'same-origin'});location.reload()};load();
</script>
</body>
</html>
