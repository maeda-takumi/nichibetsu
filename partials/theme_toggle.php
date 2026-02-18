<?php
// partials/theme_toggle.php
// 依存なし・自己完結（最小CSS/JS同梱）。/public/img 配下に moon.png / sol.png を配置してください。
?>
<style>
  /* 固定配置（右上） */
  /* .theme-toggle-wrap{
  } */
  /* iOS風トグル */
  .theme-toggle{
    --w: 56px; --h: 32px; --pad: 3px;
    position: relative; width: var(--w); height: var(--h);
    border-radius: 999px; border: 1px solid rgba(0,0,0,.12);
    background: rgba(255,255,255,.85);
    backdrop-filter: blur(6px);
    box-shadow: 0 6px 16px rgba(0,0,0,.12);
    display: inline-flex; align-items:center; justify-content:center;
    padding: 0 var(--pad); gap: 6px; cursor: pointer;
  }
  @media (prefers-color-scheme: dark){
    .theme-toggle{
      border-color: rgba(255,255,255,.12);
      background: rgba(22,26,29,.85);
      box-shadow: 0 8px 24px rgba(0,0,0,.45), 0 2px 10px rgba(0,0,0,.3);
    }
  }
  .theme-toggle .icon{
    position: absolute; width: 18px; height: 18px; pointer-events: none; opacity: .95;
  }
  .theme-toggle .sun{ left: 8px; }
  .theme-toggle .moon{ right: 8px; }
  .theme-toggle .knob{
    position: absolute; top: var(--pad); left: var(--pad);
    width: calc(var(--h) - var(--pad)*3); height: calc(var(--h) - var(--pad)*3);
    border-radius: 999px; border: 1px solid rgba(0,0,0,.12);
    background: #fff; box-shadow: 0 3px 10px rgba(0,0,0,.12);
    transition: transform .18s cubic-bezier(.2,.7,.2,1);
  }
  .theme-toggle.on .knob{ transform: translateX(calc(var(--w) - var(--h))); }
</style>

<div class="theme-toggle-wrap" role="presentation">
  <button id="themeToggle"
          class="theme-toggle"
          aria-label="ダークモード切替"
          role="switch"
          aria-checked="false"
          title="テーマ切替">
    <img class="icon moon"  src="public/img/moon.png"  alt="">
    <img class="icon sun" src="public/img/sol.png" alt="">
    <span class="knob"></span>
  </button>
</div>

<script>
// 自動/手動テーマ適用（data-theme=light|dark。未指定はOSに追従）
(function(){
  var KEY='app.theme'; // 'light' | 'dark' | 'auto'
  function applyTheme(mode){
    var html=document.documentElement;
    if(mode==='light'){ html.setAttribute('data-theme','light'); }
    else if(mode==='dark'){ html.setAttribute('data-theme','dark'); }
    else { html.removeAttribute('data-theme'); }
  }
  function prefersDark(){
    return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
  }
  function syncToggle(){
    var btn=document.getElementById('themeToggle');
    var forced= document.documentElement.getAttribute('data-theme');
    var isDark = forced ? (forced==='dark') : prefersDark();
    if(btn){
      btn.classList.toggle('on', isDark);
      btn.setAttribute('aria-checked', String(isDark));
    }
  }
  try{
    applyTheme(localStorage.getItem(KEY) || 'auto');
  }catch(e){}
  syncToggle();

  if(window.matchMedia){
    var mq=window.matchMedia('(prefers-color-scheme: dark)');
    (mq.addEventListener || mq.addListener).call(mq,'change',syncToggle);
  }

  var btn=document.getElementById('themeToggle');
  if(btn){
    btn.addEventListener('click', function(){
      var forced=document.documentElement.getAttribute('data-theme');
      var isDark = forced ? (forced==='dark') : prefersDark();
      var next = isDark ? 'light' : 'dark';
      applyTheme(next);
      syncToggle();
      try{ localStorage.setItem(KEY,next); }catch(e){}
    });
  }
})();
</script>
