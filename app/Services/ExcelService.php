<?php

namespace App\Services;

use App\Models\JobBatchesRsp;
use App\Models\rating_score;
use App\Models\Submission;
use App\Models\User;
use App\Models\vwActive;
use App\Models\vwplantillastructure;
use App\Models\xService;
use App\Traits\ApiResponseTrait;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExcelService
{
    /**
     * Create a new class instance.
     */
    // public function __construct()
    // {
    //     //
    // }

    use ApiResponseTrait;

    // the list of jobpost with status
    public function getlistOfJobExcel($validated)
    {


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
        $publicationDate = $postDate && $endDate ? "PUBLICATION DATE: {$postDate} - {$endDate}" : $postDate;

        // ── Set publication date in the sheet ─────────────────────────────────────
        $sheet->setCellValue("A3", $publicationDate);

        // ── Insert extra rows if data exceeds template rows ───────────────────────
        // $extraRows = count($jobpost) - 5;
        // if ($extraRows > 0) {
        //     $sheet->insertNewRowBefore(23, $extraRows);
        // }

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

    public function getApplicantExcel($validated)
    {


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
        $publicationDate = $postDate && $endDate ? "PUBLICATION DATE: {$postDate} - {$endDate}" : $postDate;

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
        $sheet->setCellValue("A3", $publicationDate);

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
                'pending'     => 'FOR ASSESSMENT',
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

        // ── Write demographic data to Summary sheet ───────────────────────────────
        $demo = $this->demographic();
        $summary = $demo['summary'];

        $summarySheet = $spreadsheet->getSheetByName('Summary');

        if ($summarySheet) {
            // ── Totals ────────────────────────────────────────────────────────────
            // $summarySheet->setCellValue('B2', $demo['total_application_actual']);
            // $summarySheet->setCellValue('B3', $demo['external_actual']);
            // $summarySheet->setCellValue('B4', $demo['internal_actual']);


            $summarySheet->setCellValue("B3", $publicationDate);
            // ── gender ────────────────────────────────────────────────────────────────
            $summarySheet->setCellValue('D6',  $summary['combined']['gender']['male']);
            $summarySheet->setCellValue('D7',  $summary['combined']['gender']['female']);

            // ── civil_status ────────────────────────────────────────────────────────────────
            $summarySheet->setCellValue('D8',  $summary['combined']['civil_status']['single']);
            $summarySheet->setCellValue('D9',  $summary['combined']['civil_status']['married']);
            $summarySheet->setCellValue('D10',  $summary['combined']['civil_status']['separated']);

            // ── IP ────────────────────────────────────────────────────────────────
            $summarySheet->setCellValue('D11',  $summary['combined']['ip']['yes']);
            $summarySheet->setCellValue('D12',  $summary['combined']['ip']['no']);

            // ── solo parent ────────────────────────────────────────────────────────────────
            $summarySheet->setCellValue('D13',  $summary['combined']['solo_parent']['yes']);
            $summarySheet->setCellValue('D14',  $summary['combined']['solo_parent']['no']);

            // ── solo pwd ────────────────────────────────────────────────────────────────
            $summarySheet->setCellValue('D15',  $summary['combined']['pwd']['yes']);
            $summarySheet->setCellValue('D16',  $summary['combined']['pwd']['no']);
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



    public function getApplicantQualifiedExcel($validated)
    {


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
        $publicationDate = $postDate && $endDate ? "PUBLICATION DATE: {$postDate} - {$endDate}" : $postDate;

        // ── Get all submissions for those job posts ───────────────────────────────
        $submissions = Submission::select(
            'id',
            'nPersonalInfo_id',
            'ControlNo',
            'job_batches_rsp_id',
            'status'
        )->where('status', 'Qualified')
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
        $sheet->setCellValue("A3", $publicationDate);

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

    public function getApplicantUnQualifiedExcel($validated)
    {

        $templatePath = storage_path('app/template/List-of-For-QS-Validation-Applicant.xlsx');
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
        $publicationDate = $postDate && $endDate ? "PUBLICATION DATE: {$postDate} - {$endDate}" : $postDate;

        // ── Get all submissions for those job posts ───────────────────────────────
        $submissions = Submission::select(
            'id',
            'nPersonalInfo_id',
            'ControlNo',
            'job_batches_rsp_id',
            'status'
        )->where('status', 'Unqualified')
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
        $sheet->setCellValue("A3", $publicationDate);

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
            'Content-Disposition' => 'attachment; filename="List-of-For-QS-Validation-Applicant.xlsx"',
        ]);
    }


    // ✅ Private helper — returns array, not JSON response

    // ✅ Private helper — returns array, not JSON response
    public function demographic()
    {
        $external = Submission::query()
            ->join('nPersonalInfo as p', 'submission.nPersonalInfo_id', '=', 'p.id')
            ->leftJoin('personal_declarations as pd', 'pd.nPersonalInfo_id', '=', 'p.id')
            ->select(
                DB::raw('MIN(p.id) as nPersonal_id'),
                'p.firstname',
                'p.lastname',
                'p.sex',
                'p.civil_status',
                DB::raw('CAST(p.date_of_birth AS VARCHAR(20)) as date_of_birth'),
                DB::raw('COUNT(submission.id) as jobpost'),
                DB::raw("'external' as applicant_type"),
                DB::raw('NULL as ControlNo'),
                DB::raw('pd.question_40a as ip'),          // ✅ aliased to ip
                DB::raw('pd.question_40b as pwd'),         // ✅ aliased to pwd
                DB::raw('pd.question_40c as solo_parent')  // ✅ aliased to solo_parent
            )
            ->groupBy(
                'p.firstname',
                'p.lastname',
                'p.date_of_birth',
                'pd.question_40a',
                'pd.question_40b',
                'pd.question_40c',
                'p.sex',
                'p.civil_status',
            );

        $internal = Submission::query()
            ->whereNull('submission.nPersonalInfo_id')
            ->join('xPersonal as xp', 'submission.ControlNo', '=', 'xp.ControlNo')
            ->join('xPersonalAddt as xpdt', 'submission.ControlNo', '=', 'xpdt.ControlNo')
            ->select(
                DB::raw('NULL as nPersonal_id'),
                'xp.Firstname as firstname',
                'xp.Surname as lastname',
                'xp.Sex as sex',
                'xp.CivilStatus as civil_status',
                DB::raw('CONVERT(VARCHAR(20), xp.BirthDate, 101) as date_of_birth'),
                DB::raw('COUNT(submission.id) as jobpost'),
                DB::raw("'internal' as applicant_type"),
                'submission.ControlNo',
                'xpdt.IP as ip',        // ✅ real data, no duplicate NULL below
                'xpdt.PWD as pwd',
                'xpdt.SOLOP as solo_parent'
            )
            ->groupBy(
                'xp.Firstname',
                'xp.Surname',
                'xp.BirthDate',
                'submission.ControlNo',
                'xpdt.IP',
                'xpdt.PWD',
                'xpdt.SOLOP',
                'xp.Sex',
                'xp.CivilStatus',
            );

        $query = $external->unionAll($internal);

        $results = DB::table(DB::raw("({$query->toSql()}) as combined"))
            ->mergeBindings($query->getQuery())
            ->get();
        $internalCount = $results->where('applicant_type', 'internal')->count();
        $externalCount = $results->where('applicant_type', 'external')->count();

        $externalResults = $results->where('applicant_type', 'external');
        $internalResults = $results->where('applicant_type', 'internal');

        // ── Gender counts ─────────────────────────────────────────────────────────
        $extGenderCounts = $externalResults->groupBy(fn($r) => strtolower($r->sex ?? 'unknown'));
        $intGenderCounts = $internalResults->groupBy(fn($r) => strtolower($r->sex ?? 'unknown'));

        // ── Civil status counts ───────────────────────────────────────────────────
        $extCivilCounts = $externalResults->groupBy(fn($r) => strtolower($r->civil_status ?? 'unknown'));
        $intCivilCounts = $internalResults->groupBy(fn($r) => strtolower($r->civil_status ?? 'unknown'));

        // ── IP / PWD / Solo Parent counts ────────────────────────────────────────
        $extIpCounts         = $externalResults->groupBy(fn($r) => strtolower($r->ip         ?? 'no'));
        $extPwdCounts        = $externalResults->groupBy(fn($r) => strtolower($r->pwd        ?? 'no'));
        $extSoloParentCounts = $externalResults->groupBy(fn($r) => strtolower($r->solo_parent ?? 'no'));

        $intIpCounts         = $internalResults->groupBy(fn($r) => strtolower($r->ip         ?? 'no'));
        $intPwdCounts        = $internalResults->groupBy(fn($r) => strtolower($r->pwd        ?? 'no'));
        $intSoloParentCounts = $internalResults->groupBy(fn($r) => strtolower($r->solo_parent ?? 'no'));

        return [
            'internal_actual'          => $internalCount,
            'external_actual'          => $externalCount,
            'total_application_actual' => $internalCount + $externalCount,
            'summary' => [
                'external' => [
                    'gender' => [
                        'male'   => $extGenderCounts->get('male')?->count()   ?? 0,
                        'female' => $extGenderCounts->get('female')?->count() ?? 0,
                    ],
                    'civil_status' => [
                        'single'    => $extCivilCounts->get('single')?->count()    ?? 0,
                        'married'   => $extCivilCounts->get('married')?->count()   ?? 0,
                        'separated' => $extCivilCounts->get('separated')?->count() ?? 0,
                    ],
                    'ip' => [
                        'yes' => $extIpCounts->get('yes')?->count() ?? 0,
                        'no'  => $extIpCounts->get('no')?->count()  ?? 0,
                    ],
                    'pwd' => [
                        'yes' => $extPwdCounts->get('yes')?->count() ?? 0,
                        'no'  => $extPwdCounts->get('no')?->count()  ?? 0,
                    ],
                    'solo_parent' => [
                        'yes' => $extSoloParentCounts->get('yes')?->count() ?? 0,
                        'no'  => $extSoloParentCounts->get('no')?->count()  ?? 0,
                    ],
                ],
                'internal' => [
                    'gender' => [
                        'male'   => $intGenderCounts->get('male')?->count()   ?? 0,
                        'female' => $intGenderCounts->get('female')?->count() ?? 0,
                    ],
                    'civil_status' => [
                        'single'    => $intCivilCounts->get('single')?->count()    ?? 0,
                        'married'   => $intCivilCounts->get('married')?->count()   ?? 0,
                        'separated' => $intCivilCounts->get('separated')?->count() ?? 0,
                    ],
                    'ip' => [
                        'yes' => $intIpCounts->get('yes')?->count() ?? 0,
                        'no'  => $intIpCounts->get('no')?->count()  ?? 0,
                    ],
                    'pwd' => [
                        'yes' => $intPwdCounts->get('yes')?->count() ?? 0,
                        'no'  => $intPwdCounts->get('no')?->count()  ?? 0,
                    ],
                    'solo_parent' => [
                        'yes' => $intSoloParentCounts->get('yes')?->count() ?? 0,
                        'no'  => $intSoloParentCounts->get('no')?->count()  ?? 0,
                    ],
                ],
                'combined' => [
                    'gender' => [
                        'male'   => ($extGenderCounts->get('male')?->count()   ?? 0) + ($intGenderCounts->get('male')?->count()   ?? 0),
                        'female' => ($extGenderCounts->get('female')?->count() ?? 0) + ($intGenderCounts->get('female')?->count() ?? 0),
                    ],
                    'civil_status' => [
                        'single'    => ($extCivilCounts->get('single')?->count()    ?? 0) + ($intCivilCounts->get('single')?->count()    ?? 0),
                        'married'   => ($extCivilCounts->get('married')?->count()   ?? 0) + ($intCivilCounts->get('married')?->count()   ?? 0),
                        'separated' => ($extCivilCounts->get('separated')?->count() ?? 0) + ($intCivilCounts->get('separated')?->count() ?? 0),
                    ],
                    'ip' => [
                        'yes' => ($extIpCounts->get('yes')?->count() ?? 0) + ($intIpCounts->get('yes')?->count() ?? 0),
                        'no'  => ($extIpCounts->get('no')?->count()  ?? 0) + ($intIpCounts->get('no')?->count()  ?? 0),
                    ],
                    'pwd' => [
                        'yes' => ($extPwdCounts->get('yes')?->count() ?? 0) + ($intPwdCounts->get('yes')?->count() ?? 0),
                        'no'  => ($extPwdCounts->get('no')?->count()  ?? 0) + ($intPwdCounts->get('no')?->count()  ?? 0),
                    ],
                    'solo_parent' => [
                        'yes' => ($extSoloParentCounts->get('yes')?->count() ?? 0) + ($intSoloParentCounts->get('yes')?->count() ?? 0),
                        'no'  => ($extSoloParentCounts->get('no')?->count()  ?? 0) + ($intSoloParentCounts->get('no')?->count()  ?? 0),
                    ],
                ],
            ],
        ];
    }


    public function internalApplicantDesignation($validated)
    {
        $templatePath = storage_path('app/template/List-of-Internal-Prequalified-Applicant.xlsx');
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
        $publicationDate = $postDate && $endDate ? "PUBLICATION DATE: {$postDate} - {$endDate}" : $postDate;

        // ── Get only internal submissions (ControlNo set, no nPersonalInfo_id) ────
        $submissions = Submission::select(
            'id',
            'nPersonalInfo_id',
            'ControlNo',
            'job_batches_rsp_id',
            'status'
        )
            ->where('status', 'Qualified')
            ->whereNotNull('ControlNo')
            ->whereNull('nPersonalInfo_id')
            ->whereIn('job_batches_rsp_id', $jobPostIds)
            ->with([
                'jobPost:id,Position,Office,SalaryGrade,ItemNo,status,post_date,end_date,level,tblStructureDetails_ID',
                'xPersonal',
                'xPersonalAddt',
                'xPersonalDiversity',
            ])
            ->get();

        if ($submissions->isEmpty()) {
            return $this->infoMessage('No internal qualified applicants found for the given date', 200);
        }

        // ── Bulk-fetch plantilla levels to avoid N+1 ─────────────────────────────
        $controlNos = $submissions->pluck('ControlNo');

        $employeeStatuses = vwActive::select('ControlNo', 'Firstname', 'Surname', 'Designation', 'Office', 'Status', 'Grades')
            ->whereIn('ControlNo', $controlNos)
            ->get()
            ->keyBy('ControlNo');

        // Step 1: get the plantilla structure ID per ControlNo
        $plantillaStructures = vwplantillastructure::select('ControlNo', 'ID')
            ->whereIn('ControlNo', $controlNos)
            ->get()
            ->keyBy('ControlNo');

        // Step 2: collect all structure IDs, then fetch their levels in one query
        $structureIds = $plantillaStructures->pluck('ID')->filter()->unique();

        $plantillaLevels = DB::table('vwplantillalevel')
            ->whereIn('ID', $structureIds)
            ->pluck('level', 'ID'); // keyed by ID → level value

        // ── Normalize submissions ─────────────────────────────────────────────────
        // ── Normalize submissions ─────────────────────────────────────────────────
        $normalized = $submissions->map(function ($submission) use ($employeeStatuses, $plantillaStructures, $plantillaLevels) {

            $x = $submission->xPersonal;
            if (!$x) return null;

            $employeement_status = $employeeStatuses->get($submission->ControlNo);

            // Resolve position level per submission's job post
            $plantillaStructure = $plantillaStructures->get($submission->ControlNo);
            $structureId        = $plantillaStructure?->ID;
            $positionLevel      = $structureId ? ($plantillaLevels->get($structureId) ?? '') : '';

            return [
                'submission_id'    => $submission->id,
                'ControlNo'        => $submission->ControlNo,
                'applicant_status' => $submission->status,
                'personal_info'    => [
                    'firstname'        => trim($x->Firstname),
                    'lastname'         => trim($x->Surname),
                    'current_position' => $employeement_status->Designation ?? '',
                    'office'           => $employeement_status->Office      ?? '',
                    'status'           => $employeement_status->Status      ?? '',
                    'sg'               => $employeement_status->Grades      ?? '',
                    'position_level'   => $positionLevel,
                ],
                'job_post' => $submission->jobPost,
            ];
        })->filter()->values();

        // ── Deduplicate: one row per ControlNo, collect all job posts ─────────────
        $deduplicated = $normalized
            ->groupBy('ControlNo')
            ->map(function ($group) {
                $first = $group->first();

                return [
                    'ControlNo'     => $first['ControlNo'],
                    'personal_info' => $first['personal_info'],
                    'job_posts'     => $group->map(fn($item) => $item['job_post'])->filter()->values(),
                ];
            })
            ->values();

        if ($normalized->isEmpty()) {
            return $this->infoMessage('No internal applicant records could be resolved', 200);
        }

        // ── Set publication date ──────────────────────────────────────────────────
        $sheet->setCellValue("A3", $publicationDate);

        // ── Insert extra rows if data exceeds template rows ───────────────────────
        $extraRows = $normalized->count() - 5;
        if ($extraRows > 0) {
            $sheet->insertNewRowBefore(23, $extraRows);
        }

        // ── Write data to sheet ───────────────────────────────────────────────────
        // ── Write data to sheet ───────────────────────────────────────────────────
        $row = 7;
        $no  = 1;

        foreach ($deduplicated as $item) {
            $info     = $item['personal_info'];
            $fullName = trim($info['lastname'] . ', ' . $info['firstname']);

            // Join all positions applied for into one cell
            $positionsApplied = $item['job_posts']
                ->map(fn($jp) => $jp?->Position)
                ->filter()
                ->implode(', ');

            $sheet->setCellValue("A{$row}", $no);
            $sheet->setCellValue("B{$row}", $fullName);
            $sheet->setCellValue("C{$row}", $info['current_position']);
            $sheet->setCellValue("D{$row}", $info['office']);
            $sheet->setCellValue("E{$row}", $info['position_level']);
            $sheet->setCellValue("F{$row}", $info['status']);
            $sheet->setCellValue("G{$row}", $info['sg']);
            // $sheet->setCellValue("H{$row}", $positionsApplied); // all jobs applied

            $row++;
            $no++;
        }

        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->setIncludeCharts(true);

        return new StreamedResponse(function () use ($writer) {
            $writer->save('php://output');
        }, 200, [
            'Content-Type'        => 'application/vnd.ms-excel.sheet.macroEnabled.12',
            'Content-Disposition' => 'attachment; filename="List-of-Internal-Prequalified-Applicant.xlsx"',
        ]);
    }

    // internal only with lenght of service and  current designation
    public function internalService($validated)
    {
        $templatePath = storage_path('app/template/applicantInternalService.xlsx');
        $reader = IOFactory::createReader('Xlsx');
        $reader->setIncludeCharts(true);

        $spreadsheet = $reader->load($templatePath);

        if ($spreadsheet->hasMacros()) {
            $spreadsheet->setMacrosCode($spreadsheet->getMacrosCode());
        }

        $sheet = $spreadsheet->getActiveSheet();
        ini_set('max_execution_time', 3600);

        try {
            // ── 1. Parse the incoming date (handles "April 27, 2026" or "2026-04-27") ──
            $postDate         = $validated['publication_date'];
            $parsedDate       = \Carbon\Carbon::parse($postDate)->format('Y-m-d');
            $publicationDate  = \Carbon\Carbon::parse($parsedDate)->format('F d, Y');

            // ── 2. Get all job post IDs for that post_date ────────────────────────────
            $jobPostIds = JobBatchesRsp::whereDate('post_date', $parsedDate)
                ->pluck('id');

            if ($jobPostIds->isEmpty()) {
                return $this->infoMessage('No job posts found for the given date', 200);
            }

            // ── 3. Get all submissions for those job posts (INTERNAL ONLY) ────────────
            $submissions = Submission::select(
                'id',
                'ControlNo',
                'job_batches_rsp_id',
                'status'
            )
                ->whereIn('job_batches_rsp_id', $jobPostIds)
                ->whereNotNull('ControlNo')
                ->whereNull('nPersonalInfo_id')
                ->with([
                    'xPersonal:ControlNo,Firstname,Surname,BirthDate',
                    'xPersonalAddt:ControlNo,EmailAdd,CellphoneNo',
                    'jobPost:id,Position,Office,SalaryGrade,ItemNo,status,PageNo',
                ])
                ->get();

            // ── 4. Normalize each submission into a flat structure ────────────────────
            $normalized = $submissions->map(function ($submission) {
                if (! $submission->xPersonal) {
                    return null;
                }

                $info = [
                    'control_no'       => $submission->xPersonal->ControlNo,
                    'firstname'        => trim($submission->xPersonal->Firstname),
                    'lastname'         => trim($submission->xPersonal->Surname),
                    'date_of_birth'    => $submission->xPersonal->BirthDate,
                    'email_address'    => $submission->xPersonalAddt->EmailAdd ?? null,
                    'cellphone_number' => $submission->xPersonalAddt->CellphoneNo ?? null,
                ];

                return [
                    'submission_id'      => $submission->id,
                    'ControlNo'          => $submission->ControlNo,
                    'job_batches_rsp_id' => $submission->job_batches_rsp_id,
                    'applicant_status'   => $submission->status,
                    'personal_info'      => $info,
                    'job_post'           => $submission->jobPost,
                ];
            })->filter();

            // ── 5. Group by person: lowercase name + normalized birthdate ─────────────
            $grouped = $normalized->groupBy(function ($item) {
                $firstname = strtolower(trim($item['personal_info']['firstname']));
                $lastname  = strtolower(trim($item['personal_info']['lastname']));

                try {
                    $dob = \Carbon\Carbon::parse($item['personal_info']['date_of_birth'])->format('Y-m-d');
                } catch (\Exception $e) {
                    $dob = $item['personal_info']['date_of_birth'];
                }

                return "{$firstname}|{$lastname}|{$dob}";
            });

            // ── 6. Build the final response ───────────────────────────────────────────
            $result = $grouped->values()->map(function ($group) {
                $first      = $group->first();
                $personInfo = $first['personal_info'];
                $controlNo  = $first['ControlNo'];

                try {
                    $dobCarbon    = \Carbon\Carbon::parse($personInfo['date_of_birth']);
                    $dobFormatted = $dobCarbon->format('d/m/Y');
                    $age          = $dobCarbon->age;
                } catch (\Exception $e) {
                    $dobFormatted = $personInfo['date_of_birth'];
                    $age          = null;
                }

                $current_service = xService::select('ControlNo', 'ToDate', 'FromDate', 'Office', 'Designation', 'Status')
                    ->where('ControlNo', $controlNo)
                    ->orderByDesc('ToDate')
                    ->orderByDesc('FromDate')
                    ->first();

                $office      = $current_service->Office      ?? null;
                $designation = $current_service->Designation ?? null;

                if ($designation) {
                    $designation = trim(preg_replace('/\s*\(.*?\)\s*/', '', $designation));
                }

                $status = $current_service->Status ?? null;

                $xservice = xService::select('FromDate', 'ToDate')
                    ->where('ControlNo', $controlNo)
                    ->get();

                $totalDays = 0;
                $today     = Carbon::now();

                foreach ($xservice as $service) {
                    $from = Carbon::parse($service->FromDate);
                    $to   = Carbon::parse($service->ToDate ?? now());

                    if ($to->isFuture())   $to   = $today;
                    if ($from->isFuture()) continue;

                    $totalDays += $from->diffInDays($to);
                }

                $years  = intdiv($totalDays, 365);
                $remain = $totalDays % 365;
                $months = intdiv($remain, 30);
                $days   = $remain % 30;

                $lengthOfService = "{$years} years, {$months} months, {$days} days";

                $applications = $group->map(function ($submission) {
                    $jp = $submission['job_post'];

                    return [
                        'submission_id'      => $submission['submission_id'],
                        'job_batches_rsp_id' => $submission['job_batches_rsp_id'],
                        'job_post'           => $jp ? [
                            'id'          => $jp->id,
                            'Position'    => $jp->Position,
                            'Office'      => $jp->Office,
                            'SalaryGrade' => $jp->SalaryGrade,
                            'ItemNo'      => $jp->ItemNo,
                            'PageNo'      => $jp->PageNo,
                        ] : null,
                        'applicant_status' => $submission['applicant_status'],
                    ];
                })->values();

                return [
                    'control_no'            => $personInfo['control_no'],
                    'firstname'             => $personInfo['firstname'],
                    'lastname'              => $personInfo['lastname'] ?? null,
                    'date_of_birth'         => $dobFormatted ?? null,
                    'age'                   => $age,
                    'cellphone_number'      => $personInfo['cellphone_number'] ?? null,
                    'email_address'         => $personInfo['email_address'] ?? null,
                    'lengthOfService'       => $lengthOfService,
                    'current_office'        => $office,
                    'current_position'      => $designation,
                    'status'      => $status,
                    'applicant_application' => $applications,
                ];
            })->sortBy(fn($applicant) => strtolower($applicant['lastname']))
                ->values();

            // ── Set publication date ──────────────────────────────────────────────────
            $sheet->setCellValue("A3", $publicationDate);

            // ── Insert extra rows if data exceeds template rows ───────────────────────
            // Row count now needs to account for EVERY application, not one row per person,
            // since a person with 3 applications now produces 3 rows.
            $totalRows = $result->sum(fn($item) => $item['applicant_application']->count());
            $extraRows = $totalRows - 5;
            if ($extraRows > 0) {
                $sheet->insertNewRowBefore(23, $extraRows);
            }

            // ── Write data to sheet ───────────────────────────────────────────────────
            // One row PER APPLICATION. Person-level fields (name/age/current position/
            // current office/length of service) repeat on every row for that person.
            $row = 7;

            foreach ($result as $item) {
                $fullName = trim($item['lastname'] . ', ' . $item['firstname']);
                $appCount = $item['applicant_application']->count();
                $startRow = $row;

                foreach ($item['applicant_application'] as $index => $app) {
                    $jp = $app['job_post'];

                    // Only write person-level columns on the FIRST row for this person
                    if ($index === 0) {
                        $sheet->setCellValue("A{$row}", $fullName);
                        $sheet->setCellValue("B{$row}", $item['age']);
                        $sheet->setCellValue("C{$row}", $item['current_position']);
                        $sheet->setCellValue("D{$row}", $item['current_office']);
                        $sheet->setCellValue("E{$row}", $item['status']);
                        $sheet->setCellValue("F{$row}", $item['lengthOfService']);
                        // $sheet->setCellValue("K{$row}", $item['control_no'] ?? null);
                    }

                    // Applied-position columns are written on EVERY row
                    $sheet->setCellValue("G{$row}", $jp['Position']    ?? null);
                    $sheet->setCellValue("H{$row}", $jp['Office']      ?? null);
                    $sheet->setCellValue("I{$row}", $jp['PageNo']      ?? null);
                    $sheet->setCellValue("J{$row}", $jp['ItemNo']      ?? null);
                    $sheet->setCellValue("K{$row}", $jp['SalaryGrade'] ?? null);

                    $row++;
                }

                $endRow = $row - 1;

                // Merge the person-level columns vertically if they applied to more than one position
                if ($appCount > 1) {
                    $sheet->mergeCells("A{$startRow}:A{$endRow}");
                    $sheet->mergeCells("B{$startRow}:B{$endRow}");
                    $sheet->mergeCells("C{$startRow}:C{$endRow}");
                    $sheet->mergeCells("D{$startRow}:D{$endRow}");
                    $sheet->mergeCells("E{$startRow}:E{$endRow}");
                    $sheet->mergeCells("F{$startRow}:F{$endRow}");
                    // $sheet->mergeCells("K{$startRow}:K{$endRow}");
                }
            }

            $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
            $writer->setIncludeCharts(true);

            return new StreamedResponse(function () use ($writer) {
                $writer->save('php://output');
            }, 200, [
                'Content-Type'        => 'application/vnd.ms-excel.sheet.macroEnabled.12',
                'Content-Disposition' => 'attachment; filename="applicantInternalService.xlsx"',
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            return response()->json([
                'success'  => false,
                'message'  => $e->getMessage(),
                'sql'      => $e->getSql(),
                'bindings' => $e->getBindings(),
                'file'     => $e->getFile(),
                'line'     => $e->getLine(),
            ], 500);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
            ], 500);
        }
    }

    public function topApplicant($postDateInput)
    {
        $templatePath = storage_path('app/template/list_of_applicant_top_ranking.xlsx');
        $reader = IOFactory::createReader('Xlsx');
        $reader->setIncludeCharts(true);
        $spreadsheet = $reader->load($templatePath);

        if ($spreadsheet->hasMacros()) {
            $spreadsheet->setMacrosCode($spreadsheet->getMacrosCode());
        }

        $templateSheet = $spreadsheet->getSheet(0); // keep reference before cloning

        $postDateRaw = $postDateInput['publication_date'];

        $jobPosts = JobBatchesRsp::whereDate('post_date', $postDateRaw)
            ->select('id', 'Office', 'Division', 'Section', 'Position', 'SalaryGrade', 'ItemNo', 'post_date', 'end_date')
            ->get();

        if ($jobPosts->isEmpty()) {
            return response()->json(['message' => 'No job posts found for this date.'], 404);
        }

        $usedTitles = [];

        foreach ($jobPosts as $index => $jobPost) {

            // --- ranking data for this job post (unchanged logic) ---
            $allScores = rating_score::select(
                'rating_score.id',
                'rating_score.nPersonalInfo_id',
                'rating_score.ControlNo',
                'rating_score.job_batches_rsp_id',
                'rating_score.education_score as education',
                'rating_score.experience_score as experience',
                'rating_score.training_score as training',
                'rating_score.performance_score as performance',
                'rating_score.behavioral_score as bei',
                'rating_score.grand_total',
                'nPersonalInfo.firstname as ext_firstname',
                'nPersonalInfo.lastname as ext_lastname',
                'xPersonal.Firstname as int_firstname',
                'xPersonal.Surname as int_lastname',
                'submission.id as submission_id'
            )
                ->leftJoin('nPersonalInfo', 'nPersonalInfo.id', '=', 'rating_score.nPersonalInfo_id')
                ->leftJoin('xPersonal', 'xPersonal.ControlNo', '=', 'rating_score.ControlNo')
                ->leftJoin('submission', function ($join) {
                    $join->on('submission.job_batches_rsp_id', '=', 'rating_score.job_batches_rsp_id')
                        ->where(function ($q) {
                            $q->on('submission.nPersonalInfo_id', '=', 'rating_score.nPersonalInfo_id')
                                ->orOn('submission.ControlNo', '=', 'rating_score.ControlNo');
                        });
                })
                ->where('submission.status', 'Qualified')
                ->where(function ($query) {
                    $query->where('submission.application_status', '!=', 'Withdrawn')
                        ->orWhereNull('submission.application_status');
                })
                ->where('rating_score.job_batches_rsp_id', $jobPost->id)
                ->get();

            $examScores = \App\Models\ApplicantExamScore::whereHas('submission', function ($q) use ($jobPost) {
                $q->where('job_batches_rsp_id', $jobPost->id);
            })
                ->get()
                ->keyBy('submission_id');

            $scoresByApplicant = $allScores->groupBy(
                fn($row) => $row->nPersonalInfo_id ?: 'control_' . $row->ControlNo
            );

            $applicants = [];

           

           foreach ($scoresByApplicant as $rows) {
                $first = $rows->first();

                $submissionId = $first->submission_id;
                if (!$submissionId && $first->ControlNo) {
                    $submissionId = \App\Models\Submission::where('job_batches_rsp_id', $jobPost->id)
                        ->where('ControlNo', $first->ControlNo)
                        ->value('id');
                }

                $examRecord     = $submissionId ? ($examScores[$submissionId] ?? null) : null;
                $examPercentage = $examRecord ? (float) $examRecord->exam_percentage : null;

                $scoresArray = $rows->map(fn($row) => [
                    'education'       => (float) $row->education,
                    'experience'      => (float) $row->experience,
                    'training'        => (float) $row->training,
                    'performance'     => (float) $row->performance,
                    'bei'             => $row->bei,
                    'exam_percentage' => $examPercentage,
                ])->toArray();

                $computed = RatingService::computeFinalScore($scoresArray);

                // --- internal applicant extras: office, position, length of service ---
                $office          = null;
                $designation     = null;
                $lengthOfService = null;
                $status = null;

                if (!$first->nPersonalInfo_id && $first->ControlNo) {

                    $current_service = xService::select('ControlNo', 'ToDate', 'FromDate', 'Office', 'Designation', 'Status')
                        ->where('ControlNo', $first->ControlNo)
                        ->orderByDesc('ToDate')
                        ->orderByDesc('FromDate')
                        ->first();

                    $office      = $current_service->Office ?? null;
                    $designation = $current_service->Designation ?? null;
                    $status = $current_service->Status ?? null;

                    if ($designation) {
                        $designation = trim(preg_replace('/\s*\(.*?\)\s*/', '', $designation));
                    }

                    $xservice = xService::select('FromDate', 'ToDate')
                        ->where('ControlNo', $first->ControlNo)
                        ->get();

                    $totalDays = 0;
                    $today     = Carbon::now();

                    foreach ($xservice as $service) {
                        $from = Carbon::parse($service->FromDate);
                        $to   = Carbon::parse($service->ToDate ?? now());

                        if ($to->isFuture())   $to   = $today;
                        if ($from->isFuture()) continue;

                        $totalDays += $from->diffInDays($to);
                    }

                    $years  = intdiv($totalDays, 365);
                    $remain = $totalDays % 365;
                    $months = intdiv($remain, 30);
                    $days   = $remain % 30;

                    $lengthOfService = "{$years} years, {$months} months, {$days} days";
                }

                $applicants[] = [
                    'nPersonalInfo_id' => $first->nPersonalInfo_id,
                    'ControlNo'        => $first->ControlNo,
                    'firstname'        => $first->nPersonalInfo_id ? $first->ext_firstname : $first->int_firstname,
                    'lastname'         => $first->nPersonalInfo_id ? $first->ext_lastname  : $first->int_lastname,
                    'office'           => $office,
                    'position'         => $designation,
                    'lenghtOfservice'  => $lengthOfService,
                    'status'  => $status,
                ] + $computed;
            }

            $topApplicants = collect(RatingService::addRanking($applicants))
                ->sortBy('rank')
                ->values();

            // --- sheet setup: 1 sheet per job post, titled Position - ItemNo - page ---
            $pageNo = $index + 1;
            $rawTitle = trim(($jobPost->Position ?: 'Position') . ' - Item: ' . ($jobPost->ItemNo ?: 'N/A') . ' - Page: ' . $pageNo);
            $safeTitle = preg_replace('/[\[\]\*\/\\\\\?\:]/', '', $rawTitle);
            $safeTitle = mb_substr($safeTitle, 0, 31);

            // fallback guard in case of any leftover collision (e.g. duplicate ItemNo)
            $finalTitle = $safeTitle;
            $suffix = 1;
            while (in_array($finalTitle, $usedTitles)) {
                $suffixStr  = "($suffix)";
                $finalTitle = mb_substr($safeTitle, 0, 31 - mb_strlen($suffixStr)) . $suffixStr;
                $suffix++;
            }
            $usedTitles[] = $finalTitle;

            if ($index === 0) {
                $sheet = $templateSheet;
                $sheet->setTitle($finalTitle);
            } else {
                $sheet = clone $templateSheet;
                $sheet->setTitle($finalTitle);
                $spreadsheet->addSheet($sheet, $index);
            }

            // --- header block: labels in column A, values in column B ---
            $postDateFmt     = Carbon::parse($jobPost->post_date)->format('F d, Y');
            $endDateFmt      = Carbon::parse($jobPost->end_date)->format('F d, Y');
            $publicationDate = "PUBLICATION DATE: {$postDateFmt} - {$endDateFmt}";

            $sheet->setCellValue('A3', $publicationDate);

            $sheet->setCellValue('B5', $jobPost->Office );
            $sheet->setCellValue('A6', 'Division/Section');
            $sheet->setCellValue('B6', $jobPost->Division ?? $jobPost->Section);

            $sheet->setCellValue('A7', 'Position');
            $sheet->setCellValue('B7', $jobPost->Position);

            $sheet->setCellValue('A8', 'Salary Grade');
            $sheet->setCellValue('B8', $jobPost->SalaryGrade);

            $sheet->setCellValue('A9', 'Plantilla Item No');
            $sheet->setCellValue('B9', $jobPost->ItemNo);

            $extraRows = $topApplicants->count() - 5;
            if ($extraRows > 0) {
                $sheet->insertNewRowBefore(23, $extraRows);
            }

            $row = 11;
            foreach ($topApplicants as $applicant) {
                $sheet->setCellValue("A{$row}", $applicant['rank'] ?? '');
                $sheet->setCellValue("B{$row}", trim("{$applicant['firstname']} {$applicant['lastname']}"));
                $sheet->setCellValue("C{$row}", $applicant['office'] ?? '');
                $sheet->setCellValue("E{$row}", $applicant['status'] ?? '');
                $sheet->setCellValue("D{$row}", $applicant['position'] ?? '');
                $sheet->setCellValue("F{$row}", $applicant['lenghtOfservice'] ?? '');
                
                $row++;
            }
        }

        $spreadsheet->setActiveSheetIndex(0);

        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');

        return new StreamedResponse(function () use ($writer) {
            $writer->save('php://output');
        }, 200, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="list_of_applicant_top_ranking.xlsx"',
        ]);
    }
}
