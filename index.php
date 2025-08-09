<?php
/*
 *
 * Live-Chat Application
 *
 * Repository: https://github.com/druvx13/Live-Chat
 * Description: A simple live chat website.
 *
 * Author: [Druvx13]
 * Email: [druvx13@gmail.com]
 * Date: [2025-08-09]
 * Version: 1.0
 *
 * License: The Unlicense
 */

/* ============================
   DATABASE CONFIGURATION
   ============================

   Edit the following constants to match your MySQL server credentials.
   Ensure the account has privileges to create the database (if you want auto-created DB).
*/

define('DB_HOST', '127.0.0.1');    // hostname or IP, e.g. 'localhost' or '127.0.0.1'
define('DB_PORT', '3306');         // MySQL port
define('DB_USER', 'root');         // MySQL username
define('DB_PASS', 'password');     // MySQL password
define('DB_NAME', 'live_chat_monolith'); // Desired database name (auto-created if permitted)

/* ============================
   APPLICATION CONSTANTS
   ============================ */

define('TABLE_MESSAGES', 'chat_messages'); // messages table name
define('MAX_MESSAGE_LENGTH', 4000);        // max allowed characters in a message
define('POLL_LIMIT', 200);                 // how many messages to fetch per poll (server limit)

/* ============================
   BOOTSTRAP: CONNECT & INITIALIZE
   ============================ */

/*
  Implementation notes:
  - Use PDO for reliability and prepared statements.
  - Connect first without specifying DB to create DB if it doesn't exist.
  - Then reconnect to the newly created DB and ensure table schema exists.
*/

try {
    // 1) Connect to MySQL server without specifying a database (allows DB creation)
    $dsnNoDB = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', DB_HOST, DB_PORT);
    $pdo = new PDO($dsnNoDB, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // 2) Create database if not exists
    $safeDb = str_replace('`', '``', DB_NAME);
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$safeDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    // 3) Reconnect with the database selected
    $dsnWithDB = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);
    $pdo = new PDO($dsnWithDB, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // 4) Create messages table if not exists (id, username, message, created_at)
    $createTableSQL = "
        CREATE TABLE IF NOT EXISTS `" . TABLE_MESSAGES . "` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `username` VARCHAR(150) NOT NULL,
            `message` TEXT NOT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($createTableSQL);

} catch (PDOException $e) {
    // If an API call, return JSON error. If not, render a minimal error page.
    if (isset($_GET['action']) || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'DB init/connection failed: ' . $e->getMessage()]);
        exit;
    } else {
        http_response_code(500);
        echo "<h2>Database connection/initialization failed</h2>";
        echo "<pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
        exit;
    }
}

/* ============================
   SIMPLE API ENDPOINTS (action parameter)
   ============================

   Available actions (via ?action=...):
   - send (POST)     : Insert a message {username, message}
   - fetch (GET)     : Fetch messages since last_id (optional)
   - recent (GET)    : Fetch last N messages (optional count)
   - stats (GET)     : Returns {total, last_id}
*/

if (isset($_GET['action'])) {
    $action = $_GET['action'];
    header('Content-Type: application/json; charset=utf-8');

    // ---------- SEND ----------
    if ($action === 'send' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        // Get and sanitize inputs
        $username = isset($_POST['username']) ? mb_substr(trim($_POST['username']), 0, 150) : 'Anonymous';
        $message  = isset($_POST['message']) ? trim($_POST['message']) : '';

        if ($message === '') {
            echo json_encode(['ok' => false, 'error' => 'Message cannot be empty.']);
            exit;
        }
        if (mb_strlen($message) > MAX_MESSAGE_LENGTH) {
            echo json_encode(['ok' => false, 'error' => 'Message exceeds maximum allowed length.']);
            exit;
        }

        try {
            $stmt = $pdo->prepare("INSERT INTO `" . TABLE_MESSAGES . "` (`username`, `message`) VALUES (:u, :m)");
            $stmt->execute([':u' => $username, ':m' => $message]);
            $lastId = (int)$pdo->lastInsertId();
            echo json_encode(['ok' => true, 'id' => $lastId]);
            exit;
        } catch (PDOException $e) {
            echo json_encode(['ok' => false, 'error' => 'Insert failed: ' . $e->getMessage()]);
            exit;
        }

    // ---------- FETCH ----------
    } elseif ($action === 'fetch' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        // Accept optional last_id to fetch only new messages
        $lastId = isset($_GET['last_id']) ? (int)$_GET['last_id'] : 0;
        $limit = isset($_GET['limit']) ? min((int)$_GET['limit'], POLL_LIMIT) : POLL_LIMIT;

        try {
            if ($lastId > 0) {
                // Incremental fetch: get rows with id > lastId in asc order
                $stmt = $pdo->prepare("SELECT `id`, `username`, `message`, `created_at` FROM `" . TABLE_MESSAGES . "` WHERE `id` > :lastId ORDER BY `id` ASC LIMIT :lim");
                $stmt->bindValue(':lastId', $lastId, PDO::PARAM_INT);
                $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
                $stmt->execute();
                $rows = $stmt->fetchAll();
            } else {
                // No lastId: return the most recent messages (desc then reverse to asc)
                $stmt = $pdo->prepare("SELECT `id`, `username`, `message`, `created_at` FROM `" . TABLE_MESSAGES . "` ORDER BY `id` DESC LIMIT :lim");
                $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
                $stmt->execute();
                $rows = $stmt->fetchAll();
                $rows = array_reverse($rows);
            }
            echo json_encode(['ok' => true, 'messages' => $rows]);
            exit;
        } catch (PDOException $e) {
            echo json_encode(['ok' => false, 'error' => 'Fetch failed: ' . $e->getMessage()]);
            exit;
        }

    // ---------- RECENT ----------
    } elseif ($action === 'recent' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $count = isset($_GET['count']) ? min(max((int)$_GET['count'], 1), POLL_LIMIT) : 50;
        try {
            $stmt = $pdo->prepare("SELECT `id`, `username`, `message`, `created_at` FROM `" . TABLE_MESSAGES . "` ORDER BY `id` DESC LIMIT :cnt");
            $stmt->bindValue(':cnt', $count, PDO::PARAM_INT);
            $stmt->execute();
            $rows = array_reverse($stmt->fetchAll());
            echo json_encode(['ok' => true, 'messages' => $rows]);
            exit;
        } catch (PDOException $e) {
            echo json_encode(['ok' => false, 'error' => 'Recent fetch failed: ' . $e->getMessage()]);
            exit;
        }

    // ---------- STATS ----------
    } elseif ($action === 'stats' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        try {
            $stmt = $pdo->query("SELECT COUNT(*) AS total, MAX(`id`) AS last_id FROM `" . TABLE_MESSAGES . "`");
            $row = $stmt->fetch();
            $total = isset($row['total']) ? (int)$row['total'] : 0;
            $lastId = isset($row['last_id']) ? (int)$row['last_id'] : 0;
            echo json_encode(['ok' => true, 'total' => $total, 'last_id' => $lastId]);
            exit;
        } catch (PDOException $e) {
            echo json_encode(['ok' => false, 'error' => 'Stats failed: ' . $e->getMessage()]);
            exit;
        }

    } else {
        echo json_encode(['ok' => false, 'error' => 'Invalid action or method.']);
        exit;
    }
}

/* ============================
   If no action parameter, render the full HTML UI
   The UI is large and verbose by design (monolithic).
   ============================ */
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Live Chat — Monolithic Single File</title>

  <!-- Tailwind via CDN: convenient for prototyping and demo -->
  <script src="https://cdn.tailwindcss.com"></script>

  <!-- =========================
       LARGE INLINE CSS (repetitive by request)
       This section intentionally contains repeated styles and fallback CSS
       to keep the output large and monolithic just like the original.
       ========================= -->
  <style>
    /* Basic page layout */
    html, body {
      height: 100%;
      margin: 0;
      padding: 0;
      font-family: Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue",
                   Arial, "Noto Sans", "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol";
      -webkit-font-smoothing: antialiased;
      -moz-osx-font-smoothing: grayscale;
      background: #f1f5f9; /* slate-100 */
      color: #0f172a; /* slate-900 */
    }

    /* Container sizing */
    .monolith-wrapper {
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 1rem;
    }

    /* Chat card box */
    .chat-card {
      width: 100%;
      max-width: 1100px;
      border-radius: 12px;
      background: #ffffff; /* white */
      box-shadow: 0 10px 30px rgba(2,6,23,0.08);
      border: 1px solid rgba(2,6,23,0.04);
      overflow: hidden;
    }

    /* Header */
    .chat-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 18px 22px;
      border-bottom: 1px solid rgba(2,6,23,0.04);
      background: linear-gradient(90deg, rgba(99,102,241,0.03), rgba(124,58,237,0.02));
    }

    .brand {
      display: flex;
      align-items: center;
      gap: 12px;
    }
    .brand-logo {
      width: 44px;
      height: 44px;
      border-radius: 8px;
      background: linear-gradient(135deg,#4f46e5,#7c3aed);
      display: flex;
      align-items: center;
      justify-content: center;
      color: #fff;
      font-weight: 700;
      font-size: 18px;
    }
    .brand-title {
      font-size: 18px;
      font-weight: 600;
      color: #0f172a;
    }
    .brand-sub {
      font-size: 12px;
      color: #475569;
    }

    /* Body layout: three-column-ish on desktop, single column on mobile */
    .chat-body {
      display: grid;
      grid-template-columns: 1fr;
      gap: 18px;
      padding: 20px;
    }
    @media(min-width: 900px) {
      .chat-body {
        grid-template-columns: 2fr 1fr;
      }
    }

    /* Chat area box */
    .chat-area {
      background: linear-gradient(180deg, #fbfdff, #ffffff);
      border: 1px solid rgba(2,6,23,0.04);
      border-radius: 10px;
      padding: 14px;
      display: flex;
      flex-direction: column;
      gap: 10px;
      min-height: 420px;
      max-height: calc(100vh - 300px);
      overflow: hidden;
    }

    /* Scrollable messages region */
    .messages-scroll {
      overflow-y: auto;
      -webkit-overflow-scrolling: touch;
      padding-right: 8px; /* space for scrollbar */
      display: flex;
      flex-direction: column;
      gap: 12px;
    }
    /* Repeated scrollbar CSS for desktop browsers */
    .messages-scroll::-webkit-scrollbar { width: 10px; }
    .messages-scroll::-webkit-scrollbar-thumb { background: rgba(2,6,23,0.06); border-radius: 10px; }

    /* Message bubble styles (left-aligned user messages) */
    .msg-row { display: flex; gap: 12px; align-items: flex-start; }
    .msg-avatar {
      width: 44px;
      height: 44px;
      border-radius: 999px;
      background: #6366f1;
      color: white;
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 700;
      font-size: 18px;
      flex-shrink: 0;
    }
    .msg-body { display: flex; flex-direction: column; gap: 6px; max-width: 88%; }
    .msg-header { font-size: 12px; color: #475569; }
    .msg-bubble {
      background: #ffffff;
      border: 1px solid rgba(2,6,23,0.04);
      padding: 10px 12px;
      border-radius: 8px;
      box-shadow: 0 2px 6px rgba(2,6,23,0.04);
      white-space: pre-wrap;
      word-break: break-word;
      font-size: 14px;
      color: #0f172a;
    }

    /* Input area */
    .compose-area {
      display: flex;
      gap: 10px;
      align-items: center;
      margin-top: 8px;
    }
    .compose-input {
      flex: 1;
      border-radius: 10px;
      border: 1px solid rgba(2,6,23,0.06);
      padding: 12px 14px;
      font-size: 14px;
      min-height: 46px;
      resize: none;
    }
    .compose-btn {
      background: linear-gradient(180deg,#6366f1,#7c3aed);
      color: #fff;
      padding: 10px 14px;
      border-radius: 10px;
      border: none;
      cursor: pointer;
      font-weight: 600;
    }
    .compose-btn:disabled { opacity: 0.5; cursor: not-allowed; }

    /* Right column (controls / name / stats) */
    .controls {
      background: #fbfdff;
      border: 1px solid rgba(2,6,23,0.04);
      border-radius: 10px;
      padding: 14px;
      display: flex;
      flex-direction: column;
      gap: 12px;
      min-height: 200px;
    }
    .controls .label { font-size: 13px; color: #475569; font-weight: 600; }
    .controls input[type="text"] {
      padding: 10px 12px;
      border-radius: 8px;
      border: 1px solid rgba(2,6,23,0.06);
      font-size: 14px;
      width: 100%;
    }
    .controls .small { font-size: 12px; color: #6b7280; }

    /* Footer */
    .chat-footer {
      border-top: 1px solid rgba(2,6,23,0.04);
      padding: 12px 18px;
      font-size: 12px;
      color: #6b7280;
      background: #fff;
    }

    /* Modal: Pick a name (responsive) */
    /* This modal was previously non-responsive; we make it responsive using max-widths and flex stacks */
    .modal-backdrop {
      position: fixed;
      inset: 0;
      background: rgba(0,0,0,0.45);
      display: flex;
      align-items: center;
      justify-content: center;
      z-index: 9999;
      padding: 16px;
    }
    .modal-card {
      width: 100%;
      max-width: 480px; /* cap width on large desktops */
      background: white;
      border-radius: 12px;
      padding: 18px;
      box-shadow: 0 20px 50px rgba(2,6,23,0.12);
      border: 1px solid rgba(2,6,23,0.04);
      display: flex;
      flex-direction: column;
      gap: 12px;
    }
    .modal-row { display: flex; gap: 10px; align-items: center; }
    @media(min-width:640px) {
      .modal-row { flex-direction: row; }
    }
    @media(max-width:639px) {
      .modal-row { flex-direction: column; }
    }
    .modal-input {
      flex: 1;
      padding: 10px 12px;
      border-radius: 8px;
      border: 1px solid rgba(2,6,23,0.06);
      width: 100%;
    }
    .modal-set-btn {
      padding: 10px 14px;
      background: linear-gradient(180deg,#10b981,#059669);
      color: #fff;
      border-radius: 8px;
      border: none;
      cursor: pointer;
      font-weight: 600;
      white-space: nowrap;
    }
    .modal-set-btn:focus { outline: 2px solid rgba(16,185,129,0.25); }

    /* Tiny responsive tweaks repeated for verbosity */
    @media(min-width:1200px) {
      .chat-card { max-width: 1250px; }
    }

    /* End of inline repeated CSS block */

  </style>
</head>
<body>
  <!-- Main wrapper -->
  <div class="monolith-wrapper">
    <div class="chat-card" role="application" aria-label="Live Chat Application">

      <!-- Header -->
      <div class="chat-header">
        <div class="brand" role="banner">
          <div class="brand-logo" aria-hidden="true">LC</div>
          <div>
            <div class="brand-title">Live Chat — Monolithic Demo</div>
            <div class="brand-sub">Single-file PHP + Tailwind + Inline CSS</div>
          </div>
        </div>

        <div style="display:flex;align-items:center;gap:12px;">
          <div style="font-size:12px;color:#475569;">Status: <span id="connection-status" style="color:#059669;">Connecting...</span></div>
          <div style="font-size:12px;color:#6b7280;">Messages: <span id="stat-total">—</span></div>
        </div>
      </div>

      <!-- Body -->
      <div class="chat-body">
        <!-- LEFT: Chat area (big) -->
        <div class="chat-area" aria-live="polite" aria-atomic="false">
          <!-- Scroll region containing messages -->
          <div id="messagesScroll" class="messages-scroll" role="log" aria-relevant="additions">
            <!-- Messages will be appended here by JS -->
          </div>

          <!-- Compose area (input + buttons) -->
          <div>
            <form id="sendForm" class="compose-area" onsubmit="return false;" aria-label="Send message form">
              <textarea id="messageInput" class="compose-input" placeholder="Type your message... (Shift+Enter for newline, Enter to send)" maxlength="<?php echo MAX_MESSAGE_LENGTH; ?>"></textarea>
              <button id="sendBtn" class="compose-btn" type="button">Send</button>
            </form>
            <div style="display:flex;justify-content:space-between;margin-top:8px;">
              <div style="font-size:12px;color:#6b7280;">Characters: <span id="charCount">0</span> / <?php echo MAX_MESSAGE_LENGTH; ?></div>
              <div style="font-size:12px;color:#6b7280;"><button id="forceFetch" style="background:transparent;border:0;color:#6366f1;cursor:pointer;">Refresh</button></div>
            </div>
          </div>
        </div>

        <!-- RIGHT: Controls / name / stats -->
        <aside class="controls" aria-label="Controls">
          <div>
            <div class="label">Your display name</div>
            <div style="height:8px;"></div>
            <!-- The input here intentionally mirrors the modal input; user can change both -->
            <div style="display:flex;gap:8px;align-items:center;">
              <input id="usernameInput" type="text" class="modal-input" placeholder="Pick a name (e.g. Alex)" />
              <button id="saveName" class="modal-set-btn" title="Save display name">Set</button>
            </div>
            <div style="height:8px;"></div>
            <div id="currentName" class="small" style="color:#334155;display:none;">Saved name: <strong id="displayNameText"></strong></div>
          </div>

          <div>
            <div class="label">Session Details</div>
            <div style="height:6px;"></div>
            <div class="small">Total messages stored: <strong id="statTotalRight">—</strong></div>
            <div class="small" style="margin-top:6px;">Last sync: <span id="statLastSync">—</span></div>
            <div style="height:8px;"></div>
            <div style="display:flex;gap:8px;">
              <button id="clearLocal" style="padding:8px 10px;border-radius:8px;border:1px solid rgba(2,6,23,0.06);background:#fff;cursor:pointer;">Clear name</button>
              <button id="openNameModal" style="padding:8px 10px;border-radius:8px;background:#eef2ff;border:1px solid rgba(99,102,241,0.15);cursor:pointer;">Pick name</button>
            </div>
          </div>

          <div>
            <div class="label">Notes</div>
            <div class="small" style="margin-top:6px;">Polling is used for simplicity. For scaling, use WebSockets or SSE.</div>
          </div>

        </aside>
      </div>

      <!-- Footer -->
      <div class="chat-footer">
        This is a single-file application. Tailwind is loaded via CDN. Inline CSS is intentionally verbose.
      </div>
    </div>
  </div>

  <!-- Modal: Pick a name (responsive, fixed, large repeated CSS above) -->
  <div id="nameModal" class="modal-backdrop" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
    <div class="modal-card" role="document">
      <div>
        <div id="modalTitle" style="font-weight:700;font-size:16px;color:#0f172a;">Pick a display name</div>
        <div style="font-size:13px;color:#475569;margin-top:6px;">This name will appear next to the messages you send. You can change it anytime.</div>
      </div>
      <div class="modal-row" style="margin-top:8px;">
        <input id="modalUsernameInput" class="modal-input" type="text" placeholder="Enter a name, e.g. 'Priya' or 'DevOps'">
        <button id="modalSetBtn" class="modal-set-btn">Set</button>
      </div>
      <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:6px;">
        <button id="modalCancelBtn" style="padding:8px 10px;background:#fff;border:1px solid rgba(2,6,23,0.06);border-radius:8px;cursor:pointer;">Cancel</button>
      </div>
    </div>
  </div>

  <!-- =========================
       Client-side JS: Verbose, documented, includes duplicate prevention
       ========================= -->
  <script>
    /* ----------------------------------------------------------------------------
       Client-side logic overview
       ----------------------------------------------------------------------------
       - Polls the server for new messages periodically using ?action=fetch
       - Sends messages using ?action=send (POST)
       - Maintains a Set of displayed message IDs to avoid duplicates when fetching
       - Supports forced refresh (clears DOM and reloads recent messages)
       - Modal for picking name is responsive, using max-width, stacked on mobile
       - All HTML insertion is escaped to avoid XSS attacks
       ---------------------------------------------------------------------------- */

    // Configurable values (tune for your environment)
    const POLL_INTERVAL = 1500;       // normal polling interval in ms
    const IDLE_POLL_INTERVAL = 5000;  // polling interval when tab not focused
    const FETCH_LIMIT = 200;          // how many messages the client requests per fetch
    const MAX_DOM_MESSAGES = 1200;    // keep DOM size bounded

    // State variables
    let lastId = 0;                   // last seen message ID (numeric)
    let displayedIds = new Set();     // set of message IDs rendered in DOM (prevents duplicates)
    let pollTimer = null;             // poll interval handle
    let username = null;              // current user name (from localStorage or input)
    let isWindowFocused = true;       // used to throttle polling when not focused

    // DOM references (monolithic structure uses many elements)
    const messagesScroll = document.getElementById('messagesScroll');
    const sendForm = document.getElementById('sendForm');
    const messageInput = document.getElementById('messageInput');
    const sendBtn = document.getElementById('sendBtn');
    const charCount = document.getElementById('charCount');
    const statTotal = document.getElementById('stat-total');
    const statTotalRight = document.getElementById('statTotalRight');
    const statLastSync = document.getElementById('statLastSync');
    const connectionStatus = document.getElementById('connection-status');
    const usernameInput = document.getElementById('usernameInput');
    const saveNameBtn = document.getElementById('saveName');
    const currentName = document.getElementById('currentName');
    const displayNameText = document.getElementById('displayNameText');
    const clearLocal = document.getElementById('clearLocal');
    const openNameModal = document.getElementById('openNameModal');

    // Modal references
    const nameModal = document.getElementById('nameModal');
    const modalUsernameInput = document.getElementById('modalUsernameInput');
    const modalSetBtn = document.getElementById('modalSetBtn');
    const modalCancelBtn = document.getElementById('modalCancelBtn');

    // Utility: escape HTML before inserting into DOM (prevent XSS)
    function escapeHtml(str) {
      if (str === null || str === undefined) return '';
      return String(str)
          .replace(/&/g, '&amp;')
          .replace(/</g, '&lt;')
          .replace(/>/g, '&gt;')
          .replace(/"/g, '&quot;')
          .replace(/'/g, '&#39;')
          .replace(/\//g, '&#x2F;');
    }

    // Utility: update status text
    function setStatus(text, ok = true) {
      connectionStatus.textContent = text;
      connectionStatus.style.color = ok ? '#059669' : 'crimson';
    }

    // Render a single message node (returns element)
    function renderMessageNode(msg) {
      // msg object: {id, username, message, created_at}
      const row = document.createElement('div');
      row.className = 'msg-row';
      row.dataset.mid = msg.id;

      // Avatar
      const avatar = document.createElement('div');
      avatar.className = 'msg-avatar';
      const initial = (msg.username && msg.username.trim().length) ? msg.username.trim().charAt(0).toUpperCase() : 'A';
      avatar.textContent = initial;

      // Body
      const body = document.createElement('div');
      body.className = 'msg-body';
      const header = document.createElement('div');
      header.className = 'msg-header';
      header.innerHTML = '<span style="font-weight:600;color:#0f172a;">' + escapeHtml(msg.username) + '</span>'
                       + ' <span style="margin-left:6px;font-size:12px;color:#94a3b8;">' + escapeHtml(new Date(msg.created_at).toLocaleString()) + '</span>';
      const bubble = document.createElement('div');
      bubble.className = 'msg-bubble';
      bubble.innerHTML = escapeHtml(msg.message);

      body.appendChild(header);
      body.appendChild(bubble);

      row.appendChild(avatar);
      row.appendChild(body);

      return row;
    }

    // Append messages array to DOM while avoiding duplicates.
    // options = { replace: false } -> if replace true, clears DOM and ID set first
    function appendMessages(rows, options = { replace: false }) {
      if (!rows || rows.length === 0) return;

      if (options.replace) {
        // Clear DOM and ID set to avoid duplicates and rebuild from scratch
        messagesScroll.innerHTML = '';
        displayedIds.clear();
        lastId = 0; // will be updated below
      }

      const frag = document.createDocumentFragment();
      for (const m of rows) {
        const idNum = parseInt(m.id, 10) || 0;
        if (displayedIds.has(idNum)) {
          // skip duplicates already in DOM
          continue;
        }
        const node = renderMessageNode(m);
        frag.appendChild(node);
        displayedIds.add(idNum);
        if (idNum > lastId) lastId = idNum;
      }

      // Append new items
      messagesScroll.appendChild(frag);

      // Limit DOM size by removing the earliest nodes if too many
      while (messagesScroll.children.length > MAX_DOM_MESSAGES) {
        const first = messagesScroll.firstElementChild;
        if (first && first.dataset && first.dataset.mid) {
          displayedIds.delete(parseInt(first.dataset.mid, 10));
        }
        messagesScroll.removeChild(first);
      }

      // Auto-scroll to bottom (only if we're near bottom or replace was true)
      // Check scroll position: if within 120px of bottom, auto-scroll
      const nearBottom = messagesScroll.scrollHeight - messagesScroll.scrollTop - messagesScroll.clientHeight < 120;
      if (nearBottom || options.replace) {
        messagesScroll.scrollTop = messagesScroll.scrollHeight;
      }
    }

    // Fetch messages from server.
    // If force === true, it will request last_id=0 and use replace=true to refresh fully.
    async function fetchMessages(force = false) {
      const params = new URLSearchParams();
      params.set('action', 'fetch');
      if (!force && lastId > 0) {
        params.set('last_id', lastId);
      } else {
        // request most recent
        params.set('last_id', 0);
      }
      params.set('limit', FETCH_LIMIT);

      try {
        const url = window.location.pathname + '?' + params.toString();
        const res = await fetch(url, { method: 'GET', headers: { 'Accept': 'application/json' } });
        const data = await res.json();
        if (!data || data.ok === false) {
          setStatus('Server error', false);
          return;
        }
        // append messages; if force true, replace DOM to avoid duplicates
        appendMessages(data.messages || [], { replace: Boolean(force) });
        setStatus('Connected', true);
        // update stats display
        updateStats();
      } catch (err) {
        console.error('Fetch messages error', err);
        setStatus('Offline', false);
      }
    }

    // Send a message to server
    async function sendMessage(text) {
      const u = username || (usernameInput.value && usernameInput.value.trim()) || (modalUsernameInput.value && modalUsernameInput.value.trim()) || 'Anonymous';
      if (!text || !text.trim()) return;
      const body = new URLSearchParams();
      body.set('username', u);
      body.set('message', text.trim());

      sendBtn.disabled = true;
      try {
        const res = await fetch(window.location.pathname + '?action=send', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
          body: body.toString()
        });
        const result = await res.json();
        if (result.ok) {
          // Clear input, update local char count
          messageInput.value = '';
          charCount.textContent = '0';
          // On successful send, force a refresh to ensure the message shows (replace mode)
          await fetchMessages(true);
        } else {
          alert('Send failed: ' + (result.error || 'unknown'));
        }
      } catch (e) {
        console.error('Send error', e);
        alert('Network error while sending message.');
      } finally {
        sendBtn.disabled = false;
      }
    }

    // Update stats region by calling ?action=stats
    async function updateStats() {
      try {
        const res = await fetch(window.location.pathname + '?action=stats', { method: 'GET' });
        const data = await res.json();
        if (data && data.ok) {
          statTotal.textContent = data.total;
          statTotalRight.textContent = data.total;
          statLastSync.textContent = (new Date()).toLocaleTimeString();
        }
      } catch (e) {
        // ignore silently
      }
    }

    // Polling control
    function startPolling() {
      stopPolling();
      pollTimer = setInterval(() => {
        fetchMessages(false);
      }, isWindowFocused ? POLL_INTERVAL : IDLE_POLL_INTERVAL);
      // Initial full load
      fetchMessages(true);
    }
    function stopPolling() {
      if (pollTimer) {
        clearInterval(pollTimer);
        pollTimer = null;
      }
    }

    // Local storage name handling
    function initNameFromStorage() {
      const v = localStorage.getItem('livechat_name');
      if (v && v.trim()) {
        username = v.trim();
        usernameInput.value = username;
        displayNameText.textContent = username;
        currentName.style.display = 'block';
      } else {
        // If no name saved, show modal to pick name (only on first load)
        // Do not force modal if user has explicitly closed previously (we don't track that here)
        // We'll show a small hint; user can click 'Pick name' or set it on right panel
      }
    }
    function saveNameToStorage(name) {
      const val = (name || '').trim();
      if (!val) return;
      username = val.slice(0,150);
      localStorage.setItem('livechat_name', username);
      usernameInput.value = username;
      displayNameText.textContent = username;
      currentName.style.display = 'block';
      setStatus('Name saved');
    }
    function clearNameStorage() {
      localStorage.removeItem('livechat_name');
      username = null;
      usernameInput.value = '';
      displayNameText.textContent = '';
      currentName.style.display = 'none';
      setStatus('Name cleared');
    }

    // Modal control
    function openModal() {
      nameModal.style.display = 'flex';
      modalUsernameInput.focus();
    }
    function closeModal() {
      nameModal.style.display = 'none';
      modalUsernameInput.value = '';
    }

    // Event wiring
    document.addEventListener('DOMContentLoaded', function () {
      // initialize name from storage
      initNameFromStorage();

      // start polling loop
      startPolling();

      // send button
      sendBtn.addEventListener('click', function (e) {
        const text = messageInput.value || '';
        if (text.trim().length === 0) return;
        sendMessage(text);
      });

      // support Enter to send (without shift), Shift+Enter for newline
      messageInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) {
          e.preventDefault();
          sendBtn.click();
        }
      });

      // character counter
      messageInput.addEventListener('input', function () {
        charCount.textContent = messageInput.value.length.toString();
      });

      // save name button (right controls)
      saveNameBtn.addEventListener('click', function () {
        const v = usernameInput.value || '';
        if (!v.trim()) return;
        saveNameToStorage(v);
      });

      // clear local name
      clearLocal.addEventListener('click', function () {
        clearNameStorage();
      });

      // open modal
      openNameModal.addEventListener('click', function () {
        openModal();
      });

      // modal set button
      modalSetBtn.addEventListener('click', function () {
        const v = modalUsernameInput.value || '';
        if (!v.trim()) return;
        saveNameToStorage(v);
        closeModal();
      });

      // modal cancel
      modalCancelBtn.addEventListener('click', function () {
        closeModal();
      });

      // force fetch
      document.getElementById('forceFetch').addEventListener('click', function () {
        fetchMessages(true);
      });

      // visibility change to reduce polling when tab is hidden
      document.addEventListener('visibilitychange', function () {
        isWindowFocused = !document.hidden;
        startPolling(); // restart polling with appropriate interval
      });

      // window unload cleanup (best-effort)
      window.addEventListener('beforeunload', function () {
        stopPolling();
      });

      // keyboard shortcut: press "n" to open name modal (just a convenience)
      document.addEventListener('keydown', function (e) {
        if (!e.altKey && !e.ctrlKey && !e.metaKey && e.key === 'n') {
          // avoid if focus is in an input/textarea
          const active = document.activeElement && (document.activeElement.tagName === 'INPUT' || document.activeElement.tagName === 'TEXTAREA');
          if (!active) openModal();
        }
      });

      // initial stat update
      updateStats();
    });

    // ensure we do an initial full fetch even if DOMContentLoaded missed it
    // (double-call harmless because appendMessages handles duplicates)
    setTimeout(() => { fetchMessages(true); }, 600);

    // Periodic stat refresh
    setInterval(updateStats, 7000);

  </script>

</body>
</html>
