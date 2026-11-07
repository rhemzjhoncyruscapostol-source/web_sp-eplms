<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
/**
 * ------------------------------------------------------------------
 * LavaLust - an opensource lightweight PHP MVC Framework
 * ------------------------------------------------------------------
 *
 * MIT License
 *
 * Copyright (c) 2020 Ronald M. Marasigan
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 *
 * @package LavaLust
 * @author Ronald M. Marasigan <ronald.marasigan@yahoo.com>
 * @since Version 1
 * @link https://github.com/ronmarasigan/LavaLust
 * @license https://opensource.org/licenses/MIT MIT License
 */

/*
| -------------------------------------------------------------------
| URI ROUTING
| -------------------------------------------------------------------
| Here is where you can register web routes for your application.
|
|
*/
/** @var object $router **/

// Register middleware before route dispatch so protected routes can resolve it.
(function () {
    require_once APP_DIR . 'config/middleware.php';
    get_config($config);
})();

// -----------------------------------------------------------------
// Public (no login): names, positions, offices and totals only
// -----------------------------------------------------------------
$router->get('/', 'Public_api::index');
$router->get('/api/org-chart', 'Public_api::org_chart');
$router->get('/api/directory', 'Public_api::directory');
$router->get('/api/public/summary', 'Public_api::summary');

// -----------------------------------------------------------------
// Authentication (HR, Accounting, MIS and Admin also enter an OTP code)
// -----------------------------------------------------------------
$router->post('/api/auth/login', 'Auth::login');
$router->post('/api/auth/verify-otp', 'Auth::verify_otp');
$router->post('/api/auth/resend-otp', 'Auth::resend_otp');
$router->post('/api/auth/refresh', 'Auth::refresh');
$router->post('/api/auth/logout', 'Auth::logout');
$router->get('/api/auth/me', 'Auth::me')->middleware('auth');

// -----------------------------------------------------------------
// Dashboards and analytics
// -----------------------------------------------------------------
$router->get('/api/stats', 'Stats::index')->middleware('auth');
$router->get('/api/analytics/hr', 'Analytics::hr')->middleware('auth');
$router->get('/api/analytics/accounting', 'Analytics::accounting')->middleware('auth');

// -----------------------------------------------------------------
// Employee self-service
// -----------------------------------------------------------------
$router->get('/api/me/salaries', 'Salaries::mine')->middleware('auth');
$router->get('/api/me/leaves', 'Leaves::mine')->middleware('auth');
$router->post('/api/me/leaves', 'Leaves::create')->middleware('auth');
$router->delete('/api/me/leaves/{id}', 'Leaves::cancel')->middleware('auth');

// -----------------------------------------------------------------
// Employee profiles (HR / MIS write, Admin / Accounting read)
// -----------------------------------------------------------------
$router->get('/api/employees', 'Employees::index')->middleware('auth');
$router->post('/api/employees', 'Employees::store')->middleware('auth');
$router->put('/api/employees/{id}', 'Employees::update')->middleware('auth');
$router->get('/api/employees/{id}/salary-history', 'Employees::salary_history')->middleware('auth');
$router->delete('/api/employees/{id}', 'Employees::destroy')->middleware('auth');

// -----------------------------------------------------------------
// Leave approvals (HR / MIS review, Admin read)
// -----------------------------------------------------------------
$router->get('/api/leaves', 'Leaves::index')->middleware('auth');
$router->patch('/api/leaves/{id}/review', 'Leaves::review')->middleware('auth');
$router->get('/api/leaves/{id}/attachment', 'Leaves::attachment')->middleware('auth');

// -----------------------------------------------------------------
// Attendance (HR / MIS write, Admin read)
// -----------------------------------------------------------------
$router->get('/api/attendance', 'Attendance::index')->middleware('auth');
$router->put('/api/attendance', 'Attendance::save')->middleware('auth');

// -----------------------------------------------------------------
// Payroll (Accounting writes, HR reads)
// -----------------------------------------------------------------
$router->get('/api/salaries', 'Salaries::index')->middleware('auth');
$router->post('/api/salaries', 'Salaries::store')->middleware('auth');
$router->put('/api/salaries/{id}', 'Salaries::update')->middleware('auth');
$router->delete('/api/salaries/{id}', 'Salaries::destroy')->middleware('auth');
$router->get('/api/payroll/schedules', 'Payroll_settings::index')->middleware('auth');
$router->post('/api/payroll/schedules', 'Payroll_settings::store')->middleware('auth');
$router->get('/api/loans', 'Loans::index')->middleware('auth');
$router->post('/api/loans', 'Loans::store')->middleware('auth');
$router->put('/api/loans/{id}', 'Loans::update')->middleware('auth');
$router->delete('/api/loans/{id}', 'Loans::destroy')->middleware('auth');
$router->get('/api/me/loans', 'Loans::mine')->middleware('auth');
$router->get('/api/me/attendance', 'Attendance::mine')->middleware('auth');
$router->get('/api/me/attendance/qr', 'Attendance::qr')->middleware('auth');
$router->post('/api/me/attendance/punch', 'Attendance::punch')->middleware('auth');
$router->post('/api/me/attendance/time-in', 'Attendance::time_in')->middleware('auth');
$router->post('/api/me/attendance/time-out', 'Attendance::time_out')->middleware('auth');
$router->post('/api/attendance/scan', 'Attendance::scan')->middleware('auth');

// -----------------------------------------------------------------
// Budget and expenses (Accounting only)
// -----------------------------------------------------------------
$router->get('/api/expenses', 'Expenses::index')->middleware('auth');
$router->get('/api/expenses/suggest', 'Expenses::suggest')->middleware('auth');
$router->post('/api/expenses', 'Expenses::store')->middleware('auth');
$router->get('/api/budgets', 'Expenses::budgets')->middleware('auth');
$router->put('/api/budgets', 'Expenses::save_budget')->middleware('auth');

// -----------------------------------------------------------------
// Accounts (MIS writes, Admin reads). No public registration.
// -----------------------------------------------------------------
$router->get('/api/users', 'Users::index')->middleware('auth');
$router->post('/api/users', 'Users::store')->middleware('auth');
$router->put('/api/users/{id}', 'Users::update')->middleware('auth');
$router->post('/api/users/{id}/password', 'Users::reset_password')->middleware('auth');
$router->delete('/api/users/{id}', 'Users::destroy')->middleware('auth');

// -----------------------------------------------------------------
// System settings and audit log (MIS writes, Admin reads)
// -----------------------------------------------------------------
$router->get('/api/settings', 'Settings::index')->middleware('auth');
$router->put('/api/settings', 'Settings::update')->middleware('auth');
$router->get('/api/audit', 'Audit::index')->middleware('auth');

// -----------------------------------------------------------------
// Notifications (every signed-in user)
// -----------------------------------------------------------------
$router->get('/api/notifications', 'Notifications::index')->middleware('auth');
$router->post('/api/notifications/read-all', 'Notifications::read_all')->middleware('auth');
$router->post('/api/notifications/{id}/read', 'Notifications::read')->middleware('auth');

// -----------------------------------------------------------------
// Browser preflight (only needed when the frontend is on another origin)
// -----------------------------------------------------------------
$router->options('/api/{a}', 'Public_api::preflight');
$router->options('/api/{a}/{b}', 'Public_api::preflight');
$router->options('/api/{a}/{b}/{c}', 'Public_api::preflight');
