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
    // Recruitment: vacancies & JD versions
    $routes->get('vacancies', 'Hr\VacanciesController::index', ['filter' => 'perm:hr.vacancy.view']);
    $routes->get('vacancies/new', 'Hr\VacanciesController::new', ['filter' => 'perm:hr.vacancy.manage']);
    $routes->post('vacancies', 'Hr\VacanciesController::create', ['filter' => 'perm:hr.vacancy.manage']);
    $routes->get('vacancies/(:num)', 'Hr\VacanciesController::show/$1', ['filter' => 'perm:hr.vacancy.view']);
    $routes->get('vacancies/(:num)/edit', 'Hr\VacanciesController::edit/$1', ['filter' => 'perm:hr.vacancy.manage']);
    $routes->post('vacancies/(:num)', 'Hr\VacanciesController::update/$1', ['filter' => 'perm:hr.vacancy.manage']);
    $routes->post('vacancies/(:num)/versions', 'Hr\VacanciesController::addVersion/$1', ['filter' => 'perm:hr.vacancy.manage']);

    // Applicants
    $routes->get('candidates', 'Hr\CandidatesController::index', ['filter' => 'perm:hr.candidate.view,hr.candidate.view_all']);
    $routes->get('candidates/board', 'Hr\CandidatesController::board', ['filter' => 'perm:hr.candidate.view,hr.candidate.view_all']);
    $routes->get('candidates/export', 'Hr\CandidatesController::export', ['filter' => 'perm:hr.candidate.export']);
    $routes->get('candidates/new', 'Hr\CandidatesController::new', ['filter' => 'perm:hr.candidate.create']);
    $routes->post('candidates', 'Hr\CandidatesController::create', ['filter' => 'perm:hr.candidate.create']);
    $routes->get('candidates/(:num)', 'Hr\CandidatesController::show/$1', ['filter' => 'perm:hr.candidate.view,hr.candidate.view_all']);
    $routes->get('candidates/(:num)/edit', 'Hr\CandidatesController::edit/$1', ['filter' => 'perm:hr.candidate.edit']);
    $routes->post('candidates/(:num)', 'Hr\CandidatesController::update/$1', ['filter' => 'perm:hr.candidate.edit']);
    $routes->post('candidates/(:num)/cv', 'Hr\CandidatesController::uploadCv/$1', ['filter' => 'perm:hr.candidate.create,hr.candidate.edit']);
    $routes->post('candidates/(:num)/status', 'Hr\CandidatesController::status/$1', ['filter' => 'perm:hr.candidate.edit,hr.candidate.decide']);
    $routes->post('candidates/(:num)/score', 'Hr\CandidatesController::score/$1', ['filter' => 'perm:hr.candidate.score']);
    $routes->post('candidates/(:num)/notes', 'Hr\CandidatesController::note/$1', ['filter' => 'perm:hr.timeline.create']);
    $routes->post('candidates/(:num)/interviews', 'Hr\CandidatesController::interview/$1', ['filter' => 'perm:hr.interview.manage']);
    $routes->post('candidates/(:num)/convert', 'Hr\CandidatesController::convert/$1', ['filter' => 'perm:hr.candidate.convert']);
    $routes->post('candidates/(:num)/evaluate', 'Hr\CandidatesController::evaluate/$1', ['filter' => 'perm:hr.candidate.score']);
    $routes->post('candidates/(:num)/evaluations/(:num)/review', 'Hr\CandidatesController::review/$1/$2', ['filter' => 'perm:hr.candidate.score']);
    $routes->post('candidates/(:num)/proposals/(:num)', 'Hr\CandidatesController::proposal/$1/$2', ['filter' => 'perm:hr.timeline.create']);

    // Interviews
    $routes->get('interviews', 'Hr\InterviewsController::index', ['filter' => 'perm:hr.interview.manage']);
    $routes->post('interviews/(:num)/complete', 'Hr\InterviewsController::complete/$1', ['filter' => 'perm:hr.interview.manage']);
    // Payroll
    $routes->get('payroll', 'Hr\PayrollController::index', ['filter' => 'perm:hr.payroll.view']);
    $routes->post('payroll', 'Hr\PayrollController::create', ['filter' => 'perm:hr.payroll.calculate']);
    $routes->get('payroll/statutory', 'Hr\PayrollController::statutory', ['filter' => 'perm:hr.payroll.statutory']);
    $routes->post('payroll/statutory/(:segment)', 'Hr\PayrollController::saveStatutory/$1', ['filter' => 'perm:hr.payroll.statutory']);
    $routes->post('payroll/advances', 'Hr\PayrollController::advance', ['filter' => 'perm:hr.payroll.pay']);
    $routes->get('payroll/(:num)', 'Hr\PayrollController::show/$1', ['filter' => 'perm:hr.payroll.view']);
    $routes->post('payroll/(:num)/calculate', 'Hr\PayrollController::calculate/$1', ['filter' => 'perm:hr.payroll.calculate']);
    $routes->post('payroll/(:num)/finalize', 'Hr\PayrollController::finalize/$1', ['filter' => 'perm:hr.payroll.finalize']);
    $routes->post('payroll/(:num)/delete', 'Hr\PayrollController::delete/$1', ['filter' => 'perm:hr.payroll.calculate']);
    $routes->post('payroll/(:num)/pay', 'Hr\PayrollController::pay/$1', ['filter' => 'perm:hr.payroll.pay']);
    $routes->get('payroll/(:num)/register', 'Hr\PayrollController::register/$1', ['filter' => 'perm:hr.salary.export']);
    $routes->get('payroll/items/(:num)', 'Hr\PayrollController::item/$1', ['filter' => 'perm:hr.payroll.view']);
    $routes->get('payroll/items/(:num)/preview', 'Hr\PayrollController::preview/$1', ['filter' => 'perm:hr.payslip.view_all']);
    $routes->post('payroll/items/(:num)/lines', 'Hr\PayrollController::addLine/$1', ['filter' => 'perm:hr.payroll.calculate']);
    $routes->post('payroll/lines/(:num)/delete', 'Hr\PayrollController::removeLine/$1', ['filter' => 'perm:hr.payroll.calculate']);
    $routes->post('payroll/items/(:num)/adjust', 'Hr\PayrollController::adjust/$1', ['filter' => 'perm:hr.payroll.adjust']);
    $routes->post('payroll/items/(:num)/pay', 'Hr\PayrollController::payItem/$1', ['filter' => 'perm:hr.payroll.pay']);

    // Incentives
    $routes->get('incentives', 'Hr\IncentivesController::index', ['filter' => 'perm:hr.incentive.view_all,hr.incentive.policy_manage']);
    $routes->post('incentives/generate', 'Hr\IncentivesController::generate', ['filter' => 'perm:hr.incentive.approve,hr.incentive.policy_manage']);
    $routes->post('incentives/approve', 'Hr\IncentivesController::bulkApprove', ['filter' => 'perm:hr.incentive.approve']);
    $routes->post('incentives/manual', 'Hr\IncentivesController::manual', ['filter' => 'perm:hr.incentive.approve']);
    $routes->get('incentives/policies', 'Hr\IncentivesController::policies', ['filter' => 'perm:hr.incentive.policy_manage']);
    $routes->post('incentives/policies', 'Hr\IncentivesController::savePolicy', ['filter' => 'perm:hr.incentive.policy_manage']);
    $routes->post('incentives/policies/(:num)', 'Hr\IncentivesController::savePolicy/$1', ['filter' => 'perm:hr.incentive.policy_manage']);
    $routes->post('incentives/(:num)/decide', 'Hr\IncentivesController::decide/$1', ['filter' => 'perm:hr.incentive.approve']);
    $routes->post('incentives/(:num)/split', 'Hr\IncentivesController::split/$1', ['filter' => 'perm:hr.incentive.approve']);
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
