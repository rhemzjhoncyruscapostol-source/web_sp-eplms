<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once __DIR__ . '/Base_api.php';

class Audit extends Base_api
{
    /** GET /api/audit?q=&page=1&per=25  (mis, admin) */
    public function index()
    {
        $this->api->require_method('GET');
        $this->authenticate(['mis', 'admin']);

        $q = $this->text($_GET['q'] ?? '', 100);
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $per = min(100, max(1, (int) ($_GET['per'] ?? 25)));

        $where = '';
        $params = [];
        if ($q !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
            $where = 'WHERE username LIKE ? OR action LIKE ? OR detail LIKE ?';
            $params = [$like, $like, $like];
        }
        $total = (int) $this->scalar("SELECT COUNT(*) FROM audit_log $where", $params);
        $offset = ($page - 1) * $per;
        $rows = $this->rows(
            "SELECT id, username, role, action, detail, ip, created_at FROM audit_log $where
              ORDER BY id DESC LIMIT $per OFFSET $offset",
            $params
        );
        $this->ok(['entries' => $rows, 'total' => $total, 'page' => $page, 'pages' => max(1, (int) ceil($total / $per))]);
    }
}
