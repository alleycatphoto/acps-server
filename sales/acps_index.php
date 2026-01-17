<?php
// Load .env if not already loaded
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
    if (class_exists('Dotenv\\Dotenv')) {
        $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
        $dotenv->load();
    }
}

// Get location from env
$location = getenv('LOCATION_NAME');
if ($location && trim($location) !== '') {
    $location = trim($location);
} else if (getenv('LOCATION_SLUG') && trim(getenv('LOCATION_SLUG')) !== '') {
    $location = trim(getenv('LOCATION_SLUG'));
} else {
    $location = 'UNKNOWN';
}

$csvFile = __DIR__ . '/acps_transactions.csv';
$data = [];
if (file_exists($csvFile)) {
    $handle = fopen($csvFile, 'r');
    $header = fgetcsv($handle);
    while (($row = fgetcsv($handle)) !== false) {
        // Format: Location,Date,Cash,Credit,Cash_Count,Credit_Count
        $rowLoc = trim($row[0]);
        $rowDate = trim($row[1]);
        $cash = (float)str_replace([',', '$'], '', $row[2]);
        $credit = (float)str_replace([',', '$'], '', $row[3]);
        $cash_count = (int)$row[4];
        $credit_count = (int)$row[5];
        if (!isset($data[$rowLoc])) $data[$rowLoc] = [];
        $data[$rowLoc][$rowDate] = [
            'cash' => $cash,
            'credit' => $credit,
            'cash_count' => $cash_count,
            'credit_count' => $credit_count
        ];
    }
    fclose($handle);
}

// Only show for this location
$locData = $data[$location] ?? [];

?><!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>ACPS Sales Report - <?php echo htmlspecialchars($location); ?></title>
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
        padding: 15px;
        font-family: 'Inter', sans-serif;
        background:
          linear-gradient(180deg, #050505 0%, #070707 100%);
        color: var(--text);
        min-height: 100vh;
      }
      .wrap { max-width: 800px; margin: 0 auto; }
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
      .summary-table {
        width: 100%;
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        margin-bottom: 30px;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.5);
      }
      th, td {
        padding: 12px 10px;
        border-bottom: 1px solid #000000;
        text-align: center;
        font-size: 14px;
      }
      th { background: #070707; color: var(--muted); font-weight: 900; }
      td { color: #fff; }
      tr:last-child td { border-bottom: none; }
      .col-cash { color: #10b981; font-weight: bold; }
      .col-credit { color: #3b82f6; font-weight: bold; }
      .col-total { color: #fff; font-weight: 900; }
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
        margin-bottom: 30px;
      }
      .stat-val { font-size: 28px; font-weight: 900; color: #fff; margin: 5px 0; }
      .stat-label { color: var(--muted); font-size: 14px; }
    </style>
  </head>
  <body>
    <div class="wrap">
      <div class="header">
        <div class="brand">
          <img src="<?php echo getenv('LOCATION_LOGO') ?: 'https://alleycatphoto.net/assets/images/logo.png'; ?>" alt="Logo" />
          <div class="brand-text">
            <div class="title">ACPS Sales Report</div>
            <div class="subtitle">Location: <?php echo htmlspecialchars($location); ?></div>
          </div>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-val">
          <?php echo count($locData); ?>
        </div>
        <div class="stat-label">Days Reported</div>
      </div>
      <table class="summary-table">
        <thead>
          <tr>
            <th>Date</th>
            <th class="col-cash">Cash</th>
            <th class="col-credit">Credit</th>
            <th>Cash Orders</th>
            <th>Credit Orders</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($locData as $date => $row): ?>
            <tr>
              <td><?php echo htmlspecialchars($date); ?></td>
              <td class="col-cash">$<?php echo number_format($row['cash'], 2); ?></td>
              <td class="col-credit">$<?php echo number_format($row['credit'], 2); ?></td>
              <td><?php echo $row['cash_count']; ?></td>
              <td><?php echo $row['credit_count']; ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </body>
</html>
