
🏥 Hospital Management System (HMS)

A full-stack, web-based Hospital Management System built with PHP and MySQL designed to digitize and streamline daily healthcare facility operations, patient records, doctor scheduling, and administrative tasks.

✨ Features

• Role-Based Access Control (RBAC): Distinct dashboards and permissions for Administrator, Doctors, and Patients (with support for optional roles like nurses or receptionists).
• Patient Management: Register new patients, assign unique patient IDs, and maintain comprehensive medical histories and details.
• Doctor Management: Add, update, and manage doctor profiles, specializations, and availability schedules.
• Appointment Scheduling: Book, manage, and track patient appointments with doctors.
• Authentication & Security: Secure user login and password protection for sensitive medical and personal data.
• Responsive UI: Clean layout built with HTML5, CSS3/Bootstrap, and JavaScript for desktop and mobile use.

🛠️ Tech Stack

• Frontend: HTML5, CSS3 (Bootstrap), JavaScript, AJAX
• Backend: PHP (Native / Procedural or MVC)
• Database: MySQL / phpMyAdmin
• Server Environment: XAMPP, WAMP, or LAMP

🚀 Getting Started

Follow these steps to run the project locally on your machine.

Prerequisites

• Download and install XAMPP (or WAMP/LAMP) on your computer.

Installation Steps

1. Clone or Download the Repositorybash
git clone https://github.com
(Or download the ZIP file and extract it)
2. Move Files to Web Server Directory
	• Copy the project folder and paste it into your local server's root directory:
		• XAMPP: C:\xampp\htdocs\hospital-management-system
		• WAMP: C:\wamp\www\hospital-management-system
		• LAMP (Linux): /var/www/html/hospital-management-system
3. Start Apache and MySQL
	• Open your XAMPP Control Panel and start both Apache and MySQL services.
4. Create and Import the Database
	• Open your web browser and go to http://localhost/phpmyadmin.
	• Create a new database named hms (or check your config.php file for the exact expected database name).
	• Click on the database, go to the Import tab, and upload the .sql file located inside the database/ or sql/ folder of the project.
5. Configure Database Connection
	• Open the configuration file (e.g., config/database.php or db.php) and verify your database credentials:php
$host = 'localhost';
$user = 'root';
$password = '';
$dbname = 'hms';
6. Run the Application
	• Open your browser and navigate to:text
http://localhost/hospital-management-system
