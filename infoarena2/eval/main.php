#! /usr/bin/env php
<?php

require_once(dirname($argv[0]) . "/config.php");

require_once(IA_ROOT.'common/log.php');
require_once(IA_ROOT.'common/common.php');
require_once(IA_ROOT.'common/task.php');
require_once(IA_ROOT.'common/security.php');
require_once(IA_ROOT.'common/db/db.php');
require_once(IA_ROOT.'eval/utilities.php');
require_once(IA_ROOT.'eval/download.php');
require_once(IA_ROOT.'eval/ClassicGrader.php');

log_print("");
log_print("Judge started");
log_print("");

// Create a job_result for a system error
function jobresult_system_error()
{
    return array(
            'log' => 'Eroare de sistem. Va rog sa trimiteti un mail la '.
                    'brick@wall.com sau sa postati pe forum. Va rugam sa mentionati '.
                    'id-ul jobului.',
            'message' => 'System error',
            'score' => 0,
    );
}

// Send job result.
function job_send_result($jobid, $jobresult)
{
    if ($result->message == "Eroare de sistem") {
        log_warn("System error on job $jobid");
    } else {
    }
    log_print("Sending job $jobid msg {$jobresult['message']} score {$jobresult['score']}");
    job_mark_done($jobid, $jobresult['log'], $jobresult['message'], $jobresult['score']);

    log_print("");
    log_print("");
}

// Evaluates job. Returns job result.
function job_eval($job)
{
    if (!chdir(IA_EVAL_DIR)) {
        log_print("Can't chdir to eval dir");
        return jobresult_system_error();
    }
    
    // Get task
    $task = task_get($job['task_id']);
    if (!is_task($task)) {
        log_print("Nu am putut lua task-ul " . $job['task_id']);
        return jobresult_system_error();
    }

    // Get task parameters.
    $task_parameters = task_get_parameters($job['task_id']);
    if (!$task_parameters) {
        log_print("Nu am putut lua parametrii task-ului " . $job['task_id']);
        return jobresult_system_error();
    }

    // Make the grader and execute it.
    if ($task['type'] == 'classic') {
        return task_grade_job_classic($task, $task_parameters, $job);
    } else {
        log_print("Nu stiu sa evaluez task-uri de tip ".$task['type']);
        return jobresult_system_error();
    }
}

// This function handles a certain job.
// This is the main job function.
function job_handle($job) {
    log_print("- -- --- ---- ----- Handling job " . $job['id']);
    // FIXME: do this in query.
    job_mark_delay($job['id'], 'waiting');

    $user = user_get_by_id($job['user_id']);
    if (!$user) {
        log_print("Nu am gasit utilizatorul " . $job['user_id']);
        job_send_result($job, jobresult_system_error());
        return;
    }

    // Evaluating, mark as processing.
    job_mark_delay($job['id'], 'processing');
    $job_result = job_eval($job);
    if ($job_result == null) {
        log_print("Bug in get_job_result");
        $job_result = jobresult_system_error();
    }

    job_send_result($job['id'], $job_result);
}

// main function. C rules.
function main() {
    while (1) {
        while ($job = job_get_next_job()) {
            job_handle($job);
        }
        milisleep(10);
    }
}

main()

?>
