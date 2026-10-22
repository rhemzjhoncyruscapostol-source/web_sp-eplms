<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once __DIR__ . '/Api_model.php';

class Notifications_model extends Api_model
{
    public function for_user(int $user_id): array
    {
        return $this->rows(
            'SELECT id, title, body, is_read, created_at FROM notifications
              WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT 50',
            [$user_id]
        );
    }

    public function unread_count(int $user_id): int
    {
        return (int) $this->scalar('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0', [$user_id]);
    }

    public function mark_read(int $id, int $user_id): int
    {
        return $this->run('UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?', [(int) $id, $user_id]);
    }

    public function mark_all_read(int $user_id): int
    {
        return $this->run('UPDATE notifications SET is_read = 1 WHERE user_id = ?', [$user_id]);
    }
}
