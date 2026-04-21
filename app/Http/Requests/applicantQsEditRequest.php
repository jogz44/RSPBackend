<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class applicantQsEditRequest extends FormRequest
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
            // --- Personal Information ---
            'personal_info_id'          => 'required|integer|exists:nPersonalInfo,id',
            'lastname'                  => 'sometimes|string|max:255',
            'firstname'                 => 'sometimes|string|max:255',
            'middlename'                => 'sometimes|nullable|string|max:255',
            'name_extension'            => 'sometimes|nullable|string|max:10',
            'date_of_birth'             => 'sometimes|nullable|date_format:d/m/Y',
            'sex'                       => 'sometimes|nullable|string',
            'place_of_birth'            => 'sometimes|nullable|string',
            'height'                    => 'sometimes|nullable|string',
            'weight'                    => 'sometimes|nullable|string',
            'blood_type'                => 'sometimes|nullable|string',
            'gsis_no'                   => 'sometimes|nullable|string',
            'pagibig_no'                => 'sometimes|nullable|string',
            'philhealth_no'             => 'sometimes|nullable|string',
            'sss_no'                    => 'sometimes|nullable|string',
            'tin_no'                    => 'sometimes|nullable|string',
            'civil_status'              => 'sometimes|nullable|string',
            'citizenship'               => 'sometimes|nullable|string',
            'citizenship_status'        => 'sometimes|nullable|string',
            'telephone_number'          => 'sometimes|nullable|string',
            'cellphone_number'          => 'sometimes|nullable|string',
            'email_address'             => 'sometimes|nullable|email',
            'agency_employee_no'        => 'sometimes|nullable|string',
            'umId'                      => 'sometimes|nullable|string',
            'philSys'                   => 'sometimes|nullable|string',
            // 'pwd'                       => 'sometimes|nullable|string',
            'gender_prefer'             => 'sometimes|nullable|string',
            'other_specify'             => 'sometimes|nullable|string',
            // 'image_path'                => 'sometimes|nullable|string',

            // --- Residential Address ---
            'residential_house'         => 'sometimes|nullable|string',
            'residential_street'        => 'sometimes|nullable|string',
            'residential_subdivision'   => 'sometimes|nullable|string',
            'residential_barangay'      => 'sometimes|nullable|string',
            'residential_city'          => 'sometimes|nullable|string',
            'residential_province'      => 'sometimes|nullable|string',
            'residential_zip'           => 'sometimes|nullable|string',
            'Rpurok'                    => 'sometimes|nullable|string',

            // --- Permanent Address ---
            'permanent_house'           => 'sometimes|nullable|string',
            'permanent_street'          => 'sometimes|nullable|string',
            'permanent_subdivision'     => 'sometimes|nullable|string',
            'permanent_barangay'        => 'sometimes|nullable|string',
            'permanent_city'            => 'sometimes|nullable|string',
            'permanent_province'        => 'sometimes|nullable|string',
            'permanent_zip'             => 'sometimes|nullable|string',
            'Ppurok'                    => 'sometimes|nullable|string',

            // --- Family ---
            'family_id'                         => 'sometimes|integer|exists:nFamily,id',
            'spouse_name'                       => 'sometimes|nullable|string',
            'spouse_firstname'                  => 'sometimes|nullable|string',
            'spouse_middlename'                 => 'sometimes|nullable|string',
            'spouse_extension'                  => 'sometimes|nullable|string',
            'spouse_occupation'                 => 'sometimes|nullable|string',
            'spouse_employer'                   => 'sometimes|nullable|string',
            'spouse_employer_address'           => 'sometimes|nullable|string',
            'spouse_employer_telephone'         => 'sometimes|nullable|string',
            'father_lastname'                   => 'sometimes|nullable|string',
            'father_firstname'                  => 'sometimes|nullable|string',
            'father_middlename'                 => 'sometimes|nullable|string',
            'father_extension'                  => 'sometimes|nullable|string',
            'mother_lastname'                   => 'sometimes|nullable|string',
            'mother_firstname'                  => 'sometimes|nullable|string',
            'mother_middlename'                 => 'sometimes|nullable|string',
            'mother_maidenname'                 => 'sometimes|nullable|string',

            // --- Children (array) ---
            'children'                  => 'sometimes|array',
            'children.*.id'             => 'sometimes|integer|exists:nChildren,id',
            'children.*.child_name'     => 'sometimes|nullable|string',
            'children.*.birth_date'     =>  'sometimes|nullable|date_format:d/m/Y',

            // --- Eligibility (array) ---
            'eligibilities'                         => 'sometimes|array',
            'eligibilities.*.id'                    => 'sometimes|integer|exists:nCivilServiceEligibity,id',
            'eligibilities.*.eligibility'           => 'sometimes|nullable|string',
            'eligibilities.*.rating'                => 'sometimes|nullable|string',
            'eligibilities.*.date_of_examination'   =>  'sometimes|nullable|date_format:d/m/Y',
            'eligibilities.*.place_of_examination'  => 'sometimes|nullable|string',
            'eligibilities.*.license_number'        => 'sometimes|nullable|string',
            'eligibilities.*.date_of_validity'      =>  'sometimes|nullable|date_format:d/m/Y',

            // --- Education (array) ---
            'educations'                        => 'sometimes|array',
            'educations.*.id'                   => 'sometimes|integer|exists:nEducation,id',
            'educations.*.school_name'          => 'sometimes|nullable|string',
            'educations.*.degree'               => 'sometimes|nullable|string',
            'educations.*.attendance_from'      =>  'sometimes|nullable|date_format:Y',
            'educations.*.attendance_to'        =>  'sometimes|nullable|date_format:Y',
            'educations.*.highest_units'        => 'sometimes|nullable|string',
            'educations.*.year_graduated'       => 'sometimes|nullable|string',
            'educations.*.scholarship'          => 'sometimes|nullable|string',
            'educations.*.level'                => 'sometimes|nullable|string',
            'educations.*.graduated'            => 'sometimes|nullable|string',

            // --- Training (array) ---
            'trainings'                             => 'sometimes|array',
            'trainings.*.id'                        => 'sometimes|integer|exists:nTrainings,id',
            'trainings.*.training_title'            => 'sometimes|nullable|string',
            'trainings.*.inclusive_date_from'       => 'sometimes|nullable|date_format:d/m/Y',
            'trainings.*.inclusive_date_to'         =>  'sometimes|nullable|date_format:d/m/Y',
            'trainings.*.number_of_hours'           => 'sometimes|nullable|string',
            'trainings.*.type'                      => 'sometimes|nullable|string',
            'trainings.*.conducted_by'              => 'sometimes|nullable|string',

            // --- Skills / Non-Academic (array) ---
            'skills'                            => 'sometimes|array',
            'skills.*.id'                       => 'sometimes|integer|exists:skill_non_academic,id',
            'skills.*.skill'                    => 'sometimes|nullable|string',
            'skills.*.non_academic'             => 'sometimes|nullable|string',
            'skills.*.organization'             => 'sometimes|nullable|string',

            // --- Voluntary Work (array) ---
            'voluntary_works'                           => 'sometimes|array',
            'voluntary_works.*.id'                      => 'sometimes|integer|exists:nVoluntaryWork,id',
            'voluntary_works.*.organization_name'       => 'sometimes|nullable|string',
            'voluntary_works.*.inclusive_date_from'     =>  'sometimes|nullable|date_format:d/m/Y',
            'voluntary_works.*.inclusive_date_to'       =>  'sometimes|nullable|date_format:d/m/Y',
            'voluntary_works.*.number_of_hours'         => 'sometimes|nullable|string',
            'voluntary_works.*.position'                => 'sometimes|nullable|string',

            // --- Work Experience (array) ---
            'work_experiences'                          => 'sometimes|array',
            'work_experiences.*.id'                     => 'sometimes|integer|exists:nWorkExperience,id',
            'work_experiences.*.work_date_from'         =>  'sometimes|nullable|date_format:d/m/Y',
            'work_experiences.*.work_date_to'           =>  'sometimes|nullable|date_format:d/m/Y',
            'work_experiences.*.position_title'         => 'sometimes|nullable|string',
            'work_experiences.*.department'             => 'sometimes|nullable|string',
            'work_experiences.*.monthly_salary'         => 'sometimes|nullable|string',
            'work_experiences.*.salary_grade'           => 'sometimes|nullable|string',
            'work_experiences.*.status_of_appointment'  => 'sometimes|nullable|string',
            'work_experiences.*.government_service'     => 'sometimes|nullable|string',


            //references
            'references'                            => 'sometimes|array',
            'references.*.id'                       => 'sometimes|integer|exists:references,id',
            'references.*.full_name'                    => 'sometimes|nullable|string',
            'references.*.address'                   => 'sometimes|nullable|string',
            'references.*.contact_number'             => 'sometimes|nullable|string',


            // personal_declarations
            'personal_declaration_id'                         => 'sometimes|integer|exists:personal_declarations,id',

            'question_34a'                    => 'sometimes|nullable|boolean',
            'question_34b'                   => 'sometimes|nullable|boolean',
            'response_34'             => 'sometimes|nullable|string',

            'question_35a'                    => 'sometimes|nullable|boolean',
            'response_35a'                   => 'sometimes|nullable|string',
            'question_35b'             => 'sometimes|nullable|boolean',
            'response_35b_date'         =>     'sometimes|nullable|date_format:d/m/Y',
            'response_35b_status'             => 'sometimes|nullable|string',

            'question_36'                    => 'sometimes|nullable|boolean',
            'response_36'                   => 'sometimes|nullable|string',

            'question_37'             => 'sometimes|nullable|boolean',
            'response_37'             => 'sometimes|nullable|string',


            'question_38a'                    => 'sometimes|nullable|boolean',
            'response_38a'                   => 'sometimes|nullable|string',
            'question_38b'             => 'sometimes|nullable|boolean',
            'response_38b'             => 'sometimes|nullable|string',

            'question_39'             => 'sometimes|nullable|boolean',
            'response_39'             => 'sometimes|nullable|string',


            'question_40a'             => 'sometimes|nullable|boolean',
            'response_40a'             => 'sometimes|nullable|string',
            'question_40b'             => 'sometimes|nullable|boolean',
            'response_40b'             => 'sometimes|nullable|string',

            'question_40c'             => 'sometimes|nullable|boolean',
            'response_40c'             => 'sometimes|nullable|string',

            'chronic'             => 'sometimes|nullable|boolean',
            'Psychosocial'             => 'sometimes|nullable|boolean',
            'Orthopedic'             => 'sometimes|nullable|boolean',
            'Communication'             => 'sometimes|nullable|boolean',
            'Learning'             => 'sometimes|nullable|boolean',
            'Mental'             => 'sometimes|nullable|boolean',
            'Visual'             => 'sometimes|nullable|boolean',

        ];
    }
}
