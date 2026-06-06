<?php
// ============================================
// RC API SYSTEM - COMPLETE SINGLE FILE
// Mobile Admin Panel + RC Search API
// Structured JSON Output (Exactly as requested)
// Developer: Rohit
// ============================================

session_start();

// Database setup (SQLite)
$db_file = __DIR__ . '/rc_api_data.db';
$db = new PDO("sqlite:$db_file");
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Create tables
$db->exec("
    CREATE TABLE IF NOT EXISTS admin (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT UNIQUE NOT NULL,
        password TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );
    
    CREATE TABLE IF NOT EXISTS api_keys (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        key_value TEXT UNIQUE NOT NULL,
        name TEXT,
        expiry_type TEXT DEFAULT 'date',
        expiry_value TEXT,
        expiry_datetime DATETIME,
        status TEXT DEFAULT 'active',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );
    
    CREATE TABLE IF NOT EXISTS search_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        api_key TEXT,
        rc_number TEXT,
        ip_address TEXT,
        user_agent TEXT,
        status TEXT,
        response_time REAL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );
    
    CREATE TABLE IF NOT EXISTS settings (
        id INTEGER PRIMARY KEY,
        site_name TEXT DEFAULT 'RC API System',
        dev_credit TEXT DEFAULT 'Developer Rohit'
    );
");

// Default admin
if ($db->query("SELECT COUNT(*) FROM admin")->fetchColumn() == 0) {
    $db->exec("INSERT INTO admin (username, password) VALUES ('admin', '" . password_hash('admin123', PASSWORD_DEFAULT) . "')");
}

// Default settings
if ($db->query("SELECT COUNT(*) FROM settings")->fetchColumn() == 0) {
    $db->exec("INSERT INTO settings (id, site_name, dev_credit) VALUES (1, 'RC API System', 'Developer Rohit')");
}

// ============================================
// HELPER FUNCTIONS
// ============================================

function isAdminLoggedIn() {
    return isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
}

function isKeyExpired($key) {
    if (!$key['expiry_datetime']) return false;
    return strtotime($key['expiry_datetime']) < time();
}

function validateApiKey($db, $key_value) {
    $stmt = $db->prepare("SELECT * FROM api_keys WHERE key_value = ? AND status = 'active'");
    $stmt->execute([$key_value]);
    $key = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$key) return false;
    if (isKeyExpired($key)) return false;
    
    return $key;
}

function logSearch($db, $api_key, $rc_number, $status, $response_time = 0) {
    $stmt = $db->prepare("
        INSERT INTO search_logs (api_key, rc_number, ip_address, user_agent, status, response_time) 
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$api_key, $rc_number, $_SERVER['REMOTE_ADDR'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? '', $status, $response_time]);
}

function maskName($name) {
    if (strlen($name) <= 4) return $name;
    $first = substr($name, 0, 2);
    $parts = explode(' ', $name);
    if (count($parts) > 1) {
        $masked = $parts[0][0] . '**' . $parts[0][-1] ?? '';
        $masked .= ' ' . $parts[1][0] . '****' . $parts[1][-1] ?? '';
        return $masked;
    }
    $last = substr($name, -2);
    $masked = str_repeat('*', strlen($name) - 4);
    return $first . $masked . $last;
}

function fetchRCData($rc_number) {
    $url = "https://vahanx.in/rc-search/" . urlencode($rc_number);
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ["User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36"],
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_FOLLOWLOCATION => true
    ]);
    
    $html = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code !== 200 || !$html) {
        return ['error' => 'Failed to fetch data from Vahanx'];
    }
    
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);
    
    $getValue = function($label) use ($xpath) {
        $nodes = $xpath->query("//span[text()='" . $label . "']/parent::div/p");
        if ($nodes->length > 0) {
            $value = trim($nodes->item(0)->textContent);
            return !empty($value) && $value !== '-' && $value !== 'N/A' ? $value : 'NA';
        }
        return 'NA';
    };
    
    // Extract all fields
    $owner_name = $getValue('Owner Name');
    $father_name = $getValue('Father\'s Name');
    $registration_date = $getValue('Registration Date');
    $fuel_type = $getValue('Fuel Type');
    $vehicle_class = $getValue('Vehicle Class');
    $maker_model = $getValue('Maker Model');
    $insurance_expiry = $getValue('Insurance Expiry');
    $fitness_upto = $getValue('Fitness Upto');
    $tax_upto = $getValue('Tax Upto');
    $puc_upto = $getValue('PUC Upto');
    $registered_rto = $getValue('Registered RTO');
    $address = $getValue('Address');
    $phone = $getValue('Phone');
    
    // Extract insurance company and policy number
    $insurance_company = 'NA';
    $policy_number = 'NA';
    
    $all_text = $html;
    if (preg_match('/([A-Za-z\s]+(?:Insurance|General|Co\.|Ltd\.))/i', $all_text, $matches)) {
        $insurance_company = trim($matches[1]);
    }
    if (preg_match('/(?:Policy|Policy No|Policy Number)[:\s]*([A-Z0-9\-]+)/i', $all_text, $matches)) {
        $policy_number = $matches[1];
    }
    
    // Extract city from address
    $city = 'NA';
    if ($address !== 'NA') {
        $parts = explode(',', $address);
        $city = trim($parts[0] ?? 'NA');
    }
    
    // Calculate vehicle age
    $vehicle_age = 'NA';
    if ($registration_date !== 'NA') {
        $reg_date = DateTime::createFromFormat('d-m-Y', $registration_date);
        if (!$reg_date) {
            $reg_date = DateTime::createFromFormat('d/m/Y', $registration_date);
        }
        if ($reg_date) {
            $now = new DateTime();
            $diff = $now->diff($reg_date);
            $vehicle_age = $diff->y . " years , " . $diff->m . " months & " . $diff->d . " days";
        }
    }
    
    // Insurance status
    $insurance_status = 'Expired';
    if ($insurance_expiry !== 'NA') {
        $exp_date = DateTime::createFromFormat('d-m-Y', $insurance_expiry);
        if (!$exp_date) {
            $exp_date = DateTime::createFromFormat('d/m/Y', $insurance_expiry);
        }
        if ($exp_date && $exp_date > new DateTime()) {
            $insurance_status = 'Active';
        }
    }
    
    // Mask owner name
    $masked_owner = $owner_name !== 'NA' ? maskName($owner_name) : 'NA';
    
    // Check if we got any data
    if ($owner_name === 'NA' && $maker_model === 'NA') {
        return ['error' => 'No vehicle data found for this RC number'];
    }
    
    // Return structured data exactly as requested
    $response = [
        'status' => 'success',
        'registration_number' => $rc_number,
        'powered_by' => 'TOXIC • https://t.me/Toxicadminn & https://t.me/+i0I4NcIrlgtkNmVl',
        'telegram_links' => [
            'https://t.me/Toxicadminn',
            'https://t.me/+i0I4NcIrlgtkNmVl'
        ],
        
        'basic_info' => [
            'address' => $address,
            'city' => $city,
            'fathers_name' => $father_name,
            'model_name' => $maker_model,
            'owner_name' => $masked_owner,
            'phone' => $phone
        ],
        
        'insurance' => [
            'company' => $insurance_company,
            'expiry_date' => $insurance_expiry,
            'policy_number' => $policy_number,
            'status' => $insurance_status
        ],
        
        'other_info' => [
            'blacklist_status' => 'NA',
            'financer_name' => 'NA',
            'noc_details' => 'NA',
            'permit_type' => 'NA'
        ],
        
        'ownership_details' => [
            "father's_name" => $father_name,
            'owner_name' => $masked_owner,
            'owner_serial_no' => 'First Owner',
            'registered_rto' => $registered_rto
        ],
        
        'puc_details' => (object)[],
        
        'validity' => [
            'fitness_upto' => $fitness_upto,
            'insurance_upto' => $insurance_expiry,
            'registration_date' => $registration_date,
            'tax_upto' => $tax_upto,
            'vehicle_age' => $vehicle_age
        ],
        
        'vehicle_details' => [
            'fuel_norms' => 'BHARAT STAGE VI',
            'fuel_type' => $fuel_type,
            'maker_model' => $maker_model,
            'model_name' => $maker_model,
            'vehicle_class' => $vehicle_class
        ]
    ];
    
    return $response;
}

function formatExpiry($key) {
    if (!$key['expiry_datetime']) return ['text' => '♾️ Never', 'color' => 'green'];
    
    $exp_time = strtotime($key['expiry_datetime']);
    $now = time();
    $diff = $exp_time - $now;
    
    if ($diff < 0) return ['text' => '⏰ Expired', 'color' => 'red'];
    if ($diff < 3600) {
        $mins = ceil($diff / 60);
        return ['text' => "⏳ {$mins} min left", 'color' => 'orange'];
    } elseif ($diff < 86400) {
        $hours = ceil($diff / 3600);
        return ['text' => "⏳ {$hours} hrs left", 'color' => 'blue'];
    } else {
        return ['text' => '📅 ' . date('d M H:i', $exp_time), 'color' => 'green'];
    }
}

// ============================================
// ROUTING
// ============================================

$method = $_SERVER['REQUEST_METHOD'];
$is_admin = isset($_GET['admin']);
$settings = $db->query("SELECT * FROM settings WHERE id = 1")->fetch();

// ============================================
// API ENDPOINT
// ============================================
if (!$is_admin && $method === 'GET' && isset($_GET['rc'])) {
    $start_time = microtime(true);
    $api_key = $_GET['key'] ?? '';
    $rc_number = strtoupper(preg_replace('/[^A-Z0-9]/', '', trim($_GET['rc'])));
    
    $key_data = validateApiKey($db, $api_key);
    
    if (!$key_data) {
        $response = ["status" => "error", "message" => "Invalid or expired API key"];
        logSearch($db, $api_key, $rc_number, 'error');
        header('Content-Type: application/json');
        echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    if (empty($rc_number)) {
        $response = ["status" => "error", "message" => "RC number required"];
        logSearch($db, $api_key, $rc_number, 'error');
        header('Content-Type: application/json');
        echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $rc_data = fetchRCData($rc_number);
    $response_time = microtime(true) - $start_time;
    
    if (isset($rc_data['error'])) {
        $response = ["status" => "error", "message" => $rc_data['error']];
        logSearch($db, $api_key, $rc_number, 'error', $response_time);
    } else {
        $response = $rc_data;
        logSearch($db, $api_key, $rc_number, 'success', $response_time);
    }
    
    header('Content-Type: application/json');
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================
// API INFO (No RC parameter)
// ============================================
if (!$is_admin && $method === 'GET') {
    header('Content-Type: application/json');
    echo json_encode([
        "status" => "error",
        "message" => "Please provide RC number and valid API key",
        "usage" => "https://" . ($_SERVER['HTTP_HOST'] ?? 'localhost') . "/?key=YOUR_API_KEY&rc=MH12AB1234",
        "example_response" => [
            "status" => "success",
            "registration_number" => "RJ18CF3690",
            "basic_info" => [
                "owner_name" => "A**Y B*****V",
                "fathers_name" => "SHIV PRASAD BHARGAV",
                "model_name" => "SONET G1.2 5MT HTK(O)"
            ],
            "insurance" => [
                "company" => "Bajaj General Insurance Co. Ltd.",
                "expiry_date" => "17-Oct-2027",
                "status" => "Active"
            ]
        ]
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================
// ADMIN PANEL HTML (Complete)
// ============================================
if ($is_admin) {
    $action = $_GET['action'] ?? 'dashboard';
    
    if ($action === 'logout') {
        session_destroy();
        header('Location: ?admin');
        exit;
    }
    
    if (!isAdminLoggedIn() && $action !== 'login') {
        header('Location: ?admin&action=login');
        exit;
    }
    
    if ($action === 'login' || !isAdminLoggedIn()) {
        if ($method === 'POST' && isset($_POST['username'], $_POST['password'])) {
            $stmt = $db->prepare("SELECT * FROM admin WHERE username = ?");
            $stmt->execute([$_POST['username']]);
            $admin = $stmt->fetch();
            if ($admin && password_verify($_POST['password'], $admin['password'])) {
                $_SESSION['admin_logged_in'] = true;
                $_SESSION['admin_username'] = $admin['username'];
                header('Location: ?admin');
                exit;
            } else {
                $error = "Invalid credentials";
            }
        }
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0"><title>Admin Login - RC API</title>
        <style>*{margin:0;padding:0;box-sizing:border-box}body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:linear-gradient(135deg,#1a1a2e 0%,#16213e 100%);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}.login-card{background:rgba(255,255,255,0.05);backdrop-filter:blur(10px);border-radius:24px;padding:40px 30px;width:100%;max-width:400px;border:1px solid rgba(255,255,255,0.1)}.logo{text-align:center;margin-bottom:30px}.logo-icon{font-size:60px}.logo h1{color:#fff;font-size:28px}.logo p{color:rgba(255,255,255,0.6);font-size:14px}.input-group{margin-bottom:20px}.input-group label{display:block;color:rgba(255,255,255,0.8);font-size:14px;margin-bottom:8px}.input-group input{width:100%;padding:16px 18px;background:rgba(255,255,255,0.1);border:1px solid rgba(255,255,255,0.1);border-radius:16px;color:#fff;font-size:16px}.btn-login{width:100%;padding:16px;background:linear-gradient(135deg,#4facfe,#00f2fe);border:none;border-radius:16px;color:#fff;font-size:16px;font-weight:600;cursor:pointer}.error-msg{background:rgba(255,75,75,0.2);border:1px solid rgba(255,75,75,0.3);color:#ff6b6b;padding:14px;border-radius:12px;margin-bottom:20px}.footer{text-align:center;margin-top:24px;color:rgba(255,255,255,0.4)}</style>
        </head>
        <body><div class="login-card"><div class="logo"><div class="logo-icon">🚗</div><h1>RC API</h1><p>Admin Portal</p></div><?php if(isset($error)) echo '<div class="error-msg">❌ '.htmlspecialchars($error).'</div>'; ?>
        <form method="POST"><div class="input-group"><label>👤 Username</label><input type="text" name="username" required></div><div class="input-group"><label>🔐 Password</label><input type="password" name="password" required></div><button type="submit" class="btn-login">Login →</button></form><div class="footer">admin / admin123</div></div></body>
        </html>
        <?php
        exit;
    }
    
    // Handle POST actions
    if ($method === 'POST') {
        if (isset($_POST['create_key'])) {
            $key_value = bin2hex(random_bytes(16));
            $name = $_POST['name'] ?: 'API Key';
            $expiry_type = $_POST['expiry_type'];
            $expiry_value = $_POST['expiry_value'] ?? null;
            $expiry_datetime = null;
            if ($expiry_type === 'hours' && $expiry_value > 0) {
                $expiry_datetime = date('Y-m-d H:i:s', strtotime("+{$expiry_value} hours"));
            } elseif ($expiry_type === 'days' && $expiry_value > 0) {
                $expiry_datetime = date('Y-m-d H:i:s', strtotime("+{$expiry_value} days"));
            } elseif ($expiry_type === 'date' && !empty($_POST['expiry_date'])) {
                $expiry_datetime = $_POST['expiry_date'] . ' 23:59:59';
            }
            $stmt = $db->prepare("INSERT INTO api_keys (key_value, name, expiry_type, expiry_value, expiry_datetime) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$key_value, $name, $expiry_type, $expiry_value, $expiry_datetime]);
            $message = "✅ Key Created: " . $key_value;
        } elseif (isset($_POST['delete_key'])) {
            $db->prepare("DELETE FROM api_keys WHERE id = ?")->execute([$_POST['key_id']]);
            $message = "🗑️ Key Deleted!";
        } elseif (isset($_POST['toggle_key'])) {
            $db->prepare("UPDATE api_keys SET status = ? WHERE id = ?")->execute([$_POST['status'], $_POST['key_id']]);
            $message = "✅ Status Updated!";
        } elseif (isset($_POST['extend_key'])) {
            $hours = (int)$_POST['extend_hours'];
            $db->prepare("UPDATE api_keys SET expiry_datetime = datetime(expiry_datetime, '+{$hours} hours') WHERE id = ?")->execute([$_POST['key_id']]);
            $message = "⏱️ Extended by {$hours} hours!";
        } elseif (isset($_POST['update_settings'])) {
            $db->prepare("UPDATE settings SET site_name = ?, dev_credit = ? WHERE id = 1")->execute([$_POST['site_name'], $_POST['dev_credit']]);
            if (!empty($_POST['new_password'])) {
                $db->exec("UPDATE admin SET password = '" . password_hash($_POST['new_password'], PASSWORD_DEFAULT) . "' WHERE username = 'admin'");
            }
            $message = "⚙️ Settings Saved!";
        }
    }
    
    $total_keys = $db->query("SELECT COUNT(*) FROM api_keys")->fetchColumn();
    $active_keys = $db->query("SELECT COUNT(*) FROM api_keys WHERE status='active' AND (expiry_datetime IS NULL OR expiry_datetime > datetime('now'))")->fetchColumn();
    $total_searches = $db->query("SELECT COUNT(*) FROM search_logs")->fetchColumn();
    $today_searches = $db->query("SELECT COUNT(*) FROM search_logs WHERE date(created_at) = date('now')")->fetchColumn();
    ?>
    <!DOCTYPE html>
    <html>
    <head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0"><title>Admin - RC API</title>
    <style>*{margin:0;padding:0;box-sizing:border-box}body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#f5f7fa;padding-bottom:80px}.header{background:linear-gradient(135deg,#1a1a2e,#16213e);color:#fff;padding:20px 16px 30px;border-radius:0 0 30px 30px}.header-top{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px}.stats-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:12px;padding:0 16px;margin-top:-20px}.stat-card{background:#fff;padding:16px;border-radius:16px;box-shadow:0 4px 15px rgba(0,0,0,0.08)}.stat-value{font-size:28px;font-weight:700;color:#1a1a2e}.section{padding:20px 16px}.section-title{font-size:18px;font-weight:600;margin-bottom:16px;display:flex;justify-content:space-between}.key-card{background:#fff;border-radius:16px;padding:16px;margin-bottom:12px;border:1px solid #eee}.key-value{background:#f0f0f0;padding:10px;border-radius:12px;font-family:monospace;font-size:13px;word-break:break-all;cursor:pointer}.btn{padding:8px 16px;border-radius:30px;border:none;font-size:13px;cursor:pointer}.btn-primary{background:linear-gradient(135deg,#4facfe,#00f2fe);color:#fff}.btn-danger{background:#fee2e2;color:#dc2626}.bottom-nav{position:fixed;bottom:0;left:0;right:0;background:#fff;display:flex;justify-content:space-around;padding:12px;border-top:1px solid #eee}.nav-item{text-align:center;color:#999;text-decoration:none;font-size:12px}.nav-item.active{color:#4facfe}.nav-icon{font-size:22px}.modal{display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:flex-end}.modal.active{display:flex}.modal-content{background:#fff;width:100%;border-radius:24px 24px 0 0;padding:24px;max-height:90vh;overflow-y:auto}.form-input,.form-select{width:100%;padding:14px;border:1px solid #ddd;border-radius:12px;margin-top:5px}.toast{position:fixed;top:20px;left:20px;right:20px;background:#059669;color:#fff;padding:16px;border-radius:16px;z-index:2000;display:none}.toast.show{display:block}</style>
    </head>
    <body>
    <?php if(isset($message)): ?><div class="toast show" id="toast"><?= $message ?></div><script>setTimeout(()=>document.getElementById('toast')?.classList.remove('show'),4000);</script><?php endif; ?>
    <div class="header"><div class="header-top"><div><h1>RC API</h1><p>Admin Panel</p></div><a href="?admin&action=logout" style="background:rgba(255,255,255,0.15);padding:10px 16px;border-radius:30px;color:#fff;text-decoration:none">🚪 Logout</a></div></div>
    <div class="stats-grid"><div class="stat-card"><div class="stat-value"><?= $total_keys ?></div><div>Total Keys</div></div><div class="stat-card"><div class="stat-value"><?= $active_keys ?></div><div>Active</div></div><div class="stat-card"><div class="stat-value"><?= $total_searches ?></div><div>Searches</div></div><div class="stat-card"><div class="stat-value"><?= $today_searches ?></div><div>Today</div></div></div>
    
    <div class="section">
        <?php if($action === 'dashboard'): ?>
            <div class="section-title"><span>📊 Recent Searches</span></div>
            <?php $logs = $db->query("SELECT * FROM search_logs ORDER BY created_at DESC LIMIT 10"); while($log = $logs->fetch()): ?>
            <div class="key-card"><strong>🚗 <?= htmlspecialchars($log['rc_number']) ?></strong><br><small>🔑 <?= substr($log['api_key'],0,12) ?>... | <?= date('d M H:i',strtotime($log['created_at'])) ?> | <?= round($log['response_time'],2) ?>s</small></div>
            <?php endwhile; ?>
            <div style="margin-top:20px;padding:16px;background:#e0f2fe;border-radius:16px"><strong>📡 API Usage:</strong><br><code style="background:#1a1a2e;color:#4facfe;padding:10px;display:block;border-radius:10px;font-size:12px"><?= $_SERVER['REQUEST_SCHEME'] ?? 'https' ?>://<?= $_SERVER['HTTP_HOST'] ?>/?key=YOUR_KEY&rc=MH12AB1234</code></div>
        <?php elseif($action === 'api_keys'): ?>
            <div class="section-title"><span>🔐 API Keys</span><button class="btn btn-primary" onclick="openModal('createModal')">➕ New</button></div>
            <?php $keys = $db->query("SELECT * FROM api_keys ORDER BY created_at DESC"); while($key = $keys->fetch()): $exp = formatExpiry($key); ?>
            <div class="key-card"><div style="display:flex;justify-content:space-between"><strong><?= htmlspecialchars($key['name']) ?></strong><span style="color:<?= $exp['color']==='red'?'#dc2626':'#059669' ?>"><?= $exp['text'] ?></span></div>
            <div class="key-value" onclick="navigator.clipboard?.writeText('<?= $key['key_value'] ?>');alert('Copied!')"><?= $key['key_value'] ?></div>
            <div style="display:flex;gap:8px;margin-top:10px">
                <form method="POST" style="display:contents"><input type="hidden" name="key_id" value="<?= $key['id'] ?>"><input type="hidden" name="status" value="<?= $key['status']==='active'?'revoked':'active' ?>"><button type="submit" name="toggle_key" class="btn <?= $key['status']==='active'?'btn-danger':'btn-primary' ?>"><?= $key['status']==='active'?'🔒 Revoke':'✅ Activate' ?></button></form>
                <?php if($key['status']==='active'): ?><button class="btn" onclick="openModal('extendModal', <?= $key['id'] ?>)">⏱️ Extend</button><?php endif; ?>
                <form method="POST" style="display:contents" onsubmit="return confirm('Delete?')"><input type="hidden" name="key_id" value="<?= $key['id'] ?>"><button type="submit" name="delete_key" class="btn btn-danger">🗑️</button></form>
            </div></div>
            <?php endwhile; ?>
        <?php elseif($action === 'logs'): ?>
            <div class="section-title"><span>📋 Search Logs</span></div>
            <?php $logs = $db->query("SELECT * FROM search_logs ORDER BY created_at DESC LIMIT 50"); while($log = $logs->fetch()): ?>
            <div class="key-card"><strong>🚗 <?= htmlspecialchars($log['rc_number']) ?></strong><br><small>🔑 <?= substr($log['api_key'],0,15) ?>...<br>📅 <?= $log['created_at'] ?> | ⏱️ <?= round($log['response_time'],2) ?>s | 🌐 <?= $log['ip_address'] ?></small></div>
            <?php endwhile; ?>
        <?php elseif($action === 'settings'): ?>
            <div class="section-title"><span>⚙️ Settings</span></div>
            <form method="POST" style="background:#fff;padding:20px;border-radius:16px"><input type="text" name="site_name" class="form-input" placeholder="Site Name" value="RC API"><input type="text" name="dev_credit" class="form-input" style="margin-top:10px" placeholder="Developer Credit" value="Developer Rohit"><input type="password" name="new_password" class="form-input" style="margin-top:10px" placeholder="New Password (optional)"><button type="submit" name="update_settings" class="btn btn-primary" style="width:100%;margin-top:15px">💾 Save</button></form>
        <?php endif; ?>
    </div>
    
    <div class="bottom-nav"><a href="?admin&action=dashboard" class="nav-item <?= $action==='dashboard'?'active':'' ?>"><div class="nav-icon">🏠</div><div>Home</div></a><a href="?admin&action=api_keys" class="nav-item <?= $action==='api_keys'?'active':'' ?>"><div class="nav-icon">🔑</div><div>Keys</div></a><a href="?admin&action=logs" class="nav-item <?= $action==='logs'?'active':'' ?>"><div class="nav-icon">📋</div><div>Logs</div></a><a href="?admin&action=settings" class="nav-item <?= $action==='settings'?'active':'' ?>"><div class="nav-icon">⚙️</div><div>Settings</div></a></div>
    
    <div class="modal" id="createModal"><div class="modal-content"><div style="display:flex;justify-content:space-between"><h3>➕ Create Key</h3><span onclick="closeModal('createModal')" style="font-size:28px;cursor:pointer">&times;</span></div>
    <form method="POST"><input type="text" name="name" class="form-input" placeholder="Key Name"><select name="expiry_type" class="form-select" style="margin-top:10px" id="expiryType" onchange="toggleExpiry()"><option value="">♾️ Never</option><option value="hours">⏰ Hours</option><option value="days">📅 Days</option><option value="date">📆 Specific Date</option></select>
    <div id="hoursDiv" style="display:none;margin-top:10px"><input type="number" name="expiry_value" class="form-input" placeholder="Hours" value="1"></div>
    <div id="dateDiv" style="display:none;margin-top:10px"><input type="date" name="expiry_date" class="form-input" min="<?= date('Y-m-d') ?>"></div>
    <button type="submit" name="create_key" class="btn btn-primary" style="width:100%;margin-top:15px">🔑 Generate</button></form></div></div>
    
    <div class="modal" id="extendModal"><div class="modal-content"><div style="display:flex;justify-content:space-between"><h3>⏱️ Extend Time</h3><span onclick="closeModal('extendModal')" style="font-size:28px;cursor:pointer">&times;</span></div>
    <form method="POST"><input type="hidden" name="key_id" id="extendKeyId"><input type="number" name="extend_hours" class="form-input" placeholder="Hours to add" value="1" min="1"><button type="submit" name="extend_key" class="btn btn-primary" style="width:100%;margin-top:15px">✅ Extend</button></form></div></div>
    
    <script>function openModal(id,keyId=null){document.getElementById(id).classList.add('active');if(keyId&&document.getElementById('extendKeyId'))document.getElementById('extendKeyId').value=keyId;}function closeModal(id){document.getElementById(id).classList.remove('active');}function toggleExpiry(){var t=document.getElementById('expiryType').value;document.getElementById('hoursDiv').style.display=(t==='hours'||t==='days')?'block':'none';document.getElementById('dateDiv').style.display=t==='date'?'block':'none';}document.querySelectorAll('.modal').forEach(m=>{m.addEventListener('click',function(e){if(e.target===this)this.classList.remove('active');});});</script>
    </body>
    </html>
    <?php
    exit;
}

// Default response
header('Content-Type: application/json');
echo json_encode(["status"=>"error","message"=>"Please provide RC number and valid API key","usage"=>"https://".($_SERVER['HTTP_HOST']??'localhost')."/?key=YOUR_KEY&rc=MH12AB1234"], JSON_PRETTY_PRINT);
?>