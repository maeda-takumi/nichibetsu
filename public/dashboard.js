document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('.month-card').forEach(card => {
    const body = card.querySelector('.month-body');
    if (!body) return; // ガード

    const datesBtn  = card.querySelector('.js-toggle-dates');
    const salesBtn  = card.querySelector('.js-toggle-sales');
    const salesPanel= body.querySelector('.sales-panel');

    // ここを .sales_table も対象に
    const tables = body.querySelectorAll('.summary-table, .sales-table, .sales_table');

    function isDatesHidden() {
      return Array.from(tables).every(t => t.classList.contains('dates-hidden'));
    }

    function setDatesHidden(hidden) {
      tables.forEach(t => {
        t.classList.toggle('dates-hidden', hidden);
        t.querySelectorAll('.label-col').forEach(cell => {
          const fullText = cell?.textContent?.trim?.() ?? '';
          const firstChar = fullText.charAt(0) || '';
          cell.setAttribute('data-first', firstChar);
        });
      });
      if (datesBtn) datesBtn.textContent = hidden ? '日付を表示' : '日付を非表示';
    }

    // 初期
    setDatesHidden(isDatesHidden());

    // セールス表示/非表示の初期ラベル（両方ある時だけ）
    if (salesBtn && salesPanel) {
      salesBtn.textContent = salesPanel.classList.contains('collapsed') ? 'セールス表示' : 'セールス非表示';
      salesBtn.addEventListener('click', () => {
        const collapsed = salesPanel.classList.toggle('collapsed');
        salesBtn.textContent = collapsed ? 'セールス表示' : 'セールス非表示';
      });
    }

    if (datesBtn) {
      datesBtn.addEventListener('click', () => setDatesHidden(!isDatesHidden()));
    }
  });

  // 差分適用処理（diff テーブル）
  document.querySelectorAll('.months-grid').forEach(grid => {
    const cur = grid.querySelector('.month-card[data-kind="current"]');
    const prv = grid.querySelector('.month-card[data-kind="prev"]');
    const dif = grid.querySelector('.month-card[data-kind="diff"]');
    if (!cur || !prv || !dif) return;

    function parseVal(td) {
      const v = td?.dataset?.value ?? td?.textContent ?? '0';
      const num = parseFloat(String(v).replace(/[^\d.\-]/g, ''));
      return isNaN(num) ? 0 : num;
    }

    function fmt(type, x) {
      // 必要なら toFixed(1) にして統一
      if (type === 'pct') return (x >= 0 ? '+' : '') + x.toFixed(2) + '%';
      if (type === 'yen') return (x >= 0 ? '+' : '-') + '¥' + Math.abs(Math.round(x)).toLocaleString();
      return (x >= 0 ? '+' : '') + Math.round(x).toLocaleString();
    }

    function applyDiff(selector) {
      const tCur = cur.querySelector(selector);
      const tPrv = prv.querySelector(selector);
      const tDf  = dif.querySelector(selector);
      if (!tCur || !tPrv || !tDf) return;

      const rowsCur = Array.from(tCur.tBodies[0].rows).filter(r => !r.classList.contains('section-sep'));
      const rowsPrv = Array.from(tPrv.tBodies[0].rows).filter(r => !r.classList.contains('section-sep'));
      const rowsDf  = Array.from(tDf.tBodies[0].rows).filter(r => !r.classList.contains('section-sep'));

      rowsDf.forEach((rDf, idx) => {
        const type = rDf.dataset.type || 'num';
        const rCur = rowsCur[idx];
        const rPrv = rowsPrv[idx];
        if (!rCur || !rPrv) return;

        const cellTot = rDf.querySelector('.total-col');
        if (cellTot) {
          const vCurTot = parseVal(rCur.querySelector('.total-col'));
          const vPrvTot = parseVal(rPrv.querySelector('.total-col'));
          const diffTot = vCurTot - vPrvTot;
          cellTot.textContent = fmt(type, diffTot);
          cellTot.classList.toggle('diff-pos', diffTot > 0);
          cellTot.classList.toggle('diff-neg', diffTot < 0);
        }

        const daysCur = rCur.querySelectorAll('.day-col');
        const daysPrv = rPrv.querySelectorAll('.day-col');
        const daysDf  = rDf.querySelectorAll('.day-col');

        daysDf.forEach((cDf, i) => {
          const vCur = parseVal(daysCur[i]);
          const vPrv = parseVal(daysPrv[i]);
          const d = vCur - vPrv;
          cDf.textContent = fmt(type, d);
          cDf.classList.toggle('diff-pos', d > 0);
          cDf.classList.toggle('diff-neg', d < 0);
        });
      });
    }

    // sales（table 単位・sid 突き合わせ）
    function applySalesDiffBySidTables(grid) {
      const q = (phase) =>
        Array.from(grid.querySelectorAll(`.month-card[data-kind="${phase}"] .sales-table[data-sid]`));

      const curMap = new Map(q('current').map(t => [t.getAttribute('data-sid'), t]));
      const prvMap = new Map(q('prev').map(t => [t.getAttribute('data-sid'), t]));
      const difMap = new Map(q('diff').map(t => [t.getAttribute('data-sid'), t]));

      const lastBody = (t) => t && t.tBodies && t.tBodies.length ? t.tBodies[t.tBodies.length - 1] : null;

      // data-value 優先、無ければ textContent から数値抽出
      const num = (cell) => {
        const raw = (cell?.dataset?.value ?? cell?.textContent ?? '').toString().trim();
        const v = Number(raw.replace(/[^\d.\-]/g, ''));
        return Number.isFinite(v) ? v : 0;
      };

      const fmt = (type, v) => {
        const s = v > 0 ? '+' : v < 0 ? '-' : '';
        const a = Math.abs(v);
        if (type === 'yen') return s + '¥' + a.toLocaleString();
        if (type === 'pct') return s + (Math.round(a * 10) / 10).toFixed(1) + '%'; // 小数1位
        return s + a.toLocaleString();
      };

      difMap.forEach((difTable, sid) => {
        const curTable = curMap.get(sid);
        const prvTable = prvMap.get(sid);
        if (!curTable || !prvTable) return;

        const bc = lastBody(curTable);
        const bp = lastBody(prvTable);
        const bd = lastBody(difTable);
        if (!bc || !bp || !bd) return;

        // ★ 区切り行を除外してから同じインデックスでマッチ
        const rc = Array.from(bc.rows).filter(r => !r.classList.contains('section-sep'));
        const rp = Array.from(bp.rows).filter(r => !r.classList.contains('section-sep'));
        const rd = Array.from(bd.rows).filter(r => !r.classList.contains('section-sep'));
        const rows = Math.min(rc.length, rp.length, rd.length);

        for (let i = 0; i < rows; i++) {
          const rC = rc[i], rP = rp[i], rD = rd[i];
          if (!rC || !rP || !rD) continue;

          const type = rC.dataset.type || 'num';

          // 合計セル
          const cTot = rC.querySelector('.total-col');
          const pTot = rP.querySelector('.total-col');
          const dTot = rD.querySelector('.total-col');
          if (cTot && pTot && dTot) {
            const dv = num(cTot) - num(pTot);
            dTot.dataset.value = String(dv);
            dTot.textContent   = fmt(type, dv);
            dTot.classList.toggle('diff-pos', dv > 0);
            dTot.classList.toggle('diff-neg', dv < 0);
          }

          // 日別（data-day キーで対応）
          rC.querySelectorAll('.day-col[data-day]').forEach(cDay => {
            const day  = cDay.getAttribute('data-day');
            const pDay = rP.querySelector(`.day-col[data-day="${day}"]`);
            const dDay = rD.querySelector(`.day-col[data-day="${day}"]`);
            if (!pDay || !dDay) return;
            const dv = num(cDay) - num(pDay);
            dDay.dataset.value = String(dv);
            dDay.textContent   = fmt(type, dv);
            dDay.classList.toggle('diff-pos', dv > 0);
            dDay.classList.toggle('diff-neg', dv < 0);
          });
        }
      });
    }


    // 実行
    applyDiff('.summary-table');       // summary の差分
    applySalesDiffBySidTables(grid);   // sales の差分（sid×table）
  });

  // ピン機能：ここも .sales_table を対象に追加
  document.querySelectorAll('.summary-table, .sales-table, .sales_table').forEach(table => {
    const headerCell = table.querySelector('thead th.main-col');
    if (!headerCell) return;

    const pin = document.createElement('img');
    pin.src = 'public/img/pin.png';
    pin.classList.add('pin-icon');
    headerCell.appendChild(pin);
    
    // ✅ 初期状態：アクティブにする
    table.classList.add('pin-mode');
    table.querySelectorAll('tbody .label-col').forEach(cell => {
    cell.classList.add('active_pin');
    });
    headerCell.classList.add('active_pin');
    table.querySelectorAll('tbody .total-col').forEach(cell => {
      cell.classList.add('active_pin');
    });
    table.querySelectorAll('thead .total-col').forEach(cell => {
      cell.classList.add('active_pin');
    });
    pin.addEventListener('click', (e) => {
      e.stopPropagation();
      const isActive = table.classList.toggle('pin-mode');
      table.querySelectorAll('tbody .label-col').forEach(cell => {
        cell.classList.toggle('active_pin', isActive);
      });
      table.querySelectorAll('tbody .total-col').forEach(cell => {
        cell.classList.toggle('active_pin', isActive);
      });
      table.querySelectorAll('thead .total-col').forEach(cell => {
        cell.classList.toggle('active_pin', isActive);
      });      
      headerCell.classList.toggle('active_pin', isActive);
    });
  });

});
