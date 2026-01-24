<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable,HasApiTokens;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'phone',
    ];

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
    public function companyUsers()
    {
        return $this->hasMany(CompanyUser::class, 'user_id');
    }
    public function company()
    {
        return $this->hasOneThrough(
            CompanyInformation::class,
            CompanyUser::class,
            'user_id',              // Foreign key on CompanyUser table
            'id',                   // Foreign key on CompanyInformation table
            'id',                   // Local key on User table
            'company_information_id' // Local key on CompanyUser table
        );
    }

    public function screeningLogs()
    {
        return $this->hasMany(ScreeningLog::class, 'user_id');
    }

    public function goamlReports()
    {
        return $this->hasMany(GoamlReport::class, 'user_id');
    }
}
