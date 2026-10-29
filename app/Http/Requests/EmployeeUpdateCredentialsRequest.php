<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EmployeeUpdateCredentialsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }
      protected function prepareForValidation(): void
    {
        $this->merge([
            'signing_date' => $this->input('signingDate'),
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            //xPersonal
            // 'Surname' => 'required|string',
            // 'Firstname' => 'required|string',
            // 'MIddlename' => 'nullable|string',
            'Sex' => 'nullable|string',
            'CivilStatus' => 'nullable|string',
            'BirthDate' => 'nullable|date',
            'TINNo' => 'nullable|string',
            'Address' => 'nullable|string',


            // tempreg
            'sepdate' => 'nullable|date_format:Y-m-d',
            'sepcause' => 'nullable|string',
            'vicename' => 'nullable|string',
            'vicecause' => 'nullable|string',
            'renew' => 'nullable|string',
            'deliberation_date' => 'nullable|date_format:Y-m-d',
            'assessment_date' => 'nullable|date_format:Y-m-d',
            // //xservice
            // 'FromDate' => 'required|date',
            // 'ToDate' => 'required|date',



            // tempRegAppointmentReorgExt
            'tempExtId' => 'nullable|string',
            'PresAppro'         => 'nullable|string',
            'PrevAppro'         => 'nullable|string',
            'SalAuthorized'     => 'nullable|string',
            'OtherComp'         => 'nullable|string',
            'SupPosition'       => 'nullable|string',
            'HSupPosition'      => 'nullable|string',
            'Tool'              => 'nullable|string',
          


            'Contact1'          => 'nullable|integer',
            'Contact2'          => 'nullable|integer',
            'Contact3'          => 'nullable|integer',
            'Contact4'          => 'nullable|integer',
            'Contact5'          => 'nullable|integer',
            'Contact6'          => 'nullable|integer',
            'ContactOthers'     => 'nullable|string',

            'Working1'          => 'nullable|integer',
            'Working2'          => 'nullable|integer',
            'WorkingOthers'     => 'nullable|string',

            'DescriptionSection'   => 'nullable|string',
            'DescriptionFunction'  => 'nullable|string',

            'StandardEduc'      => 'nullable|string',
            'StandardExp'       => 'nullable|string',
            'StandardTrain'     => 'nullable|string',
            'StandardElig'      => 'nullable|string',

            'Supervisor'        => 'nullable|string',

            'Core1'             => 'nullable|integer',
            'Core2'             => 'nullable|integer',
            'Core3'             => 'nullable|integer',

            'Corelevel1'        => 'nullable|integer',
            'Corelevel2'        => 'nullable|integer',
            'Corelevel3'        => 'nullable|integer',
            'Corelevel4'        => 'nullable|integer',

            'Leader1'           => 'nullable|integer',
            'Leader2'           => 'nullable|integer',
            'Leader3'           => 'nullable|integer',
            'Leader4'           => 'nullable|integer',

            'leaderlevel1'      => 'nullable|integer',
            'leaderlevel2'      => 'nullable|integer',
            'leaderlevel3'      => 'nullable|integer',
            'leaderlevel4'      => 'nullable|integer',

            'structureid'       => 'nullable|integer',
            'signing_date' => 'nullable|date_format:Y-m-d',


        ];
    }
}
