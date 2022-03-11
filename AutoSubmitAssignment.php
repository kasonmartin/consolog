<?php


namespace App\Console;

use App\Libs\CommonLib;
use App\Models\Assignment;
use App\Models\AssignmentDetail;
use App\Models\AssignmentDraf;
use App\Models\QuizQuestionAnswer;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mockery\Exception;

class AutoSubmitAssignment extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'auto_submit_assignment';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Tu dong nop bai cho hoc vien';


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
        $assignment_list = Assignment::where('status', 0)->with('quiz.quiz_question')->get();
        if (count($assignment_list) > 0) {
            foreach ($assignment_list as $assignment_info) {
                $quiz_info = $assignment_info->quiz;
                if (empty($quiz_info)) {
                    Assignment::where('_id', $assignment_info->_id)->update(['status' => 1]);
                    continue;
                }
                //Kiểm tra xem còn bao nhiêu thời gian làm bài
                $current_time = Carbon::now()->timestamp;
                $time_quiz = $quiz_info->time;//Thời gian làm bài quiz
                $end_time = Carbon::createFromTimestamp($assignment_info->start_time)->addMinutes($time_quiz)->timestamp;//Thời gian bắt đầu làm

                if ($current_time >= $end_time) { // Hết thời gian làm bài
                    $this->autoCreateAssignmentDetail($assignment_info);
                    Assignment::where('_id', $assignment_info->_id)->update(['status' => 1]);
                }

            }
        }
    }

    private function autoCreateAssignmentDetail($assignment_info)
    {
        //Get Draft
        $last_draft = AssignmentDraf::where('assignment_id', $assignment_info->_id)->orderBy('created_at', 'desc')->first();
        $quiz_question = $assignment_info->quiz->quiz_question;
        $data_answer = [];
        if (!empty($last_draft)) {
            $draft_data = json_decode($last_draft->data);
            $tempOnechoiceQuestion = $draft_data->tempOnechoiceQuestion;
            $tempMultipleChoiceQuestion = $draft_data->tempMultipleChoiceQuestion;
            $tempEssayQuestion = $draft_data->tempEssayQuestion;
            if (count($tempOnechoiceQuestion) > 0) {
                foreach ($tempOnechoiceQuestion as $answer) {
                    $data_answer[$answer->questionId] = $answer->answer;
                }
            }
            if (count($tempMultipleChoiceQuestion) > 0) {
                foreach ($tempMultipleChoiceQuestion as $answer1) {
                    $data_answer[$answer1->questionId] = $answer1->answerIds;
                }
            }
            if (count($tempEssayQuestion) > 0) {
                foreach ($tempEssayQuestion as $answer2) {
                    $data_answer[$answer2->questionId] = $answer2->answer;
                }
            }
        }
        $data_assignment_detail = [];
        foreach ($quiz_question as $key => $quiz_question_val) {
            $data_assignment_detail[$key]['quiz_question_id'] = $quiz_question_val->_id;
            $data_assignment_detail[$key]['quiz_question_type'] = $quiz_question_val->type;
            $data_assignment_detail[$key]['answer'] = empty($data_answer[$quiz_question_val->_id]) ? "" : $data_answer[$quiz_question_val->_id];
            $data_assignment_detail[$key]['assignment_id'] = $assignment_info->_id;
            $data_assignment_detail[$key]['score'] = 0;
            $data_assignment_detail[$key]['status'] = 1;//1: đã hoàn thành chưa chấm điểm; 2: Đã chấm điểm
            $data_assignment_detail[$key]['tenant_id'] = TENANT_ID_DEFAULT;
            $data_assignment_detail[$key]['created_at'] = dateNow();
            $data_assignment_detail[$key]['updated_at'] = dateNow();
        }
        if (!empty($data_assignment_detail)) {
            AssignmentDetail::insert($data_assignment_detail);
            return true;
        }
        return false;
    }
}
