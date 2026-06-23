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
  rulesChecked.textContent = result.rules_checked || 6;
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

  messagePreview.className = "message-preview";

  const logo = preview.logo_text || "BRAND";
  const title = preview.title;
  const body = preview.body || [];
  const infoRows = preview.info_rows || [];
  const buttons = preview.buttons || [];

  messagePreview.innerHTML = `
    <div class="zalo-phone-card">
      <div class="brand-logo-text">${escapeHtml(logo)}</div>

      <div class="zalo-title">${highlightParams(escapeHtml(title.text))}</div>

      <div class="zalo-body">
        ${body.map(item => `<p>${highlightParams(escapeHtml(item.text))}</p>`).join("")}
      </div>

      ${infoRows.length ? `
        <div class="zalo-info-table">
          ${infoRows.map(row => `
            <div class="zalo-info-row">
              <span>${escapeHtml(row.label)}</span>
              <strong>${highlightParams(escapeHtml(row.value))}</strong>
            </div>
          `).join("")}
        </div>
      ` : ""}

      <div class="zalo-buttons">
        ${buttons.length ? buttons.map(btn => `<button type="button">${escapeHtml(btn.text)}</button>`).join("") : ""}
      </div>
    </div>
  `;
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
"oa_id":string"375075320"
"extend_info":string"375075320"
"sections":[6 items
1:{1 item
"banner":{5 items
"title":{8 items
"text":string"Nam An xin chào Quý khách <name> - Mã khách hàng <id>,"
}
}
}
2:{1 item
"banner":{5 items
"title":{8 items
"text":string"Nam An lắng nghe bạn từng chi tiết!"
}
}
}
3:{1 item
"banner":{5 items
"title":{8 items
"text":string"Ý kiến của Quý khách chính là chìa khóa để chúng tôi cải thiện dịch vụ, nâng cao chất lượng chăm sóc và mang đến trải nghiệm ngày càng tốt hơn."
}
}
}
4:{1 item
"banner":{5 items
"title":{8 items
"text":string"Quý khách vui lòng chia sẻ cảm nhận của mình để giúp chúng tôi cải thiện dịch vụ tốt hơn. Xin cảm ơn!"
}
}
}
5:{1 item
"buttons":{2 items
"items":[1 item
0:{5 items
"text":string"Khảo sát ngay"
"click":{5 items
"action":string"action.open.inapp"
"data":string"https://forms.office.com/r/pirJb83HK8"
}
}
]
}
}
]
}`;
}

function highlightParams(text) {
  return text.replace(/(&lt;[^&]+&gt;)/g, '<span class="param-chip">$1</span>');
}

function escapeHtml(str) {
  return String(str ?? "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}