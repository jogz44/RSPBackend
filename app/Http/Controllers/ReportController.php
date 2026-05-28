<?php

namespace App\Http\Controllers;

use App\Models\JobBatchesRsp;
use App\Models\rating_score;
use App\Models\Submission;
use App\Models\xService;
use App\Services\ApplicantService;
use App\Services\ExcelService;
use App\Services\ReportService;
use App\Traits\ApiResponseTrait;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{

    use ApiResponseTrait;
    protected $reportService;
    protected $applicantService;
    // protected $excelService;

    public function __construct(ReportService $reportService, ApplicantService $applicantService)
    {
        $this->reportService = $reportService;
        $this->applicantService = $applicantService;
        // $this->excelService = $excelService;
    }

    // generate report DBM
    public function reportDbm(Request $request)
    {

        $result = $this->reportService->dbm($request);

        return $result;
    }

    // generate report plantilla structure
    public function reportPlantilla(Request $request)
    {

        $result = $this->reportService->plantilla($request);

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
            $qs = DB::table('yDesignationQS')
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


        $user = Auth::user();
        if ($user instanceof \App\Models\User) {
            activity('Export Job Request Vacant Position')
                ->causedBy($user)
                ->withProperties([
                    'job_ids'      => $ids,
                    'total_jobs'   => count($ids),
                    'ip'           => $request->ip(),
                    'user_agent'   => $request->header('User-Agent'),
                ])
                ->log("{$user->name} exported " . count($ids) . " job request position(s).");
        }


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
            'job_batches_rsp_id' => 'required|exists:job_batches_rsp,id',
            'raterId' => 'required|exists:users,id'
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


    // get the applicant with there effective already appointed
    public function appointed(Request $request)
    {
        $validated = $request->validate([
            'publication_date' => 'required|date_format:Y-m-d',
            'effective_date'   => 'required|date_format:Y-m-d'
        ]);

        $result = $this->reportService->getEffectiveDate($validated);

        return  $result;
    }

    // fetch job post based on the post date all position
    public function listDate()
    {
        $dates = JobBatchesRsp::select('post_date')
            ->distinct()
            ->orderBy('post_date', 'desc')
            ->get();

        $formattedDate = $dates->map(function ($item) {
            return [
                // 'date'      => $item->post_date, // RAW date (for API logic)
                'date' => Carbon::parse($item->post_date)->format('F d, Y'), // UI only Carbon::parse($parsedDate)->format('F d, Y'),
            ];
        });

        return response()->json($formattedDate);
    }

    // fetch the list of job publication
    public function jobPublication(Request $request)
    {

        $validated = $request->validate([
            'post_date' => 'required|date_format:Y-m-d'
        ]);

        $jobPost = JobBatchesRsp::where('post_date', $validated['post_date'])

            ->get();


        $jobPost = $jobPost->map(function ($job) {

            $salary = DB::table('tblSalarySchedule')
                ->where('Grade', $job->SalaryGrade)
                ->where('Steps', 1)
                ->first();

            $job->monthly_salary = $salary
                ? '₱' . number_format($salary->Salary, 2)
                : null;

            return $job;
        });

        return response()->json($jobPost);
    }


    // the list of jobpost with status
    public function getlistOfJobExcel(Request $request)
    {
        $validated = $request->validate([
            'post_date' => 'required',
        ]);

        $templatePath = storage_path('app/template/List-of-Position.xlsx');
        $reader = IOFactory::createReader('Xlsx');
        $reader->setIncludeCharts(true);

        $spreadsheet = $reader->load($templatePath);

        if ($spreadsheet->hasMacros()) {
            $spreadsheet->setMacrosCode($spreadsheet->getMacrosCode());
        }

        $sheet = $spreadsheet->getActiveSheet();

        $jobpost = JobBatchesRsp::select('id', 'ItemNo', 'office', 'position', 'SalaryGrade', 'post_date', 'end_date')
            ->where('post_date', $validated['post_date'])
            ->distinct()
            ->orderBy('position', 'asc')
            ->orderBy('post_date', 'desc')
            ->whereRaw('LOWER(status) != ?', ['republished'])
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

        // ── Format publication date range from the first record ──────────────────
        $firstJob        = $jobpost->first();
        $postDate        = $firstJob ? Carbon::parse($firstJob->post_date)->format('F d, Y') : '';
        $endDate         = $firstJob ? Carbon::parse($firstJob->end_date)->format('F d, Y') : '';
        $publicationDate = $postDate && $endDate ? "{$postDate} - {$endDate}" : $postDate;

        // ── Set publication date in the sheet ─────────────────────────────────────
        $sheet->setCellValue("A3", $publicationDate);

        // ── Insert extra rows if data exceeds template rows ───────────────────────
        $extraRows = count($jobpost) - 5;
        if ($extraRows > 0) {
            $sheet->insertNewRowBefore(23, $extraRows);
        }

        $row = 7;
        $no  = 1;

        foreach ($jobpost as $job) {
            $sheet->setCellValue("A{$row}", $no);
            $sheet->setCellValue("B{$row}", $job->ItemNo);
            $sheet->setCellValue("C{$row}", $job->SalaryGrade);
            $sheet->setCellValue("D{$row}", $job->office);
            $sheet->setCellValue("E{$row}", $job->position);
            $sheet->setCellValue("F{$row}", $job->total_applicants);
            $sheet->setCellValue("G{$row}", $job->pending_count);
            $sheet->setCellValue("H{$row}", $job->qualified_count);
            $sheet->setCellValue("I{$row}", $job->unqualified_count);

            $row++;
            $no++;
        }

        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->setIncludeCharts(true);

        return new StreamedResponse(function () use ($writer) {
            $writer->save('php://output');
        }, 200, [
            'Content-Type'        => 'application/vnd.ms-excel.sheet.macroEnabled.12',
            'Content-Disposition' => 'attachment; filename="List-of-Position.xlsx"',
        ]);
    }

    public function getApplicantExcel(Request $request)
    {
        $validated = $request->validate([
            'post_date' => 'required',
        ]);

        $templatePath = storage_path('app/template/List-of-all-Applicant.xlsx');
        $reader = IOFactory::createReader('Xlsx');
        $reader->setIncludeCharts(true);

        $spreadsheet = $reader->load($templatePath);

        if ($spreadsheet->hasMacros()) {
            $spreadsheet->setMacrosCode($spreadsheet->getMacrosCode());
        }

        $sheet = $spreadsheet->getActiveSheet();

        // ── Get job post IDs for the given date ───────────────────────────────────
        $jobPosts = JobBatchesRsp::whereDate('post_date', $validated['post_date'])->get();

        if ($jobPosts->isEmpty()) {
            return $this->infoMessage('No job posts found for the given date', 200);
        }

        $jobPostIds = $jobPosts->pluck('id');


        // ── Format publication date range from the first job post ─────────────────
        $firstJob        = $jobPosts->first();
        $postDate        = $firstJob ? Carbon::parse($firstJob->post_date)->format('F d, Y') : '';
        $endDate         = $firstJob ? Carbon::parse($firstJob->end_date)->format('F d, Y') : '';
        $publicationDate = $postDate && $endDate ? "{$postDate} - {$endDate}" : $postDate;

        // ── Get all submissions for those job posts ───────────────────────────────
        $submissions = Submission::select(
            'id',
            'nPersonalInfo_id',
            'ControlNo',
            'job_batches_rsp_id',
            'status'
        )
            ->whereIn('job_batches_rsp_id', $jobPostIds)
            ->with([
                'nPersonalInfo:id,firstname,lastname,date_of_birth,cellphone_number,email_address,sex,ethnic_group,civil_status,residential_street,residential_barangay,residential_city,residential_province,Rpurok',
                'jobPost:id,Position,Office,SalaryGrade,ItemNo,status,post_date,end_date,level,tblStructureDetails_ID',
                'personal_declarations:id,nPersonalInfo_id,question_40b',   // for external PWD
                'xPersonal',               // for internal
                'xPersonalAddt',           // for internal address/contact
                'xPersonalDiversity',      // for internal ethnicity/PWD
            ])
            ->get();

        // ── Normalize submissions ─────────────────────────────────────────────────
        $normalized = $submissions->map(function ($submission) {
            $isExternal = !is_null($submission->nPersonalInfo_id);

            if ($isExternal && $submission->nPersonalInfo) {
                $p = $submission->nPersonalInfo;

                // ── Build address ─────────────────────────────────────────────────
                $purok    = $p->Rpurok                  ?? '';
                $street   = $p->residential_street      ?? '';
                $barangay = $p->residential_barangay    ?? '';
                $city     = $p->residential_city        ?? '';
                $province = $p->residential_province    ?? '';
                $address  = trim(implode(', ', array_filter([$purok, $street, $barangay, $city, $province])));

                $info = [
                    'firstname'        => trim($p->firstname),
                    'lastname'         => trim($p->lastname),
                    'date_of_birth'    => $p->date_of_birth,
                    'cellphone_number' => $p->cellphone_number ?? null,
                    'email_address'    => $p->email_address    ?? null,
                    'gender'           => $p->sex              ?? null,
                    'address'          => $address,
                    'ethnic'           => $p->ethnic_group     ?? null,
                    'pwd'              => $submission->personal_declarations->question_40b ?? null, // 
                    'civil_status'     => $p->civil_status     ?? null,
                ];
            } elseif (!$isExternal && $submission->xPersonal) {
                $x    = $submission->xPersonal;
                $xAdd = $submission->xPersonalAddt;
                $xDiv = $submission->xPersonalDiversity;

                // ── Build address ─────────────────────────────────────────────────
                $purok    = $xAdd->Rpurok    ?? '';
                $street   = $xAdd->Rstreet   ?? '';
                $barangay = $xAdd->Rbarangay ?? '';
                $city     = $xAdd->Rcity     ?? '';
                $province = $xAdd->Rprovince ?? '';
                $address  = trim(implode(', ', array_filter([$purok, $street, $barangay, $city, $province])));

                $info = [
                    'firstname'        => trim($x->Firstname),
                    'lastname'         => trim($x->Surname),
                    'date_of_birth'    => $x->BirthDate,
                    'cellphone_number' => $xAdd->CellphoneNo  ?? null,
                    'email_address'    => $xAdd->EmailAdd      ?? null,
                    'gender'           => $x->Sex              ?? null,  // ← fix: was email_address
                    'address'          => $address,
                    'ethnic'           => $xDiv->ethnicity     ?? null,
                    'pwd'              => $xDiv->PWD            ?? null,
                    'civil_status'     => $x->CivilStatus      ?? null,
                ];
            } else {
                $info = null;
            }

            return [
                'submission_id'    => $submission->id,
                'ControlNo'        => $submission->ControlNo,
                'applicant_status' => $submission->status,
                'applicant_type'   => $isExternal ? 'external' : 'internal',
                'personal_info'    => $info,
                'job_post'         => $submission->jobPost,
            ];
        })->filter(fn($s) => !is_null($s['personal_info']))->values();

        // ── Set publication date ──────────────────────────────────────────────────
        $sheet->setCellValue("A2", $publicationDate);

        // ── Insert extra rows if data exceeds template rows ───────────────────────
        $extraRows = $normalized->count() - 5;
        if ($extraRows > 0) {
            $sheet->insertNewRowBefore(23, $extraRows);
        }

        // ── Write data to sheet ───────────────────────────────────────────────────
        $row = 7;
        $no  = 1;

        foreach ($normalized as $item) {
            $info     = $item['personal_info'];
            $jobPost  = $item['job_post'];
            $fullName = trim($info['lastname'] . ', ' . $info['firstname']);

            // ── Status mapping ────────────────────────────────────────────────────
            $statusMap = [
                'qualified'   => 'PRE-QUALIFIED',
                'unqualified' => 'FOR QS VALIDATION',
                'pending'     => 'PENDING',
                'hired'       => 'HIRED',
            ];
            $mappedStatus = $statusMap[strtolower($item['applicant_status'])]
                ?? strtoupper($item['applicant_status']);
                   $positionLevel = null;
    if ($jobPost?->tblStructureDetails_ID) {
        $positionLevel = DB::table('vwplantillalevel')
            ->where('ID', $jobPost->tblStructureDetails_ID)
            ->value('level'); // ← adjust 'level' to the actual column name you need
         }


            $sheet->setCellValue("A{$row}", $no);
            $sheet->setCellValue("B{$row}", $fullName);
            $sheet->setCellValue("C{$row}", $jobPost?->Position);
            $sheet->setCellValue("D{$row}", $positionLevel);          // ← correct
            $sheet->setCellValue("E{$row}", $mappedStatus);           // ← mapped status
            $sheet->setCellValue("F{$row}", $jobPost?->SalaryGrade);
            $sheet->setCellValue("G{$row}", $info['gender']       ?? '');
            $sheet->setCellValue("H{$row}", $info['civil_status'] ?? '');
            $sheet->setCellValue("I{$row}", $info['address']      ?? '');
            $sheet->setCellValue("J{$row}", $info['ethnic']       ?? '');
            $sheet->setCellValue("K{$row}", $info['pwd']          ?? '');

            $row++;
            $no++;
        }

        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->setIncludeCharts(true);

        return new StreamedResponse(function () use ($writer) {
            $writer->save('php://output');
        }, 200, [
            'Content-Type'        => 'application/vnd.ms-excel.sheet.macroEnabled.12',
            'Content-Disposition' => 'attachment; filename="List-of-all-Applicant.xlsx"',
        ]);
    }


    
    public function getApplicantQualifiedExcel(Request $request)
    {
        $validated = $request->validate([
            'post_date' => 'required',
        ]);

        $templatePath = storage_path('app/template/List-of-Prequalified-Applicant.xlsx');
        $reader = IOFactory::createReader('Xlsx');
        $reader->setIncludeCharts(true);

        $spreadsheet = $reader->load($templatePath);

        if ($spreadsheet->hasMacros()) {
            $spreadsheet->setMacrosCode($spreadsheet->getMacrosCode());
        }

        $sheet = $spreadsheet->getActiveSheet();

        // ── Get job post IDs for the given date ───────────────────────────────────
        $jobPosts = JobBatchesRsp::whereDate('post_date', $validated['post_date'])->get();

        if ($jobPosts->isEmpty()) {
            return $this->infoMessage('No job posts found for the given date', 200);
        }

        $jobPostIds = $jobPosts->pluck('id');


        // ── Format publication date range from the first job post ─────────────────
        $firstJob        = $jobPosts->first();
        $postDate        = $firstJob ? Carbon::parse($firstJob->post_date)->format('F d, Y') : '';
        $endDate         = $firstJob ? Carbon::parse($firstJob->end_date)->format('F d, Y') : '';
        $publicationDate = $postDate && $endDate ? "{$postDate} - {$endDate}" : $postDate;

        // ── Get all submissions for those job posts ───────────────────────────────
        $submissions = Submission::select(
            'id',
            'nPersonalInfo_id',
            'ControlNo',
            'job_batches_rsp_id',
            'status'
        )->where('status','Qualified')
            ->whereIn('job_batches_rsp_id', $jobPostIds)
            ->with([
                'nPersonalInfo:id,firstname,lastname,date_of_birth,cellphone_number,email_address,sex,ethnic_group,civil_status,residential_street,residential_barangay,residential_city,residential_province,Rpurok',
                'jobPost:id,Position,Office,SalaryGrade,ItemNo,status,post_date,end_date,level,tblStructureDetails_ID',
                'personal_declarations:id,nPersonalInfo_id,question_40b',   // for external PWD
                'xPersonal',               // for internal
                'xPersonalAddt',           // for internal address/contact
                'xPersonalDiversity',      // for internal ethnicity/PWD
            ])
            ->get();

        // ── Normalize submissions ─────────────────────────────────────────────────
        $normalized = $submissions->map(function ($submission) {
            $isExternal = !is_null($submission->nPersonalInfo_id);

            if ($isExternal && $submission->nPersonalInfo) {
                $p = $submission->nPersonalInfo;

                // ── Build address ─────────────────────────────────────────────────
                $purok    = $p->Rpurok                  ?? '';
                $street   = $p->residential_street      ?? '';
                $barangay = $p->residential_barangay    ?? '';
                $city     = $p->residential_city        ?? '';
                $province = $p->residential_province    ?? '';
                $address  = trim(implode(', ', array_filter([$purok, $street, $barangay, $city, $province])));

                $info = [
                    'firstname'        => trim($p->firstname),
                    'lastname'         => trim($p->lastname),
                    'date_of_birth'    => $p->date_of_birth,
                    'cellphone_number' => $p->cellphone_number ?? null,
                    'email_address'    => $p->email_address    ?? null,
                    'gender'           => $p->sex              ?? null,
                    'address'          => $address,
                    'ethnic'           => $p->ethnic_group     ?? null,
                    'pwd'              => $submission->personal_declarations->question_40b ?? null, // 
                    'civil_status'     => $p->civil_status     ?? null,
                ];
            } elseif (!$isExternal && $submission->xPersonal) {
                $x    = $submission->xPersonal;
                $xAdd = $submission->xPersonalAddt;
                $xDiv = $submission->xPersonalDiversity;

                // ── Build address ─────────────────────────────────────────────────
                $purok    = $xAdd->Rpurok    ?? '';
                $street   = $xAdd->Rstreet   ?? '';
                $barangay = $xAdd->Rbarangay ?? '';
                $city     = $xAdd->Rcity     ?? '';
                $province = $xAdd->Rprovince ?? '';
                $address  = trim(implode(', ', array_filter([$purok, $street, $barangay, $city, $province])));

                $info = [
                    'firstname'        => trim($x->Firstname),
                    'lastname'         => trim($x->Surname),
                    'date_of_birth'    => $x->BirthDate,
                    'cellphone_number' => $xAdd->CellphoneNo  ?? null,
                    'email_address'    => $xAdd->EmailAdd      ?? null,
                    'gender'           => $x->Sex              ?? null,  // ← fix: was email_address
                    'address'          => $address,
                    'ethnic'           => $xDiv->ethnicity     ?? null,
                    'pwd'              => $xDiv->PWD            ?? null,
                    'civil_status'     => $x->CivilStatus      ?? null,
                ];
            } else {
                $info = null;
            }

            return [
                'submission_id'    => $submission->id,
                'ControlNo'        => $submission->ControlNo,
                'applicant_status' => $submission->status,
                'applicant_type'   => $isExternal ? 'external' : 'internal',
                'personal_info'    => $info,
                'job_post'         => $submission->jobPost,
            ];
        })->filter(fn($s) => !is_null($s['personal_info']))->values();

        // ── Set publication date ──────────────────────────────────────────────────
        $sheet->setCellValue("A2", $publicationDate);

        // ── Insert extra rows if data exceeds template rows ───────────────────────
        $extraRows = $normalized->count() - 5;
        if ($extraRows > 0) {
            $sheet->insertNewRowBefore(23, $extraRows);
        }

        // ── Write data to sheet ───────────────────────────────────────────────────
        $row = 5;
        $no  = 1;

        foreach ($normalized as $item) {
            $info     = $item['personal_info'];
            $jobPost  = $item['job_post'];
            $fullName = trim($info['lastname'] . ', ' . $info['firstname']);

            // ── Status mapping ────────────────────────────────────────────────────
            $statusMap = [
                'qualified'   => 'PRE-QUALIFIED',
            ];
            $mappedStatus = $statusMap[strtolower($item['applicant_status'])]
                ?? strtoupper($item['applicant_status']);
                   $positionLevel = null;
    if ($jobPost?->tblStructureDetails_ID) {
        $positionLevel = DB::table('vwplantillalevel')
            ->where('ID', $jobPost->tblStructureDetails_ID)
            ->value('level'); // ← adjust 'level' to the actual column name you need
         }


            $sheet->setCellValue("A{$row}", $no);
            $sheet->setCellValue("B{$row}", $fullName);
            $sheet->setCellValue("C{$row}", $jobPost?->Position);
            $sheet->setCellValue("D{$row}", $positionLevel);          // ← correct
            // $sheet->setCellValue("E{$row}", $mappedStatus);           // ← mapped status
            $sheet->setCellValue("E{$row}", $jobPost?->SalaryGrade);
            $sheet->setCellValue("F{$row}", $info['gender']       ?? '');
            $sheet->setCellValue("G{$row}", $info['civil_status'] ?? '');
            $sheet->setCellValue("H{$row}", $info['address']      ?? '');
            $sheet->setCellValue("I{$row}", $info['ethnic']       ?? '');
            $sheet->setCellValue("J{$row}", $info['pwd']          ?? '');

            $row++;
            $no++;
        }

        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->setIncludeCharts(true);

        return new StreamedResponse(function () use ($writer) {
            $writer->save('php://output');
        }, 200, [
            'Content-Type'        => 'application/vnd.ms-excel.sheet.macroEnabled.12',
            'Content-Disposition' => 'attachment; filename="List-of-Prequalified-Applicant.xlsx"',
        ]);
    }

   
   
}
