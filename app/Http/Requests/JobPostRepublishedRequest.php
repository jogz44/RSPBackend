<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class JobPostRepublishedRequest extends FormRequest
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
            // ✅ Step 1: Validate Job Batch fields

            'Office' => 'required|string',
            'Office2' => 'nullable|string',
            'Group' => 'nullable|string',
            'Division' => 'nullable|string',
            'Section' => 'nullable|string',
            'Unit' => 'nullable|string',
            'Position' => 'required|string',
            'PositionID' => 'nullable|integer',
            'isOpen' => 'boolean',
            'post_date' => 'required|date',
            'end_date' => 'required|date',
            'PageNo' => 'required|string',
            'ItemNo' => 'required|string',
            'SalaryGrade' => 'nullable|string',
            'salaryMin' => 'nullable|string',
            'salaryMax' => 'nullable|string',
            'level' => 'nullable|string',
            'tblStructureDetails_ID' => 'required|string',
            'old_job_id' => 'required|integer',


        // ✅ Step 2: Validate criteria fields

            'Education' => 'nullable|string',
            'Eligibility' => 'nullable|string',
            'Training' => 'nullable|string',
            'Experience' => 'nullable|string',

        ];
    }
}
