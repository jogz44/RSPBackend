<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ApplicantApplicationRequest extends FormRequest
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
            'excel_file' => 'required|file|mimes:xlsx,xls,csv,xlsm',
            'zip_file' => 'required|file|mimes:zip',
            'job_batches_rsp_id' => 'required|exists:job_batches_rsp,id',
            'email' => 'required|email:rfc,dns'

        ];
    }
}
