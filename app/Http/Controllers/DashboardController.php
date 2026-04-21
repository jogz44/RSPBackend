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

        $result = $dashboardService->applicantStatus($postDate);

        return $result;
    }

    // get the total of applicant per office base on the jobpost
    public function applicantSummaryByOffice(DashboardService $dashboardService,Request $request)
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


        $result = $dashboardService->getApplicantSummaryByOffice($postDate);

        return $result;
    }

    // get the list of publication date
    public function publicationDate(DashboardService $dashboardService)
    {
        $result = $dashboardService->publication();

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

        $result = $dashboardService->jobList($postDate);

        return $result;
    }




    // total of funded and unfunded
    // total of occupied and unoccupied
    // total of  employee
    // public function getNumberOfPlantillaData(DashboardService $dashboardService)
    // {

    //     $result = $dashboardService->plantillaData();

    //     return $result;
    // }







}
