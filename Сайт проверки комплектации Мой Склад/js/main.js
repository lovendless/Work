$(function () {
  // ===== helpers =====
  const END_DELAY = 200;

  // --- глобальный буфер сканера (подняли выше, чтобы использовать в перехватчиках ниже) ---
  let buf = '';
  let timer = null;

  function flashOK(el) {
    el.addClass('filled-ok').css('outline', '2px solid #22c55e');
    el.get(0).scrollIntoView({ behavior: 'smooth', block: 'center' });
    setTimeout(() => { el.css('outline', ''); }, 900);
  }

  const onlyDigits = (s) => (s || '').replace(/\D+/g, '');

  //============= Парсим, достаём баркод ===============
  function parseScan(raw) {
    if (!raw) return null;
    const s = String(raw).trim();

    let m = s.match(/\(?01\)?\s*([0-9]{14})/);
    if (m) {
      const gtin14 = m[1];
      const ean13 = gtin14.startsWith('0') ? gtin14.slice(1) : gtin14.slice(-13);
      return { type: 'kiz', barcode: ean13, raw: s };
    }
    m = s.match(/01([0-9]{14})/);
    if (m) {
      const gtin14 = m[1];
      const ean13 = gtin14.startsWith('0') ? gtin14.slice(1) : gtin14.slice(-13);
      return { type: 'kiz', barcode: ean13, raw: s };
    }

    const digits = onlyDigits(s);
    if ([8, 12, 13, 14].includes(digits.length)) {
      let code = digits;
      if (code.length === 14 && code.startsWith('0')) code = code.slice(1);
      return { type: 'barcode', barcode: code, raw: s };
    }
    return { type: 'text', barcode: null, raw: s };
  }

  //== Берём массив баркодов у товара из аттр data-barcodes ==
  function getBarcodes(el) {
    let list = el.data('barcodes');
    if (Array.isArray(list)) return list;
    try { list = JSON.parse(el.attr('data-barcodes') || '[]'); } catch (e) { list = []; }
    return list;
  }

  //== Находим карточку товара по отсканеному баркоду ==
  function findProductByBarcode(barcode, products) {
    const norm = onlyDigits(barcode);
    for (let i = 0; i < products.length; i++) {
      const el = products.eq(i);
      const list = getBarcodes(el);
      if (list.some(b => onlyDigits(b) === norm)) return el;
    }
    return null;
  }

  //== Находим след пустую строку карточки товара ==
  function firstEmptyField(container) {
    return container.find('.product-inputs .scan-field').filter(function () {
      return $(this).attr('data-filled') !== '1';
    }).first();
  }

  // дубликат КИЗ: считаем только уже подтверждённые поля, помеченные data-scantype="kiz"
  function isDuplicateKIZ(raw) {
    const norm = String(raw).trim();
    let dup = false;
    $('.scan-field[data-scantype="kiz"]').each(function () {
      const i = $(this);
      if ((i.val() || '').trim() === norm) {
        dup = true; return false; // break
      }
    });
    return dup;
  }

  function shouldScanDataMatrix(container, info) {
    const isMarked = String(container.data('marked')) === '1';

    let list = container.data('barcodes');
    if (!Array.isArray(list)) {
      // fix: было $container (не определён), заменено на container
      try { list = JSON.parse(container.attr('data-barcodes') || '[]'); } catch (e) { list = []; }
    }

    const norm = onlyDigits(info.raw);
    const inList = list.some(b => onlyDigits(String(b)) === norm);

    return isMarked && inList;
  }

  // Устанавливаем значение в поле и необходимые аттрибуты при успехе
  function setField(field, value, type) {
    field.val(value).attr('data-filled', '1').attr('data-scantype', type || '');
    flashOK(field);
  }

  // ===== запрет ручного редактирования =====
  // 1) Глушим вставку/drag-n-drop в полях сканов
  $(document)
    .on('beforeinput paste drop', '.scan-field', function (e) {
      e.preventDefault();
    })
    // 2) Перехватываем клавиши в .scan-field:
    //    - запрещаем менять значение
    //    - символы отправляем в глобальный буфер сканера
    .on('keydown', '.scan-field', function (e) {
      // Разрешаем только Enter для подтверждения уже установленного значения
      if (e.key === 'Enter' || e.key === 'NumpadEnter') {
        e.preventDefault();
        handleScanString($(this).val());
        const container = $(this).closest('.product-item');
        const next = container.length ? firstEmptyField(container) : $();
        if (next.length) next.focus();
        updateSaveState();
        return;
      }

      // Блокируем попытки редактирования (Backspace/Delete и т.п.)
      if (['Backspace', 'Delete'].includes(e.key)) {
        e.preventDefault();
        return;
      }

      // Любые печатные символы не пишем в инпут, а отправляем в глобальный буфер сканера
      if (e.key.length === 1 && !e.ctrlKey && !e.altKey && !e.metaKey) {
        e.preventDefault();
        buf += e.key;
        clearTimeout(timer);
        timer = setTimeout(() => {
          const s = buf.trim();
          buf = '';
          if (s) handleScanString(s);
        }, END_DELAY);
        return;
      }

      // Навигационные можно оставить, остальное блокируем
      if (['ArrowLeft','ArrowRight','ArrowUp','ArrowDown','Tab','Shift','Escape'].includes(e.key)) {
        return;
      }
      e.preventDefault();
    });

  // 3) Визуально помечаем поля как «только из сканера»
  $('.scan-field').attr('readonly', true).css('caret-color', 'transparent');

  // 4) (если нужно закрыть прочие поля формы) делаем их нередактируемыми

  $('input:not(.scan-field):not(.cz-editable):not([type=checkbox]):not([type=radio]), textarea:not(.cz-editable)')
    .attr('readonly', true);
  $('select:not(.cz-editable), input[type=checkbox]:not(.cz-editable), input[type=radio]:not(.cz-editable)')
    .prop('disabled', true);

  // ===== основной код =====
  const root = $('#order-root');
  if (!root.length) return;

  const ORDER_CTX = {
    id: root.data('orderId') || '',
    number: root.data('orderNumber') || '',
    paymentMethod: root.data('paymentMethod') || '',
    withdrawnDate: root.data('withdrawnDate') || ''
  };
  
  const ORDER_LOCKED = String(root.data('orderLocked') || '0') === '1';

  const productEls = $('.product-item');
  const saveBtn = $('#saveBtn');
  const saveMsg = $('#saveMsg');
  
    if (ORDER_LOCKED) {
  // Полная блокировка «вторичного» сохранения
  if (saveBtn.length) {
    saveBtn.attr('disabled', 'true').addClass('disabled').text('Сохранено ранее');
  }
  if (saveMsg.length) {
    saveMsg.text('Этот заказ уже сохранён. Повторное сохранение запрещено.')
      .css('color', '#6b7280');
  }
  }

  function allFilled() {
    for (let i = 0; i < productEls.length; i++) {
      const el = productEls.eq(i);
      const slots = parseInt(el.data('slots') || 0, 10);
      if (slots <= 0) continue;
      const fields = el.find('.scan-field');
      if (fields.length < slots) return false;
      const empties = fields.filter(function () {
        return !($(this).val() || '').trim();
      });
      if (empties.length > 0) return false;
    }
    return true;
  }

  function updateSaveState() {
    if (!saveBtn.length) return;
    const ok = allFilled();
    saveBtn.prop('disabled', !ok)
      .css({ cursor: ok ? 'pointer' : 'not-allowed', opacity: ok ? 1 : .7 });
  }

  $(document).on('input', '.scan-field', updateSaveState);
  updateSaveState();

  function handleScanString(s) {
    const info = parseScan(s);
    if (!info || !info.raw) return;

    if (info.type === 'text' || !info.barcode) {
      alert('Нераспознанный код. Повторите сканирование.');
      return;
    }

    const prod = findProductByBarcode(info.barcode, productEls);
    if (!prod) {
      alert('Данного товара нет в заказе: ' + info.barcode);
      return;
    }

    const isMarked = String(prod.data('marked')) === '1';

    // ===== КИЗ =====
    if (info.type === 'kiz') {
      // (опционально) кириллица в сыром вводе
      if (/[А-Яа-яЁё]/.test(info.raw)) {
        alert('Некорректный баркод, переключите язык.');
        return;
      }

      // дубликаты — ТОЛЬКО для КИЗ
      if (isDuplicateKIZ(info.raw)) {
        alert('Данный КИЗ уже был просканирован.');
        return;
      }

      const slot = firstEmptyField(prod);
      if (!slot.length) {
        alert('Ошибка, этот товар уже укомплектован в нужном количестве');
        return;
      }
      setField(slot, info.raw, 'kiz'); // явное указание типа
      updateSaveState();
      return;
    }

    // ===== ШТРИХКОД =====
    // если товар маркируемый и пришёл штрихкод товара — просим сканировать DM/КИЗ
    if (shouldScanDataMatrix(prod, info)) {
      alert('Для этого товара нужно сканировать Дата матрикс код на крышке, а не баркод');
      return;
    }

    // НЕТ проверки дубликатов для штрихкодов — баркод одинаковый у всех единиц
    const slot = firstEmptyField(prod);
    if (!slot.length) {
      alert('Ошибка, этот товар уже укомплектован в нужном количестве');
      return;
    }
    setField(slot, info.raw, 'barcode');
    updateSaveState();
  }

  $(document).on('keydown', function (e) {
    const t = $(e.target);
    const isTyping = t.is('input, textarea, [contenteditable="true"]');

    if (isTyping) {
      if (t.hasClass('scan-field') && (e.key === 'Enter' || e.key === 'NumpadEnter')) {
        e.preventDefault();
        handleScanString(t.val());
        const container = t.closest('.product-item');
        const next = container.length ? firstEmptyField(container) : $();
        if (next.length) next.focus();
        updateSaveState();
      }
      return;
    }

    if (e.key === 'Enter' || e.key === 'NumpadEnter') {
      e.preventDefault();
      const s = buf.trim();
      buf = '';
      if (s) handleScanString(s);
      return;
    }

    if (e.key.length === 1 && !e.ctrlKey && !e.altKey && !e.metaKey) {
      buf += e.key;
      clearTimeout(timer);
      timer = setTimeout(() => {
        const s = buf.trim();
        buf = '';
        if (s) handleScanString(s);
      }, END_DELAY);
    }
  });

  async function doSave() {
    if (!allFilled()) {
      alert('Заполните все поля сканов.');
      return;
    }

   const items = productEls.map(function () {
  const el = $(this);

  const scans = el.find('.scan-field').map(function () {
    return ($(this).val() || '').trim();
  }).get().filter(Boolean);

  // НОВОЕ: детальный список со значением и типом (barcode/kiz)
  const scansDetailed = el.find('.scan-field').map(function () {
    const v = ($(this).val() || '').trim();
    const t = ($(this).attr('data-scantype') || '').trim();
    return v ? { value: v, type: t } : null;
  }).get().filter(Boolean);

  return {
    assortId: el.data('assort-id') || null,
    name: el.data('name') || null,
    isMarked: String(el.data('marked')) === '1',
    priceRub: parseFloat(el.data('price') || '0') || 0,
    slots: parseInt(el.data('slots') || '0', 10),
    scans,                // старое поле — оставляем для совместимости
    scansDetailed         // НОВОЕ
  };
}).get();

    if (saveBtn.length) {
      saveBtn.prop('disabled', true).text('Сохраняю...');
    }
    if (saveMsg.length) saveMsg.text('');

    $.ajax({
      url: 'save_scans.php?debug=1',
      method: 'POST',
      contentType: 'application/json; charset=utf-8',
      data: JSON.stringify({
        action: 'save_scans',
        order: ORDER_CTX,
        items
      }),
      dataType: 'json'
    })
      .done(function (data, textStatus, jqXHR) {
        if (!data || !data.ok) {
          const msg = (data && data.error) ? data.error : ('HTTP ' + jqXHR.status);
          throw new Error(msg);
        }
        if (saveBtn.length) saveBtn.text('Сохранено');
        if (saveMsg.length) {
          saveMsg.text('Записей сохранено: ' + (data.saved || 0)).css('color', '#16a34a');
        }
      if (data.cz_debug) {
  const box = $('<div style="margin-top:12px;padding:10px;border:1px solid #ddd;border-radius:8px;"></div>');
  box.append('<div style="font-weight:600;margin-bottom:8px;">Данные из Честного Знака</div>');
	data.cz_json
  if (data.cz_debug.exp_by_cis) {
    const exp = data.cz_debug.exp_by_cis;
    const table = $('<table style="width:100%;border-collapse:collapse;font-size:12px;"></table>');
    table.append('<tr><th style="text-align:left;border-bottom:1px solid #eee;padding:4px 6px;">CIS</th><th style="text-align:left;border-bottom:1px solid #eee;padding:4px 6px;">expirationDate</th></tr>');
    Object.keys(exp).forEach(function(k){
      table.append(
        '<tr><td style="border-bottom:1px solid #f3f4f6;padding:4px 6px;word-break:break-all;">'
        + k + '</td><td style="border-bottom:1px solid #f3f4f6;padding:4px 6px;">'
        + exp[k] + '</td></tr>'
      );
    });
    box.append(table);
  }
        if (data.cz_debug.raw_to_norm) {
    box.append('<div style="font-size:12px;margin:6px 0;">Нормализация (raw → normalized 12):</div>');
    box.append($('<pre style="white-space:pre-wrap;background:#f9fafb;border:1px solid #eee;border-radius:6px;padding:8px;"></pre>')
               .text(JSON.stringify(data.cz_debug.raw_to_norm, null, 2)));
           box.append($('<pre style="white-space:pre-wrap;background:#f9fafb;border:1px solid #eee;border-radius:6px;padding:8px;"></pre>')
               .text(JSON.stringify(data.cz_json, null, 2)));
  }

  // сырой JSON для быстрой отладки
  const pre = $('<pre style="white-space:pre-wrap;background:#f9fafb;border:1px solid #eee;border-radius:6px;padding:8px;margin-top:8px;max-height:320px;overflow:auto;"></pre>');
  pre.text(JSON.stringify(data.cz_debug.raw_response, null, 2));
  box.append(pre);

  $('#saveMsg').after(box);
}
      })
      .fail(function (jqXHR) {
        if (saveBtn.length) {
          saveBtn.prop('disabled', false).text('Сохранить');
        }
        if (saveMsg.length) {
          let msg = 'HTTP ' + jqXHR.status;
          try { const d = JSON.parse(jqXHR.responseText); if (d && d.error) msg = d.error; } catch (e) { }
          saveMsg.text('Ошибка сохранения: ' + msg).css('color', '#dc2626');
        }
      });
  }

  saveBtn.on('click', function (e) {
    e.preventDefault();
    if (ORDER_LOCKED) {
    alert('Этот заказ уже был сохранён ранее. Повторное сохранение запрещено.');
    return;
  	}
    doSave();
  });
  
  // ===== [CZ] отрисовка результата /cises/info =====
function escHtml(s){ return String(s).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;', "'":'&#39;'}[m])); }

function renderCzTable(czObj) {
  // контейнер под таблицу (создадим один раз)
  let box = document.getElementById('czResults');
  if (!box) {
    const after = document.getElementById('saveMsg') || document.getElementById('saveBtn');
    box = document.createElement('div');
    box.id = 'czResults';
    box.style.marginTop = '16px';
    box.style.padding = '12px';
    box.style.border = '1px solid #e5e7eb';
    box.style.borderRadius = '10px';
    box.style.background = '#fafafa';
    after.parentNode.insertBefore(box, after.nextSibling);
  }

  if (!czObj || !czObj.rows) {
    box.innerHTML = '<div style="color:#666">Данные ЧЗ отсутствуют.</div>';
    return;
  }

  // если была ошибка на сервере — покажем её
  if (czObj.rows._error) {
    box.innerHTML = '<div style="color:#dc2626"><b>Ошибка ЧЗ:</b> ' + escHtml(czObj.rows._error) + '</div>';
    return;
  }

  const rows = Array.isArray(czObj.rows) ? czObj.rows : [];
  if (!rows.length) {
    box.innerHTML = '<div style="color:#666">ЧЗ вернул пустой список.</div>';
    return;
  }

  // Таблица: CIS | expirationDate | gtin/ean | status | name
  let html = '<div style="font-weight:600;margin-bottom:8px;">Данные из Честного Знака</div>';
  html += '<div style="overflow:auto;"><table style="width:100%;border-collapse:collapse;">';
  html += '<thead><tr>' +
          '<th style="text-align:left;border-bottom:1px solid #ddd;padding:6px 8px;">CIS (КИЗ)</th>' +
          '<th style="text-align:left;border-bottom:1px solid #ddd;padding:6px 8px;">Срок годн.</th>' +
          '<th style="text-align:left;border-bottom:1px solid #ddd;padding:6px 8px;">GTIN/EAN</th>' +
          '<th style="text-align:left;border-bottom:1px solid #ddd;padding:6px 8px;">Статус</th>' +
          '<th style="text-align:left;border-bottom:1px solid #ddd;padding:6px 8px;">Наименование</th>' +
          '</tr></thead><tbody>';

  rows.forEach(function(r) {
    const cis = r.cis || r.cisFull || r.cis_full || '';
    const exp = r.expirationDate || r.expiryDate || r.expiration_date || '';
    const gtin = r.gtin || r.gtinCode || r.code || '';
    const status = r.status || r.state || '';
    const name = r.productName || r.name || '';

    html += '<tr>' +
      '<td style="border-bottom:1px solid #eee;padding:6px 8px;font-family:monospace;">' + escHtml(cis) + '</td>' +
      '<td style="border-bottom:1px solid #eee;padding:6px 8px;">' + escHtml(exp || '—') + '</td>' +
      '<td style="border-bottom:1px solid #eee;padding:6px 8px;">' + escHtml(gtin || '—') + '</td>' +
      '<td style="border-bottom:1px solid #eee;padding:6px 8px;">' + escHtml(status || '—') + '</td>' +
      '<td style="border-bottom:1px solid #eee;padding:6px 8px;">' + escHtml(name || '—') + '</td>' +
      '</tr>';
  });

  html += '</tbody></table></div>';

  // компактный «сырой» JSON под спойлером
  let raw = '';
  try { raw = JSON.stringify(rows, null, 2); } catch(e) { raw = ''; }
  if (raw) {
    html += '<details style="margin-top:8px;"><summary style="cursor:pointer;color:#4A65FF">Показать RAW JSON</summary>' +
            '<pre style="white-space:pre-wrap;font-size:12px;background:#fff;border:1px solid #eee;border-radius:8px;padding:8px;max-height:260px;overflow:auto;">' +
            escHtml(raw) + '</pre></details>';
  }

  box.innerHTML = html;
}
});
