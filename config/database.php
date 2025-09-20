<?php
class Database {
    private $db_path;
    private $connection;
    
    public function __construct() {
        // Use __DIR__ to get current file's directory, then go to data folder
        $this->db_path = __DIR__ . '/../data/typing_center.db';
        
        // Ensure the data directory exists
        $dir = dirname($this->db_path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        $this->connection = new PDO("sqlite:" . $this->db_path);
        $this->connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Enable foreign key constraints for data integrity
        $this->connection->exec("PRAGMA foreign_keys = ON");
        
        $this->createTables();
    }
    
    public function getConnection() {
        return $this->connection;
    }
    
    private function createTables() {
        $sql = "
        -- Admin table
        CREATE TABLE IF NOT EXISTS admin (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username VARCHAR(50) UNIQUE NOT NULL,
            password VARCHAR(255) NOT NULL,
            email VARCHAR(100) UNIQUE NOT NULL,
            full_name VARCHAR(100) NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        
        -- Branches table
        CREATE TABLE IF NOT EXISTS branches (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            branch_name VARCHAR(100) NOT NULL,
            branch_code VARCHAR(20) UNIQUE NOT NULL,
            address TEXT,
            phone VARCHAR(20),
            email VARCHAR(100),
            manager_username VARCHAR(50) UNIQUE NOT NULL,
            manager_password VARCHAR(255) NOT NULL,
            manager_full_name VARCHAR(100) NOT NULL,
            status TEXT DEFAULT 'active' CHECK (status IN ('active', 'inactive')),
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        
        -- Services table
        CREATE TABLE IF NOT EXISTS services (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            service_name VARCHAR(100) NOT NULL,
            service_price DECIMAL(10,2) NOT NULL,
            commission_rate DECIMAL(5,2) DEFAULT 0.00,
            status TEXT DEFAULT 'active' CHECK (status IN ('active', 'inactive')),
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        
        -- Employees table
        CREATE TABLE IF NOT EXISTS employees (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            branch_id INTEGER NOT NULL,
            employee_code VARCHAR(20) UNIQUE NOT NULL,
            username VARCHAR(50) UNIQUE NOT NULL,
            password VARCHAR(255) NOT NULL,
            full_name VARCHAR(100) NOT NULL,
            email VARCHAR(100),
            phone VARCHAR(20),
            basic_salary DECIMAL(10,2) DEFAULT 0.00,
            commission_rate DECIMAL(5,2) DEFAULT 0.00,
            hire_date DATE NOT NULL,
            status TEXT DEFAULT 'active' CHECK (status IN ('active', 'inactive')),
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (branch_id) REFERENCES branches(id)
        );
        
        -- Receipts table
        CREATE TABLE IF NOT EXISTS receipts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            receipt_number VARCHAR(50) UNIQUE NOT NULL,
            branch_id INTEGER NOT NULL,
            employee_id INTEGER NOT NULL,
            customer_name VARCHAR(100) NOT NULL,
            customer_phone VARCHAR(20),
            customer_email VARCHAR(100),
            total_amount DECIMAL(10,2) NOT NULL,
            total_commission DECIMAL(10,2) DEFAULT 0.00,
            payment_status TEXT DEFAULT 'paid' CHECK (payment_status IN ('paid', 'pending', 'cancelled')),
            notes TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (branch_id) REFERENCES branches(id),
            FOREIGN KEY (employee_id) REFERENCES employees(id)
        );
        
        -- Receipt items table
        CREATE TABLE IF NOT EXISTS receipt_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            receipt_id INTEGER NOT NULL,
            service_id INTEGER NOT NULL,
            quantity INTEGER DEFAULT 1,
            unit_price DECIMAL(10,2) NOT NULL,
            total_price DECIMAL(10,2) NOT NULL,
            commission_amount DECIMAL(10,2) DEFAULT 0.00,
            FOREIGN KEY (receipt_id) REFERENCES receipts(id),
            FOREIGN KEY (service_id) REFERENCES services(id)
        );
        
        -- Salaries table
        CREATE TABLE IF NOT EXISTS salaries (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            employee_id INTEGER NOT NULL,
            month INTEGER NOT NULL,
            year INTEGER NOT NULL,
            basic_salary DECIMAL(10,2) NOT NULL,
            total_commission DECIMAL(10,2) DEFAULT 0.00,
            total_salary DECIMAL(10,2) NOT NULL,
            payment_status TEXT DEFAULT 'pending' CHECK (payment_status IN ('paid', 'pending')),
            payment_date DATE,
            notes TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (employee_id) REFERENCES employees(id)
        );
        
        -- Activity logs table
        CREATE TABLE IF NOT EXISTS activity_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_type TEXT NOT NULL CHECK (user_type IN ('admin', 'branch', 'employee')),
            user_id INTEGER NOT NULL,
            action VARCHAR(100) NOT NULL,
            description TEXT,
            ip_address VARCHAR(45),
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        
        -- Settings table for branding
        CREATE TABLE IF NOT EXISTS settings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            setting_key VARCHAR(100) UNIQUE NOT NULL,
            setting_value TEXT,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        ";
        
        $this->connection->exec($sql);
        
        // Insert default admin if not exists
        $stmt = $this->connection->prepare("SELECT COUNT(*) FROM admin");
        $stmt->execute();
        if ($stmt->fetchColumn() == 0) {
            $admin_password = password_hash('admin123', PASSWORD_DEFAULT);
            $stmt = $this->connection->prepare("INSERT INTO admin (username, password, email, full_name) VALUES (?, ?, ?, ?)");
            $stmt->execute(['admin', $admin_password, 'admin@typingcenter.com', 'System Administrator']);
        }
        
        // Insert default services if not exists
        // $stmt = $this->connection->prepare("SELECT COUNT(*) FROM services");
        // $stmt->execute();
        // if ($stmt->fetchColumn() == 0) {
        //     $services = [
        //         ['Typing (per page)', 5.00, 10.00],
        //         ['Photocopying (per page)', 0.50, 5.00],
        //         ['Printing (per page)', 1.00, 8.00],
        //         ['Scanning (per page)', 2.00, 15.00],
        //         ['Lamination (per page)', 3.00, 20.00],
        //         ['Binding', 10.00, 25.00]
        //     ];
            
        //     $stmt = $this->connection->prepare("INSERT INTO services (service_name, service_price, commission_rate) VALUES (?, ?, ?)");
        //     foreach ($services as $service) {
        //         $stmt->execute($service);
        //     }
        // }
        
        // Insert default settings if not exists
        // $stmt = $this->connection->prepare("SELECT COUNT(*) FROM settings");
        // $stmt->execute();
        // if ($stmt->fetchColumn() == 0) {
        //     $settings = [
        //         ['company_name', 'Typing Center Management System'],
        //         ['company_address', '123 Main Street, City, Country'],
        //         ['company_phone', '+1-234-567-8900'],
        //         ['receipt_header', 'Thank you for choosing our services'],
        //         ['receipt_footer', 'Visit us again!']
        //     ];
            
        //     $stmt = $this->connection->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)");
        //     foreach ($settings as $setting) {
        //         $stmt->execute($setting);
        //     }
        // }
    }
}
?>