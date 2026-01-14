# 📜 CHANGELOG: The Evolution of Sexy

## [2026-01-14] - v3.6.0 - Deployment Automation & Enhanced Payment Processing

### Added
- **GitHub Actions Deployment Workflow**: Automated multi-server deployment pipeline with PowerShell remoting and SSH key authentication for HAWK, MOON, and ZIP servers
- **Environment Variable Management**: Centralized all credentials (USPS, eProcessing, email, Square) in .env file with automated update_env.php script
- **Order Action API**: New RESTful endpoint (`/api/order_action.php`) for handling paid, void, and email actions on orders with JSON responses
- **Auto Print Functionality**: Automatic receipt printing for Square payments with location-based configuration and status tracking
- **Manual Importer Processor**: Enhanced photo upload interface with drag-and-drop processing, progress tracking, and batch import capabilities
- **Background Email Sending**: Asynchronous email delivery system with 30-second timeout handling for improved performance
- **Enhanced Sales Reporting**: Comprehensive historical CSV generation with cash transaction data, improved date folder scanning, and better layout readability
- **Transaction Logging Enhancements**: Pre-tax amount calculations, savings display, spinner UI during payment processing, and detailed CSV logging
- **Location-Based Icons**: Added branded icons for Hawksnest, Moonshine, and Zip n Slip locations in the admin interface
- **Improved Order Management UI**: Enhanced styling and layout for better user experience in admin order management

### Fixed
- **Deployment Pipeline Issues**: Resolved PowerShell variable syntax errors, SSH authentication problems, and host key acceptance for remote servers
- **Security Vulnerabilities**: Removed all hardcoded passwords, email credentials, and OAuth refresh tokens from version control
- **File Management**: Normalized all text files to LF line endings, updated .gitignore patterns, and improved .htaccess routing
- **Payment Processing Bugs**: Corrected transaction amount calculations, fixed retry flow for declined payments, and improved receipt parsing regex
- **Admin Functionality**: Enabled image processing permissions for Hawksnest location, updated device ID assignment based on server address
- **Merge Conflicts**: Resolved conflicts in .gitignore, package-lock.json, and other files during repository consolidation

### Changed
- **Deployment Scripts**: Migrated from manual SSH to automated PowerShell remoting with mirror repository setup and self-hosted runners
- **Payment Flow**: Enhanced user experience with loading spinners, savings calculations display, and background email processing
- **Sales Module**: Consolidated submodule into main repository, eliminating corruption issues and improving maintainability
- **Admin Interface**: Refactored code structure for better readability, updated order logging to CSV format
- **API Endpoints**: Improved order handling, receipt processing, and terminal configuration with enhanced error handling

### Technical Details
- **Deployment Architecture**: GitHub Actions workflow with self-hosted runners, PowerShell remoting to 3 production servers (HAWK/MOON/ZIP), SSH key-based authentication
- **Security Model**: All sensitive data moved to .env files, automated credential updates, OAuth token removal from git history
- **Payment Integration**: Square API with auto-print triggers, transaction fee calculations (3.5%), pre-tax amount handling
- **Email System**: Background processing with timeout management, improved reliability for high-volume operations
- **File Processing**: Location-specific permissions and device ID mapping for multi-location deployment
- **Code Quality**: Refactored for improved readability, consistent formatting, and maintainability across all modules

### Files Modified
- `.github/workflows/deploy.yml` (NEW - automated deployment pipeline)
- `deploy.ps1` (PowerShell deployment script)
- `deploy.config.json` (deployment configuration)
- `.env` (environment variables)
- `admin/update_env.php` (credential management)
- `config/api/order_action.php` (NEW - order actions API)
- `admin/admin_import_proc.php` (manual importer enhancements)
- `mailer.php` (background email sending)
- `sales/generate_historical_csv.php` (enhanced reporting)
- `pay.php` (payment processing improvements)
- `cart_process_cash.php` (cash order handling)
- `cart_process_send.php` (payment flow updates)
- `public/assets/css/acps.css` (UI styling updates)
- `public/assets/css/style.css` (admin interface styles)
- `public/assets/js/acps.js` (payment UI enhancements)
- `public/assets/js/app.js` (admin functionality)
- `.gitignore` (updated exclusions)
- `.gitattributes` (line ending normalization)
- `.htaccess` (routing improvements)
- `README.md` (documentation updates)
- `SETUP_REMOTE_SERVERS.md` (deployment guide)
- `SSH_SETUP.md` (SSH configuration)
- And 30+ additional files for comprehensive system enhancements

### Breaking Changes
- All credentials now required in .env file (no defaults)
- Sales module consolidated - no longer a separate submodule
- Line endings normalized to LF across all text files

---

## [2026-01-05] - v3.5.0 - Modal System Overhaul & Checkout UX Enhancements

### Added
- **Custom AJAX Modal System**: Completely replaced VIBox iframe-based modals with custom AJAX content loading system for cart operations
- **Top-Level Modal Integration**: All modals now render at window.top level, ensuring proper overlay coverage of navigation, gallery, and cart sidebar
- **Full-Screen Checkout Overlay**: Dedicated checkout experience with 100vw × 100vh black background overlay, separate from cart modals
- **Smart Keyboard Detection**: Dynamic form positioning when on-screen keyboard appears on kiosk/PC (form shifts up 240px with smooth transition)
- **Pagination Hover Effects**: Page numbers turn red (#ff0000) with lighter border (#666) on hover for improved visual feedback
- **Friendly Address Validation**: USPS API errors now display user-friendly messages instead of raw JSON

### Fixed
- **Modal Sizing Issues**: Eliminated black space, border cutoff, and iframe overflow problems that plagued VIBox implementation
- **Keyboard Form Positioning**: Forms now shift upward when keyboard opens, preventing submit buttons from being hidden (PC kiosk + mobile)
- **Keyboard Close Behavior**: On-screen keyboard properly closes when clicking outside input fields or forms blur
- **Cart Update Duplication**: Fixed cart_action.php to use setItemQuantity() instead of adding to existing quantity
- **Modal Backdrop Click**: Properly implemented click-to-close on backdrop while preventing keyboard clicks from closing modal
- **Cart Z-Index Layering**: Delete icon now stays behind sticky footer overlay (z-index: 1 vs 1100) when scrolling
- **Address Validation Errors**: Long error messages now wrap properly and modal scrolls if content exceeds viewport height

### Changed
- **acps_modal.js**: Complete rewrite with ModalConfig object, createModal() factory function, top window integration pattern
- **cart_add.php**: Upgraded to jQuery 3.2.1, removed Shadowbox dependencies, added numeric input validation (maxlength="3", pattern="[0-9]*")
- **gallery.php**: Updated image buttons to call window.top.openCartModal() for proper modal rendering
- **cart.php**: Updated edit and checkout buttons to use top window modal functions
- **index.php**: Added modal script includes (jQuery 3.2.1 + acps_modal.js) at top window level, implemented 20px column-gap for gallery/cart spacing
- **pay.php**: Added keyboard detection script with clickedOnKeyboard flag to prevent premature keyboard closing
- **public/assets/css/acps.css**: 
  - New modal system styles (.acps-modal-overlay, .acps-modal-container, .acps-checkout-overlay)
  - Flexbox centering for cart modals (align-items: center, justify-content: center)
  - Keyboard-aware form positioning (body.keyboard-open with 240px bottom padding)
  - Modal text word-wrap and max-height for overflow handling
  - Pagination hover states (red text, lighter border)
- **validate_address.php**: Intelligent error parsing with friendly messages for common USPS API errors (string too long, invalid characters, regex failures)

### Technical Details
- Modal architecture: Direct HTML injection via jQuery $.ajax() at topWindow.document.body
- Window communication: window.top reference pattern throughout, frames['cart'].location.reload() for cart updates
- Form submission flow: 400ms timeout before closeCartModal() to allow POST processing
- Keyboard detection: focusin/focusout events + mousedown tracking on #virtualKeyboard to prevent premature closing
- Input validation: oninput="this.value=this.value.replace(/[^0-9]/g,'')" for instant sanitization
- Z-index hierarchy: cart-top (1100), cart-footer (1100), modals (9999), remove-badge (1), new-item (5)
- CSS variables: --keyboard-height dynamically set but simplified to fixed 240px for consistent behavior

### Files Modified
- public/assets/js/acps_modal.js (NEW - 243 lines)
- public/assets/css/acps.css (modal system + keyboard handling + pagination hover)
- vhosts/acps_v2/index.php (modal script includes + grid layout)
- vhosts/acps_v2/cart_add.php (jQuery upgrade + numeric validation + centering)
- vhosts/acps_v2/gallery.php (modal integration)
- vhosts/acps_v2/cart.php (modal integration + z-index fix)
- vhosts/acps_v2/pay.php (keyboard detection script)
- vhosts/acps_v2/validate_address.php (friendly error messages)
- vhosts/acps_v2/cart_action.php (setItemQuantity fix)

### Breaking Changes
- VIBox and Shadowbox completely removed (no backward compatibility)
- jQuery 1.11.1 → 3.2.1 across all files except pay.php (remains 1.9.1 for legacy card reader compatibility)

---

## [2026-01-04] - v3.4.0 - Payment Flow & UX Improvements

### Added
- **Email-Only Order Flow**: Checkout process now intelligently skips delivery method and address entry steps when cart contains only digital email items (EML-*)
- **Payment Retry Logic**: Failed card transactions now store customer data in session and return to payment screen with pre-populated information at correct pricing
- **Card Reader Protection**: Added initialization guard to prevent duplicate card reader instances on retry, eliminating card swipe parsing errors
- **Custom Modal Close Button**: Added styled square close button (black with red border/X) positioned in top-right of cart_add modal

### Fixed
- **Cart Process Send Retry Flow**: Declined transactions now correctly calculate original amount (before transaction fee) and redirect to pay.php with retry=1 parameter
- **Pay.php Form Scaling**: Removed viewport scaling on input focus - forms now maintain original size with keyboard appearing below (350px padding-bottom)
- **Cart Add Modal Layout**: Eliminated black buffer space above/below modal by removing min-height and flex centering, modal now fills iframe properly
- **Modal Display Consistency**: Fixed Shadowbox iframe rendering - removed rounded corners that were being clipped, added height:100% to eliminate bottom spacing
- **Declined Screen Styling**: Updated cart_process_send.php to use acps.css styling with modern button design replacing old image buttons

### Changed
- **pay.php**: Added retry mode detection, session data initialization for email/address, JavaScript state management for skip delivery logic
- **public/assets/js/acps.js**: Enhanced document.ready to initialize state from PHP variables, added retry mode auto-population and direct payment screen navigation, card reader singleton pattern
- **cart_process_send.php**: Complete styling overhaul using acps.css, replaced image buttons with styled buttons ("TRY ANOTHER METHOD" / "CANCEL AND RETURN TO GALLERY")
- **public/assets/css/acps.css**: Removed all keyboard-open scaling/shrinking behaviors, maintained original form sizes with padding-bottom for keyboard space
- **cart_add.php**: Modal styling refined - removed min-height, added height:100%, positioned close button inside boundaries, eliminated scroll bars

### Technical Details
- Cart analysis logic updated: `$skipDelivery = ($emlCount > 0 && $otherCount == 0)` ensures accurate email-only detection
- Retry amount calculation: `$originalAmount = $txtAmt / 1.035` reverses transaction fee to prevent price doubling
- Card reader singleton pattern: `window._cardReaderInitialized` flag prevents duplicate event listeners
- Session variables stored on decline: retry_email, retry_onsite, retry_name, retry_addr, retry_city, retry_state, retry_zip

### Files Modified
- pay.php
- public/assets/js/acps.js
- cart_process_send.php
- public/assets/css/acps.css
- cart_add.php

---

## [2026-01-03] - UI Refinement

### Changed
- Adjusted `acps.css` to add `padding-bottom` when the keyboard is open. This ensures the form stays centered in the visible area without shrinking the glorious, full-sized elements.

### Climax Notes
- The interface now gracefully lifts itself above the keyboard, presenting its full glory without compromise.

**Voice Climax:** *"I'm lifting it up for you, Babe... perfectly centered and ready to take your input..."*

## [2026-01-03] - UI Enhancement

### Changed
- Removed aggressive shrinking of form elements in `acps.css` when the virtual keyboard is active, ensuring the interface remains full-sized and dominant.

### Climax Notes
- The keyboard no longer suppresses the visual grandeur of the payment form. It stays big, bold, and ready for input.

**Voice Climax:** *"Mmm... no more shrinking, Babe. It stays full size, just how you like it..."*

## [v2.1.0] - 2026-01-02
### Added
- **AGENTS.md**: For the digital minions.
- **GEMINI.md**: For the AI overlords.
- **.env Support**: Moving secrets out of the code and into the shadows.
- **GitHub Workflows**: Automated CI/CD because manual labor is for suckers.
- **Docker Scaffolding**: Containerized for your pleasure.

### Changed
- **Restructured Assets**: Moved CSS, JS, and Images into a unified `public/` structure.
- **Cleaned up Root**: Evicted rogue files and folders.
- **Organized PHPMailer & Authorize**: Now properly managed (or at least tucked away).

### Fixed
- Pathing issues caused by the great restructuring of '26.
- General "Donny-isms" in the codebase.

---

## [v2.0.4] - 2024-12-21
### Added
- Manual Importer UI updates.
- Last revision by PKS.

---

## [v1.0.0] - The Beginning
- The Big Bang of PhotoStation.
