// Backward-compatible bookmarks enter the existing XBoard app, never a second app shell.
const [path,query='']=location.hash.slice(1).split('?'),match=/^\/nodes\/(\d+)$/.exec(path),q=new URLSearchParams(query),next=new URLSearchParams({plus:'1'});
if(match){next.set('node',match[1]);next.set('range',q.get('range')||'1');next.set('tab',q.get('tab')||'network');}
location.replace('/#/node?'+next.toString());
