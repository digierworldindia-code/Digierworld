<?php

namespace App\Libraries;

use App\Exceptions\BusinessRuleException;
use CodeIgniter\Debug\ExceptionHandlerInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * Shows a BusinessRuleException that no controller caught as the readable
 * message it was written to be, instead of the generic 500 page: a form post
 * goes back to the form with its input, a script call gets JSON, a page or
 * download gets the themed error page. The exception is still logged by the
 * framework before this runs; every other exception keeps the default handler.
 */
class BusinessRuleExceptionHandler implements ExceptionHandlerInterface
{
    public function handle(Throwable $exception, RequestInterface $request, ResponseInterface $response, int $statusCode, int $exitCode): void
    {
        $message = $exception->getMessage();
        $errors  = $exception instanceof BusinessRuleException ? $exception->errors() : [];

        if ($request instanceof IncomingRequest && ($request->isAJAX() || str_contains($request->getHeaderLine('Accept'), 'application/json'))) {
            $response->setStatusCode(422)->setJSON(['ok' => false, 'message' => $message, 'errors' => $errors])->send();

            return;
        }
        if ($request instanceof IncomingRequest && $request->getMethod() === 'POST') {
            redirect()->back()->withInput()->with('error', $message)->with('errors', $errors)->send();

            return;
        }

        $errCode  = 422;
        $errTitle = 'This could not be completed';
        $errText  = $message;
        ob_start();
        include APPPATH . 'Views/errors/html/_themed.php';
        $response->setStatusCode(422)->setBody((string) ob_get_clean())->send();
    }
}
