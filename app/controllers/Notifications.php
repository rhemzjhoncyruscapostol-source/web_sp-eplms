<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once __DIR__ . '/Base_api.php';

class Notifications extends Base_api
{
    /** GET /api/notifications  - latest 50 for the logged-in user, plus the unread count. */
    public function index()
    {
        $this->api->require_method('GET');
        $me = $this->authenticate();

        $rows = $this->rows(
            'SELECT id, title, body, action_url, is_read, created_at FROM notifications
              WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT 50',
            [$me['id']]
        );
        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
            $r['is_read'] = (int) $r['is_read'];
        }
        $unread = (int) $this->scalar('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0', [$me['id']]);
        $this->ok(['notifications' => $rows, 'unread' => $unread]);
    }

    /** POST /api/notifications/{id}/read */
    public function read($id)
    {
        $this->api->require_method('POST');
        $me = $this->authenticate();
        $this->run('UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?', [(int) $id, $me['id']]);
        $this->ok(['message' => 'Marked as read.']);
    }

    /** POST /api/notifications/read-all */
    public function read_all()
    {
        $this->api->require_method('POST');
        $me = $this->authenticate();
        $this->run('UPDATE notifications SET is_read = 1 WHERE user_id = ?', [$me['id']]);
        $this->ok(['message' => 'All marked as read.']);
    }
}
