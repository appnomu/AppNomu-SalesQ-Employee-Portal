#!/bin/bash
# =====================================================
# EP Portal Cron Installation Script
# =====================================================
# This script sets up all necessary cron jobs and directories
# Run this on your server at /home/appnomuc
# =====================================================

echo "EP Portal Cron Installation Starting..."

# Create log directories
echo "Creating log directories..."
mkdir -p /home/appnomuc/logs
mkdir -p /home/appnomuc/backups

# Set proper permissions
echo "Setting permissions..."
chmod 755 /home/appnomuc/logs
chmod 755 /home/appnomuc/backups
chmod +x /home/appnomuc/domains/emp.appnomu.com/public_html/cron/*.php

# Create initial log files
echo "Creating initial log files..."
touch /home/appnomuc/logs/ep-reminders.log
touch /home/appnomuc/logs/withdrawals.log
touch /home/appnomuc/logs/tasks.log
touch /home/appnomuc/logs/notifications.log
touch /home/appnomuc/logs/cleanup.log
touch /home/appnomuc/logs/logrotate.log

# Set log file permissions
chmod 644 /home/appnomuc/logs/*.log
chown appnomuc:appnomuc /home/appnomuc/logs/*.log

# Display cron commands to add
echo ""
echo "======================================================="
echo "CRON JOBS TO ADD - Copy these to your crontab:"
echo "Run: crontab -e"
echo "======================================================="
echo ""
echo "# EP Portal Core Processing"
echo "* * * * * php /home/appnomuc/domains/emp.appnomu.com/public_html/cron/reminder-cron.php >> /home/appnomuc/logs/ep-reminders.log 2>&1"
echo "*/5 * * * * php /home/appnomuc/domains/emp.appnomu.com/public_html/cron/process-withdrawals.php >> /home/appnomuc/logs/withdrawals.log 2>&1"
echo "*/30 * * * * php /home/appnomuc/domains/emp.appnomu.com/public_html/cron/process-tasks.php >> /home/appnomuc/logs/tasks.log 2>&1"
echo "*/15 * * * * php /home/appnomuc/domains/emp.appnomu.com/public_html/cron/process-notifications.php >> /home/appnomuc/logs/notifications.log 2>&1"
echo ""
echo "# System Maintenance"
echo "0 2 * * * php /home/appnomuc/domains/emp.appnomu.com/public_html/cron/cleanup-otp.php >> /home/appnomuc/logs/cleanup.log 2>&1"
echo "0 4 * * * find /home/appnomuc/logs -name \"*.log\" -size +10M -exec gzip {} \; >> /home/appnomuc/logs/logrotate.log 2>&1"
echo "0 5 1 * * find /home/appnomuc/logs -name \"*.log.gz\" -mtime +30 -delete >> /home/appnomuc/logs/logrotate.log 2>&1"
echo ""
echo "======================================================="
echo "Installation completed!"
echo ""
echo "Next steps:"
echo "1. Add the cron jobs above to your crontab"
echo "2. Test with: php /home/appnomuc/domains/emp.appnomu.com/public_html/cron/reminder-cron.php"
echo "3. Monitor logs: tail -f /home/appnomuc/logs/ep-reminders.log"
echo "======================================================="
