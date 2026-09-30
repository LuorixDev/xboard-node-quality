/* SPDX-License-Identifier: AGPL-3.0-only */
export const VERSION = '0.4.0';
export const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const paths = {
 pulse:'M3 12h4l3-7 4 14 3-7h4', grid:'M3 3h7v7H3z M14 3h7v7h-7z M3 14h7v7H3z M14 14h7v7h-7z',
 server:'M4 3h16v7H4z M4 14h16v7H4z M7 6h1 M7 17h1 M12 6h5 M12 17h5', globe:'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18 M3 12h18 M12 3c5 5 5 13 0 18-5-5-5-13 0-18',
 link:'M10 13a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-2 2 M14 11a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l2-2',
 settings:'M4 7h16 M4 17h16 M8 4v6 M16 14v6', arrow:'M5 12h14 M14 7l5 5-5 5', back:'M19 12H5 M10 7l-5 5 5 5',
 refresh:'M20 7v5h-5 M4 17v-5h5 M6 7a7 7 0 0 1 12-1l2 6 M4 12l2 6a7 7 0 0 0 12-1',
 plus:'M12 5v14 M5 12h14', search:'M10 3a7 7 0 1 0 0 14 7 7 0 0 0 0-14 M15 15l6 6', close:'M6 6l12 12 M18 6 6 18',
 clock:'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18 M12 7v5l3 2', check:'M5 12l4 4L19 6', shield:'M12 3l8 3v6c0 5-8 9-8 9S4 17 4 12V6z M8 12l3 3 5-6',
 chart:'M4 3v17h17 M7 15l4-5 4 3 6-8', copy:'M8 8h12v13H8z M16 8V3H3v13h5', menu:'M4 6h16 M4 12h16 M4 18h16', sun:'M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8 M12 2v3 M12 19v3 M2 12h3 M19 12h3 M5 5l2 2 M17 17l2 2 M5 19l2-2 M17 7l2-2', info:'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18 M12 11v6 M12 7v1', trash:'M4 6h16 M9 6V3h6v3 M6 6l1 15h10l1-15 M10 10v7 M14 10v7'
};
export const icon = (name, size=18) => `<svg width="${size}" height="${size}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${(paths[name]||paths.pulse).split(' M').map((p,i)=>`<path d="${i?'M':''}${p}"/>`).join('')}</svg>`;
export const stamp = t => t ? new Date(t*1000).toLocaleString('zh-CN',{month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit'}) : '尚无记录';
export const fullStamp = t => t ? new Date(t*1000).toLocaleString('zh-CN') : '尚无记录';
export function ago(t, now=Date.now()/1000) { if(!t)return '尚未上报';const s=Math.max(0,now-t);return s<60?'刚刚':s<3600?`${Math.floor(s/60)} 分钟前`:s<86400?`${Math.floor(s/3600)} 小时前`:`${Math.floor(s/86400)} 天前`; }
export const bytes = n => !Number.isFinite(Number(n))?'—':Number(n)>=1073741824?`${(n/1073741824).toFixed(1)} GB`:`${(n/1048576).toFixed(0)} MB`;
export const mean = samples => {const v=(samples||[]).filter(x=>x.status==='ok'&&Number.isFinite(x.ms));return v.length?v.reduce((a,x)=>a+x.ms,0)/v.length:null;};
export const latency = n => n==null?'—':Number(n).toFixed(1);
export const pill = (text,tone='neutral') => `<span class="pill ${tone}"><i></i>${esc(text)}</span>`;
export function state(node) {const m=node.monitor;if(!m)return {key:'unconfigured',label:'待配置',tone:'neutral'};if(!m.agent_online)return {key:'offline',label:'探针离线',tone:'warn'};if(!m.latest?.scheduled)return {key:'pending',label:'等待采集',tone:'neutral'};if(Date.now()/1000-m.latest.scheduled.observed_at>900)return {key:'stale',label:'数据过期',tone:'warn'};return {key:'online',label:'监测中',tone:'good'};}
export function credentials(win,key='VUE_NAIVE_ACCESS_TOKEN') {try{const c=JSON.parse(win.localStorage.getItem(key)||'null');return c?.value&&(!c.expire||c.expire>Date.now())?c.value:null;}catch{return null;}}
export function createApi(win,authProvider) {
 return async (path,body,signal) => {
  const token=authProvider?authProvider():credentials(win);if(!token)throw Object.assign(Error('登录已过期，请重新登录。'),{status:401});
  const own=signal?null:new win.AbortController(),timer=own?win.setTimeout(()=>own.abort(),15000):null;
  try {
   const r=await win.fetch('/api/v1/node-quality'+path,{method:body===undefined?'GET':'POST',headers:{Authorization:token,'Content-Type':'application/json'},body:body===undefined?undefined:JSON.stringify(body),signal:signal||own.signal,cache:'no-store'});
   let j;try{j=await r.json();}catch{throw Object.assign(Error('服务暂时无法响应，请稍后重试。'),{status:r.status});}
   if(!r.ok)throw Object.assign(Error(j.message||'请求失败，请重试。'),{status:r.status});return j.data;
  } catch(e) {if(e.name==='AbortError'){if(own)throw Error('请求超时，请稍后重试。');throw e;}if(e.status!==undefined)throw e;throw Error('网络连接失败，请稍后重试。');}
  finally {if(timer)win.clearTimeout(timer);}
 };
}
export function empty(title, description, action='') {return `<div class="empty"><div class="empty-icon">${icon('pulse',30)}</div><h3>${esc(title)}</h3><p>${esc(description)}</p>${action}</div>`;}
export function metrics(items) {return `<div class="summary-grid">${items.map(([label,value,hint,name])=>`<div class="summary"><div class="summary-label">${esc(label)}${icon(name||'pulse')}</div><strong>${esc(value)}</strong><small>${esc(hint||'')}</small></div>`).join('')}</div>`;}
export function initializeShell(win,kind,options={}) {
 if(options.embeddedRoot)return initializeEmbeddedShell(win,options);
 const doc=win.document, root=doc.getElementById('app');let previousFocus;
 const nav=kind==='admin'?[['overview','grid','运行概览'],['machines','server','探针与出口'],['bindings','link','节点绑定'],['targets','globe','检测目标'],['settings','settings','数据与设置']]:[['/#/dashboard','grid','仪表盘'],['/#/node','server','节点状态'],['#/', 'pulse','节点状态 Plus 监测']];
 root.innerHTML=`<button class="scrim" aria-label="关闭导航"></button><aside class="sidebar" id="plus-navigation"><a class="brand" href="${kind==='admin'?'#/overview':'#/'}"><span class="brand-mark">${icon('pulse',23)}</span><span>节点状态 <b>Plus</b><small>${kind==='admin'?'监测管理控制台':'网络质量监测'}</small></span></a><div class="nav-caption">${kind==='admin'?'工作空间':'我的网络'}</div><nav>${nav.map(([key,ic,label])=>`<a href="${kind==='admin'?'#/'+key:key}" data-nav="${esc(key)}">${icon(ic)}<span>${esc(label)}</span>${label.includes('Plus')?'<em>PLUS</em>':''}</a>`).join('')}</nav><div class="sidebar-bottom"><a href="${kind==='admin'?'plus.html':'/#/dashboard'}">${icon('back')} ${kind==='admin'?'查看用户监测页':'返回用户中心'}</a><div class="sidebar-note">${icon('shield')} 数据来自节点出口探针</div></div></aside><div class="workspace"><header class="topbar"><div class="row"><button class="icon-button mobile-menu" aria-controls="plus-navigation" aria-expanded="false" aria-label="打开导航">${icon('menu')}</button><span>${kind==='admin'?'监测管理':'节点状态 Plus 监测'}</span><span class="top-label">${kind==='admin'?'ADMIN':'NETWORK'}</span></div><div class="row"><span class="update-label" id="updated">等待同步</span><button class="icon-button" id="theme-toggle" aria-label="切换明暗主题">${icon('sun')}</button></div></header><main id="content" tabindex="-1"></main><footer>节点状态 Plus <span>v${VERSION}</span><a href="source.zip">开源代码 · AGPL</a></footer></div><div id="toast" class="toast" role="status" hidden></div><dialog id="modal" aria-labelledby="modal-title"></dialog>`;
 let color;try{color=new URL(win.location.href).searchParams.get('theme')||win.localStorage.getItem('nq-color');}catch{}if(!['dark','light'].includes(color))color=win.matchMedia?.('(prefers-color-scheme: dark)').matches?'dark':'light';doc.documentElement.dataset.theme=color;
 const theme=()=>{const next=doc.documentElement.dataset.theme==='dark'?'light':'dark';doc.documentElement.dataset.theme=next;try{win.localStorage.setItem('nq-color',next)}catch{}};
 doc.getElementById('theme-toggle').addEventListener('click',theme);
 const media=win.matchMedia?.('(max-width: 800px)'),sidebar=root.querySelector('.sidebar'),toggle=root.querySelector('.mobile-menu');
 const syncNav=()=>{const open=doc.body.classList.contains('nav-open');sidebar.inert=!!media?.matches&&!open;toggle.setAttribute('aria-expanded',String(open));if(media?.matches&&!open)sidebar.setAttribute('aria-hidden','true');else sidebar.removeAttribute('aria-hidden');};
 const closeNav=()=>{doc.body.classList.remove('nav-open');syncNav()};toggle.onclick=()=>{doc.body.classList.add('nav-open');syncNav();sidebar.querySelector('a')?.focus()};root.querySelector('.scrim').onclick=closeNav;root.querySelector('nav').addEventListener('click',closeNav);media?.addEventListener?.('change',syncNav);syncNav();
 const escapeNav=e=>{if(e.key==='Escape'&&doc.body.classList.contains('nav-open')){closeNav();toggle.focus()}};doc.addEventListener('keydown',escapeNav);
 const modal=doc.getElementById('modal');modal.addEventListener('click',e=>{if(e.target.closest('[data-close-modal]'))modal.close()});modal.addEventListener('close',()=>{if(!modal.open){modal.innerHTML='';previousFocus?.focus?.()}});
 let toastTimer;
 return {content:doc.getElementById('content'),modal,
  select(key){root.querySelectorAll('[data-nav]').forEach(n=>{n.classList.toggle('active',n.dataset.nav===key);if(n.dataset.nav===key)n.setAttribute('aria-current','page');else n.removeAttribute('aria-current')})},
  updated(){doc.getElementById('updated').textContent='更新于 '+new Date().toLocaleTimeString('zh-CN',{hour:'2-digit',minute:'2-digit'})},
  toast(text,error=false){const t=doc.getElementById('toast');t.textContent=text;t.classList.toggle('error',error);t.hidden=false;win.clearTimeout(toastTimer);toastTimer=win.setTimeout(()=>t.hidden=true,5000)},
  open(title,html){previousFocus=doc.activeElement;modal.innerHTML=`<div class="dialog-head"><h2 id="modal-title">${esc(title)}</h2><button class="icon-button" data-close-modal aria-label="关闭">${icon('close')}</button></div>${html}`;modal.showModal();},
  error(e,retry='reload'){this.content.innerHTML=empty(e.status===401?'需要登录':e.status===403?'没有管理权限':'暂时无法加载',e.message,e.status===401?'<a class="button primary" href="/#/login">前往登录</a>':`<button class="button primary" data-action="${retry}">重新加载</button>`);},
  destroy(){doc.removeEventListener('keydown',escapeNav);media?.removeEventListener?.('change',syncNav);win.clearTimeout(toastTimer);if(modal.open)modal.close();closeNav();}
 };
}

function initializeEmbeddedShell(win,options) {
 const root=options.embeddedRoot,css=options.css.replaceAll(':root[data-theme=dark]',':host([data-theme="dark"])').replaceAll(':root',':host');
 root.innerHTML=`<style>${css}:host{display:block;background:var(--canvas);height:100%;overflow:auto}.workspace{margin:0;min-height:0}main{padding:24px;min-height:0;max-width:none}footer{padding:12px 24px 20px}dialog{position:fixed}@media(max-width:600px){main{padding:16px}}</style><div id="app" class="workspace"><main id="content"></main><footer>节点状态 Plus <span>v${VERSION}</span><a href="/plugins/node_quality/source.zip">开源代码 · AGPL</a></footer><div id="toast" class="toast" role="status" hidden></div><dialog id="modal" aria-labelledby="modal-title"></dialog></div>`;
 const content=root.getElementById('content'),modal=root.getElementById('modal');let timer,focus;
 modal.addEventListener('click',e=>{if(e.target.closest('[data-close-modal]'))modal.close()});modal.addEventListener('close',()=>{if(!modal.open){modal.innerHTML='';focus?.focus?.()}});
 const links=new win.MutationObserver(()=>{content.querySelectorAll('a[href^="#/"]').forEach(a=>{const v=a.getAttribute('href');if(options.mapHref){const next=options.mapHref(v);if(next!==v)a.setAttribute('href',next);}else if(options.routePrefix&&!v.startsWith('#'+options.routePrefix))a.setAttribute('href','#'+options.routePrefix+v.slice(2));});content.querySelectorAll('a[href="plus.html"]').forEach(a=>a.setAttribute('href','/plugins/node_quality/plus.html'));});links.observe(content,{childList:true,subtree:true});
 return {content,modal,select(){},updated(){},toast(text,error=false){const el=root.getElementById('toast');el.textContent=text;el.classList.toggle('error',error);el.hidden=false;win.clearTimeout(timer);timer=win.setTimeout(()=>el.hidden=true,5000)},open(title,html){focus=root.activeElement;modal.innerHTML=`<div class="dialog-head"><h2 id="modal-title">${esc(title)}</h2><button class="icon-button" data-close-modal aria-label="关闭">${icon('close')}</button></div>${html}`;modal.showModal()},error(e){content.innerHTML=empty(e.status===401?'登录已过期':'暂时无法加载',e.message,e.status===401?`<a class="button primary" href="${esc(options.loginHref||win.location.pathname+'#/sign-in')}">重新登录</a>`:'<button class="button primary" data-action="reload">重新加载</button>')},destroy(){links.disconnect();win.clearTimeout(timer);if(modal.open)modal.close()}};
}
