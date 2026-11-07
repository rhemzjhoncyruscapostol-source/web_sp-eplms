<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once __DIR__ . '/Base_api.php';

class Analytics extends Base_api
{
    private $analytics;

    public function __construct()
    {
        parent::__construct();
        $this->analytics = $this->call->model('Analytics_model', 'analytics');
    }

    /** GET /api/analytics//hr   (hr, mis, admin) */
    public function hr()
    {
        $this->api->require_method('GET');
        $this->authenticate(['hr', 'mis', 'admin']);

        $this->ok($this->analytics->hr_dashboard((int) date('Y')));
    }

    /** GET /api/analytics/accounting?office=&year=  (accounting) */
    public function accounting()
    {
        $this->api->require_method('GET');
        $this->authenticate(['accounting']);

        $year = (int) ($_GET['year'] ?? date('Y'));
        $office = $this->text($_GET['office'] ?? '', 120);

        $this->ok($this->analytics->accounting_dashboard($year, $office));
    }
}
