<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class TemporaryService
{


public function updateTemporaryReorgAndExt(string $controlNo, ?array $validated)
{
    $validated = $validated ?? [];

    DB::table('temporary_tempreg_appointment_reorg')
        ->where('ControlNo', $controlNo)
        ->update([
            'sepdate'           => $validated['sepdate'] ?? null,
            'sepcause'          => $validated['sepcause'] ?? null,
            'vicename'          => $validated['vicename'] ?? null,
            'vicecause'         => $validated['vicecause'] ?? null,
            'deliberation_date' => $validated['deliberation_date'] ?? null,
            'renew'             => $validated['renew'] ?? null,
            'assessment_date'   => $validated['assessment_date'] ?? null,
        ]);

    DB::table('temporary_tempreg_appointment_reorg_ext')
        ->where('ControlNo', $controlNo)
        ->update([
             'ControlNo' => $controlNo,
            'PresAppro'        => $validated['PresAppro'] ?? null,
            'PrevAppro'        => $validated['PrevAppro'] ?? null,
            'SalAuthorized'    => $validated['SalAuthorized'] ?? null,
            'OtherComp'        => $validated['OtherComp'] ?? null,
            'SupPosition'      => $validated['SupPosition'] ?? null,
            'HSupPosition'     => $validated['HSupPosition'] ?? null,
            'Tool'             => $validated['Tool'] ?? null,
            'Contact1'         => $validated['Contact1'] ?? null,
            'Contact2'         => $validated['Contact2'] ?? null,
            'Contact3'         => $validated['Contact3'] ?? null,
            'Contact4'         => $validated['Contact4'] ?? null,
            'Contact5'         => $validated['Contact5'] ?? null,
            'Contact6'         => $validated['Contact6'] ?? null,
            'ContactOthers'    => $validated['ContactOthers'] ?? null,
            'Working1'         => $validated['Working1'] ?? null,
            'Working2'         => $validated['Working2'] ?? null,
            'WorkingOthers'    => $validated['WorkingOthers'] ?? null,
            'DescriptionSection'  => $validated['DescriptionSection'] ?? null,
            'DescriptionFunction' => $validated['DescriptionFunction'] ?? null,
            'StandardEduc'     => $validated['StandardEduc'] ?? null,
            'StandardExp'      => $validated['StandardExp'] ?? null,
            'StandardTrain'    => $validated['StandardTrain'] ?? null,
            'StandardElig'     => $validated['StandardElig'] ?? null,
            'Supervisor'       => $validated['Supervisor'] ?? null,
            'Core1'            => $validated['Core1'] ?? null,
            'Core2'            => $validated['Core2'] ?? null,
            'Core3'            => $validated['Core3'] ?? null,
            'Corelevel1'       => $validated['Corelevel1'] ?? null,
            'Corelevel2'       => $validated['Corelevel2'] ?? null,
            'Corelevel3'       => $validated['Corelevel3'] ?? null,
            'Corelevel4'       => $validated['Corelevel4'] ?? null,
            'Leader1'          => $validated['Leader1'] ?? null,
            'Leader2'          => $validated['Leader2'] ?? null,
            'Leader3'          => $validated['Leader3'] ?? null,
            'Leader4'          => $validated['Leader4'] ?? null,
            'leaderlevel1'     => $validated['leaderlevel1'] ?? null,
            'leaderlevel2'     => $validated['leaderlevel2'] ?? null,
            'leaderlevel3'     => $validated['leaderlevel3'] ?? null,
            'leaderlevel4'     => $validated['leaderlevel4'] ?? null,
            'structureid'      => $validated['structureid'] ?? null,
            'signing_date' => $validated['signing_date'] ?? null,
        ]);

    // Re-fetch so the response shows the updated data
    $reorg = DB::table('temporary_tempreg_appointment_reorg')
        ->where('ControlNo', $controlNo)
        ->first();

    $ext = DB::table('temporary_tempreg_appointment_reorg_ext')
        ->where('ControlNo', $controlNo)
        ->first();

    return response()->json([
        'success' => true,
        'message' => 'Update saved successfully.',
        'temporary_tempreg_appointment_reorg'     => $reorg,
        'temporary_tempreg_appointment_reorg_ext' => $ext,
    ]);
}
}