<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CriteriaLiBUpdateRequest extends FormRequest
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
            'sg_min' => 'required|integer',
            'sg_max' => 'required|integer|gte:sg_min',

            'education' => 'required|array',
            'education.*.weight' => 'required|integer',
            'education.*.description' => 'required|string',
            'education.*.percentage' => 'required|integer',

            'experience' => 'required|array',
            'experience.*.weight' => 'required|integer',
            'experience.*.description' => 'required|string',
            'experience.*.percentage' => 'required|integer',

            'training' => 'required|array',
            'training.*.weight' => 'required|integer',
            'training.*.description' => 'required|string',
            'training.*.percentage' => 'required|integer',

            'performance' => 'required|array',
            'performance.*.weight' => 'required|integer',
            'performance.*.description' => 'required|string',
            'performance.*.percentage' => 'required|integer',

            'behavioral' => 'required|array',
            'behavioral.*.weight' => 'required|integer',
            'behavioral.*.description' => 'required|string',
            'behavioral.*.percentage' => 'required|integer',
        ];
    }
}
