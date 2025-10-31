<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Project;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\ProjectTask;
use Auth;

class DashboardController extends Controller
{
    public function customerStats(){

        $total_projects = Project::where('customer_id',Auth::user()->id)->count();
        $total_in_progress_projects = Project::where('customer_id',Auth::user()->id)->where('status','In Progress')->count();
        $total_completed_projects = Project::where('customer_id',Auth::user()->id)->where('status','Completed')->count();

        return response()->json([
            "total_projects" => $total_projects,
            "total_in_progress_projects" => $total_in_progress_projects,
            "total_completed_projects" => $total_completed_projects
        ]);

    }

    /*
     public function employeeStats(){

        $total_projects = Project::where('customer_id',Auth::user()->id)->count();
        $total_in_progress_projects = Project::where('customer_id',Auth::user()->id)->where('status','In Progress')->count();
        $total_completed_projects = Project::where('customer_id',Auth::user()->id)->where('status','Completed')->count();

        return response()->json([
            "total_projects" => $total_projects,
            "total_in_progress_projects" => $total_in_progress_projects,
            "total_completed_projects" => $total_completed_projects
        ]);

    } */


    public function adminStats(){

        $total_projects = Project::count();
        $total_in_progress_projects = Project::where('status','In Progress')->count();
        $total_completed_projects = Project::where('status','Completed')->count();
        
        $total_tasks = ProjectTask::whereNull('parent_task_id')->count();

        $total_users = User::count();
        $total_employees =  User::where('user_role',3)->count();
        $total_customers =  User::where('user_role',4)->count();

        return response()->json([
            "total_projects" => $total_projects,
            "total_in_progress_projects" => $total_in_progress_projects,
            "total_completed_projects" => $total_completed_projects,
            "total_users" => $total_users,
            "total_employees" => $total_employees,
            "total_customers" => $total_customers,
            "total_tasks" => $total_tasks
        ]);

    }
}
