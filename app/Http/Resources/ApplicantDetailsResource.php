<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApplicantDetailsResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Resolve personal info from either source
        $info = $this->personal_info;

        return [
            'submission_id'      => $this->id,
            'control_no'         => $this->ControlNo,
            'job_post_id'        => $this->job_batches_rsp_id,
            'status'             => $this->status,
            'applicant_type'     => $this->applicant_type,

            'personal_information' => [
                'firstname'     => $info['firstname']     ?? $info?->firstname,
                'lastname'      => $info['lastname']      ?? $info?->lastname,
                'date_of_birth' => $info['date_of_birth'] ?? $info?->date_of_birth,
            ],

            'job_post' => $this->job_batch_rsp ? [
                'id'          => $this->job_batch_rsp->id,
                'position'    => $this->job_batch_rsp->Position,
                'office'      => $this->job_batch_rsp->Office,
                'salary_grade' => $this->job_batch_rsp->SalaryGrade,
                'salary_min'  => $this->job_batch_rsp->salaryMin,
                'salary_max'  => $this->job_batch_rsp->salaryMax,
                'status'      => $this->job_batch_rsp->status,
            ] : null,
        ];
    }
}
