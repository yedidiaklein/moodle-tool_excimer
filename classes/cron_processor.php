<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace tool_excimer;

/**
 * Class for processing cron and adhoc tasks.
 *
 * The main feature is that each task is profiled separately.
 *
 * @package   tool_excimer
 * @author    Jason den Dulk <jasondendulk@catalyst-au.net>
 * @copyright 2022, Catalyst IT
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cron_processor implements processor {

    /** @var float $sampletime Timestamp updated after processing each sample */
    public $sampletime;

    /** @var sample_set $tasksampleset A sample set recorded while processing a task */
    public $tasksampleset = null;

    /** @var sample_set A sample set for memory usage recorded while processing a task */
    protected $memoryusagesampleset;

    /**
     * Initialises the processor
     *
     * @param manager $manager The profiler manager object
     */
    public function init(manager $manager) {
        $this->sampletime = $manager->get_starttime();

        $manager->get_timer()->setCallback(function () use ($manager) {
            $this->on_interval($manager);
        });

        \core_shutdown_manager::register_function(
            function () use ($manager) {
                $manager->get_timer()->stop();
                $manager->get_profiler()->stop();
                $this->on_interval($manager);
                if ($this->tasksampleset) {
                    $this->process($manager, microtime(true));
                }
            }
        );
    }

    /**
     * Gets the minimum duration required for a profile to be saved, as seconds.
     *
     * @return float
     * @throws \dml_exception
     */
    public function get_min_duration(): float {
        return (float) get_config('tool_excimer', 'task_min_duration');
    }

    /**
     * Examines a sample generated by the profiler.
     *
     * The logic represents the following:
     *
     * If a sample is the first of a task, we create a task_samples instance, and add the sample.
     * As long as subsequent samples are in the same task, we keep adding them to task_samples.
     * When we get to a sample that is not in the same task, we process the task_samples and reset it.
     *
     * We then check for a new task with the current sample.
     *
     * @param manager $manager
     */
    public function on_interval(manager $manager) {
        $profiler = $manager->get_profiler();
        $log = $profiler->flush();
        $memoryusage = memory_get_usage();  // Record and set initial memory usage at this point.
        foreach ($log as $sample) {
            $taskname = $this->findtaskname($sample);
            $sampletime = $manager->get_starttime() + $sample->getTimestamp();

            // If there is a task and the current task name is different from the previous, then store the profile.
            if ($this->tasksampleset && ($this->tasksampleset->name != $taskname)) {
                $this->process($manager, $this->sampletime);
                $this->tasksampleset = null;
            }

            // If there exists a current task, and the sampleset for it is not created yet, create it.
            if ($taskname && ($this->tasksampleset === null)) {
                $this->tasksampleset = new sample_set($taskname, $this->sampletime);
                $this->memoryusagesampleset = new sample_set($taskname, $this->sampletime);
                if ($memoryusage) { // Ensure this only adds the mem usage for the initial base sample due to accuracy.
                    $this->memoryusagesampleset->add_sample(['sampleindex' => 0, 'value' => $memoryusage]);
                    $memoryusage = 0;
                }
            }

            // If the sampleset exists, add the current sample to it.
            if ($this->tasksampleset) {
                $this->tasksampleset->add_sample($sample);

                // Add memory usage:
                // Note that due to the looping this is probably inaccurate.
                $this->memoryusagesampleset->add_sample([
                    'sampleindex' => $this->tasksampleset->total_added() + $this->memoryusagesampleset->count() - 1,
                    'value' => memory_get_usage()
                ]);
            }

            // Instances of task_sample are always created with the previous sample's timestamp.
            // So it needs to be saved each loop.
            $this->sampletime = $sampletime;
        }
    }

    /**
     * Finds the name of the task being sampled, or null if not in a task.
     *
     * @param \ExcimerLogEntry $sample
     * @return string|null
     */
    public function findtaskname(\ExcimerLogEntry $sample): ?string {
        $trace = array_reverse($sample->getTrace());
        $length = count($trace);
        for ($i = 0; $i < $length; ++$i) {
            $fnname = $trace[$i]['function'] ?? null;
            if ($fnname == 'cron_run_inner_scheduled_task' || $fnname == 'cron_run_inner_adhoc_task') {
                if ($i + 1 < $length) {
                    if ('execute' == ($trace[$i + 1]['function'] ?? null)) {
                        return $trace[$i + 1]['class'];
                    }
                }
            }
        }
        return null;
    }

    /**
     * Processes stored samples to create a profile (if eligible).
     *
     * @param manager $manager
     * @param float $finishtime
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public function process(manager $manager, float $finishtime): void {
        $duration = $finishtime - $this->tasksampleset->starttime;
        $profile = new profile();
        $profile->add_env($this->tasksampleset->name);
        $profile->set('created', (int) $this->tasksampleset->starttime);
        $profile->set('duration', $duration);
        $reasons = $manager->get_reasons($profile);
        if ($reasons !== profile::REASON_NONE) {
            $profile->set('reason', $reasons);
            $profile->set('finished', (int) $finishtime);
            $profile->set('memoryusagedatad3', $this->memoryusagesampleset->samples);
            $profile->set('flamedatad3', flamed3_node::from_excimer_log_entries($this->tasksampleset->samples));
            $profile->save_record();
        }
    }
}
