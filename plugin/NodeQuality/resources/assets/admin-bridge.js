/* SPDX-License-Identifier: AGPL-3.0-only */
import {mountAdmin} from './admin.js?v=0.4.0';
import {credentials} from './ui.js?v=0.4.0';
export async function startAdminBridge(win) {
 if(win.__nodeQualityAdminBridge)return win.__nodeQualityAdminBridge;
 const doc=win.document;let host,area,app,oldDisplay='',oldPriority='',queued=false,stopped=false,css='',lastHash='';
 const active=()=>/^#\/plugins\/node_quality(?:\/(overview|machines|bindings|targets|settings))?\/?(?:\?.*)?$/.test(win.location.hash);
 function restore(){app?.destroy();app=null;host?.remove();host=null;if(area?.isConnected){if(oldDisplay)area.style.setProperty('display',oldDisplay,oldPriority);else area.style.removeProperty('display')}area=null;}
 function theme(){if(host)host.dataset.theme=doc.documentElement.classList.contains('dark')?'dark':'light'}
 function sync(){queued=false;if(stopped)return;if(!active()){restore();return}if(host&&(!host.isConnected||!area?.isConnected))restore();if(host){theme();if(lastHash!==win.location.hash){lastHash=win.location.hash;app?.routeChanged?.()}return}
  const wrapper=doc.querySelector('main > div');const target=wrapper&&[...wrapper.children].find(e=>e.querySelector('h2')&&e.classList.contains('flex-1'));if(!target||!css)return;
  area=target;oldDisplay=target.style.getPropertyValue('display');oldPriority=target.style.getPropertyPriority('display');
  host=doc.createElement('section');host.dataset.nqAdmin='';host.style.cssText='display:block;flex:1;min-height:0;overflow:auto;width:100%';target.before(host);theme();
  lastHash=win.location.hash;const shadow=host.attachShadow({mode:'open'});
  try{app=mountAdmin(win,{embeddedRoot:shadow,css,routePrefix:'/plugins/node_quality/',authProvider:()=>credentials(win,'XBOARD_ACCESS_TOKEN')});target.style.setProperty('display','none','important');}
  catch{restore();}
 }
 function queue(){if(!queued&&!stopped){queued=true;win.queueMicrotask(sync)}}
 const observer=new win.MutationObserver(queue);observer.observe(doc.getElementById('root')||doc.body,{subtree:true,childList:true,characterData:true});
 const color=new win.MutationObserver(theme);color.observe(doc.documentElement,{attributes:true,attributeFilter:['class']});win.addEventListener('hashchange',queue);win.addEventListener('popstate',queue);
 const bridge={stop(){stopped=true;observer.disconnect();color.disconnect();win.removeEventListener('hashchange',queue);win.removeEventListener('popstate',queue);restore();delete win.__nodeQualityAdminBridge;}};win.__nodeQualityAdminBridge=bridge;
 try{const r=await win.fetch('/plugins/node_quality/ui.css?v=0.4.0');if(!r.ok)throw Error();css=await r.text();queue()}catch{bridge.stop()}
 return bridge;
}
if(typeof window!=='undefined')startAdminBridge(window);
