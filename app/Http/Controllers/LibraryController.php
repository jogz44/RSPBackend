<?php

namespace App\Http\Controllers;

use App\Models\LibRemark;
use App\Services\LibraryService;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\Request;

class LibraryController extends Controller
{
    //

    use ApiResponseTrait;

    protected $libService;

    public function __construct(LibraryService $libService)
    {
        $this->libService = $libService;
    }

    // store library remarks
    public function store(Request $request)
    {
        $validatedData = $request->validate([
            'remarks' => 'required|string|unique:lib_remarks,remarks',
            'category' => 'required|string'
        ]);

        $validatedData['remarks'] = strtoupper($validatedData['remarks']);
        $validatedData['category'] = strtoupper($validatedData['category']);

        $data = $this->libService->storeRemark($validatedData);

        return $data;
    }

    // fetch library remarks
    public function index()
    {
        $data = LibRemark::all()->map(function ($item) {
            return [
                'remarks_id' => $item->id,
                'remarks'    => $item->remarks,
                'created_at' => $item->created_at
                    ? $item->created_at->format('F d, Y')
                    : null,
                // 'updated_at' => $item->updated_at
                //     ? $item->updated_at->format('F d, Y')
                //     : null,
            ];
        });

        return $this->successMessage($data, 'Fetch Successful', 200);
    }

    // update library remarks
    public function update($remark_id, Request $request)
    {
        $validatedData = $request->validate([
            'remarks' => 'required|string|unique:lib_remarks,remarks,' . $remark_id,
            'category' =>'required|string'
        ]);

        $validatedData['remarks'] = strtoupper($validatedData['remarks']);
        $validatedData['category'] = strtoupper($validatedData['category']);

        $data = $this->libService->updateRemark($remark_id,$validatedData);

        return $data;
    }

     // delete library remarks
    public function delete($remark_id)
    {
        $remark = LibRemark::find($remark_id);

        if (!$remark) {
            return $this->errorMessage('Remarks id not found');
        }

        $remark->delete();

        return $this->successMessage($remark, 'Successfully Deleted', 200);
    }
}
