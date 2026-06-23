<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>ZBS Template Validator</title>
  <link rel="stylesheet" href="style.css" />
</head>
<body>
  <header class="topbar">
    <div>
      <h1>ZBS Template Pre-checker</h1>
      <p>Rule-based validator for ZBS template moderation risks.</p>
    </div>
    <span class="badge">5 automatic checks</span>
  </header>

  <main class="layout">
    <section class="card input-card">
      <div class="card-header">
        <h2>Input JSON / Pseudo JSON</h2>
        <button id="loadSampleBtn" class="secondary-btn">Load sample reject</button>
      </div>

      <textarea id="jsonInput" placeholder="Paste ZBS template JSON or pseudo JSON export here..."></textarea>

      <div class="actions">
        <button id="validateBtn" class="primary-btn">Validate Template</button>
        <button id="clearBtn" class="secondary-btn">Clear</button>
      </div>
    </section>

    <section class="card preview-card">
      <div class="card-header">
        <h2>Message Overview</h2>
        <span id="templateTypeBadge" class="mini-badge">Unknown</span>
      </div>

      <div id="messagePreview" class="message-preview empty">
        Paste JSON and click Validate to preview the customer-facing message.
      </div>
    </section>
  </main>

  <section class="card result-card">
    <div class="card-header">
      <h2>Validation Result</h2>
      <span id="statusBadge" class="status neutral">Not checked</span>
    </div>

    <div id="summary" class="summary-grid">
      <div><strong>Rules checked</strong><span id="rulesChecked">5</span></div>
      <div><strong>Violations</strong><span id="violationCount">0</span></div>
      <div><strong>Manual notes</strong><span id="manualCount">0</span></div>
    </div>

    <div id="violations" class="violations-list"></div>
    <pre id="debugPanel" class="debug-panel hidden"></pre>
  </section>

  <section class="card suggestion-card">
    <div class="card-header"><h2>Suggested Fixes</h2></div>
    <ol id="suggestions" class="suggestions-list"><li>No suggestions yet.</li></ol>
  </section>

  <section class="card rule-map-card">
    <h2>Rules included in this MVP</h2>
    <table>
      <thead>
        <tr>
          <th>Rule ID</th>
          <th>Rule</th>
          <th>What it checks</th>
          <th>Example violation</th>
          <th>Suggested fix</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td>CUST_001</td>
          <td>Customer relationship</td>
          <td>Checks whether the message clearly identifies the recipient as a customer, member, or user of the business.</td>
          <td>No customer name, customer code, member code, or customer wording is found.</td>
          <td>Add wording such as “Quý khách &lt;customer_name&gt;” or “Mã khách hàng &lt;customer_code&gt;”.</td>
        </tr>
        <tr>
          <td>CTX_001</td>
          <td>Transaction / service context</td>
          <td>Checks whether the message explains the order, service, appointment, report, or activity that triggered it.</td>
          <td>The message asks for action but does not mention any order, service, appointment, report, or activity.</td>
          <td>Add context such as “Mã đơn hàng &lt;order_code&gt;”, “Dịch vụ &lt;service_name&gt;”, or “Ngày giao dịch &lt;transaction_date&gt;”.</td>
        </tr>
        <tr>
          <td>PAIR_001</td>
          <td>Customer + transaction/service identifier pair</td>
          <td>Checks whether customer identity is paired with a customer code, order ID, contract ID, transaction ID, or service/account identifier.</td>
          <td>The message has &lt;customer_name&gt; but does not include customer code, order ID, contract ID, or transaction ID.</td>
          <td>Add “Mã khách hàng &lt;customer_code&gt;”, “Mã đơn hàng &lt;order_code&gt;”, or “Mã hợp đồng &lt;contract_id&gt;”.</td>
        </tr>
        <tr>
          <td>PARAM_001</td>
          <td>Parameter format</td>
          <td>Checks whether dynamic parameters follow a clean format using letters, numbers, underscores, and angle brackets.</td>
          <td>Invalid parameter such as &lt;customer name&gt;, &lt;mã_khách_hàng&gt;, or &lt;order-id&gt;.</td>
          <td>Rename using a clean format, for example &lt;customer_name&gt; or &lt;order_id&gt;.</td>
        </tr>
        <tr>
          <td>PARAM_002</td>
          <td>Parameter prefix clarity</td>
          <td>Checks whether important parameters have a clear label or prefix. Map-info value parameters are considered labelled if their paired key exists.</td>
          <td>&lt;discount_summary&gt; appears alone without “Điều kiện áp dụng”, or &lt;discount_discountDesc&gt; appears without a clear prefix.</td>
          <td>Add labels such as “Điều kiện áp dụng: &lt;discount_summary&gt;” or “Điều kiện áp dụng: &lt;discount_discountDesc&gt;”.</td>
        </tr>
      </tbody>
    </table>
  </section>

  <script src="app.js"></script>
</body>
</html>