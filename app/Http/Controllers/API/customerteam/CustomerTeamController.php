<?php

namespace App\Http\Controllers\API\customerteam;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\CustomerTeam;
use Auth;

class CustomerTeamController extends Controller
{
    public function index()
    {

        $data = CustomerTeam::with(['customerUser', 'teamUser'])
            ->where('email',Auth::user()->email)
            ->get();

        return response()->json($data);
    }

    public function updateTeamStatus(Request $request){

        $data = CustomerTeam::findOrFail($request->team_id);

        $data->status = $request->status;
        $data->update();

        return response()->json([
            'message' => 'Team status updated successfully.'
        ]);

    }
}
