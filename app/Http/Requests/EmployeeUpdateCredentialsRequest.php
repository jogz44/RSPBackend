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

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            //xPersonal
            'Surname' => 'required|string',
            'Firstname' => 'required|string',
            'MIddlename' => 'required|string',
            'Sex' => 'nullable|string',
            'CivilStatus' => 'required|string',
            'BirthDate' => 'nullable|date',
            'TINNo' => 'nullable|string',
            'Address' => 'nullable|string',


            // tempreg

            'sepdate' => 'nullable|date',
            'sepcause' => 'nullable|string',
            'vicename' => 'nullable|string',
            'vicecause' => 'nullable|string',

            // //xservice
            // 'FromDate' => 'required|date',
            // 'ToDate' => 'required|date',



            // tempRegAppointmentReorgExt
            'tempExtId' => 'nullable|string',
            'PresAppro'         => 'required|string',
            'PrevAppro'         => 'required|string',
            'SalAuthorized'     => 'required|string',
            'OtherComp'         => 'required|string',
            'SupPosition'       => 'required|string',
            'HSupPosition'      => 'required|string',
            'Tool'              => 'nullable|string',



            'Contact1'          => 'required|integer',
            'Contact2'          => 'required|integer',
            'Contact3'          => 'required|integer',
            'Contact4'          => 'required|integer',
            'Contact5'          => 'required|integer',
            'Contact6'          => 'required|integer',
            'ContactOthers'     => 'nullable|string',

            'Working1'          => 'required|integer',
            'Working2'          => 'required|integer',
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

            'Corelevel1'        => 'required|integer',
            'Corelevel2'        => 'required|integer',
            'Corelevel3'        => 'required|integer',
            'Corelevel4'        => 'required|integer',

            'Leader1'           => 'required|integer',
            'Leader2'           => 'required|integer',
            'Leader3'           => 'required|integer',
            'Leader4'           => 'required|integer',

            'leaderlevel1'      => 'required|integer',
            'leaderlevel2'      => 'required|integer',
            'leaderlevel3'      => 'required|integer',
            'leaderlevel4'      => 'required|integer',

            'structureid'       => 'required|integer',


        ];
    }
}
