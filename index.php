<?php
// index.php - Excel SMS Add-in (final updated)
// Flow: Settings visible -> Map card visible immediately (Option A) -> Read Selected Range -> Auto-apply mapping -> Send SMS
header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: no-referrer");
header("Permissions-Policy: microphone=(), camera=()");
date_default_timezone_set('Asia/Kolkata');

$logFile = __DIR__ . "/sms_log.txt";
function write_log($msg) {
    global $logFile;
    if (!file_exists($logFile)) {
        @file_put_contents($logFile, "");
        @chmod($logFile, 0666);
    }
    $time = date("Y-m-d H:i:s");
    $line = "[$time] $msg" . PHP_EOL;
    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}

/*
 * Proxy endpoint for forwarding batches to upstream SMS API.
 * It decodes upstream response and returns success=true only if upstream.code === "000"
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['send_sms'])) {
    $rawJson = file_get_contents("php://input");
    write_log("=== RECEIVED PROXY REQUEST ===");
    write_log("RAW_PAYLOAD_PREVIEW: " . substr($rawJson, 0, 15000));

    $payload = json_decode($rawJson, true);
    if (!is_array($payload)) {
        http_response_code(400);
        $err = ["success" => false, "error" => "invalid_json"];
        write_log("Invalid JSON payload");
        header("Content-Type: application/json");
        echo json_encode($err);
        exit;
    }

    if (!isset($payload['data']) || !is_array($payload['data'])) {
        http_response_code(400);
        $err = ["success" => false, "error" => "missing_data_array"];
        write_log("Missing data array");
        header("Content-Type: application/json");
        echo json_encode($err);
        exit;
    }

    $maxPerRequest = 10000;
    $count = count($payload['data']);
    if ($count === 0) {
        http_response_code(400);
        $err = ["success" => false, "error" => "empty_data"];
        write_log("Empty data array");
        header("Content-Type: application/json");
        echo json_encode($err);
        exit;
    }
    if ($count > $maxPerRequest) {
        http_response_code(400);
        $err = ["success" => false, "error" => "exceeds_max_per_request", "max" => $maxPerRequest];
        write_log("Attempt to send $count items > $maxPerRequest");
        header("Content-Type: application/json");
        echo json_encode($err);
        exit;
    }

    // Forward to upstream SMS API - change endpoint if needed
    $smsApi = "https://sms.versatilesmshub.com/api/smsservices.php";

    $ch = curl_init($smsApi);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => ["Content-Type: application/json"],
        CURLOPT_RETURNTRANSFER => true,
        // NOTE: SSL peer verification disabled for easier local testing. Enable in production.
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_TIMEOUT        => 60
    ]);

    $response  = curl_exec($ch);
    $curlError = curl_error($ch);
    $status    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Attempt to parse upstream response JSON
    $up = null;
    $upParsed = false;
    if ($response) {
        $up = json_decode($response, true);
        if (is_array($up)) $upParsed = true;
    }

    // Determine success by upstream code === "000"
    $upCode = $upParsed && isset($up['code']) ? (string)$up['code'] : null;
    $success = ($upCode === "000") && !$curlError;

    // Log upstream raw + parsed keys for debugging
    write_log("=== UPSTREAM HTTP => $status ===");
    if ($curlError) write_log("CurlError: " . $curlError);
    write_log("Upstream Raw (preview): " . substr($response ?: "NO RESPONSE", 0, 2000));
    if ($upParsed) {
        write_log("Upstream Parsed: code=" . ($up['code'] ?? '') . " message=" . ($up['message'] ?? '') . " jobId=" . ($up['jobId'] ?? '') . " TotalCounts=" . ($up['TotalCounts'] ?? ''));
    } else {
        write_log("Upstream response not JSON or empty");
    }
    write_log("=========================");

    // return a clear structure for client usage
    header("Content-Type: application/json");
    echo json_encode([
        "success" => $success,
        "http_status" => $status,
        "curl_error" => $curlError ?: null,
        "upstream_raw" => $response,
        "upstream_parsed" => $up ?: null,
        "upstream_code" => $upCode
    ]);
    exit;
}
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8" />
<title>Excel SMS Add-in</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<script src="https://appsforoffice.microsoft.com/lib/1/hosted/office.js"></script>
<script src="https://cdn.tailwindcss.com"></script>
<style>
:root{--bg:#f7f7f9;--card:#fff;--muted:#64748b;--accent:#2563eb;--border:#e7e9ee}
html,body{height:100%;margin:0;background:var(--bg);font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial}
.container{max-width:900px;margin:26px auto;padding:16px;display:flex;flex-direction:column;gap:12px}
.topbar{display:flex;justify-content:space-between;align-items:center}
.title{font-size:20px;font-weight:600;color:#0f172a}
.small{font-size:13px;color:var(--muted)}
.card{background:var(--card);border-radius:10px;padding:14px;border:1px solid rgba(15,23,42,0.03);box-shadow:0 8px 20px rgba(20,28,45,0.04)}
.btn{padding:9px 12px;border-radius:10px;font-weight:600;cursor:pointer}
.btn-primary{background:var(--accent);color:#fff;border:none}
.btn-outline{background:#fff;border:1px solid var(--border)}
.form-row{display:flex;flex-direction:column;gap:6px}
.input{padding:10px;border-radius:8px;border:1px solid var(--border);font-size:14px}
.select{padding:10px;border-radius:8px;border:1px solid var(--border)}
.textarea{padding:10px;border-radius:8px;border:1px solid var(--border);min-height:80px}
.column-list{border:1px solid #eef6ff;padding:8px;border-radius:8px;max-height:200px;overflow:auto;background:#fbfdff}
.checkbox-row{display:flex;align-items:center;gap:8px;padding:6px;border-radius:6px}
.checkbox-row:hover{background:#f8fbff}
.map-preview{background:#fbfdff;padding:10px;border-radius:8px;border:1px dashed #e8f0ff}
.log-wrap{max-height:260px;overflow:auto;border-radius:8px;border:1px solid #f1f5f9;background:#fff;padding:8px}
.table-log td,.table-log th{padding:8px;border-bottom:1px solid #f1f5f9;font-size:13px}
pre.detail{white-space:pre-wrap;word-break:break-word;max-height:120px;overflow:auto;margin:0}
.hint{font-size:12px;color:#ef4444}
.hidden{display:none}
</style>
</head>
<body>
<div class="container">
  <div class="topbar">
    <div>
      <div class="title">Excel SMS Add-in</div>
      <div class="small">Settings → Read Selected Range → Mapping auto-applies → Send SMS</div>
    </div>
    <div style="display:flex;gap:8px">
      <button id="settingsBtn" class="btn btn-outline">Settings</button>
    </div>
  </div>

  <!-- Settings -->
  <div id="settingsPanel" class="card">
    <div style="display:flex;justify-content:space-between;align-items:center">
      <div style="font-weight:600">Settings</div>
      <div class="small">API key is masked after saving.</div>
    </div>

    <div class="form-row" style="margin-top:10px">
      <label class="small">API Key</label>
      <div style="display:flex;gap:8px">
        <input id="apiKey" class="input" placeholder="Enter API Key" autocomplete="new-password" />
        <button id="toggleApi" class="btn btn-outline">Show</button>
      </div>
      <div class="small">API key visible while typing. After Save it will appear masked.</div>
    </div>

    <div class="form-row" style="margin-top:8px">
      <label class="small">Sender ID</label>
      <input id="sender" class="input" placeholder="Sender ID" />
    </div>

    <div class="form-row" style="margin-top:8px">
      <label class="small">Campaign ID</label>
      <input id="campaign" class="input" placeholder="Campaign ID (optional)" />
    </div>

    <div class="form-row" style="margin-top:8px">
      <label class="small">Template ID</label>
      <input id="templateid" class="input" placeholder="Template ID (optional)" />
    </div>

    <div class="form-row" style="margin-top:8px">
      <label class="small">Country Code</label>
      <input id="cc" class="input" placeholder="91" />
    </div>

    <div class="form-row" style="margin-top:8px">
      <label class="small">Message Template (use {#var#} placeholders)</label>
      <textarea id="templateText" class="textarea" placeholder="Hello {#var#}, your OTP is {#var#}"></textarea>
      <div class="small">Number of {#var#} in template defines required variable columns.</div>
    </div>

    <div style="display:flex;gap:8px;margin-top:10px">
      <button id="saveBtn" class="btn btn-primary">Save</button>
    </div>
  </div>

  <!-- Map card: VISIBLE IMMEDIATELY (Option A) -->
  <div id="mapCard" class="card">
    <!-- Map Columns header WITH the Read Selected Range button on the right -->
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
  
  <div>
    <div style="font-weight:600">Map Columns</div>
    <div class="small">Header row: <span id="headerInfo">-</span></div>
  </div>

  <div>
    <button id="readBtn" class="btn btn-outline">Read Selected Range</button>
  </div>

</div>

    <div class="form-row" style="margin-top:8px">
      <label class="small">Phone Column (select exactly 1)</label>
      <select id="phoneCol" class="select"></select>
    </div>

    <div class="form-row" style="margin-top:8px">
      <label class="small">Variable Columns (select exactly the number required)</label>
      <div class="column-list" id="varColsWrap"></div>
      <div id="varHint" class="hint hidden"></div>
    </div>

<div style="display:flex;gap:8px;margin-top:8px;align-items:flex-end">
  <div style="width:90px">
    <label class="small">Start Row</label>
    <input id="startRow" type="number" class="input" min="2" style="width:90px;padding:6px 8px" />
  </div>
  <div style="width:90px">
    <label class="small">End Row</label>
    <input id="endRow" type="number" class="input" min="2" style="width:90px;padding:6px 8px" />
  </div>
  <div style="width:140px">
    <label class="small">Batch size</label>
    <select id="batchSize" class="select" style="padding:6px 8px">
      <option value="100">100</option>
      <option value="500">500</option>
      <option value="1000">1000</option>
      <option value="2000">2000</option>
      <option value="5000">5000</option>
      <option value="10000">10000</option>
    </select>
  </div>
</div>

    <div style="display:flex;gap:8px;margin-top:10px">
      <button id="clearMapping" class="btn btn-outline">Clear</button>
    </div>

    <div style="display:flex;gap:8px;margin-top:14px;">
      <button id="sendBtn" class="btn btn-primary hidden">Send SMS</button>
    </div>

    <div id="mapPreview" class="map-preview hidden" style="margin-top:10px"></div>
  </div>

  <div id="preview" class="small"></div>

  <div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center">
      <div style="font-weight:600">Send Log</div>
      <div class="small">Shows submission success/failed only (based on upstream code "000").</div>
    </div>
    <div class="log-wrap" style="margin-top:8px">
      <table class="table-log w-full">
        <thead><tr><th style="text-align:left">Number</th><th style="text-align:left">Status</th><th style="text-align:left">Detail</th></tr></thead>
        <tbody id="logBody"></tbody>
      </table>
    </div>
  </div>
</div>

<script>
/* client-side logic */
const $ = id => document.getElementById(id);
function sleep(ms){ return new Promise(r=>setTimeout(r,ms)); }
function escapeHtml(s){ if(!s) return ''; return String(s).replace(/[&<>"']/g, c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
function cleanNumber(n){ if (!n && n !== 0) return ""; n = String(n).trim(); n = n.replace(/[^\d]/g, ""); n = n.replace(/^0+/, ""); return n; }
function countTemplatePlaceholders(tpl){ if(!tpl) return 0; const m = tpl.match(/\{#var#\}/g); return m ? m.length : 0; }
function fillTemplate(template, vars){ let i=0; return template.replace(/\{#var#\}/g, ()=> vars[i++] ?? ""); }

const KEY = "smsveup_config";

const settingsBtn = $("settingsBtn"), readBtn = $("readBtn"), sendBtn = $("sendBtn");
const settingsPanel = $("settingsPanel"), saveBtn = $("saveBtn"), toggleApi = $("toggleApi");
const apiKeyInput = $("apiKey"), senderInput = $("sender"), campaignInput = $("campaign"), templateIdInput = $("templateid"), ccInput = $("cc"), templateTextInput = $("templateText");

const mapCard = $("mapCard"), phoneColSelect = $("phoneCol"), varColsWrap = $("varColsWrap");
const varHint = $("varHint"), startRowInput = $("startRow"), endRowInput = $("endRow"), batchSizeSelect = $("batchSize");
const clearMappingBtn = $("clearMapping"), mapPreview = $("mapPreview");
const preview = $("preview"), headerInfo = $("headerInfo"), logBody = $("logBody");

let rows = [], headerRow = [], headerRangeMeta = null, selectedMapping = null;
let actualApiKey = null, apiKeyWasStored = false, apiFieldMasked = false, apiEdited = false;

// initial: map card is visible immediately (Option A)
mapPreview.classList.add("hidden");
sendBtn.classList.add("hidden");

// load stored settings (if any)
Office.onReady(async () => {
  try {
    const raw = await OfficeRuntime.storage.getItem(KEY);
    if (raw) {
      const cfg = JSON.parse(raw);
      senderInput.value = cfg.sender || "";
      campaignInput.value = cfg.campaign || "";
      templateIdInput.value = cfg.templateid || "";
      ccInput.value = cfg.cc || "91";
      templateTextInput.value = cfg.template || "";

      if (cfg.api && cfg.api.length) {
        actualApiKey = cfg.api;
        apiKeyWasStored = true;
        apiFieldMasked = true;
        apiEdited = false;
        apiKeyInput.value = maskStr();
        toggleApi.textContent = "Show";
      } else {
        actualApiKey = null;
        apiKeyWasStored = false;
        apiFieldMasked = false;
        apiEdited = false;
        apiKeyInput.value = "";
        toggleApi.textContent = "Show";
      }
    }
  } catch (e) { console.warn("storage read failed", e); }
});

// mask helper
function maskStr(){ return "••••••••"; }

// Settings behavior
settingsBtn.onclick = () => {
  settingsPanel.classList.toggle("hidden");
};

toggleApi.onclick = () => {
  if (!apiKeyWasStored && !apiEdited) return;
  if (apiFieldMasked) {
    apiKeyInput.value = actualApiKey || "";
    apiFieldMasked = false;
    toggleApi.textContent = "Hide";
  } else {
    apiKeyInput.value = maskStr();
    apiFieldMasked = true;
    toggleApi.textContent = "Show";
  }
};

apiKeyInput.addEventListener('input', () => {
  const v = apiKeyInput.value;
  if (apiFieldMasked && v !== maskStr()) {
    apiFieldMasked = false;
    apiEdited = true;
    actualApiKey = v;
    toggleApi.textContent = "Hide";
    return;
  }
  if (!apiFieldMasked) {
    apiEdited = true;
    actualApiKey = v;
  }
});

// Save settings: show map card (map card already visible in Option A, but keep consistent UX)
saveBtn.onclick = async () => {
  let keyToStore = "";
  const fieldVal = apiKeyInput.value;
  if (apiEdited) {
    keyToStore = actualApiKey && actualApiKey.length ? actualApiKey : "";
  } else {
    if (apiKeyWasStored) {
      keyToStore = apiFieldMasked ? actualApiKey : (fieldVal === actualApiKey ? actualApiKey : fieldVal);
    } else {
      keyToStore = fieldVal ? fieldVal : "";
    }
  }

  const cfg = {
    api: keyToStore || "",
    sender: senderInput.value.trim(),
    campaign: campaignInput.value.trim(),
    templateid: templateIdInput.value.trim(),
    template: templateTextInput.value,
    cc: ccInput.value.trim() || "91"
  };

  try {
    await OfficeRuntime.storage.setItem(KEY, JSON.stringify(cfg));
    if (cfg.api && cfg.api.length) {
      actualApiKey = cfg.api;
      apiKeyWasStored = true;
      apiFieldMasked = true;
      apiEdited = false;
      apiKeyInput.value = maskStr();
      toggleApi.textContent = "Show";
    } else {
      actualApiKey = null;
      apiKeyWasStored = false;
      apiFieldMasked = false;
      apiEdited = false;
      apiKeyInput.value = "";
      toggleApi.textContent = "Show";
    }
    settingsPanel.classList.add("hidden");
    // Map card is visible immediately in Option A; keep it visible
    preview.textContent = "Settings saved. Map Columns are visible — select a range in Excel and click Read Selected Range.";
  } catch (e) {
    alert("Save failed: " + e);
  }
};

// READ selected range (button is inside mapCard, always visible)
readBtn.onclick = async () => {
  rows = []; headerRow = []; headerRangeMeta = null; selectedMapping = null;
  preview.textContent = "Reading selected range...";
  headerInfo.textContent = "-";
  try {
    await Excel.run(async ctx => {
      const rng = ctx.workbook.getSelectedRange();
      rng.load("values,rowCount,columnCount,rowIndex,columnIndex");
      await ctx.sync();
      if (rng.rowCount <= 1) {
        preview.textContent = "Please select a range with header + at least one data row.";
        return;
      }
      headerRow = rng.values[0].map(c => c === null ? "" : String(c));
      rows = rng.values.slice(1);
      headerRangeMeta = {
        rowCount: rng.rowCount - 1,
        columnCount: rng.columnCount,
        startRowIndex: rng.rowIndex + 1,
        columnIndex: rng.columnIndex
      };
      preview.innerHTML = `<strong>${rows.length}</strong> data rows loaded, <strong>${headerRow.length}</strong> columns detected.`;
      headerInfo.textContent = `Header row: ${rng.rowIndex+1}, data starts: ${rng.rowIndex+2}`;
      populateColumnPicker();
    });
  } catch (e) {
    preview.innerHTML = `<span style="color:#ef4444">${e}</span>`;
    console.error(e);
  }
};

function populateColumnPicker() {
  phoneColSelect.innerHTML = "";
  varColsWrap.innerHTML = "";
  headerRow.forEach((h, idx) => {
    const colName = h || `Column ${idx+1}`;
    const opt = document.createElement("option");
    opt.value = idx;
    opt.textContent = `${idx+1}: ${colName}`;
    phoneColSelect.appendChild(opt);

    const row = document.createElement("div");
    row.className = "checkbox-row";
    row.innerHTML = `<label style="display:flex;align-items:center;gap:8px"><input type="checkbox" data-idx="${idx}"> <span>${idx+1}: ${escapeHtml(colName)}</span></label>`;
    varColsWrap.appendChild(row);
  });
  // default start/end
  startRowInput.value = 2;
  endRowInput.value = headerRangeMeta.startRowIndex + headerRangeMeta.rowCount;
  varHint.classList.add("hidden");
  mapPreview.classList.add("hidden");
  sendBtn.classList.add("hidden"); // hide send until mapping auto-applies

  // attach handlers to dynamically created elements
  Array.from(varColsWrap.querySelectorAll('input[type=checkbox]')).forEach(cb => {
    cb.addEventListener('change', autoApplyMapping);
  });
  phoneColSelect.addEventListener('change', autoApplyMapping);
  startRowInput.addEventListener('input', autoApplyMapping);
  endRowInput.addEventListener('input', autoApplyMapping);
  batchSizeSelect.addEventListener('change', autoApplyMapping);
  // templateText influences required variables too
  templateTextInput.addEventListener('input', autoApplyMapping);
}

// show/hide helper
function showVarHint(msg){ varHint.textContent = msg; varHint.classList.remove("hidden"); }
function hideVarHint(){ varHint.textContent = ""; varHint.classList.add("hidden"); }

// AUTO-MAPPING
function autoApplyMapping() {
  if (!rows || !rows.length || !headerRangeMeta) {
    sendBtn.classList.add("hidden");
    return;
  }

  const templateText = templateTextInput.value || "";
  const requiredVars = countTemplatePlaceholders(templateText);

  const phoneIndex = parseInt(phoneColSelect.value, 10);
  const selectedVarIndices = Array.from(varColsWrap.querySelectorAll("input[type=checkbox]:checked")).map(cb => parseInt(cb.getAttribute("data-idx"),10));

  if (!Number.isFinite(phoneIndex)) {
    sendBtn.classList.add("hidden");
    showVarHint("Select phone column.");
    return;
  }

  if (selectedVarIndices.includes(phoneIndex)) {
    sendBtn.classList.add("hidden");
    showVarHint("Phone column cannot be selected as a variable column.");
    return;
  }

  if (requiredVars === 0) {
    // ok
  } else {
    if (selectedVarIndices.length !== requiredVars) {
      sendBtn.classList.add("hidden");
      showVarHint(`Template requires ${requiredVars} variable column(s). You selected ${selectedVarIndices.length}.`);
      return;
    }
  }

  const startRow = parseInt(startRowInput.value, 10) || (headerRangeMeta.startRowIndex + 1);
  const endRow = parseInt(endRowInput.value, 10) || (headerRangeMeta.startRowIndex + headerRangeMeta.rowCount);
  const absoluteDataStart = headerRangeMeta.startRowIndex + 1;
  const dataStartIdx = Math.max(0, startRow - absoluteDataStart);
  const dataEndIdx = Math.min(rows.length - 1, endRow - absoluteDataStart);
  if (dataStartIdx > dataEndIdx) {
    sendBtn.classList.add("hidden");
    showVarHint("Invalid start/end rows selection.");
    return;
  }

  const batchSize = parseInt(batchSizeSelect.value, 10) || 100;
  if (batchSize > 10000) {
    alert("Batch cannot exceed 10000.");
    return;
  }

  selectedMapping = { phoneIndex, varIndices: selectedVarIndices, dataStartIdx, dataEndIdx, batchSize };
  hideVarHint();
  mapPreview.classList.remove("hidden");
  mapPreview.innerHTML = `Mapping applied — Phone column: <strong>${phoneIndex+1}</strong>. Variables: <strong>${selectedVarIndices.map(i=>i+1).join(", ") || "None"}</strong>. Rows: <strong>${dataStartIdx+1}</strong> to <strong>${dataEndIdx+1}</strong> (${dataEndIdx-dataStartIdx+1} rows). Batch: <strong>${batchSize}</strong>.`;
  sendBtn.classList.remove("hidden");
}

clearMappingBtn.onclick = () => {
  selectedMapping = null;
  // clear UI but keep map card visible (per Option A)
  phoneColSelect.innerHTML = "";
  varColsWrap.innerHTML = "";
  mapPreview.classList.add("hidden");
  sendBtn.classList.add("hidden");
  preview.textContent = "";
  headerInfo.textContent = "-";
  rows = []; headerRow = []; headerRangeMeta = null;
  logBody.innerHTML = "";
};

// Send SMS only when user clicks Send SMS
sendBtn.onclick = async () => {
  if (!rows || !rows.length) return alert("Load data first.");
  if (!selectedMapping) return alert("Mapping not applied or invalid.");

  const raw = await OfficeRuntime.storage.getItem(KEY);
  if (!raw) return alert("Configure settings first.");
  const cfg = JSON.parse(raw);
  if (!cfg.api || !cfg.api.length) return alert("API Key is required in Settings.");
  if (!cfg.sender || !cfg.sender.length) return alert("Sender ID is required in Settings.");

  const templateText = cfg.template || templateTextInput.value || "";
  const requiredVars = countTemplatePlaceholders(templateText);
  if (requiredVars !== selectedMapping.varIndices.length) {
    return alert(`Template placeholders (${requiredVars}) must equal selected variable columns (${selectedMapping.varIndices.length}).`);
  }

  const cc = cfg.cc || "91";

  // dedupe & collect rows
  const seen = new Set();
  const toSendRows = [];
  for (let i = selectedMapping.dataStartIdx; i <= selectedMapping.dataEndIdx; i++) {
    const r = rows[i];
    const rawPhone = r[selectedMapping.phoneIndex];
    const phone = cleanNumber(rawPhone);
    if (!phone) continue;
    if (seen.has(phone)) continue;
    seen.add(phone);
    const vars = selectedMapping.varIndices.map(ci => (r[ci] === null || r[ci] === undefined) ? "" : String(r[ci]));
    toSendRows.push({ phone, vars });
  }

  if (!toSendRows.length) return alert("No valid phone numbers found after deduplication.");

  const batchSize = selectedMapping.batchSize || 100;
  const maxPerPayload = 10000;
  if (batchSize > maxPerPayload) return alert(`Batch size cannot exceed ${maxPerPayload}.`);

  // prepare batches
  const batches = [];
  for (let i = 0; i < toSendRows.length; i += batchSize) {
    batches.push(toSendRows.slice(i, i + batchSize));
  }

  logBody.innerHTML = "";

  for (let bi = 0; bi < batches.length; bi++) {
    const batch = batches[bi];
    const dataArr = batch.map(item => {
      const msg = fillTemplate(templateText, item.vars);
      return {
        international: "NO",
        countrycode: cc,
        number: item.phone,
        message: msg,
        url: ""
      };
    });

    const payload = {
      api: cfg.api,
      senderid: cfg.sender,
      campaignid: cfg.campaign,
      channel: "otp",
      templateid: cfg.templateid,
      dcs: "0",
      shorturl: "NO",
      dlr: "NO",
      data: dataArr
    };

    try {
      const res = await fetch("?send_sms=1", {
        method: "POST",
        headers: {"Content-Type":"application/json"},
        body: JSON.stringify(payload)
      });
      const out = await res.json();

      // upstream parsed object (if any)
      const up = out.upstream_parsed || null;
      const upCode = out.upstream_code || null;

      // Determine status text: Success if upstream code === "000"
      const statusText = (upCode === "000") ? "Success" : "Failed";
      // short detail: show jobId or message if available
      const detail = up ? ((up.jobId ? "jobId:" + up.jobId + " - " : "") + (up.message || JSON.stringify(up))) : (out.curl_error || out.upstream_raw || JSON.stringify(out));

      // For each number in batch show same status (no per-number upstream details available)
      for (const item of batch) {
        const tr = document.createElement("tr");
        tr.innerHTML = `<td>${escapeHtml(item.phone)}</td><td>${escapeHtml(statusText)}</td><td><pre class="detail">${escapeHtml(detail)}</pre></td>`;
        logBody.prepend(tr);
      }

      // small pause
      await sleep(250);
    } catch (err) {
      const detail = err.toString();
      for (const item of batch) {
        const tr = document.createElement("tr");
        tr.innerHTML = `<td>${escapeHtml(item.phone)}</td><td>Failed</td><td><pre class="detail">${escapeHtml(detail)}</pre></td>`;
        logBody.prepend(tr);
      }
    }
  }

  alert("Sending finished. Log shows Success/Failed based on upstream `code === \"000\"`.");
};
</script>
</body>
</html>

