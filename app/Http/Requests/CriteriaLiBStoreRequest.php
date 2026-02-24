<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CriteriaLiBStoreRequest extends FormRequest
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


            'behavioral' => 'required|array|min:1',
            'behavioral.*.weight' => 'required|string',
            'behavioral.*.description' => 'required|string',
            'behavioral.*.percentage' => 'required|integer',
        ];
    }
}
