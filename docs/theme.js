(()=>{const r=document.documentElement,b=document.getElementById('tt');
const sys=()=>matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light';
const cur=()=>r.getAttribute('data-theme')||sys();
const set=t=>{r.setAttribute('data-theme',t);b.textContent=t==='dark'?'Light':'Dark';
b.setAttribute('aria-label','Switch to '+(t==='dark'?'light':'dark')+' theme');};
set(cur());b.addEventListener('click',()=>set(cur()==='dark'?'light':'dark'));})();
