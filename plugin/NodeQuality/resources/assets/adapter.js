/* SPDX-License-Identifier: AGPL-3.0-only */
(() => {
  if(window.__nodeQualityAdapter?.version==='0.4.0')return;
  window.__nodeQualityAdapter?.stop?.();
  import('/plugins/node_quality/user-bridge.js?v=0.4.0').then(module=>module.startUserBridge(window)).catch(()=>console.warn('Plus monitor could not load; original node page remains available.'));
})();
