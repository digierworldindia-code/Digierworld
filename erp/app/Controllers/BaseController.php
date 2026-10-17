<?php

namespace App\Controllers;

use App\Exceptions\AccessDeniedException;
use App\Exceptions\BusinessRuleException;
use App\Services\Security\Authorization;
use CodeIgniter\Controller;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Shared controller plumbing. Every action must call authorize()/scope
 * checks in addition to the route `perm:` filter (defence in depth).
 *
 * @property IncomingRequest $request
 */
abstract class BaseController extends Controller
{
    protected Authorization $authz;

    protected BaseConnection $db;

    protected ?int $userId = null;

    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);

        $this->authz  = service('authz');
        $this->db     = db_connect();
        $this->userId = $this->authz->currentUserId();
    }

    /** @param list<string>|string $perms any-of */
    protected function authorize(array|string $perms): void
    {
        $this->authz->authorize($perms);
    }

    /** @param list<string>|string $perms */
    protected function can(array|string $perms): bool
    {
        return $this->authz->canAny((array) $perms);
    }

    /**
     * Render a page inside the main layout.
     *
     * @param array<string, mixed> $data
     */
    protected function render(string $view, array $data = []): string
    {
        $data['pageTitle'] ??= 'DGR Office';
        $data['breadcrumbs'] ??= [];

        return view($view, $data);
    }

    /** @param array<string, mixed> $payload */
    protected function json(array $payload, int $status = 200): ResponseInterface
    {
        return $this->response->setStatusCode($status)->setJSON($payload);
    }

    protected function wantsJson(): bool
    {
        return $this->request->isAJAX() || str_contains($this->request->getHeaderLine('Accept'), 'application/json');
    }

    /** @param array<string, string> $errors */
    protected function backWithErrors(array $errors, ?string $message = null): RedirectResponse
    {
        return redirect()->back()->withInput()
            ->with('errors', $errors)
            ->with('error', $message ?? 'Please correct the highlighted fields.');
    }

    protected function backWithRuleError(BusinessRuleException $e): RedirectResponse|ResponseInterface
    {
        if ($this->wantsJson()) {
            return $this->json(['ok' => false, 'message' => $e->getMessage(), 'errors' => $e->errors()], 422);
        }

        return redirect()->back()->withInput()->with('errors', $e->errors())->with('error', $e->getMessage());
    }

    /** 404 that does not reveal whether the record exists outside the caller's scope. */
    protected function notFound(): never
    {
        throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound('Record not found or not accessible.');
    }

    protected function deny(): never
    {
        throw new AccessDeniedException();
    }

    /** Validated integer id from route segment. */
    protected function idOr404(mixed $id): int
    {
        if (! is_numeric($id) || (int) $id <= 0) {
            $this->notFound();
        }

        return (int) $id;
    }

    /** Page/limit helper for list screens. @return array{0: int, 1: int, 2: int} [page, perPage, offset] */
    protected function paging(int $default = 25): array
    {
        $page    = max(1, (int) ($this->request->getGet('page') ?? 1));
        $perPage = (int) ($this->request->getGet('per_page') ?? $default);
        $perPage = in_array($perPage, [10, 25, 50, 100], true) ? $perPage : $default;

        return [$page, $perPage, ($page - 1) * $perPage];
    }

    protected function post(string $key, mixed $default = null): mixed
    {
        $v = $this->request->getPost($key);

        return $v === null || $v === '' ? $default : (is_string($v) ? trim($v) : $v);
    }
}
