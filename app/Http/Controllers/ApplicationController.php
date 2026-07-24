<?php

namespace App\Http\Controllers;

use App\Models\excel\nPersonal_info;
use App\Traits\ApiResponseTrait;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ApplicationController extends Controller
{


    use ApiResponseTrait;

    // fetch  the list of application base on the email be used
    public function getListOfApplicant(string $email)
    {
        $listOfApplication = nPersonal_info::with(['job_batches_rsp:id,Office,Position,SalaryGrade,post_date,end_date'])
            ->select('id', 'firstname', 'lastname', 'date_of_birth', 'email_address')
            ->where('email_address', $email)
            ->get()
            ->map(function ($applicant) {
                $batch = $applicant->job_batches_rsp->first(); // grab the first (or only) job batch

                return [
                    'personal_id' => $applicant->id,
                    'firstname' => $applicant->firstname,
                    'lastname' => $applicant->lastname,
                    'date_of_birth' => $applicant->date_of_birth
                        ? Carbon::createFromFormat('d/m/Y', $applicant->date_of_birth)->format('F j, Y')
                        : null,
                    'email_address' => $applicant->email_address,
                    'office' => $batch->Office ?? null,
                    'applied_position' => $batch->Position ?? null,
                    'salary_grade' => $batch->SalaryGrade ?? null,
                    'post_date' => $batch->post_date ? Carbon::parse($batch->post_date)->format('F j, Y') : null,
                    'end_date'  => $batch->end_date ? Carbon::parse($batch->end_date)->format('F j, Y') : null,
                    'application_applied_date' => $batch->pivot->created_at ?? null,
                    'application_status' => $batch->pivot->status ?? null,
                ];
            });

        if ($listOfApplication->isEmpty()) {
            return $this->successMessage($listOfApplication, 'there is no application found', 200);
        }
        return $this->successMessage($listOfApplication, 'success', 200);
    }


    // get the pds of the applicant 
    public function getApplicantPdsExternalApplication(string $email)
    {
        $personalId = nPersonal_info::with([
            'family',
            'children',
            'education',
            'work_experience',
            'training',
            'eligibity',
            'personal_declarations',
            'skills',
            'references'
        ])
            ->where('email_address', $email)
            ->latest() // orders by created_at desc, then first() grabs the newest
            ->first();

        if (!$personalId) {
            return $this->errorMessage('applicant not found', 404);
        }

        $categories = ['education', 'training', 'experience', 'eligibility'];
        $images = [];

        foreach ($categories as $category) {
            $folder = "applicant_files/{$personalId}/{$category}";

            if (Storage::disk('public')->exists($folder)) {
                $images[$category] = collect(Storage::disk('public')->files($folder))
                    ->map(fn($path) => Storage::disk('public')->url($path))
                    ->values();
            } else {
                $images[$category] = [];
            }
        }

        $personalId->setAttribute('images', $images);

        return $this->successMessage($personalId, 'success', 200);
    }
}
