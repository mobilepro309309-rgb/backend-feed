<?php

use Illuminate\Support\Facades\{Broadcast, Route};

use App\Http\Controllers\Api\Users\{UserController, UserProfileController};
use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\Posts\{CommentController, PostController};
use App\Http\Controllers\Api\{InteractiveVideoController, LatestQuestionsController, QuizBatchController, QuizTransactionController, UserSelectionController};
use App\Http\Controllers\Api\Admin\{AdminHomeController, AdminRoleController, AdminUsersController, AdminDeviceBanController, TeacherManagementController, QuestionTypeSettingController, CurriculumUnitsController, StageController, GradeController, TrackController, SubjectController, SpecializedSubjectController};
use App\Http\Controllers\Api\Location\{LocationController, NearbyStudentsController};
use App\Http\Controllers\Api\Challenges\{CheatSheetController, CloudCapsuleChallengeController, ComparisonChallengeController, DailyChallengeController, FindTheBugChallengeController, LiveDuelChallengeController};
use App\Http\Controllers\Api\Questions\{MultipleChoiceQuestionController, TrueFalseQuestionController};
use App\Http\Controllers\Api\v1\FeedController;
use App\Http\Controllers\{KashierController, NotificationController, SavedPostsController, ShareRewardController, WalletController, YouTubeVideoController};

Broadcast::routes(['middleware' => ['auth:sanctum']]);

$apiRoutes = function (): void {
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:auth')->name('login');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:auth')->name('register');
    Route::get('/security/device-response/status', [\App\Http\Controllers\Api\Security\PendingDeviceLoginController::class, 'status']);
    Route::get('/locations', [LocationController::class, 'index']);
    Route::get('/locations/governorates', [LocationController::class, 'governorates']);
    Route::get('/locations/governorates/{governorate}/districts', [LocationController::class, 'districtsForGovernorate']);

    // Public curriculum options required before a student has an auth token.
    Route::get('/registration/stages', [StageController::class, 'index']);
    Route::get('/registration/grades', [GradeController::class, 'index']);
    Route::get('/registration/tracks', [TrackController::class, 'index']);
    Route::get('/registration/specialized-subjects', [SpecializedSubjectController::class, 'index']);

    Route::get('/users/{id}/latest-posts', [LatestQuestionsController::class, 'getUserLatestPosts']);
    Route::get('/users/{id}/latest-questions', [LatestQuestionsController::class, 'getUserQuestions']);
    Route::get('/profile/{id}/latest-posts', [LatestQuestionsController::class, 'getUserLatestPosts']);
    Route::get('/daily-challenges', [DailyChallengeController::class, 'getAllChallenges']);
    Route::get('/daily-challenges/latest', [DailyChallengeController::class, 'getLatestChallenge']);
    Route::get('/daily-challenges/{id}', [DailyChallengeController::class, 'show']);
    Route::get('/find-the-bug-challenges/{id}', [FindTheBugChallengeController::class, 'show']);
    Route::get('/multiple-choice-questions/{id}', [MultipleChoiceQuestionController::class, 'show']);
    Route::get('/true-false-questions/{id}', [TrueFalseQuestionController::class, 'show']);
    Route::get('/comparison-challenges/{id}', [ComparisonChallengeController::class, 'show']);
    Route::get('/cheat-sheet/{id}', [CheatSheetController::class, 'show']);
    Route::post('/quiz-batch', [QuizBatchController::class, 'batch']);
    Route::post('/notifications/send', [NotificationController::class, 'send']);
    Route::post('/kashier/payment-hash', [KashierController::class, 'generatePaymentHash']);

    // Saved posts (development-friendly endpoints)
    Route::post('/posts/{postId}/toggle-save', [SavedPostsController::class, 'toggleSavePost']);
    Route::get('/users/{userId}/saved-posts', [SavedPostsController::class, 'getSavedPosts']);

    Route::get('/test-chat/{chatId}', function ($chatId) {
        return response()->json(['status' => 'success', 'chat_id' => $chatId]);
    });

    Route::get('/admin/users/filters/location', [AdminUsersController::class, 'getLocationFilters']);

    Route::get('/debug-chat/{chatId}', [\App\Http\Controllers\ChatController::class, 'getMessages'])
        ->withoutMiddleware(['auth:sanctum', 'throttle']);

    Route::middleware(['auth:sanctum', \App\Http\Middleware\CheckBannedDevice::class])->group(function (): void {
        Route::get('/feed', [FeedController::class, 'index']);
        Route::get('/chats/{chatId}/messages', [\App\Http\Controllers\ChatController::class, 'getMessages']);
        Route::get('/inbox', [\App\Http\Controllers\ChatController::class, 'getInbox']);
        Route::get('/chats', [\App\Http\Controllers\ChatController::class, 'index']);
        Route::post('/chats/resolve-or-create', [\App\Http\Controllers\ChatController::class, 'resolveOrCreateChat']);
        Route::post('/chats/resolve-by-teacher', [\App\Http\Controllers\ChatController::class, 'resolveChatByTeacher']);
        Route::post('/chats/{chatId}/ensure-participant', [\App\Http\Controllers\ChatController::class, 'ensureParticipant']);
        Route::post('/chat/send', [\App\Http\Controllers\ChatController::class, 'sendMessage']);
        Route::post('/messages', [\App\Http\Controllers\ChatController::class, 'sendMessage']);
        Route::post('/chat/get-or-create-private', [\App\Http\Controllers\ChatController::class, 'getOrCreatePrivateChat']);
        Route::post('/messages/initial-share', [\App\Http\Controllers\ChatController::class, 'createSharedMessage']);
        Route::post('/chats/{chatId}/delivered', [\App\Http\Controllers\ChatController::class, 'markAsDelivered']);
        Route::post('/messages/mark-as-read', [\App\Http\Controllers\ChatController::class, 'markAsRead']);
        Route::post('/chats/{chatId}/read', [\App\Http\Controllers\ChatController::class, 'markAsRead']);
        Route::post('/chat/typing', [\App\Http\Controllers\ChatController::class, 'broadcastTyping']);
        Route::post('/chats/{chatId}/typing', [\App\Http\Controllers\ChatController::class, 'broadcastTyping']);
        Route::get('/chats/{chatId}/typing', [\App\Http\Controllers\ChatController::class, 'getTypingStatus']);
        Route::get('/me', [AuthController::class, 'me']);
        Route::get('/online-users', [UserController::class, 'onlineUsers']);
        Route::post('/user/ping', [UserController::class, 'ping']);
        Route::post('/user/toggle-notifications', [UserController::class, 'toggleNotifications']);
        Route::post('/logout', [AuthController::class, 'logout']);

        // Store device push token for authenticated user
        Route::post('/notifications/token', [NotificationController::class, 'storeToken']);
        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::post('/notifications/mark-read', [NotificationController::class, 'markRead']);
        Route::post('/security/device-response', [\App\Http\Controllers\Api\Security\PendingDeviceLoginController::class, 'respond']);

        Route::get('/user/profile', [UserProfileController::class, 'show']);
        Route::put('/user/profile', [UserProfileController::class, 'update']);
        Route::post('/wallet/top-up', [WalletController::class, 'topUp']);
        Route::post('/share/reward', [ShareRewardController::class, 'claimShareReward'])->middleware('auth:sanctum');
        Route::post('/user/claim-whatsapp-reward', [ShareRewardController::class, 'claimWhatsappReward'])->middleware('auth:sanctum');

        // Quiz Transaction Routes - Gamified Quiz Progression System (Phase 1)
        Route::post('/quiz/unlock', [QuizTransactionController::class, 'unlock']);
        Route::post('/quiz/reward', [QuizTransactionController::class, 'reward']);

        Route::get('/profile/{id}/latest-questions', [LatestQuestionsController::class, 'getUserQuestions']);

        Route::put('/user/legacy-profile', [UserController::class, 'updateProfile']);
        Route::get('/nearby-students', [NearbyStudentsController::class, 'getNearbyCountOrList']);
        Route::get('/friends', [\App\Http\Controllers\Api\FriendshipController::class, 'index']);
        Route::post('/friends/resolve-for-user', [\App\Http\Controllers\Api\FriendshipController::class, 'resolveForUser']);
        Route::post('/friends/send', [\App\Http\Controllers\Api\FriendshipController::class, 'send']);
        Route::post('/friends/accept-teacher', [\App\Http\Controllers\Api\FriendshipController::class, 'acceptTeacher']);
        Route::post('/friends/cancel', [\App\Http\Controllers\Api\FriendshipController::class, 'cancel']);
        Route::post('/friends/accept', [\App\Http\Controllers\Api\FriendshipController::class, 'accept']);
        Route::post('/friends/decline', [\App\Http\Controllers\Api\FriendshipController::class, 'decline']);

        // User Selection Endpoints (teachers and classmates)
        Route::get('/users/teachers', [UserSelectionController::class, 'getTeachers']);
        Route::get('/users/classmates', [UserSelectionController::class, 'getClassmates']);
        Route::get('/teachers/available-for-post', [UserSelectionController::class, 'getAvailableTeachersForPost']);
        Route::get('/teachers/scope-filtered', [UserSelectionController::class, 'getScopeFilteredTeachers']);

        Route::get('/admin-roles', [AdminRoleController::class, 'index']);
        Route::get('/admin-roles/options', [AdminRoleController::class, 'options']);
        Route::post('/admin-roles', [AdminRoleController::class, 'store']);
        Route::put('/admin-roles/{user}', [AdminRoleController::class, 'update']);
        Route::delete('/admin-roles/{user}', [AdminRoleController::class, 'destroy']);

        Route::get('/teacher/my-scopes', [TeacherManagementController::class, 'myScopes']);
        Route::get('/teacher/my-questions-by-category', [TeacherManagementController::class, 'getMyQuestionsByCategory']);
        Route::put('/teacher/my-questions/{type}/{id}', [TeacherManagementController::class, 'updateMyQuestion']);
        Route::put('/teacher/my-questions/{type}/{id}/toggle-status', [TeacherManagementController::class, 'toggleMyQuestionStatus']);
        Route::put('/admin/questions/{type}/{id}/toggle-status', [TeacherManagementController::class, 'toggleMyQuestionStatus']);
        Route::delete('/teacher/my-questions/{type}/{id}', [TeacherManagementController::class, 'deleteMyQuestion']);
        Route::get('/teacher/pending-questions', [\App\Http\Controllers\ChatController::class, 'getPendingQuestions']);

        Route::prefix('admin/teachers')->group(function () {
            Route::get('/', [TeacherManagementController::class, 'index']);
            Route::post('/assign-scope', [TeacherManagementController::class, 'assignScope']);
            Route::delete('/scopes/{id}', [TeacherManagementController::class, 'removeScope']);
            Route::delete('/{user}', [TeacherManagementController::class, 'deleteTeacher']);
        });

        Route::get('/admin/users/lookup/{id}', [TeacherManagementController::class, 'lookupUser']);
        Route::get('/admin/home', [AdminHomeController::class, 'index']);
        Route::get('/admin/dashboard/home', [AdminHomeController::class, 'index']);
        Route::get('/admin/dashboard/posts', [AdminHomeController::class, 'getPosts']);
        Route::apiResource('admin/stages', StageController::class);
        Route::apiResource('admin/grades', GradeController::class);
        Route::apiResource('admin/tracks', TrackController::class);
        Route::apiResource('admin/subjects', SubjectController::class);
        Route::apiResource('admin/specialized-subjects', SpecializedSubjectController::class);
        Route::get('/admin/users', [AdminUsersController::class, 'index']);
        Route::put('/admin/users/{user}', [AdminUsersController::class, 'update']);
        Route::put('/admin/user-role', [AdminRoleController::class, 'updateUserRole']);
        Route::delete('/admin/users/{user}', [AdminUsersController::class, 'destroy']);
        Route::get('/admin/posts', [AdminHomeController::class, 'getPosts']);
        Route::get('/admin/pending-posts', [AdminHomeController::class, 'pendingPosts']);
        Route::post('/admin/posts/{post}/moderate', [AdminHomeController::class, 'moderatePost'])->middleware('throttle:moderation');

        // Explicit device ban route definitions to match the mobile app calls.
        Route::post('/admin/devices/ban', [AdminDeviceBanController::class, 'banDevice']);
        Route::post('/devices/ban', [AdminDeviceBanController::class, 'banDevice']);
        Route::delete('/admin/devices/unban/{ban}', [AdminDeviceBanController::class, 'unbanDevice']);
        Route::delete('/devices/unban/{ban}', [AdminDeviceBanController::class, 'unbanDevice']);
        Route::get('/admin/devices/banned', [AdminDeviceBanController::class, 'getBannedDevices']);
        Route::get('/devices/banned', [AdminDeviceBanController::class, 'getBannedDevices']);

        // Question Type Settings Management Routes
        Route::get('/admin/question-settings', [QuestionTypeSettingController::class, 'index']);
        Route::get('/admin/question-settings/{questionTypeSetting}', [QuestionTypeSettingController::class, 'show']);
        Route::put('/admin/question-settings/{questionTypeSetting}', [QuestionTypeSettingController::class, 'update']);
        Route::put('/admin/question-settings/type/{questionType}', [QuestionTypeSettingController::class, 'updateByType']);
        Route::post('/admin/question-settings/bulk-update', [QuestionTypeSettingController::class, 'bulkUpdate']);
        Route::post('/admin/question-settings/reset-defaults', [QuestionTypeSettingController::class, 'resetDefaults']);

        Route::get('/admin/curriculum-units', [CurriculumUnitsController::class, 'index']);
        Route::get('/admin/curriculum-units/{subjectUnit}', [CurriculumUnitsController::class, 'show']);
        Route::post('/admin/curriculum-units', [CurriculumUnitsController::class, 'store']);
        Route::put('/admin/curriculum-units/{subjectUnit}', [CurriculumUnitsController::class, 'update']);

        Route::get('/posts', [PostController::class, 'index']);
        Route::get('/posts/{post}', [PostController::class, 'show']);
        Route::post('/posts', [PostController::class, 'store'])->middleware('throttle:post-create');
        Route::put('/posts/{post}', [PostController::class, 'update']);
        Route::delete('/posts/{post}', [PostController::class, 'destroy']);
        Route::post('/posts/{post}/vote', [PostController::class, 'vote']);
        Route::post('/posts/{post}/react', [PostController::class, 'react']);
        Route::delete('/posts/{post}/react', [PostController::class, 'removeReaction']);

        Route::post('/storage/presign', [\App\Http\Controllers\Api\StorageController::class, 'presign']);
        Route::post('/storage/private-file-url', [\App\Http\Controllers\Api\StorageController::class, 'getPrivateFileUrl']);

        // Comments System Routes
        Route::post('/comments/block', [CommentController::class, 'block'])->middleware('throttle:moderation');
        Route::get('/posts/{post}/comments', [CommentController::class, 'index']);
        Route::post('/posts/{post}/comments', [CommentController::class, 'store']);
        Route::delete('/posts/{post}/comments/{comment}', [CommentController::class, 'destroy']);

        // Live Duel Engine Core Routes & Aliases
        Route::get('/live-duel-challenges', [LiveDuelChallengeController::class, 'index']);
        Route::get('/live-duel/eligible-peers', [LiveDuelChallengeController::class, 'getEligiblePeers']);
        Route::get('/live-duel/room-status/{roomId}', [LiveDuelChallengeController::class, 'getRoomStatus']);
        Route::post('/live-duel/create-room', [LiveDuelChallengeController::class, 'createRoom']);
        Route::post('/live-duel/rooms/{roomId}/answers', [LiveDuelChallengeController::class, 'submitAnswer']);
        
        // Strict Join Routes with Aliases for Maximum Compatibility
        Route::post('/live-duel/join-room', [LiveDuelChallengeController::class, 'joinRoom']);
        Route::post('/live-duel/join', [LiveDuelChallengeController::class, 'joinRoom']);
        Route::post('/live-duel/accept-challenge', [LiveDuelChallengeController::class, 'joinRoom']);

        Route::get('/cloud-capsule-challenges', [CloudCapsuleChallengeController::class, 'index']);
        Route::get('/comparison-challenges/list', [ComparisonChallengeController::class, 'index']);
        Route::get('/find-the-bug-challenges/list', [FindTheBugChallengeController::class, 'index']);
        Route::get('/multiple-choice-questions/list', [MultipleChoiceQuestionController::class, 'index']);
        Route::get('/true-false-questions/list', [TrueFalseQuestionController::class, 'index']);
        Route::post('/live-duel-challenges', [LiveDuelChallengeController::class, 'store']);
        Route::post('/cloud-capsule-challenges', [CloudCapsuleChallengeController::class, 'store']);
        Route::post('/comparison-challenges', [ComparisonChallengeController::class, 'store']);
        Route::post('/daily-challenges', [DailyChallengeController::class, 'store']);
        Route::post('/find-the-bug-challenges', [FindTheBugChallengeController::class, 'store']);
        Route::post('/multiple-choice-questions', [MultipleChoiceQuestionController::class, 'store']);
        Route::post('/true-false-questions', [TrueFalseQuestionController::class, 'store']);
        Route::get('/interactive-videos', [InteractiveVideoController::class, 'index']);
        Route::post('/interactive-videos', [InteractiveVideoController::class, 'store']);
        Route::post('/youtube/videos/upload', [YouTubeVideoController::class, 'uploadVideo']);
    });

    Route::post('/wallet/webhook', [WalletController::class, 'webhook']);
};

Route::middleware('api')->group($apiRoutes);
Route::middleware('api')->prefix('v1')->group($apiRoutes);