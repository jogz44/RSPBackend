<?php

namespace App\Http\Controllers;

use App\Exports\JobPositionsExport;
use App\Jobs\GeneratePlantillaReport;
use App\Jobs\GeneratePlantillaReportJob;
use App\Jobs\QueueWorkerTestJob;
use App\Models\JobBatchesRsp;
use App\Models\rating_score;
use App\Models\Submission;
use App\Services\RatingService;
use App\Services\ReportService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Calculation\Financial\Securities\Rates;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpParser\Node\Expr\FuncCall;
use PHPUnit\Util\PHP\Job;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{


    protected $reportService;


    public function __construct(ReportService $reportService)
    {
        $this->reportService = $reportService;
    }

    // generate report DBM
    public function reportDbm(Request $request)
    {

        $result = $this->reportService->dbm($request);

        return $result;
    }

    // generate report plantilla structure
    public function reportPlantilla()
    {

        $result = $this->reportService->plantilla();

        return $result;

    }

    // check plantilla status
    public function statusplantilla($jobId)
    {
        $result = $this->reportService->status($jobId);

        return $result;
    }


    // cancel generate report
    public function cancelPlantilla($jobId)
    {
        $result = $this->reportService->cancel($jobId);

        return $result;
    }

    // report job post with applicant
    public function getApplicantJobPost($jobpostId)
    {
        $jobs = Submission::where('job_batches_rsp_id', $jobpostId)
            ->with([
                'nPersonalInfo:id,firstname,lastname',
                'job_batch_rsp:id,Office,Position',
            ])
            ->get()
            ->map(function ($item) {
                return [
                    'n_personal_info' => $item->nPersonalInfo,
                    'submission_id' => $item->id,
                    // 'nPersonalInfo_id' => $item->nPersonalInfo_id,
                    // 'ControlNo' => $item->ControlNo,
                    // 'job_batches_rsp_id' => $item->job_batches_rsp_id,
                    // 'education_remark' => $item->education_remark,
                    // 'experience_remark' => $item->experience_remark,
                    // 'training_remark' => $item->training_remark,
                    // 'eligibility_remark' => $item->eligibility_remark,
                    // 'total_qs' => $item->total_qs,
                    // 'grand_total' => $item->grand_total,
                    // 'ranking' => $item->ranking,
                    'status' => $item->status,
                    // 'submitted' => $item->submitted,
                    'apply_date' => $item->created_at ? $item->created_at->format('F d, Y') : null,
                    'office' => $item->job_batch_rsp->Office ?? null,
                    'position' => $item->job_batch_rsp->Position ?? null,



                    // 'updated_at' => $item->updated_at,
                    // 'education_qualification' => $item->education_qualification,
                    // 'experience_qualification' => $item->experience_qualification,
                    // 'training_qualification' => $item->training_qualification,
                    // 'eligibility_qualification' => $item->eligibility_qualification,
                ];
            });

        return response()->json($jobs);
    }





    // report job post with applicant have schedules
    public function getApplicantHaveSchedules($jobpostId)
    {
        $jobs = Submission::where('job_batches_rsp_id', $jobpostId)
            ->with('schedules:id,submission_id,batch_name,full_name')
            ->get()
            ->map(function ($item) {

                $schedule = $item->schedules->first(); // if hasMany

                return [
                    'id' => $item->id,
                    'ApplicantHaveSchedules' => $item->schedules,

                    'batch_name' => $schedule?->batch_name,
                    'full_name' => $schedule?->full_name,
                ];
            });

        return response()->json($jobs);
    }


    // applicant final summary of rating qulification standard
    public function reportApplicantFinalScore($jobpostId)
    {
        $result = $this->reportService->applicantFinalScore($jobpostId);

        return $result;
    }

    // Palacement list
    public function placementList($office)
    {
      $result = $this->reportService->list($office);

      return $result;
    }

    // top 5 ranking applicant date publication
    public function topFiveApplicants($postDate)
    {
        $result = $this->reportService->topApplicant($postDate);

        return $result;
    }


    // list of qualified applicants  for job post publication
    public function listQualifiedApplicantsPublication($postDate)
    {
        $result = $this->reportService->listQualified($postDate);

        return $result;
    }

    // list of Unqualified applicants  for job post publication
    public function listUnQualifiedApplicantsPublication($postDate)
    {
        $result = $this->reportService->listUnQualified($postDate);

        return $result;
    }


    // //  export job request vacant position
    // public function exportJobRequestPosition(Request $request)
    // {
    //     $validated = $request->validate([
    //         'ids' => 'required|array'
    //     ]);

    //     $ids = $validated['ids'];
    //     $templatePath = storage_path('app/template/publicationVacant.xlsm');

    //     $spreadsheet = IOFactory::load($templatePath);
    //     $sheet = $spreadsheet->getActiveSheet();

    //     $jobs = DB::table('vwplantillaStructure')
    //         ->whereIn('ID', $ids)
    //         ->get();

    //     // Insert extra rows if more than 5 jobs (template has rows 18–22 pre-filled)
    //     $extraRows = count($jobs) - 5;
    //     if ($extraRows > 0) {
    //         $sheet->insertNewRowBefore(23, $extraRows);
    //     }

    //     $row = 18;
    //     $no = 1;

    //     foreach ($jobs as $job) {
    //         $qs = DB::table('yDesignationQS2')
    //             ->where('PositionID', $job->PositionID)
    //             ->first();

    //         $salary = DB::table('tblSalarySchedule')
    //             ->where('Grade', $job->SG)
    //             ->where('Steps', 1)  // double-check your column name: 'Steps' or 'Step'
    //             ->first();


    //         $sheet->setCellValue("A{$row}", $no);
    //         $sheet->setCellValue("B{$row}", $job->position ?? '');
    //         $sheet->setCellValue("C{$row}", $job->ItemNo ?? '');
    //         $sheet->setCellValue("D{$row}", $job->SG ?? '');
    //         $sheet->setCellValue("E{$row}", $salary->Salary ?? '');
    //         $sheet->setCellValue("F{$row}", $qs->Education ?? '');
    //         $sheet->setCellValue("G{$row}", $qs->Training ?? '');
    //         $sheet->setCellValue("H{$row}", $qs->Experience ?? '');
    //         $sheet->setCellValue("I{$row}", $qs->Eligibility ?? '');

    //         $row++;
    //         $no++;
    //     }

    //     $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');

    //     return new StreamedResponse(function () use ($writer) {
    //         $writer->save('php://output');
    //     }, 200, [
    //         'Content-Type'        => 'application/vnd.ms-excel.sheet.macroEnabled.12',
    //         'Content-Disposition' => 'attachment; filename="Request for Publication of Vacant Positions.xlsm"',
    //         'Cache-Control'       => 'max-age=0',
    //     ]);

    // }

    //  export job request vacant position
    public function exportJobRequestPosition(Request $request)
    {
        $validated = $request->validate([
            'ids' => 'required|array'
        ]);

        $ids = $validated['ids'];
        $templatePath = storage_path('app/template/publicationVacant.xlsm');

        $reader = IOFactory::createReader('Xlsx');
        $reader->setIncludeCharts(true);

        $spreadsheet = $reader->load($templatePath);

        // VERY IMPORTANT → preserve macros
        if ($spreadsheet->hasMacros()) {
            $spreadsheet->setMacrosCode($spreadsheet->getMacrosCode());
        }

        $sheet = $spreadsheet->getActiveSheet();

        $jobs = DB::table('vwplantillaStructure')
            ->whereIn('ID', $ids)
            ->get();

        // Insert extra rows if more than 5 jobs (template has rows 18–22 pre-filled)
        $extraRows = count($jobs) - 5;
        if ($extraRows > 0) {
            $sheet->insertNewRowBefore(23, $extraRows);
        }

        $row = 18;
        $no = 1;

        foreach ($jobs as $job) {
            $qs = DB::table('yDesignationQS2')
                ->where('PositionID', $job->PositionID)
                ->first();

            $salary = DB::table('tblSalarySchedule')
                ->where('Grade', $job->SG)
                ->where('Steps', 1)  // double-check your column name: 'Steps' or 'Step'
                ->first();


            $sheet->setCellValue("A{$row}", $no);
            $sheet->setCellValue("B{$row}", $job->position ?? '');
            $sheet->setCellValue("C{$row}", $job->ItemNo ?? '');
            $sheet->setCellValue("D{$row}", $job->SG ?? '');
            $sheet->setCellValue("E{$row}", $salary->Salary ?? '');
            $sheet->setCellValue("F{$row}", $qs->Education ?? '');
            $sheet->setCellValue("G{$row}", $qs->Training ?? '');
            $sheet->setCellValue("H{$row}", $qs->Experience ?? '');
            $sheet->setCellValue("I{$row}", $qs->Eligibility ?? '');

            $row++;
            $no++;
        }
        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->setIncludeCharts(true);

        return new StreamedResponse(function () use ($writer) {
            $writer->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.ms-excel.sheet.macroEnabled.12',
            'Content-Disposition' => 'attachment; filename="Request for Publication of Vacant Positions.xlsm"',
        ]);

    }




}
