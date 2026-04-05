<?php
/**
 * Hotel Booking API
 * RESTful API for external websites to access booking system
 * 
 * Base endpoint: /api/
 * 
 * Endpoints:
 * - GET  /api/rooms           - List available rooms
 * - GET  /api/availability    - Check room availability
 * - POST /api/bookings        - Create a new booking
 * - GET  /api/bookings/{id}   - Get booking status
 * 
 * Authentication: API Key in X-API-Key header
 */

// Load security configuration first
require_once __DIR__ . '/../config/security.php';

// Enable CORS for external websites
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, X-API-Key");
header("Content-Type: application/json");

// Send security headers
sendSecurityHeaders();

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Include database and authentication
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/email.php';

// API Authentication class
class ApiAuth {
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    /**
     * Authenticate API request
     */
    public function authenticate() {
        $apiKey = $this->getApiKey();
        
        if (!$apiKey) {
            $this->sendError('API key is required', 401);
        }
        
        $client = $this->validateApiKey($apiKey);
        
        if (!$client) {
            $this->sendError('Invalid API key', 401);
        }
        
        // Check rate limiting
        if (!$this->checkRateLimit($client['id'])) {
            $this->sendError('Rate limit exceeded. Please try again later.', 429);
        }
        
        // Update usage stats
        $this->updateUsage($client['id']);
        
        return $client;
    }
    
    /**
     * Get API key from request
     */
    private function getApiKey() {
        // Check headers first
        $headers = [];
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
        }

        if (isset($headers['X-API-Key'])) {
            return trim((string)$headers['X-API-Key']);
        }

        if (isset($headers['x-api-key'])) {
            return trim((string)$headers['x-api-key']);
        }

        if (!empty($_SERVER['HTTP_X_API_KEY'])) {
            return trim((string)$_SERVER['HTTP_X_API_KEY']);
        }

        if (!empty($_SERVER['REDIRECT_HTTP_X_API_KEY'])) {
            return trim((string)$_SERVER['REDIRECT_HTTP_X_API_KEY']);
        }
        
        // Check query parameter
        if (isset($_GET['api_key'])) {
            return $_GET['api_key'];
        }
        
        return null;
    }
    
    /**
     * Validate API key
     */
    private function validateApiKey($apiKey) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT id, api_key, client_name, client_website, client_email, 
                       permissions, rate_limit_per_hour, is_active, usage_count
                FROM api_keys 
                WHERE is_active = 1
            ");
            $stmt->execute();
            $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($clients as $client) {
                if (password_verify($apiKey, $client['api_key'])) {
                    // Decode permissions
                    $client['permissions'] = json_decode($client['permissions'], true) ?? [];
                    return $client;
                }
            }
            
            return null;
        } catch (PDOException $e) {
            error_log("API Auth Error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Check rate limit
     */
    private function checkRateLimit($apiKeyId) {
        try {
            // Get usage in the last hour
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) as count 
                FROM api_usage_logs 
                WHERE api_key_id = ? 
                AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
            ");
            $stmt->execute([$apiKeyId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Get rate limit for this key
            $stmt = $this->pdo->prepare("
                SELECT rate_limit_per_hour 
                FROM api_keys 
                WHERE id = ?
            ");
            $stmt->execute([$apiKeyId]);
            $limit = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return ($result['count'] < $limit['rate_limit_per_hour']);
        } catch (PDOException $e) {
            error_log("Rate Limit Check Error: " . $e->getMessage());
            return true; // Allow on error
        }
    }
    
    /**
     * Update usage stats
     */
    private function updateUsage($apiKeyId) {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE api_keys 
                SET last_used_at = NOW(), 
                    usage_count = usage_count + 1 
                WHERE id = ?
            ");
            $stmt->execute([$apiKeyId]);
        } catch (PDOException $e) {
            error_log("Update Usage Error: " . $e->getMessage());
        }
    }
    
    /**
     * Log API usage
     */
    public function logUsage($apiKeyId, $endpoint, $method, $responseCode, $responseTime) {
        try {
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
            
            $stmt = $this->pdo->prepare("
                INSERT INTO api_usage_logs 
                (api_key_id, endpoint, method, ip_address, user_agent, response_code, response_time)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $apiKeyId,
                $endpoint,
                $method,
                $ip,
                $userAgent,
                $responseCode,
                $responseTime
            ]);
        } catch (PDOException $e) {
            error_log("API Log Error: " . $e->getMessage());
        }
    }
    
    /**
     * Check permission
     */
    public function checkPermission($client, $permission) {
        return in_array($permission, $client['permissions']);
    }
    
    /**
     * Send error response
     */
    private function sendError($message, $code = 400) {
        http_response_code($code);
        echo json_encode([
            'success' => false,
            'error' => $message,
            'code' => $code
        ]);
        exit;
    }
}

// API Response helper
class ApiResponse {
    /**
     * Send success response
     */
    public static function success($data = null, $message = 'Success', $code = 200) {
        http_response_code($code);
        echo json_encode([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'timestamp' => date('c')
        ]);
        exit;
    }
    
    /**
     * Send error response
     */
    public static function error($message, $code = 400, $details = null) {
        http_response_code($code);
        echo json_encode([
            'success' => false,
            'error' => $message,
            'details' => $details,
            'code' => $code,
            'timestamp' => date('c')
        ]);
        exit;
    }
    
    /**
     * Send validation error
     */
    public static function validationError($errors) {
        self::error('Validation failed', 422, $errors);
    }
}

// Only run the routing block when index.php is called directly.
// When other API files require_once this file, they only need the classes above.
if (realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) :

// Initialize API
try {
    // Start timing
    $startTime = microtime(true);
    
    // Get request method and path
    $method = $_SERVER['REQUEST_METHOD'];
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';

    // Support setups that route /api/index.php/{endpoint} through PATH_INFO.
    if (!empty($_SERVER['PATH_INFO'])) {
        $path = '/api/' . ltrim($_SERVER['PATH_INFO'], '/');
    }

    $endpoint = '';
    if (preg_match('#/api(?:/index\.php)?/?(.*)$#i', $path, $matches)) {
        $endpoint = trim($matches[1], '/');
    }
    $endpoint = preg_replace('/\.php$/i', '', $endpoint);
    $endpoint = preg_replace('#/+#', '/', $endpoint);

    // Allow GET /api/bookings/{id} in addition to GET /api/bookings?id={id}
    if (preg_match('#^bookings/([^/]+)$#i', $endpoint, $bookingMatch)) {
        $endpoint = 'bookings';
        if (!isset($_GET['id']) || $_GET['id'] === '') {
            $_GET['id'] = $bookingMatch[1];
        }
    }
    
    // Initialize authentication
    $auth = new ApiAuth($pdo);
    $client = $auth->authenticate();

    // Log usage even when endpoint handlers call exit() in ApiResponse helpers.
    register_shutdown_function(function() use ($auth, $client, &$endpoint, $method, $startTime) {
        try {
            $responseTime = max(0, microtime(true) - $startTime);
            $responseCode = http_response_code();
            if (!$responseCode) {
                $responseCode = 200;
            }
            $auth->logUsage($client['id'], $endpoint ?: 'index', $method, $responseCode, $responseTime);
        } catch (Throwable $e) {
            error_log('API usage log write failed: ' . $e->getMessage());
        }
    });
    
    // Define constant to allow access to endpoint files
    define('API_ACCESS_ALLOWED', true);
    
    // Route the request
    switch ($endpoint) {
        case 'rooms':
            if ($method === 'GET') {
                require_once __DIR__ . '/rooms.php';
            } else {
                ApiResponse::error('Method not allowed', 405);
            }
            break;
            
        case 'availability':
            if ($method === 'GET') {
                require_once __DIR__ . '/availability.php';
            } else {
                ApiResponse::error('Method not allowed', 405);
            }
            break;
            
        case 'bookings':
            if ($method === 'POST') {
                require_once __DIR__ . '/bookings.php';
            } elseif ($method === 'GET' && isset($_GET['id'])) {
                require_once __DIR__ . '/booking-details.php';
            } else {
                ApiResponse::error('Method not allowed or missing booking ID', 405);
            }
            break;
            
        case 'payments':
            require_once __DIR__ . '/payments.php';
            break;

        case 'blocked-dates':
            require_once __DIR__ . '/blocked-dates.php';
            break;
            
        case 'site-settings':
            // Dynamic site settings from database
            if ($method === 'GET') {
                require_once __DIR__ . '/site-settings.php';
            } else {
                ApiResponse::error('Method not allowed', 405);
            }
            break;
            
        case '':
            // API documentation/info
            ApiResponse::success([
                'api' => getSetting('site_name', 'Hotel Website') . ' Booking API',
                'version' => '1.0.0',
                'endpoints' => [
                    'GET /api/rooms' => 'List available rooms',
                    'GET /api/availability' => 'Check room availability',
                    'POST /api/bookings' => 'Create a new booking',
                    'GET /api/bookings?id={id}' => 'Get booking status',
                    'GET /api/bookings/{id}' => 'Get booking status by path parameter',
                    'GET /api/payments' => 'List all payments (with filters)',
                    'POST /api/payments' => 'Create a new payment',
                    'GET /api/payments/{id}' => 'Get payment details',
                    'PUT /api/payments/{id}' => 'Update payment',
                    'DELETE /api/payments/{id}' => 'Delete payment (soft delete)',
                    'GET /api/blocked-dates' => 'Get blocked dates (calendar/public use)',
                    'POST /api/blocked-dates' => 'Create blocked dates (authenticated)',
                    'GET /api/site-settings' => 'Get dynamic site settings'
                ],
                'authentication' => 'API Key required in X-API-Key header',
                'documentation' => 'Contact admin for full API documentation'
            ]);
            break;
            
        default:
            if (strpos($endpoint, 'payments/') === 0) {
                require_once __DIR__ . '/payments.php';
                break;
            }

            ApiResponse::error('Endpoint not found', 404);
    }
    
} catch (Exception $e) {
    ApiResponse::error('Internal server error', 500, $e->getMessage());
}

endif; // realpath check