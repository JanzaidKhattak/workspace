Typing Center Management System
Overview
This is a PHP-based Typing Center Management System that handles multi-role user authentication and business operations for typing service centers. The system supports three user types: System Administrator, Branch Manager, and Employee.

Project Architecture
Language: PHP 8.4
Database: SQLite (file-based at data/typing_center.db)
Frontend: HTML/CSS/JavaScript with Bootstrap 5.1.3
Icons: Font Awesome 6.0.0
Server: PHP built-in development server
Port: 5000
Directory Structure
/
├── admin/           # Admin dashboard and management pages
├── branch/          # Branch manager dashboard
├── employee/        # Employee dashboard
├── config/          # Database configuration
├── includes/        # Authentication and utility classes
├── data/           # SQLite database storage
├── index.php       # Main entry point (redirects based on user role)
├── login.php       # Multi-role login page
├── logout.php      # Logout handler
└── unauthorized.php # Access denied page
Key Features
Multi-role Authentication System

System Administrator
Branch Manager
Employee access levels
Database Schema (Auto-created on startup)

Admin users
Branches with managers
Services and pricing
Employees with commission tracking
Receipts and receipt items
Salary management
Activity logging
System settings
Default Credentials

Admin: username admin, password admin123
Current Setup Status
✅ PHP server running on port 5000
✅ Database schema auto-created
✅ Default admin account created
✅ Default services populated
✅ Authentication system working
✅ Multi-role routing functional
Recent Changes
September 18, 2025: Initial project import and Replit environment setup completed
PHP server configured to run on 0.0.0.0:5000 for Replit proxy compatibility
SQLite database auto-initializes with sample data
All workflows configured and tested
User Preferences
No specific user preferences documented yet
Development Notes
The system uses SQLite for simplicity and portability
Session-based authentication with proper security measures
Responsive Bootstrap-based UI
Font Awesome icons throughout the interface
Activity logging for audit trails
Commission and salary calculation system