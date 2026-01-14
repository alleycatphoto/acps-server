<?php
// Map location names to display names
$locMap = [
    "Hawksnest" => "Hawks Nest",
    "Test Location" => "Moonshine",
    // Add more as needed
];

// Read transactions CSV and build data
$csvFile = __DIR__ . '/transactions.csv';
$data = [];
if (file_exists($csvFile)) {
    $handle = fopen($csvFile, 'r');
    $header = fgetcsv($handle);
    while (($row = fgetcsv($handle)) !== false) {
        $locName = trim($row[0]);
        $locId = $locMap[$locName] ?? $locName;
        $date = date('Y-m-d', strtotime(trim($row[1])));
        $type = trim($row[3]);
        $amount = (float)trim($row[4]);
        if (!isset($data[$locId])) $data[$locId] = [];
        if (!isset($data[$locId][$date])) $data[$locId][$date] = ['credit' => 0, 'cash' => 0, 'orders' => 0];
        // cash orders go to cash, everything else goes to credit
        if ($type === 'cash') {
            $data[$locId][$date]['cash'] += $amount;
        } else {
            $data[$locId][$date]['credit'] += $amount;
        }
        $data[$locId][$date]['orders'] += 1;
    }
    fclose($handle);
}

// Read historical credit data
$creditCsv = __DIR__ . '/CREDIT_ALL_ALLEYCAT.csv';
if (file_exists($creditCsv)) {
    $handle = fopen($creditCsv, 'r');
    $header = fgetcsv($handle);
    while (($row = fgetcsv($handle)) !== false) {
        $locName = trim($row[0]);
        $locId = $locMap[$locName] ?? $locName;
        // Convert MM/DD/YYYY to YYYY-MM-DD
        $dateParts = explode('/', trim($row[1]));
        if (count($dateParts) === 3) {
            $date = sprintf('%04d-%02d-%02d', $dateParts[2], $dateParts[0], $dateParts[1]);
        } else {
            continue; // Skip invalid dates
        }
        $orders = (int)trim($row[2]);
        $type = strtolower(trim($row[3]));
        $amount = (float)str_replace(['$', ','], '', trim($row[4]));
        
        if (!isset($data[$locId])) $data[$locId] = [];
        if (!isset($data[$locId][$date])) $data[$locId][$date] = ['credit' => 0, 'cash' => 0, 'orders' => 0];
        
        if ($type === 'cash') {
            $data[$locId][$date]['cash'] += $amount;
        } else {
            $data[$locId][$date]['credit'] += $amount;
        }
        $data[$locId][$date]['orders'] += $orders;
    }
    fclose($handle);
}

// Read historical cash data
$historicalCsv = __DIR__ . '/historical_transactions.csv';
if (file_exists($historicalCsv)) {
    $handle = fopen($historicalCsv, 'r');
    $header = fgetcsv($handle);
    while (($row = fgetcsv($handle)) !== false) {
        $locName = trim($row[0]);
        $locId = $locMap[$locName] ?? $locName;
        // Convert MM/DD/YYYY to YYYY-MM-DD
        $dateParts = explode('/', trim($row[1]));
        if (count($dateParts) === 3) {
            $date = sprintf('%04d-%02d-%02d', $dateParts[2], $dateParts[0], $dateParts[1]);
        } else {
            continue; // Skip invalid dates
        }
        $orders = (int)trim($row[2]);
        $type = strtolower(trim($row[3]));
        $amount = (float)str_replace(['$', ','], '', trim($row[4]));
        
        if (!isset($data[$locId])) $data[$locId] = [];
        if (!isset($data[$locId][$date])) $data[$locId][$date] = ['credit' => 0, 'cash' => 0, 'orders' => 0];
        
        if ($type === 'cash') {
            $data[$locId][$date]['cash'] += $amount;
        } else {
            $data[$locId][$date]['credit'] += $amount;
        }
        $data[$locId][$date]['orders'] += $orders;
    }
    fclose($handle);
}
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>ACPS Sales Report</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
      :root {
        --bg: #070707;
        --border: #242426;
        --text: #e7e7e7;
        --muted: #a7a7a7;
        --accent: #c81c1c;
        --surface: rgba(18, 18, 20, 0.92);
        --radius: 16px;
      }
      * { box-sizing: border-box; }
      body {
        margin: 0;
        padding: 20px;
        font-family: 'Inter', sans-serif;
        background:
          radial-gradient(1200px 700px at 20% -10%, rgba(200, 28, 28, 0.15), transparent 60%),
          linear-gradient(180deg, #050505 0%, #070707 100%);
        color: var(--text);
        min-height: 100vh;
      }
      .wrap { max-width: 1200px; margin: 0 auto; }
      .header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
        margin-bottom: 20px;
        padding: 16px 20px;
        background: linear-gradient(#121214eb, #0c0c0deb);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.35);
      }
      .brand { display: flex; align-items: center; gap: 15px; }
      .brand img { height: 40px; width: auto; }
      .brand-text .title { font-weight: 900; font-size: 18px; letter-spacing: 0.5px; }
      .brand-text .subtitle { color: var(--muted); font-size: 12px; }
      .back-btn {
        color: var(--text);
        text-decoration: none;
        font-weight: 900;
        font-size: 12px;
        border: 1px solid var(--accent);
        padding: 8px 16px;
        border-radius: 12px;
        background: rgba(200, 28, 28, 0.1);
        transition: all 0.2s;
        white-space: nowrap;
      }
      .back-btn:hover { background: rgba(200, 28, 28, 0.2); transform: translateY(-1px); }

      .filters {
        display: flex;
        gap: 10px;
        align-items: center;
        flex-wrap: wrap;
      }
      input[type="date"], input[type="file"] {
        background: #111;
        border: 1px solid var(--border);
        color: #fff;
        padding: 8px 12px;
        border-radius: 8px;
        outline: none;
        font-family: inherit;
        font-size: 13px;
      }
      .btn {
        background: var(--accent);
        color: #fff;
        border: none;
        padding: 8px 16px;
        border-radius: 8px;
        font-weight: 800;
        cursor: pointer;
        transition: opacity 0.2s;
        font-family: inherit;
        font-size: 12px;
        letter-spacing: 0.4px;
      }
      .btn:hover { opacity: 0.85; }
      .btn:disabled { opacity: 0.5; cursor: not-allowed; }

      .summary-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
      }
      .stat-card {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        padding: 20px;
        display: flex;
        flex-direction: column;
        align-items: center;
        text-align: center;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.5);
      }
      .stat-val { font-size: 28px; font-weight: 900; color: #fff; margin: 5px 0; }
      .stat-label {
        color: var(--muted);
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: 1px;
        font-weight: 800;
      }

      .card {
        background: #070707;
        border: none;
        border-radius: var(--radius);
        overflow: hidden;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.5);
        backdrop-filter: blur(10px);
        margin-bottom: 30px;
      }
      .card-hd {
        padding: 20px;
        border: none;
        background: #070707;
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
      }
      .card-hd h2 {
        margin: 0;
        font-size: 18px;
        font-weight: 900;
        color: var(--accent);
        text-transform: uppercase;
        text-align: center;
      }
      .card-hd .loc-total { font-size: 16px; font-weight: 800; color: #fff; }

      table { width: 100%; border-collapse: collapse; font-size: 12px; }
      th {
        text-align: right;
        padding: 8px 10px;
        color: var(--muted);
        font-weight: 900;
        border-bottom: 1px solid #000000;
        text-transform: uppercase;
        font-size: 14px;
      }
      th:first-child { text-align: left; }
      td { padding: 8px 10px; border-bottom: 1px solid #000000; text-align: center; }
      td:first-child { text-align: left; font-weight: 700; }
      td:last-child { text-align: center; }
      tr:last-child td { border-bottom: none; }

      .col-credit { color: #3b82f6; }
      .col-cash { color: #10b981; }
      .col-total { font-weight: normal; color: #fff; }

      tfoot tr { background: rgba(255, 255, 255, 0.05); font-weight: 900; }

      /* Table row styling */
      tbody tr:nth-child(odd) { background-color: #070707 !important; }
      tbody tr:nth-child(even) { background-color: #242426 !important; }
      tbody tr { color: #fff !important; }
      tbody td:last-child { color: #27ae60 !important; font-weight: bold; }

      /* Vertical borders */
      th, td { border-right: 1px solid #000000 !important; }
      th:last-child, td:last-child { border-right: none !important; }

      /* Header styling */
      thead th { background-color: #070707 !important; }
      thead tr:first-child th { border-bottom: 1px solid #000000 !important; }

      /* Monthly total rows */
      .monthly-total {
        background-color: #1a1a1a !important;
        border-top: 2px solid #27ae60 !important;
        border-bottom: 1px solid #27ae60 !important;
        font-weight: bold !important;
      }
      .monthly-total td {
        color: #27ae60 !important;
        font-size: 13px !important;
      }

      /* Grand total row */
      .grand-total {
        background-color: #0a2a0a !important;
        border-top: 3px solid #27ae60 !important;
        font-weight: bold !important;
        font-size: 16px !important;
      }
      .grand-total td {
        color: #27ae60 !important;
      }

      /* Override Bootstrap table borders */
      .table thead th {
        border-bottom: 1px solid #000000 !important;
      }

      .table td, .table th {
        border-top: none !important;
        border-bottom: 1px solid #000000 !important;
      }

      .empty { padding: 40px; text-align: center; color: var(--muted); font-weight: 700; }

      .warn {
        border: 1px solid var(--border);
        background: rgba(255, 255, 255, 0.04);
        padding: 12px 16px;
        border-radius: 12px;
        margin-bottom: 16px;
        color: var(--muted);
        font-size: 12px;
      }
      .warn b { color: #fff; }

      .upload-card {
        display: none;
        border: 1px solid rgba(200, 28, 28, 0.45);
        background: rgba(200, 28, 28, 0.08);
      }
      .upload-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 12px;
        margin-top: 10px;
      }
      .mini {
        padding: 10px;
        border-radius: 12px;
        border: 1px solid var(--border);
        background: rgba(0, 0, 0, 0.18);
      }
      .mini .label { color: #fff; font-weight: 900; font-size: 12px; text-transform: uppercase; letter-spacing: 0.4px; }
      .mini .hint { margin-top: 6px; font-size: 12px; opacity: 0.8; }

      .mobile-summary { display: none; }

      @media (max-width: 768px) {
        body { padding: 10px; }
        .header { flex-direction: column; text-align: center; }
        .brand { flex-direction: column; }
        .filters { width: 100%; flex-direction: column; }
        .filters input, .filters button { width: 100%; }
        .upload-grid { grid-template-columns: 1fr; }

        /* Table to cards */
        table, thead, tbody, th, td, tr { display: block; }
        thead tr { position: absolute; top: -9999px; left: -9999px; }
        
        tr {
          border: 1px solid var(--border);
          margin: 0 0 10px;
          border-radius: 12px;
          background: rgba(255, 255, 255, 0.02);
          padding: 12px;
          cursor: pointer;
          transition: background 0.2s;
        }
        tr:active { background: rgba(255, 255, 255, 0.06); }

        td {
          border: none;
          position: relative;
          padding-left: 50%;
          text-align: right;
          display: flex;
          justify-content: space-between;
          align-items: center;
          padding: 8px 0;
        }
        td:before {
          content: attr(data-label);
          font-weight: 800;
          color: var(--muted);
          font-size: 11px;
          text-transform: uppercase;
        }
        
        /* First child (Date) handling */
        td:first-child {
          text-align: left;
          font-weight: 900;
          color: var(--accent);
          border-bottom: none;
          margin-bottom: 0;
          padding-bottom: 0;
          justify-content: space-between;
          width: 100%;
          padding-left: 0;
        }
        td:first-child:before { display: none; }

        /* Mobile Summary Styling */
        .mobile-summary {
          display: flex;
          gap: 8px;
          align-items: center;
          font-size: 13px;
          color: #fff;
          font-weight: 700;
        }
        .mobile-summary .dim { color: var(--muted); font-weight: 400; }
        .mobile-summary .sum-total { color: #fff; }

        /* Expansion Logic */
        tr:not(.expanded) td:not(:first-child) { display: none; }
        
        tr.expanded td:first-child {
          border-bottom: 1px solid var(--border);
          margin-bottom: 10px;
          padding-bottom: 10px;
        }
        
        tr.expanded .mobile-summary { display: none; }

        tfoot tr { background: var(--accent); color: white; }
        tfoot td { color: white; }
        tfoot td:first-child { color: white; border-bottom: 1px solid rgba(255, 255, 255, 0.2); }
      }
    </style>
  </head>

  <body>
    <div class="wrap">
      <div class="header">
        <div class="brand">
          <img src="https://alleycatphoto.net/alley_logo_sm.png" alt="Alley Cat" />
          <div class="brand-text">
            <div class="title">ALLEYCAT SALES BREAKDOWN</div>
            <div class="subtitle">Credit vs Cash Breakdown</div>
          </div>
        </div>
        <a href="/admin/index.php" class="back-btn">BACK TO ADMIN</a>
      </div>

      <div class="header" style="margin-bottom: 14px; padding: 12px 20px;">
        <div class="filters">
          <input type="date" id="startDate" />
          <input type="date" id="endDate" />
          <button id="filterBtn" class="btn">Filter</button>
          <button id="resetBtn" class="btn">Reset</button>
        </div>
      </div>

      <div id="summaryContainer" class="summary-grid">
        <!-- Summary cards will be inserted here -->
      </div>

      <div id="cardsContainer">
        <!-- Location cards will be inserted here -->
      </div>

      <div class="warn">
        <b>Note:</b> Data is aggregated from transaction logs. Ensure transactions are properly logged.
      </div>
    </div>

    <script>
      const LOCATIONS = {
        "Moonshine": "Moonshine",
        "Zip'n'Slip": "Zip'n'Slip",
        "Hawks Nest": "Hawks Nest",
        "UNKNOWN": "Unknown"
      };

      const locOrder = ['Moonshine', 'Zip\'n\'Slip', 'Hawks Nest'];

      // Data from PHP
      const rawData = <?php echo json_encode($data); ?>;

      function formatMoney(cents) {
        return `$${(cents / 100).toFixed(2)}`;
      }

      function formatDate(yyyyMmDd) {
        const d = new Date(yyyyMmDd + "T00:00:00");
        if (isNaN(d)) return yyyyMmDd;
        const yyyy = d.getFullYear();
        const mm = String(d.getMonth() + 1).padStart(2, "0");
        const dd = String(d.getDate()).padStart(2, "0");
        return `${mm}/${dd}/${yyyy}`;
      }

      function isInRange(dateISO, startISO, endISO) {
        return dateISO >= startISO && dateISO <= endISO;
      }

      function clampLocId(raw) {
        if (!raw) return "UNKNOWN";
        // Simplified: just return the location name as-is
        return raw;
      }

      function ensureDay(data, locId, dateISO) {
        if (!data[locId]) data[locId] = {};
        if (!data[locId][dateISO]) data[locId][dateISO] = { credit: 0, cash: 0, orders: 0 };
        return data[locId][dateISO];
      }

      function sortDataDescByDate(data) {
        const locs = Object.keys(data).sort();
        const sorted = { 'Moonshine': {}, 'Zip\'n\'Slip': {}, 'Hawks Nest': {}, 'UNKNOWN': {} };
        for (const locId of locs) {
          const dates = Object.keys(data[locId] || {}).sort((a, b) => (a < b ? 1 : a > b ? -1 : 0));
          for (const date of dates) sorted[locId][date] = data[locId][date];
        }
        return sorted;
      }

      function computeGrandTotals(data) {
        let total = 0;
        let orders = 0;
        for (const locId of Object.keys(data)) {
          const byDate = data[locId] || {};
          for (const date of Object.keys(byDate)) {
            const s = byDate[date];
            total += (s.credit || 0) + (s.cash || 0);
            orders += s.orders || 0;
          }
        }
        return { total, orders };
      }

      function computeLocTotals(byDate) {
        let total = 0;
        let orders = 0;
        for (const date of Object.keys(byDate)) {
          const s = byDate[date];
          total += (s.credit || 0) + (s.cash || 0);
          orders += s.orders || 0;
        }
        return { total, orders };
      }

      function renderSummaryCards(data, startISO, endISO) {
        const { total, orders } = computeGrandTotals(data);
        const container = document.getElementById('summaryContainer');
        container.innerHTML = `
          <div class="stat-card">
            <div class="stat-val">${formatMoney(total)}</div>
            <div class="stat-label">Grand Total</div>
          </div>
          <div class="stat-card">
            <div class="stat-val">${orders}</div>
            <div class="stat-label">Total Orders</div>
          </div>
        `;
      }

      function getMonthName(monthKey) {
        const monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
        try {
          const [year, monthStr] = monthKey.split('-');
          const month = parseInt(monthStr, 10) - 1;
          if (month >= 0 && month < 12) {
            return `${monthNames[month]} ${year}`;
          }
          return `Month ${monthKey}`;
        } catch (e) {
          return `Month ${monthKey}`;
        }
      }

      function renderCombinedTable(data, startISO, endISO) {
        const container = document.getElementById('cardsContainer');
        container.innerHTML = '';

        // Define location order: Moonshine, Zip'n'Slip, Hawks Nest
        const locOrder = ['Moonshine', 'Zip\'n\'Slip', 'Hawks Nest'];

        // Collect all dates
        const allDates = new Set();
        for (const locId of locOrder) {
          const byDate = data[locId] || {};
          for (const date of Object.keys(byDate)) {
            if (!startISO || !endISO || isInRange(date, startISO, endISO)) {
              allDates.add(date);
            }
          }
        }
        const sortedDates = Array.from(allDates).sort((a, b) => (a < b ? 1 : a > b ? -1 : 0)); // Most recent first

        if (!sortedDates.length) {
          container.innerHTML = '<div class="empty">No data available for the selected date range.</div>';
          return;
        }

        const card = document.createElement('div');
        card.className = 'card';
        card.innerHTML = `
          <div class="card-hd">
            <h2>Combined Sales Report</h2>
          </div>
          <table class="table table-striped table-hover">
            <thead>
              <tr>
                <th rowspan="2" style="vertical-align: bottom;">Date</th>
                <th colspan="2" style="background-color: #070707; color: #f1c40f; text-align: center;">
                  <img src="/public/assets/images/moonshine.png" alt="Moonshine" style="height:24px; margin-right:8px;">Moonshine
                </th>
                <th colspan="2" style="background-color: #070707; color: #16a085; text-align: center;">
                  <img src="/public/assets/images/zipnslip.png" alt="Zip'n'Slip" style="height:24px; margin-right:8px;">Zip'n'Slip
                </th>
                <th colspan="2" style="background-color: #070707; color: #e74c3c; text-align: center;">
                  <img src="/public/assets/images/hawk.png" alt="Hawks Nest" style="height:24px; margin-right:8px;">Hawks Nest
                </th>
                <th rowspan="2" style="background-color: #2c2c2c; color: #27ae60; text-align: center; vertical-align: bottom;">Grand Total</th>
              </tr>
              <tr>
                <th style="background-color: #070707; color: #fff; text-align: center;">Credit</th>
                <th style="background-color: #070707; color: #fff; text-align: center;">Cash</th>
                <th style="background-color: #070707; color: #fff; text-align: center;">Credit</th>
                <th style="background-color: #070707; color: #fff; text-align: center;">Cash</th>
                <th style="background-color: #070707; color: #fff; text-align: center;">Credit</th>
                <th style="background-color: #070707; color: #fff; text-align: center;">Cash</th>
              </tr>
            </thead>
            <tbody id="combined-tbody">
            </tbody>
          </table>
        `;
        container.appendChild(card);

        const tbody = card.querySelector('#combined-tbody');
        const labels = ['Date', 'Moonshine Credit', 'Moonshine Cash', 'Zip Credit', 'Zip Cash', 'Hawks Nest Credit', 'Hawks Nest Cash', 'Grand Total'];

        // Render each day (most recent first)
        for (const date of sortedDates) {
          let grandTotal = 0;
          let totalOrders = 0;
          const rowData = [formatDate(date)];

          for (const locId of locOrder) {
            const byDate = data[locId] || {};
            const day = byDate[date] || { credit: 0, cash: 0, orders: 0 };
            const credit = day.credit || 0;
            const cash = day.cash || 0;
            rowData.push(formatMoney(credit), formatMoney(cash));
            grandTotal += credit + cash;
            totalOrders += day.orders || 0;
          }
          rowData.push(formatMoney(grandTotal));

          // Include order count in date cell
          rowData[0] = `${rowData[0]} (${totalOrders})`;

          const tr = document.createElement('tr');
          tr.innerHTML = rowData.map((val, i) => `<td data-label="${labels[i]}">${val}</td>`).join('');
          tbody.appendChild(tr);
        }
      }

      function renderAll(data, startISO, endISO) {
        renderSummaryCards(data, startISO, endISO);
        renderCombinedTable(data, startISO, endISO);
      }

      // Process raw data: convert amounts to cents, map locations
      function processData(raw) {
        const processed = {};
        for (const locId of Object.keys(raw)) {
          processed[locId] = {};
          for (const date of Object.keys(raw[locId])) {
            const day = raw[locId][date];
            processed[locId][date] = {
              credit: Math.round(day.credit * 100),
              cash: Math.round(day.cash * 100),
              orders: day.orders
            };
          }
        }
        return sortDataDescByDate(processed);
      }

      const processedData = processData(rawData);

      // Initial render
      renderAll(processedData);

      // Filter functionality
      document.getElementById('filterBtn').addEventListener('click', () => {
        const start = document.getElementById('startDate').value;
        const end = document.getElementById('endDate').value;
        renderAll(processedData, start, end);
      });

      document.getElementById('resetBtn').addEventListener('click', () => {
        document.getElementById('startDate').value = '';
        document.getElementById('endDate').value = '';
        renderAll(processedData);
      });
    </script>
  </body>
</html>