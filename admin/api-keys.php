<?php
/**
 * Admin Interface for Managing API Keys
 *
 * Allows administrators to:
 * - Create new API keys for external websites
 * - View and manage existing API keys
 * - Monitor API usage and rate limits
 * - Revoke/regenerate API keys
 */

// Include admin initialization (PHP-only, no HTML output)
require_once 'admin-init.php';

// Check admin authentication - only admin role can access API keys
if ($_SESSION['admin_role'] !== 'admin') {
    header('Location: dashboard.php');
    exit;
}

// Handle form submissions
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'create_key':
                $clientName = trim($_POST['client_name']);
                $clientWebsite = trim($_POST['client_website']);
                $clientEmail = trim($_POST['client_email']);
                $rateLimit = (int)$_POST['rate_limit_per_hour'];
                $permissions = isset($_POST['permissions']) ? $_POST['permissions'] : [];
                
                // Generate API key
                $rawApiKey = bin2hex(random_bytes(32));
                $hashedApiKey = password_hash($rawApiKey, PASSWORD_DEFAULT);
                
                // Prepare permissions JSON
                $permissionsJson = json_encode($permissions);
                
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO api_keys (
                            api_key, client_name, client_website, client_email,
                            permissions, rate_limit_per_hour, is_active
                        ) VALUES (?, ?, ?, ?, ?, ?, 1)
                    ");
                    
                    $stmt->execute([
                        $hashedApiKey,
                        $clientName,
                        $clientWebsite,
                        $clientEmail,
                        $permissionsJson,
                        $rateLimit
                    ]);
                    
                    $apiKeyId = $pdo->lastInsertId();
                    
                    $message = "API key created successfully!<br><br>
                               <strong>Client:</strong> $clientName<br>
                               <strong>API Key:</strong> <code>$rawApiKey</code><br><br>
                               <strong>Important:</strong> Copy this API key now. It will not be shown again.";
                    $messageType = 'success';
                    
                } catch (PDOException $e) {
                    $message = "Error creating API key: " . $e->getMessage();
                    $messageType = 'error';
                }
                break;
                
            case 'toggle_status':
                $keyId = (int)$_POST['key_id'];
                $isActive = (int)$_POST['is_active'];
                
                try {
                    $stmt = $pdo->prepare("
                        UPDATE api_keys 
                        SET is_active = ? 
                        WHERE id = ?
                    ");
                    $stmt->execute([$isActive, $keyId]);
                    
                    $message = "API key status updated successfully";
                    $messageType = 'success';
                } catch (PDOException $e) {
                    $message = "Error updating API key: " . $e->getMessage();
                    $messageType = 'error';
                }
                break;
                
            case 'regenerate_key':
                $keyId = (int)$_POST['key_id'];
                
                // Generate new API key
                $rawApiKey = bin2hex(random_bytes(32));
                $hashedApiKey = password_hash($rawApiKey, PASSWORD_DEFAULT);
                
                try {
                    $stmt = $pdo->prepare("
                        UPDATE api_keys 
                        SET api_key = ?, last_used_at = NULL, usage_count = 0 
                        WHERE id = ?
                    ");
                    $stmt->execute([$hashedApiKey, $keyId]);
                    
                    // Get client name for message
                    $stmt = $pdo->prepare("SELECT client_name FROM api_keys WHERE id = ?");
                    $stmt->execute([$keyId]);
                    $client = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    $message = "API key regenerated for <strong>{$client['client_name']}</strong><br><br>
                               <strong>New API Key:</strong> <code>$rawApiKey</code><br><br>
                               <strong>Important:</strong> Copy this new API key now. It will not be shown again.";
                    $messageType = 'success';
                } catch (PDOException $e) {
                    $message = "Error regenerating API key: " . $e->getMessage();
                    $messageType = 'error';
                }
                break;
                
            case 'delete_key':
                $keyId = (int)$_POST['key_id'];
                
                try {
                    $stmt = $pdo->prepare("DELETE FROM api_keys WHERE id = ?");
                    $stmt->execute([$keyId]);
                    
                    $message = "API key deleted successfully";
                    $messageType = 'success';
                } catch (PDOException $e) {
                    $message = "Error deleting API key: " . $e->getMessage();
                    $messageType = 'error';
                }
                break;
        }
    }
}

// Get all API keys
$apiKeys = [];
try {
    $stmt = $pdo->query("
        SELECT 
            ak.*,
            (SELECT COUNT(*) FROM api_usage_logs WHERE api_key_id = ak.id) as total_calls,
            (SELECT COUNT(*) FROM api_usage_logs WHERE api_key_id = ak.id AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)) as calls_last_hour
        FROM api_keys ak
        ORDER BY created_at DESC
    ");
    $apiKeys = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $message = "Error loading API keys: " . $e->getMessage();
    $messageType = 'error';
}

// Get API usage statistics
$usageStats = [];
try {
    $stmt = $pdo->query("
        SELECT 
            DATE(created_at) as date,
            COUNT(*) as total_calls,
            COUNT(DISTINCT api_key_id) as unique_clients,
            AVG(response_time) as avg_response_time
        FROM api_usage_logs 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        GROUP BY DATE(created_at)
        ORDER BY date DESC
    ");
    $usageStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Table might not exist yet
}

// Available permissions
$availablePermissions = [
    'rooms.read' => 'Read room information',
    'availability.check' => 'Check room availability',
    'bookings.create' => 'Create new bookings',
    'bookings.read' => 'Read booking details',
    'bookings.update' => 'Update bookings',
    'bookings.delete' => 'Delete bookings'
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>API Keys Management - Admin Panel</title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500;600;700&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/theme-dynamic.php">
    <link rel="stylesheet" href="css/admin-styles.css">
    <link rel="stylesheet" href="css/admin-components.css">
</head>
<body>

    <?php require_once 'includes/admin-header.php'; ?>
    
    <div class="admin-container">
    <div class="admin-header">
        <h1><i class="fas fa-key"></i> API Keys Management</h1>
        <p>Manage API keys for external websites to access the booking system</p>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?php echo $messageType === 'error' ? 'danger' : 'success'; ?>">
            <?php echo $message; ?>
        </div>
    <?php endif; ?>

    <div class="row">
        <div class="col-md-8">
            <!-- API Keys List -->
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-list"></i> Active API Keys</h3>
                </div>
                <div class="card-body">
                    <?php if (empty($apiKeys)): ?>
                        <div class="alert alert-info">
                            <p>No API keys found. Create your first API key to get started.</p>
                            <p>Make sure to run the SQL script first: <code>Database/add-api-keys-table.sql</code></p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Client</th>
                                        <th>Website</th>
                                        <th>Usage</th>
                                        <th>Rate Limit</th>
                                        <th>Status</th>
                                        <th>Last Used</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($apiKeys as $key): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($key['client_name']); ?></strong><br>
                                                <small class="text-muted"><?php echo htmlspecialchars($key['client_email']); ?></small>
                                            </td>
                                            <td>
                                                <?php if ($key['client_website']): ?>
                                                    <a href="<?php echo htmlspecialchars($key['client_website']); ?>" target="_blank">
                                                        <?php echo htmlspecialchars($key['client_website']); ?>
                                                    </a>
                                                <?php else: ?>
                                                    <span class="text-muted">Not specified</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <small>Total: <?php echo $key['total_calls']; ?> calls</small><br>
                                                <small>Last hour: <?php echo $key['calls_last_hour']; ?> calls</small>
                                            </td>
                                            <td><?php echo $key['rate_limit_per_hour']; ?>/hour</td>
                                            <td>
                                                <span class="badge badge-<?php echo $key['is_active'] ? 'success' : 'danger'; ?>">
                                                    <?php echo $key['is_active'] ? 'Active' : 'Inactive'; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($key['last_used_at']): ?>
                                                    <?php echo date('M j, Y H:i', strtotime($key['last_used_at'])); ?>
                                                <?php else: ?>
                                                    <span class="text-muted">Never</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <button type="button" class="btn btn-info" data-toggle="modal" data-target="#viewKeyModal<?php echo $key['id']; ?>">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                    <form method="POST" style="display: inline;">
                                                        <input type="hidden" name="action" value="toggle_status">
                                                        <input type="hidden" name="key_id" value="<?php echo $key['id']; ?>">
                                                        <input type="hidden" name="is_active" value="<?php echo $key['is_active'] ? 0 : 1; ?>">
                                                        <button type="submit" class="btn btn-<?php echo $key['is_active'] ? 'warning' : 'success'; ?>">
                                                            <i class="fas fa-power-off"></i>
                                                        </button>
                                                    </form>
                                                    <form method="POST" style="display: inline;">
                                                        <input type="hidden" name="action" value="regenerate_key">
                                                        <input type="hidden" name="key_id" value="<?php echo $key['id']; ?>">
                                                        <button type="submit" class="btn btn-secondary" onclick="return confirm('Are you sure? This will invalidate the current API key.')">
                                                            <i class="fas fa-redo"></i>
                                                        </button>
                                                    </form>
                                                    <form method="POST" style="display: inline;">
                                                        <input type="hidden" name="action" value="delete_key">
                                                        <input type="hidden" name="key_id" value="<?php echo $key['id']; ?>">
                                                        <button type="submit" class="btn btn-danger" onclick="return confirm('Are you sure you want to delete this API key?')">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                        
                                        <!-- View Key Modal -->
                                        <div class="modal fade" id="viewKeyModal<?php echo $key['id']; ?>" tabindex="-1">
                                            <div class="modal-dialog">
                                                <div class="modal-content">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title">API Key Details</h5>
                                                        <button type="button" class="close" data-dismiss="modal">
                                                            <span>&times;</span>
                                                        </button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <h6>Client Information</h6>
                                                        <p><strong>Name:</strong> <?php echo htmlspecialchars($key['client_name']); ?></p>
                                                        <p><strong>Email:</strong> <?php echo htmlspecialchars($key['client_email']); ?></p>
                                                        <p><strong>Website:</strong> <?php echo htmlspecialchars($key['client_website']); ?></p>
                                                        
                                                        <h6 class="mt-3">Permissions</h6>
                                                        <?php 
                                                        $permissions = json_decode($key['permissions'], true) ?? [];
                                                        if ($permissions): ?>
                                                            <ul>
                                                                <?php foreach ($permissions as $perm): ?>
                                                                    <li><?php echo $availablePermissions[$perm] ?? $perm; ?></li>
                                                                <?php endforeach; ?>
                                                            </ul>
                                                        <?php else: ?>
                                                            <p class="text-muted">No permissions assigned</p>
                                                        <?php endif; ?>
                                                        
                                                        <h6 class="mt-3">Usage Statistics</h6>
                                                        <p><strong>Total Calls:</strong> <?php echo $key['total_calls']; ?></p>
                                                        <p><strong>Calls Last Hour:</strong> <?php echo $key['calls_last_hour']; ?></p>
                                                        <p><strong>Rate Limit:</strong> <?php echo $key['rate_limit_per_hour']; ?> calls/hour</p>
                                                        <p><strong>Created:</strong> <?php echo date('M j, Y H:i', strtotime($key['created_at'])); ?></p>
                                                        <p><strong>Last Used:</strong> <?php echo $key['last_used_at'] ? date('M j, Y H:i', strtotime($key['last_used_at'])) : 'Never'; ?></p>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- API Usage Statistics -->
            <?php if (!empty($usageStats)): ?>
                <div class="card mt-4">
                    <div class="card-header">
                        <h3><i class="fas fa-chart-line"></i> API Usage Statistics (Last 30 Days)</h3>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Total Calls</th>
                                        <th>Unique Clients</th>
                                        <th>Avg Response Time</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($usageStats as $stat): ?>
                                        <tr>
                                            <td><?php echo date('M j, Y', strtotime($stat['date'])); ?></td>
                                            <td><?php echo $stat['total_calls']; ?></td>
                                            <td><?php echo $stat['unique_clients']; ?></td>
                                            <td><?php echo number_format($stat['avg_response_time'], 4); ?>s</td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        
        <div class="col-md-4">
            <!-- Create New API Key -->
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-plus-circle"></i> Create New API Key</h3>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="create_key">
                        
                        <div class="form-group">
                            <label for="client_name">Client Name *</label>
                            <input type="text" class="form-control" id="client_name" name="client_name" required>
                            <small class="form-text text-muted">Name of the website/client using the API</small>
                        </div>
                        
                        <div class="form-group">
                            <label for="client_website">Website URL</label>
                            <input type="url" class="form-control" id="client_website" name="client_website" placeholder="https://example.com">
                        </div>
                        
                        <div class="form-group">
                            <label for="client_email">Contact Email *</label>
                            <input type="email" class="form-control" id="client_email" name="client_email" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="rate_limit_per_hour">Rate Limit (calls per hour) *</label>
                            <input type="number" class="form-control" id="rate_limit_per_hour" name="rate_limit_per_hour" value="100" min="1" max="10000" required>
                            <small class="form-text text-muted">Maximum number of API calls allowed per hour</small>
                        </div>
                        
                                        <div class="form-group">
                                            <label>Permissions *</label>
                                            <div class="permissions-list">
                                                <?php foreach ($availablePermissions as $perm => $description): ?>
                                                    <div class="form-check">
                                                        <input class="form-check-input" type="checkbox" name="permissions[]" value="<?php echo $perm; ?>" id="perm_<?php echo $perm; ?>" checked>
                                                        <label class="form-check-label" for="perm_<?php echo $perm; ?>">
                                                            <strong><?php echo $perm; ?></strong><br>
                                                            <small class="text-muted"><?php echo $description; ?></small>
                                                        </label>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                        
                                        <button type="submit" class="btn btn-primary btn-block">
                                            <i class="fas fa-key"></i> Generate API Key
                                        </button>
                                        
                                        <div class="alert alert-info mt-3">
                                            <small>
                                                <i class="fas fa-info-circle"></i> 
                                                <strong>Important:</strong> The API key will be shown only once after creation. 
                                                Make sure to copy it and store it securely.
                                            </small>
                                        </div>
                                    </form>
                                </div>
                            </div>
                            
                            <!-- API Documentation -->
                            <div class="card mt-4">
                                <div class="card-header">
                                    <h3><i class="fas fa-book"></i> API Documentation</h3>
                                </div>
                                <div class="card-body">
                                    <h5>Quick Links</h5>
                                    <ul>
                                        <li><a href="/api/" target="_blank">API Base URL</a></li>
                                        <li><a href="/api/README.md" target="_blank">Full Documentation</a></li>
                                        <li><a href="/api/test-api.php" target="_blank">Test Script</a></li>
                                    </ul>
                                    
                                    <h5 class="mt-3">Sample Integration</h5>
                                    <pre><code>// JavaScript Example
const API_KEY = 'your_api_key_here';
const API_BASE = 'https://yourdomain.com/api/';

// Get rooms
fetch(API_BASE + 'rooms', {
    headers: { 'X-API-Key': API_KEY }
})
.then(response => response.json())
.then(data => console.log(data));</code></pre>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

</body>
</html>
