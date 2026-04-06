<?php

namespace App\Services;

class RatingService
{
    public $education;
    public $experience;
    public $training;
    public $performance;
    public $bei;
    public $exam;

    public function __construct(
        $education   = 0,
        $experience  = 0,
        $training    = 0,
        $performance = 0,
        $bei         = null,
        $exam        = null
    ) {
        $this->education   = $education;
        $this->experience  = $experience;
        $this->training    = $training;
        $this->performance = $performance;
        $this->bei         = $bei;
        $this->exam        = $exam;
    }

    /**
     * Average only non-null values from a specific key in the scores array.
     * Same logic used by BEI — 0 is valid, null is skipped.
     */
    private static function averageNullable(array $scores, string $key): float
    {
        $values = [];

        foreach ($scores as $s) {
            if (!is_null($s[$key] ?? null)) {
                $values[] = (float)$s[$key];
            }
        }

        return count($values) > 0
            ? array_sum($values) / count($values)
            : 0.0;
    }

    /**
     * Compute the final score for an applicant.
     *
     * Rules:
     * - education, experience, training, performance → always averaged across all raters
     * - bei  → optional, null = not rated (skipped in average), same as before
     * - exam → optional, null = not taken (skipped), averaged like education
     * - grand_total = total_qs + bei + exam
     *
     * @param array $scores Array of rating arrays.
     * @return array
     */
    public static function computeFinalScore(array $scores): array
    {
        $count = count($scores);

        if ($count === 0) {
            return [
                'education'   => "0.00",
                'experience'  => "0.00",
                'training'    => "0.00",
                'performance' => "0.00",
                'bei'         => "0.00",
                'exam'        => "0.00",
                'total_qs'    => "0.00",
                'grand_total' => "0.00",
            ];
        }

        // Always averaged across all raters (same as before)
        $education   = array_sum(array_column($scores, 'education'))   / $count;
        $experience  = array_sum(array_column($scores, 'experience'))  / $count;
        $training    = array_sum(array_column($scores, 'training'))    / $count;
        $performance = array_sum(array_column($scores, 'performance')) / $count;

        // BEI — null is skipped (same existing logic)
        $bei  = self::averageNullable($scores, 'bei');

        // Exam — null is skipped (same logic as BEI)
        $exam = self::averageNullable($scores, 'exam');

        $total_qs    = $education + $experience + $training + $performance;
        $grand_total = $total_qs + $bei + $exam;

        return [
            'education'   => number_format($education,   2, '.', ''),
            'experience'  => number_format($experience,  2, '.', ''),
            'training'    => number_format($training,    2, '.', ''),
            'performance' => number_format($performance, 2, '.', ''),
            'bei'         => number_format($bei,         2, '.', ''),
            'exam'        => number_format($exam,        2, '.', ''),
            'total_qs'    => number_format($total_qs,    2, '.', ''),
            'grand_total' => number_format($grand_total, 2, '.', ''),
        ];
    }

    public static function addRanking(array $applicants): array
    {
        uasort($applicants, function ($a, $b) {
            return (float)$b['grand_total'] <=> (float)$a['grand_total'];
        });

        $rank          = 0;
        $previousScore = null;

        foreach ($applicants as $id => &$data) {
            if ($previousScore !== null && (float)$data['grand_total'] === (float)$previousScore) {
                // Same score — same rank
                $data['rank'] = $rank;
            } else {
                // New score — just increment by 1
                $rank++;
                $data['rank'] = $rank;
            }
            $previousScore = (float)$data['grand_total'];
        }

        return $applicants;
    }
}
