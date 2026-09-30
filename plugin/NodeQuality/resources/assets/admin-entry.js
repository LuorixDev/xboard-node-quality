import {createApi,credentials} from './ui.js?v=0.4.0';
const api=createApi(window,()=>credentials(window,'XBOARD_ACCESS_TOKEN')||credentials(window));
api('/admin').then(data=>{if(data.admin_url?.startsWith('/')&&!data.admin_url.startsWith('//'))location.replace(data.admin_url);else throw Error('请从 XBoard 后台的 Plus 菜单进入。')}).catch(e=>{document.getElementById('entry-status').textContent=e.message;});
