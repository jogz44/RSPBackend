<?php

namespace App\Services;

use App\Models\vwplantillastructure;

class PlantillaService
{
    /**
     * Create a new class instance.
     */
    // public function __construct()
    // {
    //     //
    // }

    // fetch all employee on the plantilla
    public function fetchAllEmployeeOnplantilla($request)
    {

        // get the request args office name
        $query = vwplantillastructure::select([
            'vwplantillaStructure.ControlNo',
            'vwplantillaStructure.ID',
            'vwplantillaStructure.office',
            'vwplantillaStructure.office2',
            'vwplantillaStructure.group',
            'vwplantillaStructure.division',
            'vwplantillaStructure.section',
            'vwplantillaStructure.unit',
            'vwplantillaStructure.position',
            'vwplantillaStructure.PositionID',
            'vwplantillaStructure.PageNo',
            'vwplantillaStructure.ItemNo',
            'vwplantillaStructure.SG',
            'vwplantillaStructure.Funded',
            'vwplantillaStructure.level',
            'vwplantillaStructure.Name1',
            'vwplantillaStructure.Pics',
            'vwplantillaStructure.Status as plantillaStatus',
            'vwplantillaStructure.Name4',
            'vwplantillaStructure.OfficeID',
            'vwActive.BirthDate',
            'vwActive.Designation',
            'yDesignation.Status as designationStatus',
            'yDesignation.PMID as designationPositionId',

        ])
            ->leftJoin('vwActive', 'vwplantillaStructure.ControlNo', '=', 'vwActive.ControlNo')
            ->leftJoin('yDesignation', 'vwplantillaStructure.PositionID', '=', 'yDesignation.PMID')

            ->distinct();

        // Filter by office if provided: /plantilla?office=OfficeName
        if ($office = $request->query('office')) {
            $query->where('vwplantillaStructure.office', $office);
        }

        $plantilla = $query->get();

        return $plantilla;
    }
}
