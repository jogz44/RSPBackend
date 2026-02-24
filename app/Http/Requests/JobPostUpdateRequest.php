<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class JobPostUpdateRequest extends FormRequest
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
            'post_date' => 'required|nullable|date',
            'end_date' => 'required|nullable|date',
            'PageNo' => 'required|string',
            'ItemNo' => 'required|string',
            'PositionID' => 'required|string',
            'tblStructureDetails_ID' => 'required|string',
        ];
    }
}
