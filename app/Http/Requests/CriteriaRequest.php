<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CriteriaRequest extends FormRequest
{

    public function authorize(): bool
    {
        return true;
    }


    public function rules(): array
    {
        return [
            'job_batches_rsp_id' => 'required|integer', // not array
            // 'job_batches_rsp_id' => 'required|array',
            'job_batches_rsp_id.*' => 'exists:job_batches_rsp,id',


            'education' => 'required|array|min:1',
            'education.*.weight' => 'required|string',
            'education.*.description' => 'required|string',
            'education.*.percentage' => 'required|integer',



            'experience' => 'required|array|min:1',
            'experience.*.weight' => 'required|string',
            'experience.*.description' => 'required|string',
            'experience.*.percentage' => 'required|integer',


            'training' => 'required|array|min:1',
            'training.*.weight' => 'required|string',
            'training.*.description' => 'required|string',
            'training.*.percentage' => 'required|integer',


            'performance' => 'required|array|min:1',
            'performance.*.weight' => 'required|string',
            'performance.*.description' => 'required|string',
            'performance.*.percentage' => 'required|integer',


            'behavioral' => 'nullable|array|min:0',
            'behavioral.*.weight' => 'nullable|string',
            'behavioral.*.description' => 'nullable|string',
            'behavioral.*.percentage' => 'nullable|integer',


            'exam' => 'nullable|array|min:0',
            'exam.*.weight' => 'nullable|string',
            'exam.*.description' => 'nullable|string',
            'exam.*.percentage' => 'nullable|integer',



        ];
    }
}
