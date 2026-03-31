<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EmployeeStoreApplicationRequest extends FormRequest
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
            'ControlNo'           => 'required|string',
            'job_batches_rsp_id'  => 'required|exists:job_batches_rsp,id',
            'images'              => 'nullable|array',
            'images.education'    => 'nullable|array|min:1',
            'images.education.*'  => 'file|mimes:jpg,jpeg,png,pdf|max:5120',
            'images.training'     => 'nullable|array|min:1',
            'images.training.*'   => 'file|mimes:jpg,jpeg,png,pdf|max:5120',
            'images.experience'   => 'nullable|array',
            'images.experience.*' => 'file|mimes:jpg,jpeg,png,pdf|max:5120',
            'images.eligibility'   => 'nullable|array',
            'images.eligibility.*' => 'file|mimes:jpg,jpeg,png,pdf|max:5120',
        ];
    }
}
