<?php

namespace App\Http\Controllers;

use App\Models\LibRemark;
use App\Services\LibraryService;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LibraryController extends Controller
{
    use ApiResponseTrait;

    protected $libService;

    public function __construct(LibraryService $libService)
    {
        $this->libService = $libService;
    }

    /**
     * Store library remarks
     */
    public function store(Request $request)
    {
            $validatedData = $request->validate([
                'remarks' => [
                    'required',
                    'string',
                    Rule::unique('lib_remarks', 'remarks')
                        ->where('category', strtoupper($request->input('category'))),
                ],
                'category' => 'required|string'
            ]);


        $validatedData['remarks'] = strtoupper($validatedData['remarks']);
        $validatedData['category'] = strtoupper($validatedData['category']);

        $data = $this->libService->storeRemark($validatedData);

        return $data;
    }

    /**
     * Fetch all library remarks
     */
    public function index(Request $request)
    {
        $query = LibRemark::query();

        // Optional: Filter by category if provided
        if ($request->has('category') && !empty($request->category)) {
            $query->where('category', strtoupper($request->category));
        }

        // Optional: Search by remarks if provided
        if ($request->has('search') && !empty($request->search)) {
            $query->where('remarks', 'LIKE', '%' . strtoupper($request->search) . '%');
        }

        // Optional: Sort by latest first
        $remarks = $query->latest()->get();

        $data = $remarks->map(function ($item) {
            return [
                'remarks_id' => $item->id,
                'remarks' => $item->remarks,
                'category' => $item->category,
                'created_at' => $item->created_at
                    ? $item->created_at->format('F d, Y')
                    : null,
                'updated_at' => $item->updated_at
                    ? $item->updated_at->format('F d, Y')
                    : null,
            ];
        });

        return $this->successMessage($data, 'Fetch Successful', 200);
    }

    /**
     * Get remarks by category
     */
    public function getByCategory($category)
    {
        $remarks = LibRemark::where('category', strtoupper($category))->get();

        $data = $remarks->map(function ($item) {
            return [
                'remarks_id' => $item->id,
                'remarks' => $item->remarks,
                'category' => $item->category,
                'created_at' => $item->created_at
                    ? $item->created_at->format('F d, Y')
                    : null,
            ];
        });

        return $this->successMessage($data, 'Fetch Successful', 200);
    }

    /**
     * Get single remark by ID
     */
    public function show($remark_id)
    {
        $remark = LibRemark::find($remark_id);

        if (!$remark) {
            return $this->errorMessage('Remarks id not found', 404);
        }

        $data = [
            'remarks_id' => $remark->id,
            'remarks' => $remark->remarks,
            'category' => $remark->category,
            'created_at' => $remark->created_at
                ? $remark->created_at->format('F d, Y')
                : null,
            'updated_at' => $remark->updated_at
                ? $remark->updated_at->format('F d, Y')
                : null,
        ];

        return $this->successMessage($data, 'Fetch Successful', 200);
    }

    /**
     * Update library remarks
     */
    public function update($remark_id, Request $request)
    {
        $validatedData = $request->validate([
            'remarks' => 'required|string|unique:lib_remarks,remarks,' . $remark_id,
            'category' => 'required|string'
        ]);

        $validatedData['remarks'] = strtoupper($validatedData['remarks']);
        $validatedData['category'] = strtoupper($validatedData['category']);

        $data = $this->libService->updateRemark($remark_id, $validatedData);

        return $data;
    }

    /**
     * Delete library remarks
     */
    public function delete($remark_id)
    {
        $remark = LibRemark::find($remark_id);

        if (!$remark) {
            return $this->errorMessage('Remarks id not found', 404);
        }

        // Store data before deletion for response
        $deletedData = [
            'remarks_id' => $remark->id,
            'remarks' => $remark->remarks,
            'category' => $remark->category,
            'deleted_at' => now()->format('F d, Y H:i:s')
        ];

        $remark->delete();

        return $this->successMessage($deletedData, 'Successfully Deleted', 200);
    }

    /**
     * Get all distinct categories
     */
    public function getCategories()
    {
        $categories = LibRemark::select('category')
            ->distinct()
            ->orderBy('category')
            ->get()
            ->pluck('category');

        return $this->successMessage($categories, 'Categories fetched successfully', 200);
    }

    /**
     * Bulk delete remarks
     */
    public function bulkDelete(Request $request)
    {
        $validatedData = $request->validate([
            'remark_ids' => 'required|array',
            'remark_ids.*' => 'exists:lib_remarks,id'
        ]);

        $deletedCount = LibRemark::whereIn('id', $validatedData['remark_ids'])->delete();

        return $this->successMessage(
            ['deleted_count' => $deletedCount],
            $deletedCount . ' remark(s) successfully deleted',
            200
        );
    }
}