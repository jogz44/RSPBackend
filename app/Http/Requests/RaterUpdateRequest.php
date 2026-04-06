<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RaterUpdateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'job_batches_rsp_id' => 'nullable|array',
            'job_batches_rsp_id.*' => 'exists:job_batches_rsp,id',
            'office' => 'required|string|max:255',
            'active' => 'required|boolean',
            'representative' => 'required|string|max:255',
            'role_type' => 'required|string|max:255',
        ];
    }
}
