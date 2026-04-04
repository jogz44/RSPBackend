<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InterviewApplicantStoreRequest extends FormRequest
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

            'applicants' => 'required|array',
            'applicants.*.submission_id' => 'required|exists:submission,id',
            'applicants.*.job_batches_rsp' => 'required|exists:job_batches_rsp,id',
            'date_interview' => 'required|date',
            'time_interview' => 'required|string',
            'venue_interview' => 'required|string',
            'batch_name' => 'required|string',
      

        ];
    }
}
