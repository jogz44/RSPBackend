<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AddExamScoreApplicantRequest extends FormRequest
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
            // 'submission_id' => 'required|exists:submission,id',
            // 'exam_score' => 'required|numeric',
            // 'exam_details' => 'required|string',
            // 'exam_type' => 'required|string',
            // 'exam_total_score' => 'required|integer',
            // 'exam_date' => 'required|string',
            // 'exam_remarks' => 'required|string',
            'applicants'                        => 'required|array|min:1',
            'applicants.*.submission_id'        => 'required|exists:submission,id',
            'applicants.*.exam_score'           => 'required|numeric',
            'applicants.*.exam_details'         => 'required|string',
            'applicants.*.exam_type'            => 'required|string',
            'applicants.*.exam_total_score'     => 'required|integer',
            'applicants.*.exam_date'            => 'required|string',
            'applicants.*.exam_remarks'         => 'nullable|string',
        ];
    }
}
