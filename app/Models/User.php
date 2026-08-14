<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

use App\Models\Challenges\{CloudCapsuleChallenge, ComparisonChallenge, DailyChallenge, FindTheBugChallenge, LiveDuelChallenge};
use App\Models\Questions\{MultipleChoiceQuestion, TrueFalseQuestion};
use App\Models\Posts\Post;
use App\Models\Users\UserAddress;
use App\Models\Friendship;
use App\Models\PostVote;
use App\Models\TeacherScope;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public function setSchoolGradeAttribute($value): void
    {
        $this->attributes['school_grade'] = static::normalizeSchoolGradeValue($value);
    }

    public static function normalizeSchoolGradeValue($value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $raw = trim((string) $value);
        if (preg_match('/\d/', $raw)) {
            return (string) preg_replace('/\D+/', '', $raw);
        }

        $clean = preg_replace('/^ال(?:ـ)?/u', '', $raw);
        $clean = preg_replace('/\s+/u', '', $clean);
        $clean = str_replace(['أ', 'إ', 'آ'], 'ا', $clean);
        $clean = strtolower($clean);

        $map = [
            'اول' => '1',
            'اولى' => '1',
            'ثاني' => '2',
            'ثانية' => '2',
            'ثالث' => '3',
            'ثالثة' => '3',
            'رابع' => '4',
            'رابعة' => '4',
            'خامس' => '5',
            'خامسة' => '5',
            'سادس' => '6',
            'سادسة' => '6',
            'سابع' => '7',
            'سابعة' => '7',
            'ثامن' => '8',
            'ثامنة' => '8',
            'تاسع' => '9',
            'تاسعة' => '9',
            'عاشر' => '10',
            'عاشرة' => '10',
            'حادي عشر' => '11',
            'الحادية عشرة' => '11',
            'ثاني عشر' => '12',
            'الثانية عشرة' => '12',
        ];

        foreach ($map as $label => $numeric) {
            if (str_contains($clean, $label)) {
                return $numeric;
            }
        }

        return $raw;
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'role',
        'school_grade',
        'gender',
        'location',
        'latitude',
        'longitude',
        'theme_mode',
        'password',
        'is_online',
        'last_seen',
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
            'phone' => 'encrypted',
            'password' => 'hashed',
            'school_grade' => 'string',
            'gender' => 'string',
            'theme_mode' => 'string',
            'is_online' => 'boolean',
            'last_seen' => 'datetime',
        ];
    }

    public function liveDuelChallenges()
    {
        return $this->hasMany(LiveDuelChallenge::class);
    }

    public function cloudCapsuleChallenges()
    {
        return $this->hasMany(CloudCapsuleChallenge::class);
    }

    public function comparisonChallenges()
    {
        return $this->hasMany(ComparisonChallenge::class);
    }

    public function dailyChallenges()
    {
        return $this->hasMany(DailyChallenge::class);
    }

    public function multipleChoiceQuestions()
    {
        return $this->hasMany(MultipleChoiceQuestion::class);
    }

    public function trueFalseQuestions()
    {
        return $this->hasMany(TrueFalseQuestion::class);
    }

    public function questionExplanations(): HasMany
    {
        return $this->hasMany(QuestionExplanation::class, 'teacher_id');
    }

    public function devices(): HasMany
    {
        return $this->hasMany(UserDevice::class);
    }

    public function notificationDeliveries(): HasMany
    {
        return $this->hasMany(NotificationUser::class);
    }

    public function notifications(): HasManyThrough
    {
        return $this->hasManyThrough(Notification::class, NotificationUser::class, 'user_id', 'id', 'id', 'notification_id');
    }

    public function findTheBugChallenges()
    {
        return $this->hasMany(FindTheBugChallenge::class);
    }

    public function posts()
    {
        return $this->hasMany(Post::class);
    }

    public function postVotes(): HasMany
    {
        return $this->hasMany(PostVote::class, 'user_id');
    }

    public function savedPosts(): BelongsToMany
    {
        return $this->belongsToMany(Post::class, 'saved_posts', 'user_id', 'post_id')
            ->withTimestamps();
    }

    public static function booted(): void
    {
        static::created(function (User $user): void {
            $user->wallet()->create([
                'balance' => 0.00,
            ]);
        });
    }

    public function address()
    {
        return $this->hasOne(UserAddress::class);
    }

    public function profile()
    {
        return $this->hasOne(UserProfile::class);
    }

    public function wallet()
    {
        return $this->hasOne(Wallet::class);
    }

    public function teacherScopes(): HasMany
    {
        return $this->hasMany(TeacherScope::class);
    }

    public function hasScope($grade, $subject, $permission = 'can_answer')
    {
        return $this->teacherScopes()
            ->where('school_grade', (string) $grade)
            ->where('subject', $subject)
            ->where($permission, true)
            ->exists();
    }

    public function friendsOfMine(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'friendships',
            'sender_id',
            'receiver_id'
        )
            ->withPivot('status')
            ->wherePivot('status', 'accepted')
            ->withTimestamps();
    }

    public function friendOf(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'friendships',
            'receiver_id',
            'sender_id'
        )
            ->withPivot('status')
            ->wherePivot('status', 'accepted')
            ->withTimestamps();
    }

    public function getFriendsAttribute()
    {
        return $this->friendsOfMine->merge($this->friendOf);
    }

    public function isAdmin(): bool
    {
        $role = strtolower((string) ($this->role ?? ''));

        return $role === 'admin' || $role === 'super_admin';
    }

    public function setPresence(bool $isOnline): void
    {
        $this->forceFill([
            'is_online' => $isOnline,
            'last_seen' => $isOnline ? null : now(),
        ])->saveQuietly();
    }
}
