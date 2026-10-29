<?php

namespace App\Http\Controllers;

use App\Models\vwActive;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TempAppointmentReportController extends Controller
{
    /**
     * Generate appointment report PDF
     */
    public function generateReport($ControlNo)
    {
        try {
            $data = $this->getEmployeeData($ControlNo);

            if (!$data) {
                return response()->json(['error' => 'Employee not found'], 404);
            }

            $html = $this->generateAppointmentReportHTML($data);
            $pdf = $this->generatePDF($html, 'appointment');

            return response($pdf)
                ->header('Content-Type', 'application/pdf')
                ->header('Content-Disposition', 'attachment; filename="appointment_report_' . $ControlNo . '.pdf"');

        } catch (\Exception $e) {
            Log::error('Appointment report generation failed: ' . $e->getMessage());
            return response()->json([
                'error' => 'Failed to generate appointment report',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generate certification report PDF
     */
    public function generateCertificationReport($ControlNo)
    {
        try {
            $data = $this->getEmployeeDataWithExtended($ControlNo);

            if (!$data) {
                return response()->json(['error' => 'Employee not found'], 404);
            }

            $html = $this->generateCertificationReportHTML($data);
            $pdf = $this->generatePDF($html, 'certification');

            return response($pdf)
                ->header('Content-Type', 'application/pdf')
                ->header('Content-Disposition', 'attachment; filename="certification_report_' . $ControlNo . '.pdf"');

        } catch (\Exception $e) {
            Log::error('Certification report generation failed: ' . $e->getMessage());
            return response()->json([
                'error' => 'Failed to generate certification report',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generate position description report PDF
     */
    public function generatePositionDescriptionReport($ControlNo)
    {
        try {
            $data = $this->getEmployeeDataWithExtended($ControlNo);

            if (!$data) {
                return response()->json(['error' => 'Employee not found'], 404);
            }

            $html = $this->generatePositionDescriptionHTML($data);
            $pdf = $this->generatePDF($html, 'position');

            return response($pdf)
                ->header('Content-Type', 'application/pdf')
                ->header('Content-Disposition', 'attachment; filename="position_description_report_' . $ControlNo . '.pdf"');

        } catch (\Exception $e) {
            Log::error('Position description report generation failed: ' . $e->getMessage());
            return response()->json([
                'error' => 'Failed to generate position description report',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get employee data (basic) — reads from temporary_* staging tables
     * (default connection: RR2026 / sqlsrv).
     */
    private function getEmployeeData($ControlNo)
    {
        // 1. Anchor row from temporary_xservices
        $data = DB::table('temporary_xservices')
            ->select([
                'ControlNo',
                'FromDate',
                'ToDate',
                'Designation',
                'Status',
                'Office',
                'RateYear',
                'RateDay',
                'RateMon',
                'effectiveDate',
            ])
            ->where('ControlNo', $ControlNo)
            ->orderByDesc('FromDate')
            ->orderByDesc('ToDate')
            ->first();

        if (!$data) {
            return null;
        }

        // 2. Personal info
        $xPersonal = DB::table('temporary_x_personal')
            ->select([
                'ControlNo',
                'Surname',
                'TINNo',
                'Address',
                'BirthDate',
                'Firstname',
                'MIddlename',
                'Sex',
            ])
            ->where('ControlNo', $ControlNo)
            ->first();

        // 3. Posting dates
        $postingDate = DB::table('temporary_posting_dates')
            ->select(['ControlNo', 'post_date', 'end_date'])
            ->where('ControlNo', $ControlNo)
            ->first();

        // 4. Latest tempreg appointment
        $tempReg = DB::table('temporary_tempreg_appointment_reorg')
            ->select([
                'ID as tempId',
                'ControlNo',
                'DesigCode',
                'NewDesignation',
                'Designation',
                'SG',
                'Step',
                'Status',
                'OffCode',
                'NewOffice',
                'Office',
                'MRate',
                'ItemNo',
                'Pages',
                'DivCode',
                'SecCode',
                'Official',
                'Renew',
                'StructureID',
                'Groupcode',
                'group',
                'unitcode',
                'vicecause',
                'vicename',
                'sepdate',
                'sepcause',
                'deliberation_date',
                'assessment_date',
            ])
            ->where('ControlNo', $ControlNo)
            ->orderByDesc('ID')
            ->first();

        // 5. Latest tempreg extended
        $reorgExt = DB::table('temporary_tempreg_appointment_reorg_ext')
            ->select([
                'ID as tempExtId',
                'ControlNo',
                'PresAppro',
                'PrevAppro',
                'SalAuthorized',
                'OtherComp',
                'SupPosition',
                'HSupPosition',
                'Tool',
                'Contact1',
                'Contact2',
                'Contact3',
                'Contact4',
                'Contact5',
                'Contact6',
                'ContactOthers',
                'Working1',
                'Working2',
                'WorkingOthers',
                'DescriptionSection',
                'DescriptionFunction',
                'StandardEduc',
                'StandardExp',
                'StandardTrain',
                'StandardElig',
                'Supervisor',
                'Core1',
                'Core2',
                'Core3',
                'Corelevel1',
                'Corelevel2',
                'Corelevel3',
                'Corelevel4',
                'Leader1',
                'Leader2',
                'Leader3',
                'Leader4',
                'leaderlevel1',
                'leaderlevel2',
                'leaderlevel3',
                'leaderlevel4',
                'structureid',
                'signing_date',
            ])
            ->where('ControlNo', $ControlNo)
            ->orderByDesc('ID')
            ->first();

        $employmentType = null;
        if ($tempReg) {
            $status = strtoupper(trim($tempReg->Status ?? ''));
            $status = preg_replace('/[^A-Z]/', '', $status);
            $employmentType = match ($status) {
                'REGULAR' => 'PERMANENT',
                'ELECTIVE' => 'TEMPORARY',
                default => null,
            };
        }

        return [
            'ControlNo' => $data->ControlNo,
            'Firstname' => $xPersonal->Firstname ?? '',
            'Surname' => $xPersonal->Surname ?? '',
            'MIddlename' => $xPersonal->MIddlename ?? '',
            'Sex' => $xPersonal->Sex ?? '',
            'NewDesignation' => $tempReg->NewDesignation ?? $data->Designation ?? '',
            'SG' => $tempReg->SG ?? '',
            'Step' => $tempReg->Step ?? '',
            'employmenttype' => $employmentType,
            'NewOffice' => $tempReg->NewOffice ?? $tempReg->Office ?? '',
            'MRate' => $tempReg->MRate ?? '',
            'Renew' => $tempReg->Renew ?? '',
            'vicecause' => $tempReg->vicecause ?? null,
            'vicename' => $tempReg->vicename ?? null,
            'ItemNo' => $tempReg->ItemNo ?? '',
            'Pages' => $tempReg->Pages ?? '',
            'Status' => $tempReg->Status ?? '',
            'mayor' => 'REY T. UY',
            'vicemayor' => 'ATTY. EVA LORRAINE E. ESTABILLO',
            'deliberation_date' => $tempReg->deliberation_date ?? null,
            'post_date' => $postingDate->post_date ?? null,
            'end_date' => $postingDate->end_date ?? null,
            'assessment_date' => $tempReg->assessment_date ?? null,
            'signingDate' => $reorgExt->signing_date ?? null,
        ];
    }

    /**
     * Get employee data with extended fields — reads from temporary_* staging tables.
     *
     * The office head still comes from the LIVE HR system (vwActive on db2)
     * because there is no temporary equivalent in the staging tables.
     */
    private function getEmployeeDataWithExtended($ControlNo)
    {
        // 1. Anchor row
        $data = DB::table('temporary_xservices')
            ->select([
                'ControlNo',
                'FromDate',
                'ToDate',
                'Designation',
                'Status',
                'Office',
                'RateYear',
                'RateDay',
                'RateMon',
                'effectiveDate',
            ])
            ->where('ControlNo', $ControlNo)
            ->orderByDesc('FromDate')
            ->orderByDesc('ToDate')
            ->first();

        if (!$data) {
            return null;
        }

        // 2. Personal info
        $xPersonal = DB::table('temporary_x_personal')
            ->select([
                'ControlNo',
                'Surname',
                'TINNo',
                'Address',
                'BirthDate',
                'Firstname',
                'MIddlename',
                'Sex',
            ])
            ->where('ControlNo', $ControlNo)
            ->first();

        // 3. Posting dates
        $postingDate = DB::table('temporary_posting_dates')
            ->select(['ControlNo', 'post_date', 'end_date'])
            ->where('ControlNo', $ControlNo)
            ->first();

        // 4. Latest tempreg appointment
        $tempReg = DB::table('temporary_tempreg_appointment_reorg')
            ->select([
                'ID as tempId',
                'ControlNo',
                'DesigCode',
                'NewDesignation',
                'Designation',
                'SG',
                'Step',
                'Status',
                'OffCode',
                'NewOffice',
                'Office',
                'MRate',
                'ItemNo',
                'Pages',
                'DivCode',
                'SecCode',
                'Official',
                'Renew',
                'StructureID',
                'Groupcode',
                'group',
                'unitcode',
                'vicecause',
                'vicename',
                'sepdate',
                'sepcause',
                'deliberation_date',
                'assessment_date',
            ])
            ->where('ControlNo', $ControlNo)
            ->orderByDesc('ID')
            ->first();

        // 5. Latest tempreg extended
        $reorgExt = DB::table('temporary_tempreg_appointment_reorg_ext')
            ->select([
                'ID as tempExtId',
                'ControlNo',
                'PresAppro',
                'PrevAppro',
                'SalAuthorized',
                'OtherComp',
                'SupPosition',
                'HSupPosition',
                'Tool',
                'Contact1',
                'Contact2',
                'Contact3',
                'Contact4',
                'Contact5',
                'Contact6',
                'ContactOthers',
                'Working1',
                'Working2',
                'WorkingOthers',
                'DescriptionSection',
                'DescriptionFunction',
                'StandardEduc',
                'StandardExp',
                'StandardTrain',
                'StandardElig',
                'Supervisor',
                'Core1',
                'Core2',
                'Core3',
                'Corelevel1',
                'Corelevel2',
                'Corelevel3',
                'Corelevel4',
                'Leader1',
                'Leader2',
                'Leader3',
                'Leader4',
                'leaderlevel1',
                'leaderlevel2',
                'leaderlevel3',
                'leaderlevel4',
                'structureid',
                'signing_date',
            ])
            ->where('ControlNo', $ControlNo)
            ->orderByDesc('ID')
            ->first();

        // 6. Office head — still from the LIVE HR system (vwActive, db2).
        //    There is no temporary equivalent in your staging list.
        $officeHead = null;
        $officeName = $tempReg->NewOffice ?? $tempReg->Office ?? '';

        if (!empty($officeName)) {
            $officeHead = vwActive::where('Office', $officeName)
                ->where('Designation', 'LIKE', 'CITY GOVERNMENT DEPARTMENT HEAD I%')
                ->first();
        }

        if (!$officeHead) {
            $officeHead = vwActive::where('Designation', 'LIKE', 'CITY GOVERNMENT DEPARTMENT HEAD%')
                ->first();
        }

        $employmentType = null;
        if ($tempReg) {
            $status = strtoupper(trim($tempReg->Status ?? ''));
            $status = preg_replace('/[^A-Z]/', '', $status);
            $employmentType = match ($status) {
                'REGULAR' => 'PERMANENT',
                'ELECTIVE' => 'TEMPORARY',
                default => null,
            };
        }

        $baseData = [
            'ControlNo' => $data->ControlNo,
            'Firstname' => $xPersonal->Firstname ?? '',
            'Surname' => $xPersonal->Surname ?? '',
            'MIddlename' => $xPersonal->MIddlename ?? '',
            'Sex' => $xPersonal->Sex ?? '',
            'NewDesignation' => $tempReg->NewDesignation ?? $data->Designation ?? '',
            'SG' => $tempReg->SG ?? '',
            'Step' => $tempReg->Step ?? '',
            'employmenttype' => $employmentType,
            'NewOffice' => $tempReg->NewOffice ?? $tempReg->Office ?? '',
            'MRate' => $tempReg->MRate ?? '',
            'Renew' => $tempReg->Renew ?? '',
            'vicecause' => $tempReg->vicecause ?? null,
            'vicename' => $tempReg->vicename ?? null,
            'ItemNo' => $tempReg->ItemNo ?? '',
            'Pages' => $tempReg->Pages ?? '',
            'Status' => $tempReg->Status ?? '',
            'mayor' => 'REY T. UY',
            'vicemayor' => 'ATTY. EVA LORRAINE E. ESTABILLO',
            'deliberation_date' => $tempReg->deliberation_date ?? null,
            'post_date' => $postingDate->post_date ?? null,
            'end_date' => $postingDate->end_date ?? null,
            'assessment_date' => $tempReg->assessment_date ?? null,
            'signingDate' => $reorgExt->signing_date ?? null,
            // No temporary_vwactive, so rebuild Name4 from temporary_x_personal
            'Name4' => trim(($xPersonal->Firstname ?? '') . ' ' . ($xPersonal->Surname ?? '')),
            'TINNo' => $xPersonal->TINNo ?? null,
            'EffectiveDate' => $data->effectiveDate ?? null,
            // Office head (still from live HR system, db2)
            'officeHeadName' => $officeHead->Name4 ?? 'Office Head',
            'officeHeadPosition' => $officeHead->Designation ?? 'Office Head',
            // Default values
            'cityaccountant' => 'RAMIL Y. TIU, CPA',
            'HR' => 'JANYLENE A. PALERMO, MM',
        ];

        if ($reorgExt) {
            $baseData = array_merge($baseData, [
                'PresAppro' => $reorgExt->PresAppro ?? '',
                'PrevAppro' => $reorgExt->PrevAppro ?? '',
                'SalAuthorized' => $reorgExt->SalAuthorized ?? '',
                'OtherComp' => $reorgExt->OtherComp ?? '',
                'SupPosition' => $reorgExt->SupPosition ?? '',
                'HSupPosition' => $reorgExt->HSupPosition ?? '',
                'Tool' => $reorgExt->Tool ?? '',
                'Contact1' => $reorgExt->Contact1 ?? '',
                'Contact2' => $reorgExt->Contact2 ?? '',
                'Contact3' => $reorgExt->Contact3 ?? '',
                'Contact4' => $reorgExt->Contact4 ?? '',
                'Contact5' => $reorgExt->Contact5 ?? '',
                'Contact6' => $reorgExt->Contact6 ?? '',
                'ContactOthers' => $reorgExt->ContactOthers ?? '',
                'Working1' => $reorgExt->Working1 ?? '',
                'Working2' => $reorgExt->Working2 ?? '',
                'WorkingOthers' => $reorgExt->WorkingOthers ?? '',
                'DescriptionSection' => $reorgExt->DescriptionSection ?? '',
                'DescriptionFunction' => $reorgExt->DescriptionFunction ?? '',
                'StandardEduc' => $reorgExt->StandardEduc ?? '',
                'StandardExp' => $reorgExt->StandardExp ?? '',
                'StandardTrain' => $reorgExt->StandardTrain ?? '',
                'StandardElig' => $reorgExt->StandardElig ?? '',
                'Supervisor' => $reorgExt->Supervisor ?? '',
                'Core1' => $reorgExt->Core1 ?? '',
                'Core2' => $reorgExt->Core2 ?? '',
                'Core3' => $reorgExt->Core3 ?? '',
                'Corelevel1' => $reorgExt->Corelevel1 ?? '',
                'Corelevel2' => $reorgExt->Corelevel2 ?? '',
                'Corelevel3' => $reorgExt->Corelevel3 ?? '',
                'Corelevel4' => $reorgExt->Corelevel4 ?? '',
                'Leader1' => $reorgExt->Leader1 ?? '',
                'Leader2' => $reorgExt->Leader2 ?? '',
                'Leader3' => $reorgExt->Leader3 ?? '',
                'Leader4' => $reorgExt->Leader4 ?? '',
                'leaderlevel1' => $reorgExt->leaderlevel1 ?? '',
                'leaderlevel2' => $reorgExt->leaderlevel2 ?? '',
                'leaderlevel3' => $reorgExt->leaderlevel3 ?? '',
                'leaderlevel4' => $reorgExt->leaderlevel4 ?? '',
                // No temporary plantilla table → Division stays empty
                'Division' => '',
            ]);
        }

        return $baseData;
    }

    /**
     * Generate Appointment Report HTML
     */
    private function generateAppointmentReportHTML($data)
    {
        $sealBase64 = $this->getBase64Logo('images/image.png');
        $logoBase64 = $this->getBase64Logo('images/logo.png');

        $formattedName = $this->formatName($data);
        $officeTitle = $this->getOfficeTitle($data['NewOffice'] ?? '');
        $signatoryName = $this->getSignatoryName($data);
        $signatoryTitle = $this->getSignatoryTitle($data['NewOffice'] ?? '');
        $formattedRenew = $this->formatRenew($data);
        $showProbationaryNote = $this->shouldShowProbationaryNote($data);
        $formattedStep = ($data['SG'] ?? '') . '/' . ($data['Step'] ?? '');
        $isCoterminousOrElective = $this->isCoterminousOrElective($data);

        $publishedAt = $isCoterminousOrElective ? 'N/A' : 'CSC Website';
        $deliberationDate = $isCoterminousOrElective ? 'N/A' : $this->formatDate($data['deliberation_date'] ?? null);
        $publishStartDate = $isCoterminousOrElective ? 'N/A' : $this->formatDate($data['post_date'] ?? null);
        $publishEndDate = $isCoterminousOrElective ? 'N/A' : $this->formatDate($data['end_date'] ?? null);
        $postStartDate = $isCoterminousOrElective ? 'N/A' : $this->formatDate($data['post_date'] ?? null);
        $postEndDate = $isCoterminousOrElective ? 'N/A' : $this->formatDate($data['end_date'] ?? null);
        $assessmentDate = $isCoterminousOrElective ? 'N/A' : $this->formatDate($data['assessment_date'] ?? null);
        $signingDate = $isCoterminousOrElective ? 'N/A' : $this->formatDate($data['signingDate'] ?? null);

        $salaryWords = $this->formatSalaryWords($data['MRate'] ?? null);
        $salaryAmount = $this->formatSalaryAmount($data['MRate'] ?? null);
        $MRate = $data['MRate'] ?? '';
        $salutation = $this->getSalutation($data['Sex'] ?? '');
        $newDesignation = $data['NewDesignation'] ?? '(Position Title)';
        $employmentType = $data['employmenttype'] ?? 'N/A';
        $newOffice = $data['NewOffice'] ?? '(Office/Department/Unit)';
        $officeLength = mb_strlen($newDesignation);
        $officeWhiteSpace = ($officeLength >= 55 && $officeLength <= 75) ? 'nowrap' : 'normal';
        $debug = " Office: {$newOffice} | Length: {$officeLength} | White-space: {$officeWhiteSpace}";

        $signatoryRepName = $this->getSignatoryRepName($data['NewOffice'] ?? '');
        $signatoryRepPosition = $this->getSignatoryRepPosition($data['NewOffice'] ?? '');
        $signatoryRepOffice = $this->getSignatoryRepOffice($data['NewOffice'] ?? '');

        $viceCause = !empty($data['vicecause']) ? $data['vicecause'] : 'N/A';
        $viceName = !empty($data['vicename']) ? $data['vicename'] : 'N/A';
        $itemNo = $data['ItemNo'] ?? '(Item No.)';
        $pages = $data['Pages'] ?? '(Page No.)';

        $probationaryColor = $showProbationaryNote ? 'black' : 'white';

        return <<<HTML
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title>Appointment Report</title>
            <style>
                * { margin: 0; padding: 0; box-sizing: border-box; }
                
                .appointment-form-container {
                    display: flex;
                    flex-direction: column;
                    gap: 16px;
                    padding: 16px 0;
                    align-items: center;
                }

                .page {
                    width: 8.5in;
                    min-height: 13in;
                    font-family: 'Consolas', 'Courier New', Courier, monospace;
                    font-size: 12pt;
                    color: black;
                    background: white;
                    box-sizing: border-box;
                    page-break-after: always;
                    page-break-inside: avoid;
                }

                .page:last-child {
                    page-break-after: auto;
                }

                .appointment-form {
                    padding: 0.3in;
                    padding-top: 0.5in;
                    line-height: 1.5;
                }

                .form-content {
                    width: 100%;
                    height: 100%;
                    border: 2px solid black;
                    box-shadow: inset 0 0 0 20px #c0c0c0, inset 0 0 0 22px black;
                    padding: 0.5in;
                    box-sizing: border-box;
                    position: relative;
                    min-height: calc(13in - 1in);
                }

                .form-title {
                    position: absolute;
                    top: 0.5in;
                    left: 0.5in;
                    font-size: 10pt;
                    line-height: 1.2;
                }

                .cs-form {
                    font-weight: bold;
                    font-style: italic;
                    font-size: 13pt;
                }

                .revised {
                    font-weight: bold;
                    font-style: italic;
                    font-size: 10pt;
                }

                .stamp-section {
                    position: absolute;
                    top: 0.5in;
                    right: 0.5in;
                    text-align: center;
                    font-size: 10pt;
                    line-height: 1.2;
                }

                .stamp-line {
                    width: 200px;
                    height: 10px;
                    border: none;
                    border-bottom: 1px solid black;
                    margin-bottom: 5px;
                    background-color: white;
                }

                .stamp-label {
                    font-weight: bold;
                    font-style: italic;
                    font-size: 9pt;
                    text-align: center;
                }

                .header {
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    margin-top: 3em;
                    margin-bottom: 1em;
                }

                .right-logo img {
                    width: 105px;
                    height: 95px;
                }

                .left-logo img {
                    width: 100px;
                    height: 90px;
                }

                .center-header {
                    text-align: center;
                    flex-grow: 1;
                    font-size: 11pt;
                }

                .office {
                    font-size: 16pt;
                }

                .body {
                    font-size: 11pt;
                    text-align: justify;
                    line-height: 2.5;
                    word-break: normal;
                    overflow-wrap: anywhere;
                }

                .body p {
                    word-break: normal;
                    margin-top: 0%;
                    margin-top: 0;
                }

                p {
                    margin: 0;
                    padding: 0;
                }

                .underline {
                    text-decoration: underline;
                    word-break: break-word;
                    overflow-wrap: anywhere;
                }

                  ruby {
                    ruby-position: under;
                    text-align: center;
                    line-height: 1;
                    word-break: normal;
                    overflow-wrap: anywhere;
                    display: inline-ruby;
                }

                rt {
                    padding-top: 2px;
                    font-size: 8pt;
                    font-style: italic;
                    color: #666;
                    line-height: 1;
                    text-align: center;
                    white-space: nowrap;
                }

                .signature-block {
                    text-align: right;
                    font-size: 11pt;
                    margin-top: 5em;
                }

                .signature-salutation {
                    text-align: left;
                }

                .signature-section {
                    display: inline-block;
                    text-align: center;
                }

                .signature-name-container {
                    position: relative;
                    display: inline-block;
                    min-width: 300px;
                    border-bottom: 2px solid black;
                    padding-bottom: 3px;
                    margin-bottom: 5px;
                    word-break: break-word;
                    overflow-wrap: anywhere;
                }

                .signature-name {
                    font-weight: bold;
                    text-transform: uppercase;
                    font-size: 11pt;
                    word-break: break-word;
                    overflow-wrap: anywhere;
                }

                .signature-title {
                    font-style: italic;
                    font-size: 11pt;
                }

                .signing-date-container {
                    position: relative;
                    display: inline-block;
                    min-width: 200px;
                    border-bottom: 2px solid black;
                    padding-bottom: 3px;
                }

                .signing-date {
                    font-weight: bold;
                    font-size: 11pt;
                }

                .signing-label {
                    font-style: italic;
                    font-size: 11pt;
                }

                .footer {
                    font-size: 11pt;
                }

                .footer-note {
                    max-width: 300px;
                    margin-top: 2em;
                    margin-bottom: 15px;
                    font-size: 11pt;
                }

                .certification-page {
                    padding: 0.3in;
                    padding-top: 0.5in;
                    position: relative;
                }

                .certification-section {
                    width: 100%;
                    padding: 0;
                    box-sizing: border-box;
                    position: relative;
                }

                .certificates-container {
                    width: 100%;
                    background-color: #c0c0c0;
                    padding: 20px;
                    box-sizing: border-box;
                    border: 2px solid black;
                    margin-bottom: 10px;
                }

                .certificate-box {
                    width: 100%;
                    border: 2px solid black;
                    background-color: white;
                    padding: 0.1in;
                    box-sizing: border-box;
                    margin-bottom: 10px;
                }

                .certificate-box:last-child {
                    margin-bottom: 0;
                }

                .certification-title {
                    text-align: center;
                    font-size: 14pt;
                    font-weight: bold;
                    margin-top: 10px
                }

                .certification-text {
                    text-align: justify;
                    text-indent: 2em;
                    line-height: 1.5;
                    font-size: 11pt;
                    margin: 15px;
                    word-break: break-word;
                    overflow-wrap: anywhere;
                }

                .signature-container {
                    display: flex;
                    justify-content: flex-end;
                    margin-top: 5px;
                    margin-bottom: 5px;
                }

                .signature {
                    display: inline-block;
                    text-align: center;
                    width: 380px;
                }

                .cert-signature-title {
                    font-size: 9pt;
                }

                .notation-container {
                    width: 100%;
                    background-color: #c0c0c0;
                    border: 2px solid black;
                    box-sizing: border-box;
                    margin-bottom: 10px;
                    position: relative;
                    padding-top: 35px;
                    padding-left: 20px;
                    padding-right: 20px;
                    padding-bottom: 20px;
                }

                .notation-title {
                    text-align: center;
                    font-size: 14pt;
                    font-weight: bold;
                    margin-top: 5px;
                    position: absolute;
                    top: 3px;
                    left: 0;
                    right: 0;
                }

                .notation-content {
                    background-color: #ffff;
                    border: 2px solid black;
                    padding: 0.15in;
                    box-sizing: border-box;
                }

                .notation-table table {
                    width: 100%;
                    border-collapse: collapse;
                    margin: 0 auto;
                    table-layout: fixed;
                }

                .notation-table td {
                    border: 1px solid #000;
                    padding: 5px;
                    height: 30px;
                    word-break: break-word;
                    overflow-wrap: anywhere;
                }

                .table-header {
                    text-align: center;
                    font-size: 11pt;
                    font-weight: bold;
                    background-color: #fff;
                }

                .checkbox-row {
                    font-size: 10pt;
                    word-break: break-word;
                    overflow-wrap: anywhere;
                }

                .checkbox-deeper-indent {
                    padding-left: 5px;
                    font-size: 10pt;
                }

                .indent-wrapper {
                    padding-left: 20px;
                    display: flex;
                    align-items: center;
                    word-break: break-word;
                    overflow-wrap: anywhere;
                }

                .bold-text {
                    font-weight: bold;
                }

                .custom-checkbox {
                    appearance: none;
                    -webkit-appearance: none;
                    width: 12px;
                    height: 12px;
                    border: 1px solid black;
                    background-color: white;
                    margin-right: 5px;
                    position: relative;
                    top: 1px;
                }

                .custom-checkbox:checked {
                    background-color: white;
                }

                .custom-checkbox:checked:after {
                    content: '✓';
                    position: absolute;
                    top: -2px;
                    left: 1px;
                    color: black;
                    font-size: 10px;
                }

                .form-field {
                    display: inline-block;
                    width: 150px;
                    min-width: 100px;
                    border-bottom: 1px solid #000;
                    word-break: break-word;
                    overflow-wrap: anywhere;
                }

                .acknowledgement-container {
                    width: 100%;
                    background-color: #c0c0c0;
                    border: 2px solid black;
                    box-sizing: border-box;
                    padding: 10px;
                }

                .acknowledgement-boxes {
                    display: flex;
                    width: 100%;
                }

                .left-box {
                    width: 50%;
                    background-color: white;
                    border: 2px solid black;
                    padding: 0.15in;
                    padding-top: 40px;
                    box-sizing: border-box;
                    border-right: none;
                }

                .right-box {
                    width: 50%;
                    background-color: white;
                    border: 2px solid black;
                    padding: 0.15in;
                    box-sizing: border-box;
                }

                .copy-text {
                    font-size: 8pt;
                    line-height: 1.5;
                }

                .acknowledgement-title {
                    text-align: center;
                    font-size: 13pt;
                    font-weight: bold;
                    margin-top: 0;
                    margin-bottom: 10px;
                }

                .acknowledgement-text {
                    text-align: left;
                    margin-bottom: 30px;
                    font-size: 8pt;
                }

                .appointee-signature {
                    display: flex;
                    flex-direction: column;
                    align-items: center;
                    justify-content: center;
                    margin-top: 10px;
                }

                .appointee-signature .signature-line {
                    border-top: 1px solid #000;
                    display: block;
                    width: 200px;
                    margin: 0 auto 3px auto;
                }

                .appointee-signature-title {
                    text-align: center;
                    font-size: 10pt;
                    width: 100%;
                }
            </style>
        </head>
        <body>
            <!-- Page 1: Appointment Form -->
            <div class="page appointment-form">
                <div class="form-content">
                    <div class="form-title">
                        <div class="cs-form">CS Form No. 33-B</div>
                        <div class="revised">Revised 2025</div>
                    </div>

                    <div class="stamp-section">
                        <div class="stamp-line"></div>
                        <div class="stamp-label">Stamp of Date of Receipts</div>
                    </div>

                    <div class="header">
                        <div class="left-logo">
                            <img src="data:image/png;base64,{$sealBase64}" alt="Philippine Seal" />
                        </div>
                        <div class="center-header">
                            <div>Republic of the Philippines</div>
                            <div>PROVINCE OF DAVAO DEL NORTE</div>
                            <div><strong>CITY OF TAGUM</strong></div>
                            <br />
                            <div class="office">
                                <strong>{$officeTitle}</strong>
                            </div>
                        </div>
                        <div class="right-logo">
                            <img src="data:image/png;base64,{$logoBase64}" alt="City of Tagum Logo" />
                        </div>
                    </div>

                    <div class="body">
                        <p>
                            {$salutation}:
                            <strong class="underline">{$formattedName}</strong>
                        </p>

                        <p style="text-indent: 2em">
                            You are hereby appointed as
                            <ruby>
                                <strong style="text-decoration: underline">{$newDesignation}</strong>
                                <rt>(Position Title)</rt>
                            </ruby>
                            (SG/Step
                            <span style="text-decoration: underline; font-weight: bold">{$formattedStep}</span>
                            <span>)</span>
                            under
                            <ruby>
                                <span style="font-weight: bold; border-bottom: 1.5px solid black; display: inline-block; min-width: 160px; text-align: center; line-height: 0.9">{$employmentType}</span>
                                <rt>(Permanent, Temporary, etc.)</rt>
                            </ruby>
                            status at the
                            <ruby>
                                <strong class="underline">
                                    <span style="white-space: {$officeWhiteSpace} !important;">
                                        {$newOffice}
                                    </span>
                                </strong>
                                <rt>
                                    <span style="white-space: nowrap !important; word-break: normal !important;">
                                        (Office/Department/Unit)
                                    </span>
                                </rt>
                            </ruby>
                            &nbsp;with a compensation rate of
                           <strong class="underline">
                            {$salaryWords}
                            </strong>
                            <span>&nbsp;(P&nbsp;</span>
                            <strong class="underline">
                            {$MRate}
                            </strong>
                            <span>)</span>
                            pesos per month.
                        </p>

                        <p style="text-indent: 2em">
                            The nature of this appointment is
                            <ruby>
                                <span style="font-weight: bold; border-bottom: 1.5px solid black; width: 350px; line-height: 0.9; display: inline-block; text-align: center;">{$formattedRenew}</span>
                                <rt>(Original, Promotion, etc.)</rt>
                            </ruby>
                            vice
                            <span style="font-weight: bold; border-bottom: 1.5px solid black; line-height: 0.9; display: inline-block; text-align: center; min-width: 250px;">{$viceCause}</span>
                            , who
                            <ruby>
                                <span style="font-weight: bold; border-bottom: 1.5px solid black; line-height: 0.9; display: inline-block; text-align: center; min-width: 250px;">{$viceName}</span>
                                <rt>(Transferred, Retired, etc.)</rt>
                            </ruby>
                            with Plantilla Item No.
                            <strong class="underline">{$itemNo}</strong>
                            Page
                            <strong class="underline">{$pages}</strong>
                            .
                        </p>

                        <p style="text-indent: 2em">
                            This appointment shall take effect on the date of signing by the appointing officer/authority.
                        </p>

                        <p style="text-indent: 2em; color: {$probationaryColor}">
                            *Appointee shall undergo probationary period of six (6) months upon assumption of duty.
                        </p>
                    </div>

                    <div class="signature-block">
                        <div class="signature-section">
                            <p class="signature-salutation">Very truly yours,</p>
                            <br />
                            <br />
                            <div class="signature-name-container">
                                <strong class="signature-name">{$signatoryName}</strong>
                            </div>
                            <div class="signature-title">{$signatoryTitle}</div>
                            <br />
                            <div class="signing-date-container">
                                <strong class="signing-date">{$signingDate}</strong>
                            </div>
                            <div class="signing-label">Date of Signing</div>
                        </div>
                    </div>

                    <div class="footer-note">
                        <p class="footer">
                            Accredited/Deregulated Pursuant to
                            <br />
                            <span>CSC Resolution No.</span>
                            <strong class="underline">1701688,</strong>
                            , s.
                            <strong class="underline">2017</strong>
                            <br />
                            Dated
                            <strong class="underline">December 28, 2017</strong>
                        </p>
                    </div>
                </div>
            </div>

            <!-- Page 2: Certification Page -->
            <div class="page certification-page">
                <div class="certification-section">
                    <div class="certificates-container">
                        <div class="certificate-box">
                            <h3 class="certification-title">Certification</h3>
                            <p class="certification-text">
                                This is to certify that all requirements and supporting papers pursuant to the
                                <span><b>2025 Omnibus Rules on Appointments and Other Human Resource Actions,</b></span>
                                have been complied with, reviewed and found to be in order.
                            </p>
                            <p class="certification-text">
                                The position was published at
                                <span class="underline">{$publishedAt}</span>
                                from
                                <span class="underline">{$publishStartDate}</span>
                                to
                                <span class="underline">{$publishEndDate}</span>
                                and posted in three (3) conspicuous places from
                                <span class="underline">{$postStartDate}</span>
                                to
                                <span class="underline">{$postEndDate}</span>
                                in consonance with Republic Act No. 7041. The assessment by the Human Resource
                                Merit Promotion and Selection Board (HRMPSB) started on
                                <span class="underline">{$assessmentDate}</span>.
                            </p>
                            <div class="signature-container">
                                <div class="signature">
                                    <div class="signature-name-container">
                                        <strong class="signature-name">JANYLENE A. PALERMO, MM</strong>
                                    </div>
                                    <div class="cert-signature-title">City Human Resource Mgt. Officer</div>
                                </div>
                            </div>
                        </div>

                        <div class="certificate-box">
                            <h3 class="certification-title">Certification</h3>
                            <p class="certification-text">
                                This is to certify that the appointee has been screened and found qualified by
                                at least the majority of the HRMPSB/Placement Committee during the deliberation
                                held on
                                <span class="underline">{$deliberationDate}</span>.
                            </p>
                            <div class="signature-container">
                                <div class="signature">
                                    <div class="signature-name-container">
                                        <strong class="signature-name">{$signatoryRepName}</strong>
                                    </div>
                                    <div class="cert-signature-title">
                                        {$signatoryRepPosition}
                                        <br />
                                        Authorized Representative of the {$signatoryRepOffice}
                                        <br />
                                        Chairperson
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="notation-container">
                        <h3 class="notation-title">CSC/HRMO Notation</h3>
                        <div class="notation-content">
                            <div class="notation-table">
                                <table>
                                    <tbody>
                                        <tr>
                                            <td colspan="3" class="table-header">ACTION ON APPOINTMENTS</td>
                                            <td class="table-header">Recorded by</td>
                                        </tr>
                                        <tr>
                                            <td colspan="3" class="checkbox-row">
                                                <input type="checkbox" class="custom-checkbox" />
                                                Validated per RAI for the month of
                                                <span class="form-field"></span>
                                            </td>
                                            <td></td>
                                        </tr>
                                        <tr>
                                            <td colspan="3" class="checkbox-row">
                                                <input type="checkbox" class="custom-checkbox" />
                                                Invalidated per CSCRO/FO letter dated
                                                <span class="form-field"></span>
                                            </td>
                                            <td></td>
                                        </tr>
                                        <tr>
                                            <td class="checkbox-row">
                                                <input type="checkbox" class="custom-checkbox" />
                                                <span class="bold-text">Appeal</span>
                                            </td>
                                            <td class="table-header">DATE FILED</td>
                                            <td class="table-header">STATUS</td>
                                            <td></td>
                                        </tr>
                                        <tr>
                                            <td class="checkbox-deeper-indent">
                                                <div class="indent-wrapper">
                                                    <input type="checkbox" class="custom-checkbox" />
                                                    CSCRO/ CSC-Commission
                                                </div>
                                            </td>
                                            <td></td>
                                            <td></td>
                                            <td></td>
                                        </tr>
                                        <tr>
                                            <td class="checkbox-row">
                                                <input type="checkbox" class="custom-checkbox" />
                                                <span class="bold-text">Petition for Review</span>
                                            </td>
                                            <td></td>
                                            <td></td>
                                            <td></td>
                                        </tr>
                                        <tr>
                                            <td class="checkbox-deeper-indent">
                                                <div class="indent-wrapper">
                                                    <input type="checkbox" class="custom-checkbox" />
                                                    CSC-Commission
                                                </div>
                                            </td>
                                            <td></td>
                                            <td></td>
                                            <td></td>
                                        </tr>
                                        <tr>
                                            <td class="checkbox-deeper-indent">
                                                <div class="indent-wrapper">
                                                    <input type="checkbox" class="custom-checkbox" />
                                                    Court of Appeals
                                                </div>
                                            </td>
                                            <td></td>
                                            <td></td>
                                            <td></td>
                                        </tr>
                                        <tr>
                                            <td class="checkbox-deeper-indent">
                                                <div class="indent-wrapper">
                                                    <input type="checkbox" class="custom-checkbox" />
                                                    Supreme Court
                                                </div>
                                            </td>
                                            <td></td>
                                            <td></td>
                                            <td></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <div class="acknowledgement-container">
                        <div class="acknowledgement-boxes">
                            <div class="left-box">
                                <p class="copy-text">
                                    Original Copy - for the Agency
                                    <br />
                                    Certified True Copy - for the Civil Service Commission
                                    <br />
                                    Certified True Copy - for the Appointee
                                </p>
                            </div>
                            <div class="right-box">
                                <h3 class="acknowledgement-title">Acknowledgement</h3>
                                <p class="acknowledgement-text">
                                    Received original of appointment on
                                    <span class="form-field"></span>
                                </p>
                                <div class="appointee-signature">
                                    <div class="signature-line"></div>
                                    <div class="appointee-signature-title">Appointee</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </body>
        </html>
        HTML;
    }

    /**
     * Generate Certification Report HTML
     */
    private function generateCertificationReportHTML($data)
    {
        $sealBase64 = $this->getBase64Logo('images/image.png');
        $logoBase64 = $this->getBase64Logo('images/logo.png');

        $signatoryName = $this->getSignatoryName($data);
        $signatoryTitle = $this->getSignatoryTitle($data['NewOffice'] ?? '');
        $officeTitle = $this->getOfficeTitle($data['NewOffice'] ?? '');
        $formattedName = $this->formatName($data);
        $effectiveDate = $data['EffectiveDate'] ?? null;
        $presAppro = $data['PresAppro'] ?? '';

        $formattedDateEnglish = $this->formatDateEnglish($effectiveDate);
        $formattedDateTagalog = $this->formatDateTagalog($effectiveDate);
        $formattedDayWithSuffix = $this->formatDayWithSuffix($effectiveDate);
        $formattedMonth = $this->formatMonth($effectiveDate);
        $formattedYear = $this->formatYear($effectiveDate);

        $sex = $data['Sex'] ?? '';
        $salutation = strtoupper($sex) === 'MALE' ? 'Mr.' : 'Ms.';
        $name4 = $data['Name4'] ?? 'Unknown';
        $newDesignation = $data['NewDesignation'] ?? 'NA';
        $newOffice = $data['NewOffice'] ?? 'NA';
        $cityAccountant = $data['cityaccountant'] ?? 'City Accountant';
        $officeHeadName = $data['officeHeadName'] ?? 'Office Head';
        $officeHeadPosition = $data['officeHeadPosition'] ?? 'Office Head';
        $hr = $data['HR'] ?? 'JANYLENE A. PALERMO, MM';
        $tinNo = $data['TINNo'] ?? '';
        $footerPhone = '0987654321';
        $footerEmail = 'mayoruy@gmail.com';

        $oathWords = [
            ['fil' => 'Ako', 'eng' => 'I'],
            ['fil' => 'si', 'eng' => ''],
            ['fil' => " <span style=\"font-weight:bold; text-decoration:underline;\">{$name4}</span>", 'eng' => '(Name of Appointee)'],
            ['fil' => ' ng', 'eng' => ''],
            ['fil' => ' <span style=\"font-weight:bold; text-decoration:underline;\">TAGUM CITY, DAVAO DEL NORTE</span> ', 'eng' => '(Address)'],
            ['fil' => 'na itinalaga bilang', 'eng' => 'having been appointed to'],
            ['fil' => " <span style=\"font-weight:bold; text-decoration:underline;\">{$newDesignation}</span> ", 'eng' => '(Position)'],
            ['fil' => 'ay taimtim na nanunumpa na', 'eng' => 'hereby solemnly swear,'],
            ['fil' => 'sa abot ng aking kakayahan,', 'eng' => 'to the best of my ability,'],
            ['fil' => 'ang mga katungkulang pinagtalagahan sa akin', 'eng' => 'the duties of my present position'],
            ['fil' => "at sa dapat gampanan sa iba pang pagkaraan nito'y gagampanan ko", 'eng' => 'and of all others that I may hereafter hold'],
            ['fil' => 'sa ilalim ng Republika ng Pilipinas;', 'eng' => 'under the Republic of the Philippines;'],
            ['fil' => 'na aking itataguyod at ipagtatangol ang Saligang Batas ng Pilipinas;', 'eng' => 'to uphold and defend the Constitution,'],
            ['fil' => 'na tunay na mananalig at tatalima ako rito;', 'eng' => 'that I will bear true faith and allegiance to the same;'],
            ['fil' => 'na susundin ko ang mga batas at mga kautusang legal,', 'eng' => 'that I will obey the laws, legal orders,'],
            ['fil' => 'at mga dekretong pinaiiral ng mga sadyang', 'eng' => 'and decrees promulgated'],
            ['fil' => 'itinakdang maykapangyarihan ng Republika ng Pilipinas;', 'eng' => 'by the duly constituted authorities of the Republic of the Philippines;'],
            ['fil' => 'at kusa kong babalikatin ang pananagutang ito', 'eng' => 'and that I impose this obligation upon myself voluntarily,'],
            ['fil' => 'ng walang ano mang pasubali o hangaring umiwas.', 'eng' => 'without mental reservation or purpose of evasion.'],
            ['fil' => '', 'eng' => ''],
            ['fil' => '', 'eng' => ''],
        ];

        $oathWordsHtml = '';
        foreach ($oathWords as $word) {
            $oathWordsHtml .= <<<HTML
        <ruby class="word-ruby">
            <span>{$word['fil']}</span>
            <rt>{$word['eng']}</rt>
        </ruby>
    HTML;
        }

        $headerHtml = <<<HTML
        <div class="header-wrapper">
            <div class="logo-container">
                <img src="data:image/png;base64,{$logoBase64}" alt="City of Tagum Logo" class="logo" />
            </div>
            <div class="header-text-container">
                <div class="republic-text">REPUBLIC OF THE PHILIPPINES</div>
                <div class="province-text">PROVINCE OF DAVAO DEL NORTE</div>
                <div class="city-text">CITY OF TAGUM</div>
            </div>
        </div>
        <div class="green-banner">
            <div class="office-text"></div>
        </div>
    HTML;

        $footerHtml = <<<HTML
        <div class="footer-section">
            <div class="footer-item">
                <div class="footer-inner">
                    <div class="footer-icon">
                        <svg viewBox="0 0 24 24" fill="currentColor">
                            <path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z"/>
                        </svg>
                    </div>
                    <div class="footer-text">
                        2<sup>nd</sup> Floor, City Hall of Tagum,
                        <br />
                        JV Ayala Ave., Brgy. Apokon
                    </div>
                </div>
            </div>
            <div class="footer-item">
                <div class="footer-inner">
                    <div class="footer-icon">
                        <svg viewBox="0 0 24 24" fill="currentColor">
                            <path d="M6.62 10.79c1.44 2.83 3.76 5.14 6.59 6.59l2.2-2.2c.27-.27.67-.36 1.02-.24 1.12.37 2.33.57 3.57.57.55 0 1 .45 1 1V20c0 .55-.45 1-1 1-9.39 0-17-7.61-17-17 0-.55.45-1 1-1h3.5c.55 0 1 .45 1 1 0 1.25.2 2.45.57 3.57.11.35.03.74-.25 1.02l-2.2 2.2z"/>
                        </svg>
                    </div>
                    <div class="footer-text">{$footerPhone}</div>
                </div>
            </div>
            <div class="footer-item">
                <div class="footer-inner">
                    <div class="footer-icon">
                        <svg viewBox="0 0 24 24" fill="currentColor">
                            <path d="M20 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z"/>
                        </svg>
                    </div>
                    <div class="footer-text">{$footerEmail}</div>
                </div>
            </div>
        </div>
    HTML;

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Certification Report</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        .certification-report-wrapper {
            display: flex;
            flex-direction: column;
            align-items: center;
            width: 100%;
            padding: 20px 0;
            gap: 20px;
        }

        .certification-report-container {
            width: 8.5in;
            min-height: 16in;
            height: 13in;
            position: relative;
            font-family: Arial, sans-serif;
            background-color: white;
            box-sizing: border-box;
            color: black;
            line-height: 1.5;
            letter-spacing: 0.5px;
            display: flex;
            flex-direction: column;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.25);
            page-break-after: always;
            page-break-inside: avoid;
              
        }

        .certification-report-container:last-child {
            page-break-after: auto;
        }


        /* Header Styles */
        .header-wrapper {
            margin-top: 25px;
            display: flex;
            align-items: center;
        }

        .logo-container {
            position: absolute;
            width: 90px;
            margin-top: 50px;
            height: 130px;
            margin-right: 10px;
            background-color: white;
        }

        .logo {
            padding-left: 5px;
            padding-right: 5px;
            padding-top: 0.25in;
            width: 90px;
            height: auto;
        }

        .header-text-container {
            flex: 1;
            color: #00703c;
        }

        .republic-text,
        .province-text {
            font-size: 8pt;
            font-weight: 500;
            padding-left: 1in;
            line-height: 1;
        }

        .city-text {
            font-size: 13pt;
            font-weight: bold;
            line-height: 1;
            padding-top: 2px;
            padding-left: 1in;
            text-transform: uppercase;
        }

        .green-banner {
            background-color: #008000;
            color: white;
            height: 36px;
            display: flex;
            align-items: center;
            margin-top: 5px;
            margin-bottom: 30px;
            width: calc(100% + 3in);
            margin-left: -1in;
        }

        .office-text {
            font-weight: bold;
            text-transform: uppercase;
            padding-left: 2in;
            font-size: 13pt;
        }

        /* Footer Styles */
       .footer-section {
        position: absolute;
        bottom: 0;
        left: 0;
        right: 0;
        padding: 0 1in;
        padding-bottom: 20px;
        padding-top: 10px;
        width: 100%;
        display: flex;
        justify-content: space-between;
        color: #00703c;
        font-size: 9pt;
        background: white;
        box-sizing: border-box;
        border-top: 1px solid #e0e0e0;
        }

        .footer-item {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 33.33%;
            height: 40px;
            box-sizing: border-box;
        }

        .footer-inner {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .footer-icon {
            width: 16px;
            height: 16px;
            min-width: 16px;
            border-radius: 50%;
            background-color: #00703c;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
        }

        .footer-icon svg {
            display: block;
            width: 10px;
            height: 10px;
        }

        .footer-text {
            color: #00703c;
            line-height: 1.3;
            text-align: left;
        }

        .footer-text sup {
            font-size: 0.7em;
            vertical-align: super;
        }

        .certification-title {
            text-align: center;
            margin-bottom: 50px;
        }

        .certification-title h1 {
            font-size: 18pt;
            font-weight: bold;
            letter-spacing: 1px;
            margin-top: 80px;    
        }

        .certification-body {
            text-align: justify;
        }

        .main-text {
            margin-bottom: 15px;
            text-align: justify;
            line-height: 1.8;
            font-size: 14pt;
        }

        .indented {
            text-indent: 2em;
        }

        .issuance-text {
            margin-top: 15px;
            margin-bottom: 60px;
            text-align: justify;
            line-height: 1.8;
            font-size: 14pt;
        }

        .bold {
            font-weight: bold;
        }

        .underline {
            text-decoration: underline;
        }

        .oath-content {
            text-align: justify;
            margin-bottom: 1.5em;
            line-height: 3;
            font-size: 14pt;
        }

        .word-ruby {
            display: inline ruby;
            letter-spacing: 1;
            text-align: center;
            ruby-align: center;
            word-break: normal;
        }

        rt {
            font-size: 10pt;
            font-style: italic;
            color: #7f8c8d;
            word-break: normal;
        }

        ruby {
            ruby-position: under;
        }

        .signature-container {
            width: 100%;
            display: flex;
            justify-content: flex-end;
            margin-top: 60px;
        }

        .left-signature-container {
            width: 100%;
            display: flex;
            flex-direction: column;
            justify-content: flex-start;
            margin-top: 30px;
        }

        .stamp {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            font-size: 14pt;
        }

        .double {
            width: 100%;
            height: 6px;
            border-top: 1px solid black;
            border-bottom: 1px solid black;
            margin: 20px 0;
        }

        .signature-section {
            width: 4in;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding-top: 50px;
        }

        .signature-line {
            width: 100%;
            border-bottom: 1px solid black;
            margin-bottom: 5px;
        }

        .signature-name {
            font-weight: bold;
            text-align: center;
            text-transform: uppercase;
            font-size: 14pt;
        }

        .signature-title {
            text-align: center;
            font-size: 12pt;
        }


        .subtitle {
            font-size: 10pt;
            font-weight: normal;
            margin-top: 5px;
        }

        .FS {
            font-family: 'Times New Roman', Times, serif;
        }

        @media print {
            .certification-report-wrapper {
                padding: 0;
                gap: 0;
            }
            .certification-report-container {
                box-shadow: none;
                page-break-after: always;
                page-break-inside: avoid;
                height: 13in;
            }
            .certification-report-container:last-child {
                page-break-after: auto;
            }
            .footer-section {
                border-top: 1px solid #e0e0e0;
            }
        }
    </style>
</head>
<body>
    <div class="certification-report-wrapper">
        <!-- Page 1 -->
        <div class="certification-report-container page">
            {$headerHtml}

            <div class="report-content">
                <div class="certification-title">
                    <h1>CERTIFICATION FOR APPOINTMENTS ISSUED IN LOCAL GOVERNMENT UNITS (LGUs)</h1>
                </div>
                <div class="certification-body">
                    <p class="main-text indented">
                        This is to certify all pertinent provisions of Sec 325 of RA No. 7160 (Local
                        Government Code of 1991) have been complied with relative to the appointments issued
                        on {$formattedDateEnglish}
                    </p>

                    <div class="signature-container">
                        <div class="signature-section">
                            <div class="signature-name">{$signatoryName}</div>
                            <div class="signature-line"></div>
                            <div class="signature-title">{$signatoryTitle}</div>
                        </div>
                    </div>

                    <div class="stamp" style="padding-top: 35px; padding-bottom: 40px">
                        Date: {$formattedDateEnglish}
                    </div>
                </div>

                <div class="certification-title">
                    <h1>CERTIFICATION ON AVAILABILITY OF FUNDS</h1>
                </div>
                <div class="certification-body">
                    <p class="main-text indented">
                        This is to certify that funds are available pursuant to {$presAppro}
                    </p>

                    <div class="signature-container">
                        <div class="signature-section">
                            <div class="signature-name">{$cityAccountant}</div>
                            <div class="signature-line"></div>
                            <div class="signature-title">CITY ACCOUNTANT</div>
                        </div>
                    </div>

                    <div class="stamp" style="padding-top: 35px; padding-bottom: 40px">
                        Date: {$formattedDateEnglish}
                    </div>
                </div>
            </div>

            {$footerHtml}
        </div>

        <!-- Page 2 -->
        <div class="certification-report-container page">
            {$headerHtml}

            <div class="report-content">
                <div style=" margin-bottom: 10px;">
                    <div>CSC Form No. 4</div>
                    <div>Revised 2025</div>
                </div>

                <div class="certification-title">
                    <h1>CERTIFICATION OF ASSUMPTION TO DUTY</h1>
                </div>
                <div class="certification-body">
                    <p class="main-text indented">
                        This is to certify that {$salutation} <span class="bold underline">{$name4}</span>
                        has assumed the duties and responsibilities as
                        <span class="bold underline">{$newDesignation}</span>
                        of
                        <span class="bold underline">{$newOffice}</span>
                        effective
                        <span class="bold underline">{$formattedDateEnglish}</span>.
                    </p>

                    <p class="main-text indented">
                        This certification is being issued in connection with the issuance of the appointment
                        of {$salutation} <span class="bold underline">{$name4}</span>
                        as <span class="bold underline">{$newDesignation}</span>.
                    </p>

                    <p class="issuance-text indented">
                        Done this {$formattedDayWithSuffix} day of {$formattedMonth}, {$formattedYear} at the
                        City Government Center, JV Ayala Avenue, Apokon, Tagum City, Davao del Norte.
                    </p>
                    <div class="signature-container">
                        <div class="signature-section">
                            <div class="signature-name">{$officeHeadName}</div>
                            <div class="signature-line"></div>
                            <div class="signature-title">{$officeHeadPosition}</div>
                        </div>
                    </div>

                    <div class="left-signature-container">
                        <div class="stamp">Attested by:</div>
                        <div class="signature-section">
                            <div class="signature-name">{$hr}</div>
                            <div class="signature-line"></div>
                            <div class="signature-title">CITY HUMAN RESOURCE MGT. OFFICER</div>
                        </div>
                        <div class="stamp" style="padding-top: 35px; padding-bottom: 40px">
                            Date: {$formattedDateEnglish}
                        </div>
                    </div>

                    <div class="stamp" style="display: flex; flex-direction: col; margin-top: 20px;">
                        <div>201 file</div>
                        <div>Admin</div>
                        <div>COA</div>
                        <div>CSC</div>
                    </div>
                </div>
            </div>

          
        </div>

        <!-- Page 3 -->
        <div class="certification-report-container page">
            {$headerHtml}

            <div class="report-content">
                <div style="margin-bottom: 10px;">
                    <div><b>SS Porma Blg. 32</b></div>
                    <div><i>CS Form No. 32</i></div>
                </div>

                <div style=" margin-bottom: 10px;">
                    <div><b>Narebisa 2025</b></div>
                    <div><i>Revised 2025</i></div>
                </div>

                <div class="certification-title">
                    <h1 style="margin-top: 30px">PANUNUMPA SA KATUNGKULAN</h1>
                    <div class="subtitle">OATH OF OFFICE</div>
                </div>
                <div class="certification-body FS">
                    <p class="oath-content indented">
                        {$oathWordsHtml}
                    </p>
                    <div class="indented">
                        <ruby class="word-ruby">
                            <span>KASIHAN NAWA AKO NG DIYOS.</span>
                            <rt>SO HELP ME GOD</rt>
                        </ruby>
                    </div>

                    <div class="signature-container">
                        <div class="signature-name">{$name4}</div>
                    </div>

                    <div class="left-signature-container">
                        <div>
                            Government ID:
                            <span class="underline">TIN</span>
                        </div>
                        <div>
                            Numero ng ID:
                            <span class="underline">{$tinNo}</span>
                        </div>
                        <div>
                            Araw ng Pagkakaloob:
                            <span style="display: inline-block; width: 80px; border-bottom: 1px solid black"></span>
                        </div>
                    </div>

                    <div class="double"></div>

                    <p class="main-text indented">
                        Nilagdaan at pinanumpaan sa harap ko ngayong
                        {$formattedDateTagalog} sa Tagum City, Davao Del Norte, Pilipinas.
                    </p>

                    <div class="signature-container">
                        <div class="signature-section">
                            <div class="signature-name">{$signatoryName}</div>
                            <div class="signature-line"></div>
                            <div class="signature-title">{$signatoryTitle}</div>
                        </div>
                    </div>
                </div>
            </div>    
        </div>
    </div>
</body>
</html>
HTML;
    }

    /**
     * Generate Position Description Report HTML
     */
    private function generatePositionDescriptionHTML($data)
    {
        $sealBase64 = $this->getBase64Logo('images/image.png');
        $logoBase64 = $this->getBase64Logo('images/logo.png');

        // Embedded fonts so Chromium never falls back to a random system font.
        $fontRegular = $this->getFontBase64([
            public_path('fonts/Arimo-Regular.ttf'),
            'C:\\Windows\\Fonts\\arial.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
        ]);
        $fontBold = $this->getFontBase64([
            public_path('fonts/Arimo-Bold.ttf'),
            'C:\\Windows\\Fonts\\arialbd.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
        ]);

        $newDesignation = $data['NewDesignation'] ?? '';
        $itemNo = $data['ItemNo'] ?? '';
        $sg = $data['SG'] ?? '';
        $newOffice = $data['NewOffice'] ?: ($data['NewOFfice'] ?? '');
        $division = $data['Division'] ?? '';
        $presAppro = $data['PresAppro'] ?? '';
        $prevAppro = $data['PrevAppro'] ?? '';
        $salAuthorized = $data['SalAuthorized'] ?? '';
        $otherComp = $data['OtherComp'] ?? '';
        $supPosition = $data['SupPosition'] ?? '';
        $hSupPosition = $data['HSupPosition'] ?? '';
        $tool = $data['Tool'] ?? '';
        $contactOthers = $data['ContactOthers'] ?? '';
        $workingOthers = $data['WorkingOthers'] ?? '';
        $descriptionSection = $data['DescriptionSection'] ?? '';
        $descriptionFunction = $data['DescriptionFunction'] ?? '';
        $standardEduc = $data['StandardEduc'] ?? '';
        $standardExp = $data['StandardExp'] ?? '';
        $standardTrain = $data['StandardTrain'] ?? '';
        $standardElig = $data['StandardElig'] ?? '';
        $core1 = $data['Core1'] ?? '';
        $corelevel1 = $data['Corelevel1'] ?? '';
        $leader1 = $data['Leader1'] ?? '';
        $leaderlevel1 = $data['leaderlevel1'] ?? '';
        $name4 = $data['Name4'] ?? 'JOGRAD M. MAHUSAY';
        $supervisor = $data['Supervisor'] ?? '';

        $formattedDescFunction = $this->formatDescriptionFunction($descriptionFunction);

        // Checkbox states (1 = Occasional, 2 = Frequent), computed before the heredoc
        $isChecked = fn($value, $match) => ((string) $value === $match) ? 'checked' : '';

        $c1Occ = $isChecked($data['Contact1'] ?? '', '1');
        $c1Freq = $isChecked($data['Contact1'] ?? '', '2');
        $c2Occ = $isChecked($data['Contact2'] ?? '', '1');
        $c2Freq = $isChecked($data['Contact2'] ?? '', '2');
        $c3Occ = $isChecked($data['Contact3'] ?? '', '1');
        $c3Freq = $isChecked($data['Contact3'] ?? '', '2');
        $c4Occ = $isChecked($data['Contact4'] ?? '', '1');
        $c4Freq = $isChecked($data['Contact4'] ?? '', '2');
        $c5Occ = $isChecked($data['Contact5'] ?? '', '1');
        $c5Freq = $isChecked($data['Contact5'] ?? '', '2');
        $c6Occ = $isChecked($data['Contact6'] ?? '', '1');
        $c6Freq = $isChecked($data['Contact6'] ?? '', '2');
        $w1Occ = $isChecked($data['Working1'] ?? '', '1');
        $w1Freq = $isChecked($data['Working1'] ?? '', '2');
        $w2Occ = $isChecked($data['Working2'] ?? '', '1');
        $w2Freq = $isChecked($data['Working2'] ?? '', '2');

        return <<<HTML
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title>Position Description Report</title>
            <style>
                @font-face {
                    font-family: 'ReportFont';
                    src: url(data:font/ttf;base64,{$fontRegular}) format('truetype');
                    font-weight: normal;
                    font-style: normal;
                }
                @font-face {
                    font-family: 'ReportFont';
                    src: url(data:font/ttf;base64,{$fontBold}) format('truetype');
                    font-weight: bold;
                    font-style: normal;
                }

                /* Lay out at 2x; generatePDF() prints at scale 0.5 for this report. */
                html { zoom: 2; }

                * { margin: 0; padding: 0; box-sizing: border-box; }

                .position-description-wrapper {
                    display: flex;
                    flex-direction: column;
                    align-items: center;
                    width: 100%;
                    gap: 20px;
                }

                .position-description-container {
                    width: 8.5in;
                    min-height: 13in;
                    font-family: 'ReportFont', Arial, 'Liberation Sans', Helvetica, sans-serif;
                    font-size: 9pt;
                    line-height: 1.2;
                    box-sizing: border-box;
                    overflow: visible;
                    letter-spacing: normal;
                    text-rendering: geometricPrecision;
                    background: white;
                    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.25);
                    page-break-after: always;
                    page-break-inside: avoid;
                }

                .position-description-container:last-child {
                    page-break-after: auto;
                }

                .main-table {
                    width: 100%;
                    border-collapse: collapse;
                    border: 2px solid #000;
                    table-layout: fixed;
                    height: 100%;
                }

                .main-table td,
                .main-table th {
                    border: 1px solid #000;
                }

                /* Plain label cells (no class) keep a little breathing room */
                .main-table td:not([class]) {
                    padding: 2px 4px;
                }

                /* Qualification standards table */
                .qualifications-table {
                    width: 100%;
                    height: auto;
                    border-collapse: collapse;
                    table-layout: fixed;
                }

                .qualifications-table td {
                    width: 25%;
                    border: 1px solid #000;
                    padding: 4px;
                }

                .header-cell {
                    padding: 0;
                    border-bottom: 2px solid #000;
                    width: 50%;
                }

                .header {
                    display: flex;
                    align-items: center;
                    padding: 5px;
                    height: 100%;
                }

                .logo-container {
                    width: 60px;
                    flex-shrink: 0;
                }

                .logo {
                    width: 50px;
                    height: auto;
                }

                .header-text {
                    flex-grow: 1;
                    font-size: 9pt;
                    text-align: center;
                    font-weight: bold;
                }

                .right-logo {
                    text-align: right;
                }

                .section-header {
                    background-color: #ccc;
                    font-weight: bold;
                    text-transform: uppercase;
                    font-size: 8pt;
                    padding: 3px 4px;
                }

                .sub-header {
                    background-color: #f0f0f0;
                    font-weight: bold;
                    text-align: center;
                    font-size: 8pt;
                }

                .data-cell {
                    vertical-align: top;
                    padding: 4px;
                    font-weight: normal;
                }

                .dr-data-cell {
                    vertical-align: top;
                    min-height: 50px;
                    height: 50px;
                    padding: 4px;
                    font-weight: normal;
                }

                .empty-cell {
                    height: 30px;
                }

                .large-empty-cell {
                    min-height: 80px;
                    height: 80px;
                }

                .qs-large-empty-cell {
                    min-height: 80px;
                    height: 260px;
                }

                .large-cell {
                    min-height: 200px;
                    height: 200px;
                }

                /* Formatted content for numbered lists */
                .formatted-content {
                    text-align: left;
                    line-height: 1.4;
                }

                .formatted-content br {
                    display: block;
                    content: '';
                    margin: 0.3em 0;
                }

                .supervised-note {
                    height: auto;
                    padding-top: 5px;
                    padding-bottom: 5px;
                }

                .checkbox-group-container {
                    display: flex;
                    justify-content: space-between;
                    width: 100%;
                }

                .checkbox-group {
                    display: flex;
                    flex-direction: column;
                    width: 30%;
                    padding: 3px 0;
                }

                .checkbox-item {
                    margin-bottom: 4px;
                    display: flex;
                    align-items: center;
                    font-size: 9pt;
                }

                .checkbox-item input[type='checkbox'] {
                    margin-right: 8px;
                }

                .checkbox-item label {
                    font-weight: normal;
                }

                .checkbox-cell {
                    text-align: center;
                    vertical-align: middle;
                }

                .checkbox-wrapper {
                    display: inline-block;
                    position: relative;
                    width: 12px;
                    height: 12px;
                }

                .custom-checkbox {
                    -webkit-appearance: none;
                    -moz-appearance: none;
                    appearance: none;
                    width: 12px;
                    height: 12px;
                    border: 1px solid #000;
                    border-radius: 0;
                    background-color: #fff;
                    margin: 0;
                    padding: 0;
                    display: block;
                    box-sizing: border-box;
                }

                input[type='checkbox'].custom-checkbox:checked {
                    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 14 14'%3E%3Cpath d='M 3 7 L 6 10 L 11 4' stroke='black' stroke-width='2' fill='none'/%3E%3C/svg%3E");
                    background-position: center;
                    background-repeat: no-repeat;
                    background-size: 10px;
                }

                .small-text {
                    font-style: italic;
                    font-size: 8pt;
                    font-weight: normal;
                    text-align: center;
                }

                .contact-option {
                    background-color: #ccc;
                    font-weight: bold;
                    text-align: center;
                    text-transform: uppercase;
                    font-size: 8pt;
                    padding: 3px 4px;
                }

                .main-table td.no-padding {
                    padding: 0;
                }

                .description-cell {
                    text-align: left;
                    min-height: 80px;
                    font-weight: normal;
                    line-height: 1.3;
                }

                .bd-description-cell {
                    text-align: left;
                    min-height: 80px;
                    height: 525px;
                    font-weight: normal;
                    line-height: 1.3;
                }

                .acceptance-text {
                    text-align: left;
                    padding: 8px;
                    line-height: 1.3;
                }

                .signature-cell {
                    height: 60px;
                    vertical-align: bottom;
                    padding-bottom: 5px;
                }

                .signature-line {
                    display: flex;
                    flex-direction: column;
                    align-items: center;
                    width: 100%;
                }

                .signature {
                    border-bottom: 1px solid #000;
                    width: 100%;
                    text-align: center;
                    padding-bottom: 3px;
                    margin-bottom: 3px;
                    font-weight: normal;
                    font-size: 8pt;
                }

                .signature-label {
                    font-size: 8pt;
                    font-weight: normal;
                    text-align: center;
                }

                @media print {
                    .position-description-wrapper {
                        padding: 0;
                        gap: 0;
                    }
                    .position-description-container {
                        box-shadow: none;
                        padding: .5in;
                        page-break-after: always;
                        page-break-inside: avoid;
                        height: 13in;
                    }
                    .position-description-container:last-child {
                        page-break-after: auto;
                    }
                }
            </style>
        </head>
        <body>
            <div class="position-description-wrapper">
                <!-- Page 1 -->
                <div class="position-description-container page">
                    <table class="main-table">
                        <tbody>
                            <tr>
                                <td colspan="3" rowspan="2" class="header-cell">
                                    <div class="header">
                                        <div class="logo-container">
                                            <img src="data:image/png;base64,{$logoBase64}" alt="City of Tagum Logo" class="logo" />
                                        </div>
                                        <div class="header-text">
                                            <div>Republic of the Philippines</div>
                                            <div>POSITION DESCRIPTION FORM</div>
                                            <div>DBM-CSC Form No. 1</div>
                                            <div>(Revised Version No. 1, s. 2017)</div>
                                        </div>
                                        <div class="logo-container right-logo">
                                            <img src="data:image/png;base64,{$sealBase64}" alt="Philippine Seal" class="logo" />
                                        </div>
                                    </div>
                                </td>
                                <td colspan="3" class="section-header">
                                    1. POSITION TITLE (as approved by authorized agency) with parenthetical title
                                </td>
                            </tr>
                            <tr>
                                <td colspan="3" class="data-cell">{$newDesignation}</td>
                            </tr>

                            <tr>
                                <td colspan="3" class="section-header">2. ITEM NUMBER</td>
                                <td colspan="3" class="section-header">3. SALARY GRADE</td>
                            </tr>
                            <tr>
                                <td colspan="3" class="data-cell">{$itemNo}</td>
                                <td colspan="3" class="data-cell">{$sg}</td>
                            </tr>

                            <tr>
                                <td colspan="6" class="section-header">
                                    4. FOR LOCAL GOVERNMENT POSITION, ENUMERATE GOVERNMENTAL UNIT AND CLASS
                                </td>
                            </tr>
                            <tr>
                                <td colspan="6" class="data-cell">
                                    <div class="checkbox-group-container">
                                        <div class="checkbox-group">
                                            <div class="checkbox-item">
                                                <input type="checkbox" class="custom-checkbox" />
                                                <label>Province</label>
                                            </div>
                                            <div class="checkbox-item">
                                                <input type="checkbox" class="custom-checkbox" checked />
                                                <label>City</label>
                                            </div>
                                            <div class="checkbox-item">
                                                <input type="checkbox" class="custom-checkbox" />
                                                <label>Municipality</label>
                                            </div>
                                        </div>
                                        <div class="checkbox-group">
                                            <div class="checkbox-item">
                                                <input type="checkbox" class="custom-checkbox" checked />
                                                <label>1st Class</label>
                                            </div>
                                            <div class="checkbox-item">
                                                <input type="checkbox" class="custom-checkbox" />
                                                <label>2nd Class</label>
                                            </div>
                                            <div class="checkbox-item">
                                                <input type="checkbox" class="custom-checkbox" />
                                                <label>3rd Class</label>
                                            </div>
                                            <div class="checkbox-item">
                                                <input type="checkbox" class="custom-checkbox" />
                                                <label>4th Class</label>
                                            </div>
                                        </div>
                                        <div class="checkbox-group">
                                            <div class="checkbox-item">
                                                <input type="checkbox" class="custom-checkbox" />
                                                <label>5th Class</label>
                                            </div>
                                            <div class="checkbox-item">
                                                <input type="checkbox" class="custom-checkbox" />
                                                <label>6th Class</label>
                                            </div>
                                            <div class="checkbox-item">
                                                <input type="checkbox" class="custom-checkbox" />
                                                <label>Special</label>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                            </tr>

                            <tr>
                                <td colspan="3" class="section-header">5. AGENCY</td>
                                <td colspan="3" class="section-header">6. OFFICE</td>
                            </tr>
                            <tr>
                                <td colspan="3" class="data-cell">LOCAL GOVERNMENT UNIT OF TAGUM CITY</td>
                                <td colspan="3" class="data-cell">{$newOffice}</td>
                            </tr>

                            <tr>
                                <td colspan="3" class="section-header">7. DIVISION</td>
                                <td colspan="3" class="section-header">8. WORKSTATION / PLACE OF WORK</td>
                            </tr>
                            <tr>
                                <td colspan="3" class="data-cell">{$division}</td>
                                <td colspan="3" class="data-cell">
                                    CITY HALL GOVERNMENT CENTER, JV AYALA AVENUE, APOKON, TAGUM CITY
                                </td>
                            </tr>

                            <tr>
                                <td colspan="3" class="section-header">9. PRESENT APPROP ACT</td>
                                <td colspan="3" class="section-header">10. PREVIOUS APPROP ACT</td>
                            </tr>
                            <tr>
                                <td colspan="3" class="data-cell">{$presAppro}</td>
                                <td colspan="3" class="data-cell">{$prevAppro}</td>
                            </tr>
                            <tr>
                                <td colspan="3" class="section-header">11. SALARY AUTHORIZED</td>
                                <td colspan="3" class="section-header">12. OTHER COMPENSATION</td>
                            </tr>
                            <tr>
                                <td colspan="3" class="data-cell">{$salAuthorized}</td>
                                <td colspan="3" class="data-cell">{$otherComp}</td>
                            </tr>

                            <tr>
                                <td colspan="3" class="section-header">13. POSITION TITLE OF IMMEDIATE SUPERVISOR</td>
                                <td colspan="3" class="section-header">14. POSITION TITLE OF NEXT HIGHER SUPERVISOR</td>
                            </tr>
                            <tr>
                                <td colspan="3" class="data-cell">{$supPosition}</td>
                                <td colspan="3" class="data-cell">{$hSupPosition}</td>
                            </tr>

                            <tr>
                                <td colspan="6" class="section-header">
                                    15. POSITION TITLE, AND ITEM OF THOSE DIRECTLY SUPERVISED
                                </td>
                            </tr>
                            <tr>
                                <td colspan="6" class="data-cell small-text supervised-note">
                                    (If more than seven (7) list only by their item numbers and titles)
                                </td>
                            </tr>
                            <tr>
                                <td colspan="3" class="sub-header">POSITION TITLE</td>
                                <td colspan="3" class="sub-header">ITEM NUMBER</td>
                            </tr>
                            <tr>
                                <td colspan="3" class="data-cell empty-cell large-empty-cell"></td>
                                <td colspan="3" class="data-cell empty-cell large-empty-cell"></td>
                            </tr>

                            <tr>
                                <td colspan="6" class="section-header">
                                    16. MACHINE, EQUIPMENT, TOOLS, ETC., USED REGULARLY IN PERFORMANCE OF WORK
                                </td>
                            </tr>
                            <tr>
                                <td colspan="6" class="data-cell">{$tool}</td>
                            </tr>

                            <tr>
                                <td colspan="6" class="section-header">17. CONTACTS / CLIENTS / STAKEHOLDERS</td>
                            </tr>
                            <tr>
                                <td class="contact-option">17a. Internal</td>
                                <td class="contact-option">Occasional</td>
                                <td class="contact-option">Frequent</td>
                                <td class="contact-option">17b. External</td>
                                <td class="contact-option">Occasional</td>
                                <td class="contact-option">Frequent</td>
                            </tr>
                            <tr>
                                <td>Executive / Managerial</td>
                                <td class="checkbox-cell">
                                    <div class="checkbox-wrapper">
                                        <input type="checkbox" class="custom-checkbox" {$c1Occ} />
                                    </div>
                                </td>
                                <td class="checkbox-cell">
                                    <div class="checkbox-wrapper">
                                        <input type="checkbox" class="custom-checkbox" {$c1Freq} />
                                    </div>
                                </td>
                                <td>General Public</td>
                                <td class="checkbox-cell">
                                    <div class="checkbox-wrapper">
                                        <input type="checkbox" class="custom-checkbox" {$c5Occ} />
                                    </div>
                                </td>
                                <td class="checkbox-cell">
                                    <div class="checkbox-wrapper">
                                        <input type="checkbox" class="custom-checkbox" {$c5Freq} />
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <td>Supervisors</td>
                                <td class="checkbox-cell">
                                    <div class="checkbox-wrapper">
                                        <input type="checkbox" class="custom-checkbox" {$c2Occ} />
                                    </div>
                                </td>
                                <td class="checkbox-cell">
                                    <div class="checkbox-wrapper">
                                        <input type="checkbox" class="custom-checkbox" {$c2Freq} />
                                    </div>
                                </td>
                                <td>Other Agencies</td>
                                <td class="checkbox-cell">
                                    <div class="checkbox-wrapper">
                                        <input type="checkbox" class="custom-checkbox" {$c6Occ} />
                                    </div>
                                </td>
                                <td class="checkbox-cell">
                                    <div class="checkbox-wrapper">
                                        <input type="checkbox" class="custom-checkbox" {$c6Freq} />
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <td>Non-Supervisors</td>
                                <td class="checkbox-cell">
                                    <div class="checkbox-wrapper">
                                        <input type="checkbox" class="custom-checkbox" {$c3Occ} />
                                    </div>
                                </td>
                                <td class="checkbox-cell">
                                    <div class="checkbox-wrapper">
                                        <input type="checkbox" class="custom-checkbox" {$c3Freq} />
                                    </div>
                                </td>
                                <td>Others (please Specify):</td>
                                <td colspan="2" class="data-cell">{$contactOthers}</td>
                            </tr>
                            <tr>
                                <td>Staff</td>
                                <td class="checkbox-cell">
                                    <div class="checkbox-wrapper">
                                        <input type="checkbox" class="custom-checkbox" {$c4Occ} />
                                    </div>
                                </td>
                                <td class="checkbox-cell">
                                    <div class="checkbox-wrapper">
                                        <input type="checkbox" class="custom-checkbox" {$c4Freq} />
                                    </div>
                                </td>
                                <td colspan="3"></td>
                            </tr>

                            <tr>
                                <td colspan="6" class="section-header">18. WORKING CONDITION</td>
                            </tr>
                            <tr>
                                <td>Office Work</td>
                                <td class="checkbox-cell">
                                    <div class="checkbox-wrapper">
                                        <input type="checkbox" class="custom-checkbox" {$w1Occ} />
                                    </div>
                                </td>
                                <td class="checkbox-cell">
                                    <div class="checkbox-wrapper">
                                        <input type="checkbox" class="custom-checkbox" {$w1Freq} />
                                    </div>
                                </td>
                                <td>Others (please Specify)</td>
                                <td colspan="2">{$workingOthers}</td>
                            </tr>
                            <tr>
                                <td>Field Work</td>
                                <td class="checkbox-cell">
                                    <div class="checkbox-wrapper">
                                        <input type="checkbox" class="custom-checkbox" {$w2Occ} />
                                    </div>
                                </td>
                                <td class="checkbox-cell">
                                    <div class="checkbox-wrapper">
                                        <input type="checkbox" class="custom-checkbox" {$w2Freq} />
                                    </div>
                                </td>
                                <td colspan="3"></td>
                            </tr>

                            <tr>
                                <td colspan="6" class="section-header">
                                    19. BRIEF DESCRIPTION OF THE GENERAL FUNCTION OF THE UNIT OR SECTION
                                </td>
                            </tr>
                            <tr>
                                <td colspan="6" class="data-cell description-cell">{$descriptionSection}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Page 2 -->
                <div class="position-description-container page">
                    <table class="main-table">
                        <tbody>
                            <tr>
                                <td colspan="6" class="section-header">
                                    20. BRIEF DESCRIPTION OF THE GENERAL FUNCTION OF THE POSITION (Job Summary)
                                </td>
                            </tr>
                            <tr>
                                <td colspan="6" class="data-cell bd-description-cell large-cell formatted-content">
                                    <div>{$formattedDescFunction}</div>
                                </td>
                            </tr>

                            <tr>
                                <td colspan="6" class="section-header">21. QUALIFICATION STANDARDS</td>
                            </tr>
                            <tr>
                                <td colspan="6" class="no-padding">
                                    <table class="qualifications-table">
                                        <tbody>
                                            <tr>
                                                <td class="section-header">21A. EDUCATION</td>
                                                <td class="section-header">21B. EXPERIENCE</td>
                                                <td class="section-header">21C. TRAINING</td>
                                                <td class="section-header">21D. ELIGIBILITY</td>
                                            </tr>
                                            <tr>
                                                <td class="data-cell qs-large-empty-cell">{$standardEduc}</td>
                                                <td class="data-cell qs-large-empty-cell">{$standardExp}</td>
                                                <td class="data-cell qs-large-empty-cell">{$standardTrain}</td>
                                                <td class="data-cell qs-large-empty-cell">{$standardElig}</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </td>
                            </tr>

                            <tr>
                                <td colspan="5" class="section-header">21e. Core Competencies</td>
                                <td colspan="1" class="section-header">Competency Level</td>
                            </tr>
                            <tr>
                                <td colspan="5" class="data-cell">{$core1}</td>
                                <td colspan="1" class="data-cell">{$corelevel1}</td>
                            </tr>

                            <tr>
                                <td colspan="5" class="section-header">21f. Leadership Competencies</td>
                                <td colspan="1" class="section-header">Competency Level</td>
                            </tr>
                            <tr>
                                <td colspan="5" class="data-cell">{$leader1}</td>
                                <td colspan="1" class="data-cell">{$leaderlevel1}</td>
                            </tr>

                            <tr>
                                <td colspan="5" class="section-header">
                                    22. STATEMENT OF DUTIES AND RESPONSIBILITIES (Technical Competencies)
                                </td>
                                <td colspan="1" class="section-header">Competency Level</td>
                            </tr>
                            <tr>
                                <td colspan="2" class="data-cell">Percentage of Working Time</td>
                                <td colspan="3" class="data-cell">(State the duties and responsibilities here.)</td>
                                <td colspan="1" rowspan="2" class="data-cell"></td>
                            </tr>
                            <tr>
                                <td colspan="2" class="dr-data-cell"></td>
                                <td colspan="3" class="dr-data-cell"></td>
                            </tr>

                            <tr>
                                <td colspan="6" class="section-header">23. ACKNOWLEDGEMENT AND ACCEPTANCE:</td>
                            </tr>
                            <tr>
                                <td colspan="6" class="data-cell acceptance-text">
                                    I have received a copy of this position description. It has been discussed with me and
                                    I have freely chosen to comply with the performance and behavior/conduct expectations
                                    contained herein.
                                </td>
                            </tr>
                            <tr>
                                <td colspan="3" class="signature-cell">
                                    <div class="signature-line">
                                        <div class="signature">{$name4}</div>
                                        <div class="signature-label">Employee's Name, Date and Signature</div>
                                    </div>
                                </td>
                                <td colspan="3" class="signature-cell">
                                    <div class="signature-line">
                                        <div class="signature">{$supervisor} &nbsp;&nbsp;&nbsp; &nbsp;&nbsp;&nbsp;&nbsp;</div>
                                        <div class="signature-label">Supervisor's Name, Date and Signature</div>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </body>
        </html>
        HTML;
    }

    /**
     * Generate PDF using Puppeteer
     */
    private function generatePDF($html, $type = 'appointment')
    {
        $tempDir = storage_path('app/temp');
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $uniqueId = uniqid();
        $tempHtml = $tempDir . '/report_' . $uniqueId . '.html';
        $tempPdf = $tempDir . '/output_' . $uniqueId . '.pdf';
        $tempScript = $tempDir . '/script_' . $uniqueId . '.mjs';

        file_put_contents($tempHtml, $html);

        // The position report is laid out at 2x (html { zoom: 2 }) and printed at 0.5.
        // The other reports print at normal scale.
        $pdfScale = $type === 'position' ? 0.5 : 1;

        $script = '
        import puppeteer from "puppeteer";
        import fs from "fs";

        const htmlPath = "' . addslashes($tempHtml) . '";
        const pdfPath = "' . addslashes($tempPdf) . '";

        (async () => {
            try {
                console.error("Starting Puppeteer...");

                const browser = await puppeteer.launch({
                    headless: true,
                    args: [
                        "--no-sandbox",
                        "--disable-setuid-sandbox",
                        "--disable-dev-shm-usage",
                        "--font-render-hinting=none"
                    ]
                });

                console.error("Browser launched...");

                const page = await browser.newPage();
                console.error("Page created...");

                const html = fs.readFileSync(htmlPath, "utf-8");
                console.error("HTML read, length: " + html.length);

                await page.setContent(html, {
                    waitUntil: "networkidle0",
                    timeout: 60000
                });
                await page.evaluateHandle("document.fonts.ready");
                console.error("Content set...");

                const pdf = await page.pdf({
                    width: "8.5in",
                    height: "13in",
                    scale: ' . $pdfScale . ',
                    printBackground: true,
                    margin: {
                        top: "0",
                        right: "0",
                        bottom: "0",
                        left: "0"
                    },
                    preferCSSPageSize: true
                });
                console.error("PDF generated, size: " + pdf.length);

                fs.writeFileSync(pdfPath, pdf);
                console.error("PDF written to file");

                console.log("SUCCESS");

                await browser.close();
            } catch (error) {
                console.error("ERROR: " + error.message);
                console.error(error.stack);
                process.exit(1);
            }
        })();
        ';

        file_put_contents($tempScript, $script);

        $cmd = 'node ' . $tempScript . ' 2>&1';
        Log::info('Executing: ' . $cmd);

        $output = shell_exec($cmd);
        Log::info('Output: ' . $output);

        @unlink($tempHtml);
        @unlink($tempScript);

        if (strpos($output, 'ERROR:') !== false) {
            throw new \Exception('Puppeteer error: ' . $output);
        }

        if (!file_exists($tempPdf)) {
            throw new \Exception('PDF file not created. Output: ' . $output);
        }

        $pdfData = file_get_contents($tempPdf);
        @unlink($tempPdf);

        if (!$pdfData) {
            throw new \Exception('Failed to read PDF');
        }

        if (substr($pdfData, 0, 4) !== '%PDF') {
            throw new \Exception('Invalid PDF. Header: ' . bin2hex(substr($pdfData, 0, 20)));
        }

        return $pdfData;
    }

    /**
     * Helper methods
     */
    private function formatName($data)
    {
        $firstName = $data['Firstname'] ?? '';
        $middleName = $data['MIddlename'] ?? '';
        $middleInitial = '';

        if (!empty($middleName)) {
            $middleInitial = strtoupper(substr($middleName, 0, 1)) . '. ';
        }

        $surname = $data['Surname'] ?? '';

        if (empty($firstName) && empty($surname)) {
            return '(Name)';
        }

        return trim($firstName . ' ' . $middleInitial . $surname);
    }

    private function getSalutation($sex)
    {
        return strtoupper($sex) === 'MALE' ? 'MR.' : 'MS.';
    }

    private function isSanggunianOffice($office)
    {
        return (
            strpos($office, 'VICE MAYOR') !== false ||
            strpos($office, 'SANGGUNIANG PANLUNGSOD') !== false ||
            strpos($office, 'SANGGUNIAN') !== false
        );
    }

    private function getOfficeTitle($office)
    {
        return $this->isSanggunianOffice($office)
            ? 'OFFICE OF THE VICE MAYOR'
            : 'OFFICE OF THE CITY MAYOR';
    }

    private function getSignatoryName($data)
    {
        $office = $data['NewOffice'] ?? '';
        return $this->isSanggunianOffice($office)
            ? ($data['vicemayor'] ?? 'ATTY. EVA LORRAINE E. ESTABILLO')
            : ($data['mayor'] ?? 'REY T. UY');
    }

    private function getSignatoryTitle($office)
    {
        return $this->isSanggunianOffice($office)
            ? 'City Vice Mayor'
            : 'City Mayor';
    }

    private function getSignatoryRepName($office)
    {
        return $this->isSanggunianOffice($office)
            ? 'DELVIN M. SANTOS'
            : 'EDGAR C. DE GUZMAN';
    }

    private function getSignatoryRepPosition($office)
    {
        return $this->isSanggunianOffice($office)
            ? 'Executive Assistant IV'
            : 'City Administrator';
    }

    private function getSignatoryRepOffice($office)
    {
        return $this->isSanggunianOffice($office)
            ? 'City Vice Mayor'
            : 'City Mayor';
    }

    private function formatRenew($data)
    {
        $renewValue = $data['Renew'] ?? '';
        $employmentType = strtoupper($data['employmenttype'] ?? '');

        if ($renewValue === 'ORIGINAL' && $employmentType === 'PERMANENT') {
            return $renewValue . '*';
        }
        return $renewValue;
    }

    private function shouldShowProbationaryNote($data)
    {
        $renewValue = $data['Renew'] ?? '';
        $employmentType = strtoupper($data['employmenttype'] ?? '');

        return $renewValue === 'ORIGINAL' && $employmentType === 'PERMANENT';
    }

    private function isCoterminousOrElective($data)
    {
        $status = $data['Status'] ?? '';
        return $status === 'CO-TERMINOUS' || $status === 'Elective';
    }

    private function formatDate($dateStr)
    {
        if (empty($dateStr)) {
            return 'N/A';
        }

        try {
            $date = new \DateTime($dateStr);
            return $date->format('d F Y');
        } catch (\Exception $e) {
            return 'N/A';
        }
    }

    private function formatDateEnglish($date)
    {
        if (!$date)
            return '';
        $dateObj = new \DateTime($date);
        return $dateObj->format('j F Y');
    }

    private function formatDateTagalog($date)
    {
        if (!$date)
            return '';
        $dateObj = new \DateTime($date);
        $day = $dateObj->format('j');
        $year = $dateObj->format('Y');

        $tagalogMonths = [
            1 => 'Enero',
            2 => 'Pebrero',
            3 => 'Marso',
            4 => 'Abril',
            5 => 'Mayo',
            6 => 'Hunyo',
            7 => 'Hulyo',
            8 => 'Agosto',
            9 => 'Setyembre',
            10 => 'Oktubre',
            11 => 'Nobyembre',
            12 => 'Disyembre'
        ];

        $month = $tagalogMonths[(int) $dateObj->format('n')];
        return "ika-{$day} ng {$month}, {$year}";
    }

    private function formatDayWithSuffix($date)
    {
        if (!$date)
            return '';
        $dateObj = new \DateTime($date);
        $day = (int) $dateObj->format('j');

        if ($day > 3 && $day < 21)
            return $day . 'th';
        switch ($day % 10) {
            case 1:
                return $day . 'st';
            case 2:
                return $day . 'nd';
            case 3:
                return $day . 'rd';
            default:
                return $day . 'th';
        }
    }

    private function formatMonth($date)
    {
        if (!$date)
            return '';
        $dateObj = new \DateTime($date);
        return $dateObj->format('F');
    }

    private function formatYear($date)
    {
        if (!$date)
            return '';
        $dateObj = new \DateTime($date);
        return $dateObj->format('Y');
    }

    private function formatDescriptionFunction($text)
    {
        if (empty($text))
            return '';

        $text = preg_replace('/(\d+\.)\s*/', '<br/>$1 ', $text);
        $text = preg_replace('/(\d+\))\s*/', '<br/>$1 ', $text);
        $text = preg_replace('/([a-z]\))\s*/', '<br/>$1 ', $text);
        $text = preg_replace('/((?:i|ii|iii|iv|v|vi|vii|viii|ix|x)\.)\s*/i', '<br/>$1 ', $text);
        $text = preg_replace('/^<br\/>/', '', $text);
        $text = preg_replace('/(<br\/>){2,}/', '<br/>', $text);

        return $text;
    }

    private function formatSalaryWords($amount)
    {
        if (empty($amount)) {
            return '';
        }

        $numericAmount = floatval($amount);
        $wholeNumber = floor($numericAmount);
        $decimalPart = round(($numericAmount - $wholeNumber) * 100);

        $words = $this->numberToWords($wholeNumber);

        if ($decimalPart > 0) {
            $words .= ' AND ' . $this->numberToWords($decimalPart) . '/100';
        }

        return $words;
    }

    private function numberToWords($num)
    {
        if ($num === 0) {
            return 'ZERO';
        }

        $ones = [
            '',
            'ONE',
            'TWO',
            'THREE',
            'FOUR',
            'FIVE',
            'SIX',
            'SEVEN',
            'EIGHT',
            'NINE',
            'TEN',
            'ELEVEN',
            'TWELVE',
            'THIRTEEN',
            'FOURTEEN',
            'FIFTEEN',
            'SIXTEEN',
            'SEVENTEEN',
            'EIGHTEEN',
            'NINETEEN'
        ];
        $tens = ['', '', 'TWENTY', 'THIRTY', 'FORTY', 'FIFTY', 'SIXTY', 'SEVENTY', 'EIGHTY', 'NINETY'];
        $scales = ['', 'THOUSAND', 'MILLION', 'BILLION', 'TRILLION'];

        $result = '';
        $scaleIndex = 0;

        while ($num > 0) {
            $chunk = $num % 1000;
            if ($chunk !== 0) {
                $chunkWords = $this->convertHundreds($chunk, $ones, $tens);
                if ($scales[$scaleIndex]) {
                    $chunkWords .= ' ' . $scales[$scaleIndex];
                }
                $result = $chunkWords . ($result ? ' ' . $result : '');
            }
            $num = floor($num / 1000);
            $scaleIndex++;
        }

        return $result;
    }

    private function convertHundreds($num, $ones, $tens)
    {
        $result = '';

        if ($num >= 100) {
            $result .= $ones[floor($num / 100)] . ' HUNDRED';
            $num %= 100;
            if ($num > 0) {
                $result .= ' ';
            }
        }

        if ($num >= 20) {
            $result .= $tens[floor($num / 10)];
            $num %= 10;
            if ($num > 0) {
                $result .= '-' . $ones[$num];
            }
        } elseif ($num > 0) {
            $result .= $ones[$num];
        }

        return $result;
    }

    private function formatSalaryAmount($amount)
    {
        if (empty($amount)) {
            return '';
        }
        return '₱' . number_format(floatval($amount), 2);
    }

    private function getBase64Logo($path)
    {
        $fullPath = public_path($path);
        if (!file_exists($fullPath)) {
            return '';
        }
        $imageData = file_get_contents($fullPath);
        return base64_encode($imageData);
    }

    /**
     * Returns base64 of the first font file that exists, or '' if none found.
     */
    private function getFontBase64(array $candidates): string
    {
        foreach ($candidates as $path) {
            if (is_file($path)) {
                return base64_encode(file_get_contents($path));
            }
        }
        return '';
    }
}