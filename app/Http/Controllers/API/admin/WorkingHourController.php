<?php

namespace App\Http\Controllers\API\admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\WorkingHour;
use Auth;

class WorkingHourController extends Controller
{
    public function index()
    {

        $data = WorkingHour::with(['user', 'employee'])->get();

        return response()->json($data);

    }

    public function store(Request $request)
    {
        $employee_id = $request->employee_id;
        $start_time = $request->start_time;
        $end_time = $request->end_time;

        // 1️⃣ Basic sanity check
        if ($start_time >= $end_time) {
            return response()->json([
                'status' => false,
                'message' => 'End time must be greater than start time.',
            ], 422);
        }

        // 2️⃣ Check for conflicting slots
        $conflict = WorkingHour::where('employee_id', $employee_id)
            ->where(function ($query) use ($start_time, $end_time) {
                $query->where('start_time', '<', $end_time)
                    ->where('end_time', '>', $start_time);
            })
            ->exists();

        if ($conflict) {
            return response()->json([
                'status' => false,
                'message' => 'This time slot conflicts with an existing working hour.',
            ], 409);
        }

        // 3️⃣ Save if no conflict
        $workingHour = WorkingHour::create([
            'user_id' => Auth::id(),
            'employee_id' => $employee_id,
            'start_time' => $start_time,
            'end_time' => $end_time,
        ]);

        // 4️⃣ Success response
        return response()->json([
            'status' => true,
            'message' => 'Working hours added successfully.',
            'data' => $workingHour,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $employee_id = $request->employee_id;
        $start_time = $request->start_time;
        $end_time = $request->end_time;

        // 1️⃣ Find record
        $workingHour = WorkingHour::find($id);

        if (!$workingHour) {
            return response()->json([
                'status' => false,
                'message' => 'Working hour record not found.',
            ], 404);
        }

        // 2️⃣ Basic sanity check
        if ($start_time >= $end_time) {
            return response()->json([
                'status' => false,
                'message' => 'End time must be greater than start time.',
            ], 422);
        }

        // 3️⃣ Check conflict (exclude current record)
        $conflict = WorkingHour::where('employee_id', $employee_id)
            ->where('id', '!=', $id)
            ->where(function ($query) use ($start_time, $end_time) {
                $query->where('start_time', '<', $end_time)
                    ->where('end_time', '>', $start_time);
            })
            ->exists();

        if ($conflict) {
            return response()->json([
                'status' => false,
                'message' => 'This time slot conflicts with an existing working hour.',
            ], 409);
        }

        // 4️⃣ Update record
        $workingHour->update([
            'employee_id' => $employee_id,
            'start_time' => $start_time,
            'end_time' => $end_time,
        ]);

        // 5️⃣ Success response
        return response()->json([
            'status' => true,
            'message' => 'Working hours updated successfully.',
            'data' => $workingHour,
        ], 200);
    }

    public function show($id)
    {
        $workingHour = WorkingHour::where('employee_id',$id)->get();

        if (!$workingHour) {
            return response()->json([
                'status' => false,
                'message' => 'Working hour record not found.',
            ], 404);
        }

        return response()->json([
            'status' => true,
            'data' => $workingHour,
        ], 200);
    }

    public function destroy($id)
    {
        $workingHour = WorkingHour::find($id);

        if (!$workingHour) {
            return response()->json([
                'status' => false,
                'message' => 'Working hour record not found.',
            ], 404);
        }

        // Optional: authorization check
        /*
        if ($workingHour->user_id !== Auth::id()) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized action.',
            ], 403);
        } */

        $workingHour->delete();

        return response()->json([
            'status' => true,
            'message' => 'Working hour deleted successfully.',
        ], 200);
    }

}
