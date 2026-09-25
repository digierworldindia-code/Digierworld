<?php

namespace App\Controllers;

use App\Exceptions\AppException;
use App\Libraries\RequestContext;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Shared by every controller.
 *
 * Controllers stay thin: they read and validate input, call a service, and
 * choose what to show. Business rules live in app/Services; SQL never appears
 * in a view.
 */
abstract class BaseController extends Controller
{
    /** @var \CodeIgniter\HTTP\IncomingRequest */
    protected $request;

    protected RequestContext $ctx;

    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);
        $this->ctx = service('requestContext');
    }

    /**
     * Runs a state-changing action and turns the outcome into a redirect with a
     * message. A rule the person broke (AppException) comes back as a readable
     * error on the form they were using; anything else is a fault, logged in
     * full and reported generically.
     */
    protected function act(callable $action, string|callable $successMessage, ?string $successUrl = null): RedirectResponse
    {
        try {
            $result = $action();
        } catch (AppException $e) {
            if ($e->internal() !== null) {
                log_message('info', 'refused: {why}', ['why' => $e->internal()]);
            }

            return redirect()->back()->withInput()->with('error', $e->getMessage());
        } catch (Throwable $e) {
            log_message('error', '[{rid}] {class}: {msg} at {file}:{line}', [
                'rid' => $this->ctx->requestId, 'class' => $e::class, 'msg' => $e->getMessage(),
                'file' => $e->getFile(), 'line' => $e->getLine(),
            ]);

            return redirect()->back()->withInput()->with('error', 'Something went wrong. Reference ' . $this->ctx->requestId . ' — quote it if you contact support.');
        }

        $message = is_string($successMessage) ? $successMessage : $successMessage($result);
        $target  = $successUrl !== null ? redirect()->to($successUrl) : redirect()->back();

        return $target->with('success', $message);
    }

    /**
     * Validates the request. On failure, returns to the form with every
     * field's message; on success returns only the validated fields.
     *
     * @param array<string,string|array> $rules
     *
     * @return array<string,mixed>|RedirectResponse
     */
    protected function validated(array $rules, array $messages = []): array|RedirectResponse
    {
        if (! $this->validate($rules, $messages)) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors())
                ->with('error', 'Please check the highlighted fields.');
        }

        return $this->validator->getValidated();
    }

    /** A page-not-found that never reveals whether something exists but is not yours. */
    protected function notFound(): never
    {
        throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
    }
}
