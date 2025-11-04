# AppNomu SalesQ - Employee Portal

A comprehensive PHP-based employee management system with multi-channel notifications, salary management, and task tracking.

![AppNomu SalesQ](https://appnomu.com/landing/assets/images/AppNomu%20SalesQ%20logo.png)

## 🚀 Features

### Core Features
- **Employee Management** - Complete employee lifecycle management
- **Leave Requests** - Submit and track leave requests with approval workflow
- **Task Management** - Assign and track tasks with deadlines
- **Ticket System** - Internal support ticket management
- **Document Management** - Secure file upload and storage
- **Salary Management** - Automated salary allocation and withdrawal system

### Communication & Notifications
- **Multi-Channel OTP** - SMS, Email, and WhatsApp authentication
- **Smart Reminders** - Automated reminders via SMS, WhatsApp, and system notifications
- **Real-time Notifications** - In-app notification system

### Financial Features
- **Salary Withdrawals** - Integrated with Flutterwave for mobile money and bank transfers
- **Withdrawal Statements** - Complete transaction history
- **Payment Processing** - Secure payment gateway integration

### Security Features
- **OTP Authentication** - Multi-factor authentication via SMS, Email, WhatsApp
- **Session Management** - Secure session handling with timeout
- **CSRF Protection** - Cross-site request forgery protection
- **Role-Based Access** - Admin and Employee role separation
- **Audit Logging** - Complete activity tracking

## 📋 Requirements

- **PHP** >= 7.4
- **MySQL** >= 5.7 or MariaDB >= 10.3
- **Apache/Nginx** with mod_rewrite enabled
- **SSL Certificate** (Required for production)
- **Composer** (Optional, for dependencies)

## 🛠️ Installation

### 1. Clone the Repository

```bash
git clone https://github.com/appnomu/AppNomu-SalesQ-Employee-Portal.git
cd AppNomu-SalesQ-Employee-Portal
```

### 2. Configure Environment

```bash
# Copy the environment template
cp .env.example .env

# Edit .env with your actual credentials
nano .env
```

### 3. Database Setup

```bash
# Import the database schema
mysql -u your_username -p your_database_name < database/consolidated-database.sql
```

### 4. Configure Web Server

**Apache (.htaccess is included)**
- Ensure mod_rewrite is enabled
- Point document root to the project directory

**Nginx**
```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

### 5. Set Permissions

```bash
# Make uploads directory writable
chmod 755 uploads/
chmod 755 logs/

# Secure sensitive files
chmod 600 .env
```

## 🔐 Configuration

### Required API Keys

1. **Infobip** (SMS, WhatsApp, Email)
   - Sign up at [Infobip](https://www.infobip.com/)
   - Get API key and base URL
   - Configure WhatsApp Business number

2. **Flutterwave** (Payment Processing)
   - Sign up at [Flutterwave](https://flutterwave.com/)
   - Get public, secret, and encryption keys
   - Set environment (sandbox/live)

3. **Cloudflare** (Optional - Security)
   - Get API token from Cloudflare dashboard

### Environment Variables

See `.env.example` for all required configuration options.

**Critical Settings:**
- `DB_*` - Database credentials
- `ENCRYPTION_KEY` - 32-character random string
- `JWT_SECRET` - 64-character random string
- `INFOBIP_API_KEY` - Infobip API credentials
- `FLUTTERWAVE_SECRET_KEY` - Flutterwave credentials

## 👤 Default Admin Account

After database import, login with:

- **Email:** `info@appnomu.com`
- **Password:** `UgandanN256@`
- **Employee Number:** `EP-ADMIN001`

**⚠️ IMPORTANT:** Change the default password immediately after first login!

## 📱 Features Overview

### For Employees
- View and update profile
- Submit leave requests
- View assigned tasks
- Create support tickets
- Request salary withdrawals
- Set reminders
- Upload documents

### For Administrators
- Create and manage employees
- Approve/reject leave requests
- Assign tasks to employees
- Respond to support tickets
- Manage salary allocations
- View withdrawal requests
- Generate reports
- System settings management

## 🔧 Cron Jobs

Set up the following cron jobs for automated tasks:

```bash
# Process reminders every minute
* * * * * php /path/to/project/cron/reminder-cron.php

# Cleanup expired OTPs every hour
0 * * * * php /path/to/project/cron/cleanup-otp.php

# Monthly salary allocation (1st of each month)
0 0 1 * * php /path/to/project/cron/monthly-salary-allocation.php

# Process pending withdrawals every 5 minutes
*/5 * * * * php /path/to/project/cron/process-withdrawals.php

# Process notifications every minute
* * * * * php /path/to/project/cron/process-notifications.php
```

## 🏗️ Project Structure

```
EPportal/
├── admin/              # Admin dashboard pages
├── api/                # API endpoints
├── auth/               # Authentication pages
├── config/             # Configuration files
├── cron/               # Scheduled tasks
├── database/           # Database schema
├── employee/           # Employee dashboard pages
├── includes/           # Shared PHP classes and functions
├── uploads/            # User uploaded files (gitignored)
├── .env.example        # Environment template
├── .htaccess           # Apache configuration
└── index.php           # Entry point
```

## 🔒 Security Best Practices

1. **Never commit `.env` file** - Contains sensitive credentials
2. **Use HTTPS in production** - Required for secure sessions
3. **Change default passwords** - Update admin password immediately
4. **Regular backups** - Backup database and uploads regularly
5. **Keep dependencies updated** - Monitor for security updates
6. **Restrict file permissions** - Secure sensitive files and directories
7. **Enable error logging** - Monitor application logs

## 📚 Documentation

- [Clean URLs Implementation](CLEAN-URLS-IMPLEMENTATION.md)
- [Employee Assessment Questions](EMPLOYEE-ASSESSMENT-QUESTIONS.md)

## 🤝 Contributing

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

## 📝 License

This project is proprietary software owned by AppNomu. All rights reserved.

## 🆘 Support

For support and inquiries:
- **Email:** support@appnomu.com
- **Website:** [https://appnomu.com](https://appnomu.com)
- **Portal:** [https://emp.appnomu.com](https://emp.appnomu.com)

## ⚠️ Important Notes

- This system handles sensitive employee and financial data
- Ensure compliance with local data protection regulations
- Regular security audits are recommended
- Test thoroughly in staging before production deployment
- Keep all API keys and credentials secure

---

**Built with ❤️ by AppNomu Team**
