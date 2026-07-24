<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ApplicationRequest extends FormRequest
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

            'email_checker' => 'required|email:rfc,dns',

            'job_batches_rsp_id' => 'required|exists:job_batches_rsp,id',

            // personal information
            'lastname' => 'required|string',
            'firstname' => 'required|string',
            'middlename' => 'nullable|string',
            'name_extension' => 'nullable|string',
            'date_of_birth' => 'required|date_format:d/m/Y',
            'sex' => 'nullable|string',
            'place_of_birth' => 'nullable|string',
            'weight' => 'nullable|numeric',
            'height' => 'nullable|numeric',
            'blood_type' => 'nullable|string',
            'gsis_no' => 'nullable|string',
            'pagibig_no' => 'nullable|string',
            'philhealth_no' => 'nullable|string',
            'sss_no' => 'nullable|string',
            'tin_no' => 'nullable|string',
            'image_path' => 'nullable|image|mimes:jpg,jpeg,png|max:5120',
            'civil_status' => 'nullable|string',
            'citizenship' => 'nullable|string',
            'citizenship_status' => 'nullable|string',



            'residential_house' => 'nullable|string',
            'residential_street' => 'nullable|string',
            'residential_subdivision' => 'nullable|string',
            'residential_barangay' => 'nullable|string',
            'residential_city' => 'nullable|string',
            'residential_province' => 'nullable|string',
            'residential_zip' => 'nullable|string',


            'permanent_house' => 'nullable|string',
            'permanent_street' => 'nullable|string',
            'permanent_subdivision' => 'nullable|string',
            'permanent_barangay' => 'nullable|string',
            'permanent_city' => 'nullable|string',
            'permanent_province' => 'nullable|string',
            'permanent_zip' => 'nullable|string',



            'telephone_number' => 'nullable|string',
            'cellphone_number' =>  ['required', 'regex:/^(09\d{9}|\+639\d{9})$/'],
            'email_address' => 'required|email',
            'agency_employee_no' => 'nullable|string',
            'umId' => 'nullable|string',
            'philSys' => 'nullable|string',
            // 'pwd' => 'nullable|string',
            'gender_prefer' => 'nullable|string',
            'other_specify' => 'nullable|string',
            'Ppurok' => 'nullable|string',
            'Rpurok' => 'nullable|string',

            'ethnic_group' => 'nullable|string',
            'ethnic_specify' => 'nullable|string',


            // family
            'spouse_name' => 'nullable|string',
            'spouse_firstname' => 'nullable|string',
            'spouse_middlename' => 'nullable|string',
            'spouse_extension' => 'nullable|string',
            'spouse_occupation' => 'nullable|string',
            'spouse_employer' => 'nullable|string',
            'spouse_employer_address' => 'nullable|string',
            'spouse_employer_telephone' => 'nullable|string',
            'father_lastname' => 'nullable|string',
            'father_firstname' => 'nullable|string',
            'father_middlename' => 'nullable|string',
            'father_extension' => 'nullable|string',
            'mother_lastname' => 'nullable|string',
            'mother_firstname' => 'nullable|string',
            'mother_middlename' => 'nullable|string',
            'mother_maidenname' => 'nullable|string',


            // children
            'children' => 'nullable|array',
            'children.*.child_name' => 'nullable|string',
            'children.*.birth_date' => 'nullable|date_format:d/m/Y',

            // school
            'school' => 'nullable|array',
            'school.*.degree' => 'nullable|string',
            'school.*.attendance_from' => ['nullable', 'regex:/^[0-9]{4}$/'],
            'school.*.attendance_to' => ['nullable', 'regex:/^[0-9]{4}$/'],
            'school.*.highest_units' => 'nullable|integer',
            'school.*.year_graduated' => ['nullable', 'regex:/^[0-9]{4}$/'],
            'school.*.scholarship' => 'nullable|string',
            'school.*.level' => 'nullable|string',
            'school.*.attachment_path' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',

            // 'school_name.*.graduated'=> 'nullable|string',

            //trainings
            // todo date format
            'training' => 'nullable|array',
            'training.*.training_title' => 'nullable|string',
            'training.*.inclusive_date_from' => 'nullable|date_format:d/m/Y',
            'training.*.inclusive_date_to' => 'nullable|date_format:d/m/Y',
            'training.*.number_of_hours' => 'nullable|integer',
            'training.*.type' => 'nullable|string',
            'training.*.conducted_by' => 'nullable|string',
            'training.*.attachment_path' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',

            // work-experience
            'experience' => 'nullable|array',
            'experience.*.work_date_from' => 'nullable|date_format:d/m/Y',
            // 'experience.*.work_date_to' => 'nullable|date_format:d/m/Y',
            'experience.*.work_date_to' => [
                'nullable',
                function ($attribute, $value, $fail) {
                    if (in_array(strtoupper($value), ['PRESENT'])) {
                        return;
                    }
                    if (!\DateTime::createFromFormat('d/m/Y', $value) || \DateTime::createFromFormat('d/m/Y', $value)->format('d/m/Y') !== $value) {
                        $fail('The ' . $attribute . ' field must be a valid date in d/m/Y format, or "Present".');
                    }
                },
            ],
            'experience.*.position_title' => 'nullable|string',
            'experience.*.department' => 'nullable|string',
            'experience.*.monthly_salary' => 'nullable|numeric',
            'experience.*.salary_grade' => 'nullable|string',
            'experience.*.status_of_appointment' => 'nullable|string',
            'experience.*.government_service' => 'nullable|string',
            'experience.*.attachment_path' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',
            // voluntary
            'voluntary' => 'nullable|array',
            'voluntary.*.organization_name' => 'nullable|string',
            'voluntary.*.inclusive_date_from' => 'nullable|date_format:d/m/Y',
            'voluntary.*.inclusive_date_to' => 'nullable|date_format:d/m/Y',
            'voluntary.*.number_of_hours' => 'nullable|integer',
            'voluntary.*.position' => 'nullable|string',


            // eligibility
            'eligibility' => 'nullable|array',
            'eligibility.*.eligibility' => 'nullable|string',
            'eligibility.*.rating' => 'nullable|numeric|decimal:0,2',
            'eligibility.*.date_of_examination' => 'nullable|date_format:d/m/Y',
            'eligibility.*.place_of_examination' => 'nullable|string',
            'eligibility.*.license_number' => 'nullable|string',
            'eligibility.*.date_of_validity' => 'nullable|date_format:d/m/Y',
            'eligibility.*.attachment_path' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',



            // skill & non academic
            'skill' => 'nullable|array',
            'skill.*.skill' => 'nullable|string',
            'skill.*.non_academic' => 'nullable|string',
            'skill.*.organization' => 'nullable|string',

            // references
            'reference' => 'nullable|array',
            'reference.*.full_name' => 'nullable|string',
            'reference.*.address' => 'nullable|string',
            'reference.*.contact_number' => 'nullable|string',

            // personal_declarations
            'personal_declaration' => 'nullable|array',
            // Q34
            'personal_declaration.*.question_34a' => 'nullable|string',
            'personal_declaration.*.question_34b' => 'nullable|string',
            'personal_declaration.*.response_34' => 'nullable|string',

            // Q35
            'personal_declaration.*.question_35a' => 'nullable|string',
            'personal_declaration.*.response_35a' => 'nullable|string',

            'personal_declaration.*.question_35b' => 'nullable|string',
            'personal_declaration.*.response_35b_date' => 'nullable|string',
            'personal_declaration.*.response_35b_status' => 'nullable|string',

            // Q36
            'personal_declaration.*.question_36' => 'nullable|string',
            'personal_declaration.*.response_36' => 'nullable|string',

            // Q37
            'personal_declaration.*.question_37' => 'nullable|string',
            'personal_declaration.*.response_37' => 'nullable|string',

            // Q38
            'personal_declaration.*.question_38a' => 'nullable|string',
            'personal_declaration.*.response_38a' => 'nullable|string',
            'personal_declaration.*.question_38b' => 'nullable|string',
            'personal_declaration.*.response_38b' => 'nullable|string',

            // Q39
            'personal_declaration.*.question_39' => 'nullable|string',
            'personal_declaration.*.response_39' => 'nullable|string',

            // Q40
            'personal_declaration.*.question_40a' => 'nullable|string',
            'personal_declaration.*.response_40a' => 'nullable|string',

            'personal_declaration.*.question_40b' => 'nullable|string',
            'personal_declaration.*.response_40b' => 'nullable|string',

            'personal_declaration.*.question_40c' => 'nullable|string',
            'personal_declaration.*.response_40c' => 'nullable|string',

            'personal_declaration.*.chronic' => 'nullable|in:0,1',
            'personal_declaration.*.Psychosocial' => 'nullable|in:0,1',
            'personal_declaration.*.Orthopedic' => 'nullable|in:0,1',
            'personal_declaration.*.Communication' => 'nullable|in:0,1',
            'personal_declaration.*.Learning' => 'nullable|in:0,1',
            'personal_declaration.*.Mental' => 'nullable|in:0,1',
            'personal_declaration.*.Visual' => 'nullable|in:0,1',

            // insert image education,training,experience
            'other_document'             => 'nullable|array',
            'other_document.*.document'  => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',






        ];
    }
}
