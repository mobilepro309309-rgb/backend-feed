<?php

namespace App\Http\Controllers\Api\Posts;

use App\Http\Controllers\Controller;
use App\Models\Posts\Post;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PostController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'المستخدم غير مصادق عليه',
            ], 401);
        }

        $posts = Post::query()
            ->where('status', 'published')
            // eager-load user so frontend receives the author inside each post
            ->with(['user' => function ($query) {
                $query->select('id', 'name');
            }])
            ->orderByDesc('id')
            ->cursorPaginate(10);

        $items = $posts->getCollection()->map(function (Post $post) use ($user) {
            // Calculate likes dynamically from post_reactions table
            $likesCount = DB::table('post_reactions')
                ->where('post_id', $post->id)
                ->count();

            // Get the authenticated user's reaction type (if any)
            $userReaction = null;
            if ($user) {
                $userReaction = DB::table('post_reactions')
                    ->where('post_id', $post->id)
                    ->where('user_id', $user->id)
                    ->value('type');
            }

            return [
                'id' => $post->id,
                'content' => $post->content,
                'subject' => $post->subject,
                'image_url' => $post->image_url,
                'attachments' => $post->attachments,
                'status' => $post->status,
                'published_at' => $post->published_at,
                'likes' => $likesCount,
                'comments' => 0,
                'shares' => 0,
                'user_reaction' => $userReaction,
                'created_at' => $post->created_at,
                'updated_at' => $post->updated_at,
                'user' => $post->user ? [
                    'id' => $post->user->id,
                    'name' => $post->user->name,
                ] : null,
            ];
        });

        return response()->json([
            'data' => $items,
            'meta' => [
                'next_cursor' => $posts->nextCursor()?->encode(),
                'prev_cursor' => $posts->previousCursor()?->encode(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'المستخدم غير مصادق عليه',
            ], 401);
        }

        $validated = $request->validate([
            'content' => ['required_without:attachments', 'string'],
            'subject' => ['required', 'string', 'max:120'],
            'image_url' => ['nullable', 'string'],
            'attachments' => ['nullable', 'array'],
            'attachments.*.id' => ['nullable', 'string'],
            'attachments.*.name' => ['nullable', 'string'],
            'attachments.*.uri' => ['nullable', 'string'],
            'attachments.*.mimeType' => ['nullable', 'string'],
            'attachments.*.size' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:draft,published'],
        ]);

        $post = Post::create([
            'user_id' => $user->id,
            'content' => $validated['content'],
            'subject' => $validated['subject'],
            'image_url' => $validated['image_url'] ?? null,
            'attachments' => $validated['attachments'] ?? null,
            'status' => $validated['status'] ?? 'published',
            'published_at' => ($validated['status'] ?? 'published') === 'published' ? now() : null,
        ]);

        return response()->json([
            'message' => 'تم إنشاء المنشور بنجاح',
            'data' => $post->fresh()->load('user'),
        ], 201);
    }

    public function react(Request $request, Post $post)
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'المستخدم غير مصادق عليه',
            ], 401);
        }

        $validated = $request->validate([
            'type' => 'required|in:like,love,haha,wow,sad,angry',
        ]);

        $type = $validated['type'];

        $payload = DB::transaction(function () use ($post, $user, $type) {
            $existingReaction = DB::table('post_reactions')
                ->where('post_id', $post->id)
                ->where('user_id', $user->id)
                ->first();

            if ($existingReaction) {
                if ($existingReaction->type === $type) {
                    DB::table('post_reactions')
                        ->where('post_id', $post->id)
                        ->where('user_id', $user->id)
                        ->delete();

                    $newLikesCount = DB::table('post_reactions')
                        ->where('post_id', $post->id)
                        ->count();

                    return [
                        'type' => null,
                        'likes_count' => $newLikesCount,
                    ];
                }

                DB::table('post_reactions')
                    ->where('post_id', $post->id)
                    ->where('user_id', $user->id)
                    ->update([
                        'type' => $type,
                        'updated_at' => now(),
                    ]);
            } else {
                DB::table('post_reactions')->insert([
                    'post_id' => $post->id,
                    'user_id' => $user->id,
                    'type' => $type,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $newLikesCount = DB::table('post_reactions')
                ->where('post_id', $post->id)
                ->count();

            return [
                'type' => $type,
                'likes_count' => $newLikesCount,
            ];
        });

        return response()->json([
            'message' => 'تم تحديث تفاعل المنشور',
            'type' => $payload['type'],
            'likes_count' => $payload['likes_count'],
        ]);
    }

    public function removeReaction(Request $request, Post $post)
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'المستخدم غير مصادق عليه',
            ], 401);
        }

        $payload = DB::transaction(function () use ($post, $user) {
            DB::table('post_reactions')
                ->where('post_id', $post->id)
                ->where('user_id', $user->id)
                ->delete();

            $newLikesCount = DB::table('post_reactions')
                ->where('post_id', $post->id)
                ->count();

            return [
                'likes_count' => $newLikesCount,
            ];
        });

        return response()->json([
            'message' => 'تم إزالة تفاعل المنشور',
            'likes_count' => $payload['likes_count'],
        ]);
    }

    public function show(Request $request, Post $post)
    {
        $user = $request->user();
        $post->load(['user' => function ($query) {
            $query->select('id', 'name');
        }]);

        $likesCount = DB::table('post_reactions')
            ->where('post_id', $post->id)
            ->count();

        $userReaction = null;
        if ($user) {
            $userReaction = DB::table('post_reactions')
                ->where('post_id', $post->id)
                ->where('user_id', $user->id)
                ->value('type');
        }

        return response()->json([
            'data' => [
                'id' => $post->id,
                'content' => $post->content,
                'subject' => $post->subject,
                'image_url' => $post->image_url,
                'attachments' => $post->attachments,
                'status' => $post->status,
                'published_at' => $post->published_at,
                'likes' => $likesCount,
                'comments' => $post->comments,
                'shares' => $post->shares,
                'user_reaction' => $userReaction,
                'created_at' => $post->created_at,
                'updated_at' => $post->updated_at,
                'user' => $post->user ? [
                    'id' => $post->user->id,
                    'name' => $post->user->name,
                ] : null,
            ],
        ]);
    }
}
