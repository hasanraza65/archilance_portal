<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\SoftDeletes;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */

    protected $guarded = [];
    use SoftDeletes;


    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }


    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function commentReadStatuses()
    {
        return $this->hasMany(TaskCommentReadStatus::class, 'receiver_id');
    }
    
    // Projects assigned to the user
    // Many-to-many pivot for projects
    public function assignedProjects()
    {
        return $this->belongsToMany(Project::class, 'project_assignees', 'employee_id', 'project_id');
    }
    
    // Many-to-many pivot for tasks (main + sub)
    public function assignedTasks()
    {
        return $this->belongsToMany(ProjectTask::class, 'task_assignees', 'employee_id', 'task_id');
    }



}
