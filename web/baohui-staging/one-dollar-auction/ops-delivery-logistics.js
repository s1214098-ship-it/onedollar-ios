(function () {
  "use strict";

  const ENDPOINT = "ops-delivery-logistics.php";

  function salesForm() {
    return document.getElementById("salesOutForm");
  }

  function formValue(form, name) {
    const field = form.querySelector('[name="' + name + '"]');
    return field ? String(field.value || "").trim() : "";
  }

  function ensureField(form, name, labelText, type) {
    if (form.querySelector('[name="' + name + '"]')) return;
    const tracking = form.querySelector('[name="sales_tracking_no"]');
    const anchor = tracking && tracking.closest("label");
    const label = document.createElement("label");
    if (name === "sales_logistics_status_note") label.className = "wide";
    const title = document.createTextNode(labelText);
    const input = type === "textarea" ? document.createElement("textarea") : document.createElement("input");
    input.name = name;
    if (type === "date") input.type = "date";
    else if (type === "textarea") input.rows = 2;
    else input.type = "text";
    if (type === "textarea") input.placeholder = "官網貨態、門市、查詢備註";
    label.appendChild(title);
    label.appendChild(input);
    if (anchor && anchor.parentNode) anchor.parentNode.insertBefore(label, anchor.nextSibling);
    else form.appendChild(label);
  }

  function ensureSafeFields(form) {
    ensureField(form, "sales_logistics_delivery_status", "配送狀態", "text");
    ensureField(form, "sales_logistics_status_note", "查詢備註", "textarea");
    ensureField(form, "sales_logistics_checked_date", "查詢日期", "date");
  }

  function noticeBox(form) {
    let box = document.getElementById("baohui-logistics-safe-notice");
    if (box) return box;
    box = document.createElement("p");
    box.id = "baohui-logistics-safe-notice";
    box.className = "muted";
    box.style.marginTop = "8px";
    const button = form.querySelector("[data-logistics-safe-save]");
    if (button && button.parentNode) button.parentNode.insertBefore(box, button.nextSibling);
    else form.appendChild(box);
    return box;
  }

  function setNotice(form, text, ok) {
    const box = noticeBox(form);
    box.textContent = text;
    box.style.color = ok ? "#0f766e" : "#be123c";
  }

  function payloadFromForm(form) {
    const payload = {
      id: formValue(form, "sales_delivery_id"),
      delivery_no: formValue(form, "sales_doc_no"),
      tracking_no: formValue(form, "sales_tracking_no"),
      logistics_company: formValue(form, "sales_logistics_company"),
      shipping_status: formValue(form, "sales_shipping_status"),
      logistics_delivery_status: formValue(form, "sales_logistics_delivery_status"),
      logistics_status_note: formValue(form, "sales_logistics_status_note"),
      logistics_checked_date: formValue(form, "sales_logistics_checked_date")
    };
    return payload;
  }

  async function saveLogistics(form, button) {
    const payload = payloadFromForm(form);
    if (!payload.id || !payload.delivery_no) {
      setNotice(form, "這張還不是已存出貨單，不能用只更新物流。", false);
      return;
    }
    button.disabled = true;
    setNotice(form, "正在只更新物流欄位（不改庫存）…", true);
    try {
      const response = await fetch(ENDPOINT, {
        method: "POST",
        credentials: "same-origin",
        headers: {
          "Content-Type": "application/json",
          Accept: "application/json"
        },
        body: JSON.stringify(payload)
      });
      const data = await response.json().catch(function () { return null; });
      if (!data || !data.ok) {
        const err = data && (data.error || data.rejected) ? String(data.error || data.rejected) : ("HTTP " + response.status);
        setNotice(form, "物流回寫失敗：" + err, false);
        return;
      }
      const after = data.after || {};
      setNotice(form, "物流已更新（未動庫存／成本）：" + (after.delivery_no || payload.delivery_no) +
        "　" + (after.logistics_company || "未選物流") +
        "　" + (after.tracking_no || "無單號") +
        "　" + (after.shipping_status || ""), true);
    } catch (error) {
      setNotice(form, "物流回寫失敗：" + (error && error.message ? error.message : "網路錯誤"), false);
    } finally {
      button.disabled = false;
    }
  }

  function injectButton(form) {
    if (form.querySelector("[data-logistics-safe-save]")) return;
    if (!form.querySelector('[name="sales_delivery_id"]')) return;
    ensureSafeFields(form);
    const button = document.createElement("button");
    button.type = "button";
    button.className = "secondary";
    button.setAttribute("data-logistics-safe-save", "1");
    button.textContent = "只更新物流（不改庫存）";
    button.addEventListener("click", function (event) {
      event.preventDefault();
      saveLogistics(form, button);
    });
    const stockBtn = form.querySelector('button.primary, button[type="submit"]');
    if (stockBtn && stockBtn.parentNode) stockBtn.parentNode.insertBefore(button, stockBtn);
    else form.appendChild(button);
    const hint = document.createElement("small");
    hint.className = "muted";
    hint.style.display = "block";
    hint.textContent = "物流回寫走獨立 API，不會 restore 庫存、不會重建 stock_movements、不會重算成本。左邊綠色按鈕仍會更新庫存，日常查件請用這一顆。";
    button.parentNode.insertBefore(hint, button);
  }

  function hydrate() {
    const form = salesForm();
    if (!form) return;
    injectButton(form);
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", hydrate);
  } else {
    hydrate();
  }
  window.addEventListener("hashchange", hydrate);
})();
