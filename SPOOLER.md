ACPS Spooler & Delivery System (Technical Specification)

1. Overview

The ACPS Spooler is a decoupled queue management system. It separates Order Completion (logic/payment) from Hardware Execution (printing) and Cloud Delivery (GMailer/Drive).

Producer: config/api/order_action.php

Consumer (Printer): config/api/spooler.php (triggered by app.js heartbeat)

Consumer (Mailer): gmailer.php (autonomous OAuth2 token-based CLI)

2. Directory Schema

/YYYY/MM/DD/
├── raw/                # Original captures
├── emails/             # ARCHIVE: Successfully sent delivery folders
└── spool/              # ACTIVE QUEUE
    ├── printer/        # JPGs awaiting physical print tick
    └── mailer/         # Folder-per-order: [OrderID]/ (info.txt + JPGs)


3. Authentication & Security

The system uses Google OAuth2 Desktop Credentials.

Credentials: config/google/credentials.json (Secret)

Token: config/google/token.json (Generated via auth_setup.php)

Persistence: gmailer.php automatically refreshes the access_token using the refresh_token without user intervention.

4. UI Heartbeat (app.js)

The Master Console performs a "Tick" every 1500ms:

spooler.php?action=status -> Updates UI badges for Printer and Mailer tabs.

spooler.php?action=tick_printer -> Checks if C:/orders is empty. If yes, moves exactly one file from spool/printer/.

5. Agentic Instructions

Maintenance:

"Ensure all file movements use rename() to guarantee atomicity. No script should write directly to C:/orders except spooler.php's tick_printer action."

Delivery:

"The gmailer.php script is self-healing. It manages its own token refresh. If a delivery fails, check spool/mailer/[OrderID]/error.log. Successful deliveries move the entire order folder to the daily emails/ archive."