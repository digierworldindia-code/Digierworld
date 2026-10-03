<?php

namespace App\Controllers;

use App\Exceptions\QmsException;
use App\Exceptions\RecordLockedException;
use App\Exceptions\ValidationException;
use CodeIgniter\Controller;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * Controllers stay thin: read the request, call one service method, choose the
 * response. Business errors (QmsException) become flash messages or JSON with
 * the right HTTP status; unexpected errors are logged with the request id and
 * shown as a generic message.
 */
abstract class BaseController extends Controller
{
    /**
     * @return array<string, mixed>|null
     */
    protected function currentUser(): ?array
    {
        return service('auth')->user();
    }

    /**
     * Renders a view; the logged-in user is available to every layout.
     *
     * @param array<string, mixed> $data
     */
    protected function render(string $view, array $data = []): string
    {
        return view($view, $data + ['currentUser' => $this->currentUser()]);
    }

    /**
     * Runs a form action: success → redirect with a message; business error →
     * back to the form with input and errors.
     */
    protected function perform(callable $action, string|\Closure $successUrl, string $successMessage): RedirectResponse
    {
        try {
            $result = $action();
            $url    = $successUrl instanceof \Closure ? $successUrl($result) : $successUrl;

            return redirect()->to($url)->with('success', $successMessage);
        } catch (Throwable $e) {
            return $this->backWithError($e);
        }
    }

    protected function backWithError(Throwable $e): RedirectResponse
    {
        $e = $this->translate($e);

        $redirect = redirect()->back()->withInput()->with('error', $e->getMessage());
        if ($e instanceof ValidationException) {
            $redirect->with('errors', $e->errors());
        }

        return $redirect;
    }

    /**
     * JSON error response with the mapped status code.
     */
    protected function jsonError(Throwable $e): ResponseInterface
    {
        $e    = $this->translate($e);
        $body = ['ok' => false, 'error' => $e->getMessage()];
        if ($e instanceof ValidationException) {
            $body['errors'] = $e->errors();
        }

        return $this->response->setStatusCode($e instanceof QmsException ? $e->httpStatus() : 500)->setJSON($body);
    }

    /**
     * Maps database trigger / constraint errors to user-safe business errors.
     */
    protected function translate(Throwable $e): QmsException
    {
        if ($e instanceof QmsException) {
            return $e;
        }

        $message = $e->getMessage();
        if ($e instanceof DatabaseException) {
            if (str_contains($message, 'QMS-LOCK:')) {
                return new RecordLockedException(trim(substr($message, strpos($message, 'QMS-LOCK:') + 9)));
            }
            if ((int) $e->getCode() === 1062) {
                return new ValidationException([], 'This record already exists (duplicate code or number).');
            }
            if ((int) $e->getCode() === 3819) {
                return new ValidationException([], 'The values break a data rule (for example LSL greater than USL).');
            }
            if (in_array((int) $e->getCode(), [1451, 1452], true)) {
                return new ValidationException([], 'This record is still referenced by other data and cannot be changed this way.');
            }
        }

        $reference = service('requestContext')->requestId();
        log_message('error', '[{ref}] {class}: {message} at {file}:{line}', [
            'ref' => $reference, 'class' => $e::class, 'message' => $message, 'file' => $e->getFile(), 'line' => $e->getLine(),
        ]);

        return new class ("Something went wrong. Reference: {$reference}") extends QmsException {
            protected int $httpStatus = 500;
        };
    }

    /**
     * Trimmed string input (null when missing).
     */
    protected function input(string $key): ?string
    {
        $value = $this->request->getPost($key);

        return is_string($value) ? trim($value) : null;
    }
}
