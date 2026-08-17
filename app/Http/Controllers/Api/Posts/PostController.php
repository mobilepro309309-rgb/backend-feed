<?php

namespace App\Http\Controllers\Api\Posts;

use App\Http\Controllers\Controller;
use App\Models\PostVote;
use App\Models\Posts\Post;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PostController extends Controller
{
    private function rejectVideoUploadForRegularUsers(Request $request, array $attachments = []): ?\Illuminate\Http\JsonResponse
    {
        $user = $request->user();

        if (! $user || mb_strtolower(trim((string) ($user->role ?? ''))) !== 'user') {
            return null;
        }

        $attachmentList = is_array($attachments) ? $attachments : [];

        foreach ($attachmentList as $attachment) {
            if (! is_array($attachment)) {
                continue;
            }

            $mimeType = (string) ($attachment['mimeType'] ?? $attachment['mime_type'] ?? '');
            $name = (string) ($attachment['name'] ?? '');
            $uri = (string) ($attachment['uri'] ?? '');

            if ($this->looksLikeVideoMedia($mimeType) || $this->looksLikeVideoMedia($name) || $this->looksLikeVideoMedia($uri)) {
                return response()->json([
                    'message' => 'رفع الفيديوهات متاح فقط للمعلمين والمشرفين',
                ], 403);
            }
        }

        return null;
    }

    private function looksLikeVideoMedia(string $value): bool
    {
        $normalized = strtolower(trim($value));

        if ($normalized === '') {
            return false;
        }

        if (str_contains($normalized, 'video/')) {
            return true;
        }

        foreach (['mp4', 'mov', 'avi', 'mkv', 'webm', 'm4v', '3gp', 'mpeg', 'mpg', 'wmv'] as $extension) {
            if (str_ends_with($normalized, '.' . $extension) || str_contains($normalized, '.' . $extension) || str_contains($normalized, $extension)) {
                return true;
            }
        }

        return false;
    }

    public function index(Request $request)
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'المستخدم غير مصادق عليه',
            ], 401);
        }

        $unitNumber = $request->query('unit_number', $request->input('unit_number', 'all'));
        $resolvedUnitNumber = $unitNumber === null || $unitNumber === '' || strtolower(trim((string) $unitNumber)) === 'all'
            ? null
            : ((int) $unitNumber > 0 ? (int) $unitNumber : null);

        $posts = Post::query()
            ->where('status', 'published')
            ->when($resolvedUnitNumber !== null, function ($query) use ($resolvedUnitNumber) {
                $query->where('unit_number', $resolvedUnitNumber);
            })
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
            'content' => ['nullable', 'string', 'required_without:attachments'],
            'subject' => ['required', 'string', 'max:120'],
            'unit_number' => ['nullable', 'integer', 'min:1', 'max:50'],
            'image_url' => ['nullable', 'string'],
            'attachments' => ['nullable', 'array', 'required_without:content'],
            'attachments.*.id' => ['nullable', 'string'],
            'attachments.*.name' => ['nullable', 'string'],
            'attachments.*.uri' => ['nullable', 'string'],
            'attachments.*.mimeType' => ['nullable', 'string'],
            'attachments.*.size' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:draft,published'],
        ]);

        $videoRestrictionResponse = $this->rejectVideoUploadForRegularUsers($request, $validated['attachments'] ?? []);
        if ($videoRestrictionResponse) {
            return $videoRestrictionResponse;
        }

        $post = Post::create([
            'user_id' => $user->id,
            'content' => $validated['content'],
            'subject' => $validated['subject'],
            'unit_number' => isset($validated['unit_number']) && $validated['unit_number'] !== '' ? (int) $validated['unit_number'] : null,
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

    public function update(Request $request, Post $post)
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'المستخدم غير مصادق عليه',
            ], 401);
        }

        $normalizedRole = mb_strtolower(trim((string) ($user->role ?? '')));
        $canUpdate = $post->user_id === $user->id
            || in_array($normalizedRole, ['admin', 'teacher', 'super-admin', 'moderator'], true);

        if (! $canUpdate) {
            return response()->json([
                'message' => 'ليس لديك صلاحية تعديل هذا المنشور',
            ], 403);
        }

        $validated = $request->validate([
            'content' => ['nullable', 'string'],
            'subject' => ['nullable', 'string', 'max:120'],
            'unit_number' => ['nullable', 'integer', 'min:1', 'max:50'],
            'image_url' => ['nullable', 'string'],
            'attachments' => ['nullable', 'array'],
            'status' => ['nullable', 'in:draft,published'],
        ]);

        $post->update([
            'content' => $validated['content'] ?? $post->content,
            'subject' => $validated['subject'] ?? $post->subject,
            'unit_number' => array_key_exists('unit_number', $validated) && $validated['unit_number'] !== ''
                ? (int) $validated['unit_number']
                : $post->unit_number,
            'image_url' => $validated['image_url'] ?? $post->image_url,
            'attachments' => $validated['attachments'] ?? $post->attachments,
            'status' => $validated['status'] ?? $post->status,
        ]);

        return response()->json([
            'message' => 'تم تعديل المنشور بنجاح',
            'data' => $post->fresh()->load('user'),
        ]);
    }

    public function destroy(Request $request, Post $post)
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'المستخدم غير مصادق عليه',
            ], 401);
        }

        $normalizedRole = mb_strtolower(trim((string) ($user->role ?? '')));
        $canDelete = $post->user_id === $user->id
            || in_array($normalizedRole, ['admin', 'teacher', 'super-admin', 'moderator'], true);

        if (! $canDelete) {
            return response()->json([
                'message' => 'ليس لديك صلاحية حذف هذا المنشور',
            ], 403);
        }

        $post->delete();

        return response()->json([
            'message' => 'تم حذف المنشور بنجاح',
            'data' => ['id' => $post->id],
        ]);
    }

    public function vote(Request $request, Post $post)
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'المستخدم غير مصادق عليه',
            ], 401);
        }

        $validated = $request->validate([
            'type' => ['required', 'in:up,down,1,-1'],
        ]);

        $voteType = $validated['type'];
        $normalizedVote = in_array($voteType, ['up', 1, '1'], true) ? 1 : -1;
        $userVoteLabel = $normalizedVote === 1 ? 'up' : 'down';

        $payload = DB::transaction(function () use ($post, $user, $normalizedVote, $userVoteLabel) {
            $existingVote = PostVote::where('post_id', $post->id)
                ->where('user_id', $user->id)
                ->first();

            $voteRemoved = false;
            $previousVoteLabel = null;

            if ($existingVote) {
                $previousVoteLabel = $existingVote->vote_type === 1 ? 'up' : 'down';

                if ($existingVote->vote_type === $normalizedVote) {
                    $existingVote->delete();
                    $voteRemoved = true;
                    $post->decrement($normalizedVote === 1 ? 'upvotes_count' : 'downvotes_count');
                    $post->decrement('votes_score', $normalizedVote);
                } else {
                    $existingVote->update(['vote_type' => $normalizedVote]);
                    if ($normalizedVote === 1) {
                        $post->decrement('downvotes_count');
                        $post->increment('upvotes_count');
                    } else {
                        $post->decrement('upvotes_count');
                        $post->increment('downvotes_count');
                    }
                    $post->increment('votes_score', $normalizedVote * 2);
                }
            } else {
                PostVote::create([
                    'post_id' => $post->id,
                    'user_id' => $user->id,
                    'vote_type' => $normalizedVote,
                ]);

                if ($normalizedVote === 1) {
                    $post->increment('upvotes_count');
                } else {
                    $post->increment('downvotes_count');
                }
                $post->increment('votes_score', $normalizedVote);
            }

            $post->refresh();

            return [
                'post_id' => $post->id,
                'user_vote' => $voteRemoved ? null : $userVoteLabel,
                'votes_score' => $post->votes_score,
                'upvotes_count' => $post->upvotes_count,
                'downvotes_count' => $post->downvotes_count,
            ];
        });

        return response()->json([
            'success' => true,
            'message' => 'Vote updated successfully',
            'data' => $payload,
        ]);
    }

    public function react(Request $request, Post $post)
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
