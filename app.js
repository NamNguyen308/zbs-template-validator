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

  try {
    const response = await fetch("validate.php", {
      method: "POST",
      headers: {
        "Content-Type": "application/json"
      },
      body: JSON.stringify({ template })
    });

    const result = await response.json();
    renderResult(result);
  } catch (error) {
    alert("Validation failed. Please check your PHP server.");
    console.error(error);
  }

  validateBtn.disabled = false;
  validateBtn.textContent = "Validate Template";
}

function renderResult(result) {
  if (result.status === "error") {
    alert(result.message);
    return;
  }

  templateTypeBadge.textContent = result.template_type || "Unknown";
  rulesChecked.textContent = result.rules_checked || 6;
  violationCount.textContent = result.violations_count || 0;
  manualCount.textContent = result.manual_notes_count || 0;

  statusBadge.textContent = result.status === "pass" ? "Pass" : "Fail";
  statusBadge.className = result.status === "pass" ? "status pass" : "status fail";

  renderPreview(result.preview || []);
  renderViolations(result.violations || []);
  renderSuggestions(result.suggestions || []);
}

function renderPreview(preview) {
  if (!preview.length) {
    messagePreview.className = "message-preview empty";
    messagePreview.innerHTML = "No readable message text found.";
    return;
  }

  messagePreview.className = "message-preview";

  messagePreview.innerHTML = preview.map((item, index) => {
    const text = highlightParams(escapeHtml(item.text));
    const typeClass = index === 0 ? "preview-title" : "preview-line";

    return `
      <div class="${typeClass}">
        <span class="preview-index">${index + 1}</span>
        <p>${text}</p>
        <small>${escapeHtml(item.location)}</small>
      </div>
    `;
  }).join("");
}

function renderViolations(violations) {
  if (!violations.length) {
    violationsBox.innerHTML = `
      <div class="empty-state">
        No violations found for the selected MVP rules.
      </div>
    `;
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

  suggestionsBox.innerHTML = suggestions.map(s => `
    <li>${escapeHtml(s)}</li>
  `).join("");
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
}

function loadSample() {
  jsonInput.value = `{
  "root": {
    "sections": [
      {
        "banner": {
          "title": {
            "text": "Chúc mừng sinh nhật <customer_name>",
            "type": "text-title"
          }
        }
      },
      {
        "banner": {
          "title": {
            "text": "Lime Orange gửi lời chúc mừng sinh nhật đến <span class=\\"param\\"><customer_name></span>. Tri ân khách hàng hạng <span class=\\"param\\"><membership_tier></span>, tặng bạn Voucher <span class=\\"param\\"><discount_discountAmount></span><span class=\\"param\\"><discount_discountDesc></span>.",
            "type": "text-normal"
          }
        }
      },
      {
        "open_utility": {
          "type": "voucher",
          "top": {
            "contents": {
              "items": [
                {
                  "text": "Giảm <discount_discountAmount>",
                  "type": "text-title"
                },
                {
                  "text": "<discount_summary>",
                  "type": "text-normal"
                }
              ]
            }
          }
        }
      }
    ]
  }
}`;
}

function highlightParams(text) {
  return text.replace(/(&lt;[^&]+&gt;)/g, '<span class="param-chip">$1</span>');
}

function escapeHtml(str) {
  return String(str)
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}