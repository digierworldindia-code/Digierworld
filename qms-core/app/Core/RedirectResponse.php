<?php

declare(strict_types=1);

namespace App\Core;

/**
 * redirect()->to(url), redirect()->back(), ->withInput(), ->with('success', '…').
 */
final class RedirectResponse extends Response
{
    public function to(string $url, ?int $code = null): self
    {
        // Only absolute URLs of this application or local paths (no open redirect).
        if (! self::isLocal($url)) {
            $url = site_url('/');
        }
        $this->setStatusCode($code ?? (in_array(service('request')->getMethod(), ['POST', 'PUT', 'PATCH', 'DELETE'], true) ? 303 : 302));
        $this->setHeader('Location', $url);
        $this->body = '';

        return $this;
    }

    /** Back to the previous page of this application. */
    public function back(?int $code = null): self
    {
        return $this->to(previous_url(), $code);
    }

    /** Keeps the submitted form values for old() on the next page. */
    public function withInput(): self
    {
        $request = service('request');
        $post    = (array) $request->getPost();
        // Never keep passwords or tokens in the session.
        foreach (array_keys($post) as $key) {
            if (preg_match('/password|csrf|token/i', (string) $key)) {
                unset($post[$key]);
            }
        }
        session()->setFlashdata('_qms_old_input', ['get' => (array) $request->getGet(), 'post' => $post]);

        return $this;
    }

    public function with(string $key, mixed $message): self
    {
        session()->setFlashdata($key, $message);

        return $this;
    }

    private static function isLocal(string $url): bool
    {
        if (str_starts_with($url, '/') && ! str_starts_with($url, '//') && ! str_starts_with($url, '/\\')) {
            return true;
        }
        $base = parse_url(config('App')->baseURL);
        $to   = parse_url($url);
        if ($to === false || ! isset($to['host'], $base['host'])) {
            return false;
        }

        return strcasecmp($to['host'], $base['host']) === 0
            && ($to['port'] ?? null) === ($base['port'] ?? null)
            && in_array(strtolower($to['scheme'] ?? ''), ['http', 'https'], true);
    }
}
