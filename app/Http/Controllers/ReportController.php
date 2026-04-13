<?php

namespace App\Http\Controllers;

use App\Models\JobBatchesRsp;
use App\Models\rating_score;
use App\Models\Submission;
use App\Services\ApplicantService;
use App\Services\ReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{


    protected $reportService;
    protected $applicantService;


    public function __construct(ReportService $reportService, ApplicantService $applicantService)
    {
        $this->reportService = $reportService;
        $this->applicantService = $applicantService;
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

        $competency = DB::table('vwplantillalevel')
            ->whereIn('ID', $ids)
            ->select('ID', 'SG', 'Level')
            ->get()
            ->keyBy('ID');


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
            $levelData = $competency[$job->ID] ?? null;

            $sheet->setCellValue("A{$row}", $no);
            $sheet->setCellValue("B{$row}", $job->position ?? '');
            $sheet->setCellValue("C{$row}", $job->ItemNo ?? '');
            $sheet->setCellValue("D{$row}", $job->SG ?? '');
            $sheet->setCellValue("E{$row}", $salary->Salary ?? '');
            $sheet->setCellValue("F{$row}", $qs->Education ?? '');
            $sheet->setCellValue("G{$row}", $qs->Training ?? '');
            $sheet->setCellValue("H{$row}", $qs->Experience ?? '');
            $sheet->setCellValue("I{$row}", $qs->Eligibility ?? '');
            // $sheet->setCellValue("J{$row}", $competency->competency()?? '');
            $sheet->setCellValue(
                "J{$row}",
                $levelData
                    ? $this->competency($levelData->SG, $levelData->Level)
                    : ''
            );

            $sheet->setCellValue(
                "K{$row}",
                implode(' - ', array_filter([
                    $job->office ?? '',
                    $job->office2 ?? '',
                    $job->group ?? '',
                    $job->division ?? '',
                    $job->section ?? '',
                ]))
            );
            // $sheet->setCellValue("K{$row}", $job-> ?? '');
            // $sheet->setCellValue("K{$row}", $job->office ?? '');

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


    private function competency($sg, $level)
    {
        $data = $this->competencyRules();

        $descriptions = $data['descriptions'];
        $secondLevel = $data['secondLevel'];
        $firstLevel = $data['firstLevel'];

        $competencies = [];

        // choose which level rule
        if ($level == 2) {
            $rules = $this->matchRange($sg, $secondLevel);
        } else {
            $rules = $this->matchRange($sg, $firstLevel);
        }

        if (!$rules) {
            return '';
        }

        foreach ($rules as $code => $rating) {

            if ($rating == '-') {
                continue;
            }

            $name = $descriptions['core'][$code]
                ?? $descriptions['technical'][$code]
                ?? $descriptions['leadership'][$code]
                ?? $code;

            $competencies[] = "{$name} ({$rating})";
        }

        return implode(", ", $competencies);
    }

    private function matchRange($sg, $rules)
    {
        foreach ($rules as $range => $value) {

            if (str_contains($range, '-')) {

                [$min, $max] = explode('-', $range);

                if ($sg >= $min && $sg <= $max) {
                    return $value;
                }
            } else {

                if ($sg == $range) {
                    return $value;
                }
            }
        }

        return null;
    }

    private function competencyRules()
    {
        $descriptions = [
            'core' => [
                'DSE' => 'Delivering Service Excellence',
                'EI'  => 'Exemplifying Integrity',
                'IS'  => 'Interpersonal Skills',
            ],
            'technical' => [
                'P&O' => 'Planning and Organizing',
                'M&E' => 'Monitoring and Evaluation',
                'RM'  => 'Records Management',
                'P&N' => 'Partnering and Networking',
                'PM'  => 'Process Management',
                'AD'  => 'Attention to Details',
            ],
            'leadership' => [
                'TSC'   => 'Thinking Strategically and Creatively',
                'PSDM'  => 'Problem Solving and Decision Making',
                'BCIWR' => 'Building Collaborative and Inclusive Working Relationships',
                'MPCR'  => 'Managing Performance and Coaching for Results',
            ],
        ];

        // LEVEL
        $secondLevel = [

            // SG
            '23-25' => [
                'DSE' => 'Superior',
                'EI' => 'Superior',
                'IS' => 'Superior',
                'P&O' => 'Superior',
                'M&E' => 'Superior',
                'RM' => 'Superior',
                'P&N' => 'Superior',
                'PM' => 'Superior',
                'AD' => 'Superior',
                'TSC' => 'Superior',
                'PSDM' => 'Superior',
                'BCIWR' => 'Superior',
                'MPCR' => 'Superior',
            ],
            '20-22' => [
                'DSE' => 'Superior',
                'EI' => 'Superior',
                'IS' => 'Superior',
                'P&O' => 'Superior',
                'M&E' => 'Superior',
                'RM' => 'Superior',
                'P&N' => 'Superior',
                'PM' => 'Superior',
                'AD' => 'Superior',
                'TSC' => 'Superior',
                'PSDM' => 'Superior',
                'BCIWR' => 'Superior',
                'MPCR' => 'Advanced',
            ],
            '18-19' => [
                'DSE' => 'Superior',
                'EI' => 'Superior',
                'IS' => 'Superior',
                'P&O' => 'Superior',
                'M&E' => 'Superior',
                'RM' => 'Superior',
                'P&N' => 'Superior',
                'PM' => 'Superior',
                'AD' => 'Superior',
                'TSC' => 'Advanced',
                'PSDM' => 'Advanced',
                'BCIWR' => 'Advanced',
                'MPCR' => 'Advanced',
            ],
            '15-17' => [
                'DSE' => 'Superior',
                'EI' => 'Superior',
                'IS' => 'Superior',
                'P&O' => 'Advanced',
                'M&E' => 'Advanced',
                'RM' => 'Advanced',
                'P&N' => 'Advanced',
                'PM' => 'Advanced',
                'AD' => 'Advanced',
                'TSC' => 'Intermediate',
                'PSDM' => 'Intermediate',
                'BCIWR' => 'Intermediate',
                'MPCR' => '-',
            ],
            '14' => [
                'DSE' => 'Advanced',
                'EI' => 'Advanced',
                'IS' => 'Advanced',
                'P&O' => '-',
                'M&E' => '-',
                'RM' => 'Advanced',
                'P&N' => '-',
                'PM' => 'Advanced',
                'AD' => 'Advanced',
                'TSC' => 'Intermediate',
                'PSDM' => 'Intermediate',
                'BCIWR' => 'Intermediate',
                'MPCR' => '-',
            ],
            '12-13' => [
                'DSE' => 'Advanced',
                'EI' => 'Advanced',
                'IS' => 'Advanced',
                'P&O' => '-',
                'M&E' => '-',
                'RM' => 'Advanced',
                'P&N' => '-',
                'PM' => 'Advanced',
                'AD' => 'Advanced',
                'TSC' => 'Basic',
                'PSDM' => 'Basic',
                'BCIWR' => 'Basic',
                'MPCR' => '-',
            ],
            '9-11' => [
                'DSE' => 'Advanced',
                'EI' => 'Advanced',
                'IS' => 'Advanced',
                'P&O' => '-',
                'M&E' => '-',
                'RM' => 'Intermediate',
                'P&N' => '-',
                'PM' => 'Intermediate',
                'AD' => 'Intermediate',
                'TSC' => 'Basic',
                'PSDM' => 'Basic',
                'BCIWR' => 'Basic',
                'MPCR' => '-',
            ],
        ];

        // Level
        $firstLevel = [

            // sg
            '18' => [
                'DSE' => 'Superior',
                'EI' => 'Superior',
                'IS' => 'Superior',
                'P&O' => 'Superior',
                'M&E' => 'Superior',
                'RM' => 'Superior',
                'P&N' => 'Superior',
                'PM' => 'Superior',
                'AD' => 'Superior',
                'TSC' => 'Advanced',
                'PSDM' => 'Advanced',
                'BCIWR' => 'Advanced',
                'MPCR' => 'Advanced',
            ],
            '14' => [
                'DSE' => 'Advanced',
                'EI' => 'Advanced',
                'IS' => 'Advanced',
                'P&O' => '-',
                'M&E' => '-',
                'RM' => 'Advanced',
                'P&N' => '-',
                'PM' => 'Advanced',
                'AD' => 'Advanced',
                'TSC' => 'Intermediate',
                'PSDM' => 'Intermediate',
                'BCIWR' => 'Intermediate',
                'MPCR' => '-',
            ],
            '13' => [
                'DSE' => 'Advanced',
                'EI' => 'Advanced',
                'IS' => 'Advanced',
                'P&O' => '-',
                'M&E' => '-',
                'RM' => 'Advanced',
                'P&N' => '-',
                'PM' => 'Advanced',
                'AD' => 'Advanced',
                'TSC' => 'Basic',
                'PSDM' => 'Basic',
                'BCIWR' => 'Basic',
                'MPCR' => '-',
            ],
            '11-12' => [
                'DSE' => 'Advanced',
                'EI' => 'Advanced',
                'IS' => 'Advanced',
                'P&O' => '-',
                'M&E' => '-',
                'RM' => 'Intermediate',
                'P&N' => '-',
                'PM' => 'Intermediate',
                'AD' => 'Intermediate',
                'TSC' => 'Basic',
                'PSDM' => 'Basic',
                'BCIWR' => 'Basic',
                'MPCR' => '-',
            ],
            '10' => [
                'DSE' => 'Intermediate',
                'EI' => 'Intermediate',
                'IS' => 'Intermediate',
                'P&O' => '-',
                'M&E' => '-',
                'RM' => 'Intermediate',
                'P&N' => '-',
                'PM' => 'Intermediate',
                'AD' => 'Intermediate',
                'TSC' => '-',
                'PSDM' => '-',
                'BCIWR' => '-',
                'MPCR' => '-',
            ],
            '8-9' => [
                'DSE' => 'Intermediate',
                'EI' => 'Intermediate',
                'IS' => 'Intermediate',
                'P&O' => '-',
                'M&E' => '-',
                'RM' => 'Basic',
                'P&N' => '-',
                'PM' => 'Basic',
                'AD' => 'Basic',
                'TSC' => '-',
                'PSDM' => '-',
                'BCIWR' => '-',
                'MPCR' => '-',
            ],
            '3-7' => [
                'DSE' => 'Basic',
                'EI' => 'Basic',
                'IS' => 'Basic',
                'P&O' => '-',
                'M&E' => '-',
                'RM' => 'Basic',
                'P&N' => '-',
                'PM' => 'Basic',
                'AD' => 'Basic',
                'TSC' => '-',
                'PSDM' => '-',
                'BCIWR' => '-',
                'MPCR' => '-',
            ],
        ];

        return compact('descriptions', 'secondLevel', 'firstLevel');
    }

    // fetch the rating of the rater on the  specific job post
    public function ratingFormReport(Request $request)
    {

        $validated = $request->validate([
            'job_batches_rsp_id' => 'required|exists:job_batches_rsp,id'
        ]);


    $data = $this->reportService->ratingFormQualificationStandard($validated);

        return $data;
    }


    // final summary of the applicant score on the specific job post
    public function finalSummaryRating($jobpostId, Request $request) // fetch the score of the applicant
    {

        $result = $this->applicantService->applicantFinalSummaryScore($jobpostId, $request);

        return $result;
    }


    // applicant raking per jobp post
    public function applicantRanking($jobpostId, Request $request) 
    {

        $result = $this->reportService->ranking($jobpostId, $request);

        return $result;
    }
}
