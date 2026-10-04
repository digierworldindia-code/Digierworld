<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Base for all controllers: access to the request, the response and the
 * request-level validator.
 */
abstract class Controller
{
    protected Request $request;

    protected Response $response;

    protected ?Validator $validator = null;

    public function initController(Request $request, Response $response): void
    {
        $this->request  = $request;
        $this->response = $response;
    }

    /**
     * Validates the POST data (and uploaded files) against rules.
     *
     * @param array<string, string> $rules
     */
    protected function validate(array $rules): bool
    {
        return $this->validateData((array) $this->request->getPost(), $rules);
    }

    /**
     * @param array<string, mixed>  $data
     * @param array<string, string> $rules
     */
    protected function validateData(array $data, array $rules): bool
    {
        $this->validator = new Validator();

        return $this->validator->run($data, $rules, $this->request);
    }
}
