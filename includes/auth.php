<?php
session_start();

class Auth {
    private $db;
    
    public function __construct($database) {
        $this->db = $database->getConnection();
    }
    
    public function login($username, $password, $user_type) {
        try {
            switch ($user_type) {
                case 'admin':
                    $stmt = $this->db->prepare("SELECT * FROM admin WHERE username = ?");
                    break;
                case 'branch':
                    $stmt = $this->db->prepare("SELECT * FROM branches WHERE manager_username = ? AND status = 'active'");
                    break;
                case 'employee':
                    $stmt = $this->db->prepare("SELECT e.*, b.branch_name FROM employees e JOIN branches b ON e.branch_id = b.id WHERE e.username = ? AND e.status = 'active'");
                    break;
                default:
                    return false;
            }
            
            $stmt->execute([$username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user) {
                $password_field = ($user_type === 'branch') ? 'manager_password' : 'password';
                if (password_verify($password, $user[$password_field])) {
                    // Regenerate session ID to prevent session fixation attacks
                    session_regenerate_id(true);
                    
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['user_type'] = $user_type;
                    $_SESSION['username'] = $username;
                    $_SESSION['full_name'] = ($user_type === 'branch') ? $user['manager_full_name'] : $user['full_name'];
                    
                    if ($user_type === 'employee') {
                        $_SESSION['branch_id'] = $user['branch_id'];
                        $_SESSION['branch_name'] = $user['branch_name'];
                    }
                    
                    // Log activity
                    $this->logActivity($user_type, $user['id'], 'Login', 'User logged in');
                    
                    return true;
                }
            }
            return false;
        } catch (Exception $e) {
            return false;
        }
    }
    
    public function logout() {
        if (isset($_SESSION['user_type'], $_SESSION['user_id'])) {
            $this->logActivity($_SESSION['user_type'], $_SESSION['user_id'], 'Logout', 'User logged out');
        }
        
        session_destroy();
        header('Location: /login.php');
        exit;
    }
    
    public function isLoggedIn() {
        return isset($_SESSION['user_id']) && isset($_SESSION['user_type']);
    }
    
    public function requireLogin() {
        if (!$this->isLoggedIn()) {
            header('Location: /login.php');
            exit;
        }
    }
    
    public function requireRole($allowed_roles) {
        $this->requireLogin();
        if (!in_array($_SESSION['user_type'], $allowed_roles)) {
            header('Location: /unauthorized.php');
            exit;
        }
    }
    
    public function logActivity($user_type, $user_id, $action, $description = '') {
        try {
            $stmt = $this->db->prepare("INSERT INTO activity_logs (user_type, user_id, action, description, ip_address) VALUES (?, ?, ?, ?, ?)");
            $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
            $stmt->execute([$user_type, $user_id, $action, $description, $ip_address]);
        } catch (Exception $e) {
            // Log error but don't stop execution
        }
    }
    
    public function getUserInfo() {
        return [
            'id' => $_SESSION['user_id'] ?? null,
            'type' => $_SESSION['user_type'] ?? null,
            'username' => $_SESSION['username'] ?? null,
            'full_name' => $_SESSION['full_name'] ?? null,
            'branch_id' => $_SESSION['branch_id'] ?? null,
            'branch_name' => $_SESSION['branch_name'] ?? null
        ];
    }
}
?>