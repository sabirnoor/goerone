<?php

namespace App\Http\Controllers;

use App\Models\mst_country;
use App\Models\mst_state;
use App\Models\Staticcities;
use Illuminate\Http\Request;

class DashboardController extends Controller
{


    public function country(Request $request)
    {
        if ($request->isMethod('post')) {
            $post = $request->all();
            $country = mst_country::orderBy('name', 'ASC')->get();
            return response()->json([
                'status' => [
                    'success' => true,
                    'httpStatus' => 200,
                ],
                'result' => !empty($country) ? $country : [],
                'message' => 'success',
            ]);
        } else {
            return response()->json([
                'status' => [
                    'success' => false,
                    'httpStatus' => 404,
                ],
                'Booking' => [],
                'message' => 'Invalid method',
            ]);
        }
    }
    public function states(Request $request)
    {
        if ($request->isMethod('post')) {
            $data = mst_state::where('countryid', $request->countryid)->orderBy('name', 'ASC')->get();
            return response()->json([
                'status' => [
                    'success' => true,
                    'httpStatus' => 200,
                ],
                'result' => !empty($data) ? $data : [],
                'message' => 'success',
            ]);
        } else {
            return response()->json([
                'status' => [
                    'success' => false,
                    'httpStatus' => 404,
                ],
                'Booking' => [],
                'message' => 'Invalid method',
            ]);
        }
    }
    public function cities(Request $request)
    {
        if ($request->isMethod('post')) {
            $data = Staticcities::where('mst_country_id', $request->countryid)->where('mst_state_id', $request->stateid)->orderBy('cityName', 'ASC')->get();
            return response()->json([
                'status' => [
                    'success' => true,
                    'httpStatus' => 200,
                ],
                'result' => !empty($data) ? $data : [],
                'message' => 'success',
            ]);
        } else {
            return response()->json([
                'status' => [
                    'success' => false,
                    'httpStatus' => 404,
                ],
                'Booking' => [],
                'message' => 'Invalid method',
            ]);
        }
    }
}
