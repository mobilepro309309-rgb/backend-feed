<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Posts\Post;

class LatestQuestionsController extends Controller
{
    /**
     * Return latest posts for a given user.
     *
     * @param  int  $userId
     * @return \Illuminate\Http\JsonResponse
     */
    public function getUserLatestPosts($userId)
    {
        $user = User::findOrFail($userId);

        $posts = Post::where('user_id', $userId)
            ->where('status', 'published')
            ->with('user:id,name')
            ->latest('created_at')
            ->take(10)
            ->get();

        return response()->json([
            'status' => 'success',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'avatar' => null,
            ],
            'posts' => $posts,
            'questions' => $posts,
            'pagination' => [
                'total' => $posts->count(),
            ],
        ]);
    }

    /**
     * Keep the old method name for backward compatibility.
     */
    public function getUserLatestQuestions($userId)
    {
        return $this->getUserLatestPosts($userId);
    }

    /**
     * Alias method requested: getUserQuestions
     * Kept for API compatibility with the front-end spec.
     */
    public function getUserQuestions($userId)
    {
        return $this->getUserLatestPosts($userId);
    }
}
