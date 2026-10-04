<?php

namespace App\Controllers\Inspections;

use App\Controllers\BaseController;
use Throwable;

/**
 * Workflow transitions. Submit is sent by the entry screen as JSON (so pending
 * autosaves are flushed first); the signature dialogs post normal forms.
 * Both carry an Idempotency-Key and the status the user saw.
 */
class WorkflowController extends BaseController
{
    public function submit(int $id)
    {
        $json = $this->wantsJson();
        $data = $json ? (array) ($this->safeJson() ?? []) : (array) $this->request->getPost();

        try {
            $result = service('workflow')->submit($id, (string) ($data['seen_status'] ?? ''), $this->idempotencyKey($data), $this->currentUser());
        } catch (Throwable $e) {
            return $json ? $this->jsonError($e) : $this->backWithError($e);
        }

        $url = site_url('inspections/' . $result['report_id']);
        if ($json) {
            session()->setFlashdata('success', $result['message']);

            return $this->response->setJSON($result + ['redirect' => $url]);
        }

        return redirect()->to($url)->with('success', $result['message']);
    }

    public function act(int $id)
    {
        return $this->perform(
            fn (): array => service('workflow')->act(
                $id,
                (string) $this->input('action'),
                (string) $this->input('remarks'),
                (string) $this->request->getPost('password'),
                (string) $this->input('seen_status'),
                $this->idempotencyKey((array) $this->request->getPost()),
                $this->currentUser(),
            ),
            site_url('inspections/' . $id),
            'Signature recorded.',
            true,
        );
    }

    public function cancel(int $id)
    {
        return $this->perform(
            fn (): array => service('workflow')->cancel($id, (string) $this->input('remarks'), (string) $this->input('seen_status'), $this->currentUser()),
            site_url('inspections/' . $id),
            'Report cancelled.',
            true,
        );
    }

    public function revise(int $id)
    {
        return $this->perform(
            fn (): array => service('workflow')->revise($id, (string) $this->input('remarks'), $this->idempotencyKey((array) $this->request->getPost()), $this->currentUser()),
            static fn (array $result): string => site_url('inspections/' . $result['report_id'] . '/edit'),
            'Revision created.',
            true,
        );
    }

    /**
     * perform() with the service's own message.
     */
    protected function perform(callable $action, string|\Closure $successUrl, string $successMessage, bool $useResultMessage = false): \App\Core\RedirectResponse
    {
        try {
            $result = $action();
        } catch (Throwable $e) {
            return $this->backWithError($e);
        }
        $url     = $successUrl instanceof \Closure ? $successUrl($result) : $successUrl;
        $message = $useResultMessage && is_array($result) && isset($result['message']) ? (string) $result['message'] : $successMessage;

        return redirect()->to($url)->with('success', $message);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function idempotencyKey(array $data): ?string
    {
        $key = trim($this->request->getHeaderLine('Idempotency-Key'));
        if ($key === '') {
            $key = trim((string) ($data['idempotency_key'] ?? ''));
        }

        return $key === '' ? null : $key;
    }

    private function wantsJson(): bool
    {
        return str_contains($this->request->getHeaderLine('Content-Type'), 'application/json');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function safeJson(): ?array
    {
        try {
            $data = $this->request->getJSON(true);
        } catch (Throwable) {
            return null;
        }

        return is_array($data) ? $data : null;
    }
}
