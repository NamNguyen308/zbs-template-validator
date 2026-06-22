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
      <p>Validate ZBS template JSON before submitting for moderation.</p>
    </div>
    <span class="badge">Rule-based MVP</span>
  </header>

  <main class="layout">
    <section class="card input-card">
      <div class="card-header">
        <h2>Input JSON</h2>
        <button id="loadSampleBtn" class="secondary-btn">Load sample reject</button>
      </div>

      <textarea id="jsonInput" placeholder="Paste ZBS template JSON here..."></textarea>

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
      <div>
        <strong>Rules checked</strong>
        <span id="rulesChecked">6</span>
      </div>
      <div>
        <strong>Violations</strong>
        <span id="violationCount">0</span>
      </div>
      <div>
        <strong>Manual notes</strong>
        <span id="manualCount">0</span>
      </div>
    </div>

    <div id="violations" class="violations-list"></div>
  </section>

  <section class="card suggestion-card">
    <div class="card-header">
      <h2>Suggested Fixes</h2>
    </div>
    <ol id="suggestions" class="suggestions-list">
      <li>No suggestions yet.</li>
    </ol>
  </section>

  <section class="card rule-map-card">
    <h2>Rules included in this MVP</h2>
    <table>
      <thead>
        <tr>
          <th>Rule ID</th>
          <th>Rule</th>
          <th>What it checks</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td>CUST_001</td>
          <td>Customer relationship</td>
          <td>Checks whether the message identifies the recipient as a customer/member.</td>
        </tr>
        <tr>
          <td>CTX_001</td>
          <td>Transaction / service context</td>
          <td>Checks whether the message explains the transaction, service, appointment, report, or activity.</td>
        </tr>
        <tr>
          <td>PAIR_001</td>
          <td>Customer + transaction pair</td>
          <td>Checks whether customer identity is paired with a transaction/service/account identifier.</td>
        </tr>
        <tr>
          <td>PARAM_001</td>
          <td>Parameter format</td>
          <td>Checks whether parameters follow a clean format like &lt;customer_name&gt;.</td>
        </tr>
        <tr>
          <td>PARAM_002</td>
          <td>Parameter prefix clarity</td>
          <td>Checks whether important parameters have clear labels/prefixes.</td>
        </tr>
        <tr>
          <td>TEXT_001</td>
          <td>Writing quality</td>
          <td>Checks common typo-like wording and suspicious writing issues.</td>
        </tr>
      </tbody>
    </table>
  </section>

  <script src="app.js"></script>
</body>
</html>