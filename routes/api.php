<?php


use Illuminate\Support\Facades\Route;

use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\ProfileManagementController;
use App\Http\Controllers\API\UserRoleController;
use App\Http\Controllers\API\PermissionController;
use App\Http\Controllers\API\admin\ProjectController;
use App\Http\Controllers\API\admin\ProjectTaskController;
use App\Http\Controllers\API\admin\TaskAssigneeController;
use App\Http\Controllers\API\admin\TaskCommentController;
use App\Http\Controllers\API\admin\UserManagementController;
use App\Http\Controllers\API\admin\ProjectBriefController;
use App\Http\Controllers\API\admin\WorkSessionController;
use App\Http\Controllers\API\ChatController;
use App\Http\Controllers\API\SubscriptionController;

Route::post('/login', [AuthController::class, 'login']);
Route::post('/register', [AuthController::class, 'register']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::post('/update-profile', [ProfileManagementController::class, 'updateProfile']);
    Route::post('/update-password', [ProfileManagementController::class, 'updatePassword']);
    Route::post('/update-profile-pic', [ProfileManagementController::class, 'updateProfilePic']);

    Route::resource('user-roles', UserRoleController::class);
    Route::resource('permissions', PermissionController::class);
    
    Route::post('/update-fcm-token', [AuthController::class, 'updateFCMToken']);


    Route::prefix('admin')->middleware('role:2')->group(function () {

        //Project with tasks and comments Module

        Route::resource('project', ProjectController::class);
        Route::get('/projects-with-tasks', [ProjectController::class, 'projectsWithTasks']);
        Route::resource('project-task', ProjectTaskController::class);
        Route::resource('task-assignee', TaskAssigneeController::class);
        Route::post('/bulk-assign', [TaskAssigneeController::class, 'bulkAssign']);
        Route::resource('task-comment', TaskCommentController::class);
        Route::post('/mark-comments-read', [TaskCommentController::class, 'markAllAsRead']);
        Route::post('/mark-reply-comments-read', [TaskCommentController::class, 'markAllRepliesAsRead']);
        Route::post('/change-project-task-order', [ProjectTaskController::class, 'changeOrder']);

        Route::resource('project-brief', ProjectBriefController::class);
        Route::resource('/task-brief', App\Http\Controllers\API\admin\TaskBriefController::class);

        //ending Project Module

         //User management
        Route::resource('employee-user', UserManagementController::class);
        Route::resource('supervisor-user', UserManagementController::class);
        Route::resource('customer-user', UserManagementController::class);
        Route::resource('admin-user', UserManagementController::class);
        //Ending user management

        //work session 
        Route::resource('/work-session', WorkSessionController::class);
        //ending work session

        Route::resource('/project-chat', App\Http\Controllers\API\admin\ProjectChatController::class);

        //leaves 
        Route::resource('/leave-request', App\Http\Controllers\API\admin\LeaveRequestController::class);

        //dashboard
        Route::get('/dashboard', [App\Http\Controllers\API\DashboardController::class, 'adminStats']);

        Route::get('/all_subscriptions', [App\Http\Controllers\API\customer\SubscriptionController::class, 'adminAllSubscriptions']);

        //project assignees

        Route::post('/update-project-assignees', [App\Http\Controllers\API\admin\ProjectController::class, 'updateProjectAssignees']);

        Route::post('/update-project-status', [App\Http\Controllers\API\admin\ProjectController::class, 'updateStatus']);

        //customer team
        Route::resource('/customer-team', App\Http\Controllers\API\admin\CustomerTeamController::class);
        
        
        //
        Route::get('/projects-with-members', [ProjectController::class, 'projectsWithMember']);
        
        
        Route::post('/delete-idle-time', [App\Http\Controllers\API\employee\ScreenshotController::class, 'deleteIdleTime']);


    });


    Route::prefix('supervisor')->middleware('role:6')->group(function () {

        //Project with tasks and comments Module

        Route::resource('project', ProjectController::class);
        Route::get('/projects-with-tasks', [ProjectController::class, 'projectsWithTasks']);
        Route::resource('project-task', ProjectTaskController::class);
        Route::resource('task-assignee', TaskAssigneeController::class);
        Route::post('/bulk-assign', [TaskAssigneeController::class, 'bulkAssign']);
        Route::resource('task-comment', TaskCommentController::class);
        Route::post('/mark-comments-read', [TaskCommentController::class, 'markAllAsRead']);
        Route::post('/mark-reply-comments-read', [TaskCommentController::class, 'markAllRepliesAsRead']);
        Route::post('/change-project-task-order', [ProjectTaskController::class, 'changeOrder']);

        Route::resource('project-brief', ProjectBriefController::class);
        Route::resource('/task-brief', App\Http\Controllers\API\admin\TaskBriefController::class);

        //ending Project Module

        //User management
        Route::resource('employee-user', UserManagementController::class);
        Route::resource('supervisor-user', UserManagementController::class);
        Route::resource('customer-user', UserManagementController::class);
        Route::resource('admin-user', UserManagementController::class);
        //Ending user management

        //work session 
        Route::resource('/work-session', WorkSessionController::class);
        //ending work session

        Route::resource('/project-chat', App\Http\Controllers\API\admin\ProjectChatController::class);

        //leaves 
        Route::resource('/leave-request', App\Http\Controllers\API\admin\LeaveRequestController::class);

        //dashboard
        Route::get('/dashboard', [App\Http\Controllers\API\DashboardController::class, 'adminStats']);

        Route::get('/all_subscriptions', [App\Http\Controllers\API\customer\SubscriptionController::class, 'adminAllSubscriptions']);

        //project assignees

        Route::post('/update-project-assignees', [App\Http\Controllers\API\admin\ProjectController::class, 'updateProjectAssignees']);

        Route::post('/update-project-status', [App\Http\Controllers\API\admin\ProjectController::class, 'updateStatus']);

        //customer team
        Route::resource('/customer-team', App\Http\Controllers\API\admin\CustomerTeamController::class);
        
        
        //
        Route::get('/projects-with-members', [ProjectController::class, 'projectsWithMember']);


    });


    Route::prefix('employee')->middleware('role:3')->group(function () {

        //Project with tasks and comments Module

        Route::resource('/project', App\Http\Controllers\API\employee\ProjectController::class);

        Route::get('/projects-with-tasks', [App\Http\Controllers\API\employee\ProjectController::class, 'projectsWithTasks']);


        // Project module
        Route::resource('/project-task', App\Http\Controllers\API\employee\ProjectTaskController::class);
        Route::resource('/task-assignee', App\Http\Controllers\API\employee\TaskAssigneeController::class);
        Route::post('/bulk-assign', [App\Http\Controllers\API\employee\TaskAssigneeController::class, 'bulkAssign']);
        Route::resource('/task-comment', App\Http\Controllers\API\employee\TaskCommentController::class);
        Route::post('/mark-comments-read', [App\Http\Controllers\API\employee\TaskCommentController::class, 'markAllAsRead']);
        Route::post('/mark-reply-comments-read', [App\Http\Controllers\API\employee\TaskCommentController::class, 'markAllRepliesAsRead']);
        Route::post('/change-project-task-order', [App\Http\Controllers\API\employee\ProjectTaskController::class, 'changeOrder']);
        Route::resource('/project-brief', App\Http\Controllers\API\employee\ProjectBriefController::class);
        Route::resource('/task-brief', App\Http\Controllers\API\employee\TaskBriefController::class);

        // User management
        Route::resource('/employee-user', App\Http\Controllers\API\employee\UserManagementController::class);
        Route::resource('/customer-user', App\Http\Controllers\API\employee\UserManagementController::class);
        Route::resource('/admin-user', App\Http\Controllers\API\employee\UserManagementController::class);

        //work session
        Route::resource('/work-session', App\Http\Controllers\API\employee\WorkSessionController::class);
        
        Route::resource('/other-work-session', App\Http\Controllers\API\admin\WorkSessionController::class);
        
        Route::resource('/screenshot', App\Http\Controllers\API\employee\ScreenshotController::class);
        Route::post('/manual-time', [App\Http\Controllers\API\employee\WorkSessionController::class, 'manualSession']);
        Route::post('/stop-session', [App\Http\Controllers\API\employee\WorkSessionController::class, 'stop']);

        Route::post('/update-idle-time', [App\Http\Controllers\API\employee\ScreenshotController::class, 'upsertIdleTime']);

        //leaves 
        Route::resource('/leave-request', App\Http\Controllers\API\employee\LeaveRequestController::class);

        //project chats
        Route::resource('/project-chat', App\Http\Controllers\API\employee\ProjectChatController::class);
        
        
        Route::post('/update-project-status', [App\Http\Controllers\API\admin\ProjectController::class, 'updateStatus']);

        //dashboard
        
        Route::post('/update-project-assignees', [App\Http\Controllers\API\admin\ProjectController::class, 'updateProjectAssignees']);
        
         Route::resource('/customer-team', App\Http\Controllers\API\admin\CustomerTeamController::class);
        
         Route::middleware('employeeType:Manager')->group(function () {
             //project assignees

          //  Route::post('/update-project-assignees', [App\Http\Controllers\API\admin\ProjectController::class, 'updateProjectAssignees']);
    
            //customer team
           // Route::resource('/customer-team', App\Http\Controllers\API\admin\CustomerTeamController::class);
        });


        Route::middleware('employeeType:Supervisor')->group(function () {
             //project assignees

           // Route::post('/update-project-assignees', [App\Http\Controllers\API\admin\ProjectController::class, 'updateProjectAssignees']);
    
            //customer team
           // Route::resource('/customer-team', App\Http\Controllers\API\admin\CustomerTeamController::class);
        });
        
        
        Route::get('/projects-with-members', [ProjectController::class, 'projectsWithMember']);

    });


    Route::prefix('customer')->middleware('role:4')->group(function () {


        Route::resource('/project-chat', App\Http\Controllers\API\customer\ProjectChatController::class);
        Route::resource('/project', App\Http\Controllers\API\customer\ProjectController::class);
        
        Route::get('/projects-with-tasks', [App\Http\Controllers\API\customer\ProjectController::class, 'projectsWithTasks']);
        
        Route::resource('/work-session', App\Http\Controllers\API\customer\WorkSessionController::class);
        Route::resource('/screenshot', App\Http\Controllers\API\customer\ScreenshotController::class);
        Route::resource('/project-task', App\Http\Controllers\API\customer\ProjectTaskController::class);

        Route::middleware('auth:sanctum')->prefix('subscription')->group(function () {
            Route::get('/', [App\Http\Controllers\API\customer\SubscriptionController::class, 'index']);
            Route::get('/active', [App\Http\Controllers\API\customer\SubscriptionController::class, 'getActive']);
            Route::post('/create', [App\Http\Controllers\API\customer\SubscriptionController::class, 'store']);
            Route::post('/cancel/{id}', [App\Http\Controllers\API\customer\SubscriptionController::class, 'cancel']);

            Route::post('/payment-intent', [App\Http\Controllers\API\customer\SubscriptionController::class, 'reatePaymentIntent']);

            Route::get('/billing-detail', [App\Http\Controllers\API\customer\SubscriptionController::class, 'getBillingDetail']);

            Route::post('/update-billing-detail', [App\Http\Controllers\API\customer\SubscriptionController::class, 'updateBillingDetail']);
        });


        Route::middleware('auth:sanctum')->prefix('payment-method')->group(function () {
            Route::post('/attach', [App\Http\Controllers\API\customer\PaymentMethodController::class, 'attach']);
            Route::get('/list', [App\Http\Controllers\API\customer\PaymentMethodController::class, 'list']);
            Route::post('/set-default', [App\Http\Controllers\API\customer\PaymentMethodController::class, 'setDefault']);
            Route::delete('/delete/{paymentMethodId}', [App\Http\Controllers\API\customer\PaymentMethodController::class, 'delete']);

        });

        //dashboard
        Route::get('/dashboard', [App\Http\Controllers\API\DashboardController::class, 'customerStats']);

        //customer team
        Route::resource('/customer-team', App\Http\Controllers\API\customer\CustomerTeamController::class);
        
        
        Route::resource('/task-brief', App\Http\Controllers\API\employee\TaskBriefController::class);
        
        Route::resource('/task-comment', App\Http\Controllers\API\employee\TaskCommentController::class);

    });


    Route::prefix('member')->middleware('role:5')->group(function () {

        Route::resource('/project-chat', App\Http\Controllers\API\customer\ProjectChatController::class);
        Route::resource('/project', App\Http\Controllers\API\customer\ProjectController::class);
        
        Route::get('/projects-with-tasks', [App\Http\Controllers\API\customer\ProjectController::class, 'projectsWithTasks']);
        
        Route::resource('/work-session', App\Http\Controllers\API\customer\WorkSessionController::class);
        Route::resource('/screenshot', App\Http\Controllers\API\customer\ScreenshotController::class);
        Route::resource('/project-task', App\Http\Controllers\API\customer\ProjectTaskController::class);

        //customer team
        Route::resource('/customer-team', App\Http\Controllers\API\customerteam\CustomerTeamController::class);
        Route::post('/update-team-status', [App\Http\Controllers\API\customerteam\CustomerTeamController::class, 'updateTeamStatus']);


    });



});







Route::prefix('chat')->middleware('auth:sanctum')->group(function () {
    Route::post('send', [ChatController::class, 'store']);
    Route::post('update/{id}', [ChatController::class, 'update']);
    Route::post('reaction', [ChatController::class, 'addReaction']);
    Route::post('remove-reaction', [ChatController::class, 'removeReaction']);
    Route::post('read-status', [ChatController::class, 'markRead']);
    Route::post('bulk-read-status', [ChatController::class, 'bulkMarkRead']);
    Route::get('conversations/{userId}', [ChatController::class, 'getConversation']);
    Route::get('unread-count', [ChatController::class, 'unreadCount']);
    Route::get('users-list', [ChatController::class, 'getChatUsersList']);
    Route::delete('delete/{id}', [ChatController::class, 'deleteMessage']);
});


 //Route::get('/logout-other/{id}', [App\Http\Controllers\API\AuthController::class, 'logoutOtherUser']);
 
 
  Route::get('/test-data', [ProjectController::class, 'testData']);
