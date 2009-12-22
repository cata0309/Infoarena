<?php

require_once(IA_ROOT_DIR."common/db/db.php");
require_once(IA_ROOT_DIR."common/db/round.php");
require_once(IA_ROOT_DIR."common/db/task.php");
require_once(IA_ROOT_DIR."common/round.php");
require_once(IA_ROOT_DIR."common/tags.php");
require_once(IA_ROOT_DIR."common/textblock.php");
require_once(IA_ROOT_DIR."www/format/pager.php");

// Displays form to either create a new round or edit an existing one.
// This form does not edit round content (its associated textblock)
// (textblock editor does that)
//
// Initially, the form is filled in with either:
//      * values for the existing round we edit
//      * default initial values when creating a new round
//
// Form submits to controller_round_save_details().
// When a validation error occurs in controller_round_save_details() it calls
// this controller as an error handler in order to display the form
// with the user-submitted data and their corresponding errors.
function controller_round_details($round_id) {
    global $identity_user;

    // Validate round_id
    if (!is_round_id($round_id)) {
        flash_error('Identificatorul rundei este invalid');
        redirect(url_home());
    }

    // Get round
    $round = round_get($round_id);
    if (!$round) {
        flash_error("Runda nu exista");
        redirect(url_home());
    }

    // Security check
    identity_require('round-edit', $round);

    // Filter for available round types.
    $round_types = array();
    foreach (round_get_types() as $round_type => $pretty_name) {
        if (identity_can("round-edit", round_init('round_id', $round_type,
            $identity_user)))
            $round_types[$round_type] = $pretty_name;
    }

    // get parameter list for rounds (in general, not for this specific round)
    $param_infos = round_get_parameter_infos();
    $all_tasks = task_get_all();
    $all_task_ids = array();
    foreach ($all_tasks as $idx => $task) {
        if ($round['type'] != 'user-defined' ||
            identity_can('task-use-in-user-round', $task)) {
            $all_task_ids[$task['id']] = true;
        } else {
            unset($all_tasks[$idx]);
        }
    }

    // Get parameters and task list.
    $round_params = round_get_parameters($round['id']);
    $round_tasks = array();
    foreach (round_get_tasks($round_id) as $task) {
        $round_tasks[] = $task['id'];
    }

    // Form stuff.
    $values = array();
    $errors = array();

    // Fill in form values from request, defaults in $round
    $values['type'] = request('type', $round['type']);
    $values['title'] = request('title', $round['title']);
    $values['page_name'] = request('page_name', $round['page_name']);
    $values['start_time'] = request('start_time', $round['start_time']);
    $values['public_eval'] = request('public_eval', $round['public_eval']);

    // Get tasks. SHIT FUCK DAMN;
    // It seems we can't find out if the user submitted anything at all.
    // Which messes up everything.
    if (request_is_post()) {
        $values['tasks'] = request('tasks', array());
    } else {
        $values['tasks'] = $round_tasks;
    }

    // Parameter values, for all possible types of rounds.
    // Yucky, but functional.
    foreach ($round_types as $round_type => $pretty_name) {
        foreach ($param_infos[$round_type] as $name => $info) {
            $form_name = "param_{$round_type}_{$name}";
            $def = $info['default'];
            if ($round_type == $round['type']) {
                $def = getattr($round_params, $name, $def);
            }
            $values[$form_name] = request($form_name, $def);
        }
    }

    // Tag data
    $values['tags'] = request('tags', tag_build_list("round", $round_id, "tag"));

    // Validate the monkey.

    // Build new round
    $new_round = $round;
    $new_round['type'] = $values['type'];
    $new_round['title'] = $values['title'];
    $new_round['page_name'] = $values['page_name'];
    $new_round['start_time'] = $values['start_time'];
    $new_round['public_eval'] = $values['public_eval'];

    $errors = round_validate($new_round);

    // Validate task list.
    $new_round_tasks = $values['tasks'];

    if (!is_array($new_round_tasks)) {
        $errors['tasks'] = 'Valori invalide.';
    } else {
        foreach ($new_round_tasks as $tid) {
            if (!is_string($tid)) {
                $errors['tasks'] = 'Valori invalide.';
                break;
            }
            if (!array_key_exists($tid, $all_task_ids)) {
                $errors['tasks'] = "Nu exista task-ul $tid.";
                break;
            }
        }
    }

    if (request_is_post() && count($values['tasks']) == 0) {
        $errors['tasks'] = "Trebuie sa alegi cel putin o problema";
    }

    // Additional validation for user defined tasks
    if (!array_key_exists('tasks', $errors)
        && $values['type'] == 'user-defined' &&
        count($values['tasks']) > IA_USER_DEFINED_ROUND_TASK_LIMIT) {
            $errors['tasks'] = "Nu poti alege mai mult de " .
            IA_USER_DEFINED_ROUND_TASK_LIMIT . " probleme";
        }

    if (array_key_exists('tasks', $errors)) {
        $values['tasks'] = array();
    }

    // Validate round parameters. Only for current type, and only if
    // properly selected.
    // FIXME: refactor
    $new_round_params = $round_params;
    if (!array_key_exists('type', $errors)) {
        $round_type = $new_round['type'];
        foreach ($param_infos[$round_type] as $name => $info) {
            $form_name = "param_{$round_type}_{$name}";
            $new_round_params[$name] = $values[$form_name];
        }
        $round_params_errors = round_validate_parameters(
                $round_type, $new_round_params);
        // Properly copy errors. Sucky
        foreach ($param_infos[$round_type] as $name => $info) {
            $form_name = "param_{$round_type}_{$name}";
            if (array_key_exists($name, $round_params_errors)) {
                $errors[$form_name] = $round_params_errors[$name];
            }
        }
    }
    // Always copy timestamp for ratings
    $new_round_params['rating_timestamp'] = db_date_parse($new_round['start_time']);

    // Handle tags
    tag_validate($values, $errors);

    // If posting with no errors then do the db monkey
    if (request_is_post() && !$errors) {
        // Don't forget about security.
        identity_require("round-edit", $new_round);
        round_update($new_round);
        round_update_parameters($round_id, $new_round_params);
        round_update_task_list($round_id, $round_tasks, $new_round_tasks);

        if (identity_can('round-tag', $new_round)) {
            tag_update("round", $new_round['id'], "tag", $values['tags']);
        }

        flash("Runda a fost modificata cu succes.");
        redirect(url_round_edit($round_id));
    }

    // Create view.
    $view = array();
    $view['title'] = "Editare $round_id";
    $view['page_name'] = url_round_edit($round_id);
    $view['round_id'] = $round_id;
    $view['round'] = $round;
    $view['form_values'] = $values;
    $view['form_errors'] = $errors;
    $view['param_infos'] = $param_infos;
    $view['all_tasks'] = $all_tasks;
    $view['round_types'] = $round_types;

    execute_view_die("views/round_edit.php", $view);
}

// Creates a round. Minimalist
function controller_round_create()
{
    global $identity_user;

    // Security check.
    identity_require_login();

    // Form stuff.
    $values = array();
    $errors = array();

    // Get form values
    $values['id'] = strtolower(request('id', ''));
    // FIXME: type hidden
    $values['type'] = request('type', 'user-defined');

    if (request_is_post()) {
        if (!is_round_id($values['id'])) {
            $errors['id'] = "Id-ul rundei este invalid";
        } else if (round_get($values['id'])) {
            $errors['id'] = "Exista deja o runda cu acest id";
        }
        if (!array_key_exists($values['type'], round_get_types())) {
            $errors['type'] = "Tip de runda invalid";
        }

        if (!$errors) {
            $round = round_init(
                    $values['id'],
                    $values['type'],
                    $identity_user);
            identity_require("round-create", $round);
            $round_params = array();
            // FIXME: array_ magic?
            $param_infos = round_get_parameter_infos();
            foreach ($param_infos[$values['type']] as $name => $info) {
                $round_params[$name] = $info['default'];
            }

            // This should never fail.
            log_assert(round_create($round, $round_params,
                    identity_get_user_id(), remote_ip_info()));
            flash("O noua runda a fost creata, acum poti sa editezi detalii.");
            redirect(url_round_edit($round['id']));
        }
    }

    // Filter for available round types.
    $round_types = array();
    foreach (round_get_types() as $round_type => $pretty_name) {
        if (identity_can("round-create", round_init("round_id", $round_type,
            $identity_user)))
            $round_types[$round_type] = $pretty_name;
    }

    // Create view.
    $view = array();
    $view['title'] = "Creare runda";
    $view['page_name'] = url_round_create();
    $view['form_values'] = $values;
    $view['form_errors'] = $errors;
    $view['round_types'] = $round_types;

    execute_view_die("views/round_create.php", $view);
}

function controller_round_delete($round_id) {
    // Validate round_id
    if (!is_round_id($round_id)) {
        flash_error('Identificatorul rundei este invalid');
        redirect(url_home());
    }

    // Get round
    $round = round_get($round_id);
    if (!$round) {
        flash_error("Runda nu exista");
        redirect(url_home());
    }

    // Security check
    identity_require('round-delete', $round);

    $options = pager_init_options();

    $textblock_list = textblock_grep('%"'.$round_id.'"%', '%', false, $options['first_entry'],
                                     $options['display_entries']);

    for ($i = 0; $i < count ($textblock_list); ++$i) {
        $textblock_list[$i]['id'] = $options['first_entry'] + $i + 1;
    }

    // Form stuff.
    $values = array();
    $errors = array();

    // Create view
    $view = array();
    $view['textblock_list'] = $textblock_list;
    $view['title'] = "Stergere textblockuri corelate cu $round_id";
    $view['page_name'] = url_round_delete($round_id);
    $view['round_id'] = $round_id;
    $view['round'] = $round;
    $view['form_values'] = $values;
    $view['form_errors'] = $errors;
    $entries = textblock_grep_count('%"'.$round_id.'"%', '%', false);
    $view['total_entries'] = $entries['cnt'];
    $view['first_entry'] = $options['first_entry'];
    $view['display_entries'] = $options['display_entries'];

    execute_view_die("views/round_delete.php", $view);
}

?>
