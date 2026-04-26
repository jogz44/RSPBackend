<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RaterRegisterRequest extends FormRequest
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
            'name' => 'required|string|max:255',
            'username' => 'required|string|max:255|unique:users,username',
            'job_batches_rsp_id' => 'nullable|array',
            'job_batches_rsp_id.*' => 'exists:job_batches_rsp,id',
            'position' => 'required|string|max:255',
            'office' => 'required|string|max:255',
            'password' => 'required|string|min:5',

        ];
    }
}
