<?php

namespace App\Controllers\Warehouse;

use App\Controllers\BaseController;

/**
 * Shared by the warehouse panel and the field-agent panel.
 *
 * Both are phone screens used standing up, so they share a layout and a few
 * habits: no caching of a page whose numbers are the point, a back link that
 * always goes somewhere sensible, and JSON replies for the scanner that carry
 * a fresh CSRF token, because the application rotates it on every request and
 * a scanner that did not pass the new one back would work exactly once.
 */
abstract class FloorController extends BaseController
{
    /**
     * `back` is where the layout's back arrow goes. It is passed from here
     * rather than decided in the view: a view rendered through extend() runs
     * in its own scope, so anything the layout needs has to arrive as data.
     */
    protected function render(string $view, string $title, array $data = [], ?string $back = null): string
    {
        $this->response->setHeader('Cache-Control', 'no-store');

        return view($view, $data + ['title' => $title, 'ctx' => $this->ctx, 'back' => $back]);
    }

    /**
     * A scanner reply. The token is minted after the request that is being
     * answered, so it is the one the next scan must send.
     *
     * @param array<string,mixed> $payload
     */
    protected function scanned(array $payload): \CodeIgniter\HTTP\ResponseInterface
    {
        return $this->response
            ->setHeader('Cache-Control', 'no-store')
            ->setJSON($payload + ['csrf' => csrf_hash()]);
    }

    /**
     * A scanner reply for something that went wrong before a scan could even
     * be judged. Shaped exactly like a scan result so the screen has one way
     * of showing bad news.
     */
    protected function scanFailed(string $title, string $message): \CodeIgniter\HTTP\ResponseInterface
    {
        return $this->scanned([
            'ok' => false, 'status' => 'REJECTED', 'title' => $title, 'message' => $message,
        ]);
    }

    /** The code a scanner posted, bounded: a QR payload is short or it is not ours. */
    protected function scannedCode(): string
    {
        return mb_substr(trim((string) $this->request->getPost('code')), 0, 300);
    }

    protected function scanSource(): string
    {
        return $this->request->getPost('source') === 'MANUAL' ? 'MANUAL' : 'CAMERA';
    }

    protected function post(string $name, int $max = 2000): string
    {
        return mb_substr(trim((string) $this->request->getPost($name)), 0, $max);
    }
}
