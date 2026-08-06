<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>订阅访问分析</title>
    <style>body{margin:0;background:#f4f6f8;color:#15202b;font:15px Arial,sans-serif;display:grid;min-height:100vh;place-items:center}.panel{background:#fff;border:1px solid #d9e0e6;padding:32px;width:min(360px,calc(100vw - 48px));box-shadow:0 12px 32px #15202b12}h1{margin:0 0 8px;font-size:24px}p{color:#65727e;line-height:1.5}label,input,button{display:block;width:100%;box-sizing:border-box}label{margin:20px 0 7px;font-weight:600}input{border:1px solid #b9c5cf;padding:11px;border-radius:4px;font:inherit}button{margin-top:16px;border:0;border-radius:4px;background:#116466;color:#fff;padding:11px;font:inherit;font-weight:600;cursor:pointer}.error{min-height:20px;color:#b42318;margin-top:12px}</style>
</head>
<body>
    <main class="panel">
        <h1>订阅访问分析</h1>
        <p>用于排查共享订阅、代理访问与异常请求模式。</p>
        <form method="post" action="{{ $analysisBaseUrl }}/login">
            <label for="password">访问密码</label>
            <input id="password" name="password" type="password" autocomplete="current-password" required>
            <button type="submit">进入分析页</button><div class="error" role="alert"></div>
        </form>
    </main>
    <script>document.querySelector('form').addEventListener('submit',async e=>{e.preventDefault();const f=e.currentTarget,b=f.querySelector('button'),m=f.querySelector('.error');b.disabled=true;m.textContent='';const r=await fetch(f.action,{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},credentials:'same-origin',body:JSON.stringify({password:f.password.value})});if(r.ok){location.href='{{ $analysisBaseUrl }}';return}m.textContent=(await r.json()).message||'登录失败';b.disabled=false})</script>
</body>
</html>
