<?php
/**
 * System Notifications Helper
 * Handles in-app notification management
 */

class SystemNotifications {
    private $db;
    
    public function __construct($database) {
        $this->db = $database;
    }
    
    /**
     * Create a new system notification
     */
    public function createNotification($userId, $title, $message, $type = 'info') {
        $stmt = $this->db->prepare("
            INSERT INTO system_notifications (user_id, title, message, type) 
            VALUES (?, ?, ?, ?)
        ");
        return $stmt->execute([$userId, $title, $message, $type]);
    }
    
    /**
     * Get unread notifications for a user
     */
    public function getUnreadNotifications($userId, $limit = 10) {
        $stmt = $this->db->prepare("
            SELECT * FROM system_notifications 
            WHERE user_id = ? AND is_read = FALSE 
            ORDER BY created_at DESC 
            LIMIT ?
        ");
        $stmt->execute([$userId, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get all notifications for a user
     */
    public function getAllNotifications($userId, $limit = 50) {
        $stmt = $this->db->prepare("
            SELECT * FROM system_notifications 
            WHERE user_id = ? 
            ORDER BY created_at DESC 
            LIMIT ?
        ");
        $stmt->execute([$userId, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Mark notification as read
     */
    public function markAsRead($notificationId, $userId) {
        $stmt = $this->db->prepare("
            UPDATE system_notifications 
            SET is_read = TRUE 
            WHERE id = ? AND user_id = ?
        ");
        return $stmt->execute([$notificationId, $userId]);
    }
    
    /**
     * Mark all notifications as read for a user
     */
    public function markAllAsRead($userId) {
        $stmt = $this->db->prepare("
            UPDATE system_notifications 
            SET is_read = TRUE 
            WHERE user_id = ? AND is_read = FALSE
        ");
        return $stmt->execute([$userId]);
    }
    
    /**
     * Get unread notification count
     */
    public function getUnreadCount($userId) {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as count 
            FROM system_notifications 
            WHERE user_id = ? AND is_read = FALSE
        ");
        $stmt->execute([$userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['count'] ?? 0;
    }
    
    /**
     * Delete old notifications (older than 30 days)
     */
    public function cleanupOldNotifications() {
        $stmt = $this->db->prepare("
            DELETE FROM system_notifications 
            WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");
        return $stmt->execute();
    }
}

/**
 * Helper function to get notifications instance
 */
function getNotifications($db) {
    return new SystemNotifications($db);
}
?>
