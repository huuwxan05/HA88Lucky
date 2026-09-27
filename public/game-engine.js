(() => {
  const API='/api/index.php'; const listeners=new Map();
  async function request(action,opts={}){const c=new AbortController(),t=setTimeout(()=>c.abort(),8000);try{const r=await fetch(API+'?action='+action,{credentials:'same-origin',signal:c.signal,...opts,headers:{'Content-Type':'application/json',...(opts.headers||{})}});const x=await r.json().catch(()=>({error:'invalid_json'}));if(!r.ok)throw new Error(x.error||('HTTP '+r.status));return x;}finally{clearTimeout(t)}}
  window.HA88GameEngine={
    listGames:()=>request('game_list'),config:g=>request('game_config&game='+encodeURIComponent(g)),startRound:g=>request('game_round&game='+encodeURIComponent(g)),
    placeBet:(data)=>request('place_bet',{method:'POST',body:JSON.stringify(data)}),
    settleRound:(g,r)=>request('settle_round&game='+encodeURIComponent(g)+'&round='+encodeURIComponent(r)),
    session:()=>request('session'),login:data=>request('login',{method:'POST',body:JSON.stringify(data)}),register:data=>request('register',{method:'POST',body:JSON.stringify(data)}),logout:()=>request('logout',{method:'POST'}),wallet:()=>request('wallet'),
    heartbeat:()=>request('health'),
    on(e,fn){if(!listeners.has(e))listeners.set(e,new Set());listeners.get(e).add(fn);return()=>listeners.get(e)?.delete(fn)},
    emit(e,d){(listeners.get(e)||[]).forEach(fn=>{try{fn(d)}catch(_){}})},
    mountRound(game,root){const el=typeof root==='string'?document.querySelector(root):root;if(!el)return;let timer=null;const render=async()=>{try{const x=await this.startRound(game),r=x.round,g=x.game;this.emit('round:start',x);el.innerHTML='<div class="ha88-round-card"><b>'+g.name+'</b><div>Round: '+r.round_id+'</div><div>Thời gian: <span data-left>'+Math.ceil((new Date(r.closes_at)-Date.now())/1000)+'</span>s</div><div>Mode: TEST</div><div>Difficulty: '+g.difficulty+'</div></div>';let left=Math.ceil((new Date(r.closes_at)-Date.now())/1000)||g.round_seconds||30;clearInterval(timer);timer=setInterval(()=>{left--;const n=el.querySelector('[data-left]');if(n)n.textContent=left;if(left<=0){clearInterval(timer)}},1000)}catch(e){el.innerHTML='<div class="ha88-error">'+e.message+'</div>'}};render();return{refresh:render,destroy:()=>clearInterval(timer)}}
  };
})();
