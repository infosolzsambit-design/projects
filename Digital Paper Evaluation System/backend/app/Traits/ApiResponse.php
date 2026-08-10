<?php

namespace App\Traits;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;

/**
 * One consistent response envelope for every API endpoint.
 */
trait ApiResponse
{
    public function success(mixed $data = null, string $message = 'Request successful.', int $status = 200): JsonResponse
    {
        return response()->json([
            'status' => true,
            'message' => $message,
            'data' => $data,
        ], $status);
    }

    /**
     * Wrap a paginator into the standard { items, pagination } shape.
     */
    public function paginated(LengthAwarePaginator $paginator, string $message = 'Records retrieved successfully.', mixed $items = null): JsonResponse
    {
        return $this->success([
            'items' => $items ?? $paginator->items(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ], $message);
    }

    public function error(string $message = 'Something went wrong.', int $status = 400, mixed $errors = null): JsonResponse
    {
        $payload = [
            'status' => false,
            'message' => $message,
        ];

        if (! is_null($errors)) {
            $payload['errors'] = $errors;
        }

        return response()->json($payload, $status);
    }

    public function validationError(mixed $errors, string $message = 'Validation failed.'): JsonResponse
    {
        return $this->error($message, 422, $errors);
    }

    public function notFound(string $message = 'Resource not found.'): JsonResponse
    {
        return $this->error($message, 404);
    }

    public function unauthorized(string $message = 'Unauthenticated.'): JsonResponse
    {
        return $this->error($message, 401);
    }

    public function forbidden(string $message = 'This action is unauthorized.'): JsonResponse
    {
        return $this->error($message, 403);
    }
}
