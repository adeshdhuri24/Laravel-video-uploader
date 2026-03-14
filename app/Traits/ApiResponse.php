<?php

namespace App\Traits;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

trait ApiResponse
{
    protected const HTTP_OK = 200;

    protected const HTTP_CREATED = 201;

    protected const HTTP_NO_CONTENT = 204;

    protected const HTTP_BAD_REQUEST = 400;

    protected const HTTP_UNPROCESSABLE_ENTITY = 422;

    protected function success(mixed $data = null, string $message = '', int $status = self::HTTP_OK): JsonResponse
    {
        $payload = ['success' => true];

        if ($message !== '') {
            $payload['message'] = $message;
        }

        if ($data !== null) {
            $payload['data'] = $data;
        }

        return response()->json($payload, $status);
    }

    protected function created(mixed $data = null, string $message = ''): JsonResponse
    {
        return $this->success($data, $message, self::HTTP_CREATED);
    }

    protected function error(string $message, int $status = self::HTTP_BAD_REQUEST, array $errors = []): JsonResponse
    {
        $payload = [
            'success' => false,
            'message' => $message,
        ];

        if (! empty($errors)) {
            $payload['errors'] = $errors;
        }

        return response()->json($payload, $status);
    }

    protected function noContent(): Response
    {
        return response()->noContent(self::HTTP_NO_CONTENT);
    }
}
