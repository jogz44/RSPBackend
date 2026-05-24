<?php

namespace App\Services;

use App\Models\JobBatchesRsp;
use App\Models\Submission;
use App\Models\vwplantillastructure;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardService
{


    // status of applicant
    public function applicantStatus($postDate = null, $endDate = null)
    {
        // ✅ Filter submissions by postDate via job post relationship
        $applicants = Submission::selectRaw("
        COUNT(*) as total,
        SUM(CASE WHEN status = 'qualified' THEN 1 ELSE 0 END) as qualified,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'unqualified' THEN 1 ELSE 0 END) as unqualified
    ")
            ->when($postDate, function ($q) use ($postDate) {
                $q->whereHas('jobPost', fn($j) => $j->where('post_date', $postDate));
            })
            ->first();
        // ✅ Internal = has ControlNo, External = only has nPersonalInfo_id (no ControlNo)
        $applicantType = Submission::selectRaw("
            SUM(CASE WHEN ControlNo IS NOT NULL THEN 1 ELSE 0 END) as [internal],
            SUM(CASE WHEN ControlNo IS NULL AND nPersonalInfo_id IS NOT NULL THEN 1 ELSE 0 END) as [external]
        ")
            ->when($postDate, function ($q) use ($postDate) {
                $q->whereHas('jobPost', fn($j) => $j->where('post_date', $postDate));
            })
            ->first();

        // Plantilla — no postDate filter needed
        $plantilla = vwplantillastructure::selectRaw("
        COUNT(*) as total_positions,
        SUM(CASE WHEN Funded = 1 THEN 1 ELSE 0 END) as funded,
        SUM(CASE WHEN Funded = 0 THEN 1 ELSE 0 END) as unfunded,
        SUM(CASE WHEN Funded = 1 AND ControlNo IS NOT NULL THEN 1 ELSE 0 END) as occupied,
        SUM(CASE WHEN Funded = 1 AND ControlNo IS NULL THEN 1 ELSE 0 END) as unoccupied
    ")->first();

        // count the number of job base on the parameter send on the post_date
        $countJobpost = JobBatchesRsp::where('post_date', $postDate)->count();

        // get the value of actual data of the applicant - internal - external
        $applicantList = $this->listOfApplicants();

        return response()->json([

            'publish_jobpost' => [
                'vacant'    => (int) $countJobpost,
                'post_date' => $postDate && $endDate 
                    ? Carbon::parse($postDate)->format('F d, Y') . ' - ' . Carbon::parse($endDate)->format('F d, Y') 
                    : null,
            ],
            'applicant_application' => [
                'qualified'       => (int) $applicants->qualified,
                'pending'         => (int) $applicants->pending,
                'unqualified'     => (int) $applicants->unqualified,
                'total_applicant' => (int) $applicants->total,
                'internal'        => (int) $applicantType->internal,
                'external'        => (int) $applicantType->external,
            ],

            'plantilla_position' => [
                'funded'          => (int) $plantilla->funded,
                'unfunded'        => (int) $plantilla->unfunded,
                'occupied'        => (int) $plantilla->occupied,
                'unoccupied'      => (int) $plantilla->unoccupied,
                'total_positions' => (int) $plantilla->total_positions,
            ],

            'applicant_actual_application' => [
                'internal_actual'           => $applicantList['internal_actual'],
                'external_actual'           => $applicantList['external_actual'],
                'total_application_actual'  => $applicantList['total_application_actual'],
            ],


        ]);
    }


    // get the number of total of applicant per office
    public function getApplicantSummaryByOffice($postDate = null)
    {
        $summary = JobBatchesRsp::select('Office', 'post_date')
            ->when($postDate, fn($q) => $q->where('post_date', $postDate)) // ✅ only filter if postDate is provided
            ->withCount([
                'submissions as total_applicant',
                'submissions as Pending'     => fn($q) => $q->where('status', 'pending'),
                'submissions as Qualified'   => fn($q) => $q->where('status', 'Qualified'),
                'submissions as Unqualified' => fn($q) => $q->where('status', 'Unqualified'),
            ])
            ->get()
            ->groupBy('Office')
            ->map(function ($group) {
                return [
                    'Office'          => $group->first()->Office,
                    'Total_applicant' => $group->sum('total_applicant'),
                    'Pending'         => $group->sum('pending'),
                    'Qualified'       => $group->sum('Qualified'),
                    'Unqualified'     => $group->sum('Unqualified'),
                ];
            })
            ->values();

        return response()->json($summary);
    }


    //fetch job post list with status
    public function jobList($postDate = null)
    {
        // 🔹 Fetch job posts EXCLUDING republished ones
        $jobPosts = JobBatchesRsp::select('id', 'Position', 'post_date', 'Office', 'PositionID', 'ItemNo', 'status', 'end_date', 'tblStructureDetails_ID')
            // ->where('post_date',$postDate)
            ->when($postDate, fn($q) => $q->where('post_date', $postDate)) // only filter if postDate is provided

            ->withCount([
                'submissions as total_applicants',
                'submissions as qualified_count' => function ($query) {
                    $query->whereRaw('LOWER(status) = ?', ['qualified']);
                },
                'submissions as unqualified_count' => function ($query) {
                    $query->whereRaw('LOWER(status) = ?', ['unqualified']);
                },
                'submissions as pending_count' => function ($query) {
                    $query->whereRaw('LOWER(status) = ?', ['pending']);
                },
                'submissions as hired_count' => function ($query) {
                    $query->whereRaw('LOWER(status) = ?', ['hired']);
                },
            ])
            ->get();


        return response()->json($jobPosts);
    }


    // list of applicant applied internal - external

    // ✅ Private helper — returns array, not JSON response
    private function listOfApplicants()
    {
        $external = Submission::query()
            ->join('nPersonalInfo as p', 'submission.nPersonalInfo_id', '=', 'p.id')
            ->select(
                DB::raw('MIN(p.id) as nPersonal_id'),
                'p.firstname',
                'p.lastname',
                // DB::raw(" CONVERT(DATE, p.date_of_birth, 103) as date_of_birth"),
                DB::raw('CAST(p.date_of_birth AS VARCHAR(20)) as date_of_birth'), // varchar → varchar, no conversion

                DB::raw('COUNT(submission.id) as jobpost'),
                DB::raw("'external' as applicant_type"),
                DB::raw('NULL as ControlNo')
            )
            ->groupBy('p.firstname', 'p.lastname', 'p.date_of_birth');

        $internal = Submission::query()
            ->whereNull('submission.nPersonalInfo_id')
            ->join('xPersonal as xp', 'submission.ControlNo', '=', 'xp.ControlNo')
            ->select(
                DB::raw('NULL as nPersonal_id'),
                'xp.Firstname as firstname',
                'xp.Surname as lastname',
                // DB::raw('CAST(xp.BirthDate AS DATE) as date_of_birth'),
                DB::raw('CONVERT(VARCHAR(20), xp.BirthDate, 101) as date_of_birth'), // datetime → varchar MM/dd/yyyy
                DB::raw('COUNT(submission.id) as jobpost'),
                DB::raw("'internal' as applicant_type"),
                'submission.ControlNo'
            )
            ->groupBy('xp.Firstname', 'xp.Surname', 'xp.BirthDate', 'submission.ControlNo');

        $query = $external->unionAll($internal);

        $results = DB::table(DB::raw("({$query->toSql()}) as combined"))
            ->mergeBindings($query->getQuery())
            ->get();

        $internalCount = $results->where('applicant_type', 'internal')->count();
        $externalCount = $results->where('applicant_type', 'external')->count();

        // Return plain array for use by other methods
        return [
            'data'                      => $results,
            'internal_actual'           => $internalCount,
            'external_actual'           => $externalCount,
            'total_application_actual'  => $internalCount + $externalCount,
        ];
    }
}
