<?php

namespace App\Http\Controllers\API\admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\CustomerTeam;

class CustomerTeamController extends Controller
{
    public function index()
    {

        $data = CustomerTeam::with(['customerUser', 'teamUser'])
            ->paginate(10);

        return response()->json($data);
    }

    public function destroy($id)
    {
        $teamMember = CustomerTeam::findOrFail($id);

        $teamMember->delete();

        return response()->json([
            'message' => 'Team member deleted successfully.'
        ]);
    }
}
