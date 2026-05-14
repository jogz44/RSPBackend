<?php

namespace App\Traits;

trait ApiResponseTrait
{
    protected function successMessage($data = null, string $message = 'Success', int $code = 200)
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data'    => $data,
        ], $code);
    }

    protected function errorMessage(string $message = 'Something went wrong', int $code = 500, $errors = null)
    {
        $response = [
            'success' => false,
            'message' => $message,
        ];

        if ($errors !== null) {
            $response['errors'] = $errors;
        }

        return response()->json($response, $code);
    }

    // informational responses — no data, not an error
    protected function infoMessage(string $message = 'No data available.', int $code = 200)
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data'    => null,
        ], $code);
    }
}
