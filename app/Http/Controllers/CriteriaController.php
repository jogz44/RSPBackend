<?php

namespace App\Http\Controllers;

use App\Http\Requests\CriteriaLiBStoreRequest;
use App\Http\Requests\CriteriaLiBUpdateRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use App\Http\Requests\CriteriaRequest;
use App\Models\library\CriteriaLibrary;
use App\Models\criteria\criteria_rating;
use App\Services\CriteriaService;

class CriteriaController extends Controller
{

    // creating a criteria per job post and if the job post already have criteria then try
    // to create a new one criteria for that post it will be update the old criteria
    public function storeCriteria(CriteriaRequest $request, CriteriaService $criteriaService)
    {
        $validated = $request->validated();

        $result = $criteriaService->store($validated,$request);

        return $result;
    }


    // deleting the criteria of job_post
    public function deleteCriteria($id, Request $request, CriteriaService $criteriaService)
    {

        $result = $criteriaService->delete($id ,$request);

        return $result;

    }

      // this is for view criteria on admin to view the criteria of the job post
    public function viewCriteria($job_batches_rsp_id, CriteriaService $criteriaService)
    {

        $result = $criteriaService->view($job_batches_rsp_id);

        return $result;

    }


    // store criteria library
    public function criteriaLibStore(CriteriaLiBStoreRequest $request, CriteriaService $criteriaService)
    {

        $validated = $request->validated();

        $result = $criteriaService->libStore($validated,$request);

        return  $result;

    }

    // update criteria library
    public function criteriaLibUpdate( $criteriaId, CriteriaLiBUpdateRequest $request, CriteriaService $criteriaService)
    {

        $validated = $request->validated();

        $result = $criteriaService->libUpdate($validated,$criteriaId,$request);

        return $result;
    }

    // delete  criteria library
    public function criteriaLibDelete($criteriaId, Request $request, CriteriaService $criteriaService)
    {

        $result = $criteriaService->libDelete($criteriaId, $request);

        return $result;
    }


   // fetching the criteria
    public function fetchCriteriaDetails($criteriaId, CriteriaService $criteriaService)
    {

        $result = $criteriaService->details($criteriaId);

        return $result;

    }

    // fetch criteria base on the sg if the  job post are no criteria yet
    public function fetchNonCriteriaJob($sg, CriteriaService $criteriaService)
    {
        $sg = (int) $sg; // force integer

     $result = $criteriaService->CriteriaJob($sg);

        return $result;

    }

    // fetch all criteria library data
    public function fetchCriteriaLibrary()
    {
        $lib = CriteriaLibrary::all();

        return response()->json($lib);
    }



}
