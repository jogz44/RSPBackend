<?php

namespace App\Http\Controllers;

use App\Models\JobBatchesRsp;
use App\Models\Submission;
use App\Models\vwplantillastructure;
use App\Services\DashboardService;
use Carbon\Carbon;
use Illuminate\Container\Attributes\Auth;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    //


    // get the publication of job post
    private function publicationDate()
    {
        $dates = JobBatchesRsp::select('post_date')
            ->distinct()
            ->orderBy('post_date', 'desc')
            ->get();

        return $dates->map(fn($item) => [
            'date' => Carbon::parse($item->post_date)->format('M d, Y'),
        ]);
    }

    private function latestPostDate(): ?string
    {
        $dates = $this->publicationDate();

        if ($dates->isEmpty()) {
            return null;
        }

        // Re-parse the formatted "M d, Y" back to Y-m-d for service consumption
        return Carbon::createFromFormat('M d, Y', $dates->first()['date'])->format('Y-m-d');
    }

    // total of applicant
    // total of each status of applicant
    public function totalApplicantStatus(DashboardService $dashboardService, Request $request,)
    {

        $postDate = null;

        if ($request->has('postDate')) {
            try {
                $postDate = Carbon::createFromFormat('m-d-Y', $request->query('postDate'))->format('Y-m-d');
            } catch (\Exception $e) {
                return response()->json([
                    'message' => 'Invalid date format. Use MM-DD-YYYY. Example: 04-07-2026',
                ], 422);
            }
        }
        $postDate ??= $this->latestPostDate();

        $result = $dashboardService->applicantStatus($postDate);
        return $result;
    }

    // get the total of applicant per office base on the jobpost
    public function applicantSummaryByOffice(DashboardService $dashboardService, Request $request)
    {

        // Validate and convert format if postDate is provided
        $postDate = $request->query('postDate'); // reads ?postDate=04-07-2026

        if (!is_null($postDate)) {
            try {
                $postDate = Carbon::createFromFormat('m-d-Y', $postDate)->format('Y-m-d');
            } catch (\Exception $e) {
                return response()->json([
                    'message' => 'Invalid date format. Use MM-DD-YYYY. Example: 04-07-2026',
                ], 422);
            }
        }
        // lastest
        $postDate ??= $this->latestPostDate();

        $result = $dashboardService->getApplicantSummaryByOffice($postDate);

        return $result;
    }



    // get the list of jobpost
    public function jobPost(DashboardService $dashboardService, Request $request)
    {

        // Validate and convert format if postDate is provided
        $postDate = $request->query('postDate'); // reads ?postDate=04-07-2026

        if (!is_null($postDate)) {
            try {
                $postDate = Carbon::createFromFormat('m-d-Y', $postDate)->format('Y-m-d');
            } catch (\Exception $e) {
                return response()->json([
                    'message' => 'Invalid date format. Use MM-DD-YYYY. Example: 04-07-2026',
                ], 422);
            }
        }

        $postDate ??= $this->latestPostDate();

        $result = $dashboardService->jobList($postDate);

        return $result;
    }
}
