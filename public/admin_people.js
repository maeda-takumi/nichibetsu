// public/admin_people.js
(function(){
  const root = document.querySelector('.container');
  const TOKEN = root?.dataset.adminToken || '';
  const API   = root?.dataset.api || 'api_people.php';

  // タブ挙動
  document.querySelectorAll('.tabs button').forEach(btn=>{
    btn.addEventListener('click', ()=>{
      document.querySelectorAll('.tabs button').forEach(b=>b.classList.remove('active'));
      btn.classList.add('active');
      document.querySelectorAll('.panel').forEach(p=>p.classList.remove('active'));
      const kind = btn.dataset.kind;
      document.getElementById('panel-'+kind).classList.add('active');
      loadList(kind);
    });
  });

  // 初期ロード
  loadList('actors'); loadList('sales');

  // 公開関数
  window.loadList = loadList;
  window.clearForm = clearForm;
  window.submitUpsert = submitUpsert;
  window.editItem = editItem;
  window.delItem = delItem;

    function loadList(kind){
    fetch(`${API}?kind=${encodeURIComponent(kind)}`, {headers:{'X-Admin-Token':TOKEN}})
        .then(r=>r.json()).then(data=>{
        const tbody = document.querySelector('#'+(kind==='actors'?'a':'s')+'-table tbody');
        tbody.innerHTML = '';
        (data.items||[]).forEach(item=>{
            const tr = document.createElement('tr');
            if (kind === 'actors') {
            // ▼ 別名列（aliases）を「有効」の前に必ず入れる
            tr.innerHTML = `
                <td>${esc(item.name)}</td>
                <td>${esc(item.kana)}</td>
                <td>${esc(item.email)}</td>
                <td>${esc((item.tags||[]).join(', '))}</td>
                <td>${esc((item.aliases||[]).join(', '))}</td>
                <td>${item.active ? '✓' : ''}</td>
                <td>${esc(item.note)}</td>
                <td class="actions">
                <button onclick="editItem('${kind}', ${encode(item)})">編集</button>
                <button onclick="delItem('${kind}', '${item.id}')">削除</button>
                </td>`;
            } else {
            tr.innerHTML = `
                <td>${esc(item.name)}</td>
                <td>${esc(item.kana)}</td>
                <td>${esc(item.email)}</td>
                <td>${esc((item.tags||[]).join(', '))}</td>
                <td>${item.active ? '✓' : ''}</td>
                <td>${esc(item.note)}</td>
                <td class="actions">
                <button onclick="editItem('${kind}', ${encode(item)})">編集</button>
                <button onclick="delItem('${kind}', '${item.id}')">削除</button>
                </td>`;
            }
            tbody.appendChild(tr);
        });
        });
    }


  function submitUpsert(kind){
    const p = kind==='actors' ? 'a' : 's';
    const id = document.getElementById(p+'-id-hint').dataset.id || '';
    const item = {
      id,
      name:  val(p,'name'),
      kana:  val(p,'kana'),
      email: val(p,'email'),
      tags:  (val(p,'tags')||'').split(',').map(s=>s.trim()).filter(Boolean),
      active: document.getElementById(p+'-active').checked,
      note:  val(p,'note'),
    };
    if (kind === 'actors') {
      item.aliases = (val(p,'aliases')||'').split(',').map(s=>s.trim()).filter(Boolean);
    }
    if(!item.name){ alert('名前は必須です'); return; }

    fetch(`${API}?kind=${encodeURIComponent(kind)}`, {
      method:'POST',
      headers:{'Content-Type':'application/json','X-Admin-Token':TOKEN},
      body: JSON.stringify({action:'upsert', item})
    }).then(r=>r.json()).then(res=>{
      if(res.error){ alert(res.error); return; }
      clearForm(kind);
      loadList(kind);
    });
  }

  function editItem(kind, item){
    const p = kind==='actors' ? 'a' : 's';
    setVal(p,'name', item.name||'');
    setVal(p,'kana', item.kana||'');
    setVal(p,'email', item.email||'');
    setVal(p,'tags', (item.tags||[]).join(','));
    if (kind === 'actors') {
      setVal(p,'aliases', (item.aliases||[]).join(','));
    }
    document.getElementById(p+'-active').checked = !!item.active;
    setVal(p,'note', item.note||'');
    const hint = document.getElementById(p+'-id-hint');
    hint.textContent = 'ID: '+item.id;
    hint.dataset.id = item.id;
  }

  function clearForm(kind){
    const p = kind==='actors' ? 'a' : 's';
    setVal(p,'name',''); setVal(p,'kana',''); setVal(p,'email','');
    setVal(p,'tags',''); setVal(p,'note','');
    if (kind === 'actors') setVal(p,'aliases','');
    document.getElementById(p+'-active').checked = true;
    const hint = document.getElementById(p+'-id-hint');
    hint.textContent = ''; hint.dataset.id = '';
  }

  function delItem(kind, id){
    if(!confirm('削除しますか？')) return;
    fetch(`${API}?kind=${encodeURIComponent(kind)}`, {
      method:'POST',
      headers:{'Content-Type':'application/json','X-Admin-Token':TOKEN},
      body: JSON.stringify({action:'delete', id})
    }).then(r=>r.json()).then(res=>{
      if(res.error){ alert(res.error); return; }
      loadList(kind);
    });
  }

  // utils
  function val(p, k){ return document.getElementById(`${p}-${k}`).value.trim(); }
  function setVal(p, k, v){ document.getElementById(`${p}-${k}`).value = v; }
  function esc(s){ return (s||'').replace(/[&<>"']/g, c=>({ '&':'&amp;','<':'&gt;','>':'&gt;','"':'&quot;', "'":'&#39;' }[c])); }
  function encode(o){ return JSON.stringify(o).replace(/"/g,'&quot;'); }
})();
