<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subject_units', function (Blueprint $table): void {
            $table->unsignedBigInteger('subject_id')->nullable()->after('id');
        });

        DB::table('subject_units')
            ->join('subjects', function (JoinClause $join): void {
                $join->on('subjects.code', '=', 'subject_units.subject')
                    ->orOn('subjects.name_ar', '=', 'subject_units.subject')
                    ->orOn('subjects.name_en', '=', 'subject_units.subject');
            })
            ->select('subject_units.id', 'subjects.id as subject_id')
            ->orderBy('subject_units.id')
            ->get()
            ->each(function (object $row): void {
                DB::table('subject_units')
                    ->where('id', $row->id)
                    ->update(['subject_id' => $row->subject_id]);
            });

        Schema::table('subject_units', function (Blueprint $table): void {
            $table->foreign('subject_id')->references('id')->on('subjects')->nullOnDelete();
            $table->unique('subject_id');
            $table->dropUnique(['school_grade', 'subject']);
            $table->dropIndex(['school_grade', 'subject']);
            $table->dropColumn(['school_grade', 'subject']);
        });
    }

    public function down(): void
    {
        Schema::table('subject_units', function (Blueprint $table): void {
            $table->string('school_grade')->nullable()->after('id');
            $table->string('subject')->nullable()->after('school_grade');
        });

        DB::table('subject_units')
            ->leftJoin('subjects', 'subjects.id', '=', 'subject_units.subject_id')
            ->update([
                'subject_units.school_grade' => DB::raw("COALESCE(subjects.grade_id, '')"),
                'subject_units.subject' => DB::raw("COALESCE(subjects.code, '')"),
            ]);

        Schema::table('subject_units', function (Blueprint $table): void {
            $table->dropUnique(['subject_id']);
            $table->dropForeign(['subject_id']);
            $table->dropColumn('subject_id');
            $table->unique(['school_grade', 'subject']);
            $table->index(['school_grade', 'subject']);
        });
    }
};
