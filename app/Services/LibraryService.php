<?php

namespace App\Services;

use App\Models\LibRemark;
use App\Traits\ApiResponseTrait;

class LibraryService
{
    /**
     * Create a new class instance.
     */
    use ApiResponseTrait;


    // store library remarks
    public function storeRemark($validatedData)
    {

        $data = LibRemark::create($validatedData);

        return $this->successMessage($data, 'Successful store', 200);
    }

     // update library remarks
    public function updateRemark($remark_id,$validatedData)
    {

        $remark = LibRemark::find($remark_id);
        
        if (!$remark) {
            return $this->errorMessage('Remarks id not found');
        }
        
        $remark->update($validatedData);

        return $this->successMessage($remark,'Successfully Updated',200);
    }
}
