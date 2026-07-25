<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use App\Models\Posts\Post;

class SavedPostsController extends Controller
{
    /**
     * Toggle saving/unsaving a post for the authenticated (or provided) user.
     */
    public function toggleSavePost(Request $request, $postId)
    {
        $user = $request->user();

        if (!$user) {
            $userId = $request->input('user_id');
            if (!$userId) {
                return response()->json(['status' => 'error', 'message' => 'Unauthenticated or missing user_id'], 401);
            }
            $user = User::find($userId);
            if (!$user) {
                return response()->json(['status' => 'error', 'message' => 'User not found'], 404);
            }
        }

        $post = Post::findOrFail($postId);

        $already = $user->savedPosts()->where('post_id', $postId)->exists();

        if ($already) {
            $user->savedPosts()->detach($postId);
            $action = 'unsaved';
        } else {
            $user->savedPosts()->attach($postId);
            $action = 'saved';
        }

        return response()->json(['status' => 'success', 'action' => $action]);
    }

    /**
     * Get saved posts for a specific user, newest saved first, paginated.
     */
    public function getSavedPosts($userId)
    {
        $user = User::findOrFail($userId);

        $saved = $user->savedPosts()
            ->with('user')
            ->latest('saved_posts.created_at')
            ->paginate(10);

        return response()->json(['status' => 'success', 'posts' => $saved]);
    }
}
