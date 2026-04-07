<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApplicantRaterResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Resolve name from either n_personal_info or x_personal
        $firstName = $this->nPersonalInfo?->firstname ?? $this->xPersonal?->Firstname;
        $lastName  = $this->nPersonalInfo?->lastname  ?? $this->xPersonal?->Surname;

        return [
            'submission_id'       => $this->id,
            'job_post_id'         => $this->job_batches_rsp_id,
            'job_position'        => $this->job_batch_rsp?->Position,
            'control_no'          => $this->ControlNo,
            'n_personal_info_id'  => $this->nPersonalInfo_id,
            'applicant_name'      => trim("{$firstName} {$lastName}"),
            'status'              => $this->status,
            'date_applied'        => $this->created_at?->format('Y-m-d'),
        ];
    }
}
