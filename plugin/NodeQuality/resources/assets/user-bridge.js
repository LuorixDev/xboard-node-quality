/* SPDX-License-Identifier: AGPL-3.0-only */
import {mountUser} from './plus.js?v=0.4.0';
import {credentials} from './ui.js?v=0.4.0';
export function inlineHref(href) {
 if(!href.startsWith('#/'))return href;
 const [path,query='']=href.slice(1).split('?'),match=/^\/nodes\/(\d+)$/.exec(path),q=new URLSearchParams(query);
 if(path==='/node'&&q.get('plus')==='1')return href;if(path!=='/'&&!match)return href;
 const result=new URLSearchParams({plus:'1'});if(match){result.set('node',match[1]);result.set('range',q.get('range')||'1');result.set('tab',q.get('tab')||'network');}
 return '#/node?'+result.toString();
}
export async function startUserBridge(win) {
 if(win.__nodeQualityAdapter?.version==='0.4.0')return win.__nodeQualityAdapter;
 win.__nodeQualityAdapter?.stop?.();
 const doc=win.document,links=new Set();let host,original,app,display='',priority='',hadStyle=false,css='',queued=false,stopped=false,lastHash=win.location.hash;
 const active=()=>{const [p,q='']=win.location.hash.slice(1).split('?');return p==='/node'&&new URLSearchParams(q).get('plus')==='1'};
 const style=doc.createElement('style');style.dataset.nqNavigation='';style.textContent=`[data-nq-plus]{display:flex!important;align-items:center;gap:10px;min-height:42px;padding:0 18px;color:inherit!important;text-decoration:none;font-size:14px;border-radius:4px;box-sizing:border-box}[data-nq-plus]:hover{background:var(--n-item-color-hover,rgba(110,130,160,.12))}[data-nq-plus][aria-current=page]{color:var(--n-item-text-color-active,#3875de)!important;background:var(--n-item-color-active,rgba(60,110,220,.12))}body[data-nq-plus-active] [role=menuitem]:has(+[data-nq-plus]) .n-menu-item-content--selected{background:transparent!important;color:var(--n-item-text-color,inherit)!important}body[data-nq-plus-active] [role=menuitem]:has(+[data-nq-plus]) .n-menu-item-content--selected .n-menu-item-content__icon{color:var(--n-item-icon-color,inherit)!important}body[data-nq-plus-active] [role=menuitem]:has(+[data-nq-plus]) .n-menu-item-content--selected::before{background:transparent!important}body[data-nq-plus-active] [role=menuitem]:has(+[data-nq-plus]) .n-menu-item-content--selected::after{opacity:0!important}body[data-nq-plus-active] [role=menuitem]:has(+[data-nq-plus]) .n-menu-item-content-header{color:var(--n-item-text-color,inherit)!important}`;doc.head.append(style);
 function restore(){app?.destroy();app=null;host?.remove();host=null;if(original?.isConnected){if(display)original.style.setProperty('display',display,priority);else original.style.removeProperty('display');if(!hadStyle&&!original.getAttribute('style'))original.removeAttribute('style');}original=null;delete doc.body.dataset.nqPlusActive;}
 function theme(){if(host)host.dataset.theme=doc.documentElement.classList.contains('dark')?'dark':'light';}
 function menu(){doc.querySelectorAll('[role=menu]').forEach(m=>{const native=[...m.querySelectorAll('[role=menuitem]')].find(e=>!e.hasAttribute('data-nq-plus')&&/^(节点状态|節點狀態|Node Status|Nodes)$/i.test(e.textContent.trim()));if(!native)return;let a=m.querySelector('[data-nq-plus]');if(!a){a=doc.createElement('a');a.dataset.nqPlus='';a.setAttribute('role','menuitem');a.className='n-menu-item';a.href='#/node?plus=1';a.innerHTML='<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M3 12h4l3-7 4 14 3-7h4"/></svg><span>节点状态 Plus 监测</span>';native.after(a);links.add(a);}if(active())a.setAttribute('aria-current','page');else a.removeAttribute('aria-current');});for(const a of links)if(!a.isConnected)links.delete(a);}
 function sync(){queued=false;if(stopped)return;menu();if(!active()||!credentials(win)){restore();lastHash=win.location.hash;return}doc.body.dataset.nqPlusActive='';if(host&&(!host.isConnected||!original?.isConnected))restore();if(host){theme();if(lastHash!==win.location.hash){lastHash=win.location.hash;app?.routeChanged?.()}return}if(!css)return;
  const list=doc.querySelector('article section section > ul.n-list');if(!list)return;original=list;hadStyle=list.hasAttribute('style');display=list.style.getPropertyValue('display');priority=list.style.getPropertyPriority('display');host=doc.createElement('section');host.dataset.nqUserView='';host.style.cssText='display:block;width:100%;min-height:100%';list.before(host);theme();lastHash=win.location.hash;
  try{app=mountUser(win,{embeddedRoot:host.attachShadow({mode:'open'}),css,routeMode:'inline',mapHref:inlineHref,loginHref:'/#/login'});list.style.setProperty('display','none','important');doc.body.dataset.nqPlusActive='';}catch{restore();}
 }
 function queue(){if(!queued&&!stopped){queued=true;win.queueMicrotask(sync)}}
 const observer=new win.MutationObserver(queue);observer.observe(doc.getElementById('app')||doc.body,{childList:true,subtree:true,characterData:true});const colors=new win.MutationObserver(theme);colors.observe(doc.documentElement,{attributes:true,attributeFilter:['class']});
 const click=()=>win.setTimeout(queue,0);win.addEventListener('hashchange',queue);win.addEventListener('popstate',queue);win.addEventListener('storage',queue);doc.addEventListener('click',click);
 const watch=win.setInterval(()=>{if(lastHash!==win.location.hash)queue()},1000);
 const bridge={version:'0.4.0',stop(){stopped=true;observer.disconnect();colors.disconnect();win.clearInterval(watch);win.removeEventListener('hashchange',queue);win.removeEventListener('popstate',queue);win.removeEventListener('storage',queue);doc.removeEventListener('click',click);restore();links.forEach(a=>a.remove());links.clear();style.remove();delete win.__nodeQualityAdapter;}};win.__nodeQualityAdapter=bridge;sync();
 try{const response=await win.fetch('/plugins/node_quality/ui.css?v=0.4.0');if(!response.ok)throw Error();css=await response.text();queue();}catch{ /* No content is hidden when the monitor assets cannot load. */ }
 return bridge;
}
