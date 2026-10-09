<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Exceptions\BusinessRuleException;
use CodeIgniter\Controller;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\CLIRequest;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Base controller: thin controllers delegate business rules to services.
 */
abstract class BaseController extends Controller
{
    /** @var CLIRequest|IncomingRequest */
    protected $request;

    protected $helpers = ['app', 'ui', 'form', 'url', 'text'];

    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);
    }

    protected function userId(): int
    {
        return (int) auth()->id();
    }

    protected function company(): ?array
    {
        return service('companyContext')->company();
    }

    /**
     * Runs a state-changing action; business-rule violations are shown to the
     * user and the form input is preserved.
     */
    protected function attempt(callable $fn, string $success, ?string $redirectTo = null): RedirectResponse
    {
        try {
            $result = $fn();
        } catch (BusinessRuleException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }
        if ($result instanceof RedirectResponse) {
            return $result->with('success', $success);
        }

        return ($redirectTo !== null ? redirect()->to(site_url($redirectTo)) : redirect()->back())->with('success', $success);
    }

    /**
     * Validates the request; on failure redirects back with errors + input.
     */
    protected function invalid(array $rules, array $messages = []): ?RedirectResponse
    {
        if (! $this->validate($rules, $messages)) {
            return redirect()->back()->withInput()->with('error', 'Please correct the highlighted fields.');
        }

        return null;
    }

    protected function notFound(string $message = 'Page not found'): never
    {
        throw PageNotFoundException::forPageNotFound($message);
    }

    /**
     * Loads a record and ensures it belongs to the current company.
     * Logs a security event and returns 404 otherwise (TEST 8).
     */
    protected function owned(object $model, int $id, string $column = 'company_id'): array
    {
        $row = $model->find($id);
        $cid = service('companyContext')->companyId();
        if (! $row || $cid === null || (int) $row[$column] !== $cid) {
            service('audit')->security('access.denied', sprintf('User #%d (company #%s) tried to open %s #%d', $this->userId(), $cid ?? '-', $model->table ?? get_class($model), $id), [
                'entity_type' => $model->table ?? null, 'entity_id' => $id, 'company_id' => $cid,
            ]);

            throw PageNotFoundException::forPageNotFound();
        }

        return $row;
    }

    protected function render(string $view, array $data = []): string
    {
        return view($view, $data);
    }
}
