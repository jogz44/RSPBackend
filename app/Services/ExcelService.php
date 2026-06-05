<?php

namespace App\Services;

use App\Models\JobBatchesRsp;
use App\Models\Submission;
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
        )->where('status','Unqualified')
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
        ->groupBy('p.firstname', 'p.lastname', 'p.date_of_birth', 'pd.question_40a', 'pd.question_40b', 'pd.question_40c',    'p.sex',
            'p.civil_status',);

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
    ->groupBy('xp.Firstname', 'xp.Surname', 'xp.BirthDate', 'submission.ControlNo', 'xpdt.IP', 'xpdt.PWD', 'xpdt.SOLOP',      'xp.Sex',
        'xp.CivilStatus',);

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

}
