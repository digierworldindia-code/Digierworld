<?php

use CodeIgniter\Router\RouteCollection;

/**
 * HR, recruitment, payroll and employee self-service routes.
 * Salary, bank and private-document endpoints carry their own permissions;
 * controllers re-check every permission and record scope.
 *
 * @var RouteCollection $routes
 */
$routes->group('hr', static function (RouteCollection $routes): void {
    // Employees
    $routes->get('employees', 'Hr\EmployeesController::index', ['filter' => 'perm:hr.employee.view_basic,hr.employee.view_all']);
    $routes->get('employees/new', 'Hr\EmployeesController::new', ['filter' => 'perm:hr.employee.manage']);
    $routes->post('employees', 'Hr\EmployeesController::create', ['filter' => 'perm:hr.employee.manage']);
    $routes->get('employees/(:num)', 'Hr\EmployeesController::show/$1', ['filter' => 'perm:hr.employee.view_basic,hr.employee.view_all']);
    $routes->get('employees/(:num)/edit', 'Hr\EmployeesController::edit/$1', ['filter' => 'perm:hr.employee.manage']);
    $routes->post('employees/(:num)', 'Hr\EmployeesController::update/$1', ['filter' => 'perm:hr.employee.manage']);
    $routes->post('employees/(:num)/bank', 'Hr\EmployeesController::saveBank/$1', ['filter' => 'perm:hr.employee.bank_view']);
    $routes->post('employees/(:num)/bank/reveal', 'Hr\EmployeesController::revealBank/$1', ['filter' => 'perm:hr.employee.bank_view']);
    $routes->post('employees/(:num)/documents', 'Hr\EmployeesController::uploadDocument/$1', ['filter' => 'perm:hr.employee.private_docs']);
    $routes->post('employees/(:num)/salary', 'Hr\EmployeesController::saveSalary/$1', ['filter' => 'perm:hr.salary.manage']);

    // Attendance
    $routes->get('attendance', 'Hr\AttendanceController::index', ['filter' => 'perm:hr.attendance.mark,hr.attendance.view_all']);
    $routes->post('attendance', 'Hr\AttendanceController::save', ['filter' => 'perm:hr.attendance.mark']);
    $routes->get('attendance/register', 'Hr\AttendanceController::month', ['filter' => 'perm:hr.attendance.view_all']);

    // Leave
    $routes->get('leaves', 'Hr\LeavesController::index', ['filter' => 'perm:hr.leave.view_all,hr.leave.approve']);
    $routes->post('leaves', 'Hr\LeavesController::create', ['filter' => 'perm:hr.leave.approve']);
    $routes->get('leaves/balances', 'Hr\LeavesController::balances', ['filter' => 'perm:hr.leave.view_all']);
    $routes->post('leaves/adjust', 'Hr\LeavesController::adjust', ['filter' => 'perm:hr.leave.manage_types']);
    $routes->post('leaves/(:num)/decide', 'Hr\LeavesController::decide/$1', ['filter' => 'perm:hr.leave.approve']);
    $routes->post('leaves/(:num)/cancel', 'Hr\LeavesController::cancel/$1', ['filter' => 'perm:hr.leave.approve']);

    // Holidays & weekly offs
    $routes->get('holidays', 'Hr\HolidaysController::index', ['filter' => 'perm:hr.holiday.manage']);
    $routes->post('holidays', 'Hr\HolidaysController::save', ['filter' => 'perm:hr.holiday.manage']);
    $routes->post('holidays/(:num)/delete', 'Hr\HolidaysController::delete/$1', ['filter' => 'perm:hr.holiday.manage']);
    $routes->post('holidays/rules', 'Hr\HolidaysController::saveRule', ['filter' => 'perm:hr.holiday.manage']);
    $routes->post('holidays/rules/(:num)/toggle', 'Hr\HolidaysController::toggleRule/$1', ['filter' => 'perm:hr.holiday.manage']);
});

// ---- Employee self-service (always the caller's own employee record) ----
$routes->group('me', static function (RouteCollection $routes): void {
    $routes->get('/', 'MeController::index', ['filter' => 'perm:self.profile.view,self.attendance.view,self.leave.request,self.payslip.view,self.incentive.view']);
    $routes->post('punch', 'MeController::punch', ['filter' => 'perm:self.attendance.checkin']);
    $routes->get('attendance', 'MeController::attendance', ['filter' => 'perm:self.attendance.view']);
    $routes->get('leaves', 'MeController::leaves', ['filter' => 'perm:self.leave.request']);
    $routes->post('leaves', 'MeController::requestLeave', ['filter' => 'perm:self.leave.request']);
    $routes->post('leaves/(:num)/cancel', 'MeController::cancelLeave/$1', ['filter' => 'perm:self.leave.request']);
    $routes->get('payslips', 'MeController::payslips', ['filter' => 'perm:self.payslip.view']);
    $routes->get('incentives', 'MeController::incentives', ['filter' => 'perm:self.incentive.view']);
});
