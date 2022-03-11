<?php


namespace App\Console;

use App\Libs\CommonLib;
use App\Models\Assignment;
use App\Models\AssignmentDetail;
use App\Models\QuizQuestionAnswer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mockery\Exception;

class CalculatorAssignmentGrade extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'calculator:assignment_grade';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Tinh diem hoc sinh';

    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $data = $this->getLockDataProcess(5);
        foreach ($data as $assignment) {
            var_dump("Tinh toan: " . $assignment->_id);
            $assignment_details = $assignment->assignment_detail;
            $score_assignment = $assignment->score;
            if (count($assignment_details) <= 0)
                continue;

            $session = DB::connection('mongodb')->getMongoClient()->startSession();
            $session->startTransaction();
            try {
                foreach ($assignment_details as $assignment_detail_item) {
                    $quiz_question = $assignment_detail_item->quiz_question;
                    if (empty($quiz_question))
                        continue;

                    if ($quiz_question->type == 2)
                        continue;

                    $type_quiz_question = $quiz_question->type; //0:Chọn 1 đáp án đúng,1:Chọn nhiều đáp án đúng, 2:Câu tự luận

                    $quiz_question_answer_true = QuizQuestionAnswer::where('quiz_question_id', $quiz_question->id)->where('is_true', 1)->get()->pluck('id')->toArray();

                    $score_item = null;
                    if ($type_quiz_question == 0) {
                        if (in_array($assignment_detail_item->answer, $quiz_question_answer_true))
                            $score_item = (!empty($quiz_question_answer_true->percent_score)) ? round(($quiz_question_answer_true->percent_score * $quiz_question->score) / 100, 2) : $quiz_question->score;
                        else
                            $score_item = 0;

                    } elseif ($type_quiz_question == 1) {
                        $array_compare = array_diff($assignment_detail_item->answer, $quiz_question_answer_true);
                        if (empty($array_compare))
                            $score_item = (!empty($quiz_question_answer_true->percent_score)) ? round(($quiz_question_answer_true->percent_score * $quiz_question->score) / 100, 2) : $quiz_question->score;
                        else
                            $score_item = 0;
                    }

                    if (isset($score_item)) {
                        //Cap nhat diem cho hoc sinh
                        $score_assignment = $score_assignment + $score_item;
                        AssignmentDetail::where('_id', $assignment_detail_item->id)->update(['score' => $score_item, 'status' => 2]);

                        Assignment::where('_id', $assignment_detail_item->assignment_id)->update(['score' => $score_assignment]);
                    }
                }
                $status_assignment = 4;
                $assignment_detail_done = AssignmentDetail::where('assignment_id', $assignment->id)->where('status', 2)->count();
                if (count($assignment_details) == $assignment_detail_done)
                    $status_assignment = 2;

                var_dump("Tinh xong " . $assignment->_id);

                $this->updateStatusProcess([$assignment->_id], $status_assignment);

                $session->commitTransaction();
            } catch (\Exception $exception) {
                $session->abortTransaction();
                $this->updateStatusProcess([$assignment->_id], 5);
                Log::info($exception->getMessage());
            }
        }
    }

    /**
     * @param int $number_record
     * @return array
     */
    private function getLockDataProcess($number_record = 10)
    {
        $data = array();
        $redis_client = CommonLib::getRedisClientInstall();
        $redis_process_key = "get_data_assignment_not_grade";

        try {
            if ($redis_client->LockTake($redis_process_key, $redis_process_key, 10) == 1) {
                try {
                    $data = Assignment::where('status', 1)->with('assignment_detail.quiz_question')->take($number_record)->get();
                    if (!empty($data) && count($data) > 0) {
                        $this->updateStatusProcess($data->pluck('id')->toArray(), 3);
                    }
                } catch (Exception $ex) {
                    if (!empty($data) && count($data) > 0) {
                        $this->updateStatusProcess($data->pluck('id')->toArray(), 4);
                        unset($data);
                    }
                }
                $redis_client->LockRelease($redis_process_key, $redis_process_key);
            }
        } catch (Exception $ex) {
            if (!empty($data) && count($data) > 0) {
                $this->updateStatusProcess($data->pluck('id')->toArray(), 3);
                unset($data);
            }
        }
        return $data;
    }

    private function updateStatusProcess($ids, $status)
    {
        Assignment::whereIn('_id', $ids)->update(['status' => $status]);
    }
}
