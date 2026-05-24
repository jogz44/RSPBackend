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
        $dates = JobBatchesRsp::select('post_date', 'end_date')
            ->distinct()
            ->orderBy('post_date', 'desc')
            ->get();

        return $dates->map(fn($item) => [
            'date' => Carbon::parse($item->post_date)->format('M d, Y'),
            'end_date' => Carbon::parse($item->end_date)->format('M d, Y'),
        ]);
    }

    private function latestPostDate(): array
    {
        $dates = $this->publicationDate();

        if ($dates->isEmpty()) {
            return ['post_date' => null, 'end_date' => null];
        }

        return [
            'post_date' => Carbon::createFromFormat('M d, Y', $dates->first()['date'])->format('Y-m-d'),
            'end_date'  => Carbon::createFromFormat('M d, Y', $dates->first()['end_date'])->format('Y-m-d'),
        ];
    }
    // total of applicant
    // total of each status of applicant
    public function totalApplicantStatus(DashboardService $dashboardService, Request $request,)
    {

        $postDate = null;
        $endDate = null;

        if ($request->has('postDate')) {
            try {
                $postDate = Carbon::createFromFormat('m-d-Y', $request->query('postDate'))->format('Y-m-d');
            } catch (\Exception $e) {
                return response()->json([
                    'message' => 'Invalid date format. Use MM-DD-YYYY. Example: 04-07-2026',
                ], 422);
            }
        }

        if ($request->has('endDate')) {
            try {
                $endDate = Carbon::createFromFormat('m-d-Y', $request->query('endDate'))->format('Y-m-d');
            } catch (\Exception $e) {
                return response()->json([
                    'message' => 'Invalid end date format. Use MM-DD-YYYY. Example: 04-07-2026',
                ], 422);
            }
        }
        // Fall back to latest dates if not provided
        if (!$postDate || !$endDate) {
            $latest   = $this->latestPostDate();
            $postDate ??= $latest['post_date'];
            $endDate  ??= $latest['end_date'];
        }

        $result = $dashboardService->applicantStatus($postDate, $endDate);
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
