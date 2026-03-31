<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateExamScoreApplicantRequest extends FormRequest
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

            'exam_score' => 'required|numeric',
            'exam_details' => 'required|string',
            'exam_type' => 'required|string',
            'exam_total_score' => 'required|integer',
            'exam_date' => 'nullable|string',
            'exam_remarks' => 'nullable|string',


        ];
    }
}
