<?php

namespace App\Http\Controllers\Api\Posts;

use App\Events\NewCommentEvent;
use App\Http\Controllers\Controller;
use App\Models\Comments\Comment;
use App\Models\Posts\Post;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\Request;

class CommentController extends Controller
{
    private function rejectVideoUploadForRegularUsers(Request $request, array $validated = []): ?\Illuminate\Http\JsonResponse
    {
        $user = $request->user();

        if (! $user || mb_strtolower(trim((string) ($user->role ?? ''))) !== 'user') {
            return null;
        }

        $candidates = [
            $validated['file_url'] ?? null,
            $validated['type'] ?? null,
            $validated['metadata']['type'] ?? null,
            $validated['metadata']['mime_type'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if ($this->looksLikeVideoMedia((string) ($candidate ?? ''))) {
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

    /**
     * Fetch all top-level comments for a specific post
     * GET /api/posts/{post}/comments
     */
    public function index(Post $post)
    {
        $user = request()->user();

        if (!$user) {
            return response()->json([
                'message' => 'المستخدم غير مصادق عليه',
            ], 401);
        }
        
        // Fetch top-level comments with eager-loaded user and replies
        $comments = $post->comments()
            ->with([
                'user' => function ($query) {
                    $query->select('id', 'name', 'role');
                },
                'replies' => function ($query) {
                    $query->with([
                        'user' => function ($userQuery) {
                            $userQuery->select('id', 'name', 'role');
                        },
                    ])->orderByDesc('created_at');
                },
                'reactions' => function ($query) {
                    $query->select('id', 'comment_id', 'user_id', 'type');
                },
                'attachments' => function ($query) {
                    $query->select('id', 'comment_id', 'name', 'uri', 'mime_type', 'size', 'type', 'note');
                },
            ])
            ->get();

        // Transform comments to include metadata and reaction counts
        $transformedComments = $comments->map(function (Comment $comment) {
            return $this->formatCommentForResponse($comment);
        });

        return response()->json([
            'data' => $transformedComments,
            'total' => $comments->count(),
        ]);
    }

    /**
     * Create a new comment or reply
     * POST /api/posts/{post}/comments
     */
    public function store(Request $request, Post $post)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'message' => 'المستخدم غير مصادق عليه',
            ], 401);
        }

        $validated = $request->validate([
            'content' => ['nullable', 'string', 'max:5000'],
            'parent_id' => ['nullable', 'exists:post_comments,id'],
            'type' => ['nullable', 'in:text,image,voice,gif,video,file'],
            'metadata' => ['nullable', 'array'],
            'file_url' => ['nullable', 'url'],
        ]);

        $videoRestrictionResponse = $this->rejectVideoUploadForRegularUsers($request, $validated);
        if ($videoRestrictionResponse) {
            return $videoRestrictionResponse;
        }

        // Ensure at least one of content or file_url is present
        $hasContent = isset($validated['content']) && trim((string)$validated['content']) !== '';
        $hasFileUrl = isset($validated['file_url']) && trim((string)$validated['file_url']) !== '';
        if (!$hasContent && !$hasFileUrl) {
            return response()->json([
                'message' => 'يجب توفير نص التعليق أو مرفق (content أو file_url).',
            ], 422);
        }

        $post->loadMissing('user');
        $authorGender = $this->normalizeGender($post->user?->gender);
        $userGender = $this->normalizeGender($user->gender);

        if (!$user->isAdmin() && (!$authorGender || !$userGender || $userGender !== $authorGender)) {
            return response()->json([
                'message' => 'غير مسموح بالتعليق على هذا المنشور',
            ], 403);
        }

        \Log::info('[CommentController] Storing comment:', [
            'postId' => $post->id,
            'userId' => $user->id,
            'parentId' => $validated['parent_id'] ?? null,
            'contentLength' => strlen($validated['content']),
            'fullPayload' => $validated,
        ]);

        // If parent_id is provided, verify it belongs to the same post
        if ($validated['parent_id'] ?? null) {
            $parentComment = Comment::find($validated['parent_id']);
            if (!$parentComment) {
                return response()->json([
                    'message' => 'التعليق الأب المحدد غير موجود',
                ], 422);
            }
            if ($parentComment->post_id !== $post->id) {
                return response()->json([
                    'message' => 'الرد المحدد لا ينتمي للمنشور ذاته',
                ], 422);
            }
        }

        // Create the comment
        $comment = $post->allComments()->create([
            'user_id' => $user->id,
            'parent_id' => $validated['parent_id'] ?? null,
            // store empty string if no content provided to satisfy DB non-null constraint
            'content' => $validated['content'] ?? '',
            'type' => $validated['type'] ?? ($hasFileUrl ? 'image' : 'text'),
            'metadata' => $validated['metadata'] ?? null,
            'file_url' => $validated['file_url'] ?? null,
        ]);

        \Log::info('[CommentController] Comment created:', [
            'commentId' => $comment->id,
            'parentId' => $comment->parent_id,
        ]);

        // Reload with relationships
        $comment->load([
            'user' => function ($query) {
                $query->select('id', 'name', 'role');
            },
            'replies' => function ($query) {
                $query->with([
                    'user' => function ($userQuery) {
                        $userQuery->select('id', 'name', 'role');
                    },
                ])->orderByDesc('created_at');
            },
            'reactions' => function ($query) {
                $query->select('id', 'comment_id', 'user_id', 'type');
            },
            'attachments' => function ($query) {
                $query->select('id', 'comment_id', 'name', 'uri', 'mime_type', 'size', 'type', 'note');
            },
        ]);

        $formattedComment = $this->formatCommentForResponse($comment);

        // Dispatch broadcast event for realtime updates
        broadcast(new NewCommentEvent((int) $post->id, $formattedComment))->toOthers();

        // Send push notification to post owner when commenter is not the owner
        try {
            $postOwnerId = $post->user_id ?? null;
            if ($postOwnerId && (int) $postOwnerId !== (int) $user->id) {
                $recipient = User::find($postOwnerId);
                if ($recipient) {
                    $notificationService = app(NotificationService::class);

                    $title = 'تعليق جديد على المنشور';
                    $snippet = trim((string) ($comment->content ?? ''));
                    if ($snippet === '' && $comment->file_url) {
                        $snippet = 'أرفق ملفًا مرفقًا';
                    }
                    $body = $user->name ? ("{$user->name} علق على منشورك" . ($snippet ? ": {$snippet}" : '')) : 'لديك تعليق جديد على منشورك';

                    $data = [
                        'type' => 'new_comment',
                        'target_type' => 'post',
                        'action_type' => 'comments',
                        'target_id' => $post->id,
                        'post_id' => $post->id,
                        'sender_id' => $user->id,
                        'sender_name' => $user->name ?? null,
                        'sender_avatar' => $user->avatar ?? $user->avatar_url ?? null,
                        'comment_id' => $comment->id,
                    ];

                    \Log::info('COMMENT NOTIFICATION ATTEMPT:', [
                        'recipient_id' => $recipient->id,
                        'title' => $title,
                        'body' => $body,
                        'data' => $data,
                    ]);

                    $notificationService->sendNotification($recipient, $title, $body, $data);
                }
            }
        } catch (\Throwable $e) {
            \Log::error('COMMENT NOTIFICATION FAILED:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'post_id' => $post->id,
                'comment_id' => $comment->id,
                'recipient_id' => $postOwnerId ?? null,
            ]);
        }

        return response()->json([
            'message' => 'تم إنشاء التعليق بنجاح',
            'data' => $formattedComment,
        ], 201);
    }

    private function normalizeGender(?string $gender): ?string
    {
        if ($gender === null || $gender === '') {
            return null;
        }

        $normalized = strtolower(trim((string) $gender));

        if (in_array($normalized, ['male', 'ذكر', 'ولد', 'boy', 'man', 'm'], true)) {
            return 'male';
        }

        if (in_array($normalized, ['female', 'انثى', 'بنت', 'girl', 'woman', 'f'], true)) {
            return 'female';
        }

        return $normalized;
    }

    /**
     * Format comment data for API response
     */
    private function formatCommentForResponse(Comment $comment): array
    {
        return [
            'id' => $comment->id,
            'post_id' => $comment->post_id,
            'user_id' => $comment->user_id,
            'parent_id' => $comment->parent_id,
            'content' => $comment->content,
            'type' => $comment->type,
            'metadata' => $comment->metadata,
            'file_url' => $comment->file_url,
            'created_at' => $comment->created_at,
            'updated_at' => $comment->updated_at,
            'user' => $comment->user ? [
                'id' => $comment->user->id,
                'name' => $comment->user->name,
                'role' => $comment->user->role,
            ] : null,
            'replies' => $comment->replies ? $comment->replies->map(fn ($reply) => $this->formatCommentForResponse($reply))->toArray() : [],
            'replies_count' => $comment->replies()->count(),
            'reactions_count' => $comment->reactions()->count(),
            'reactions' => $comment->reactions->map(fn ($reaction) => [
                'id' => $reaction->id,
                'type' => $reaction->type,
                'user_id' => $reaction->user_id,
            ])->toArray(),
            'attachments' => $comment->attachments->map(fn ($attachment) => [
                'id' => $attachment->id,
                'name' => $attachment->name,
                'uri' => $attachment->uri,
                'mime_type' => $attachment->mime_type,
                'size' => $attachment->size,
                'type' => $attachment->type,
                'note' => $attachment->note,
            ])->toArray(),
        ];
    }
}
