const jsonInput = document.getElementById("jsonInput");
const validateBtn = document.getElementById("validateBtn");
const clearBtn = document.getElementById("clearBtn");
const loadSampleBtn = document.getElementById("loadSampleBtn");

const messagePreview = document.getElementById("messagePreview");
const templateTypeBadge = document.getElementById("templateTypeBadge");
const statusBadge = document.getElementById("statusBadge");
const rulesChecked = document.getElementById("rulesChecked");
const violationCount = document.getElementById("violationCount");
const manualCount = document.getElementById("manualCount");
const violationsBox = document.getElementById("violations");
const suggestionsBox = document.getElementById("suggestions");
const debugPanel = document.getElementById("debugPanel");

validateBtn.addEventListener("click", validateTemplate);
clearBtn.addEventListener("click", clearAll);
loadSampleBtn.addEventListener("click", loadSample);

async function validateTemplate() {
  const template = jsonInput.value.trim();

  if (!template) {
    alert("Please paste template JSON first.");
    return;
  }

  validateBtn.disabled = true;
  validateBtn.textContent = "Validating...";
  debugPanel.classList.add("hidden");
  debugPanel.textContent = "";

  try {
    const response = await fetch("validate.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ template })
    });

    const raw = await response.text();
    let result;

    try {
      result = JSON.parse(raw);
    } catch (parseError) {
      console.error("Server did not return JSON:", raw);
      debugPanel.classList.remove("hidden");
      debugPanel.textContent = raw;
      alert("PHP returned an error page instead of JSON. Check the debug panel below.");
      return;
    }

    renderResult(result);
  } catch (error) {
    alert("Validation failed. Please check network or PHP server.");
    console.error(error);
  } finally {
    validateBtn.disabled = false;
    validateBtn.textContent = "Validate Template";
  }
}

function renderResult(result) {
  if (result.status === "error") {
    alert(result.message || "Validation error");
    return;
  }

  templateTypeBadge.textContent = result.template_type || "Unknown";
  rulesChecked.textContent = result.rules_checked || 5;
  violationCount.textContent = result.violations_count || 0;
  manualCount.textContent = result.manual_notes_count || 0;

  statusBadge.textContent = result.status === "pass" ? "Pass" : "Fail";
  statusBadge.className = result.status === "pass" ? "status pass" : "status fail";

  renderPreview(result.preview || null);
  renderViolations(result.violations || []);
  renderSuggestions(result.suggestions || []);
}

function renderPreview(preview) {
  if (!preview || !preview.title) {
    messagePreview.className = "message-preview empty";
    messagePreview.innerHTML = "No readable message text found.";
    return;
  }

  const cleaned = normalizePreview(preview);

  messagePreview.className = "message-preview";

  const logo = cleaned.logo_text || "OA";
  const logoImage = cleaned.logo_image_url || null;
  const title = cleaned.title;
  const body = cleaned.body || [];
  const infoRows = cleaned.info_rows || [];
  const utilityBox = cleaned.utility_box || null;
  const buttons = cleaned.buttons || [];

  messagePreview.innerHTML = `
    <div class="zalo-phone-card">
      <div class="brand-logo-wrap">
        ${
          logoImage
            ? `<img class="brand-logo-img" src="${escapeHtml(logoImage)}" alt="OA logo" />`
            : `<div class="brand-logo-text">${escapeHtml(logo)}</div>`
        }
      </div>

      <div class="zalo-title">${renderParams(title.text)}</div>

      <div class="zalo-body">
        ${body.map(item => `<p>${renderParams(item.text)}</p>`).join("")}
      </div>

      ${infoRows.length ? `
        <div class="zalo-info-table">
          ${infoRows.map(row => `
            <div class="zalo-info-row">
              <span>${escapeHtml(row.label)}</span>
              <strong>${renderParams(row.value)}</strong>
            </div>
          `).join("")}
        </div>
      ` : ""}

      ${utilityBox ? `
        <div class="zalo-payment-box">
          <div class="payment-left">
            <div class="payment-title">${escapeHtml(utilityBox.title)}</div>
            <div class="payment-amount">${renderParams(utilityBox.amount)}</div>
            ${(utilityBox.details || []).map(d => `
              <div class="payment-detail">${renderParams(d.text)}</div>
            `).join("")}
          </div>
          <div class="payment-icon">▣</div>
        </div>
      ` : ""}

      <div class="zalo-buttons">
        ${buttons.length ? buttons.map((btn, index) => `
          <button type="button" class="${index === 0 ? "primary-preview-btn" : "secondary-preview-btn"}">
            ${index === 1 ? "☎ " : ""}${escapeHtml(btn.text)}
          </button>
        `).join("") : ""}
      </div>
    </div>
  `;
}

function normalizePreview(preview) {
  const title = preview.title;
  const titleKey = normalizeText(title?.text || "");

  const body = dedupeByText(preview.body || [])
    .filter(item => normalizeText(item.text) !== titleKey)
    .filter(item => !looksLikeUtilityText(item.text));

  const infoRows = dedupeRows(preview.info_rows || []);
  const buttons = dedupeByText(preview.buttons || []);

  let utilityBox = preview.utility_box || null;

  if (utilityBox) {
    utilityBox = {
      title: utilityBox.title || "",
      amount: utilityBox.amount || "",
      details: dedupeByText(utilityBox.details || [])
    };
  }

  return {
    ...preview,
    logo_text: normalizeLogo(preview.logo_text),
    logo_image_url: preview.logo_image_url || null,
    title,
    body,
    info_rows: infoRows,
    utility_box: utilityBox,
    buttons
  };
}

function normalizeLogo(logo) {
  const text = String(logo || "OA").trim();

  if (text.toLowerCase().includes("bv")) return "BVland";

  return text;
}

function dedupeByText(items) {
  const seen = new Set();
  const result = [];

  for (const item of items) {
    const key = normalizeText(item.text || "");

    if (!key) continue;
    if (seen.has(key)) continue;

    seen.add(key);
    result.push(item);
  }

  return result;
}

function dedupeRows(rows) {
  const seen = new Set();
  const result = [];

  for (const row of rows) {
    const key = normalizeText(`${row.label}|${row.value}`);

    if (!key || key === "|") continue;
    if (seen.has(key)) continue;

    seen.add(key);
    result.push(row);
  }

  return result;
}

function looksLikeUtilityText(text) {
  const value = normalizeText(text);

  return (
    value === "số tiền thanh toán" ||
    value.includes("ngân hàng tmcp") ||
    value.startsWith("tài khoản:") ||
    value.includes("công ty cp bv invest") ||
    value.startsWith("giảm ") ||
    value.startsWith("hsd:")
  );
}

function normalizeText(text) {
  return String(text || "")
    .trim()
    .replace(/\s+/g, " ")
    .toLowerCase();
}

function renderParams(text) {
  const safe = escapeHtml(text);

  return safe.replace(/(&lt;[^&]+&gt;)/g, '<span class="param-inline">$1</span>');
}

function renderViolations(violations) {
  if (!violations.length) {
    violationsBox.innerHTML = `<div class="empty-state">No violations found for the selected MVP rules.</div>`;
    return;
  }

  violationsBox.innerHTML = violations.map(v => `
    <div class="violation-card">
      <div class="violation-top">
        <span class="rule-id">${escapeHtml(v.rule_id)}</span>
        <span class="category">${escapeHtml(v.category)}</span>
      </div>

      <h3>${escapeHtml(v.rule_name)}</h3>

      <div class="info-row">
        <strong>Location</strong>
        <code>${escapeHtml(v.location)}</code>
      </div>

      ${v.source_line ? `
        <div class="info-row">
          <strong>Source line</strong>
          <code>${escapeHtml(v.source_line)}</code>
        </div>
      ` : ""}

      <div class="info-row">
        <strong>Violating value</strong>
        <code>${escapeHtml(v.violating_value)}</code>
      </div>

      <div class="reason-box">
        <strong>Why this may be rejected</strong>
        <p>${escapeHtml(v.reason)}</p>
      </div>

      <div class="fix-box">
        <strong>Suggested fix</strong>
        <p>${escapeHtml(v.suggestion)}</p>
      </div>
    </div>
  `).join("");
}

function renderSuggestions(suggestions) {
  if (!suggestions.length) {
    suggestionsBox.innerHTML = "<li>No suggestions yet.</li>";
    return;
  }

  suggestionsBox.innerHTML = suggestions.map(s => `<li>${escapeHtml(s)}</li>`).join("");
}

function clearAll() {
  jsonInput.value = "";
  messagePreview.className = "message-preview empty";
  messagePreview.innerHTML = "Paste JSON and click Validate to preview the customer-facing message.";
  violationsBox.innerHTML = "";
  suggestionsBox.innerHTML = "<li>No suggestions yet.</li>";
  statusBadge.textContent = "Not checked";
  statusBadge.className = "status neutral";
  templateTypeBadge.textContent = "Unknown";
  violationCount.textContent = "0";
  manualCount.textContent = "0";
  debugPanel.classList.add("hidden");
  debugPanel.textContent = "";
}

function loadSample() {
  jsonInput.value = `"root":{7 items
"oa_id":string"335055060"
"extend_info":string"335055060"
"sections":[5 items
1:{1 item
"banner":{5 items
"title":{8 items
"text":string"Chúc mừng sinh nhật <customer_name>"
}
}
}
2:{1 item
"banner":{5 items
"title":{8 items
"text":string"Lime Orange gửi lời chúc mừng sinh nhật tốt đẹp nhất đến <span class="param"><customer_name></span>! Tri ân khách hàng hạng <span class="param"><membership_tier></span>, tặng bạn Voucher <span class="param"><discount_discountAmount></span><span class="param"><discount_discountDesc></span>. Cảm ơn bạn đã tin tưởng Lime Orange!"
}
}
}
3:{1 item
"open_utility":{8 items
"type":string"voucher"
"top":{5 items
"contents":{7 items
"items":[3 items
0:{3 items
"text":string"Giảm <discount_discountAmount>"
}
1:{3 items
"text":string"<discount_summary>"
}
2:{3 items
"text":string"HSD: <discount_startDate> - <discount_endDate>"
}
]
}
}
}
}
4:{1 item
"buttons":{2 items
"items":[3 items
0:{5 items
"text":string"Xem mã ưu đãi"
}
1:{5 items
"text":string"Mua sắm ngay"
}
2:{5 items
"text":string"Quan tâm OA"
}
]
}
}
]
}`;
}

function escapeHtml(str) {
  return String(str ?? "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}