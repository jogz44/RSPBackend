<?php

namespace App\Services;

use App\Models\JobBatchesRsp;
use App\Models\Submission;
use App\Models\vwplantillastructure;
use Carbon\Carbon;

class DashboardService
{


    // status of applicant
    public function applicantStatus($postDate = null)
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

        return response()->json([
            'qualified'       => (int) $applicants->qualified,
            'pending'         => (int) $applicants->pending,
            'unqualified'     => (int) $applicants->unqualified,
            'total_applicant' => (int) $applicants->total,

            'internal'        => (int) $applicantType->internal,
            'external'        => (int) $applicantType->external,


            'funded'          => (int) $plantilla->funded,
            'unfunded'        => (int) $plantilla->unfunded,
            'occupied'        => (int) $plantilla->occupied,
            'unoccupied'      => (int) $plantilla->unoccupied,
            'total_positions' => (int) $plantilla->total_positions,
        ]);
    }
    // public function applicantStatus()
    // {
    //     // Applicant status counts (ONE QUERY ONLY)
    //     $applicants = Submission::selectRaw("
    //     COUNT(*) as total,
    //     SUM(CASE WHEN status = 'qualified' THEN 1 ELSE 0 END) as qualified,
    //     SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
    //     SUM(CASE WHEN status = 'unqualified' THEN 1 ELSE 0 END) as unqualified
    // ")->first();

    //     // Plantilla counts (ONE QUERY ONLY)
    //     $plantilla = vwplantillastructure::selectRaw("
    //     COUNT(*) as total_positions,
    //     SUM(CASE WHEN Funded = 1 THEN 1 ELSE 0 END) as funded,
    //     SUM(CASE WHEN Funded = 0 THEN 1 ELSE 0 END) as unfunded,
    //     SUM(CASE WHEN Funded = 1 AND ControlNo IS NOT NULL THEN 1 ELSE 0 END) as occupied,
    //     SUM(CASE WHEN Funded = 1 AND ControlNo IS NULL THEN 1 ELSE 0 END) as unoccupied
    // ")->first();

    //     return response()->json([
    //         'qualified' => (int) $applicants->qualified,
    //         'pending' => (int) $applicants->pending,
    //         'unqualified' => (int) $applicants->unqualified,
    //         'total_applicant' => (int) $applicants->total,

    //         'funded' => (int) $plantilla->funded,
    //         'unfunded' => (int) $plantilla->unfunded,
    //         'occupied' => (int) $plantilla->occupied,
    //         'unoccupied' => (int) $plantilla->unoccupied,
    //         'total_positions' => (int) $plantilla->total_positions,
    //     ]);
    // }
    // public function applicantStatus()
    // {
    //     // count total of applicant each status
    //     $qualified = Submission::where('status', 'qualified')->count();
    //     $pending = Submission::where('status', 'pending')->count();
    //     $unqualified = Submission::where('status', 'unqualified')->count();


    //         $funded = vwplantillastructure::where('Funded', true)->count();
    //         $unfunded = vwplantillastructure::where('Funded', false)->count();
    //         $occupied = vwplantillastructure::where('Funded', true)
    //             ->whereNotNull('ControlNo')
    //             ->count();
    //         $unoccupied = vwplantillastructure::where('Funded', true)
    //             ->whereNull('ControlNo')
    //             ->count();
    //         $total_positions = vwplantillastructure::count();


    //     // count the total of applicant
    //     $total = Submission::count();

    //     return response()->json([
    //         'qualified' => $qualified,
    //         'pending' => $pending,
    //         'unqualified' => $unqualified,
    //         'total_applicant' => $total,
    //         'funded' => $funded,
    //         'unfunded' => $unfunded,
    //         'occupied' => $occupied,
    //         'unoccupied' => $unoccupied,
    //         'total_positions' => $total_positions,
    //     ]);
    // }

    // employee total and status
    // funded and unfunded position
    // total occupied and unccupied position
    // public function plantillaData()
    // {
    //     $funded = vwplantillastructure::where('Funded', true)->count();
    //     $unfunded = vwplantillastructure::where('Funded', false)->count();
    //     $occupied = vwplantillastructure::where('Funded', true)
    //         ->whereNotNull('ControlNo')
    //         ->count();
    //     $unoccupied = vwplantillastructure::where('Funded', true)
    //         ->whereNull('ControlNo')
    //         ->count();
    //     $total = vwplantillastructure::count();

    //     return response()->json([
    //         'funded' => $funded,
    //         'unfunded' => $unfunded,
    //         'occupied' => $occupied,
    //         'unoccupied' => $unoccupied,
    //         'total' => $total,
    //     ]);
    // }

    // public function plantillaData()
    // {
    //     $data = vwplantillastructure::selectRaw("
    //     COUNT(*) as total,
    //     SUM(CASE WHEN Funded = 1 THEN 1 ELSE 0 END) as funded,
    //     SUM(CASE WHEN Funded = 0 THEN 1 ELSE 0 END) as unfunded,
    //     SUM(CASE WHEN Funded = 1 AND ControlNo IS NOT NULL THEN 1 ELSE 0 END) as occupied,
    //     SUM(CASE WHEN Funded = 1 AND ControlNo IS NULL THEN 1 ELSE 0 END) as unoccupied
    // ")->first();

    //     return response()->json([
    //         'total'      => (int) $data->total,
    //         'funded'     => (int) $data->funded,
    //         'unfunded'   => (int) $data->unfunded,
    //         'occupied'   => (int) $data->occupied,
    //         'unoccupied' => (int) $data->unoccupied,
    //     ]);
    // }


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

    // get the publication of job post
    public function publication()
    {
        $dates = JobBatchesRsp::select('post_date')
            ->distinct()
            ->orderBy('post_date', 'desc')
            ->get();

        $formattedDate = $dates->map(function ($item) {
            return [
                // 'date'      => $item->post_date, // RAW date (for API logic)
                'date' => Carbon::parse($item->post_date)->format('M d, Y'), // UI only
            ];
        });

        return response()->json($formattedDate);
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
}
